<?php
/**
 * browser_authorize（浏览器授权取码）工具测试
 *
 * 覆盖「AI 弹授权链接 → 用户在自己浏览器里确认 → 取回 device + 会员密钥」：
 *
 *   1) 身份不明（storageDir 为空）时拒绝写盘，并说明原因
 *   2) start 拿到票据后：secret 落本地（0600）、authorize_url 经事件交给宿主
 *   3) status 未确认 → 不落凭据，给模型可读的提示
 *   4) status 已确认 → 取回密钥、落盘 0600、事件里不带密钥明文
 *   5) credential / forget 的行为
 *   6) 票据过期、票据失效（409）后的本地清理
 *
 * 全程用注入的假 HTTP，不联网、不需要任何站点。
 *
 * 运行：php tests/agent_browser_grant_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tools\BrowserGrantTool;

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
function assert_has($name, $needle, $haystack)
{
    $ok = is_string($haystack) && strpos($haystack, $needle) !== false;
    if (!$ok) { echo "  未找到: {$needle}\n  实际: " . var_export($haystack, true) . "\n"; }
    test($name, $ok);
}
function rrmdir($dir)
{
    if (!is_dir($dir)) { return; }
    foreach ((array) scandir($dir) as $it) {
        if ($it === '.' || $it === '..') { continue; }
        $p = $dir . '/' . $it;
        if (is_dir($p) && !is_link($p)) { rrmdir($p); } else { @unlink($p); }
    }
    @rmdir($dir);
}

// 假 HTTP：按队列返回预设响应，并记录收到的请求
function fake_http(array &$calls, array &$queue)
{
    return function ($method, $url, array $form = []) use (&$calls, &$queue) {
        $calls[] = ['method' => $method, 'url' => $url, 'form' => $form];
        if (!$queue) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => '队列为空'];
        }
        return array_shift($queue);
    };
}
function json_res($status, $result, $message = 'ok')
{
    return [
        'ok'     => true,
        'status' => $status,
        'body'   => json_encode(['status' => $status, 'message' => $message, 'result' => $result], JSON_UNESCAPED_UNICODE),
        'error'  => '',
    ];
}

$tmp = sys_get_temp_dir() . '/php-ai-browser-grant_' . getmypid();
rrmdir($tmp);
@mkdir($tmp, 0700, true);

$site = 'https://browser.example.com';
$grantUrl  = $site . '/Browser/Grant/start';
$pollUrl   = $site . '/Browser/Grant/poll';
$grantDir  = $tmp . '/browser';
$grantFile = $grantDir . '/grant.json';
$credFile  = $grantDir . '/credential.json';

$events = [];
$ctx = function ($storageDir, $sessionId = 'sess-1') use (&$events) {
    return new ToolContext([
        'workdir'    => sys_get_temp_dir(),
        'sessionId'  => $sessionId,
        'userId'     => 'admin:7',
        'storageDir' => $storageDir,
        'emit'       => function ($ev) use (&$events) { $events[] = $ev; },
    ]);
};

// ===== 1) 身份不明：拒绝写盘，不发请求 =====
$calls = []; $queue = [];
$tool = new BrowserGrantTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'start'], $ctx('', ''));
test('身份不明时 start 失败', !$r->isSuccess());
assert_has('身份不明时提示需要 userId 或 sessionId', 'userId', $r->getError());
assert_eq('身份不明时不发任何请求', 0, count($calls));

// ===== 2) start：票据与 secret 落本地，authorize_url 经事件交出 =====
$calls = []; $queue = [
    json_res(200, [
        'ticket'        => 'TICKET-AAA',
        'secret'        => 'SECRET-BBB',
        'authorize_url' => $site . '/Browser/Member/authorize?ticket=TICKET-AAA',
        'poll_url'      => $pollUrl,
        'interval'      => 1,
        'expires_in'    => 300,
    ]),
];
$events = [];
$tool = new BrowserGrantTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'start'], $ctx($tmp));
test('start 成功', $r->isSuccess());
assert_eq('start 打到站点 start 接口', $grantUrl, $calls[0]['url']);
assert_eq('start 用 POST', 'POST', $calls[0]['method']);
assert_eq('start 自报应用名', 'MCI AI Agent', $calls[0]['form']['app']);
assert_has('start 结果里给出授权链接', 'authorize?ticket=TICKET-AAA', $r->getContent());
test('票据文件已写入', is_file($grantFile));
test('票据文件权限 0600', (fileperms($grantFile) & 0777) === 0600);
test('授权目录权限 0700', (fileperms($grantDir) & 0777) === 0700);
$saved = json_decode((string) file_get_contents($grantFile), true);
assert_eq('本地留下 secret 供轮询', 'SECRET-BBB', $saved['secret']);
assert_eq('本地留下 poll_url', $pollUrl, $saved['poll_url']);
test('过期时间已算好', (int) $saved['expires_at'] > time());
assert_eq('推了一个事件', 1, count($events));
assert_eq('事件类型是 browser_authorize', 'browser_authorize', $events[0]['type']);
assert_eq('事件带上授权链接', $site . '/Browser/Member/authorize?ticket=TICKET-AAA', $events[0]['authorize_url']);
test('事件里不带 secret', !isset($events[0]['secret']));

// ===== 3) status：用户还没确认 =====
$calls = []; $queue = [json_res(200, ['chosen' => false])];
$events = [];
$tool = new BrowserGrantTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'status'], $ctx($tmp));
test('未确认时 status 仍是成功结果', $r->isSuccess());
assert_has('未确认时提示用户去确认', '还没有确认', $r->getContent());
assert_eq('poll 带上 ticket', 'TICKET-AAA', $calls[0]['form']['ticket']);
assert_eq('poll 带上 secret', 'SECRET-BBB', $calls[0]['form']['secret']);
test('未确认时不落凭据', !is_file($credFile));
test('未确认时票据保留', is_file($grantFile));

// ===== 4) status：已确认，取回密钥 =====
$calls = []; $queue = [
    json_res(200, [
        'chosen'     => true,
        'device'     => 'DEV-9',
        'name'       => '我的 Chrome',
        'online'     => true,
        'key'        => 'sk-mci-browser-PLAINKEY',
        'key_prefix' => 'sk-mci-br',
        'api_base'   => $site . '/Browser/Api/',
    ]),
];
$events = [];
$tool = new BrowserGrantTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'status'], $ctx($tmp));
test('已确认时 status 成功', $r->isSuccess());
assert_has('把密钥明文交给模型', 'sk-mci-browser-PLAINKEY', $r->getContent());
assert_has('回报设备', 'DEV-9', $r->getContent());
test('凭据已落盘', is_file($credFile));
test('凭据文件权限 0600', (fileperms($credFile) & 0777) === 0600);
$cred = json_decode((string) file_get_contents($credFile), true);
assert_eq('凭据里有 device', 'DEV-9', $cred['device']);
assert_eq('凭据里有 api_base', $site . '/Browser/Api/', $cred['api_base']);
assert_eq('凭据里有密钥', 'sk-mci-browser-PLAINKEY', $cred['key']);
test('用过的票据被清掉', !is_file($grantFile));
$types = array_column($events, 'type');
test('推了 browser_credential 事件', in_array('browser_credential', $types, true));
$cev = null;
foreach ($events as $e) { if ($e['type'] === 'browser_credential') { $cev = $e; } }
test('凭据事件里不带密钥明文', $cev !== null && !isset($cev['key']));
assert_eq('凭据事件里给了密钥前缀', 'sk-mci-br', $cev['key_prefix']);

// ===== 5) credential / forget =====
$calls = []; $queue = [];
$tool = new BrowserGrantTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'credential'], $ctx($tmp));
test('credential 成功', $r->isSuccess());
assert_has('credential 回报设备', 'DEV-9', $r->getContent());
assert_eq('credential 不发请求', 0, count($calls));
test('credential 不回密钥明文', strpos($r->getContent(), 'sk-mci-browser-PLAINKEY') === false);

$r = $tool->execute(['action' => 'status'], $ctx($tmp));
assert_has('已有凭据时 status 提示无需重新授权', '无需重新授权', $r->getContent());

$r = $tool->execute(['action' => 'forget'], $ctx($tmp));
test('forget 成功', $r->isSuccess());
test('forget 删掉了凭据', !is_file($credFile));
assert_has('forget 提醒去站点吊销', '吊销', $r->getContent());

$r = $tool->execute(['action' => 'forget'], $ctx($tmp));
test('没有凭据时 forget 也不报错', $r->isSuccess());

// ===== 6) 票据失效（409）→ 清本地票据 =====
$calls = []; $queue = [
    json_res(200, ['ticket' => 'T-1', 'secret' => 'S-1', 'authorize_url' => $site . '/a?ticket=T-1', 'poll_url' => $pollUrl, 'interval' => 1, 'expires_in' => 300]),
];
$tool = new BrowserGrantTool($site, ['app' => 'App'], fake_http($calls, $queue));
$tool->execute(['action' => 'start'], $ctx($tmp));
test('新票据已写入', is_file($grantFile));
$calls = []; $queue = [json_res(409, null, '授权请求已失效')];
$tool = new BrowserGrantTool($site, ['app' => 'App'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'status'], $ctx($tmp));
test('失效票据不算工具失败', $r->isSuccess());
assert_has('失效时把服务端原因带给模型', '已失效', $r->getContent());
test('失效后本地票据被清掉', !is_file($grantFile));

// ===== 7) 票据过期 → 清本地票据，提示重新申请 =====
$calls = []; $queue = [];
$tool = new BrowserGrantTool($site, ['app' => 'App'], fake_http($calls, $queue));
@mkdir($grantDir, 0700, true);
file_put_contents($grantFile, json_encode(['ticket' => 'OLD', 'secret' => 'OLD', 'poll_url' => $pollUrl, 'expires_at' => time() - 10]));
$r = $tool->execute(['action' => 'status'], $ctx($tmp));
test('过期票据不算工具失败', $r->isSuccess());
assert_has('过期后提示重新申请', '重新调用 action=start', $r->getContent());
test('过期后本地票据被清掉', !is_file($grantFile));
assert_eq('过期后不再发请求', 0, count($calls));

// ===== 8) 没有票据也没有凭据时问状态 =====
$r = $tool->execute(['action' => 'status'], $ctx($tmp));
assert_has('无票据时提示先 start', 'action: "start"', $r->getContent());

// ===== 9) 参数与配置 =====
$r = $tool->execute(['action' => 'nope'], $ctx($tmp));
test('非法 action 报错', !$r->isSuccess());
$bare = new BrowserGrantTool('', [], fake_http($calls, $queue));
$r = $bare->execute(['action' => 'start'], $ctx($tmp));
test('未配站点时失败', !$r->isSuccess());
assert_eq('工具名', 'browser_authorize', $tool->name());
$schema = $tool->schema();
assert_eq('schema 里 action 是必填', ['action'], $schema['required']);
assert_eq('action 枚举', ['start', 'status', 'credential', 'forget'], $schema['properties']['action']['enum']);

rrmdir($tmp);
@rmdir($tmp . '/browser');

echo "\n通过 {$passed} 项，失败 {$failed} 项\n";
exit($failed === 0 ? 0 : 1);
