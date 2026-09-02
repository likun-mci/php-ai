<?php
/**
 * Phase 5.1 测试——编排层核心
 *
 * 覆盖：
 *   1. ExecutionStrategy / StrategyDecision 值对象
 *   2. StrategySelector 规则决策（direct / plan / delegate / parallel / verify / background / ask_user）
 *   3. SubAgentDefinition 标准化配置
 *   4. 权限与工具求交：子 Agent 能力永远 ⊆ 父 Agent
 *   5. BuiltinAgents 六个内置角色与工具收窄
 *   6. AgentOrchestrator 决策 + 执行 + 事件流
 *   7. TaskDependency / TaskGraph（DAG、就绪计算、阻塞传播、成环拒绝）
 *   8. BackgroundDispatcher 三档降级
 *   9. ParallelAgentExecutor 并行与顺序降级
 *  10. ResultAggregator 结果聚合
 *  11. SubAgent transcript 落盘与 resume
 *  12. Agent 快捷方法（codeAgent / task / dispatch）
 *
 * 不联网、不需要 Key。运行：php tests/agent_phase51_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\AgentRuntime;
use Ai\Agent\Orchestrator\AgentOrchestrator;
use Ai\Agent\Orchestrator\BackgroundDispatcher;
use Ai\Agent\Orchestrator\ExecutionStrategy;
use Ai\Agent\Orchestrator\ParallelAgentExecutor;
use Ai\Agent\Orchestrator\ResultAggregator;
use Ai\Agent\Orchestrator\StrategyDecision;
use Ai\Agent\Orchestrator\StrategySelector;
use Ai\Agent\Permission\PermissionManager;
use Ai\Agent\SubAgent\BuiltinAgents;
use Ai\Agent\SubAgent\SubAgentDefinition;
use Ai\Agent\SubAgent\SubAgentManager;
use Ai\Agent\Task\TaskDependency;
use Ai\Agent\Task\TaskGraph;
use Ai\Agent\Task\TaskStatus;

/** 按顺序回放预置响应的假传输层 */
class ScriptedTransport51 implements \Ai\Contracts\TransportInterface
{
    public $responses = [];
    public $requests  = [];

    public function post(string $url, array $data, array $headers = []): array
    {
        $this->requests[] = $data;
        if (!$this->responses) {
            return ['choices' => [['message' => ['role' => 'assistant', 'content' => '完成'], 'finish_reason' => 'stop']]];
        }
        return array_shift($this->responses);
    }
    public function get(string $url, array $params = [], array $headers = []): array { return []; }
    public function setTimeout(int $t): \Ai\Contracts\TransportInterface { return $this; }
    public function setProxy(string $p): \Ai\Contracts\TransportInterface { return $this; }
    public function setStreamCallback(?callable $cb): \Ai\Contracts\TransportInterface { return $this; }
}

$passed = 0;
$failed = 0;

function test($name, $ok)
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "✓ {$name}\n";
    } else {
        $failed++;
        echo "✗ {$name}\n";
    }
}

function assert_eq($name, $expected, $actual)
{
    test($name, $expected === $actual);
    if ($expected !== $actual) {
        echo "    期望: " . var_export($expected, true) . "\n";
        echo "    实际: " . var_export($actual, true) . "\n";
    }
}

function makeAI51(array $responses = [])
{
    $tr = new ScriptedTransport51();
    $tr->responses = $responses;
    $ai = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
    $ai->setTransport($tr);
    return [$ai, $tr];
}

function textReply51($text)
{
    return ['choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']]];
}

/** 造一组假工具 */
function fakeTools51(array $names)
{
    $tools = [];
    foreach ($names as $name) {
        $tools[$name] = [
            'description'  => $name,
            'input_schema' => ['type' => 'object'],
            'handler'      => function (array $in) use ($name) { return $name . ' ok'; },
        ];
    }
    return $tools;
}

$tmpDir = sys_get_temp_dir() . '/php_ai_p51_' . getmypid();
@mkdir($tmpDir, 0777, true);

// ===== 一、ExecutionStrategy / StrategyDecision =====

echo "\n=== 一、策略值对象 ===\n";

assert_eq('七种策略', 7, count(ExecutionStrategy::all()));
test('策略校验', ExecutionStrategy::isValid('delegate') && !ExecutionStrategy::isValid('nope'));
test('策略说明非空', ExecutionStrategy::describe(ExecutionStrategy::PLAN) !== '');
test('委派类策略', ExecutionStrategy::isDelegating('delegate') && ExecutionStrategy::isDelegating('parallel'));
test('非委派类', !ExecutionStrategy::isDelegating('direct'));
test('延后执行类', ExecutionStrategy::isDeferred('background') && ExecutionStrategy::isDeferred('ask_user'));

$d = StrategyDecision::delegate('explorer', '需要大范围调查');
assert_eq('委派策略', ExecutionStrategy::DELEGATE, $d->getStrategy());
assert_eq('委派目标', 'explorer', $d->getAgent());
assert_eq('委派理由', '需要大范围调查', $d->getReason());
test('is() 判断', $d->is(ExecutionStrategy::DELEGATE) && !$d->is(ExecutionStrategy::DIRECT));

$bg = StrategyDecision::background('耗时长');
test('background 策略自动置位', $bg->isBackground());
$par = StrategyDecision::parallel(['A', 'B'], '两路');
test('parallel 策略自动置位', $par->isParallel());
assert_eq('并行子任务', 2, count($par->getSubtasks()));

$invalid = new StrategyDecision('nonexistent', 'x');
assert_eq('非法策略退回 direct', ExecutionStrategy::DIRECT, $invalid->getStrategy());

$arr = $d->toArray();
test('toArray 含 reason', isset($arr['reason']) && $arr['reason'] !== '');
$rt = StrategyDecision::fromArray($arr);
assert_eq('序列化往返', 'explorer', $rt->getAgent());
test('toSummary 可读', strpos($d->toSummary(), 'delegate') !== false);

$conf = StrategyDecision::direct('x', 1.8);
assert_eq('置信度被夹到 1.0', 1.0, $conf->getConfidence());

// ===== 二、StrategySelector 规则决策 =====

echo "\n=== 二、策略选择 ===\n";

list($ai) = makeAI51();
$sam = new SubAgentManager($ai);
$sam->setParentTools(fakeTools51(['read_file', 'write_file', 'edit_file', 'grep', 'glob', 'bash']));
BuiltinAgents::registerAll($sam);

$selector = new StrategySelector($sam);

assert_eq('简单任务 → direct', ExecutionStrategy::DIRECT, $selector->select('读一下 README')->getStrategy());
assert_eq('重构任务 → plan', ExecutionStrategy::PLAN, $selector->select('重构整个用户认证系统')->getStrategy());
assert_eq('验证任务 → verify', ExecutionStrategy::VERIFY, $selector->select('跑一下测试确认没问题')->getStrategy());
assert_eq('后台任务 → background', ExecutionStrategy::BACKGROUND, $selector->select('后台扫描整个项目')->getStrategy());
assert_eq('空任务 → ask_user', ExecutionStrategy::ASK_USER, $selector->select('   ')->getStrategy());

$parallel = $selector->select('分析项目中的认证、支付、SEO');
assert_eq('并列任务 → parallel', ExecutionStrategy::PARALLEL, $parallel->getStrategy());
assert_eq('拆出 3 路', 3, count($parallel->getSubtasks()));
test('每一路都带上动词', strpos($parallel->getSubtasks()[0], '分析') === 0);

$delegate = $selector->select('审查 Auth.php 的安全问题');
assert_eq('审查任务 → delegate', ExecutionStrategy::DELEGATE, $delegate->getStrategy());
assert_eq('派给 reviewer', 'reviewer', $delegate->getAgent());

test('点名子 Agent 稳定命中', $selector->matchAgent('让 explorer 去看看依赖关系') === 'explorer');
assert_eq('匹配不到返回空串', '', $selector->matchAgent('今天天气不错'));

// 计划执行途中不再重新规划
$inPlan = $selector->select('重构整个认证系统', ['has_plan' => true]);
test('计划中途不再 plan', $inPlan->getStrategy() !== ExecutionStrategy::PLAN);

// 关掉自动委派与规划
$strict = new StrategySelector($sam, ['autoDelegate' => false, 'autoPlan' => false]);
assert_eq('关掉自动规划', ExecutionStrategy::DIRECT, $strict->select('重构整个用户认证系统')->getStrategy());
assert_eq('关掉自动委派', ExecutionStrategy::DIRECT, $strict->select('审查 Auth.php 的安全问题')->getStrategy());

// 自定义决策器
$custom = new StrategySelector($sam);
$custom->setResolver(function ($task, $context) {
    return strpos($task, '特殊') !== false ? StrategyDecision::verify('自定义规则命中') : null;
});
assert_eq('自定义决策器生效', ExecutionStrategy::VERIFY, $custom->select('特殊任务')->getStrategy());
assert_eq('返回 null 时退回规则', ExecutionStrategy::DIRECT, $custom->select('读文件')->getStrategy());

// 不该被误拆成并行的句子
$notParallel = $selector->select('分析这个方法为什么在并发情况下会返回错误的结果并给出修复方案');
test('长句不被误拆成并行', $notParallel->getStrategy() !== ExecutionStrategy::PARALLEL);

// 「改完顺便验证」不是验证任务——实测里这句曾被误判成 verify
$fixThenVerify = $selector->select('修复 divide 的除零 Bug，改完运行测试确认全部通过');
test('带改动动词的不判 verify', $fixThenVerify->getStrategy() !== ExecutionStrategy::VERIFY);
test('识别出改动动词', $selector->hasMutatingVerb('修复除零 Bug'));
test('纯验证句不含改动动词', !$selector->hasMutatingVerb('跑一下测试确认没问题'));
assert_eq('纯验证仍判 verify', ExecutionStrategy::VERIFY, $selector->select('跑一下测试确认没问题')->getStrategy());
assert_eq('改配置后确认判 direct', ExecutionStrategy::DIRECT, $selector->select('修改配置后确认服务能起来')->getStrategy());

// ===== 三、SubAgentDefinition 标准化 =====

echo "\n=== 三、SubAgent 配置标准化 ===\n";

$def = new SubAgentDefinition('reviewer', [
    'description'     => '审查',
    'prompt'          => '你是审查者',
    'tools'           => fakeTools51(['read_file', 'grep']),
    'disallowedTools' => ['write_file'],
    'model'           => 'claude-sonnet-5',
    'permissionMode'  => 'manual',
    'maxTurns'        => 12,
    'skills'          => ['php-development'],
    'mcpServers'      => ['fs'],
    'memory'          => $tmpDir . '/mem',
    'background'      => true,
    'isolation'       => 'worktree',
]);

assert_eq('名称', 'reviewer', $def->getName());
assert_eq('maxTurns 映射到 maxIter', 12, $def->getMaxIter());
assert_eq('模型', 'claude-sonnet-5', $def->getModel());
assert_eq('权限模式', 'manual', $def->getPermissionMode());
assert_eq('禁用工具', ['write_file'], $def->getDisallowedTools());
test('isToolDisallowed', $def->isToolDisallowed('write_file') && !$def->isToolDisallowed('read_file'));
assert_eq('技能', ['php-development'], $def->getSkills());
assert_eq('MCP 服务器', ['fs'], $def->getMcpServers());
assert_eq('记忆目录', $tmpDir . '/mem', $def->getMemoryDir());
test('后台标记', $def->isBackground());
test('worktree 隔离', $def->isWorktreeIsolated());

$plain = new SubAgentDefinition('plain', ['max_iter' => 7]);
assert_eq('旧写法 max_iter 仍可用', 7, $plain->getMaxIter());
test('未配置时不隔离', !$plain->isWorktreeIsolated());
$exported = $def->toArray();
test('toArray 可 JSON 化', json_encode($exported) !== false);
test('toArray 不含工具对象', !isset($exported['tools']));

// ===== 四、权限与工具求交 =====

echo "\n=== 四、子 Agent 能力 ⊆ 父 Agent ===\n";

$parentTools = fakeTools51(['read_file', 'grep', 'glob']);
$sam2 = new SubAgentManager($ai);
$sam2->setParentTools($parentTools);

// 子 Agent 声明了父 Agent 没有的工具
$sam2->register('greedy', [
    'description' => '想要更多权限',
    'tools'       => fakeTools51(['read_file', 'write_file', 'bash']),
]);
$resolved = $sam2->resolveTools($sam2->get('greedy'));
assert_eq('父没有的工具拿不到', ['read_file'], array_keys($resolved));

// 未声明 tools 的子 Agent 拿到父全集
$sam2->register('inherit', ['description' => '继承全部']);
assert_eq('未声明则继承父工具集', 3, count($sam2->resolveTools($sam2->get('inherit'))));

// disallowedTools 只做减法
$sam2->register('narrow', ['description' => '收窄', 'disallowedTools' => ['grep', 'glob']]);
assert_eq('disallowedTools 生效', ['read_file'], array_keys($sam2->resolveTools($sam2->get('narrow'))));

// 权限模式只能收紧
$pm = new PermissionManager(PermissionManager::MODE_AUTO);
$sam2->setParentPermission($pm);
$sam2->register('stricter', ['description' => 'x', 'permissionMode' => 'manual']);
$sam2->register('looser', ['description' => 'x', 'permissionMode' => 'bypass']);
$strictRuntime = $sam2->buildRuntime($sam2->get('stricter'));
$looseRuntime = $sam2->buildRuntime($sam2->get('looser'));
test('子 Agent 可以收紧权限', $strictRuntime instanceof AgentRuntime);
test('放宽权限的请求被忽略',
    $looseRuntime->getPermission() === null || $looseRuntime->getPermission()->getMode() !== 'bypass');

// ===== 五、内置 SubAgent =====

echo "\n=== 五、内置 SubAgent ===\n";

assert_eq('六个内置角色', 6, count(BuiltinAgents::names()));
test('含 explorer', in_array(BuiltinAgents::EXPLORER, BuiltinAgents::names(), true));
assert_eq('explorer 工具', ['read_file', 'grep', 'glob', 'code_index'], BuiltinAgents::toolNames('explorer'));
test('explorer 带上代码索引', in_array('code_index', BuiltinAgents::toolNames('explorer'), true));
test('explorer 是只读的', BuiltinAgents::isReadOnly('explorer'));
test('reviewer 是只读的', BuiltinAgents::isReadOnly('reviewer'));
test('coder 不是只读的', !BuiltinAgents::isReadOnly('coder'));
test('tester 拿不到写工具', !in_array('write_file', BuiltinAgents::toolNames('tester'), true));
assert_eq('未知角色配置为 null', null, BuiltinAgents::config('nope'));

$samB = new SubAgentManager($ai);
$samB->setParentTools(fakeTools51(['read_file', 'write_file', 'edit_file', 'grep', 'glob', 'bash']));
$registered = BuiltinAgents::registerAll($samB);
assert_eq('注册六个', 6, count($registered));
assert_eq('父没有 code_index 时 explorer 仍是三个工具', 3, count($samB->resolveTools($samB->get('explorer'))));
assert_eq('coder 实际工具六个', 6, count($samB->resolveTools($samB->get('coder'))));

// 父 Agent 没有 bash 时，coder 也拿不到
$samC = new SubAgentManager($ai);
$samC->setParentTools(fakeTools51(['read_file', 'grep']));
BuiltinAgents::register($samC, ['coder']);
assert_eq('父没有 bash 时 coder 也没有', ['read_file', 'grep'], array_keys($samC->resolveTools($samC->get('coder'))));

// 只装部分
$samD = new SubAgentManager($ai);
$samD->setParentTools(fakeTools51(['read_file', 'grep', 'glob']));
assert_eq('只装两个', 2, count(BuiltinAgents::register($samD, ['explorer', 'tester'])));

// ===== 六、AgentOrchestrator =====

echo "\n=== 六、编排器 ===\n";

list($ai2, $tr2) = makeAI51([textReply51('看完了')]);
$runtime = new AgentRuntime($ai2);
$runtime->setSystem('助手');

$events = [];
$orchestrator = new AgentOrchestrator($runtime, ['subAgents' => $samB]);
$orchestrator->onEvent(function ($e) use (&$events) { $events[] = $e; });

$decision = $orchestrator->decide('审查 Auth.php 的安全问题');
assert_eq('编排器决策', ExecutionStrategy::DELEGATE, $decision->getStrategy());
test('决策进了事件流', count(array_filter($events, function ($e) {
    return $e['type'] === 'strategy_decision';
})) === 1);
$evt = $events[0];
test('决策事件带理由', isset($evt['reason']) && $evt['reason'] !== '');
test('lastDecision 可取', $orchestrator->lastDecision() === $decision);
assert_eq('决策历史 1 条', 1, count($orchestrator->decisions()));

// 委派执行
list($ai3) = makeAI51([textReply51('审查完成，没有发现问题')]);
$samE = new SubAgentManager($ai3);
$samE->setParentTools(fakeTools51(['read_file']));
BuiltinAgents::register($samE, ['reviewer']);
$runtime3 = new AgentRuntime($ai3);
$orch3 = new AgentOrchestrator($runtime3, ['subAgents' => $samE]);
$result = $orch3->execute('审查代码', StrategyDecision::delegate('reviewer', '匹配'));
test('委派执行成功', $result->isDone());
test('结果来自子 Agent', strpos($result->getText(), '审查完成') !== false);
assert_eq('结果标注策略', ExecutionStrategy::DELEGATE, $result->getExtra()['strategy']);

// 委派目标不存在 → 退回直接执行
list($ai4, $tr4) = makeAI51([textReply51('我自己干')]);
$runtime4 = new AgentRuntime($ai4);
$orch4 = new AgentOrchestrator($runtime4, ['subAgents' => $samE]);
$result4 = $orch4->execute('干活', StrategyDecision::delegate('ghost', 'x'));
assert_eq('目标不存在时退回直接执行', '我自己干', $result4->getText());

// 没挂 SubAgentManager 时行为不变
list($ai5, $tr5) = makeAI51([textReply51('直接干完了')]);
$runtime5 = new AgentRuntime($ai5);
$orch5 = new AgentOrchestrator($runtime5);
$result5 = $orch5->handle('审查 Auth.php 的安全问题');
assert_eq('无子 Agent 时退回直接执行', '直接干完了', $result5->getText());

// ask_user 策略
$orch6 = new AgentOrchestrator(new AgentRuntime($ai5));
$askResult = $orch6->execute('', StrategyDecision::askUser('需求不清'));
test('ask_user 停在等待用户', $askResult->getStopReason() === \Ai\Agent\Loop\StopReason::WAITING_USER);

// ===== 七、Task DAG =====

echo "\n=== 七、Task DAG ===\n";

$graph = new TaskGraph();
$graph->addTask('a')->addTask('b')->addTask('c')->addTask('d');
test('建立依赖 b←a', $graph->dependsOn('b', 'a'));
test('建立依赖 c←a', $graph->dependsOn('c', 'a'));
test('建立依赖 d←c', $graph->dependsOn('d', 'c'));

assert_eq('初始只有 a 就绪', ['a'], $graph->ready());
$graph->markCompleted('a');
$ready = $graph->ready();
sort($ready);
assert_eq('a 完成后 b/c 可并行', ['b', 'c'], $ready);

$graph->markCompleted('c');
$ready = $graph->ready();
sort($ready);
assert_eq('c 完成后 d 就绪', ['b', 'd'], $ready);

assert_eq('依赖查询', ['a'], $graph->dependenciesOf('c'));
assert_eq('反向依赖查询', ['d'], $graph->dependentsOf('c'));

test('拒绝自依赖', !$graph->dependsOn('a', 'a'));
test('拒绝成环', !$graph->dependsOn('a', 'd'));
test('重复依赖幂等', $graph->dependsOn('b', 'a'));

$layers = $graph->layers();
test('分层：第一层是 a', $layers[0] === ['a']);
test('分层数 >= 3', count($layers) >= 3);

// 失败传播
$g2 = new TaskGraph();
$g2->addTask('x')->addTask('y')->addTask('z');
$g2->dependsOn('y', 'x');
$g2->dependsOn('z', 'y');
$g2->markFailed('x');
assert_eq('下游被阻塞', TaskGraph::STATE_BLOCKED, $g2->getStatus('y'));
assert_eq('阻塞向下传播', TaskGraph::STATE_BLOCKED, $g2->getStatus('z'));
assert_eq('blocked 列表', 2, count($g2->blocked()));
assert_eq('阻塞后无就绪任务', 0, count($g2->ready()));

// 软依赖：上游失败也能跑
$g3 = new TaskGraph();
$g3->addTask('p')->addTask('q');
$g3->dependsOn('q', 'p', TaskDependency::TYPE_SOFT);
$g3->markFailed('p');
assert_eq('软依赖不阻塞', ['q'], $g3->ready());

// 完成判定与序列化
$g4 = new TaskGraph();
$g4->addTask('only');
test('未完成', !$g4->isComplete());
$g4->markCompleted('only');
test('全部终结即完成', $g4->isComplete());

$restored = TaskGraph::fromArray($graph->toArray());
assert_eq('序列化往返：任务数', count($graph->tasks()), count($restored->tasks()));
assert_eq('序列化往返：依赖数', count($graph->dependencies()), count($restored->dependencies()));

$dep = new TaskDependency('d', 'c', TaskDependency::TYPE_HARD, '必须先建表');
test('依赖是硬依赖', $dep->isHard());
assert_eq('依赖说明', '必须先建表', $dep->getReason());
assert_eq('依赖序列化往返', 'c', TaskDependency::fromArray($dep->toArray())->getDependsOn());

// ===== 八、BackgroundDispatcher =====

echo "\n=== 八、后台派发 ===\n";

$dispatcher = new BackgroundDispatcher(['allowFork' => false, 'resultDir' => $tmpDir . '/bg']);
assert_eq('无 runner 无 fork → sync', BackgroundDispatcher::MODE_SYNC, $dispatcher->mode());

$handle = $dispatcher->dispatch('task_sync', function () { return '干完了'; });
assert_eq('sync 档立即完成', 'completed', $handle['status']);
assert_eq('sync 档如实标注非后台', false, $handle['background']);
assert_eq('sync 档返回结果', '干完了', $handle['result']);
assert_eq('查询句柄', 'completed', $dispatcher->status('task_sync')['status']);
assert_eq('取结果', '干完了', $dispatcher->result('task_sync'));
assert_eq('查询不存在的任务', null, $dispatcher->status('nope'));

$failHandle = $dispatcher->dispatch('task_fail', function () { throw new \RuntimeException('炸了'); });
assert_eq('异常记为 failed', 'failed', $failHandle['status']);
test('记录了错误信息', strpos($failHandle['error'], '炸了') !== false);

$runnerCalls = [];
$withRunner = new BackgroundDispatcher([
    'runner' => function (array $payload) use (&$runnerCalls) {
        $runnerCalls[] = $payload['task_id'];
        return null;   // 真异步：不立即返回结果
    },
]);
assert_eq('有 runner → runner 档', BackgroundDispatcher::MODE_RUNNER, $withRunner->mode());
$h = $withRunner->dispatch('task_async', function () { return 'x'; });
assert_eq('runner 档标记 running', 'running', $h['status']);
test('runner 档标记为后台', $h['background']);
assert_eq('runner 收到 task_id', ['task_async'], $runnerCalls);

// runner 抛异常
$badRunner = new BackgroundDispatcher(['runner' => function () { throw new \RuntimeException('runner 挂了'); }]);
assert_eq('runner 异常记为 failed', 'failed', $badRunner->dispatch('t', function () { return 1; })['status']);

// fork 能力检测（不同环境结果不同，只验证接口一致）
$forkable = new BackgroundDispatcher(['resultDir' => $tmpDir . '/bg2']);
test('canFork 返回布尔', is_bool($forkable->canFork()));
test('mode 是三档之一', in_array($forkable->mode(), ['runner', 'fork', 'sync'], true));

// ===== 九、ParallelAgentExecutor =====

echo "\n=== 九、并行子 Agent ===\n";

list($ai6) = makeAI51([
    textReply51('认证模块分析完毕'),
    textReply51('支付模块分析完毕'),
    textReply51('SEO 分析完毕'),
]);
$samF = new SubAgentManager($ai6);
$samF->setParentTools(fakeTools51(['read_file', 'grep', 'glob']));
BuiltinAgents::register($samF, ['explorer']);

$parEvents = [];
$executor = new ParallelAgentExecutor($samF);
$executor->onEvent(function ($e) use (&$parEvents) { $parEvents[] = $e['type']; });

assert_eq('默认并发上限 4', 4, $executor->getMaxConcurrency());
assert_eq('无 runner → 顺序降级', ParallelAgentExecutor::MODE_SEQUENTIAL, $executor->mode());

$results = $executor->run([
    ['agent' => 'explorer', 'task' => '分析认证'],
    ['agent' => 'explorer', 'task' => '分析支付'],
    ['agent' => 'explorer', 'task' => '分析 SEO'],
]);
assert_eq('三路都有结果', 3, count($results));
test('结果带 agent', $results[0]['agent'] === 'explorer');
test('结果带 task_id', isset($results[0]['task_id']));
test('触发了并行开始事件', in_array('parallel_agents_started', $parEvents, true));
test('触发了子 Agent 事件', in_array('subagent_started', $parEvents, true));

assert_eq('空任务列表返回空', 0, count($executor->run([])));
assert_eq('过滤掉无 task 的项', 0, count($executor->run([['agent' => 'explorer']])));

// 注入并行运行器
$ranJobs = [];
$executor->setRunner(function (array $jobs) use (&$ranJobs) {
    $ranJobs = $jobs;
    $out = [];
    foreach ($jobs as $job) {
        $out[] = ['status' => 'completed', 'summary' => '并行完成：' . $job['task']];
    }
    return $out;
});
assert_eq('有 runner → runner 档', ParallelAgentExecutor::MODE_RUNNER, $executor->mode());
$parallelResults = $executor->run([
    ['agent' => 'explorer', 'task' => 'A'],
    ['agent' => 'explorer', 'task' => 'B'],
]);
assert_eq('runner 返回两条', 2, count($parallelResults));
test('runner 结果被采用', strpos($parallelResults[0]['summary'], '并行完成') === 0);
assert_eq('runner 收到两个 job', 2, count($ranJobs));

// runner 返回条数对不上 → 退回顺序执行
$executor->setRunner(function (array $jobs) { return ['只有一条']; });
$mismatched = $executor->run([
    ['agent' => 'explorer', 'task' => 'A'],
    ['agent' => 'explorer', 'task' => 'B'],
]);
assert_eq('条数对不上时退回顺序执行', 2, count($mismatched));

// ===== 十、ResultAggregator =====

echo "\n=== 十、结果聚合 ===\n";

$aggregator = new ResultAggregator();
$agg = $aggregator->aggregate([
    ['agent' => 'explorer', 'task' => '分析认证', 'status' => 'completed', 'task_id' => 't1',
     'summary' => "认证走 JWT，实现在 src/Auth.php 第 42 行。\n建议补充 token 过期处理。"],
    ['agent' => 'explorer', 'task' => '分析支付', 'status' => 'completed', 'task_id' => 't2',
     'summary' => '支付在 src/Payment.php，依赖第三方 SDK。'],
    ['agent' => 'explorer', 'task' => '分析 SEO', 'status' => 'failed', 'task_id' => 't3',
     'reason' => 'max_iter', 'summary' => '超出迭代上限'],
]);

assert_eq('两路完成', 2, $agg['stats']['completed']);
assert_eq('一路失败', 1, $agg['stats']['failed']);
assert_eq('findings 两条', 2, count($agg['findings']));
assert_eq('errors 一条', 1, count($agg['errors']));
test('抽出文件路径', in_array('src/Auth.php', $agg['files'], true));
test('抽出建议', count($agg['recommendations']) > 0);
assert_eq('transcripts 三条', 3, count($agg['transcripts']));
test('摘要含统计', strpos($agg['summary'], '共 3 路任务') === 0);
test('摘要含失败信息', strpos($agg['summary'], '未完成') !== false);

$aggregator->setSummarizer(function (array $results) { return '模型生成的摘要'; });
assert_eq('自定义摘要器生效', '模型生成的摘要', $aggregator->aggregate([])['summary']);

$aggregator->setSummarizer(function () { throw new \RuntimeException('摘要器挂了'); });
test('摘要器异常时退回规则拼接', strpos($aggregator->aggregate([])['summary'], '共 0 路任务') === 0);

// 长文本截断（下限 50 字符，传更小的值会被夹到 50）
$longAgg = (new ResultAggregator(['perResultLimit' => 60]))->aggregate([
    ['agent' => 'a', 'task' => 't', 'status' => 'completed', 'summary' => str_repeat('长', 300)],
]);
test('单路结论被截断', mb_strlen($longAgg['findings'][0]['content'], 'UTF-8') === 61);
$flooredAgg = (new ResultAggregator(['perResultLimit' => 5]))->aggregate([
    ['agent' => 'a', 'task' => 't', 'status' => 'completed', 'summary' => str_repeat('长', 300)],
]);
test('截断长度有下限', mb_strlen($flooredAgg['findings'][0]['content'], 'UTF-8') === 51);

// ===== 十一、Transcript 落盘与 resume =====

echo "\n=== 十一、Transcript 落盘与续跑 ===\n";

list($ai7) = makeAI51([textReply51('第一轮的结论'), textReply51('第二轮继续完成')]);
$samG = new SubAgentManager($ai7);
$samG->setParentTools(fakeTools51(['read_file']));
$samG->setTranscriptDir($tmpDir . '/transcripts');
BuiltinAgents::register($samG, ['explorer']);

$runId = $samG->runSync('explorer', '调查登录流程');
test('transcript 已落盘', is_file($tmpDir . '/transcripts/' . $runId . '.json'));

// 新实例（模拟另一个进程）能读到
$samH = new SubAgentManager($ai7);
$samH->setTranscriptDir($tmpDir . '/transcripts');
$loaded = $samH->getTranscript($runId);
test('另一进程读得到 transcript', is_array($loaded) && $loaded['agent'] === 'explorer');

$resumeId = $samG->resume($runId, '继续看看权限校验');
test('resume 返回新 run', $resumeId !== '' && $resumeId !== $runId);
$resumed = $samG->getTranscript($resumeId);
assert_eq('记录了来源', $runId, $resumed['resumed_from']);
assert_eq('续跑不存在的记录返回空', '', $samG->resume('nope'));

// ===== 十一之二、队列依赖调度与后台完成回调 =====

echo "\n=== 十一之二、队列依赖调度 ===\n";

list($aiQ) = makeAI51([textReply51('A 完成'), textReply51('B 完成')]);
$queue = new \Ai\Agent\Queue\AgentQueue();
$rtA = new AgentRuntime($aiQ);
$rtB = new AgentRuntime($aiQ);
$idA = $queue->dispatch('任务 A', $rtA, [['role' => 'user', 'content' => 'A']])->getId();
$idB = $queue->dispatch('任务 B', $rtB, [['role' => 'user', 'content' => 'B']])->getId();

// B 依赖 A：即便 B 先排在前面也得等 A
test('声明依赖成功', $queue->dependsOn($idB, $idA));
test('依赖图已创建', $queue->getGraph() instanceof TaskGraph);
assert_eq('先调度到 A', $idA, $queue->next()->getId());

$queue->processNext();
assert_eq('A 完成后轮到 B', $idB, $queue->next()->getId());
$queue->processNext();
assert_eq('队列已清空', 0, $queue->pendingCount());

// 依赖未满足时不调度
list($aiQ2) = makeAI51();
$queue2 = new \Ai\Agent\Queue\AgentQueue();
$idX = $queue2->dispatch('X', new AgentRuntime($aiQ2), [['role' => 'user', 'content' => 'x']])->getId();
$idY = $queue2->dispatch('Y', new AgentRuntime($aiQ2), [['role' => 'user', 'content' => 'y']])->getId();
$queue2->dependsOn($idX, $idY);
$queue2->dependsOn($idY, $idX);   // 成环，应被拒绝
test('队列拒绝成环依赖', count($queue2->getGraph()->dependencies()) === 1);

// 后台完成回调
$completed = [];
$dispatcher2 = new BackgroundDispatcher(['allowFork' => false]);
$dispatcher2->onComplete(function (array $handle) use (&$completed) {
    $completed[] = $handle['task_id'];
});
$dispatcher2->dispatch('cb_task', function () { return 'ok'; });
assert_eq('完成回调被触发', ['cb_task'], $completed);

$dispatcher2->dispatch('cb_fail', function () { throw new \RuntimeException('x'); });
assert_eq('失败也触发回调', 2, count($completed));

$badCb = new BackgroundDispatcher(['allowFork' => false]);
$badCb->onComplete(function () { throw new \RuntimeException('回调炸了'); });
assert_eq('回调异常不影响任务结果', 'completed',
    $badCb->dispatch('cb_safe', function () { return 'ok'; })['status']);

// ===== 十二、Agent 快捷方法 =====

echo "\n=== 十二、Agent 快捷方法 ===\n";

list($ai8, $tr8) = makeAI51([textReply51('任务完成')]);
$agent = (new Agent($ai8))->setWorkdir($tmpDir);
$agent->codeAgent();

$samI = $agent->getRuntime()->getSubAgentManager();
test('codeAgent 装上了子 Agent', $samI !== null);
assert_eq('六个内置角色', 6, count($samI->all()));
test('codeAgent 装上了工具', count($agent->getRuntime()->getToolRegistry()->all()) > 0);
test('codeAgent 装上了验证器', $agent->getRuntime()->getVerificationManager() !== null);
test('codeAgent 装上了计划管理器', $agent->getRuntime()->getPlanManager() !== null);
test('codeAgent 装上了反思', $agent->getRuntime()->getReflectionManager() !== null);
test('explorer 拿不到写工具',
    !isset($samI->resolveTools($samI->get('explorer'))['write_file']));

test('orchestrator() 惰性创建', $agent->orchestrator() instanceof AgentOrchestrator);
test('orchestrator() 复用同一实例', $agent->orchestrator() === $agent->orchestrator());

$taskResult = $agent->task('读一下 README');
test('task() 返回结果', $taskResult->getText() !== '');
test('task() 记录了决策', $agent->orchestrator()->lastDecision() !== null);

$handle = $agent->dispatch('扫描整个项目');
test('dispatch 返回 task_id', isset($handle['task_id']) && $handle['task_id'] !== '');
test('dispatch 标注了档位', in_array($handle['mode'], ['runner', 'fork', 'sync'], true));
test('taskStatus 可查', $agent->taskStatus($handle['task_id']) !== null);

// 只装部分内置角色
list($ai9) = makeAI51();
$agent2 = (new Agent($ai9))->setWorkdir($tmpDir);
$agent2->codeAgent(['agents' => ['explorer', 'coder']]);
assert_eq('只装两个角色', 2, count($agent2->getRuntime()->getSubAgentManager()->all()));

// ===== 清理 =====

exec('rm -rf ' . escapeshellarg($tmpDir));

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);
