<?php
/**
 * Web 工具测试：web_fetch / web_search（用 mock HttpFetch，不打真实外网）
 *
 * 覆盖 dev.md v2.1 §1.3：HTML 转文本、截断、SSRF 拦截透传、DDG 结果解析、
 * 权限归外呼档、不默认装配。
 *
 * 运行：php tests/agent_webtools_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Tools\WebFetchTool;
use Ai\Agent\Tools\WebSearchTool;
use Ai\Agent\Tools\ClaudeCodeTools;
use Ai\Agent\Tool\ToolContext;
use Ai\Tools\HttpFetch;
use Ai\Agent\Permission\PermissionManager;

$passed = 0;
$failed = 0;
function test($name, $ok)
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "✓ {$name}\n"; }
    else { $failed++; echo "✗ {$name}\n"; }
}

// 可编程的假 HttpFetch：按 URL 返回预设响应
class FakeHttpFetch extends HttpFetch
{
    /** @var array<string, array<string,mixed>> */
    public $responses = [];
    /** @var array<int, string> */
    public $calls = [];
    public function fetch($url)
    {
        $this->calls[] = $url;
        foreach ($this->responses as $needle => $resp) {
            if ($needle === '*' || strpos($url, $needle) !== false) {
                return array_merge([
                    'ok' => true, 'status' => 200, 'content_type' => 'text/html',
                    'final_url' => $url, 'bytes' => 0, 'body' => '', 'error' => '',
                ], $resp);
            }
        }
        return ['ok' => false, 'status' => 0, 'content_type' => '', 'final_url' => $url, 'bytes' => 0, 'body' => '', 'error' => 'no mock'];
    }
}

$ctx = new ToolContext([]);

// ===== 一、web_fetch HTML → 文本 =====
echo "=== 一、web_fetch HTML 转文本 ===\n";
$fake = new FakeHttpFetch();
$fake->responses['example.com'] = [
    'content_type' => 'text/html',
    'body' => "<html><head><title>T</title><style>.x{color:red}</style></head>"
            . "<body><h1>标题</h1><p>第一段</p><script>alert(1)</script><p>第二段</p></body></html>",
];
$wf = new WebFetchTool($fake);
$r = $wf->execute(['url' => 'https://example.com/doc'], $ctx);
test('web_fetch 成功', $r->isSuccess());
$c = $r->getContent();
test('HTML 抽出正文文本', strpos($c, '标题') !== false && strpos($c, '第一段') !== false && strpos($c, '第二段') !== false);
test('去掉了 script 内容', strpos($c, 'alert(1)') === false);
test('去掉了 style 内容', strpos($c, 'color:red') === false);

// ===== 二、web_fetch 抓取失败 =====
echo "\n=== 二、web_fetch 失败处理 ===\n";
$fakeErr = new FakeHttpFetch();  // 无匹配 → ok:false
$r = (new WebFetchTool($fakeErr))->execute(['url' => 'https://blocked.internal/x'], $ctx);
test('抓取失败返回 error', !$r->isSuccess() && strpos($r->getError(), 'web_fetch 失败') !== false);
$r2 = (new WebFetchTool($fake))->execute(['url' => ''], $ctx);
test('空 url 报错', !$r2->isSuccess());

// ===== 三、web_fetch 截断 =====
echo "\n=== 三、web_fetch 截断 ===\n";
$big = new FakeHttpFetch();
$big->responses['big.com'] = ['content_type' => 'text/plain', 'body' => str_repeat('A', 5000)];
$wfSmall = new WebFetchTool($big, 1024);
$r = $wfSmall->execute(['url' => 'https://big.com/'], $ctx);
$m = $r->getMetadata();
test('超长被截断', !empty($m['truncated']) && strpos($r->getContent(), '截断') !== false);

// ===== 四、web_search DDG 解析 =====
echo "\n=== 四、web_search 解析 ===\n";
$ddgHtml = '<html><body>'
    . '<a rel="nofollow" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fphp.net%2Fmanual" class="result-link">PHP Manual</a>'
    . '<td class="result-snippet">官方手册</td>'
    . '<a rel="nofollow" href="https://example.org/2" class="result-link">Second</a>'
    . '<td class="result-snippet">第二条摘要</td>'
    . '</body></html>';
$fakeS = new FakeHttpFetch();
$fakeS->responses['duckduckgo.com/lite'] = ['content_type' => 'text/html', 'body' => $ddgHtml];
$ws = new WebSearchTool($fakeS);
$r = $ws->execute(['query' => 'php manual'], $ctx);
test('web_search 成功', $r->isSuccess());
$m = $r->getMetadata();
test('解析出 2 条结果', isset($m['results']) && $m['results'] === 2);
$c = $r->getContent();
test('第一条标题正确', strpos($c, 'PHP Manual') !== false);
test('uddg 包装被解出真实链接', strpos($c, 'https://php.net/manual') !== false);
test('带出摘要', strpos($c, '官方手册') !== false);
// 请求 URL 用 lite 端点 + 编码 query
test('请求打到 DDG lite', count($fakeS->calls) === 1 && strpos($fakeS->calls[0], 'lite.duckduckgo.com/lite') !== false);

// limit 生效
$r = (new WebSearchTool($fakeS))->execute(['query' => 'x', 'limit' => 1], $ctx);
test('limit=1 只返 1 条', $r->getMetadata()['results'] === 1);

// 无结果
$fakeEmpty = new FakeHttpFetch();
$fakeEmpty->responses['*'] = ['body' => '<html><body>nothing</body></html>'];
$r = (new WebSearchTool($fakeEmpty))->execute(['query' => 'zzz'], $ctx);
test('无结果友好返回', $r->isSuccess() && $r->getMetadata()['results'] === 0);

// ===== 五、权限：外呼工具 manual 询问、dont_ask 放行 =====
echo "\n=== 五、权限门控 ===\n";
$manual = new PermissionManager(PermissionManager::MODE_MANUAL);
$rf = $manual->check(new WebFetchTool($fake), ['url' => 'https://x.com'], $ctx);
test('manual 下 web_fetch 需询问', $rf->needsAsk() || $rf->isDenied());
$rs = $manual->check(new WebSearchTool($fake), ['query' => 'x'], $ctx);
test('manual 下 web_search 需询问', $rs->needsAsk() || $rs->isDenied());
$dontAsk = new PermissionManager(PermissionManager::MODE_DONT_ASK);
test('dont_ask 放行 web_fetch', $dontAsk->check(new WebFetchTool($fake), [], $ctx)->isAllowed());

// ===== 六、不默认装配、web() 显式启用 =====
echo "\n=== 六、装配策略 ===\n";
$all = ClaudeCodeTools::all(['workdir' => sys_get_temp_dir()]);
test('all() 不含 web_fetch', !isset($all['web_fetch']));
test('all() 不含 web_search', !isset($all['web_search']));
$web = ClaudeCodeTools::web();
test('web() 提供 web_fetch', isset($web['web_fetch']) && $web['web_fetch'] instanceof WebFetchTool);
test('web() 提供 web_search', isset($web['web_search']) && $web['web_search'] instanceof WebSearchTool);

echo "\n" . str_repeat('=', 50) . "\n";
if ($failed === 0) { echo "全部通过：{$passed} 通过，0 失败\n"; exit(0); }
echo "有失败：{$passed} 通过，{$failed} 失败\n";
exit(1);
