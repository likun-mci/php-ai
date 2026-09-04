<?php
/**
 * GrepTool v2.1 增强测试：ripgrep 探测 + 纯 PHP 回退，两路对拍
 *
 * 覆盖 dev.md v2.1 §1.1：ignore_case / 上下文行 / output_mode / include，
 * rg 与 php 两路对同一查询结果一致。
 *
 * 运行：php tests/agent_grep2_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Tools\GrepTool;
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

$base = sys_get_temp_dir() . '/php-ai-grep2_' . getmypid();
rrmdir($base);
@mkdir($base . '/sub', 0777, true);
file_put_contents($base . '/a.php', "<?php\nfunction login() {}\n// LOGIN helper\nclass User {}\n");
file_put_contents($base . '/b.php', "<?php\n\$x = 1;\nfunction Login2() {}\n");
file_put_contents($base . '/notes.txt', "login here\nnothing\n");
file_put_contents($base . '/sub/c.php', "<?php\n// todo: login flow\n");

$ps = new PathSafety($base);
$ctx = new ToolContext(['workdir' => $base]);

// 收集某 engine 下的匹配文件集合（用于对拍）
function grepFiles($ps, $ctx, $engine, array $input)
{
    $g = (new GrepTool($ps, 100))->setEngine($engine);
    $r = $g->execute(array_merge($input, ['output_mode' => 'files_with_matches']), $ctx);
    $files = [];
    foreach (explode("\n", $r->getContent()) as $ln) {
        $ln = trim($ln);
        if ($ln !== '' && substr($ln, -4) === '.php' || (strpos($ln, '.txt') !== false)) {
            // 只取看起来像路径的行
        }
    }
    // 更稳妥：从 metadata + 内容行解析
    return $r;
}

$hasRg = Shell::hasBinary('rg');
echo $hasRg ? "环境有 rg：将对拍 rg 与 php 两路\n\n" : "环境无 rg：只测 php 路\n\n";

$engines = $hasRg ? ['php', 'rg'] : ['php'];

// ===== 一、字面匹配（默认 content 模式）=====
echo "=== 一、字面匹配 ===\n";
foreach ($engines as $eng) {
    $g = (new GrepTool($ps, 100))->setEngine($eng);
    $r = $g->execute(['pattern' => 'login'], $ctx);   // 大小写敏感：只匹配小写 login
    $m = $r->getMetadata();
    // a.php:2 login()，notes.txt:1 login here，sub/c.php:2 todo: login flow = 3 行
    test("[$eng] 字面 login 命中 3 行", isset($m['matches']) && $m['matches'] === 3);
    test("[$eng] 大小写敏感(不匹配 LOGIN/Login2)", strpos($r->getContent(), 'LOGIN helper') === false);
}

// ===== 二、ignore_case =====
echo "\n=== 二、ignore_case ===\n";
foreach ($engines as $eng) {
    $g = (new GrepTool($ps, 100))->setEngine($eng);
    $r = $g->execute(['pattern' => 'login', 'ignore_case' => true], $ctx);
    $m = $r->getMetadata();
    // 现在应匹配 login/LOGIN/Login2 → a.php(2行:login()+LOGIN) b.php(1) notes(1) c.php(1) = 5
    test("[$eng] ignore_case 命中 5 行", isset($m['matches']) && $m['matches'] === 5);
}

// ===== 三、正则 =====
echo "\n=== 三、正则 ===\n";
foreach ($engines as $eng) {
    $g = (new GrepTool($ps, 100))->setEngine($eng);
    $r = $g->execute(['pattern' => '/function \w+/'], $ctx);
    $m = $r->getMetadata();
    // a.php:2 function login，b.php:3 function Login2 = 2
    test("[$eng] 正则 function \\w+ 命中 2 行", isset($m['matches']) && $m['matches'] === 2);
}

// ===== 四、include 过滤 =====
echo "\n=== 四、include 过滤 ===\n";
foreach ($engines as $eng) {
    $g = (new GrepTool($ps, 100))->setEngine($eng);
    $r = $g->execute(['pattern' => 'login', 'include' => '*.txt'], $ctx);
    $m = $r->getMetadata();
    test("[$eng] include *.txt 只命中 notes.txt 1 行", isset($m['matches']) && $m['matches'] === 1);
}

// ===== 五、output_mode: files_with_matches =====
echo "\n=== 五、files_with_matches ===\n";
foreach ($engines as $eng) {
    $g = (new GrepTool($ps, 100))->setEngine($eng);
    $r = $g->execute(['pattern' => 'login', 'output_mode' => 'files_with_matches'], $ctx);
    $m = $r->getMetadata();
    // a.php, notes.txt, sub/c.php = 3 文件
    test("[$eng] files_with_matches = 3 文件", isset($m['files']) && $m['files'] === 3);
    test("[$eng] 内容含文件名不含行内容", strpos($r->getContent(), 'a.php') !== false && strpos($r->getContent(), 'login()') === false);
}

// ===== 六、output_mode: count =====
echo "\n=== 六、count ===\n";
foreach ($engines as $eng) {
    $g = (new GrepTool($ps, 100))->setEngine($eng);
    $r = $g->execute(['pattern' => 'login', 'output_mode' => 'count'], $ctx);
    $m = $r->getMetadata();
    test("[$eng] count 总匹配 3", isset($m['matches']) && $m['matches'] === 3);
}

// ===== 七、上下文行 =====
echo "\n=== 七、上下文行 context ===\n";
foreach ($engines as $eng) {
    $g = (new GrepTool($ps, 100))->setEngine($eng);
    $r = $g->execute(['pattern' => 'LOGIN helper', 'context' => 1], $ctx);
    $content = $r->getContent();
    // LOGIN helper 在 a.php:3，前后各一行：a.php:2 login()、a.php:4 class User
    test("[$eng] context=1 带出前一行 login()", strpos($content, 'login()') !== false);
    test("[$eng] context=1 带出后一行 class User", strpos($content, 'class User') !== false);
}

// ===== 八、auto 引擎选 rg（若有）=====
echo "\n=== 八、auto 引擎 ===\n";
$g = (new GrepTool($ps, 100))->setEngine('auto');
$r = $g->execute(['pattern' => 'login'], $ctx);
$m = $r->getMetadata();
test('auto 引擎可用', $r->isSuccess() && isset($m['engine']));
test('auto 引擎选择正确', $m['engine'] === ($hasRg ? 'rg' : 'php'));

// ===== 九、rg 与 php 结果一致（对拍）=====
if ($hasRg) {
    echo "\n=== 九、rg vs php 对拍 ===\n";
    $cases = [
        ['pattern' => 'login'],
        ['pattern' => 'login', 'ignore_case' => true],
        ['pattern' => '/function \w+/'],
        ['pattern' => 'login', 'output_mode' => 'count'],
    ];
    foreach ($cases as $i => $case) {
        $rp = (new GrepTool($ps, 100))->setEngine('php')->execute($case, $ctx)->getMetadata();
        $rr = (new GrepTool($ps, 100))->setEngine('rg')->execute($case, $ctx)->getMetadata();
        $pm = isset($rp['matches']) ? $rp['matches'] : -1;
        $rm = isset($rr['matches']) ? $rr['matches'] : -1;
        test("对拍 #{$i} matches 一致 (php={$pm} rg={$rm})", $pm === $rm);
    }
}

// ===== 十、rg 输出解析（喂标准 ripgrep 输出，覆盖 rg 分支，不依赖真 rg 二进制）=====
echo "\n=== 十、rg 输出解析 ===\n";
$g = new GrepTool($ps, 100);
$ref = new \ReflectionMethod(GrepTool::class, 'parseRgOutput');
$ref->setAccessible(true);
$root = rtrim($base, '/') . '/';

// content 模式：file:line:text（匹配）与 file-line-text（上下文）
$rgContent = $root . "a.php:2:function login() {}\n"
           . $root . "a.php-3-// LOGIN helper\n"
           . $root . "notes.txt:1:login here\n";
$hits = $ref->invoke($g, $rgContent, 'content', 100, $root);
$matchN = 0; $ctxN = 0;
foreach ($hits as $h) { $h['ctx'] ? $ctxN++ : $matchN++; }
test('rg content 解析出 2 匹配 + 1 上下文', $matchN === 2 && $ctxN === 1);
test('rg content 路径转相对', $hits[0]['file'] === 'a.php' && strpos($hits[0]['file'], $root) === false);

// files_with_matches
$rgFiles = $root . "a.php\n" . $root . "notes.txt\n";
$hitsF = $ref->invoke($g, $rgFiles, 'files_with_matches', 100, $root);
test('rg files 解析出 2 文件', count($hitsF) === 2 && $hitsF[1]['file'] === 'notes.txt');

// count
$rgCount = $root . "a.php:2\n" . $root . "notes.txt:1\n";
$hitsC = $ref->invoke($g, $rgCount, 'count', 100, $root);
test('rg count 解析每文件计数', count($hitsC) === 2 && $hitsC[0]['text'] === '2' && $hitsC[1]['text'] === '1');

echo "\n" . str_repeat('=', 50) . "\n";
rrmdir($base);
if ($failed === 0) { echo "全部通过：{$passed} 通过，0 失败\n"; exit(0); }
echo "有失败：{$passed} 通过，{$failed} 失败\n";
exit(1);
