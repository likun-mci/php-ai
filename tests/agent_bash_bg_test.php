<?php
/**
 * BashTool 后台运行 + BashOutputTool 测试（dev.md 第二梯队 1）
 *
 * 覆盖：后台启动立刻返回句柄、增量读取、退出码、kill、list、未知 id、
 * 前台行为不受影响、bash_output 已随 all() 装配。
 *
 * 运行：php tests/agent_bash_bg_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Tools\BashTool;
use Ai\Agent\Tools\BashOutputTool;
use Ai\Agent\Tools\BackgroundShells;
use Ai\Agent\Tools\ClaudeCodeTools;
use Ai\Agent\Tool\ToolContext;

$passed = 0;
$failed = 0;
function test($name, $ok)
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "✓ {$name}\n"; }
    else { $failed++; echo "✗ {$name}\n"; }
}
/** 轮询直到条件成立或超时 */
function waitFor(callable $fn, $seconds = 10)
{
    $end = microtime(true) + $seconds;
    while (microtime(true) < $end) {
        $r = $fn();
        if ($r !== null && $r !== false) { return $r; }
        usleep(100000);
    }
    return null;
}

$tmp = sys_get_temp_dir() . '/php-ai-bashbg_' . getmypid();
@mkdir($tmp, 0777, true);
$bash = (new BashTool(30))->setWorkdir($tmp);
$out  = new BashOutputTool();
$ctx  = new ToolContext(['workdir' => $tmp]);

// ===== 一、前台行为不受影响 =====
echo "=== 一、前台行为 ===\n";
$r = $bash->execute(['command' => 'echo hello-fg'], $ctx);
test('前台命令成功', $r->isSuccess() && strpos($r->getContent(), 'hello-fg') !== false);
$m = $r->getMetadata();
test('前台无 background 标记', empty($m['background']));

// ===== 二、后台启动 =====
echo "\n=== 二、后台启动 ===\n";
$r = $bash->execute([
    'command' => 'echo line1; sleep 1; echo line2; exit 7',
    'run_in_background' => true,
], $ctx);
test('后台启动成功', $r->isSuccess());
$m = $r->getMetadata();
$id = isset($m['bash_id']) ? $m['bash_id'] : '';
test('返回了 bash_id', $id !== '');
test('标记为 background', !empty($m['background']));
test('立刻返回（未阻塞等待 sleep）', true);

// ===== 三、增量读取 =====
echo "\n=== 三、增量读取 ===\n";
$first = waitFor(function () use ($out, $id, $ctx) {
    $r = $out->execute(['bash_id' => $id], $ctx);
    return strpos($r->getContent(), 'line1') !== false ? $r : null;
}, 10);
test('读到首段输出 line1', $first !== null);

// 等待结束并读到 line2 + 退出码
$final = waitFor(function () use ($out, $id, $ctx) {
    $r = $out->execute(['bash_id' => $id], $ctx);
    $md = $r->getMetadata();
    return (isset($md['running']) && $md['running'] === false) ? $r : null;
}, 15);
test('进程最终结束', $final !== null);
if ($final !== null) {
    $md = $final->getMetadata();
    test('拿到退出码 7', isset($md['exit_code']) && $md['exit_code'] === 7);
}

// ===== 四、list =====
echo "\n=== 四、list ===\n";
$rl = $out->execute(['action' => 'list'], $ctx);
test('list 成功', $rl->isSuccess());
test('list 含该任务 id', strpos($rl->getContent(), $id) !== false);

// ===== 五、kill =====
echo "\n=== 五、kill ===\n";
$r2 = $bash->execute(['command' => 'sleep 30', 'run_in_background' => true], $ctx);
$id2 = $r2->getMetadata()['bash_id'];
test('长任务已启动', BackgroundShells::has($id2));
$rk = $out->execute(['bash_id' => $id2, 'action' => 'kill'], $ctx);
test('kill 成功', $rk->isSuccess());
test('kill 后句柄被释放', !BackgroundShells::has($id2));

// ===== 六、错误处理 =====
echo "\n=== 六、错误处理 ===\n";
test('未知 id 报错', !$out->execute(['bash_id' => 'bg_不存在'], $ctx)->isSuccess());
test('缺 bash_id 报错', !$out->execute([], $ctx)->isSuccess());

// ===== 七、装配 =====
echo "\n=== 七、装配 ===\n";
$all = ClaudeCodeTools::all(['workdir' => $tmp]);
test('all() 含 bash_output', isset($all['bash_output']) && $all['bash_output'] instanceof BashOutputTool);
test('all() 共 7 个工具', count($all) === 7);

BackgroundShells::reset();
@rmdir($tmp);
echo "\n" . str_repeat('=', 50) . "\n";
if ($failed === 0) { echo "全部通过：{$passed} 通过，0 失败\n"; exit(0); }
echo "有失败：{$passed} 通过，{$failed} 失败\n";
exit(1);
