<?php
/**
 * Worktree Isolation 测试
 *
 * 覆盖：
 *   1. spawn_agent schema 含 isolation 参数
 *   2. 非 git 目录返回错误
 *   3. worktree 创建、执行、清理
 *   4. diff 捕获
 *
 * 运行：php tests/agent_worktree_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\AgentRuntime;
use Ai\Agent\SubAgent\SubAgentManager;
use Ai\Agent\Tools\ClaudeCodeTools;

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

// ===== 1. schema 含 isolation 参数 =====

echo "=== 一、spawn_agent schema 含 isolation ===\n";

$ai = new AI();
$ai->setConfig(['model' => 'deepseek-anthropic', 'api_key' => 'sk-test', 'max_tokens' => 1024]);

$sam = new SubAgentManager($ai);
$sam->register('test-agent', ['description' => '测试']);

$schema = $sam->getToolSchema();
test('schema 含 isolation 属性', isset($schema['input_schema']['properties']['isolation']));
$isolation = $schema['input_schema']['properties']['isolation'];
assert_eq('isolation 类型', 'string', $isolation['type']);
test('isolation 枚举含 worktree', in_array('worktree', $isolation['enum'], true));

// ===== 2. 非 git 目录返回错误 =====

echo "\n=== 二、非 git 目录返回错误 ===\n";

$sam2 = new SubAgentManager($ai);
$sam2->register('test-agent', ['description' => '测试', 'prompt' => '你是测试助手']);
$sam2->setWorkdir('/tmp');

// 通过 handler 模拟调用
$handler = $sam2->getHandler();
$result = $handler(['agent' => 'test-agent', 'task' => '执行测试', 'isolation' => 'worktree']);
test('非 git 目录返回错误', strpos($result, 'ERROR') !== false || strpos($result, 'failed') !== false);

// ===== 3. 当前 git 目录创建 worktree =====

echo "\n=== 三、当前 git 目录创建 worktree ===\n";

$cwd = getcwd();
$sam3 = new SubAgentManager($ai);
$sam3->register('test-agent', ['description' => '测试', 'prompt' => '你是测试助手', 'max_iter' => 3]);
$sam3->setWorkdir($cwd);

// 直接调用 runSyncWithWorktree
$reflection = new ReflectionMethod($sam3, 'runSyncWithWorktree');
$reflection->setAccessible(true);
$runId = $reflection->invoke($sam3, 'test-agent', '说 hello');

$transcript = $sam3->getTranscript($runId);
test('worktree 执行返回记录', $transcript !== null);
test('worktree 执行有记录', $transcript['status'] === 'completed' || $transcript['status'] === 'stopped');
test('worktree 执行有 diff 字段', array_key_exists('diff', $transcript));
test('worktree 执行有 diff_stat 字段', array_key_exists('diff_stat', $transcript));
test('worktree 执行有 duration_ms', $transcript['duration_ms'] > 0);

// 确认 worktree 已被清理（检查 .claude/worktrees 下没有残留）
$worktreeDir = $cwd . '/.claude/worktrees';
if (is_dir($worktreeDir)) {
    $entries = scandir($worktreeDir);
    $count = 0;
    if ($entries) {
        $count = count(array_filter($entries, function ($e) { return $e !== '.' && $e !== '..'; }));
    }
    test('worktree 目录已清理', $count === 0);
}

// ===== 4. 未知 agent 返回错误 =====

echo "\n=== 四、未知 agent 返回错误 ===\n";

$sam4 = new SubAgentManager($ai);
$sam4->setWorkdir($cwd);
$reflection2 = new ReflectionMethod($sam4, 'runSyncWithWorktree');
$reflection2->setAccessible(true);
$runId = $reflection2->invoke($sam4, 'nonexistent', '任务');
test('未知 agent 返回错误', $runId !== null);
$transcript = $sam4->getTranscript($runId);
test('未知 agent 状态为 failed', $transcript !== null && $transcript['status'] === 'failed');

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);