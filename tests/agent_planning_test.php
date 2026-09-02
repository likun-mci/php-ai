<?php
/**
 * Agent Planning Engine 冒烟测试
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Ai\Agent\Planning\PlanManager;
use Ai\Agent\Planning\Plan;
use Ai\Agent\Planning\PlanStep;
use Ai\Agent\Planning\PlanExecutor;
use Ai\Agent\Planning\PlanReview;

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

echo "=== Agent Planning Engine Test ===\n\n";

// 1. PlanStep 基础
echo "--- PlanStep ---\n";
$step = new PlanStep(1, '修改 session 逻辑', [
    'description' => '分析并修改用户登录的 session 处理',
    'dependencies' => [],
]);
assert_eq(1, $step->getId(), 'step id');
assert_eq('修改 session 逻辑', $step->getAction(), 'step action');
assert_eq('pending', $step->getStatus(), 'step status initial');
assert_true($step->isPending(), 'step isPending()');
assert_true($step->isReady([]), 'step isReady() with empty completed');

$step->markRunning();
assert_eq('running', $step->getStatus(), 'step status running');
assert_true($step->isRunning(), 'step isRunning()');

$step->markCompleted('已修改 session 逻辑');
assert_eq('completed', $step->getStatus(), 'step status completed');
assert_true($step->isCompleted(), 'step isCompleted()');
assert_true($step->isTerminal(), 'step isTerminal()');

// 2. PlanStep 失败状态
$step2 = new PlanStep(2, '修改数据库连接');
$step2->markFailed('连接超时');
assert_eq('failed', $step2->getStatus(), 'step2 status failed');
assert_true($step2->isFailed(), 'step2 isFailed()');

// 3. PlanStep 跳过状态
$step3 = new PlanStep(3, '更新配置文件');
$step3->markSkipped('不需要修改');
assert_eq('skipped', $step3->getStatus(), 'step3 status skipped');
assert_true($step3->isSkipped(), 'step3 isSkipped()');

// 4. PlanStep 依赖检查
$step4 = new PlanStep(4, '部署到服务器', [
    'dependencies' => [1, 2],
]);
assert_true(!$step4->isReady([]), 'step4 not ready without deps');
assert_true(!$step4->isReady([1]), 'step4 not ready with partial deps');
assert_true($step4->isReady([1, 2]), 'step4 ready with all deps');

// 5. Plan 创建
echo "\n--- Plan ---\n";
$plan = new Plan('修复用户登录问题', [
    new PlanStep(1, '分析认证流程'),
    new PlanStep(2, '修改 session 逻辑', ['dependencies' => [1]]),
    new PlanStep(3, '运行测试', ['dependencies' => [2]]),
]);
assert_eq('修复用户登录问题', $plan->getGoal(), 'plan goal');
assert_eq('pending', $plan->getStatus(), 'plan initial status');
assert_eq(3, count($plan->getSteps()), 'plan steps count');

// 6. Plan 进度
assert_eq(0, $plan->progress(), 'plan progress 0%');

// 7. Plan 当前步骤
$current = $plan->getCurrentStep();
assert_true($current !== null, 'plan has current step');
assert_eq(1, $current->getId(), 'plan current step id');

// 8. Plan toArray / fromArray
$data = $plan->toArray();
$restored = Plan::fromArray($data);
assert_eq($plan->getGoal(), $restored->getGoal(), 'plan serialization goal');
assert_eq(count($plan->getSteps()), count($restored->getSteps()), 'plan serialization steps count');

// 9. Plan JSON
$json = $plan->toJson();
$fromJson = Plan::fromJson($json);
assert_eq($plan->getGoal(), $fromJson->getGoal(), 'plan json roundtrip');

// 10. PlanManager 创建
echo "\n--- PlanManager ---\n";
$tmpDir = sys_get_temp_dir() . '/php_ai_plan_test_' . uniqid();
$pm = new PlanManager($tmpDir, ['persist' => true]);
$created = $pm->createPlan('部署新版本', [
    'steps' => ['构建代码', '运行测试', '部署到服务器'],
    'risks' => ['测试环境可能不稳定'],
]);
assert_true($created !== null, 'pm createPlan');
assert_eq('部署新版本', $created->getGoal(), 'pm plan goal');
assert_eq(3, count($created->getSteps()), 'pm plan steps');

// 11. PlanManager start / advance
$pm->start($created->getId());
$plan = $pm->getPlan($created->getId());
assert_eq('running', $plan->getStatus(), 'pm plan status running');

$nextStep = $pm->advance($created->getId());
assert_true($nextStep !== null, 'pm advance returns step');
assert_eq('构建代码', $nextStep->getAction(), 'pm advance step action');

// 12. PlanManager completeStep
$pm->completeStep($created->getId(), $nextStep->getId(), '构建完成');
$plan = $pm->getPlan($created->getId());
$completed = $plan->getCompletedSteps();
assert_eq(1, count($completed), 'pm completed steps count');

// 13. PlanManager modifyPlan
$pm->modifyPlan($created->getId(), [
    'append' => ['发送部署通知'],
], '增加通知步骤');
$plan = $pm->getPlan($created->getId());
assert_eq(4, count($plan->getSteps()), 'pm modified steps count');

// 14. PlanManager 持久化
$pm2 = new PlanManager($tmpDir, ['persist' => true]);
assert_eq(1, count($pm2->allPlans()), 'pm persisted plans loaded');

// 清理
array_map('unlink', glob($tmpDir . '/*.json'));
@rmdir($tmpDir);

// 15. PlanExecutor 基础
echo "\n--- PlanExecutor ---\n";
$pm3 = new PlanManager('', ['persist' => false]);
$plan3 = $pm3->createPlan('执行测试', [
    'steps' => ['步骤1', '步骤2', '步骤3'],
]);
$executor = new PlanExecutor($pm3);

$result = $executor->executeAll($plan3->getId(), function ($step, $plan) {
    return '执行完成: ' . $step->getAction();
});
assert_true($result['success'], 'executor executeAll success');
assert_eq(3, $result['completed'], 'executor completed count');

// 16. PlanExecutor 失败重试
$pm4 = new PlanManager('', ['persist' => false]);
$plan4 = $pm4->createPlan('失败测试', ['steps' => ['会失败的步骤']]);
$executor2 = new PlanExecutor($pm4, ['maxRetries' => 1]);
$result2 = $executor2->executeAll($plan4->getId(), function ($step, $plan) {
    throw new \RuntimeException('故意失败');
});
assert_true(!$result2['success'], 'executor failed step');

// 17. PlanReview 基础
echo "\n--- PlanReview ---\n";
$pm5 = new PlanManager('', ['persist' => false]);
$plan5 = $pm5->createPlan('审查计划', ['steps' => ['步骤1', '步骤2']]);
$review = new PlanReview($pm5);
$result = $review->review($plan5->getId());
assert_eq('ok', $result['status'], 'review ok status');

// 18. PlanReview 检测失败步骤
$pm6 = new PlanManager('', ['persist' => false]);
$plan6 = $pm6->createPlan('失败计划', ['steps' => ['步骤1', '步骤2']]);
$pm6->failStep($plan6->getId(), 1, '执行错误');
$review6 = new PlanReview($pm6);
$result = $review6->review($plan6->getId());
assert_eq('affected', $result['status'], 'review affected status');
assert_true(count($result['issues']) > 0, 'review has issues');

// 19. PlanReview 依赖环检测
$pm7 = new PlanManager('', ['persist' => false]);
$plan7 = $pm7->createPlan('环测试', [
    'steps' => [
        ['id' => 1, 'action' => 'A', 'dependencies' => [2]],
        ['id' => 2, 'action' => 'B', 'dependencies' => [1]],
    ],
]);
$cycle = $review->detectDependencyCycle($plan7);
assert_true(count($cycle) > 0, 'review detects cycle');

// 20. Plan 摘要
$summary = $plan->toSummary();
assert_true(strpos($summary, '部署新版本') !== false, 'plan summary contains goal');

echo "\n=== 结果: {$pass} 通过, {$fail} 失败 ===\n";
exit($fail > 0 ? 1 : 0);