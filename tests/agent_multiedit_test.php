<?php
/**
 * EditFileTool v2.1：MultiEdit 批量原子编辑测试
 *
 * 覆盖 dev.md v2.1 §1.2：edits 顺序应用、原子性（失败不落盘）、兼容单次用法、
 * replace_all、唯一性报错、报失败索引。
 *
 * 运行：php tests/agent_multiedit_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Tools\EditFileTool;
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
function rrmdir($dir)
{
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) ?: [] as $it) {
        if ($it === '.' || $it === '..') { continue; }
        $p = $dir . '/' . $it;
        is_dir($p) && !is_link($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

$base = sys_get_temp_dir() . '/php-ai-medit_' . getmypid();
rrmdir($base);
@mkdir($base, 0777, true);
$ps = new PathSafety($base);
$ctx = new ToolContext(['workdir' => $base]);
$ef = new EditFileTool($ps);

function writef($base, $name, $content) { file_put_contents($base . '/' . $name, $content); }
function readf($base, $name) { return file_get_contents($base . '/' . $name); }

// ===== 一、单次编辑兼容 =====
echo "=== 一、单次编辑兼容 ===\n";
writef($base, 'a.txt', "hello foo\n");
$r = $ef->execute(['path' => 'a.txt', 'old_string' => 'foo', 'new_string' => 'world'], $ctx);
test('单次编辑成功', $r->isSuccess());
test('单次编辑内容正确', readf($base, 'a.txt') === "hello world\n");
$m = $r->getMetadata();
test('单次编辑 replaced=1', isset($m['replaced']) && $m['replaced'] === 1);

// 单次：未找到报错（无 edits[0] 前缀）
$rNF = $ef->execute(['path' => 'a.txt', 'old_string' => '不存在', 'new_string' => 'x'], $ctx);
test('单次未找到报错', !$rNF->isSuccess() && strpos($rNF->getError(), 'edits[') === false);

// ===== 二、批量顺序应用 =====
echo "\n=== 二、批量顺序应用 ===\n";
writef($base, 'b.php', "<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n");
$r = $ef->execute([
    'path'  => 'b.php',
    'edits' => [
        ['old_string' => '$a = 1;', 'new_string' => '$a = 100;'],
        ['old_string' => '$b = 2;', 'new_string' => '$b = 200;'],
        ['old_string' => '$c = 3;', 'new_string' => '$c = 300;'],
    ],
], $ctx);
test('批量编辑成功', $r->isSuccess());
$content = readf($base, 'b.php');
test('三处都改了', strpos($content, '100') && strpos($content, '200') && strpos($content, '300'));
$m = $r->getMetadata();
test('metadata edits=3', isset($m['edits']) && $m['edits'] === 3);
test('metadata replaced=3', isset($m['replaced']) && $m['replaced'] === 3);

// 顺序依赖：第二条在第一条结果上匹配
writef($base, 'seq.txt', "AAA\n");
$r = $ef->execute([
    'path'  => 'seq.txt',
    'edits' => [
        ['old_string' => 'AAA', 'new_string' => 'BBB'],
        ['old_string' => 'BBB', 'new_string' => 'CCC'],   // 依赖上一条产出的 BBB
    ],
], $ctx);
test('顺序依赖编辑成功', $r->isSuccess() && readf($base, 'seq.txt') === "CCC\n");

// ===== 三、原子性：中途失败不落盘 =====
echo "\n=== 三、原子性 ===\n";
writef($base, 'atom.txt', "one two three\n");
$before = readf($base, 'atom.txt');
$r = $ef->execute([
    'path'  => 'atom.txt',
    'edits' => [
        ['old_string' => 'one', 'new_string' => 'ONE'],       // 能成功
        ['old_string' => '不存在的串', 'new_string' => 'x'],  // 失败
        ['old_string' => 'three', 'new_string' => 'THREE'],
    ],
], $ctx);
test('批量中途失败返回错误', !$r->isSuccess());
test('失败报第 1 条(edits[1])', strpos($r->getError(), 'edits[1]') !== false);
test('原子性：文件未被修改', readf($base, 'atom.txt') === $before);

// ===== 四、replace_all 与唯一性 =====
echo "\n=== 四、replace_all / 唯一性 ===\n";
writef($base, 'dup.txt', "x x x\n");
// 不加 replace_all，多匹配报错
$r = $ef->execute(['path' => 'dup.txt', 'old_string' => 'x', 'new_string' => 'y'], $ctx);
test('多匹配未加 replace_all 报错', !$r->isSuccess() && strpos($r->getError(), '不唯一') !== false);
test('报错后文件未改', readf($base, 'dup.txt') === "x x x\n");
// 加 replace_all
$r = $ef->execute(['path' => 'dup.txt', 'old_string' => 'x', 'new_string' => 'y', 'replace_all' => true], $ctx);
test('replace_all 全替换', $r->isSuccess() && readf($base, 'dup.txt') === "y y y\n");
// 批量里单条 replace_all
writef($base, 'dup2.txt', "a a\nb\n");
$r = $ef->execute([
    'path'  => 'dup2.txt',
    'edits' => [
        ['old_string' => 'a', 'new_string' => 'A', 'replace_all' => true],
        ['old_string' => 'b', 'new_string' => 'B'],
    ],
], $ctx);
test('批量含 replace_all 成功', $r->isSuccess() && readf($base, 'dup2.txt') === "A A\nB\n");

// ===== 五、无 .tmp 残留（atomicWrite）=====
echo "\n=== 五、原子写无残留 ===\n";
test('无 .tmp 残留', count((array) glob($base . '/*.tmp.*')) === 0);

echo "\n" . str_repeat('=', 50) . "\n";
rrmdir($base);
if ($failed === 0) { echo "全部通过：{$passed} 通过，0 失败\n"; exit(0); }
echo "有失败：{$passed} 通过，{$failed} 失败\n";
exit(1);
