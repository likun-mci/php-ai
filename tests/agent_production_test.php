<?php
/**
 * 生产就绪度测试
 *
 * 覆盖四件在真实部署里会咬人的事：
 *   1. 取消      —— HTTP 断开 / 用户中止 / 超时，循环必须停得下来
 *   2. 执行预算  —— 墙钟与工具调用次数的硬闸
 *   3. 渐进披露  —— search_tools 激活的工具下一轮真的能看见
 *   4. 模型决策  —— 策略由模型选，失败自动退回规则版
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Ai\Agent\Budget\BudgetManager;
use Ai\Agent\Loop\CancellationToken;
use Ai\Agent\Orchestrator\ExecutionStrategy;
use Ai\Agent\Orchestrator\ModelStrategyResolver;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolDiscovery;
use Ai\Agent\Tool\ToolRegistry;

$pass = 0;
$fail = 0;

function ok($cond, $label)
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS: {$label}\n"; }
    else { $fail++; echo "  FAIL: {$label}\n"; }
}

function eq($expected, $actual, $label)
{
    global $pass, $fail;
    if ($expected === $actual) { $pass++; echo "  PASS: {$label}\n"; }
    else {
        $fail++;
        echo "  FAIL: {$label}\n";
        echo "    expected: " . var_export($expected, true) . "\n";
        echo "    actual:   " . var_export($actual, true) . "\n";
    }
}

// ============================================================
echo "\n=== 一、取消 ===\n";
// ============================================================

$t = new CancellationToken();
ok(!$t->isCancelled(), '新令牌未取消');
$t->cancel('用户按了停止');
ok($t->isCancelled(), 'cancel() 后判定取消');
eq('用户按了停止', $t->getReason(), '记录取消原因');

// 原因不被后续调用覆盖——第一个原因才是真原因
$t->cancel('另一个原因');
eq('用户按了停止', $t->getReason(), '重复取消不覆盖首个原因');
$t->reset();
ok(!$t->isCancelled(), 'reset 复位');

// 探针
$flag = false;
$probed = new CancellationToken(function () use (&$flag) { return $flag; }, '探针触发');
ok(!$probed->isCancelled(), '探针为假时不取消');
$flag = true;
ok($probed->isCancelled(), '探针为真时取消');
eq('探针触发', $probed->getReason(), '用探针的原因');
// 固化：探针翻回假也不复活
$flag = false;
ok($probed->isCancelled(), '探针结果固化，不会诡异复活');

// 文件探针（跨进程叫停）
$stopFile = sys_get_temp_dir() . '/php-ai-stop-' . getmypid();
@unlink($stopFile);
$fileToken = CancellationToken::whenFileExists($stopFile);
ok(!$fileToken->isCancelled(), '文件不存在时不取消');
touch($stopFile);
ok($fileToken->isCancelled(), '文件出现后取消（clearstatcache 生效）');
@unlink($stopFile);

// 超时令牌
$expired = CancellationToken::afterSeconds(0);
ok($expired->isCancelled(), '零秒截止立即取消');
ok(!CancellationToken::afterSeconds(60)->isCancelled(), '未到截止不取消');

// ToolContext 拿到的是活探针，不是快照
$live = false;
$tc = new ToolContext(['cancelled' => function () use (&$live) { return $live; }]);
ok(!$tc->isCancelled(), '工具上下文初始未取消');
$live = true;
ok($tc->isCancelled(), '工具上下文能看到建好之后才到的取消信号');

// 布尔写法保持兼容
ok((new ToolContext(['cancelled' => true]))->isCancelled(), '布尔 cancelled 仍然认');
ok(!(new ToolContext(['cancelled' => false]))->isCancelled(), '布尔 false 不取消');

// ============================================================
echo "\n=== 二、执行预算 ===\n";
// ============================================================

$b = new BudgetManager(['maxToolCalls' => 3]);
$b->start();
ok(!$b->exceeded(), '未调用工具时未超限');
$b->recordToolCalls(3);
ok(!$b->exceeded(), '刚好到上限不算超');
$b->recordToolCalls(1);
ok($b->exceeded(), '超过工具调用上限');
ok(strpos($b->summary()['reason'], '工具调用次数超限') !== false, '超限原因说清是哪一项');
eq(4, $b->getToolCalls(), '工具调用计数正确');

$d = new BudgetManager(['maxDuration' => 1]);
$d->start();
ok(!$d->exceeded(), '刚开始未超时');
ok($d->summary()['elapsed'] >= 0, 'summary 带上已运行秒数');

// 墙钟：不实际 sleep，改用反射把起点往前挪，测试不该为了等一秒而变慢
$ref = new ReflectionProperty('Ai\Agent\Budget\BudgetManager', 'startedAt');
$ref->setAccessible(true);
$ref->setValue($d, microtime(true) - 5);
ok($d->exceeded(), '超过墙钟上限');
ok(strpos($d->summary()['reason'], '墙钟') !== false, '超时原因说清是墙钟');

// 没设上限就不该被限制
$free = new BudgetManager();
$free->start();
$free->recordToolCalls(9999);
ok(!$free->exceeded(), '未设上限时不限制（默认行为不变）');

// reset 让同一实例能跑第二个任务
$b->reset();
ok(!$b->exceeded(), 'reset 后额度回满');
eq(0, $b->getToolCalls(), 'reset 清零工具计数');

// token / 成本上限仍然有效
$tok = new BudgetManager(['maxTokens' => 100]);
$tok->record(['prompt_tokens' => 80, 'completion_tokens' => 40]);
ok($tok->exceeded(), 'token 上限仍然有效');

// ============================================================
echo "\n=== 三、渐进披露 ===\n";
// ============================================================

function mkTool($name, $desc)
{
    return ['description' => $desc, 'handler' => function () use ($name) { return $name; }];
}

$registry = new ToolRegistry();
$registry->registerAll([
    'read_file'   => mkTool('read_file', '读取文件内容'),
    'pg_query'    => mkTool('pg_query', '在 PostgreSQL 数据库上执行查询'),
    'redis_get'   => mkTool('redis_get', '读取 Redis 缓存'),
    'deploy_k8s'  => mkTool('deploy_k8s', '部署到 Kubernetes 集群'),
]);

$disc = new ToolDiscovery($registry, ['alwaysAvailable' => ['read_file']]);

// 关键回归：目录必须独立于注册表。装配流程会裁注册表，裁完还得搜得到
eq(4, count($disc->catalogNames()), '构造时快照了全量目录');
$registry->clear()->registerAll(['read_file' => mkTool('read_file', '读取文件内容')]);
$hits = $disc->search('数据库');
ok(count($hits) > 0, '注册表被裁之后仍然搜得到目录里的工具（原先搜不到）');
eq('pg_query', $hits[0]['name'], '搜到正确的工具');

$initial = $disc->initialTools();
ok(isset($initial['read_file']), '初始工具含常用工具');
ok(isset($initial['search_tools']), '初始工具含 search_tools');
ok(!isset($initial['deploy_k8s']), '初始工具不含未激活的工具');

ok($disc->activate('deploy_k8s'), '激活目录里的工具');
ok(isset($disc->activeTools()['deploy_k8s']), '激活后进入 activeTools');
ok(!$disc->activate('不存在的工具'), '激活不存在的工具返回 false');

$disc->deactivate('deploy_k8s');
ok(!isset($disc->activeTools()['deploy_k8s']), 'deactivate 生效');

// search_tools 的 handler 会自动激活搜到的工具
$def = $disc->searchToolDefinition();
$out = call_user_func($def['handler'], ['query' => 'Kubernetes 部署']);
ok(strpos($out, 'deploy_k8s') !== false, 'search_tools 返回命中工具');
ok(in_array('deploy_k8s', $disc->activated(), true), 'search_tools 自动激活了搜到的工具');

// 运行时才接进来的工具（MCP）能补进目录
$disc->addToCatalog(['mcp_tool' => mkTool('mcp_tool', '运行时接入的 MCP 工具')]);
ok(in_array('mcp_tool', $disc->catalogNames(), true), '可以把运行时工具补进目录');

// toolDefs 每轮按激活集过滤
$ai = new \Ai\AI(['api_key' => 'test', 'platform' => 'openai']);
$full = new ToolRegistry();
$full->registerAll([
    'read_file'  => mkTool('read_file', '读取文件内容'),
    'deploy_k8s' => mkTool('deploy_k8s', '部署到 Kubernetes 集群'),
]);
$disc2 = new ToolDiscovery($full, ['alwaysAvailable' => ['read_file']]);
$full->register('search_tools', $disc2->searchToolDefinition());

$ctx = new \Ai\Agent\AgentContext($ai, $full, null);
$ctx->setToolDiscovery($disc2);
$names = array_column($ctx->toolDefs(), 'name');
sort($names);
eq(['read_file', 'search_tools'], $names, '未激活时模型只看得见常用工具');

$disc2->activate('deploy_k8s');
$names = array_column($ctx->toolDefs(), 'name');
sort($names);
eq(['deploy_k8s', 'read_file', 'search_tools'], $names, '激活后模型立刻看得见（每轮重算）');
ok($full->get('deploy_k8s') !== null, '注册表始终保有全量工具，激活了才执行得了');

// 不挂 discovery 时行为不变
$plain = new \Ai\Agent\AgentContext($ai, $full, null);
eq(3, count($plain->toolDefs()), '不挂 discovery 时给出全部工具（默认行为不变）');

// ============================================================
echo "\n=== 四、模型决策 ===\n";
// ============================================================

/** 假 AI：按预设返回，不发网络请求 */
class FakeAI extends \Ai\AI
{
    /** @var string */
    public $canned = '';
    /** @var bool */
    public $throw = false;
    /** @var int */
    public $calls = 0;

    public function chat($payload = ''): \Ai\Contracts\AIResponseInterface
    {
        $this->calls++;
        if ($this->throw) {
            throw new \RuntimeException('模型炸了');
        }
        return new \Ai\Response\AIResponse(['content' => $this->canned]);
    }
}

$fake = new FakeAI(['api_key' => 'test', 'platform' => 'openai']);
$sam = new \Ai\Agent\SubAgent\SubAgentManager($fake);
$sam->register('explorer', ['description' => '只读探索代码结构', 'prompt' => 'x']);

$resolver = new ModelStrategyResolver($fake, $sam);

$fake->canned = '{"strategy":"plan","reason":"跨三个模块","subtasks":["a","b"],"confidence":0.9}';
$d = $resolver->resolve('重构权限系统');
ok($d !== null, '解析出决策');
eq(ExecutionStrategy::PLAN, $d->getStrategy(), '策略为 plan');
eq('跨三个模块', $d->getReason(), '理由透传');

// 缓存：同一任务不重复问
$before = $fake->calls;
$resolver->resolve('重构权限系统');
eq($before, $fake->calls, '同一任务描述命中缓存，不重复调模型');

// 围栏 + 前言容错
$resolver->clearCache();
$fake->canned = "好的，我的判断是：\n```json\n{\"strategy\":\"direct\",\"reason\":\"很简单\"}\n```";
$d = $resolver->resolve('读一下 README');
ok($d !== null && $d->getStrategy() === ExecutionStrategy::DIRECT, '能从围栏和前言里抠出 JSON');

// 委派给不存在的子 Agent → 降级 direct 而不是硬派
$resolver->clearCache();
$fake->canned = '{"strategy":"delegate","agent":"ghost","reason":"交给 ghost"}';
$d = $resolver->resolve('随便什么任务');
eq(ExecutionStrategy::DIRECT, $d->getStrategy(), '子 Agent 不存在时降级为 direct');
ok(strpos($d->getReason(), 'ghost') !== false, '降级理由说清是哪个 agent 不存在');

// 委派给存在的子 Agent
$resolver->clearCache();
$fake->canned = '{"strategy":"delegate","agent":"explorer","reason":"适合探索"}';
$d = $resolver->resolve('看看支付模块');
eq(ExecutionStrategy::DELEGATE, $d->getStrategy(), '子 Agent 存在时正常委派');
eq('explorer', $d->getAgent(), '委派目标正确');

// 并行子任务不足两个 → 降级
$resolver->clearCache();
$fake->canned = '{"strategy":"parallel","subtasks":["只有一个"],"reason":"并行"}';
eq(ExecutionStrategy::DIRECT, $resolver->resolve('x')->getStrategy(), '并行不足两项时降级为 direct');

// 非法策略 / 非 JSON / 模型异常 → 一律 null，交回规则版
$resolver->clearCache();
$fake->canned = '{"strategy":"telepathy","reason":"?"}';
ok($resolver->resolve('y') === null, '非法策略返回 null（交回规则版）');

$resolver->clearCache();
$fake->canned = '我觉得直接做就行';
ok($resolver->resolve('z') === null, '非 JSON 返回 null');

$resolver->clearCache();
$fake->throw = true;
ok($resolver->resolve('w') === null, '模型调用失败返回 null，不让任务死在决策上');
ok($resolver->lastError() !== null, '失败被记录下来可排查');
$fake->throw = false;

// 计划执行途中不重复问
$resolver->clearCache();
$before = $fake->calls;
ok($resolver->resolve('某一步', ['has_plan' => true]) === null, '计划执行途中不问策略');
eq($before, $fake->calls, '也没有白花一次模型调用');

// 作为 callable 直接挂给 StrategySelector
$resolver->clearCache();
$fake->canned = '{"strategy":"direct","reason":"简单"}';
$selector = new \Ai\Agent\Orchestrator\StrategySelector($sam);
$selector->setResolver($resolver);
eq(ExecutionStrategy::DIRECT, $selector->select('重构整个系统')->getStrategy(),
    '挂上 resolver 后，含「重构」的任务也由模型说了算（不再被词表定死）');

// resolver 返回 null 时规则版接手
$resolver->clearCache();
$fake->canned = 'garbage';
eq(ExecutionStrategy::PLAN, $selector->select('重构整个系统')->getStrategy(),
    'resolver 拿不准时规则版接手，任务照跑');

// ============================================================
echo "\n=== 五、端到端：取消真的能停下循环 ===\n";
// ============================================================

/** 每次都要求调工具的假 AI——不取消就会一直跑到 maxIter */
class LoopingAI extends \Ai\AI
{
    /** @var int */
    public $calls = 0;

    public function chat($payload = ''): \Ai\Contracts\AIResponseInterface
    {
        $this->calls++;
        return new \Ai\Response\AIResponse([
            'content'    => '继续干活',
            'tool_calls' => [[
                'id'    => 'call_' . $this->calls,
                'name'  => 'tick',
                'input' => ['n' => $this->calls],
            ]],
        ]);
    }
}

$loopAI = new LoopingAI(['api_key' => 'test', 'platform' => 'openai']);
$runtime = new \Ai\Agent\AgentRuntime($loopAI);
$runtime->setMaxIter(20);

$ticks = 0;
$runtime->setTools(['tick' => [
    'description' => '计数',
    'handler'     => function () use (&$ticks) { $ticks++; return 'tick ' . $ticks; },
]]);

// 基线：不取消会跑满 20 轮
$result = $runtime->run([['role' => 'user', 'content' => '一直干']]);
eq(\Ai\Agent\Loop\StopReason::MAX_ITER, $result->getStopReason(), '不取消时跑到迭代上限');
eq(20, $ticks, '基线跑满 20 轮');

// 跑到第 3 轮时取消
$loopAI2 = new LoopingAI(['api_key' => 'test', 'platform' => 'openai']);
$runtime2 = new \Ai\Agent\AgentRuntime($loopAI2);
$runtime2->setMaxIter(20);
$token = new CancellationToken();
$runtime2->setCancellation($token);

$ticks2 = 0;
$runtime2->setTools(['tick' => [
    'description' => '计数',
    'handler'     => function () use (&$ticks2, $token) {
        $ticks2++;
        if ($ticks2 === 3) {
            $token->cancel('干到第三轮时被叫停');
        }
        return 'tick ' . $ticks2;
    },
]]);

$cancelledEvents = [];
$runtime2->onEvent(function ($e) use (&$cancelledEvents) {
    if (isset($e['type']) && $e['type'] === 'cancelled') {
        $cancelledEvents[] = $e;
    }
});

$result2 = $runtime2->run([['role' => 'user', 'content' => '一直干']]);

eq(\Ai\Agent\Loop\StopReason::USER_CANCEL, $result2->getStopReason(), '取消后以 user_cancel 收尾');
eq(3, $ticks2, '第 3 轮之后立刻停手，没有把剩下 17 轮跑完');
ok($loopAI2->calls <= 3, '不再发起新的模型调用（取消后不烧钱）');
eq(1, count($cancelledEvents), '发出了 cancelled 事件');
eq('干到第三轮时被叫停', $cancelledEvents[0]['reason'], '事件带上取消原因');
eq('干到第三轮时被叫停', $result2->getExtra()['reason'], '结果里带上取消原因');

// 开跑前就取消 → 一次模型调用都不该发
$loopAI3 = new LoopingAI(['api_key' => 'test', 'platform' => 'openai']);
$runtime3 = new \Ai\Agent\AgentRuntime($loopAI3);
$runtime3->setTools(['tick' => ['description' => '计数', 'handler' => function () { return 'x'; }]]);
$runtime3->cancel('开跑前就取消');
$result3 = $runtime3->run([['role' => 'user', 'content' => '干活']]);
eq(\Ai\Agent\Loop\StopReason::USER_CANCEL, $result3->getStopReason(), '开跑前取消立即收尾');
eq(0, $loopAI3->calls, '一次模型调用都没发');

// 工具调用次数上限也能停下循环
$loopAI4 = new LoopingAI(['api_key' => 'test', 'platform' => 'openai']);
$runtime4 = new \Ai\Agent\AgentRuntime($loopAI4);
$runtime4->setMaxIter(50);
// 每次返回不同内容，免得进展守卫（结果连续相同 → no_progress）抢先收口，
// 那样测的就不是预算闸了
$n4 = 0;
$runtime4->setTools(['tick' => ['description' => '计数', 'handler' => function () use (&$n4) {
    $n4++;
    return 'tick ' . $n4;
}]]);
$runtime4->setBudget(new BudgetManager(['maxToolCalls' => 4]));
$result4 = $runtime4->run([['role' => 'user', 'content' => '一直干']]);
eq(\Ai\Agent\Loop\StopReason::BUDGET_EXCEEDED, $result4->getStopReason(), '工具调用超限时停下循环');
ok($loopAI4->calls < 50, '没有跑满迭代上限');

echo "\n=== 结果: {$pass} 通过, {$fail} 失败 ===\n";
exit($fail > 0 ? 1 : 0);
