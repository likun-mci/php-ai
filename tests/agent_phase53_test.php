<?php
/**
 * Phase 5.3 测试——规模化与开发者 API
 *
 * 覆盖：
 *   1. ToolGroup 分组开关与过滤
 *   2. ToolDiscovery 按需发现与激活
 *   3. PermissionPolicy 分层策略（DENY 优先）
 *   4. EventLog 事件回放（since / sinceId / SSE）
 *   5. AgentScheduler 优先级、并发上限、重试
 *   6. ModelRouter 按角色与复杂度选模型
 *   7. ArtifactManager 产物存取与引用
 *   8. AgentResult 结构化契约
 *   9. Agent::create 链式 API
 *
 * 不联网、不需要 Key。运行：php tests/agent_phase53_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\AgentResult;
use Ai\Agent\Event\EventLog;
use Ai\Agent\Orchestrator\AgentScheduler;
use Ai\Agent\Orchestrator\ArtifactManager;
use Ai\Agent\Orchestrator\ModelRouter;
use Ai\Agent\Permission\PermissionManager;
use Ai\Agent\Permission\PermissionPolicy;
use Ai\Agent\Task\TaskGraph;
use Ai\Agent\Tool\ToolDiscovery;
use Ai\Agent\Tool\ToolGroup;
use Ai\Agent\Tool\ToolRegistry;

class ScriptedTransport53 implements \Ai\Contracts\TransportInterface
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

function makeAI53(array $responses = [])
{
    $tr = new ScriptedTransport53();
    $tr->responses = $responses;
    $ai = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
    $ai->setTransport($tr);
    return [$ai, $tr];
}

function fakeTool53($name, $description)
{
    return [
        'description'  => $description,
        'input_schema' => ['type' => 'object'],
        'handler'      => function (array $in) use ($name) { return $name . ' ok'; },
    ];
}

$tmpDir = sys_get_temp_dir() . '/php_ai_p53_' . getmypid();
@mkdir($tmpDir, 0777, true);

// ===== 一、ToolGroup =====

echo "\n=== 一、工具分组 ===\n";

$groups = new ToolGroup();
assert_eq('八个内置分组', 8, count(ToolGroup::builtinNames()));
test('read_file 属于 filesystem', in_array('filesystem', $groups->groupsOf('read_file'), true));
test('默认全部启用', $groups->isEnabled('read_file'));
test('未归组的工具默认可用', $groups->isEnabled('some_random_tool'));

$groups->disable(ToolGroup::DEPLOYMENT);
test('停用分组后工具不可用', !$groups->isEnabled('deploy'));
test('其它分组不受影响', $groups->isEnabled('read_file'));
test('停用的分组不在启用列表里', !in_array(ToolGroup::DEPLOYMENT, $groups->enabledGroups(), true));

$groups->enable(ToolGroup::DEPLOYMENT);
test('重新启用生效', $groups->isEnabled('deploy'));

$groups->only([ToolGroup::FILESYSTEM]);
test('only 之后只剩 filesystem', $groups->isEnabled('read_file'));
test('only 之后其它分组停用', !$groups->isEnabled('deploy') && !$groups->isEnabled('browser'));

$groups2 = new ToolGroup();
$groups2->assign('custom', ['my_tool', 'my_other_tool']);
assert_eq('自定义分组', ['my_tool', 'my_other_tool'], $groups2->toolsIn('custom'));
$groups2->disable('custom');
test('自定义分组可停用', !$groups2->isEnabled('my_tool'));

$filtered = $groups2->filter([
    'read_file' => fakeTool53('read_file', '读'),
    'my_tool'   => fakeTool53('my_tool', '自定义'),
]);
assert_eq('过滤掉停用分组的工具', ['read_file'], array_keys($filtered));

// 工具属于多个分组，任一启用即可用
$groups3 = new ToolGroup();
$groups3->assign('a', ['shared'])->assign('b', ['shared']);
$groups3->disable('a');
test('属于多个分组时任一启用即可用', $groups3->isEnabled('shared'));
$groups3->disable('b');
test('全部分组停用后不可用', !$groups3->isEnabled('shared'));

// ===== 二、ToolDiscovery =====

echo "\n=== 二、工具按需发现 ===\n";

$registry = new ToolRegistry();
$registry->registerAll([
    'read_file' => fakeTool53('read_file', '读取文件内容'),
    'grep'      => fakeTool53('grep', '搜索文件内容'),
    'sql_query' => fakeTool53('sql_query', '执行 SQL 查询语句，操作 database'),
    'db_schema' => fakeTool53('db_schema', '查看 database 表结构'),
    'deploy'    => fakeTool53('deploy', '部署到生产环境'),
]);

$discovery = new ToolDiscovery($registry, ['alwaysAvailable' => ['read_file', 'grep']]);
$initial = $discovery->initialTools();
assert_eq('初始 3 个（2 常用 + search_tools）', 3, count($initial));
test('含 search_tools', isset($initial['search_tools']));
test('不含数据库工具', !isset($initial['sql_query']));

$hits = $discovery->search('database');
test('搜到数据库工具', count($hits) >= 2);
$names = array_map(function ($h) { return $h['name']; }, $hits);
test('命中 sql_query', in_array('sql_query', $names, true));
test('搜索结果带描述', $hits[0]['description'] !== '');
assert_eq('空查询返回空', 0, count($discovery->search('')));
assert_eq('无关查询返回空', 0, count($discovery->search('量子力学')));

test('激活成功', $discovery->activate('sql_query'));
test('激活不存在的工具失败', !$discovery->activate('nope'));
test('已激活列表', in_array('sql_query', $discovery->activated(), true));
test('激活后进入可用工具', isset($discovery->activeTools()['sql_query']));

$discovery->deactivate('sql_query');
test('停用后移出可用工具', !isset($discovery->activeTools()['sql_query']));

// search_tools 工具本身可执行，且会自动激活
$searchTool = $discovery->searchToolDefinition();
$output = call_user_func($searchTool['handler'], ['query' => 'database']);
test('search_tools 返回结果', strpos($output, 'sql_query') !== false);
test('搜索后自动激活', in_array('sql_query', $discovery->activated(), true));
$output = call_user_func($searchTool['handler'], ['query' => '']);
test('缺 query 报错', strpos($output, 'ERROR') === 0);
$output = call_user_func($searchTool['handler'], ['query' => '量子力学']);
test('搜不到时说明白', strpos($output, '没有找到') !== false);

// 分组停用的工具搜不出来
$groupFilter = new ToolGroup();
$groupFilter->disable(ToolGroup::DEPLOYMENT);
$discovery2 = new ToolDiscovery($registry, ['groups' => $groupFilter]);
$names2 = array_map(function ($h) { return $h['name']; }, $discovery2->search('部署'));
test('停用分组的工具搜不出来', !in_array('deploy', $names2, true));
test('停用分组的工具激活失败', !$discovery2->activate('deploy'));

// ===== 三、分层权限策略 =====

echo "\n=== 三、分层权限 ===\n";

$policy = new PermissionPolicy();
$policy->layer(PermissionPolicy::LAYER_GLOBAL)
    ->allow('Bash(git *)')
    ->deny('Bash(rm -rf *)')
    ->deny('Write(.env)');
$policy->layer(PermissionPolicy::LAYER_TASK)->allow('Bash(php *)');

assert_eq('全局允许的放行', PermissionPolicy::ALLOW, $policy->check('Bash', 'git status'));
assert_eq('全局禁止的拒绝', PermissionPolicy::DENY, $policy->check('Bash', 'rm -rf /'));
assert_eq('没人表态的问人', PermissionPolicy::ASK, $policy->check('Bash', 'curl example.com'));
assert_eq('任务层允许的放行', PermissionPolicy::ALLOW, $policy->check('Bash', 'php -v'));

// DENY 优先：即便别的层允许，全局禁令仍然生效
$policy->layer(PermissionPolicy::LAYER_SKILL)->allow('Bash(rm -rf *)');
assert_eq('DENY 优先于任何层的 allow', PermissionPolicy::DENY, $policy->check('Bash', 'rm -rf /tmp'));

$explain = $policy->explain('Bash', 'rm -rf /');
assert_eq('说明来自哪一层', PermissionPolicy::LAYER_GLOBAL, $explain['layer']);
assert_eq('说明命中哪条规则', 'Bash(rm -rf *)', $explain['rule']);
assert_eq('放行也能说明理由', PermissionPolicy::ALLOW, $policy->explain('Bash', 'git log')['decision']);
assert_eq('没人表态时无规则', '', $policy->explain('Bash', 'curl x')['rule']);

// 只写工具名 = 整个工具
$toolLevel = new PermissionPolicy();
$toolLevel->layer('global')->allow('read_file');
assert_eq('工具级放行', PermissionPolicy::ALLOW, $toolLevel->check('read_file', 'anything'));

// 清层
$policy->clearLayer(PermissionPolicy::LAYER_TASK);
assert_eq('清层后不再放行', PermissionPolicy::ASK, $policy->check('Bash', 'php -v'));
assert_eq('清层不影响其它层', PermissionPolicy::ALLOW, $policy->check('Bash', 'git status'));

// 应用到 PermissionManager
$pm = new PermissionManager(PermissionManager::MODE_MANUAL);
$policy->applyTo($pm);
test('规则已写入权限管理器', count($pm->getRules()) > 0);

// ===== 四、EventLog 回放 =====

echo "\n=== 四、事件回放 ===\n";

$log = new EventLog($tmpDir . '/events', ['sessionId' => 's1']);
for ($i = 1; $i <= 5; $i++) {
    $log->append(['type' => 'agent_text', 'id' => 'evt_' . $i, 'sequence' => $i, 'session_id' => 's1']);
}
assert_eq('记录 5 条', 5, $log->count('s1'));
assert_eq('最新 sequence', 5, $log->lastSequence('s1'));

$missed = $log->since(3, 's1');
assert_eq('补发 2 条', 2, count($missed));
assert_eq('补发从 4 开始', 4, $missed[0]['sequence']);
assert_eq('从 0 开始补发全部', 5, count($log->since(0, 's1')));
assert_eq('超出范围补发 0 条', 0, count($log->since(99, 's1')));

$byId = $log->sinceId('evt_3', 's1');
assert_eq('按事件 ID 补发 2 条', 2, count($byId));
assert_eq('ID 找不到时补发全部（宁可重发不漏发）', 5, count($log->sinceId('evt_nope', 's1')));
assert_eq('空 ID 补发全部', 5, count($log->sinceId('', 's1')));

$log->append(['type' => 'tool_call', 'id' => 'evt_6', 'sequence' => 6, 'session_id' => 's1', 'task_id' => 't1']);
assert_eq('按类型过滤', 1, count($log->ofType('tool_call', 's1')));
assert_eq('按任务过滤', 1, count($log->ofTask('t1', 's1')));

// 另一个进程读得到
$log2 = new EventLog($tmpDir . '/events');
assert_eq('跨进程可见', 6, $log2->count('s1'));

// 没有 sequence 的事件自动补
$log->append(['type' => 'done', 'session_id' => 's2']);
assert_eq('自动补 sequence', 1, $log->lastSequence('s2'));
$log->append(['type' => 'done', 'session_id' => 's2']);
assert_eq('sequence 递增', 2, $log->lastSequence('s2'));
test('自动补时间戳', isset($log->all('s2')[0]['timestamp']));

$sse = EventLog::toSse($log->since(5, 's1'));
test('SSE 帧含 id', strpos($sse, 'id: evt_6') !== false);
test('SSE 帧含 event', strpos($sse, 'event: tool_call') !== false);
test('SSE 帧含 data', strpos($sse, 'data: {') !== false);

$log->clear('s2');
assert_eq('clear 生效', 0, $log->count('s2'));

// 内存模式
$memLog = new EventLog();
$memLog->append(['type' => 'x', 'sequence' => 1]);
assert_eq('内存模式可用', 1, $memLog->count());

// recorder 挂到 Agent
list($aiE) = makeAI53();
$agentE = new Agent($aiE);
$logE = $agentE->eventLog($tmpDir . '/agent_events');
$agentE->run([['role' => 'user', 'content' => '干活']]);
test('Agent 事件被记录', $logE->count() > 0);
test('eventLog 复用同一实例', $agentE->eventLog() === $logE);

// ===== 五、AgentScheduler =====

echo "\n=== 五、调度器 ===\n";

$scheduler = new AgentScheduler(['max_concurrent' => 2, 'max_subagents' => 2, 'maxRetries' => 1]);

test('提交任务', $scheduler->submit('t1', '普通任务', AgentScheduler::PRIORITY_NORMAL));
test('提交高优先级', $scheduler->submit('t2', '紧急任务', AgentScheduler::PRIORITY_HIGH));
test('提交低优先级', $scheduler->submit('t3', '不急', AgentScheduler::PRIORITY_LOW));
test('重复提交失败', !$scheduler->submit('t1'));
test('空 ID 提交失败', !$scheduler->submit(''));
assert_eq('排队 3 个', 3, $scheduler->pendingCount());

assert_eq('高优先级先跑', 't2', $scheduler->next());
test('开始执行', $scheduler->start('t2'));
assert_eq('运行中 1 个', 1, $scheduler->runningCount());
assert_eq('排队剩 2 个', 2, $scheduler->pendingCount());
assert_eq('接着是普通优先级', 't1', $scheduler->next());

$scheduler->start('t1');
assert_eq('并发已满时不再给任务', null, $scheduler->next());
test('并发已满时无法启动', !$scheduler->start('t3'));

assert_eq('完成任务', 'completed', $scheduler->finish('t2', true));
assert_eq('完成后释放并发', 't3', $scheduler->next());
assert_eq('状态查询', 'completed', $scheduler->statusOf('t2')['status']);
assert_eq('排队中的状态', 'queued', $scheduler->statusOf('t3')['status']);
assert_eq('运行中的状态', 'running', $scheduler->statusOf('t1')['status']);
assert_eq('未知任务无状态', null, $scheduler->statusOf('nope'));

// 失败重试
assert_eq('失败后重新入队', 'requeued', $scheduler->finish('t1', false, '模型超时'));
test('重试任务回到队列', in_array('t1', $scheduler->pending(), true));
$scheduler->start('t1');
assert_eq('超过重试上限判失败', 'failed', $scheduler->finish('t1', false, '又失败了'));
assert_eq('失败状态带原因', '又失败了', $scheduler->statusOf('t1')['error']);

// 并发额度
test('申请子 Agent 额度', $scheduler->acquire('subagents'));
test('再申请一个', $scheduler->acquire('subagents'));
test('超出上限申请失败', !$scheduler->acquire('subagents'));
test('额度用尽时 hasCapacity 为假', !$scheduler->hasCapacity('subagents'));
$scheduler->release('subagents');
test('释放后可再申请', $scheduler->hasCapacity('subagents'));
test('未知类型申请失败', !$scheduler->acquire('nope'));

$stats = $scheduler->stats();
test('统计含上限', isset($stats['limits']['max_concurrent']));
test('统计含占用', isset($stats['usage']['subagents']));

// max_tasks 上限
$small = new AgentScheduler(['max_tasks' => 2]);
$small->submit('a');
$small->submit('b');
test('超出 max_tasks 拒绝提交', !$small->submit('c'));

// 与依赖图联动
$graph = new TaskGraph();
$sched2 = new AgentScheduler(['max_concurrent' => 4]);
$sched2->setGraph($graph);
$sched2->submit('x');
$sched2->submit('y');
$graph->dependsOn('y', 'x');
assert_eq('依赖未满足时只给 x', 'x', $sched2->next());
$sched2->start('x');
$sched2->finish('x', true);
assert_eq('x 完成后轮到 y', 'y', $sched2->next());

// ===== 六、ModelRouter =====

echo "\n=== 六、模型路由 ===\n";

$router = new ModelRouter(['cheap' => 'haiku', 'standard' => 'sonnet', 'premium' => 'opus']);
test('已配置', $router->isConfigured());

assert_eq('explorer 用便宜的', 'haiku', $router->route(['agent' => 'explorer']));
assert_eq('coder 用高级的', 'opus', $router->route(['agent' => 'coder']));
assert_eq('reviewer 用高级的', 'opus', $router->route(['agent' => 'reviewer']));
assert_eq('tester 用标准的', 'sonnet', $router->route(['agent' => 'tester']));

assert_eq('重构任务用高级的', 'opus', $router->route(['task' => '重构整个认证系统']));
assert_eq('读文件用便宜的', 'haiku', $router->route(['task' => '读一下 README']));

assert_eq('预算见底降档', 'haiku', $router->route(['agent' => 'coder', 'budget_left' => 0.05]));
assert_eq('预算充足不降档', 'opus', $router->route(['agent' => 'coder', 'budget_left' => 0.8]));
assert_eq('critical 优先级上最好的', 'opus',
    $router->route(['agent' => 'explorer', 'priority' => AgentScheduler::PRIORITY_CRITICAL]));

test('复杂度估算：重构高', $router->estimateComplexity('重构整个系统') >= 0.7);
test('复杂度估算：读文件低', $router->estimateComplexity('读一下文件') <= 0.3);
test('复杂度估算：空任务居中', $router->estimateComplexity('') === 0.5);

$router->setAgentTier('explorer', ModelRouter::TIER_PREMIUM);
assert_eq('可覆盖角色档位', 'opus', $router->route(['agent' => 'explorer']));

$router->setResolver(function (array $context) { return 'custom-model'; });
assert_eq('自定义路由器优先', 'custom-model', $router->route(['agent' => 'coder']));
$router->setResolver(function () { return ''; });
assert_eq('自定义返回空时退回规则', 'opus', $router->route(['agent' => 'coder']));

$empty = new ModelRouter();
test('未配置时不路由', !$empty->isConfigured());
assert_eq('未配置返回空串（沿用当前模型）', '', $empty->route(['agent' => 'coder']));

// ===== 七、ArtifactManager =====

echo "\n=== 七、产物管理 ===\n";

$artifacts = new ArtifactManager($tmpDir . '/artifacts');
$ref = $artifacts->put('task_123', 'test-report.json', '{"failed":3}');
assert_eq('引用格式', 'artifact://task_123/test-report.json', $ref);
test('是产物引用', ArtifactManager::isRef($ref));
test('普通字符串不是引用', !ArtifactManager::isRef('/tmp/x.json'));
assert_eq('取回内容', '{"failed":3}', $artifacts->get($ref));
test('产物存在', $artifacts->has($ref));
assert_eq('取不存在的产物', null, $artifacts->get('artifact://nope/x.json'));

$meta = $artifacts->metaOf($ref);
assert_eq('元信息含任务 ID', 'task_123', $meta['task_id']);
assert_eq('元信息含大小', 12, $meta['size']);

$long = str_repeat('A', 2000);
$longRef = $artifacts->put('task_123', 'big.log', $long);
$preview = $artifacts->preview($longRef, 100);
test('预览被截断', strlen($preview) < 200);
test('预览带引用提示', strpos($preview, $longRef) !== false);
assert_eq('短内容预览不截断', '{"failed":3}', $artifacts->preview($ref, 500));

$refs = $artifacts->listFor('task_123');
assert_eq('任务下 2 个产物', 2, count($refs));
assert_eq('无产物的任务返回空', 0, count($artifacts->listFor('task_none')));

$note = $artifacts->summarize('task_456', 'out.txt', "FAILURES!\nTest A failed\nTest B failed\nmore...");
test('摘要含引用', strpos($note, 'artifact://task_456/out.txt') !== false);
test('摘要含开头几行', strpos($note, 'FAILURES!') !== false);

test('删除产物', $artifacts->delete($ref));
test('删除后不存在', !$artifacts->has($ref));

// 路径穿越防护
$evil = $artifacts->put('task_123', '../../../etc/passwd', 'x');
test('路径穿越被挡下', strpos($evil, '..') === false);

// 内存模式
$memArt = new ArtifactManager();
$memRef = $memArt->put('t', 'x.txt', 'hello');
assert_eq('内存模式可存取', 'hello', $memArt->get($memRef));
assert_eq('内存模式可列出', 1, count($memArt->listFor('t')));
test('内存模式可删除', $memArt->delete($memRef));

// ===== 八、AgentResult 契约 =====

echo "\n=== 八、结构化结果 ===\n";

$result = AgentResult::done("修复了登录问题\n\n细节若干", ['iterations' => 5]);
assert_eq('状态', 'completed', $result->getStatus());
assert_eq('摘要取首行', '修复了登录问题', $result->getSummary());
assert_eq('默认无改动文件', [], $result->getFilesChanged());
assert_eq('默认成本 0', 0.0, $result->getCost());

$result->withDetails([
    'files_changed' => ['src/Auth.php', 'tests/AuthTest.php'],
    'tests'         => ['passed' => true, 'failed' => 0],
    'verification'  => ['passed' => true],
    'artifacts'     => ['artifact://t1/report.json'],
    'subtasks'      => [['agent' => 'tester', 'status' => 'completed']],
    'cost'          => 0.042,
    'duration_ms'   => 12500.4,
    'summary'       => '自定义摘要',
]);

assert_eq('改动文件', 2, count($result->getFilesChanged()));
assert_eq('测试结果', true, $result->getTests()['passed']);
assert_eq('验证结果', true, $result->getVerification()['passed']);
assert_eq('产物引用', 1, count($result->getArtifacts()));
assert_eq('子任务', 1, count($result->getSubtasks()));
assert_eq('成本', 0.042, $result->getCost());
assert_eq('耗时', 12500.4, $result->getDuration());
assert_eq('自定义摘要优先', '自定义摘要', $result->getSummary());

$contract = $result->toContract();
foreach (['status', 'summary', 'files_changed', 'tests', 'verification',
          'artifacts', 'subtasks', 'usage', 'cost', 'iterations', 'duration_ms'] as $key) {
    test("契约含 {$key}", array_key_exists($key, $contract));
}
test('契约可 JSON 化', json_encode($contract) !== false);

// max_iter 属于 isError() 认定的错误原因，状态是 failed 而不是 stopped
$errored = AgentResult::stopped(\Ai\Agent\Loop\StopReason::MAX_ITER, '没做完');
assert_eq('达到迭代上限算失败', 'failed', $errored->getStatus());

$waiting = AgentResult::stopped(\Ai\Agent\Loop\StopReason::WAITING_USER, '等用户回话');
assert_eq('等待用户是 stopped 而非 failed', 'stopped', $waiting->getStatus());

// ===== 九、Agent::create 链式 API =====

echo "\n=== 九、开发者 API ===\n";

list($aiC) = makeAI53([
    ['choices' => [['message' => ['role' => 'assistant', 'content' => '任务完成'], 'finish_reason' => 'stop']]],
]);

$agent = Agent::create($aiC)
    ->workdir($tmpDir)
    ->codeAgent(['agents' => ['explorer', 'coder']]);

test('create 返回 Agent', $agent instanceof Agent);
assert_eq('workdir 生效', $tmpDir, $agent->getRuntime()->getWorkdir());
assert_eq('只装两个子 Agent', 2, count($agent->getRuntime()->getSubAgentManager()->all()));

$before = count($agent->getRuntime()->getToolRegistry()->all());
$agent->tools(['my_tool' => fakeTool53('my_tool', '自定义工具')]);
assert_eq('tools() 是追加不是覆盖', $before + 1, count($agent->getRuntime()->getToolRegistry()->all()));

$agent->agents([
    'dba' => ['description' => '数据库结构与索引优化', 'tools' => ['read_file', 'bash']],
]);
$sam = $agent->getRuntime()->getSubAgentManager();
test('注册了自定义子 Agent', $sam->get('dba') !== null);
assert_eq('按名字取到工具', 2, count($sam->resolveTools($sam->get('dba'))));

$sched = $agent->scheduler(['max_concurrent' => 3]);
test('scheduler 惰性创建', $sched instanceof AgentScheduler);
test('scheduler 复用', $agent->scheduler() === $sched);
assert_eq('上限已设置', 3, $sched->limits()['max_concurrent']);

$art = $agent->artifacts($tmpDir . '/agent_artifacts');
test('artifacts 惰性创建', $art instanceof ArtifactManager);
$artRef = $art->put('task_1', 'log.txt', 'hello');
assert_eq('通过 Agent 存产物', 'hello', $art->get($artRef));

$mr = $agent->modelRouter(['cheap' => 'haiku', 'premium' => 'opus']);
test('modelRouter 惰性创建', $mr instanceof ModelRouter);
assert_eq('路由生效', 'haiku', $mr->route(['agent' => 'explorer']));

$tg = $agent->toolGroups();
test('toolGroups 惰性创建', $tg instanceof ToolGroup);
test('toolGroups 复用', $agent->toolGroups() === $tg);

$pp = $agent->permissionPolicy();
$pp->layer('global')->deny('Bash(rm -rf *)');
$agent->applyPermissionPolicy();
test('权限策略已应用', count($agent->getRuntime()->getPermission()->getRules()) > 0);

$disc = $agent->useToolDiscovery(['read_file', 'grep']);
test('工具发现已启用', $disc instanceof ToolDiscovery);

// 收窄的是「给模型看什么」，不是「能执行什么」。
// 这里原先断言注册表被裁到 <= 3 个——那恰好是在断言目录被毁掉：
// 搜索和激活都查注册表，裁完就只能在已经给了模型的那几个里搜，
// 渐进披露整个失效。现在注册表保持全量，由 toolDefs() 每轮按激活集过滤
test('注册表保持全量（激活了才执行得了）',
    count($agent->getRuntime()->getToolRegistry()->all()) > 3);
$discCtx = new \Ai\Agent\AgentContext($aiC, $agent->getRuntime()->getToolRegistry(), null);
$discCtx->setToolDiscovery($disc);
test('给模型的工具定义收窄到初始集', count($discCtx->toolDefs()) <= 3);

// 激活之后模型下一轮就该看得见——这是渐进披露的全部意义
$disc->activate('my_tool');
test('激活后模型立刻看得见（每轮重算工具定义）',
    in_array('my_tool', array_column($discCtx->toolDefs(), 'name'), true));

// resume：后台任务查句柄
list($aiR) = makeAI53();
$agentR = Agent::create($aiR)->workdir($tmpDir);
$handle = $agentR->dispatch('后台任务');
$resumed = $agentR->resume($handle['task_id']);
test('resume 拿到后台句柄', is_array($resumed) && isset($resumed['task_id']));
assert_eq('resume 未知任务返回 null', null, $agentR->resume('nope'));

// ===== 清理 =====

exec('rm -rf ' . escapeshellarg($tmpDir));

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);
