<?php
/**
 * v1.63 双根存储 / 身份隔离 / 会话安全 测试
 *
 * 覆盖 dev.md 第二十五节要求：HOME 探测、项目根解析、userId 隔离、
 * session 安全（safeName / ownership / 损坏文件 / 原子写 / 旧文件名）、
 * 只读零副作用。
 *
 * 绝不污染真实 HOME：全程使用临时 HOME，结束递归删除并恢复环境。
 *
 * 运行：php tests/agent_storage_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Helpers\Path;
use Ai\Agent\Storage\AgentHome;
use Ai\Agent\Session\FileSessionStore;
use Ai\Agent\Session\OwnedSessionStore;
use Ai\Agent\Session\AgentSession;
use Ai\Agent\Memory\MemoryManager;

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

// ===== 测试环境：临时 HOME =====
$origHome = getenv('HOME');
$tmpBase  = sys_get_temp_dir() . '/php-ai-agent-test_' . getmypid();
rrmdir($tmpBase);
@mkdir($tmpBase, 0700, true);

$fakeHome = $tmpBase . '/home';
@mkdir($fakeHome, 0700, true);
putenv('HOME=' . $fakeHome);

$ai = new AI();  // 无 key：只测存储布线，不发请求

// ===== 一、Path helper =====
echo "=== 一、Path helper ===\n";
assert_eq('normalize 合并 .. 与重复斜杠', '/a/c/d', Path::normalize('/a/b/../c//d/./'));
assert_eq('normalize 保留相对前导 ..', '../b', Path::normalize('a/../../b'));
assert_eq('normalize 空串归一为 .', '.', Path::normalize(''));
test('isAbsolute /x', Path::isAbsolute('/x'));
test('isAbsolute x 为假', !Path::isAbsolute('x'));
test('isAbsolute C:\\ 为真', Path::isAbsolute('C:\\win'));
// safeName 无损：不同原 id 不碰撞
test('safeName a/b != a_b（散列后缀区分）', Path::safeName('a/b') !== Path::safeName('a_b'));
test('safeName a.b != a/b', Path::safeName('a.b') !== Path::safeName('a/b'));
test('safeName 幂等', Path::safeName('x/y') === Path::safeName('x/y'));
test('safeName 不含斜杠', strpos(Path::safeName('a/b/../c'), '/') === false);
// slug 稳定且与 dev.md 2.5 一致
assert_eq('slug 与 dev.md 示例一致', 'root-workspace-php-ai-29c1cb80cd75', Path::slug('/root/workspace/php-ai'));

// ===== 二、HOME 探测 =====
echo "\n=== 二、HOME 探测 ===\n";
assert_eq('HOME 正常', Path::normalize($fakeHome), Path::home());

// HOME=/ 与不存在路径都必须被拒；此环境 posix_getpwuid 可能兜底给出真实 home，
// 因此断言「不等于被拒的候选」而非「一定为空」——关键是绝不采用 / 或不存在的路径
putenv('HOME=/');
$h = Path::home();
test('HOME=/ 被拒（绝不返回根）', $h !== '/' && $h !== '');
putenv('HOME=/nonexistent_' . getmypid());
$h = Path::home();
test('HOME 不存在被拒（不返回该路径）', strpos($h, 'nonexistent_') === false);
putenv('HOME=' . $fakeHome);
test('恢复 HOME 后可探测', Path::home() === Path::normalize($fakeHome));

// HOME 不可用时 AgentHome 走临时回退——仅当 posix 兜底也拿不到 home 时才可测
putenv('HOME=/nonexistent_' . getmypid());
if (Path::home() === '') {
    $hTmp = new AgentHome('/tmp/x');
    test('无 HOME 时 isTempHome', $hTmp->isTempHome());
    test('临时根位于 sys_get_temp_dir', strpos($hTmp->home(), sys_get_temp_dir()) === 0);
} else {
    echo "  （本环境 posix_getpwuid 兜底，跳过临时回退用例）\n";
}
putenv('HOME=' . $fakeHome);

// ===== 三、项目根解析 =====
echo "\n=== 三、项目根解析 ===\n";
$proj = $tmpBase . '/proj';
@mkdir($proj . '/.agent', 0755, true);
@mkdir($proj . '/src/deep', 0755, true);

$h1 = new AgentHome($proj);
assert_eq('项目内直接命中 .agent', Path::normalize($proj), $h1->projectRoot());

$h2 = new AgentHome($proj . '/src/deep');
assert_eq('子目录向上找到 .agent', Path::normalize($proj), $h2->projectRoot());

$noAgent = $tmpBase . '/plain';
@mkdir($noAgent, 0755, true);
$h3 = new AgentHome($noAgent);
assert_eq('无 .agent 时项目根=workdir', Path::normalize($noAgent), $h3->projectRoot());

$h4 = new AgentHome($proj . '/src/deep', ['projectRoot' => $tmpBase . '/explicit']);
assert_eq('显式 setProjectRoot 优先', Path::normalize($tmpBase . '/explicit'), $h4->projectRoot());

// HOME 边界：workdir 在 HOME 下、且 HOME 里放了 .agent，也不能把 HOME 当项目根
@mkdir($fakeHome . '/.agent', 0755, true);
@mkdir($fakeHome . '/work', 0755, true);
$h5 = new AgentHome($fakeHome . '/work');
test('不把 HOME 当项目根', $h5->projectRoot() !== Path::normalize($fakeHome));
rrmdir($fakeHome . '/.agent');

// ===== 四、userId 隔离 =====
echo "\n=== 四、userId 隔离 ===\n";
$hu = new AgentHome($proj, ['userId' => 'u_10086']);
assert_eq('userId 分片与 dev.md 2.6 一致',
    Path::normalize($fakeHome) . '/.agent/users/fc/28/fc28289a895d91bfd54e5b08176da84d',
    $hu->userDir('u_10086'));
test('user A != user B 目录不同', $hu->userDir('A') !== $hu->userDir('B'));
test('原始 userId 不出现在路径中', strpos($hu->userDir('u_10086'), 'u_10086') === false);
// 特殊字符 userId 仍生成安全路径
foreach (['张三', 'a/b', 'x@y.com', 'a b', '../etc'] as $uid) {
    $d = $hu->userDir($uid);
    test("userId『{$uid}』路径安全（无原文、无斜杠段穿越）",
        $d !== '' && strpos($d, $uid) === false && strpos($d, '..') === false);
}
assert_eq('空 userId 无 user 目录', '', $hu->userDir(''));
$hNoUser = new AgentHome($proj);  // 不带 userId
assert_eq('无 userId 实例无 user memory', '', $hNoUser->memoryPath('user'));

// ===== 五、Memory scope 映射 =====
echo "\n=== 五、Memory scope 映射 ===\n";
$hm = new AgentHome($proj, ['userId' => 'u1']);
assert_eq('agent → HOME/.agent/AGENT.md',
    $hm->home() . '/AGENT.md', $hm->memoryPath('agent'));
assert_eq('project 可写 → 项目侧 .agent/AGENT.md',
    Path::normalize($proj) . '/.agent/AGENT.md', $hm->memoryPath('project'));
$readPaths = $hm->memoryReadPaths('project');
test('project 读路径含主 + 回退两处', count($readPaths) === 2);

// 项目不可写 → project memory 走 HOME 回退
$hm->markProjectUnwritable('test');
test('markProjectUnwritable 后 isProjectWritable 为假', !$hm->isProjectWritable());
test('project 写目标回退到 HOME/projects/<slug>',
    strpos($hm->memoryPath('project'), '/projects/') !== false);

// MemoryManager scope 文件注入
$mm = new MemoryManager();
$mm->setScopeFiles([
    'agent'   => $tmpBase . '/mmtest/agent.md',
    'project' => $tmpBase . '/mmtest/project.md',
]);
assert_eq('resolveFile 返回注入路径', $tmpBase . '/mmtest/agent.md', $mm->resolveFile('agent'));
test('未定位 scope resolveFile 返回空', $mm->resolveFile('user') === '');
$mm->remember('agent', '记住这条');
test('remember 后读回', strpos($mm->read('agent'), '记住这条') !== false);
test('forPrompt 含 agent 段', strpos($mm->forPrompt(), '## agent') !== false);

// ===== 六、Session 安全 =====
echo "\n=== 六、Session 安全 ===\n";
$sdir = $tmpBase . '/sessions';
$store = new FileSessionStore($sdir);

// safeName：a/b 与 a_b 落到不同文件（不串号）
$sA = new AgentSession('a/b', ['messages' => [['role' => 'user', 'content' => 'A']]]);
$sB = new AgentSession('a_b', ['messages' => [['role' => 'user', 'content' => 'B']]]);
$store->save($sA);
$store->save($sB);
$lA = $store->load('a/b');
$lB = $store->load('a_b');
test('a/b 与 a_b 不串号', $lA !== null && $lB !== null
    && $lA->getMessages()[0]['content'] === 'A'
    && $lB->getMessages()[0]['content'] === 'B');

// 构造不建目录之外，save 后目录存在
test('save 惰性建目录', is_dir($sdir));
// 无残留 .tmp
$tmpLeft = glob($sdir . '/*.tmp.*');
test('原子写无 .tmp 残留', is_array($tmpLeft) && count($tmpLeft) === 0);

// 不存在 → null（不建文件）
test('加载不存在会话返回 null', $store->load('no-such-id') === null);

// 损坏 JSON：改名保留残骸，不当成不存在
$badPath = $sdir . '/' . Path::safeName('corrupt-id') . '.json';
file_put_contents($badPath, '{不是合法json');
$loadedBad = $store->load('corrupt-id');
test('损坏文件返回 null', $loadedBad === null);
$corruptLeft = glob($sdir . '/' . Path::safeName('corrupt-id') . '.json.corrupt.*');
test('损坏文件被改名保留（非静默删除）', is_array($corruptLeft) && count($corruptLeft) === 1);
test('损坏原文件不再存在（已改名）', !is_file($badPath));

// 旧文件名兼容读取
$legacyName = preg_replace('/[^a-zA-Z0-9\-_]/', '_', 'legacy-sess');
file_put_contents($sdir . '/' . $legacyName . '.json',
    json_encode(['messages' => [['role' => 'user', 'content' => 'OLD']]]));
$legacyLoaded = $store->load('legacy-sess');
test('旧文件名仍可读', $legacyLoaded !== null && $legacyLoaded->getMessages()[0]['content'] === 'OLD');

// ownership 校验
$owned = new OwnedSessionStore(new FileSessionStore($sdir . '/owned'), 'u_10086', 'slug-x');
$os = new AgentSession('sid1', ['messages' => [['role' => 'user', 'content' => 'hi']]]);
$owned->save($os);
$reload = $owned->load('sid1');
test('归属一致可加载', $reload !== null);
test('save 盖上 userId 戳', $reload !== null && $reload->getUserId() === 'u_10086');
$wrongUser = new OwnedSessionStore(new FileSessionStore($sdir . '/owned'), 'u_999', 'slug-x');
test('userId 不符拒绝加载', $wrongUser->load('sid1') === null);
$wrongProj = new OwnedSessionStore(new FileSessionStore($sdir . '/owned'), 'u_10086', 'slug-Y');
test('projectId 不符拒绝加载', $wrongProj->load('sid1') === null);

// AgentSession system/extra 往返（dev.md 第二十节修复）
$se = new AgentSession('se', []);
$se->setSystem('SYS')->setExtra(['k' => 'v']);
$roundtrip = new AgentSession('se', $se->toArray());
assert_eq('system 往返', 'SYS', $roundtrip->getSystem());
assert_eq('extra 往返', 'v', $roundtrip->getExtra()['k']);

// ===== 七、只读零副作用 =====
echo "\n=== 七、只读零副作用 ===\n";
$roHome = $tmpBase . '/ro_home';
@mkdir($roHome, 0700, true);
putenv('HOME=' . $roHome);
$roProj = $tmpBase . '/ro_proj';
@mkdir($roProj, 0755, true);

$roAgent = Agent::create($ai)->setWorkdir($roProj)->setUserId('u_ro')->setSessionId('ro-sid');
// 全部只读 API
$roAgent->getConversation();
$roAgent->isAwaitingPermission();
$roAgent->agentHome()->memoryPath('agent');
$roAgent->agentHome()->projectRoot();
$mmRo = $roAgent->getRuntime()->getMemoryManager();
if ($mmRo !== null) {
    $mmRo->read('agent');
    $mmRo->forPrompt();
    $mmRo->resolveFile('user');
}
test('只读后项目侧 .agent/ 未被创建', !is_dir($roProj . '/.agent'));
test('只读后 HOME/.agent/users 未被创建', !is_dir($roHome . '/.agent/users'));
test('只读后 HOME 侧 sessions 未被创建', !is_dir($roHome . '/.agent'));
putenv('HOME=' . $fakeHome);

// ===== 八、Agent 自动持久化端到端 =====
echo "\n=== 八、Agent 自动持久化端到端 ===\n";
$e2eHome = $tmpBase . '/e2e_home';
@mkdir($e2eHome, 0700, true);
putenv('HOME=' . $e2eHome);
$e2eProj = $tmpBase . '/e2e_proj';
@mkdir($e2eProj, 0755, true);

$a1 = Agent::create($ai)->setWorkdir($e2eProj)->setUserId('u_10086')->setSessionId('s1');
$a1->setConversation([['role' => 'user', 'content' => 'hello']]);
// 新实例（模拟跨请求）能恢复
$a2 = Agent::create($ai)->setWorkdir($e2eProj)->setUserId('u_10086')->setSessionId('s1');
$conv = $a2->getConversation();
test('跨实例恢复会话', count($conv) === 1 && $conv[0]['content'] === 'hello');
// 换用户读不到（路径 + 归属双重隔离）
$a3 = Agent::create($ai)->setWorkdir($e2eProj)->setUserId('u_other')->setSessionId('s1');
test('另一用户读不到该会话', $a3->getConversation() === []);
// autoPersist(false) 回到纯内存
$a4 = Agent::create($ai)->setWorkdir($e2eProj)->setSessionId('s2')->autoPersist(false);
$a4->setConversation([['role' => 'user', 'content' => 'x']]);
test('autoPersist(false) 不落盘', $a4->getRuntime()->getSessionManager() === null);
// newSessionId 唯一性
test('newSessionId 唯一', Agent::newSessionId() !== Agent::newSessionId());
assert_eq('newSessionId 长度 32', 32, strlen(Agent::newSessionId()));
putenv('HOME=' . $fakeHome);

// ===== 收尾 =====
echo "\n" . str_repeat('=', 60) . "\n";
if ($origHome === false) { putenv('HOME'); } else { putenv('HOME=' . $origHome); }
rrmdir($tmpBase);

if ($failed === 0) {
    echo "全部通过：{$passed} 通过，0 失败\n";
    exit(0);
}
echo "有失败：{$passed} 通过，{$failed} 失败\n";
exit(1);
