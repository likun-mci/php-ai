<?php
/**
 * v1.64 JsonlSessionStore 测试
 *
 * 覆盖 dev.md 第五~八节 / 第二十五节：append、compact rewrite、rollback、
 * state 往返、旧 JSON 兼容、损坏恢复、并发 save 完整性、自动挂载切 JSONL。
 *
 * 运行：php tests/agent_jsonl_session_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Helpers\Path;
use Ai\Agent\Session\JsonlSessionStore;
use Ai\Agent\Session\OwnedSessionStore;
use Ai\Agent\Session\AgentSession;

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
        echo "  期望: " . var_export($expected, true) . "  实际: " . var_export($actual, true) . "\n";
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
function msgs($n)
{
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => 'm' . $i];
    }
    return $out;
}

$base = sys_get_temp_dir() . '/php-ai-jsonl-test_' . getmypid();
rrmdir($base);
@mkdir($base, 0700, true);

$dir = $base . '/sessions';
$store = new JsonlSessionStore($dir);

// ===== 一、构造零副作用 =====
echo "=== 一、构造零副作用 ===\n";
test('构造不创建目录', !is_dir($dir));
test('加载不存在返回 null', $store->load('nope') === null);
test('load 不存在不创建目录', !is_dir($dir));

// ===== 二、append 快路径 =====
echo "\n=== 二、append 快路径 ===\n";
$store->save(new AgentSession('s1', ['messages' => msgs(2)]));
$l = $store->load('s1');
assert_eq('首存 2 条', 2, count($l->getMessages()));
test('save 惰性建目录', is_dir($dir));

$store->save(new AgentSession('s1', ['messages' => msgs(5)]));  // 前缀不变，+3
$l = $store->load('s1');
assert_eq('append 后 5 条', 5, count($l->getMessages()));
assert_eq('末条内容正确', 'm4', $l->getMessages()[4]['content']);

$jsonlFile = $dir . '/' . Path::safeName('s1') . '.jsonl';
$body = (string) file_get_contents($jsonlFile);
$lineCount = count(array_filter(explode("\n", trim($body))));
assert_eq('jsonl 恰好 5 行（纯 append，无 rewrite 标记）', 5, $lineCount);
test('append 路径不含 rewrite 标记', strpos($body, '"rewrite"') === false);
test('无 .tmp 残留', count((array) glob($dir . '/*.tmp.*')) === 0);
test('无 .lock 残留内容（锁文件可存在但为空）',
    !is_file($dir . '/' . Path::safeName('s1') . '.lock')
    || filesize($dir . '/' . Path::safeName('s1') . '.lock') === 0);

// ===== 三、compact / rollback 触发重写 =====
echo "\n=== 三、compact / rollback 重写 ===\n";
$store->save(new AgentSession('s1', ['messages' => [['role' => 'user', 'content' => '摘要']]]));  // 变少
$l = $store->load('s1');
assert_eq('compact 后 1 条', 1, count($l->getMessages()));
assert_eq('compact 内容正确', '摘要', $l->getMessages()[0]['content']);
$body = (string) file_get_contents($jsonlFile);
test('compact 走了 rewrite（含标记）', strpos($body, '"rewrite"') !== false);

// rollback：前缀被替换（数量相同但内容变）
$store->save(new AgentSession('s1', ['messages' => msgs(3)]));   // 重新长回 3 条
$store->save(new AgentSession('s1', ['messages' => [
    ['role' => 'user', 'content' => 'DIFFERENT'],  // 前缀第 0 条变了
    ['role' => 'assistant', 'content' => 'm1'],
    ['role' => 'user', 'content' => 'm2'],
]]));
$l = $store->load('s1');
assert_eq('rollback 后首条被替换', 'DIFFERENT', $l->getMessages()[0]['content']);
assert_eq('rollback 后 3 条', 3, count($l->getMessages()));

// ===== 四、state 往返 =====
echo "\n=== 四、state 往返 ===\n";
$se = new AgentSession('s2', [
    'messages'   => msgs(1),
    'iteration'  => 9,
    'status'     => 'paused',
    'system'     => 'SYS',
    'budget_state' => ['spent' => 3],
    'user_id'    => 'u1',
    'project_id' => 'p1',
]);
$store->save($se);
$l = $store->load('s2');
assert_eq('iteration 往返', 9, $l->getIteration());
assert_eq('status 往返', 'paused', $l->getStatus());
assert_eq('system 往返', 'SYS', $l->getSystem());
assert_eq('budget_state 往返', 3, $l->getBudgetState()['spent']);
assert_eq('user_id 往返', 'u1', $l->getUserId());
assert_eq('project_id 往返', 'p1', $l->getProjectId());
// message 不进 state.json
$stateFile = $dir . '/' . Path::safeName('s2') . '.state.json';
$stateRaw = json_decode((string) file_get_contents($stateFile), true);
test('state.json 不含 messages', is_array($stateRaw) && !isset($stateRaw['messages']));
test('state.json 含 message_count', is_array($stateRaw) && isset($stateRaw['message_count']));
test('state.json 含 last_hash', is_array($stateRaw) && isset($stateRaw['last_hash']));

// ===== 五、旧 <sid>.json 兼容 =====
echo "\n=== 五、旧 JSON 兼容 ===\n";
$legacyName = preg_replace('/[^a-zA-Z0-9\-_]/', '_', 'old-sess');
$legacyJson = $dir . '/' . $legacyName . '.json';
file_put_contents($legacyJson, json_encode([
    'messages' => [['role' => 'user', 'content' => 'LEGACY']],
    'status'   => 'running',
]));
$l = $store->load('old-sess');
test('旧 json 可读', $l !== null && $l->getMessages()[0]['content'] === 'LEGACY');
test('旧 json 不被删除（不自动迁移）', is_file($legacyJson));

// ===== 六、损坏恢复 =====
echo "\n=== 六、损坏恢复 ===\n";
// state.json 损坏 → 改名保留，load 返回 null
$badBase = $dir . '/' . Path::safeName('broken');
file_put_contents($badBase . '.jsonl', '{"seq":1,"type":"message","message":{"role":"user","content":"x"}}' . "\n");
file_put_contents($badBase . '.state.json', '{坏掉的 json');
$l = $store->load('broken');
test('state 损坏返回 null', $l === null);
test('state 损坏被改名保留', count((array) glob($badBase . '.state.json.corrupt.*')) === 1);

// jsonl 单行损坏 → 跳过该行，其余仍读出
$partBase = $dir . '/' . Path::safeName('partial');
file_put_contents($partBase . '.jsonl',
    '{"seq":1,"type":"message","message":{"role":"user","content":"good1"}}' . "\n"
    . '{坏行没写完' . "\n"
    . '{"seq":2,"type":"message","message":{"role":"assistant","content":"good2"}}' . "\n");
$l = $store->load('partial');
test('损坏行被跳过、其余读出', $l !== null && count($l->getMessages()) === 2
    && $l->getMessages()[0]['content'] === 'good1'
    && $l->getMessages()[1]['content'] === 'good2');

// ===== 七、并发 save 完整性 =====
echo "\n=== 七、并发 save 完整性 ===\n";
$concDir = $base . '/conc';
$worker = <<<'PHP'
require %AUTOLOAD%;
use Ai\Agent\Session\JsonlSessionStore;
use Ai\Agent\Session\AgentSession;
$store = new JsonlSessionStore(%DIR%);
$tag = $argv[1];
for ($i = 0; $i < 40; $i++) {
    $s = $store->load('cc');
    $msgs = $s ? $s->getMessages() : [];
    $msgs[] = ['role' => 'user', 'content' => $tag . '-' . $i];
    $store->save(new AgentSession('cc', ['messages' => $msgs]));
}
PHP;
$worker = str_replace(
    ['%AUTOLOAD%', '%DIR%'],
    [var_export(__DIR__ . '/../autoload.php', true), var_export($concDir, true)],
    $worker
);
$wf = $base . '/worker.php';
file_put_contents($wf, "<?php\n" . $worker);
$php = escapeshellarg(PHP_BINARY);
$cmd = "$php " . escapeshellarg($wf) . " A & $php " . escapeshellarg($wf) . " B & wait";
exec($cmd . ' 2>&1', $out, $rc);
$store2 = new JsonlSessionStore($concDir);
$l = $store2->load('cc');
test('并发后仍能加载（文件未损坏）', $l !== null);
test('并发后 state.json 是合法 JSON',
    is_array(json_decode((string) file_get_contents($concDir . '/' . Path::safeName('cc') . '.state.json'), true)));
test('并发后无 .tmp 残留', count((array) glob($concDir . '/*.tmp.*')) === 0);
// jsonl 每行都可解析（无交错半行）
$ccJsonl = $concDir . '/' . Path::safeName('cc') . '.jsonl';
$allLinesOk = true;
foreach (array_filter(explode("\n", trim((string) file_get_contents($ccJsonl)))) as $ln) {
    if (!is_array(json_decode($ln, true))) { $allLinesOk = false; break; }
}
test('并发后 jsonl 每行都合法（锁保证无交错）', $allLinesOk);

// ===== 八、ownership 包裹 JSONL =====
echo "\n=== 八、ownership 包裹 JSONL ===\n";
$owned = new OwnedSessionStore(new JsonlSessionStore($base . '/owned'), 'uA', 'pA');
$owned->save(new AgentSession('os', ['messages' => msgs(1)]));
test('归属一致可读', $owned->load('os') !== null);
test('归属戳已写入', $owned->load('os')->getUserId() === 'uA');
$wrong = new OwnedSessionStore(new JsonlSessionStore($base . '/owned'), 'uB', 'pA');
test('归属不符拒绝加载', $wrong->load('os') === null);

// ===== 九、Agent 自动挂载切 JSONL =====
echo "\n=== 九、Agent 自动挂载 JSONL ===\n";
$origHome = getenv('HOME');
$fakeHome = $base . '/home';
@mkdir($fakeHome, 0700, true);
putenv('HOME=' . $fakeHome);
$ai = new AI();
$a1 = Agent::create($ai)->setWorkdir($base . '/proj')->setUserId('u_10086')->setSessionId('auto1');
$a1->setConversation([['role' => 'user', 'content' => 'hi jsonl']]);
$sm = $a1->getRuntime()->getSessionManager();
test('自动挂载了 SessionManager', $sm !== null);
$inner = $sm !== null ? $sm->getStore() : null;
test('底层是 OwnedSessionStore 包 JsonlSessionStore',
    $inner instanceof OwnedSessionStore && $inner->inner() instanceof JsonlSessionStore);
$a2 = Agent::create($ai)->setWorkdir($base . '/proj')->setUserId('u_10086')->setSessionId('auto1');
test('跨实例经 JSONL 恢复', $a2->getConversation() === [['role' => 'user', 'content' => 'hi jsonl']]);
// 落盘的确是 .jsonl
$found = (array) glob($fakeHome . '/.agent/users/*/*/*/sessions/*.jsonl');
test('磁盘上生成了 .jsonl 文件', count($found) >= 1);
if ($origHome === false) { putenv('HOME'); } else { putenv('HOME=' . $origHome); }

// ===== 收尾 =====
echo "\n" . str_repeat('=', 60) . "\n";
rrmdir($base);
if ($failed === 0) {
    echo "全部通过：{$passed} 通过，0 失败\n";
    exit(0);
}
echo "有失败：{$passed} 通过，{$failed} 失败\n";
exit(1);
