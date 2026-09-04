<?php
/**
 * Agent Tool 执行链 + CLI 测试（Phase 5-6 与端到端）
 *
 * 覆盖 dev.md 第 6-8 节：参数校验与收敛、风险确认、Controller 网关、
 * 「Discovery 过滤不是安全边界」这条红线、CLI 六条命令、以及
 * 「标注 → 索引 → 搜索 → 取 Schema → 调用 → 业务代码真的被执行」的完整闭环。
 *
 * 全程使用临时目录与临时 sqlite，结束递归删除，绝不污染仓库。
 *
 * 运行：php tests/agent_tool_execution_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Discovery\RegistryToolBridge;
use Ai\Agent\Indexer\ToolIndexer;
use Ai\Agent\Registry\ArgumentValidator;
use Ai\Agent\Registry\CallableControllerGateway;
use Ai\Agent\Registry\ControllerToolExecutor;
use Ai\Agent\Registry\MemoryToolRegistry;
use Ai\Agent\Registry\RiskPolicy;
use Ai\Agent\Registry\SqliteToolRegistry;
use Ai\Agent\Registry\ToolSearchContext;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolDefinition;
use Ai\Agent\Tool\ToolParameter;
use Ai\Cli\ToolRegistryCommand;

$passed = 0;
$failed = 0;

function test($name, $ok)
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "✓ {$name}\n"; }
    else { $failed++; echo "✗ {$name}\n"; }
}
function assert_eq($name, $expected, $actual)
{
    if ($expected !== $actual) {
        echo "  期望: " . var_export($expected, true) . "\n  实际: " . var_export($actual, true) . "\n";
    }
    test($name, $expected === $actual);
}
function rrmdir($dir)
{
    if (!is_dir($dir)) { return; }
    $items = scandir($dir);
    if ($items === false) { return; }
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') { continue; }
        $p = $dir . '/' . $it;
        if (is_dir($p) && !is_link($p)) { rrmdir($p); } else { @unlink($p); }
    }
    @rmdir($dir);
}

$fixtures = __DIR__ . '/fixtures/agent_app';
$tmpBase  = sys_get_temp_dir() . '/php-ai-exec-test_' . getmypid();
rrmdir($tmpBase);
@mkdir($tmpBase, 0700, true);

/** 造一个带各类参数的 Tool，供校验用例复用
 * @return ToolDefinition
 */
function make_tool()
{
    return new ToolDefinition([
        'name' => 'demo.tool', 'description' => '演示', 'controller_path' => 'demo/tool',
        'parameters' => [
            new ToolParameter(['name' => 'id', 'types' => ['integer'], 'required' => true]),
            new ToolParameter(['name' => 'title', 'types' => ['string', 'null']]),
            new ToolParameter(['name' => 'ratio', 'types' => ['number']]),
            new ToolParameter(['name' => 'flag', 'types' => ['boolean']]),
            new ToolParameter(['name' => 'tags', 'types' => ['array']]),
            new ToolParameter(['name' => 'status', 'types' => ['string'], 'enum' => ['draft', 'published']]),
            new ToolParameter(['name' => 'page', 'types' => ['integer'], 'default' => 1]),
        ],
    ]);
}

// ===================================================================
echo "\n=== 1. ArgumentValidator ===\n";
// ===================================================================

$tool = make_tool();

$r = ArgumentValidator::validate($tool, ['id' => 5]);
test('必填齐全时通过', $r['ok']);
assert_eq('只传必填时只带回必填', ['id' => 5], $r['arguments']);

$r = ArgumentValidator::validate($tool, []);
test('缺必填时失败', !$r['ok']);
test('错误信息点名缺失的参数', strpos(implode('', $r['errors']), 'id') !== false);

$r = ArgumentValidator::validate($tool, ['id' => '123']);
test('数字字符串收敛成 int', $r['ok'] && $r['arguments']['id'] === 123);

$r = ArgumentValidator::validate($tool, ['id' => 'abc']);
test('非数字字符串不被强转（拒绝而不是变成 0）', !$r['ok']);

$r = ArgumentValidator::validate($tool, ['id' => 1, 'ratio' => '3.5']);
test('数字字符串收敛成 float', $r['ok'] && $r['arguments']['ratio'] === 3.5);

$r = ArgumentValidator::validate($tool, ['id' => 1, 'flag' => 'true']);
test('"true" 收敛成 bool', $r['ok'] && $r['arguments']['flag'] === true);
$r = ArgumentValidator::validate($tool, ['id' => 1, 'flag' => 'maybe']);
test('无法判断的布尔值被拒', !$r['ok']);

$r = ArgumentValidator::validate($tool, ['id' => 1, 'title' => null]);
test('可空参数接受 null', $r['ok'] && $r['arguments']['title'] === null);
$r = ArgumentValidator::validate($tool, ['id' => null]);
test('不可空参数拒绝 null', !$r['ok']);

$r = ArgumentValidator::validate($tool, ['id' => 1, 'tags' => '["a","b"]']);
test('JSON 字符串形式的数组被还原', $r['ok'] && $r['arguments']['tags'] === ['a', 'b']);

$r = ArgumentValidator::validate($tool, ['id' => 1, 'status' => 'draft']);
test('枚举合法值通过', $r['ok']);
$r = ArgumentValidator::validate($tool, ['id' => 1, 'status' => 'deleted']);
test('枚举非法值被拒', !$r['ok']);
test('枚举错误信息列出可选值', strpos(implode('', $r['errors']), 'published') !== false);

$r = ArgumentValidator::validate($tool, ['id' => 1, 'evil' => 'rm -rf /', 'is_admin' => true]);
test('未声明的参数被丢弃而不是透传', $r['ok'] && !isset($r['arguments']['evil']) && !isset($r['arguments']['is_admin']));
assert_eq('被丢弃的参数名被记录', ['evil', 'is_admin'], $r['dropped']);

$r = ArgumentValidator::validate($tool, ['id' => 1]);
test('有默认值的参数不传时不出现在结果里（让 PHP 用默认值）', !array_key_exists('page', $r['arguments']));

// ===================================================================
echo "\n=== 2. RiskPolicy ===\n";
// ===================================================================

/** @param string $risk @param bool|null $confirm @return ToolDefinition */
function risk_tool($risk, $confirm = null)
{
    $data = ['name' => 'r.' . $risk, 'description' => 'x', 'controller_path' => 'r/x', 'risk' => $risk];
    if ($confirm !== null) {
        $data['requires_confirmation'] = $confirm;
    }
    return new ToolDefinition($data);
}

$policy = new RiskPolicy();
test('low 不需要确认', !$policy->needsConfirmation(risk_tool('low')));
test('medium 不需要确认', !$policy->needsConfirmation(risk_tool('medium')));
test('high 需要确认', $policy->needsConfirmation(risk_tool('high')));
test('critical 需要确认', $policy->needsConfirmation(risk_tool('critical')));
test('critical 是强制确认', $policy->isForced(risk_tool('critical')));
test('high 不是强制确认', !$policy->isForced(risk_tool('high')));

test('@agent-confirm true 能把低风险抬成需确认', $policy->needsConfirmation(risk_tool('low', true)));
test('@agent-confirm false 能让高风险免确认', !$policy->needsConfirmation(risk_tool('high', false)));

$strict = new RiskPolicy();
$strict->setThreshold(ToolDefinition::RISK_MEDIUM);
test('阈值降到 medium 后 medium 需要确认', $strict->needsConfirmation(risk_tool('medium')));
test('阈值降到 medium 后 low 仍不需要', !$strict->needsConfirmation(risk_tool('low')));

$loose = new RiskPolicy();
$loose->setThreshold(ToolDefinition::RISK_CRITICAL);
test('阈值抬到 critical 后 high 免确认', !$loose->needsConfirmation(risk_tool('high')));
test('阈值抬到 critical 后 critical 仍需确认（强制闸不被阈值绕过）', $loose->needsConfirmation(risk_tool('critical')));

$override = new RiskPolicy();
$override->setOverride('r.high', false);
test('override 能让指定 Tool 免确认', !$override->needsConfirmation(risk_tool('high')));
$override->setOverride('r.critical', false);
test('override 不能让 critical 免确认', $override->needsConfirmation(risk_tool('critical')));
$override->allowCriticalWithoutConfirm(true);
test('显式打开开关后 critical 才能免确认', !$override->needsConfirmation(risk_tool('critical')));

// ===================================================================
echo "\n=== 3. ControllerToolExecutor ===\n";
// ===================================================================

$execReg = new MemoryToolRegistry();
$execReg->register(make_tool());
$execReg->register(new ToolDefinition([
    'name' => 'demo.disabled', 'description' => '已禁用', 'controller_path' => 'demo/off', 'enabled' => false,
]));
$execReg->register(new ToolDefinition([
    'name' => 'demo.noctrl', 'description' => '没有 Controller 入口',
    'class_name' => 'Evil', 'method_name' => 'run',
]));
$execReg->register(new ToolDefinition([
    'name' => 'demo.danger', 'description' => '危险操作', 'controller_path' => 'demo/danger', 'risk' => 'critical',
]));

$dispatched = [];
$gateway = new CallableControllerGateway(function ($path, array $args, array $ctx) use (&$dispatched) {
    $dispatched[] = ['path' => $path, 'args' => $args, 'ctx' => $ctx];
    if ($path === 'demo/deny') {
        throw new \RuntimeException('无权访问 ' . $path);
    }
    return ['ok' => true, 'path' => $path, 'args' => $args];
});

$audit    = [];
$executor = new ControllerToolExecutor($execReg, $gateway);
$executor->onExecuted(function (array $rec) use (&$audit) { $audit[] = $rec; });

$res = $executor->execute('demo.tool', ['id' => '7', 'title' => 'hi', 'extra' => 'x']);
test('正常执行成功', $res->isSuccess());
assert_eq('网关收到的 Controller 路径', 'demo/tool', $dispatched[0]['path']);
assert_eq('网关收到的是收敛后的参数', ['id' => 7, 'title' => 'hi'], $dispatched[0]['args']);
test('未声明参数没有透传到网关', !isset($dispatched[0]['args']['extra']));
$meta = $res->getMetadata();
assert_eq('结果 metadata 带 controller', 'demo/tool', $meta['controller']);
assert_eq('结果 metadata 记录被丢弃的参数', ['extra'], $meta['dropped_arguments']);
test('结果 metadata 有耗时', isset($meta['duration_ms']));

$res = $executor->execute('nope.nope');
test('未知 Tool 失败', !$res->isSuccess());
assert_eq('未知 Tool 的 reason', 'not_found', $res->getMetadata()['reason']);

$res = $executor->execute('demo.disabled');
test('禁用的 Tool 拒绝执行', !$res->isSuccess());
assert_eq('禁用的 reason', 'disabled', $res->getMetadata()['reason']);

$before = count($dispatched);
$res = $executor->execute('demo.noctrl', ['x' => 1]);
test('没有 @agent-controller 的 Tool 拒绝执行', !$res->isSuccess());
assert_eq('拒绝原因是 no_controller', 'no_controller', $res->getMetadata()['reason']);
assert_eq('拒绝时网关根本没被调用（不会退回反射直调）', $before, count($dispatched));

$res = $executor->execute('demo.tool', []);
test('参数不合法时失败', !$res->isSuccess());
assert_eq('参数错误的 reason', 'invalid_arguments', $res->getMetadata()['reason']);
assert_eq('参数不合法时网关没被调用', $before, count($dispatched));

$res = $executor->execute('demo.danger');
test('critical 未确认被拦', !$res->isSuccess());
$meta = $res->getMetadata();
assert_eq('确认拦截的 reason', 'requires_confirmation', $meta['reason']);
test('metadata 告知需要确认', !empty($meta['requires_confirmation']));
test('metadata 告知是强制确认', !empty($meta['forced']));
assert_eq('确认拦截时网关没被调用', $before, count($dispatched));

$res = $executor->execute('demo.danger', [], ['confirmed' => true]);
test('确认后放行', $res->isSuccess());

// 网关抛异常 = 权限拒绝
$execReg->register(new ToolDefinition([
    'name' => 'demo.deny', 'description' => '会被网关拒绝', 'controller_path' => 'demo/deny',
]));
$res = $executor->execute('demo.deny');
test('网关拒绝时 Tool 执行失败', !$res->isSuccess());
assert_eq('网关拒绝的 reason', 'exception', $res->getMetadata()['reason']);
test('错误信息透出网关给的原因', strpos($res->getError(), '无权访问') !== false);

// 网关抛 Error（不只是 Exception）
$errGateway = new CallableControllerGateway(function ($path, array $args, array $ctx) {
    throw new \TypeError('参数类型不对');
});
$errExec = new ControllerToolExecutor($execReg, $errGateway);
$res = $errExec->execute('demo.deny');
test('网关抛 Error 也被兜住而不是打断整个 Agent', !$res->isSuccess());
assert_eq('Error 的 reason', 'error', $res->getMetadata()['reason']);

// 审计钩子
test('审计钩子在成功时触发', count($audit) > 0);
$sawSuccess = false;
$sawFail    = false;
$sawConfirm = false;
foreach ($audit as $rec) {
    if ($rec['success'] === true) { $sawSuccess = true; }
    if ($rec['success'] === false && isset($rec['metadata']['reason']) && $rec['metadata']['reason'] === 'not_found') { $sawFail = true; }
    if (isset($rec['metadata']['reason']) && $rec['metadata']['reason'] === 'requires_confirmation') { $sawConfirm = true; }
}
test('审计记录了成功的执行', $sawSuccess);
test('审计记录了失败的执行', $sawFail);
test('审计记录了被确认拦截的执行', $sawConfirm);

// strict_arguments
$strictExec = new ControllerToolExecutor($execReg, $gateway, ['strict_arguments' => true]);
$res = $strictExec->execute('demo.tool', ['id' => 1, 'evil' => 1]);
test('strict_arguments 下多余参数直接报错', !$res->isSuccess());
assert_eq('strict 的 reason', 'unknown_arguments', $res->getMetadata()['reason']);

// ===================================================================
echo "\n=== 4. 安全红线：Discovery 过滤不是安全边界 ===\n";
// ===================================================================

// can() 一律放行、dispatch() 一律拒绝：模拟「候选过滤过时/不完整」的现实情况。
// 结论必须是「拒绝」——最终授权只认 dispatch() 里应用自己的校验。
$optimisticGateway = new CallableControllerGateway(
    function ($path, array $args, array $ctx) {
        throw new \RuntimeException('Policy 拒绝：只能修改自己创建的文章');
    },
    function ($path, array $ctx) {
        return true;   // Discovery 阶段乐观放行
    }
);

$lineReg = new MemoryToolRegistry();
$lineReg->register(new ToolDefinition([
    'name' => 'article.update', 'description' => '修改文章', 'controller_path' => 'article/update',
]));

$bridge = new RegistryToolBridge($lineReg, $optimisticGateway);
$bridge->setContext(new ToolSearchContext(['user_id' => 7, 'permissions' => ['article/update']]));

$found = $bridge->searcher()->summaries('修改文章');
test('Discovery 阶段能搜到（can 放行）', count($found) === 1);

$res = $bridge->invoke(['name' => 'article.update', 'arguments' => []]);
test('执行时仍被拒绝——搜得到 ≠ 有权限', !$res->isSuccess());
test('拒绝原因来自应用自己的业务规则', strpos($res->getError(), 'Policy 拒绝') !== false);

// 反过来：Registry 里有、上下文看不到的 Tool，不能靠直接点名绕过去
$hiddenBridge = new RegistryToolBridge($lineReg, new CallableControllerGateway(
    function ($path, array $args, array $ctx) { return 'executed'; }
));
$hiddenBridge->setContext(new ToolSearchContext(['permissions' => ['order/*']]));
$res = $hiddenBridge->invoke(['name' => 'article.update', 'arguments' => []]);
test('上下文看不到的 Tool 不能被直接点名调用', !$res->isSuccess());
assert_eq('拒绝原因是不可见', 'not_visible', $res->getMetadata()['reason']);

// ===================================================================
echo "\n=== 5. 端到端：标注 → 索引 → 搜索 → Schema → 调用 → 业务代码 ===\n";
// ===================================================================

require_once $fixtures . '/ArticleService.php';
require_once $fixtures . '/OrderService.php';

$e2eDb  = $tmpBase . '/e2e.sqlite';
$e2eReg = new SqliteToolRegistry($e2eDb);
$e2eRes = (new ToolIndexer($e2eReg))->scan([$fixtures]);
assert_eq('端到端：索引出 8 个 Tool', 8, $e2eReg->count(true));

$article = new AgentAppFixture\ArticleService();
$order   = new AgentAppFixture\OrderService();
$routes  = [
    'article/list'   => [$article, 'listArticles'],
    'article/read'   => [$article, 'read'],
    'article/create' => [$article, 'create'],
    'article/update' => [$article, 'update'],
    'article/delete' => [$article, 'delete'],
    'order/refund'   => [$order, 'refund'],
    'order/purge'    => [$order, 'purge'],
];
$executed = [];

// 这个网关模拟应用现有的 Controller 入口：先权限校验，再分发
$appGateway = new CallableControllerGateway(
    function ($path, array $args, array $ctx) use ($routes, &$executed) {
        $perms = isset($ctx['permissions']) ? $ctx['permissions'] : null;
        if (is_array($perms) && !in_array($path, $perms, true)) {
            throw new \RuntimeException('无权访问 ' . $path);
        }
        if (!isset($routes[$path])) {
            throw new \RuntimeException('未注册的 Controller 入口: ' . $path);
        }
        $executed[] = $path;
        return call_user_func_array($routes[$path], array_values($args));
    },
    function ($path, array $ctx) {
        $perms = isset($ctx['permissions']) ? $ctx['permissions'] : null;
        return !is_array($perms) || in_array($path, $perms, true);
    }
);

$e2eBridge = new RegistryToolBridge($e2eReg, $appGateway);
$e2eBridge->setContext(new ToolSearchContext([
    'user_id'     => 7,
    'permissions' => ['article/list', 'article/read', 'article/update'],
]));
$tools = $e2eBridge->tools();
$tctx  = new ToolContext([]);

// ① 模型搜工具
$sr = $tools['search_app_tools']->execute(['query' => '修改文章标题'], $tctx);
test('① 搜索成功', $sr->isSuccess());
$cands = json_decode((string) $sr->getContent(), true);
test('① 候选里有 article.update', is_array($cands) && $cands[0]['name'] === 'article.update');
$candNames = [];
foreach ($cands as $c) { $candNames[] = $c['name']; }
test('① 无权限的 article.delete 不在候选里', !in_array('article.delete', $candNames, true));

// ② 模型取 Schema
$gr = $tools['get_app_tool']->execute(['name' => 'article.update'], $tctx);
test('② 取到 Schema', $gr->isSuccess());
$schema = json_decode((string) $gr->getContent(), true);
assert_eq('② Schema 的必填参数', ['id'], $schema['parameters']['required']);

// ③ 模型调用
$cr = $tools['call_app_tool']->execute([
    'name'      => 'article.update',
    'arguments' => ['id' => '1', 'title' => '热门香港SEO公司推荐'],
], $tctx);
test('③ 调用成功', $cr->isSuccess());
$out = json_decode((string) $cr->getContent(), true);
assert_eq('③ 业务代码真的被执行（返回了改后的标题）', '热门香港SEO公司推荐', $out['title']);
assert_eq('③ 走的是 Controller 入口', ['article/update'], $executed);

// ④ 无权限的能力：搜不到，也调不动
$cr = $tools['call_app_tool']->execute(['name' => 'article.delete', 'arguments' => ['id' => 1]], $tctx);
test('④ 无权限的能力调用失败', !$cr->isSuccess());
assert_eq('④ 业务代码没有被执行', ['article/update'], $executed);

// ⑤ 高风险能力：即使有权限也要先确认
$e2eBridge->setContext(new ToolSearchContext(['user_id' => 7]));   // 不过滤，全部可见
$cr = $e2eBridge->invoke(['name' => 'order.refund', 'arguments' => ['orderId' => 9, 'amount' => 12.5]]);
test('⑤ 高风险未确认被拦', !$cr->isSuccess());
test('⑤ 拦截时业务代码没被执行', !in_array('order/refund', $executed, true));
$cr = $e2eBridge->invoke([
    'name' => 'order.refund', 'arguments' => ['orderId' => 9, 'amount' => 12.5], 'confirmed' => true,
]);
test('⑤ 确认后执行成功', $cr->isSuccess());
test('⑤ 确认后业务代码被执行', in_array('order/refund', $executed, true));
$refund = json_decode((string) $cr->getContent(), true);
assert_eq('⑤ 退款金额被正确收敛成 float 传给业务代码', 12.5, $refund['amount']);

// ⑥ 没有 Controller 入口的能力：任何情况下都不执行
$cr = $e2eBridge->invoke(['name' => 'order.orphan', 'arguments' => []]);
test('⑥ 没有 Controller 入口的能力被拒绝', !$cr->isSuccess());
assert_eq('⑥ 拒绝原因', 'no_controller', $cr->getMetadata()['reason']);

// ===================================================================
echo "\n=== 6. CLI ===\n";
// ===================================================================

$cliDb = $tmpBase . '/cli.sqlite';

/** 跑一次 CLI 并捕获输出
 * @param string[] $argv
 * @return array{code: int, out: string, err: string}
 */
function run_cli(array $argv)
{
    $out = '';
    $err = '';
    $cmd = new ToolRegistryCommand();
    $cmd->setOutput(function ($text, $isError) use (&$out, &$err) {
        if ($isError) { $err .= $text; } else { $out .= $text; }
    });
    $code = $cmd->run($argv);
    return ['code' => $code, 'out' => $out, 'err' => $err];
}

$r = run_cli(['index', '--path=' . $fixtures, '--db=' . $cliDb]);
assert_eq('index 退出码 0', 0, $r['code']);
test('index 输出扫描摘要', strpos($r['out'], '扫描') !== false);
test('index 把缺 controller 的 Tool 报成警告', strpos($r['err'], '缺少 @agent-controller') !== false);
test('index 创建了 Registry 文件', is_file($cliDb));

$r = run_cli(['tools', '--db=' . $cliDb]);
assert_eq('tools 退出码 0', 0, $r['code']);
test('tools 列出 article.update', strpos($r['out'], 'article.update') !== false);
test('tools 显示风险等级', strpos($r['out'], 'critical') !== false);

$r = run_cli(['tools', '--db=' . $cliDb, '--json']);
$rows = json_decode($r['out'], true);
test('tools --json 输出可解析', is_array($rows) && count($rows) === 8);
test('tools --json 每行是摘要', isset($rows[0]['name'], $rows[0]['risk']));

$r = run_cli(['tools:search', '退款', '--db=' . $cliDb]);
assert_eq('tools:search 退出码 0', 0, $r['code']);
test('tools:search 命中 order.refund', strpos($r['out'], 'order.refund') !== false);

$r = run_cli(['tools:search', '文章 修改', '--db=' . $cliDb, '--json', '--limit=3']);
$rows = json_decode($r['out'], true);
test('tools:search --json 可解析', is_array($rows));
test('tools:search --limit 生效', count($rows) <= 3);

$r = run_cli(['tools:show', 'article.update', '--db=' . $cliDb]);
assert_eq('tools:show 退出码 0', 0, $r['code']);
test('tools:show 打印 Controller 入口', strpos($r['out'], 'article/update') !== false);
test('tools:show 打印参数 Schema', strpos($r['out'], '"properties"') !== false);

$r = run_cli(['tools:show', 'not.exists', '--db=' . $cliDb]);
assert_eq('tools:show 不存在时退出码 1', 1, $r['code']);

$r = run_cli(['index', '--path=' . $fixtures, '--db=' . $cliDb, '--check']);
assert_eq('index --check 最新时退出码 0', 0, $r['code']);
test('index --check 说明是最新的', strpos($r['out'], '最新') !== false);

// 造一个变化：删掉一条文件 hash 记录，让 check 认为有文件需要重扫
$chkReg = new SqliteToolRegistry($cliDb);
$chkReg->pdo()->exec('DELETE FROM agent_index_files');
$r = run_cli(['index', '--path=' . $fixtures, '--db=' . $cliDb, '--check']);
assert_eq('index --check 过期时退出码 1', 1, $r['code']);
test('index --check 过期时说明原因', strpos($r['out'], '过期') !== false);

$r = run_cli(['index', '--path=' . $fixtures, '--db=' . $cliDb, '--check', '--json']);
$payload = json_decode($r['out'], true);
test('index --check --json 带 stale 标志', is_array($payload) && $payload['stale'] === true);

$r = run_cli(['tools:remove', 'order.orphan', '--db=' . $cliDb]);
assert_eq('tools:remove 退出码 0', 0, $r['code']);
assert_eq('tools:remove 后真的没了', null, (new SqliteToolRegistry($cliDb))->get('order.orphan'));
$r = run_cli(['tools:remove', 'order.orphan', '--db=' . $cliDb]);
assert_eq('tools:remove 不存在时退出码 1', 1, $r['code']);

$clearDb = $tmpBase . '/clear.sqlite';
run_cli(['index', '--path=' . $fixtures, '--db=' . $clearDb]);
$r = run_cli(['index', '--path=' . $fixtures, '--db=' . $clearDb, '--clear']);
assert_eq('--clear 后仍是全量 8 个', 8, (new SqliteToolRegistry($clearDb))->count(true));

$r = run_cli(['index', '--db=' . $tmpBase . '/none.sqlite']);
assert_eq('没有可扫描路径时退出码 2', 2, $r['code']);
test('提示怎么配置路径', strpos($r['err'], '.ai/config.php') !== false);

$r = run_cli(['no-such-command']);
assert_eq('未知命令退出码 2', 2, $r['code']);
$r = run_cli([]);
assert_eq('无参数时打印帮助并返回 2', 2, $r['code']);
$r = run_cli(['help']);
assert_eq('help 退出码 0', 0, $r['code']);
test('help 列出全部命令', strpos($r['out'], 'tools:search') !== false);

// 配置文件驱动
$cfgDir = $tmpBase . '/cfg';
@mkdir($cfgDir, 0700, true);
file_put_contents(
    $cfgDir . '/config.php',
    "<?php\nreturn ['agent' => ['index' => ['paths' => [" . var_export($fixtures, true) . "]]]];\n"
);
$r = run_cli(['index', '--config=' . $cfgDir . '/config.php', '--db=' . $tmpBase . '/cfg.sqlite']);
assert_eq('配置文件驱动扫描退出码 0', 0, $r['code']);
assert_eq('配置文件驱动扫描出 8 个', 8, (new SqliteToolRegistry($tmpBase . '/cfg.sqlite'))->count(true));

$r = run_cli(['index', '--config=' . $cfgDir . '/missing.php', '--db=' . $tmpBase . '/x.sqlite']);
assert_eq('配置文件不存在时退出码 2', 2, $r['code']);

$r = run_cli(['index', '--path=' . $fixtures, '--bootstrap=' . $cfgDir . '/nope.php']);
assert_eq('bootstrap 文件不存在时退出码 2', 2, $r['code']);

// 真正跑一次 bin/php-ai（验证脚本本身可执行、autoload 定位正确）
$binDb = $tmpBase . '/bin.sqlite';
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/php-ai')
    . ' index --path=' . escapeshellarg($fixtures) . ' --db=' . escapeshellarg($binDb) . ' 2>/dev/null';
$binOut = shell_exec($cmd);
test('bin/php-ai 可直接运行', is_string($binOut) && strpos($binOut, '扫描') !== false);
assert_eq('bin/php-ai 真的写了库', 8, (new SqliteToolRegistry($binDb))->count(true));

// ===================================================================
echo "\n=== 7. PHP 8 Attribute（低版本自动跳过）===\n";
// ===================================================================

$php8Fixtures = __DIR__ . '/fixtures/agent_app_php8';
if (PHP_VERSION_ID >= 80000) {
    $attrReg = new MemoryToolRegistry();
    $attrRes = (new ToolIndexer($attrReg))->scan([$php8Fixtures]);
    assert_eq('Attribute 索引出 2 个 Tool', 2, $attrRes->toolsAdded);
    test('#[AgentTool] 被识别', $attrReg->get('user.read') !== null);
    assert_eq('Attribute 的 controller 被采用', 'user/read', $attrReg->get('user.read')->getControllerPath());
    assert_eq('Attribute 的 keywords 被采用', ['用户', '资料'], $attrReg->get('user.read')->getKeywords());

    test('Attribute 的 name 覆盖 PHPDoc 的 @agent-tool', $attrReg->get('user.update') !== null);
    assert_eq('PHPDoc 的 tool 名没有被单独注册', null, $attrReg->get('user.update.docname'));
    $u = $attrReg->get('user.update');
    assert_eq('Attribute 的 description 覆盖 PHPDoc', '修改用户资料', $u->getDescription());
    assert_eq('Attribute 的 controller 覆盖 PHPDoc', 'user/update', $u->getControllerPath());
    assert_eq('Attribute 的 risk 覆盖 PHPDoc', 'medium', $u->getRisk());
    assert_eq('PHPDoc 的 @param 描述仍被采用', '用户 ID', $u->getParameter('id')->getDescription());
    test('没有标注的方法不入库', $attrReg->get('user.helper') === null);
} else {
    echo "  （当前 PHP " . PHP_VERSION . " < 8.0，Attribute 用例跳过）\n";
    $attrReg = new MemoryToolRegistry();
    (new ToolIndexer($attrReg))->scan([$php8Fixtures]);
    assert_eq('PHP 7 下 Attribute fixture 不产出 Tool（也不报错）', 0, $attrReg->count(true));
}

// ===================================================================
echo "\n=== 清理 ===\n";
rrmdir($tmpBase);
test('临时目录已清理', !is_dir($tmpBase));

echo "\n========================================\n";
echo "通过: {$passed}  失败: {$failed}\n";
echo "========================================\n";
exit($failed > 0 ? 1 : 0);
