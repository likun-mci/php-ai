<?php
/**
 * CheckpointManager 测试
 *
 * 覆盖：
 *   1. Checkpoint 值对象
 *   2. 保存/加载检查点
 *   3. loadLatest 加载最新
 *   4. 多轮次自动清理
 *   5. 启用/停用
 *   6. 集成到 AgentRuntime / Agent
 *
 * 运行：php tests/agent_checkpoint_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\Checkpoint\Checkpoint;
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

$cpDir = sys_get_temp_dir() . '/cp_test_' . uniqid();

// ===== 1. Checkpoint 值对象 =====

echo "=== 一、Checkpoint 值对象 ===\n";

$cp = new Checkpoint('task_1', [
    'iteration' => 5,
    'messages'  => [['role' => 'user', 'content' => 'hello']],
    'extra'     => ['budget' => ['used' => 100]],
]);

assert_eq('ID', 'task_1', $cp->getId());
assert_eq('iteration', 5, $cp->getIteration());
assert_eq('消息数量', 1, count($cp->getMessages()));
assert_eq('extra 含 budget', 100, $cp->getExtra()['budget']['used']);
test('createdAt 是浮点数', is_float($cp->getCreatedAt()));

$arr = $cp->toArray();
test('toArray 含 id', isset($arr['id']));
test('toArray 含 iteration', isset($arr['iteration']));
test('toArray 含 messages', isset($arr['messages']));
test('toArray 含 extra', isset($arr['extra']));

// ===== 2. 保存/加载检查点 =====

echo "\n=== 二、保存/加载检查点 ===\n";

$cm = new CheckpointManager($cpDir);
test('初始化后 enabled', $cm->isEnabled());

$msg1 = [['role' => 'user', 'content' => '第一轮']];
$msg2 = [['role' => 'user', 'content' => '第二轮']];
$msg3 = [['role' => 'user', 'content' => '第三轮']];

$cm->save('task_1', 1, $msg1);
$cm->save('task_1', 2, $msg2);
$cm->save('task_1', 3, $msg3);

// 加载指定轮次
$loaded = $cm->load('task_1', 2);
test('load 指定轮次成功', $loaded !== null);
assert_eq('load 轮次正确', 2, $loaded->getIteration());
assert_eq('load 消息正确', '第二轮', $loaded->getMessages()[0]['content']);

// 加载最新
$latest = $cm->loadLatest('task_1');
test('loadLatest 返回最新', $latest !== null);
assert_eq('loadLatest 轮次', 3, $latest->getIteration());

// 加载不存在的轮次
$none = $cm->load('task_1', 99);
test('load 不存在返回 null', $none === null);

// 加载不存在的任务
$none2 = $cm->loadLatest('nonexistent');
test('loadLatest 不存在返回 null', $none2 === null);

// ===== 3. 多任务隔离 =====

echo "\n=== 三、多任务隔离 ===\n";

$cm->save('task_2', 1, [['role' => 'user', 'content' => '任务二']]);
$latest1 = $cm->loadLatest('task_1');
$latest2 = $cm->loadLatest('task_2');
test('task_1 最新为轮次 3', $latest1 !== null && $latest1->getIteration() === 3);
test('task_2 最新为轮次 1', $latest2 !== null && $latest2->getIteration() === 1);

// ===== 4. 自动清理 =====

echo "\n=== 四、自动清理 ===\n";

$cm2 = new CheckpointManager($cpDir, ['maxCheckpoints' => 2]);
$cm2->save('task_clean', 1, [['role' => 'user', 'content' => 'a']]);
$cm2->save('task_clean', 2, [['role' => 'user', 'content' => 'b']]);
$cm2->save('task_clean', 3, [['role' => 'user', 'content' => 'c']]);

$latest = $cm2->loadLatest('task_clean');
test('清理后最新为轮次 3', $latest !== null && $latest->getIteration() === 3);

// 轮次 1 应该已被清理
$cp1 = $cm2->load('task_clean', 1);
test('轮次 1 已被清理', $cp1 === null);

// 验证旧 checkpoint 文件被清理
$all = $cm2->load('task_clean', 2);
test('轮次 2 仍保留', $all !== null);

// ===== 5. 启用/停用 =====

echo "\n=== 五、启用/停用 ===\n";

$cm3 = new CheckpointManager($cpDir, ['enabled' => false]);
test('停用时 save 返回空', $cm3->save('task_x', 1, []) === '');

$cm4 = new CheckpointManager($cpDir, ['enabled' => true]);
$cm4->setEnabled(false);
test('setEnabled false 后 save 返回空', $cm4->save('task_y', 1, []) === '');

// 空 baseDir
$cm5 = new CheckpointManager();
test('空 baseDir 时 save 返回空', $cm5->save('task', 1, []) === '');
test('空 baseDir 时 loadLatest 返回 null', $cm5->loadLatest('task') === null);

// ===== 6. 删除 =====

echo "\n=== 六、删除 ===\n";

$cm6 = new CheckpointManager($cpDir);
$cm6->save('task_del', 1, [['role' => 'user', 'content' => 'x']]);
test('删除前存在', $cm6->loadLatest('task_del') !== null);
$cm6->delete('task_del');
test('删除后不存在', $cm6->loadLatest('task_del') === null);

// ===== 7. 集成到 AgentRuntime / Agent =====

echo "\n=== 七、集成到 AgentRuntime / Agent ===\n";

$ai = new AI();
$ai->setConfig([
    'model'      => 'deepseek-anthropic',
    'api_key'    => 'sk-test',
    'max_tokens' => 1024,
]);

$agent = new Agent($ai);
$agent->setCheckpointDir($cpDir);
$runtime = $agent->getRuntime();
test('setCheckpointDir 创建了 CheckpointManager', $runtime->getCheckpointManager() !== null);

$cm = $runtime->getCheckpointManager();
test('CheckpointManager 的 baseDir 已设置', $cm->getBaseDir() === $cpDir);

// 清理
$cm->delete('task_1');
$cm->delete('task_2');
$cm->delete('task_clean');
$cm->delete('task_del');
@rmdir($cpDir);

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);