<?php
/**
 * Agent 事件系统升级测试
 *
 * 覆盖：
 *   1. emit 事件包含新字段（sequence, task_id, parent_task_id, tool_call_id, message_id）
 *   2. sequence 自增
 *   3. taskId 注入到 context
 *   4. tool_call_id 在 emit 中传递
 *   5. 集成 Agent 循环
 *
 * 运行：php tests/agent_event_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\AgentContext;
use Ai\Agent\Task\TaskManager;
use Ai\Agent\Tool\ToolRegistry;
use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;

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

// ===== 1. AgentContext 事件新字段 =====

echo "=== 一、AgentContext 事件新字段 ===\n";

$ai = new AI();
$ai->setConfig([
    'model'      => 'deepseek-anthropic',
    'api_key'    => 'sk-test',
    'max_tokens' => 1024,
]);

$events = [];
$context = new AgentContext($ai, new ToolRegistry(), function ($ev) use (&$events) {
    $events[] = $ev;
});

// 设置新字段
$context->setTaskId('task_abc');
$context->setParentTaskId('task_parent');
$context->setToolCallId('call_123');
$context->setMessageId('msg_456');

// 发射事件
$context->emit('test_event', ['custom' => 'data']);

test('事件包含 sequence', isset($events[0]['sequence']));
test('sequence 为正整数', $events[0]['sequence'] > 0);
test('事件包含 task_id', isset($events[0]['task_id']));
assert_eq('task_id 正确', 'task_abc', $events[0]['task_id']);
test('事件包含 parent_task_id', isset($events[0]['parent_task_id']));
assert_eq('parent_task_id 正确', 'task_parent', $events[0]['parent_task_id']);
test('事件包含 tool_call_id', isset($events[0]['tool_call_id']));
assert_eq('tool_call_id 正确', 'call_123', $events[0]['tool_call_id']);
test('事件包含 message_id', isset($events[0]['message_id']));
assert_eq('message_id 正确', 'msg_456', $events[0]['message_id']);
test('事件包含 id', isset($events[0]['id']));
test('事件包含 session_id', isset($events[0]['session_id']));
test('事件包含 agent_id', isset($events[0]['agent_id']));
test('事件包含 turn_id', isset($events[0]['turn_id']));
test('事件包含 timestamp', isset($events[0]['timestamp']));
test('事件包含自定义字段', isset($events[0]['custom']));
assert_eq('自定义字段值', 'data', $events[0]['custom']);

// ===== 2. sequence 自增 =====

echo "\n=== 二、sequence 自增 ===\n";

$events2 = [];
$context2 = new AgentContext($ai, new ToolRegistry(), function ($ev) use (&$events2) {
    $events2[] = $ev;
});

$context2->emit('a', []);
$context2->emit('b', []);
$context2->emit('c', []);

test('事件 1 sequence 为 1', $events2[0]['sequence'] === 1);
test('事件 2 sequence 为 2', $events2[1]['sequence'] === 2);
test('事件 3 sequence 为 3', $events2[2]['sequence'] === 3);

// ===== 3. 无 emit 时不报错 =====

echo "\n=== 三、无 emit 时不报错 ===\n";

$context3 = new AgentContext($ai, new ToolRegistry());
$context3->setTaskId('task_xyz');
$context3->setToolCallId('call_xyz');
$context3->emit('test', []);
test('无 emit 回调时不报错', true);

// ===== 4. getter =====

echo "\n=== 四、getter 方法 ===\n";

$context4 = new AgentContext($ai, new ToolRegistry());
$context4->setTaskId('t1');
$context4->setParentTaskId('tp');
$context4->setToolCallId('c1');
$context4->setMessageId('m1');

assert_eq('getTaskId', 't1', $context4->getTaskId());
assert_eq('getParentTaskId', 'tp', $context4->getParentTaskId());
assert_eq('getToolCallId', 'c1', $context4->getToolCallId());
assert_eq('getMessageId', 'm1', $context4->getMessageId());

$context4->setTaskId(null);
test('setTaskId null 后 getTaskId 为 null', $context4->getTaskId() === null);

$context4->setParentTaskId(null);
test('setParentTaskId null 后为 null', $context4->getParentTaskId() === null);

// ===== 5. AgentRuntime 集成（taskId 注入） =====

echo "\n=== 五、AgentRuntime 集成 ===\n";

$ai2 = new AI();
$ai2->setConfig([
    'model'      => 'deepseek-anthropic',
    'api_key'    => 'sk-test',
    'max_tokens' => 1024,
]);

$taskEvents = [];
$agent = new Agent($ai2);
$agent->onEvent(function ($ev) use (&$taskEvents) {
    $taskEvents[] = $ev;
});

$tm = new TaskManager();
$task = $tm->create('测试事件任务', 'sess_1');
$agent->setTaskManager($tm);
$agent->setTaskId($task->getId());

$messages = [['role' => 'user', 'content' => '说 hello']];
$agent->run($messages);

$taskEventsWithTaskId = array_filter($taskEvents, function ($ev) {
    return isset($ev['task_id']);
});
test('Agent 事件包含 task_id', count($taskEventsWithTaskId) > 0);

// 检查 task_start 事件
$taskStartEvents = array_filter($taskEvents, function ($ev) {
    return $ev['type'] === 'task_start';
});
$taskStart = reset($taskStartEvents);
test('task_start 事件包含 task_id', $taskStart !== false && isset($taskStart['task_id']));
test('task_start 事件 task_id 非空', $taskStart !== false && $taskStart['task_id'] !== '');

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);