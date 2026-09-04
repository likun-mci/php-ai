<?php
/**
 * GlobTool v2.1：可靠递归 + 尊重 .gitignore 测试
 *
 * 覆盖 dev.md v2.1 §1.4：** 递归、* 不跨目录、? 单字符、基础排除、
 * git 可用时尊重 .gitignore。
 *
 * 运行：php tests/agent_glob2_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Tools\GlobTool;
use Ai\Agent\Tools\PathSafety;
use Ai\Agent\Tool\ToolContext;
use Ai\Helpers\Shell;

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
function globList($tool, $ctx, $pattern)
{
    $r = $tool->execute(['pattern' => $pattern], $ctx);
    $files = [];
    $content = $r->getContent();
    if (strpos($content, 'Found') === 0) {   // 只有命中才逐行解析；未找到直接空
        foreach (explode("\n", $content) as $ln) {
            $ln = trim($ln);
            if ($ln !== '' && strpos($ln, 'Found') !== 0 && strpos($ln, '…') !== 0) {
                $files[] = $ln;
            }
        }
    }
    return [$r, $files];
}

$base = sys_get_temp_dir() . '/php-ai-glob2_' . getmypid();
rrmdir($base);

// ===== 非 git 目录：走递归遍历回退路 =====
$plain = $base . '/plain';
@mkdir($plain . '/src/deep', 0777, true);
@mkdir($plain . '/vendor/pkg', 0777, true);
@mkdir($plain . '/.hidden', 0777, true);
file_put_contents($plain . '/a.txt', 'x');
file_put_contents($plain . '/b.php', 'x');
file_put_contents($plain . '/src/c.php', 'x');
file_put_contents($plain . '/src/deep/d.php', 'x');
file_put_contents($plain . '/src/note.txt', 'x');
file_put_contents($plain . '/vendor/pkg/e.php', 'x');
file_put_contents($plain . '/.hidden/f.php', 'x');

$g = new GlobTool(new PathSafety($plain), 100);
$ctx = new ToolContext(['workdir' => $plain]);

echo "=== 一、非 git：通配符语义 ===\n";
list($r, $txt) = globList($g, $ctx, '*.txt');
test('*.txt 只匹配根级 a.txt', in_array('a.txt', $txt, true) && !in_array('src/note.txt', $txt, true));

list($r, $php) = globList($g, $ctx, '**/*.php');
test('**/*.php 匹配根级 b.php', in_array('b.php', $php, true));
test('**/*.php 匹配嵌套 src/c.php', in_array('src/c.php', $php, true));
test('**/*.php 匹配深层 src/deep/d.php', in_array('src/deep/d.php', $php, true));

list($r, $srcphp) = globList($g, $ctx, 'src/**/*.php');
test('src/**/*.php 匹配 src/c.php', in_array('src/c.php', $srcphp, true));
test('src/**/*.php 匹配 src/deep/d.php', in_array('src/deep/d.php', $srcphp, true));
test('src/**/*.php 不匹配根级 b.php', !in_array('b.php', $srcphp, true));

echo "\n=== 二、非 git：基础排除 ===\n";
list($r, $all) = globList($g, $ctx, '**/*.php');
test('排除 vendor/', !in_array('vendor/pkg/e.php', $all, true));
test('排除隐藏目录 .hidden/', !in_array('.hidden/f.php', $all, true));

echo "\n=== 三、单字符 ? ===\n";
list($r, $q) = globList($g, $ctx, '?.php');
test('?.php 匹配 b.php', in_array('b.php', $q, true));
test('?.php 不匹配 src/c.php', !in_array('src/c.php', $q, true));

// ===== git 目录：尊重 .gitignore =====
echo "\n=== 四、git 尊重 .gitignore ===\n";
if (Shell::hasBinary('git')) {
    $repo = $base . '/repo';
    @mkdir($repo . '/build', 0777, true);
    file_put_contents($repo . '/.gitignore', "build/\n*.log\n");
    file_put_contents($repo . '/keep.php', 'x');
    file_put_contents($repo . '/debug.log', 'x');       // 被 *.log 忽略
    file_put_contents($repo . '/build/out.php', 'x');    // 被 build/ 忽略
    // 初始化 git 仓库（--others --exclude-standard 无需 commit）
    Shell::capture('git -C ' . escapeshellarg($repo) . ' init -q', ['timeout' => 15]);
    Shell::capture('git -C ' . escapeshellarg($repo) . ' config user.email t@t.co', ['timeout' => 10]);
    Shell::capture('git -C ' . escapeshellarg($repo) . ' config user.name t', ['timeout' => 10]);

    $gg = new GlobTool(new PathSafety($repo), 100);
    $gctx = new ToolContext(['workdir' => $repo]);
    list($r, $files) = globList($gg, $gctx, '**/*.php');
    test('保留未忽略的 keep.php', in_array('keep.php', $files, true));
    test('.gitignore 排除 build/out.php', !in_array('build/out.php', $files, true));
    list($r, $logs) = globList($gg, $gctx, '*.log');
    test('.gitignore 排除 *.log', !in_array('debug.log', $logs, true) && count($logs) === 0);
} else {
    echo "  (无 git，跳过 gitignore 用例)\n";
}

// ===== 五、兼容：*.txt 基本用法（回归 agent_advanced 场景）=====
echo "\n=== 五、基本兼容 ===\n";
$r = $g->execute(['pattern' => '*.txt'], $ctx);
test('*.txt 成功且含 a.txt', $r->isSuccess() && strpos($r->getContent(), 'a.txt') !== false);
$m = $r->getMetadata();
test('metadata 有 count', isset($m['count']) && $m['count'] >= 1);

echo "\n" . str_repeat('=', 50) . "\n";
rrmdir($base);
if ($failed === 0) { echo "全部通过：{$passed} 通过，0 失败\n"; exit(0); }
echo "有失败：{$passed} 通过，{$failed} 失败\n";
exit(1);
