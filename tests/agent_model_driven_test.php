<?php
/**
 * 模型驱动执行逻辑测试
 *
 * 覆盖四件事（对应 .claude/dev.md 第五节）：
 *   1. update_plan —— 模型自己管计划
 *   2. delegate    —— 模型自己派子 Agent
 *   3. 失败输出截断 —— 失败结果不再原样灌进上下文
 *   4. 反思判据   —— 简单问答不再被逼着多跑一轮
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\Planning\PlanManager;
use Ai\Agent\Planning\PlanStep;
use Ai\Agent\Reflection\ReflectionManager;
use Ai\Agent\SubAgent\SubAgentManager;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolExecutor;
use Ai\Agent\Tool\ToolRegistry;
use Ai\Agent\Tool\ToolResult;
use Ai\Agent\Tools\DelegateTool;
use Ai\Agent\Tools\UpdatePlanTool;
use Ai\Helpers\Text;

$pass = 0;
$fail = 0;

function ok($cond, $label)
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS: {$label}\n";
    } else {
        $fail++;
        echo "  FAIL: {$label}\n";
    }
}

function eq($expected, $actual, $label)
{
    global $pass, $fail;
    if ($expected === $actual) {
        $pass++;
        echo "  PASS: {$label}\n";
    } else {
        $fail++;
        echo "  FAIL: {$label}\n";
        echo "    expected: " . var_export($expected, true) . "\n";
        echo "    actual:   " . var_export($actual, true) . "\n";
    }
}

$ctx = new ToolContext(['workdir' => sys_get_temp_dir()]);

// ============================================================
echo "\n=== 一、update_plan ===\n";
// ============================================================

$pm = new PlanManager();
$bound = '';
$tool = new UpdatePlanTool($pm, ['onChange' => function ($plan) use (&$bound) {
    $bound = $plan->getId();
}]);

eq('update_plan', $tool->name(), '工具名');

$r = $tool->execute([
    'goal'  => '修复登录 Bug 并跑通测试',
    'items' => [
        ['action' => '定位登录入口', 'status' => 'completed'],
        ['action' => '修复空指针', 'status' => 'in_progress'],
        ['action' => '跑 composer test'],
    ],
], $ctx);

ok($r->isSuccess(), '首次调用建立计划');
ok($tool->getPlanId() !== '', '工具记住了计划 ID');
eq($tool->getPlanId(), $bound, 'onChange 把计划 ID 绑了出去');

$plan = $pm->getPlan($tool->getPlanId());
eq('修复登录 Bug 并跑通测试', $plan->getGoal(), '目标写入');
eq(3, count($plan->getSteps()), '三个步骤');

$steps = $plan->getSteps();
eq(PlanStep::STATUS_COMPLETED, $steps[0]->getStatus(), 'completed 原样保留');
eq(PlanStep::STATUS_RUNNING, $steps[1]->getStatus(), 'in_progress 映射为 running');
eq(PlanStep::STATUS_PENDING, $steps[2]->getStatus(), '省略 status 视为 pending');

// 整表覆盖 + 版本链
$before = $plan->getVersion();
$tool->execute(['items' => [
    ['action' => '定位登录入口', 'status' => 'completed'],
    ['action' => '改用已有的 Auth 基础类', 'status' => 'in_progress'],
]], $ctx);

$plan = $pm->getPlan($tool->getPlanId());
eq(2, count($plan->getSteps()), '整表覆盖：三步改成两步');
ok($plan->getVersion() > $before, '版本号递增');
ok(count($pm->versions($tool->getPlanId())) > 0, '旧版本被快照保留，没有被覆盖丢掉');
eq('修复登录 Bug 并跑通测试', $plan->getGoal(), '省略 goal 时目标不变');

// 同义字段名
$pm2 = new PlanManager();
$tool2 = new UpdatePlanTool($pm2);
$tool2->execute(['goal' => 'G', 'items' => [
    ['description' => '用 description 写的步骤'],
    ['content' => '用 content 写的步骤'],
    '直接给字符串',
]], $ctx);
eq(3, count($pm2->getPlan($tool2->getPlanId())->getSteps()), 'description/content/裸字符串都认');

// 边界
ok(!$tool2->execute(['items' => []], $ctx)->isSuccess(), '空 items 报错');
$tool3 = new UpdatePlanTool(new PlanManager(), ['maxItems' => 2]);
$over = $tool3->execute(['items' => [['action' => 'a'], ['action' => 'b'], ['action' => 'c']]], $ctx);
ok(!$over->isSuccess(), '步骤数超上限报错');
ok(!$tool3->execute(['items' => [['status' => 'pending']]], $ctx)->isSuccess(), '没有 action 的条目视为无效');

// schema 完整
$schema = $tool->schema();
ok(in_array('items', $schema['required'], true), 'schema 要求 items');
ok(in_array('in_progress', $schema['properties']['items']['items']['properties']['status']['enum'], true),
    'schema 里列了 in_progress');

// ============================================================
echo "\n=== 二、delegate ===\n";
// ============================================================

/** runSync 打桩，避免测试真的去调模型 */
class FakeSubAgentManager extends SubAgentManager
{
    /** @var array<string, mixed> */
    public $canned = ['status' => 'completed', 'summary' => '支付入口在 src/Pay/Entry.php'];

    /** @var int */
    public $calls = 0;

    public function runSync($agentName, $task)
    {
        $this->calls++;
        $runId = 'sub_fake_' . $this->calls;
        $this->runs[$runId] = array_merge([
            'task_id'    => $runId,
            'agent'      => $agentName,
            'task'       => $task,
            'iterations' => 3,
            'messages'   => [],
        ], $this->canned);
        return $runId;
    }
}

$sam = new FakeSubAgentManager(new AI(['api_key' => 'test', 'platform' => 'openai']));
$sam->register('explorer', ['description' => '只读探索代码结构', 'prompt' => 'x']);
$sam->register('tester', ['description' => '跑测试并报告失败', 'prompt' => 'x']);

$dt = new DelegateTool($sam, ['maxDelegations' => 2]);

eq('delegate', $dt->name(), '工具名');
ok(strpos($dt->description(), 'explorer') !== false, '描述里列出了可用子 Agent 名字');
ok(strpos($dt->description(), '只读探索代码结构') !== false, '描述里带上了子 Agent 职责');
eq(['explorer', 'tester'], $dt->schema()['properties']['agent']['enum'], 'schema 用 enum 限定名字');

$r = $dt->execute(['agent' => 'explorer', 'task' => '找支付入口'], $ctx);
ok($r->isSuccess(), '委派成功');
eq('支付入口在 src/Pay/Entry.php', $r->getContent(), '只把摘要回给主上下文');
eq('sub_fake_1', $r->getMetadata()['task_id'], '返回 task_id 供查 transcript');

// 名字写错 → 错误结果而不是异常，并列出可用名字
$bad = $dt->execute(['agent' => 'explor', 'task' => 'x'], $ctx);
ok(!$bad->isSuccess(), '未知子 Agent 返回错误结果');
ok(strpos($bad->getError(), 'explorer') !== false, '错误信息列出可用名字，模型能换一个再试');
eq(1, $sam->calls, '名字错时没有真的去跑子 Agent');

// 未完成时把半截产出也交回去
$sam->canned = ['status' => 'stopped', 'reason' => 'max_iter', 'summary' => '只查到一半'];
$stopped = $dt->execute(['agent' => 'tester', 'task' => 'x'], $ctx);
ok(!$stopped->isSuccess(), '子 Agent 未完成 → 失败结果');
ok(strpos($stopped->getError(), '只查到一半') !== false, '未完成也把已有产出交回去');

// 次数上限
$over = $dt->execute(['agent' => 'explorer', 'task' => 'x'], $ctx);
ok(!$over->isSuccess(), '超过委派次数上限被拦住');
eq(2, $dt->getCount(), '计数只统计真正跑过的委派');
$dt->reset();
eq(0, $dt->getCount(), 'reset 清零');

ok(!$dt->execute(['agent' => 'explorer', 'task' => ''], $ctx)->isSuccess(), '空 task 报错');

// ============================================================
echo "\n=== 三、失败输出截断 ===\n";
// ============================================================

class HugeErrorTool implements \Ai\Agent\Tool\AgentToolInterface
{
    public function name() { return 'huge_error'; }
    public function description() { return 'x'; }
    public function schema() { return ['type' => 'object', 'properties' => []]; }
    public function execute(array $input, ToolContext $context)
    {
        // 头部是无关日志，尾部才是结论——真实的测试失败输出就是这个形状
        return ToolResult::error(str_repeat("noise line\n", 5000) . 'FATAL: 断言失败在第 42 行');
    }
}

class HugeOkTool implements \Ai\Agent\Tool\AgentToolInterface
{
    public function name() { return 'huge_ok'; }
    public function description() { return 'x'; }
    public function schema() { return ['type' => 'object', 'properties' => []]; }
    public function execute(array $input, ToolContext $context)
    {
        return ToolResult::success('HEAD-MARKER' . str_repeat('x', 60000));
    }
}

$registry = new ToolRegistry();
$registry->register(new HugeErrorTool());
$registry->register(new HugeOkTool());
$executor = new ToolExecutor($registry);
$executor->setMaxOutputBytes(2000);

$errResult = $executor->execute(['id' => '1', 'name' => 'huge_error', 'input' => []], $ctx);
ok(!$errResult->isSuccess(), '失败结果仍然是失败');
ok(strlen((string) $errResult) < 5000, '失败输出被截断（改动前完全不截）');
ok(strpos($errResult->getError(), 'FATAL: 断言失败在第 42 行') !== false, '失败输出保留尾部结论');
ok(strpos($errResult->getError(), 'truncated') !== false, '如实告知被截断');
ok($errResult->isPartial(), '标记为部分结果');
eq(true, $errResult->getMetadata()['truncated_bytes'] > 0, '元数据记录截掉了多少');

$okResult = $executor->execute(['id' => '2', 'name' => 'huge_ok', 'input' => []], $ctx);
ok(strpos($okResult->getContent(), 'HEAD-MARKER') === 0, '成功输出仍然保留头部');
ok(strlen($okResult->getContent()) < 5000, '成功输出照样截断');

// 短的失败结果不动
$short = new ToolResult(['success' => false, 'error' => '文件不存在']);
$registry->register('short_err', ['description' => 'x', 'handler' => function () {
    return new ToolResult(['success' => false, 'error' => '文件不存在']);
}]);
$shortOut = $executor->execute(['id' => '3', 'name' => 'short_err', 'input' => []], $ctx);
eq('文件不存在', $shortOut->getError(), '未超限的失败结果原样保留');

// UTF-8 尾部截断不劈开汉字
$cn = str_repeat('中文测试', 500);
$tail = Text::cutBytesTail($cn, 101);
ok(strlen($tail) <= 101, '尾部截断不超上限');
ok(mb_check_encoding($tail, 'UTF-8'), '尾部截断后仍是合法 UTF-8');
ok(substr($cn, -30) === substr($tail, -30), '留的确实是尾部');

// ============================================================
echo "\n=== 四、反思判据：简单问答不该多跑一轮 ===\n";
// ============================================================

$rm = new ReflectionManager();

// 直接给出答案 → 完成
$answer = $rm->reflect([
    ['role' => 'user', 'content' => 'PHP 怎么判断数组为空？'],
    ['role' => 'assistant', 'content' => '用 empty($arr) 最直接；'
        . '如果只想排除空数组而不排除 "0"、null 这类值，用 count($arr) === 0。'
        . 'PHP 7.3 起 count() 对非数组会报错，先确认变量确实是数组。'],
], 'PHP 怎么判断数组为空', ['iteration' => 0, 'isFirstRound' => true]);
ok($answer->isSuccess(), '首轮直接答完 → 判为完成（不再被逼着调工具）');

// 推迟性发言 → 继续（原有行为保留）
$defer = $rm->reflect([
    ['role' => 'user', 'content' => '帮我解决一个问题'],
    ['role' => 'assistant', 'content' => '让我先分析一下'],
], '目标', ['iteration' => 0, 'isFirstRound' => true]);
ok($defer->shouldContinue(), '推迟性发言 → 继续');

$defer2 = $rm->reflect([
    ['role' => 'user', 'content' => 'fix the bug'],
    ['role' => 'assistant', 'content' => "Let me look at the code first."],
], 'fix the bug', ['iteration' => 0, 'isFirstRound' => true]);
ok($defer2->shouldContinue(), '英文推迟性发言 → 继续');

// 给了代码块 → 已经是答案
$code = $rm->reflect([
    ['role' => 'user', 'content' => '写个 JWT 登录示例'],
    ['role' => 'assistant', 'content' => "好的，我来写：\n```php\n<?php\necho 'jwt';\n```"],
], '写个 JWT 登录示例', ['iteration' => 0, 'isFirstRound' => true]);
ok($code->isSuccess(), '含代码块 → 判为已给出答案');

// 一句话都没说 → 确实没干活
$silent = $rm->reflect([
    ['role' => 'user', 'content' => '帮我解决一个问题'],
    ['role' => 'assistant', 'content' => ''],
], '目标', ['iteration' => 0, 'isFirstRound' => true]);
ok($silent->shouldContinue(), '既没调工具也没说话 → 继续');

// ============================================================
echo "\n=== 五、装配 ===\n";
// ============================================================

$ai = new AI(['api_key' => 'test', 'platform' => 'openai']);
$agent = Agent::create($ai);
$agent->setPlanManager(new PlanManager());

$sam2 = new FakeSubAgentManager($ai);
$sam2->register('explorer', ['description' => '探索', 'prompt' => 'x']);
$agent->setSubAgentManager($sam2);
$agent->tools(['dummy' => ['description' => 'd', 'handler' => function () { return 'ok'; }]]);

$before = array_keys($agent->getRuntime()->getToolRegistry()->all());
ok(!in_array('update_plan', $before, true), '不调 modelDrivenTools 时工具集不变（入口向后兼容）');

$agent->modelDrivenTools();
$after = $agent->getRuntime()->getToolRegistry()->all();
ok(isset($after['update_plan']), 'update_plan 已注册');
ok(isset($after['delegate']), 'delegate 已注册');
ok(isset($after['dummy']), '原有工具没有被顶掉');

$parent = $sam2->getParentTools();
ok(!isset($parent['delegate']), '子 Agent 拿不到 delegate（否则会一层套一层无限委派）');
ok(!isset($parent['update_plan']), '子 Agent 拿不到 update_plan（它不该改写主计划）');
ok(isset($parent['dummy']), '子 Agent 仍能继承普通工具');

// 依赖没挂就不注册
$bare = Agent::create($ai);
$bare->modelDrivenTools();
$bareTools = $bare->getRuntime()->getToolRegistry()->all();
ok(!isset($bareTools['update_plan']), '没挂 PlanManager 就不注册 update_plan');
ok(!isset($bareTools['delegate']), '没挂子 Agent 就不注册 delegate');

// 计划 ID 绑回运行时：下一轮 system 注入才看得见计划
$agent->getRuntime()->getToolRegistry()->get('update_plan')
    ->execute(['goal' => 'G', 'items' => [['action' => 'a']]], $ctx);
ok($agent->getRuntime()->getPlanId() !== '', '模型建的计划绑回了运行时');

echo "\n=== 结果: {$pass} 通过, {$fail} 失败 ===\n";
exit($fail > 0 ? 1 : 0);
