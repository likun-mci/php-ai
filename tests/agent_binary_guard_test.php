<?php
/**
 * 二进制文件防护测试（dev.md 第二梯队 2 的实际收敛点）
 *
 * 真实 bug：read_file 读图片/PDF 会把原始字节灌进上下文 → 非法 UTF-8 →
 * 下一次请求 json_encode() 失败 → 整个 Agent 运行中断。grep 命中二进制同理。
 *
 * 运行：php tests/agent_binary_guard_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Tools\ReadFileTool;
use Ai\Agent\Tools\GrepTool;
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
        $p = "$d/$i";
        is_dir($p) && !is_link($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($d);
}

$base = sys_get_temp_dir() . '/php-ai-bin_' . getmypid();
rrmdir($base);
@mkdir($base, 0777, true);

// 一个最小合法 PNG（1x1）
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents($base . '/pic.png', $png);
file_put_contents($base . '/doc.pdf', "%PDF-1.4\n\x00\x01\x02binary\xff\xfe");
file_put_contents($base . '/blob.bin', "\x00\x01\x02\xff\xfe\xfd needle");
file_put_contents($base . '/code.php', "<?php\n// needle here\n\$x=1;\n");

$ps = new PathSafety($base);
$ctx = new ToolContext(['workdir' => $base]);
$read = new ReadFileTool($ps, 100000);

// ===== 一、read_file 不再灌入原始字节 =====
echo "=== 一、read_file 二进制防护 ===\n";
$r = $read->execute(['path' => 'pic.png'], $ctx);
test('读图片返回成功（不是误报错误）', $r->isSuccess());
$c = $r->getContent();
test('内容是合法 UTF-8', mb_check_encoding($c, 'UTF-8'));
test('json_encode 不再失败', json_encode(['content' => $c]) !== false);
test('内容说明是图片', strpos($c, '图片') !== false);
$m = $r->getMetadata();
test('metadata 标记 binary', !empty($m['binary']));
test('metadata 带宽高', isset($m['width']) && $m['width'] === 1 && $m['height'] === 1);

$r = $read->execute(['path' => 'doc.pdf'], $ctx);
test('PDF 识别为 PDF', $r->isSuccess() && strpos($r->getContent(), 'PDF') !== false);
test('PDF 内容合法 UTF-8', mb_check_encoding($r->getContent(), 'UTF-8'));

$r = $read->execute(['path' => 'blob.bin'], $ctx);
test('通用二进制被拦', $r->isSuccess() && !empty($r->getMetadata()['binary']));
test('通用二进制内容合法 UTF-8', mb_check_encoding($r->getContent(), 'UTF-8'));

// ===== 二、文本文件不受影响 =====
echo "\n=== 二、文本文件不受影响 ===\n";
$r = $read->execute(['path' => 'code.php'], $ctx);
test('文本正常读取', $r->isSuccess() && strpos($r->getContent(), 'needle here') !== false);
test('文本无 binary 标记', empty($r->getMetadata()['binary']));

// ===== 三、grep 跳过二进制 =====
echo "\n=== 三、grep 跳过二进制 ===\n";
$grep = (new GrepTool($ps, 100))->setEngine('php');
$r = $grep->execute(['pattern' => 'needle'], $ctx);
test('grep 成功', $r->isSuccess());
$gc = $r->getContent();
test('grep 结果合法 UTF-8', mb_check_encoding($gc, 'UTF-8'));
test('grep json_encode 不失败', json_encode(['c' => $gc]) !== false);
test('命中文本文件 code.php', strpos($gc, 'code.php') !== false);
test('跳过二进制 blob.bin', strpos($gc, 'blob.bin') === false);

rrmdir($base);
echo "\n" . str_repeat('=', 50) . "\n";
if ($failed === 0) { echo "全部通过：{$passed} 通过，0 失败\n"; exit(0); }
echo "有失败：{$passed} 通过，{$failed} 失败\n";
exit(1);
