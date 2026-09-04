<?php
/**
 * NotebookEditTool 测试（dev.md 第二梯队 4）
 *
 * 运行：php tests/agent_notebook_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Tools\NotebookEditTool;
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

$base = sys_get_temp_dir() . '/php-ai-nb_' . getmypid();
rrmdir($base); @mkdir($base, 0777, true);

// 造一个带 base64 输出噪声的 notebook
$nb = [
    'nbformat' => 4,
    'nbformat_minor' => 5,
    'metadata' => ['kernelspec' => ['name' => 'python3']],
    'cells' => [
        ['cell_type' => 'markdown', 'metadata' => new stdClass(), 'source' => ["# 标题\n", "说明"]],
        ['cell_type' => 'code', 'metadata' => new stdClass(), 'execution_count' => 3,
         'source' => ["print('hello')\n"],
         'outputs' => [['output_type' => 'display_data', 'data' => ['image/png' => str_repeat('QUJD', 5000)]]]],
    ],
];
$file = $base . '/demo.ipynb';
file_put_contents($file, json_encode($nb, JSON_UNESCAPED_UNICODE));

$tool = new NotebookEditTool(new PathSafety($base));
$ctx = new ToolContext(['workdir' => $base]);
$load = function () use ($file) { return json_decode(file_get_contents($file), true); };

// ===== 一、read：给源码、不给 base64 =====
echo "=== 一、read ===\n";
$r = $tool->execute(['path' => 'demo.ipynb'], $ctx);
test('read 成功', $r->isSuccess());
$c = $r->getContent();
test('含 markdown 源码', strpos($c, '# 标题') !== false);
test('含 code 源码', strpos($c, "print('hello')") !== false);
test('不回传 base64 噪声', strpos($c, 'QUJDQUJD') === false);
test('输出只给摘要', strpos($c, '个输出，已省略') !== false);
test('metadata 报 cell 数', $r->getMetadata()['cells'] === 2);

// ===== 二、replace =====
echo "\n=== 二、replace ===\n";
$r = $tool->execute(['path' => 'demo.ipynb', 'action' => 'replace', 'cell_index' => 1,
                     'source' => "print('world')\nx = 1"], $ctx);
test('replace 成功', $r->isSuccess());
$d = $load();
test('源码已更新', strpos(implode('', $d['cells'][1]['source']), "print('world')") !== false);
test('旧输出被清空（源码变了输出即失效）', $d['cells'][1]['outputs'] === []);
test('execution_count 重置', $d['cells'][1]['execution_count'] === null);
test('多行存成行数组', is_array($d['cells'][1]['source']) && count($d['cells'][1]['source']) === 2);
test('顶层 nbformat 保留', isset($d['nbformat']) && $d['nbformat'] === 4);
test('kernelspec 保留', isset($d['metadata']['kernelspec']['name']));

// ===== 三、insert =====
echo "\n=== 三、insert ===\n";
$r = $tool->execute(['path' => 'demo.ipynb', 'action' => 'insert', 'cell_index' => 0,
                     'cell_type' => 'code', 'source' => 'import os'], $ctx);
test('insert 成功', $r->isSuccess());
$d = $load();
test('插到了最前', strpos(implode('', $d['cells'][0]['source']), 'import os') !== false);
test('cell 数变 3', count($d['cells']) === 3);
test('新 code cell 有 outputs 字段', array_key_exists('outputs', $d['cells'][0]));

// ===== 四、delete =====
echo "\n=== 四、delete ===\n";
$r = $tool->execute(['path' => 'demo.ipynb', 'action' => 'delete', 'cell_index' => 0], $ctx);
test('delete 成功', $r->isSuccess());
$d = $load();
test('cell 数回到 2', count($d['cells']) === 2);
test('删对了（首个又是 markdown）', $d['cells'][0]['cell_type'] === 'markdown');

// ===== 五、错误处理 =====
echo "\n=== 五、错误处理 ===\n";
test('越界 replace 报错', !$tool->execute(['path' => 'demo.ipynb', 'action' => 'replace', 'cell_index' => 99, 'source' => 'x'], $ctx)->isSuccess());
test('replace 缺 source 报错', !$tool->execute(['path' => 'demo.ipynb', 'action' => 'replace', 'cell_index' => 0], $ctx)->isSuccess());
test('insert 缺 cell_type 报错', !$tool->execute(['path' => 'demo.ipynb', 'action' => 'insert', 'cell_index' => 0, 'source' => 'x'], $ctx)->isSuccess());
test('未知 action 报错', !$tool->execute(['path' => 'demo.ipynb', 'action' => 'bogus'], $ctx)->isSuccess());
file_put_contents($base . '/bad.ipynb', '{"not":"a notebook"}');
test('非法 ipynb 报错', !$tool->execute(['path' => 'bad.ipynb'], $ctx)->isSuccess());
test('不存在文件报错', !$tool->execute(['path' => 'nope.ipynb'], $ctx)->isSuccess());

// ===== 六、写回后仍是合法 JSON =====
echo "\n=== 六、写回完整性 ===\n";
test('写回后仍可解析', is_array($load()));
test('无 .tmp 残留', count((array) glob($base . '/*.tmp.*')) === 0);

rrmdir($base);
echo "\n" . str_repeat('=', 50) . "\n";
if ($failed === 0) { echo "全部通过：{$passed} 通过，0 失败\n"; exit(0); }
echo "有失败：{$passed} 通过，{$failed} 失败\n";
exit(1);
