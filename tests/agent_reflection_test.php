<?php
/**
 * Agent Reflection System 冒烟测试
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Ai\Agent\Reflection\ReflectionManager;
use Ai\Agent\Reflection\ReflectionResult;

$pass = 0;
$fail = 0;

function assert_eq($expected, $actual, $label)
{
    global $pass, $fail;
    if ($expected === $actual) {
        $pass++;
        echo "  PASS: {$label}\n";
    } else {
        $fail++;
        echo "  FAIL: {$label}\n";
        echo "    expected: " . var_export($expected, true) . "\n";
        echo "    actual:   " . var_export($actual, true) . "\n";
    }
}

function assert_true($actual, $label)
{
    global $pass, $fail;
    if ($actual) {
        $pass++;
        echo "  PASS: {$label}\n";
    } else {
        $fail++;
        echo "  FAIL: {$label}\n";
        echo "    expected true, got: " . var_export($actual, true) . "\n";
    }
}

echo "=== Agent Reflection System Test ===\n\n";

// 1. ReflectionResult 基础
echo "--- ReflectionResult ---\n";
$result = ReflectionResult::completed('完成');
assert_true($result->isSuccess(), 'completed result');
assert_true(!$result->shouldContinue(), 'completed should not continue');
assert_eq('完成', $result->getReason(), 'completed reason');

$result2 = ReflectionResult::continuing('测试失败', '分析错误日志');
assert_true(!$result2->isSuccess(), 'continuing not success');
assert_true($result2->shouldContinue(), 'continuing should continue');
assert_eq('分析错误日志', $result2->getNextAction(), 'continuing next action');

// 2. ReflectionResult toPrompt
$prompt = $result2->toPrompt();
assert_true(strpos($prompt, '目标未完成') !== false, 'toPrompt contains unfinished');
assert_true(strpos($prompt, '分析错误日志') !== false, 'toPrompt contains next action');

// 3. ReflectionResult serialization
$data = $result2->toArray();
$restored = ReflectionResult::fromArray($data);
assert_eq($result2->isSuccess(), $restored->isSuccess(), 'result serialization');

// 4. ReflectionManager 基础
echo "\n--- ReflectionManager ---\n";
$rm = new ReflectionManager();

// 5. 反思：完成标记
$messages = [
    ['role' => 'user', 'content' => '帮我修复登录问题'],
    ['role' => 'assistant', 'content' => '已完成修复，问题已解决'],
];
$result = $rm->reflect($messages, '修复登录问题');
assert_true($result->isSuccess(), 'reflection detects completion');

// 6. 反思：工具错误
$messages2 = [
    ['role' => 'user', 'content' => '修改代码'],
    ['role' => 'assistant', 'content' => [
        ['type' => 'text', 'text' => '我来修改文件'],
        ['type' => 'tool_use', 'name' => 'edit_file'],
    ]],
    ['role' => 'tool', 'content' => 'Parse error: syntax error'],
];
$result2 = $rm->reflect($messages2, '修改代码');
assert_true($result2->shouldContinue(), 'reflection detects error');
assert_true(strpos($result2->getReason(), '出错') !== false, 'error reason mentions error');

// 7. 反思：空消息（没有完成标记，应继续）
$messages3 = [];
$result3 = $rm->reflect($messages3, '测试');
assert_true($result3->shouldContinue(), 'empty messages should continue');

// 8. 反思：禁用
$rm2 = new ReflectionManager(['enabled' => false]);
$result4 = $rm2->reflect($messages, '目标');
assert_true($result4->isSuccess(), 'disabled reflection always completes');

// 9. 反思：最大轮数
$rm3 = new ReflectionManager(['maxRounds' => 3]);
$result5 = $rm3->reflect([], '目标', ['iteration' => 5]);
assert_true($result5->isSuccess(), 'reflection stops at max rounds');

// 10. 自定义策略
$rm4 = new ReflectionManager(['strategy' => function ($messages, $goal, $context) {
    return ReflectionResult::continuing('自定义策略判定未完成', '执行自定义操作');
}]);
$result6 = $rm4->reflect([], '目标');
assert_true($result6->shouldContinue(), 'custom strategy');
assert_eq('执行自定义操作', $result6->getNextAction(), 'custom strategy next action');

// 11. shouldContinue / getNextAction
assert_true($rm->shouldContinue($result2), 'shouldContinue returns true for error');
assert_eq('分析错误并修复', $rm->getNextAction($result2), 'getNextAction for error');

// 12. 多轮后无错误，默认完成
$messages7 = [];
for ($i = 0; $i < 3; $i++) {
    $messages7[] = ['role' => 'user', 'content' => '继续'];
    $messages7[] = ['role' => 'assistant', 'content' => [
        ['type' => 'text', 'text' => '执行中'],
        ['type' => 'tool_use', 'name' => 'read_file', 'id' => 'tool_' . $i],
    ]];
    $messages7[] = ['role' => 'tool', 'content' => '文件内容'];
}
$result7 = $rm->reflect($messages7, '目标', ['iteration' => 3]);
assert_true($result7->isSuccess(), 'multiple rounds no error defaults to completed');

// 13. 第一轮没有工具调用
$messages8 = [
    ['role' => 'user', 'content' => '帮我解决一个问题'],
    ['role' => 'assistant', 'content' => '让我先分析一下'],
];
$result8 = $rm->reflect($messages8, '解决问题', ['isFirstRound' => true]);
assert_true($result8->shouldContinue(), 'first round without tools continues');

echo "\n=== 结果: {$pass} 通过, {$fail} 失败 ===\n";
exit($fail > 0 ? 1 : 0);