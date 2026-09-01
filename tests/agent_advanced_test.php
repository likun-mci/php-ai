<?php
/**
 * Agent Runtime 高级功能测试（Phase 2-7）
 *
 * 覆盖：
 *   1. 内置文件工具（Read/Write/Edit/Glob/Grep/Bash）
 *   2. 权限系统（manual/plan/bypass 模式 + 规则匹配）
 *   3. Context compaction
 *   4. Session 持久化
 *   5. SubAgent 注册与 spawn_agent 工具
 *   6. Budget 预算控制
 *
 * 不联网、不需要 Key。运行：php tests/agent_advanced_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\AgentRuntime;
use Ai\Agent\Budget\BudgetManager;
use Ai\Agent\Context\ContextManager;
use Ai\Agent\Permission\PermissionManager;
use Ai\Agent\Permission\PermissionResult;
use Ai\Agent\Permission\PermissionRule;
use Ai\Agent\Session\AgentSession;
use Ai\Agent\Session\FileSessionStore;
use Ai\Agent\Session\SessionManager;
use Ai\Agent\SubAgent\SubAgentManager;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;
use Ai\Agent\Tools\ClaudeCodeTools;
use Ai\Agent\Tools\PathSafety;
use Ai\Agent\Tools\ReadFileTool;
use Ai\Agent\Tools\WriteFileTool;
use Ai\Agent\Tools\EditFileTool;
use Ai\Agent\Tools\GlobTool;
use Ai\Agent\Tools\GrepTool;
use Ai\Agent\Tools\BashTool;

/** 按顺序回放预置响应的假传输层 */
class ScriptedTransport3 implements \Ai\Contracts\TransportInterface
{
    public $responses = [];
    public $requests  = [];
    public $loopLast  = false;

    public function post(string $url, array $data, array $headers = []): array
    {
        $this->requests[] = $data;
        if (!$this->responses) {
            return [];
        }
        $resp = array_shift($this->responses);
        if ($this->loopLast && !$this->responses) {
            $this->responses[] = $resp;
        }
        return $resp;
    }
    public function get(string $url, array $params = [], array $headers = []): array { return []; }
    public function setTimeout(int $t): \Ai\Contracts\TransportInterface { return $this; }
    public function setProxy(string $p): \Ai\Contracts\TransportInterface { return $this; }
    public function setStreamCallback(?callable $cb): \Ai\Contracts\TransportInterface { return $this; }
}

$failures = [];
function check(bool $ok, string $name, string $detail = ''): void
{
    global $failures;
    if (!$ok) { $failures[] = $name . ($detail !== '' ? "（{$detail}）" : ''); }
    echo ($ok ? "✓ " : "✗ ") . $name . ($ok ? '' : " — {$detail}") . "\n";
}

// 临时工作目录
$workdir = sys_get_temp_dir() . '/agent_adv_' . getmypid();
if (!is_dir($workdir)) { mkdir($workdir, 0755, true); }
file_put_contents($workdir . '/sample.txt', "line1\nline2\nline3\nline4\nline5\n");
file_put_contents($workdir . '/app.php', "<?php\nfunction login() { return 'ok'; }\n");

// ---------------------------------------------------------------
// 一、内置文件工具
// ---------------------------------------------------------------
echo "=== 一、内置文件工具 ===\n\n";

$pathSafety = new PathSafety($workdir);
$ctx = new ToolContext($workdir);

// ReadFileTool
$rf = new ReadFileTool($pathSafety);
$r = $rf->execute(['path' => 'sample.txt'], $ctx);
check($r->isSuccess() && strpos($r->getContent(), 'line1') !== false, 'read_file 读取文件');
check(strpos($r->getDisplay(), 'Read sample.txt') !== false, 'read_file display 正确', $r->getDisplay());

// 路径沙箱：拒绝越界
$r = $rf->execute(['path' => '/etc/passwd'], $ctx);
check(!$r->isSuccess(), 'read_file 拒绝越界路径');

// 不存在的文件
$r = $rf->execute(['path' => 'nope.txt'], $ctx);
check(!$r->isSuccess(), 'read_file 报告文件不存在');

// WriteFileTool
$wf = new WriteFileTool($pathSafety);
$r = $wf->execute(['path' => 'new.txt', 'content' => 'hello'], $ctx);
check($r->isSuccess() && file_exists($workdir . '/new.txt'), 'write_file 创建文件');

// EditFileTool
$ef = new EditFileTool($pathSafety);
$r = $ef->execute(['path' => 'new.txt', 'old_string' => 'hello', 'new_string' => 'hello world'], $ctx);
check($r->isSuccess() && file_get_contents($workdir . '/new.txt') === 'hello world', 'edit_file 局部替换');

// GlobTool
$glob = new GlobTool($pathSafety, 100);
$r = $glob->execute(['pattern' => '*.txt'], $ctx);
check($r->isSuccess() && strpos($r->getContent(), 'sample.txt') !== false, 'glob 搜索文件');

// GrepTool
$grep = new GrepTool($pathSafety, 50);
$r = $grep->execute(['pattern' => 'login', 'include' => '*.php', 'path' => '.'], $ctx);
check($r->isSuccess() && $r->getMetadata()['matches'] > 0, 'grep 搜索内容', $r->getDisplay());

// BashTool
$bash = new BashTool(10);
$bash->setWorkdir($workdir);
$r = $bash->execute(['command' => 'echo $((1+2))'], $ctx);
check($r->isSuccess() && trim($r->getContent()) === '3', 'bash 执行命令', trim($r->getContent()));

// ClaudeCodeTools 工厂
$tools = ClaudeCodeTools::all(['workdir' => $workdir]);
check(count($tools) === 6, 'ClaudeCodeTools::all() 返回 6 个工具');
$roTools = ClaudeCodeTools::readOnly(['workdir' => $workdir]);
check(count($roTools) === 3 && !isset($roTools['bash']), 'ClaudeCodeTools::readOnly() 不含 bash');

// ---------------------------------------------------------------
// 二、权限系统
// ---------------------------------------------------------------
echo "\n=== 二、权限系统 ===\n\n";

$pm = new PermissionManager('plan');
$rf2 = new ReadFileTool($pathSafety);
$bash2 = new BashTool(10);

// plan 模式：只读放行，危险拒绝
$r = $pm->check($rf2, ['path' => 'sample.txt'], $ctx);
check($r->isAllowed(), 'plan 模式：read_file 放行');
$r = $pm->check($bash2, ['command' => 'ls'], $ctx);
check($r->isDenied(), 'plan 模式：bash 拒绝');

// manual 模式：只读放行，bash 创建权限请求
$pm2 = new PermissionManager("manual");
$r = $pm2->check($rf2, ["path" => "x"], $ctx);
check($r->isAllowed(), "manual 模式：read_file 放行");
$r = $pm2->check($bash2, ["command" => "ls"], $ctx);
check($r->needsAsk(), "manual 模式：bash 需要询问", $r->getReason());
$request = $r->getRequest();
check($request !== null && $request->getToolName() === "bash", "manual 模式：已创建 PermissionRequest",
      $request ? $request->getToolName() : "null");
check($pm2->approve($request->getId()), "approve() 批准请求");
check($request->isApproved(), "请求状态为 approved");

// 规则匹配：allowTool / denyTool + 参数模式
$pm3 = new PermissionManager('manual');
$pm3->allowTool('read_file');
$pm3->denyTool('bash', ['command' => 'rm *']);
$r = $pm3->check($rf2, ['path' => 'x'], $ctx);
check($r->isAllowed(), 'allowTool 规则放行');
$r = $pm3->check($bash2, ['command' => 'rm -rf /'], $ctx);
check($r->isDenied(), 'denyTool 参数规则拒绝 rm', $r->getReason());

// PermissionResult 状态
check(PermissionResult::allow()->isAllowed(), 'PermissionResult::allow');
check(PermissionResult::deny('x')->isDenied(), 'PermissionResult::deny');
check(PermissionResult::ask('x')->needsAsk(), 'PermissionResult::ask');

// PermissionRule 通配匹配
check(PermissionRule::matchPattern('/var/www/*', '/var/www/app'), '通配符匹配');
check(!PermissionRule::matchPattern('/var/www/*', '/etc/passwd'), '通配符不匹配');

// ---------------------------------------------------------------
// 三、Context Manager
// ---------------------------------------------------------------
echo "\n=== 三、Context Manager ===\n\n";

$msgs = [];
for ($i = 0; $i < 30; $i++) {
    $msgs[] = ['role' => 'user', 'content' => '这是第 ' . $i . ' 条很长很长的消息，用来占满上下文空间。'];
    $msgs[] = ['role' => 'assistant', 'content' => '回复第 ' . $i . ' 条，同样很长。'];
}

$cm = new ContextManager($msgs, ['maxTokens' => 1000, 'keepRecent' => 4]);
check($cm->tokenCount() > 0, 'token 估算返回正值', (string) $cm->tokenCount());
check($cm->shouldCompact(), '超过阈值触发压缩');

$newMsgs = $cm->compact(function ($old, $hint) {
    return '已压缩 ' . count($old) . ' 条历史消息';
}, '测试任务');
check(count($newMsgs) < count($msgs), '压缩后消息变少');
check(count($newMsgs) === 5, '压缩后 = 1 条摘要 + 4 条最近', (string) count($newMsgs));
check($newMsgs[0]['role'] === 'system', '摘要作为 system 消息');

// token 估算（中文）
$tok = ContextManager::estimateTokens('你好世界');
check($tok > 0 && $tok <= 5, '中文 token 估算合理', (string) $tok);

// ---------------------------------------------------------------
// 四、Session 持久化
// ---------------------------------------------------------------
echo "\n=== 四、Session ===\n\n";

$storeDir = $workdir . '/sessions';
$store = new FileSessionStore($storeDir);
$session = new AgentSession('sess-1', [
    'messages' => [['role' => 'user', 'content' => 'hi']],
    'system'   => '助手',
]);
$store->save($session);
$loaded = $store->load('sess-1');
check($loaded !== null && $loaded->getId() === 'sess-1', '会话保存后可加载');
check($loaded->getMessages() === [['role' => 'user', 'content' => 'hi']], '消息正确恢复');
$store->delete('sess-1');
check($store->load('sess-1') === null, '会话删除');

// SessionManager 暂停/恢复
$sm = new SessionManager($store);
$sm->create('sess-2', ['messages' => [['role' => 'user', 'content' => 'x']]]);
$sm->pause('sess-2');
$paused = $store->load('sess-2');
check($paused !== null && $paused->isPaused(), '暂停后状态为 paused');
$resumed = $sm->resume('sess-2');
check($resumed !== null && $resumed->isRunning(), '恢复后状态为 running');
$sm->delete('sess-2');

// ---------------------------------------------------------------
// 五、SubAgent
// ---------------------------------------------------------------
echo "\n=== 五、SubAgent ===\n\n";

$ai = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai->setTransport(new ScriptedTransport3());

$sam = new SubAgentManager($ai);
$sam->register('code-reviewer', [
    'description' => '审查代码质量',
    'prompt'      => '你是代码审查专家，找出安全漏洞。',
    'tools'       => [new ReadFileTool($pathSafety)],
]);
check(count($sam->all()) === 1, '子 Agent 注册成功');

$schema = $sam->getToolSchema();
check($schema['name'] === 'spawn_agent', 'spawn_agent 工具定义存在');
check(isset($schema['input_schema']['properties']['agent']), 'spawn_agent 有 agent 参数');

$desc = $sam->toolDescription();
check(strpos($desc, 'code-reviewer') !== false, 'toolDescription 列出子 Agent');

$handler = $sam->getHandler();
check(is_callable($handler), 'spawn_agent handler 可调用');

// 未知子 Agent
$r = $handler(['agent' => 'nope', 'task' => 'x']);
check(is_string($r) && strpos($r, '不存在') !== false, '未知子 Agent 报错');

// ---------------------------------------------------------------
// 六、Budget
// ---------------------------------------------------------------
echo "\n=== 六、Budget ===\n\n";

$bm = new BudgetManager([
    'maxTokens' => 1000,
    'pricing'   => ['prompt' => 5.0, 'completion' => 25.0, 'cached' => 0.5],
    'perMillion' => true,
]);
check(!$bm->exceeded(), '初始未超预算');

$bm->record(['prompt_tokens' => 2000, 'completion_tokens' => 1000]);
check($bm->exceeded(), 'token 超限触发');
$summary = $bm->summary();
check($summary['exceeded'] && strpos($summary['reason'], 'token') !== false, '超限原因正确');

$bm2 = new BudgetManager([
    'maxBudget' => 1.0,
    'pricing'   => ['prompt' => 5.0, 'completion' => 25.0],
    'perMillion' => true,
]);
$bm2->record(['prompt_tokens' => 100000, 'completion_tokens' => 50000]);
$s2 = $bm2->summary();
check($s2['exceeded'], '预算超限触发');
check($s2['cost'] > 1.0, '成本估算正确', '$' . round($s2['cost'], 4));

// ---------------------------------------------------------------
// 七、Agent 完整集成（带权限 + 预算 + 会话）
// ---------------------------------------------------------------
echo "\n=== 七、Agent 集成 ===\n\n";

$tr = new ScriptedTransport3();
$tr->responses = [
    [
        'choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
            'id' => 'c1', 'type' => 'function',
            'function' => ['name' => 'get_weather', 'arguments' => '{"city":"北京"}'],
        ]]], 'finish_reason' => 'tool_calls']],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    ],
    [
        'choices' => [['message' => ['role' => 'assistant', 'content' => '北京晴，25℃。'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 5, 'total_tokens' => 25],
    ],
];
$ai2 = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai2->setTransport($tr);

$events = [];
$agent = (new Agent($ai2))
    ->setSystem('天气助手')
    ->setTools(['get_weather' => [
        'description' => '查天气',
        'input_schema' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
        'handler' => function ($in) { return '晴'; },
    ]])
    ->setPermissionMode('auto')
    ->setMaxBudget(10.0)
    ->setSessionId('integrated-1')
    ->setSessionManager(new SessionManager(new FileSessionStore($storeDir)))
    ->onEvent(function ($e) use (&$events) { $events[] = $e['type']; });

$agent->run([['role' => 'user', 'content' => '北京天气']]);
check($agent->lastText() === '北京晴，25℃。', '集成 Agent 正常运行');
check(in_array('tool_call', $events, true), 'tool_call 事件正常');
check(in_array('done', $events, true), 'done 事件正常');

// 会话已持久化
$storedSession = $store->load('integrated-1');
check($storedSession !== null && $storedSession->isCompleted(), '会话自动持久化并标记完成');

// 清理
foreach (glob($workdir . '/*') as $f) { @unlink($f); }
@rmdir($workdir);

echo "\n", str_repeat('=', 60), "\n";
if ($failures) {
    echo count($failures) . " 项未通过：\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    exit(1);
}
echo "全部通过：Agent Runtime Phase 2-7 高级功能工作正常\n";
exit(0);
