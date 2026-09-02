<?php
/**
 * AgentQueue 测试
 *
 * 覆盖：
 *   1. dispatch 入队
 *   2. hasPending / pendingCount
 *   3. processNext 处理下一个
 *   4. process 处理指定任务
 *   5. cancel 取消
 *   6. resume 恢复
 *   7. result 获取结果
 *   8. 自动跳过已取消/已完成
 *
 * 运行：php tests/agent_queue_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\AgentRuntime;
use Ai\Agent\AgentResult;
use Ai\Agent\Queue\AgentQueue;
use Ai\Agent\Task\TaskManager;
use Ai\Agent\Task\TaskStatus;

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

// 创建 AI 实例
$ai = new AI();
$ai->setConfig([
    'model'      => 'deepseek-anthropic',
    'api_key'    => 'sk-test',
    'max_tokens' => 1024,
]);

// ===== 1. dispatch 入队 =====

echo "=== 一、dispatch 入队 ===\n";

$queue = new AgentQueue();
test('队列初始无任务', !$queue->hasPending());
assert_eq('pendingCount 初始 0', 0, $queue->pendingCount());

$runtime = new AgentRuntime($ai);
$task = $queue->dispatch('修复登录问题', $runtime, [['role' => 'user', 'content' => '说 hello']], 'sess_1');
test('dispatch 返回 AgentTask', $task !== null);
test('任务 ID 非空', $task->getId() !== '');
assert_eq('任务目标', '修复登录问题', $task->getGoal());
test('任务状态为 QUEUED', $task->isQueued());

test('队列有任务', $queue->hasPending());
assert_eq('pendingCount 为 1', 1, $queue->pendingCount());

// ===== 2. 多任务入队 =====

echo "\n=== 二、多任务入队 ===\n";

$task2 = $queue->dispatch('优化性能', $runtime, [], 'sess_1');
$task3 = $queue->dispatch('添加测试', $runtime, [], 'sess_1');
assert_eq('pendingCount 为 3', 3, $queue->pendingCount());

$next = $queue->next();
test('next 返回第一个任务', $next !== null && $next->getId() === $task->getId());

// ===== 3. processNext 处理下一个 =====

echo "\n=== 三、processNext 处理下一个 ===\n";

$result = $queue->processNext();
test('processNext 返回 AgentResult', $result instanceof AgentResult);
test('处理后有结果', $result !== null);

// 验证任务状态
$processed = $queue->get($task->getId());
test('任务已标记为 completed 或 failed', $processed !== null && ($processed->isCompleted() || $processed->isFailed()));

// 结果可用
$res = $queue->result($task->getId());
test('result 可获取', $res !== null);
assert_eq('result 与 processNext 返回一致', true, $res === $result);

assert_eq('pendingCount 减为 2', 2, $queue->pendingCount());

// ===== 4. process 处理指定任务 =====

echo "\n=== 四、process 处理指定任务 ===\n";

$result2 = $queue->process($task2->getId());
test('process 指定任务返回结果', $result2 instanceof AgentResult);

$task2Status = $queue->get($task2->getId());
test('指定任务已处理', $task2Status !== null && ($task2Status->isCompleted() || $task2Status->isFailed()));

// 处理不存在的任务
$none = $queue->process('nonexistent');
test('process 不存在返回 null', $none === null);

// ===== 5. cancel 取消 =====

echo "\n=== 五、cancel 取消 ===\n";

$queue2 = new AgentQueue();
$t = $queue2->dispatch('将被取消的任务', $runtime, [], 'sess_1');
test('取消前队列有任务', $queue2->hasPending());
$ok = $queue2->cancel($t->getId());
test('cancel 返回 true', $ok);
test('取消后任务为 CANCELLED', $queue2->get($t->getId())->isCancelled());
test('取消后 pendingCount 为 0', !$queue2->hasPending());

// 取消不存在的任务
$ok2 = $queue2->cancel('nonexistent');
test('cancel 不存在的任务返回 false', !$ok2);

// ===== 6. resume 恢复 =====

echo "\n=== 六、resume 恢复 ===\n";

$queue3 = new AgentQueue();
$t3 = $queue3->dispatch('暂停后恢复的任务', $runtime, [], 'sess_1');
$t3->setStatus(TaskStatus::PAUSED);
test('暂停后 isPaused', $t3->isPaused());

$ok3 = $queue3->resume($t3->getId());
test('resume 返回 true', $ok3);
$recovered = $queue3->get($t3->getId());
test('恢复后重新入队', $queue3->hasPending());

// 恢复不存在的任务
$ok4 = $queue3->resume('nonexistent');
test('resume 不存在返回 false', !$ok4);

// 恢复不是 paused 的任务
$t4 = $queue3->dispatch('运行中的任务', $runtime, [], 'sess_1');
$ok5 = $queue3->resume($t4->getId());
test('resume 非 paused 任务返回 false', !$ok5);

// ===== 7. 全部任务列表 =====

echo "\n=== 七、全部任务列表 ===\n";

$all = $queue->all();
test('all 返回数组', is_array($all));
assert_eq('all 数量', 3, count($all));

// ===== 8. TaskManager 自动创建 =====

echo "\n=== 八、TaskManager 自动创建 ===\n";

$queue4 = new AgentQueue();
test('自动创建 TaskManager', $queue4->getTaskManager() !== null);

$tm = new TaskManager();
$queue5 = new AgentQueue($tm);
test('传入的 TaskManager 被使用', $queue5->getTaskManager() === $tm);

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);