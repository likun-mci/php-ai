<?php
/**
 * LSP 客户端与 lsp 工具测试（dev.md 第三梯队 1）
 *
 * 用 tests/fixtures/fake-lsp-server.php（说标准 LSP 分帧的假服务器）做端到端验证：
 * 握手、didOpen、definition/references/hover/symbols、跳过穿插通知、位置基准换算、
 * URI↔路径、相对路径输出、服务器缺失时的降级提示。
 *
 * 运行：php tests/agent_lsp_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Lsp\LspClient;
use Ai\Agent\Tools\LspTool;
use Ai\Agent\Tools\PathSafety;
use Ai\Agent\Tool\ToolContext;

$passed = 0;
$failed = 0;
function test($name, $ok)
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "✓ {$name}\n"; }
    else { $failed++; echo "✗ {$name}\n"; }
}
function rrmdir($d)
{
    if (!is_dir($d)) { return; }
    foreach (scandir($d) ?: [] as $i) {
        if ($i === '.' || $i === '..') { continue; }
        $p = "$d/$i"; is_dir($p) && !is_link($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($d);
}

$root = '/tmp/lsp-proj';
rrmdir($root);
@mkdir($root . '/src', 0777, true);
file_put_contents($root . '/src/A.php', "<?php\nclass A {\n    public function login() {}\n}\n");
file_put_contents($root . '/src/Target.php', "<?php\nclass Target {}\n");

$server = __DIR__ . '/fixtures/fake-lsp-server.php';
$phpBin = PHP_BINARY;

// 用 php 解释器直接跑假服务器（脚本本身有 shebang，也可直接执行）
$client = new LspClient($phpBin, [$server], ['rootPath' => $root, 'timeout' => 15]);

// ===== 一、握手 =====
echo "=== 一、initialize 握手 ===\n";
test('initialize 成功', $client->initialize());
test('initialize 幂等', $client->initialize());
$caps = $client->capabilities();
test('拿到服务器能力', isset($caps['definitionProvider']) && $caps['definitionProvider'] === true);

// ===== 二、definition（单个 Location 形态）=====
echo "\n=== 二、definition ===\n";
$defs = $client->definition($root . '/src/A.php', 3, 20);
test('返回 1 处定义', count($defs) === 1);
test('URI 转回本地路径', $defs && $defs[0]['file'] === '/tmp/lsp-proj/src/Target.php');
test('行号 0 基转 1 基（LSP 9 → 10）', $defs && $defs[0]['line'] === 10);
test('列号保持 0 基', $defs && $defs[0]['character'] === 4);

// ===== 三、references（Location[] 形态）=====
echo "\n=== 三、references ===\n";
$refs = $client->references($root . '/src/A.php', 3, 20);
test('返回 2 处引用', count($refs) === 2);
test('第二处行号换算正确（41 → 42）', isset($refs[1]) && $refs[1]['line'] === 42);

// ===== 四、hover（MarkupContent 形态）=====
echo "\n=== 四、hover ===\n";
$hover = $client->hover($root . '/src/A.php', 3, 20);
test('hover 抽出 value', strpos($hover, 'function login') !== false);

// ===== 五、documentSymbol（两种符号形态混合）=====
echo "\n=== 五、symbols ===\n";
$syms = $client->documentSymbols($root . '/src/A.php');
test('返回 2 个符号', count($syms) === 2);
test('DocumentSymbol 形态取到行号（4 → 5）', isset($syms[0]) && $syms[0]['line'] === 5 && $syms[0]['name'] === 'UserService');
test('SymbolInformation 形态取到行号（11 → 12）', isset($syms[1]) && $syms[1]['line'] === 12);

$client->close();

// ===== 六、LspTool 端到端 =====
echo "\n=== 六、LspTool ===\n";
$ps = new PathSafety($root);
$ctx = new ToolContext(['workdir' => $root]);
$tool = new LspTool($ps, $phpBin, [$server], ['rootPath' => $root, 'timeout' => 15]);

$r = $tool->execute(['action' => 'definition', 'path' => 'src/A.php', 'line' => 3, 'character' => 20], $ctx);
test('工具 definition 成功', $r->isSuccess());
test('输出转相对路径', strpos($r->getContent(), 'src/Target.php:10:4') !== false);
test('metadata 计数', $r->getMetadata()['count'] === 1);

$r = $tool->execute(['action' => 'references', 'path' => 'src/A.php', 'line' => 3, 'character' => 20], $ctx);
test('工具 references 返回 2 条', $r->getMetadata()['count'] === 2);

$r = $tool->execute(['action' => 'hover', 'path' => 'src/A.php', 'line' => 3, 'character' => 20], $ctx);
test('工具 hover 成功', $r->isSuccess() && strpos($r->getContent(), 'login') !== false);

$r = $tool->execute(['action' => 'symbols', 'path' => 'src/A.php'], $ctx);
test('工具 symbols 返回 2 个', $r->getMetadata()['count'] === 2);

// 参数校验
test('未知 action 报错', !$tool->execute(['action' => 'bogus', 'path' => 'src/A.php'], $ctx)->isSuccess());
test('缺 line 报错', !$tool->execute(['action' => 'definition', 'path' => 'src/A.php'], $ctx)->isSuccess());
test('空 path 报错', !$tool->execute(['action' => 'definition', 'path' => '', 'line' => 1], $ctx)->isSuccess());
test('文件不存在报错', !$tool->execute(['action' => 'definition', 'path' => 'nope.php', 'line' => 1], $ctx)->isSuccess());
$tool->shutdown();

// ===== 七、服务器缺失时明确降级 =====
echo "\n=== 七、服务器缺失降级 ===\n";
$missing = new LspTool($ps, 'definitely-not-installed-lsp-xyz', ['--stdio'], ['rootPath' => $root]);
$r = $missing->execute(['action' => 'definition', 'path' => 'src/A.php', 'line' => 1], $ctx);
test('未安装时报错而非静默', !$r->isSuccess());
test('提示改用 grep/code_index', strpos($r->getError(), 'grep') !== false);

rrmdir($root);
echo "\n" . str_repeat('=', 50) . "\n";
if ($failed === 0) { echo "全部通过：{$passed} 通过，0 失败\n"; exit(0); }
echo "有失败：{$passed} 通过，{$failed} 失败\n";
exit(1);
