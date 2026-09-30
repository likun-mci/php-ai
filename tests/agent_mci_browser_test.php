<?php
/**
 * mci_browser（授权 + 浏览器操作一体）工具测试
 *
 * 这个工具把两件事收在一个名字下：先取授权（存票据、轮询、落凭据），
 * 再用凭据打站点的 `/Browser/Api/*`。覆盖：
 *
 *   1) 元数据：工具名、action 枚举同时含授权与操作动作
 *   2) 没身份 / 没凭据时操作：拒绝并指路（且不发任何请求）
 *   3) authorize / grant：委托给 BrowserGrantTool，事件与落盘照旧
 *   4) 操作：GET / POST、Bearer 密钥、device 默认取凭据里那台
 *   5) open 默认后台打开（active=0），不抢用户焦点
 *   6) 参数白名单：模型多给的键不原样转给站点
 *   7) 结果整理：text / eval / tabs / devices / open 各自给人话
 *   8) 站点 4xx → 工具失败，并把站点的 message 带给模型
 *   9) screenshot：inline=1 取字节交给媒体门面；门面缺失时如实说明看不到图
 *  10) forget 之后操作重新要求授权
 *
 * 全程用注入的假 HTTP，不联网、不需要站点。
 *
 * 运行：php tests/agent_mci_browser_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Media\FileMediaStore;
use Ai\Agent\Media\MediaManager;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tools\MciBrowserTool;

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
function assert_not_has($name, $needle, $haystack)
{
    $ok = is_string($haystack) && strpos($haystack, $needle) === false;
    if (!$ok) { echo "  不该出现: {$needle}\n  实际: " . var_export($haystack, true) . "\n"; }
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
    return function ($method, $url, array $form = [], $timeout = 0, $key = '') use (&$calls, &$queue) {
        $calls[] = [
            'method' => $method, 'url' => $url, 'form' => $form,
            'timeout' => $timeout, 'key' => $key,
        ];
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
/** 站点操作接口的统一信封：{device, ms, result} */
function api_res($inner, $device = 'DEV-9', $ms = 12)
{
    return json_res(200, ['device' => $device, 'ms' => $ms, 'result' => $inner]);
}

$tmp = sys_get_temp_dir() . '/php-ai-mci-browser_' . getmypid();
rrmdir($tmp);
@mkdir($tmp, 0700, true);
@mkdir($tmp . '/media', 0700, true);

$site      = 'https://browser.example.com';
$grantUrl  = $site . '/Browser/Grant/start';
$pollUrl   = $site . '/Browser/Grant/poll';
$apiBase   = $site . '/Browser/Api/';
$grantDir  = $tmp . '/browser';
$grantFile = $grantDir . '/grant.json';
$credFile  = $grantDir . '/credential.json';

$events = [];
$ctx = function ($storageDir, $withMedia = false) use (&$events, $tmp) {
    $c = new ToolContext([
        'workdir'    => sys_get_temp_dir(),
        'sessionId'  => 'sess-1',
        'userId'     => 'admin:7',
        'storageDir' => $storageDir,
        'emit'       => function ($ev) use (&$events) { $events[] = $ev; },
    ]);
    if ($withMedia) {
        $c->setMediaManager(new MediaManager(new FileMediaStore($tmp . '/media')));
    }
    return $c;
};

// ===== 1) 元数据 =====
$calls = []; $queue = [];
$tool = new MciBrowserTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
assert_eq('工具名', 'mci_browser', $tool->name());
$schema = $tool->schema();
assert_eq('action 是必填', ['action'], $schema['required']);
$enum = $schema['properties']['action']['enum'];
foreach (['authorize', 'grant', 'credential', 'forget'] as $a) {
    test('枚举含授权动作 ' . $a, in_array($a, $enum, true));
}
foreach (['devices', 'tabs', 'open', 'click', 'text', 'eval', 'screenshot', 'wait'] as $a) {
    test('枚举含操作动作 ' . $a, in_array($a, $enum, true));
}
assert_has('描述里点明要先授权', 'authorize', $tool->description());
assert_has('描述里提醒收尾要关掉自己开的标签', 'close', $tool->description());

// ===== 2) 身份不明 / 没凭据 =====
$r = $tool->execute(['action' => 'devices'], $ctx(''));
test('身份不明时操作失败', !$r->isSuccess());
assert_has('身份不明时提示需要身份', 'userId', $r->getError());
assert_eq('身份不明时不发请求', 0, count($calls));

$r = $tool->execute(['action' => 'tabs'], $ctx($tmp));
test('没凭据时操作失败', !$r->isSuccess());
assert_has('没凭据时指路 authorize', 'action: "authorize"', $r->getError());
assert_eq('没凭据时不发请求', 0, count($calls));

$r = $tool->execute(['action' => 'nope'], $ctx($tmp));
test('未知 action 报错', !$r->isSuccess());
assert_has('未知 action 列出可用动作', 'authorize', $r->getError());

$bare = new MciBrowserTool('', [], fake_http($calls, $queue));
test('未配站点时失败', !$bare->execute(['action' => 'devices'], $ctx($tmp))->isSuccess());

// ===== 3) authorize 委托授权流程 =====
$calls = []; $events = [];
$queue = [json_res(200, [
    'ticket'        => 'TICKET-AAA',
    'secret'        => 'SECRET-BBB',
    'authorize_url' => $site . '/Browser/Member/authorize?ticket=TICKET-AAA',
    'poll_url'      => $pollUrl,
    'interval'      => 1,
    'expires_in'    => 600,
])];
$tool = new MciBrowserTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'authorize'], $ctx($tmp));
test('authorize 打到站点授权接口', $calls[0]['url'] === $grantUrl && $calls[0]['method'] === 'POST');
assert_eq('authorize 自报应用名', 'MCI AI Agent', $calls[0]['form']['app']);
test('票据落本地', is_file($grantFile));
test('票据权限 0600', (fileperms($grantFile) & 0777) === 0600);
test('推了 browser_authorize 事件', $events && $events[0]['type'] === 'browser_authorize');
assert_has('authorize 结果给出确认页', 'authorize?ticket=TICKET-AAA', $r->getContent());

// ===== 4) grant 取回凭据 =====
$events = [];
$calls = []; $queue = [json_res(200, [
    'chosen'     => true,
    'device'     => 'DEV-9',
    'name'       => '我的 Edge',
    'online'     => true,
    'key'        => 'mk-mci-PLAINKEY',
    'key_prefix' => 'mk-mci',
    'api_base'   => $apiBase,
])];
$tool = new MciBrowserTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'grant'], $ctx($tmp));
test('grant 成功', $r->isSuccess());
assert_eq('grant 走 poll', $pollUrl, $calls[0]['url']);
assert_has('grant 把密钥交给模型', 'mk-mci-PLAINKEY', $r->getContent());
test('凭据落盘 0600', is_file($credFile) && (fileperms($credFile) & 0777) === 0600);

// ===== 5) devices =====
$calls = []; $queue = [api_res([
    'online'   => [['device_id' => 'DEV-9', 'version' => '1.3.4', 'ip' => '127.0.0.1']],
    'devices'  => [
        ['id' => 'DEV-9', 'name' => '我的 Edge', 'online' => true, 'last_seen' => '2026-09-30 11:31:05'],
        ['id' => 'DEV-1', 'name' => '旧机', 'online' => false, 'last_seen' => '2026-09-22 06:38:23'],
    ],
    'settings' => ['allow_origins' => ['*']],
])];
$tool = new MciBrowserTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'devices'], $ctx($tmp));
test('devices 成功', $r->isSuccess());
assert_eq('devices 打到 status 接口', $apiBase . 'status', $calls[0]['url']);
assert_eq('devices 用 GET', 'GET', $calls[0]['method']);
assert_eq('devices 把密钥交给 HTTP 层（由 request 加 Bearer 头）', 'mk-mci-PLAINKEY', $calls[0]['key']);
assert_eq('devices 默认带上凭据里的设备', 'DEV-9', $calls[0]['form']['device']);
assert_has('devices 列出在线设备', 'DEV-9', $r->getContent());
assert_has('devices 列出离线设备', '旧机', $r->getContent());

// ===== 6) open：默认后台打开，不抢焦点 =====
$calls = []; $queue = [api_res(['tab' => 955404376, 'url' => 'https://example.com/', 'title' => 'Example Domain', 'load' => 'load', 'note' => ''])];
$tool = new MciBrowserTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'open', 'url' => 'https://example.com'], $ctx($tmp));
test('open 成功', $r->isSuccess());
assert_eq('open 用 POST', 'POST', $calls[0]['method']);
assert_eq('open 打到 open 接口', $apiBase . 'open', $calls[0]['url']);
assert_eq('open 传了 url', 'https://example.com', $calls[0]['form']['url']);
assert_eq('open 默认 active=0（不抢焦点）', 0, $calls[0]['form']['active']);
assert_has('open 回报标签 id', '955404376', $r->getContent());
assert_has('open 回报标题', 'Example Domain', $r->getContent());
// 开完就提醒关：这是借用户浏览器开的标签，不提醒就会留下一排
assert_has('open 结果提醒收尾要关掉它', 'close', $r->getContent());
assert_has('open 结果里的关闭提醒带上标签 id', '955404376', $r->getContent());

// close：给人话，且说明不传 tab 关的是哪个
$calls = []; $queue = [api_res(['tab' => 955404376, 'closed' => true])];
$tool = new MciBrowserTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'close', 'tab' => 955404376], $ctx($tmp));
test('close 成功', $r->isSuccess());
assert_eq('close 打到 close 接口', $apiBase . 'close', $calls[0]['url']);
assert_has('close 回报关掉的标签 id', '955404376', $r->getContent());
assert_has('close 说明不传 tab 关的是哪个', '上次操作', $r->getContent());

$calls = []; $queue = [api_res(['tab' => 1, 'url' => 'u', 'title' => 't', 'load' => 'load'])];
$tool = new MciBrowserTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$tool->execute(['action' => 'open', 'url' => 'https://a.com', 'active' => true], $ctx($tmp));
assert_eq('open 传 active=true → 1', 1, $calls[0]['form']['active']);

// ===== 7) 参数白名单 =====
$calls = []; $queue = [api_res(['tab' => 1, 'url' => 'u', 'title' => 't', 'load' => 'load'])];
$tool = new MciBrowserTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$tool->execute([
    'action' => 'open', 'url' => 'https://a.com',
    'device' => 'DEV-OTHER',          // 允许：显式指定设备
    'path'   => '/etc/passwd',        // 不属于 open 的参数
    'evil'   => 'x',                  // 完全未知的键
    'app'    => 'nope',               // 授权专属参数
], $ctx($tmp));
assert_eq('白名单外的参数不转发（path）', false, array_key_exists('path', $calls[0]['form']));
assert_eq('白名单外的参数不转发（evil）', false, array_key_exists('evil', $calls[0]['form']));
assert_eq('白名单外的参数不转发（app）', false, array_key_exists('app', $calls[0]['form']));
assert_eq('显式 device 覆盖默认', 'DEV-OTHER', $calls[0]['form']['device']);

// ===== 8) text / eval / tabs =====
$calls = []; $queue = [api_res(['text' => "第一行\n第二行"])];
$tool = new MciBrowserTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'text', 'selector' => 'h1'], $ctx($tmp));
assert_eq('text 直接给正文', "第一行\n第二行", $r->getContent());
assert_eq('text 用 GET', 'GET', $calls[0]['method']);
assert_eq('text 传到 selector', 'h1', $calls[0]['form']['selector']);
assert_eq('text 也带上 device', 'DEV-9', $calls[0]['form']['device']);

$calls = []; $queue = [api_res(['value' => 'Example Domain / 0', 'type' => 'string'])];
$tool = new MciBrowserTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'eval', 'expression' => 'document.title'], $ctx($tmp));
assert_eq('eval 给回值', 'Example Domain / 0', $r->getContent());
assert_eq('eval 传表达式', 'document.title', $calls[0]['form']['expression']);

$calls = []; $queue = [api_res([
    'total' => 14, 'listed' => 1, 'truncated' => true,
    'tabs'  => [['id' => 955403201, 'title' => 'AI Agent', 'url' => 'https://likun.work/Ai/Page/agent', 'active' => true]],
])];
$tool = new MciBrowserTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'tabs'], $ctx($tmp));
assert_has('tabs 列出标签', '955403201', $r->getContent());
assert_has('tabs 标明当前标签', '（当前）', $r->getContent());
assert_has('tabs 说明被截断', 'all=1', $r->getContent());
assert_has('tabs 提示后续用 tab=', 'tab=', $r->getContent());

// ===== 9) 站点 4xx → 工具失败并带站点 message =====
$calls = []; $queue = [json_res(422, null, 'Error: 找不到元素：h1')];
$tool = new MciBrowserTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'text', 'selector' => 'h1'], $ctx($tmp));
test('站点 4xx 时工具失败', !$r->isSuccess());
assert_has('把站点的 message 带给模型', '找不到元素', $r->getError());

$calls = []; $queue = [json_res(409, null, '浏览器设备不在线')];
$tool = new MciBrowserTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'open', 'url' => 'https://a.com'], $ctx($tmp));
assert_has('设备不在线时如实回报', '不在线', $r->getError());

$calls = []; $queue = [];
$tool = new MciBrowserTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'text'], $ctx($tmp));
test('网络不通时工具失败', !$r->isSuccess());

// ===== 10) screenshot：inline 取字节，交给媒体门面 =====
$onePixelPng = base64_encode((string) base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
));
$calls = []; $queue = [api_res([
    'file' => '/www/shots/m1-20260930-113633-aaaaaaaaaaaa.png',
    'name' => 'm1-20260930-113633-aaaaaaaaaaaa.png',
    'bytes' => 68, 'width' => 1912, 'height' => 909,
    'url' => 'https://example.com/', 'data' => $onePixelPng,
])];
$tool = new MciBrowserTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'screenshot'], $ctx($tmp, true));
test('screenshot 成功', $r->isSuccess());
assert_eq('screenshot 要 inline 字节', 1, $calls[0]['form']['inline']);
assert_eq('screenshot 用 POST', 'POST', $calls[0]['method']);
test('截图作为媒体附上', $r->hasMedia());
assert_has('说明图已进上下文', '视觉输入', $r->getContent());
assert_has('回报落盘的路径', '/www/shots/', $r->getContent());
assert_has('回报页面地址', 'https://example.com/', $r->getContent());

// 没有媒体门面（比如调用方没挂）：仍然成功，但必须说清「这次看不到图」
$calls = []; $queue = [api_res([
    'file' => '/www/shots/x.png', 'bytes' => 68, 'width' => 10, 'height' => 10, 'data' => $onePixelPng,
])];
$tool = new MciBrowserTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'screenshot'], $ctx($tmp));
test('无媒体门面时仍成功', $r->isSuccess());
assert_not_has('无媒体门面时不谎称已附加', '视觉输入', $r->getContent());
assert_has('无媒体门面时说明看不到图', '看不到图', $r->getContent());

// ===== 11) credential / forget =====
$calls = []; $queue = [];
$tool = new MciBrowserTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'credential'], $ctx($tmp));
assert_has('credential 回报设备', 'DEV-9', $r->getContent());
assert_not_has('credential 不回密钥明文', 'mk-mci-PLAINKEY', $r->getContent());

$r = $tool->execute(['action' => 'forget'], $ctx($tmp));
test('forget 成功', $r->isSuccess());
test('forget 删掉凭据', !is_file($credFile));
$r = $tool->execute(['action' => 'devices'], $ctx($tmp));
test('forget 之后操作重新要求授权', !$r->isSuccess());
assert_has('forget 之后指路 authorize', 'action: "authorize"', $r->getError());

// ===== 12) 没有凭据时仍可重复 authorize =====
$calls = []; $queue = [json_res(200, [
    'ticket' => 'T-2', 'secret' => 'S-2', 'authorize_url' => $site . '/a?ticket=T-2',
    'poll_url' => $pollUrl, 'interval' => 1, 'expires_in' => 600,
])];
$tool = new MciBrowserTool($site, ['app' => 'MCI AI Agent'], fake_http($calls, $queue));
$r = $tool->execute(['action' => 'authorize'], $ctx($tmp));
test('清掉凭据后仍能重新申请授权', $r->isSuccess() && $calls[0]['url'] === $grantUrl);

rrmdir($tmp);

echo "\n通过 {$passed} 项，失败 {$failed} 项\n";
exit($failed === 0 ? 0 : 1);
