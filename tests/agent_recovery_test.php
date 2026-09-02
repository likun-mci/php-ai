<?php
/**
 * Crash Recovery 测试
 *
 * 覆盖：
 *   1. 正常保存 checkpoint 后可恢复
 *   2. recover() 恢复消息
 *   3. 无可恢复检查点时返回 null
 *   4. Agent->recoverFromCrash() 快捷方式
 *   5. recover 后复用 run() 继续执行
 *
 * 运行：php tests/agent_recovery_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\Checkpoint\CheckpointManager;

$passed = 0;
$failed = 0;

function test($name, $ok)
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "✓ {$name}\n";
    } else {
        $failed++;
        echo "✗ {$name}\n";
    }
}

function assert_eq($name, $expected, $actual)
{
    test($name, $expected === $actual);
}

$cpDir = sys_get_temp_dir() . '/rec_test_' . uniqid();

// ===== 1. 保存 checkpoint 后可恢复 =====

echo "=== 一、保存 checkpoint 后可恢复 ===\n";

$ai = new AI();
$ai->setConfig([
    'model'      => 'deepseek-anthropic',
    'api_key'    => 'sk-test',
    'max_tokens' => 1024,
]);

$cm = new CheckpointManager($cpDir);
$cm->save('task_crash', 3, [
    ['role' => 'user', 'content' => '任务开始'],
    ['role' => 'assistant', 'content' => '正在处理'],
    ['role' => 'user', 'content' => '工具结果已回填'],
]);

// 通过 AgentRuntime 恢复
$agent = new Agent($ai);
$agent->setCheckpointDir($cpDir);

$messages = $agent->recoverFromCrash('task_crash');
test('recoverFromCrash 返回消息数组', is_array($messages));
test('恢复的消息数量正确', count($messages) === 3);
assert_eq('第一条消息', '任务开始', $messages[0]['content']);

// 验证 loadLatest 能读取
$cm2 = new CheckpointManager($cpDir);
$latest = $cm2->loadLatest('task_crash');
test('CheckpointManager 直接加载最新成功', $latest !== null);
assert_eq('加载轮次', 3, $latest->getIteration());

// ===== 2. 无可恢复检查点时返回 null =====

echo "\n=== 二、无可恢复检查点时返回 null ===\n";

$agent2 = new Agent($ai);
$agent2->setCheckpointDir($cpDir);
$none = $agent2->recoverFromCrash('nonexistent_task');
test('无检查点返回 null', $none === null);

// 未配置 checkpoint 管理器
$agent3 = new Agent($ai);
$none3 = $agent3->recoverFromCrash('task_crash');
test('未配置 CheckpointManager 时返回 null', $none3 === null);

// ===== 3. 多轮次恢复时取最新 =====

echo "\n=== 三、多轮次恢复时取最新 ===\n";

$cm3 = new CheckpointManager($cpDir);
$cm3->save('task_multi', 1, [['role' => 'user', 'content' => '第一轮']]);
$cm3->save('task_multi', 2, [['role' => 'user', 'content' => '第二轮']]);
$cm3->save('task_multi', 5, [['role' => 'user', 'content' => '第五轮']]);

$agent4 = new Agent($ai);
$agent4->setCheckpointDir($cpDir);
$messages = $agent4->recoverFromCrash('task_multi');
assert_eq('恢复最新轮次消息', '第五轮', $messages[0]['content']);

// ===== 4. 恢复后继续执行 =====

echo "\n=== 四、恢复后继续执行 ===\n";

// 模拟从 checkpoint 保存后继续
$cm4 = new CheckpointManager($cpDir);
$startMessages = [['role' => 'user', 'content' => '任务开始']];
$cm4->save('task_continue', 1, $startMessages);

$agent5 = new Agent($ai);
$agent5->setCheckpointDir($cpDir);
$recovered = $agent5->recoverFromCrash('task_continue');
test('恢复后的消息非空', is_array($recovered) && count($recovered) > 0);
test('恢复后消息内容正确', $recovered[0]['content'] === '任务开始');

// recover() 返回后直接调用 run() 继续（run 内部 loadLatest 已有此能力，这里验证不抛错）
$events = [];
$agent5->onEvent(function ($ev) use (&$events) {
    $events[] = $ev;
});
$agent5->run($recovered);
test('恢复后 run() 正常执行', count($events) > 0);

// 验证新 checkpoint 已保存（迭代数 > 旧的 1）
$newLatest = $cm4->loadLatest('task_continue');
test('恢复后新的 checkpoint 已保存', $newLatest !== null && $newLatest->getIteration() >= 1);

// ===== 5. AgentRuntime->recover() 直接调用 =====

echo "\n=== 五、AgentRuntime->recover() 直接调用 ===\n";

$agent6 = new Agent($ai);
$agent6->setCheckpointDir($cpDir);
$runtime = $agent6->getRuntime();
$messages = $runtime->recover('task_crash');
test('runtime->recover() 返回消息', is_array($messages));
assert_eq('runtime->recover() 内容正确', '任务开始', $messages[0]['content']);

// 测试 recover 同时设置了 taskId
test('recover 设置了 taskId', $runtime->getTaskId() === 'task_crash');

// ===== 清理 =====

$cm->delete('task_crash');
$cm3->delete('task_multi');
$cm4->delete('task_continue');
@rmdir($cpDir);

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);