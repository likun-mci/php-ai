<?php
/**
 * 加固测试——长任务与委派场景下的状态、预算、度量
 *
 * 覆盖：
 *   1. 子 Agent 继承父预算与取消（子 Agent 花的是父 Agent 的钱和时间）
 *   2. 运行度量自动填进 AgentResult（成本核算不该让调用方自己拼）
 *   3. 压缩保真——摘要会漏，结构化状态不会
 *   4. 计划与完成判据联动（还有 pending 步骤时不算完成）
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Ai\AI;
use Ai\Agent\AgentRuntime;
use Ai\Agent\Budget\BudgetManager;
use Ai\Agent\Context\ContextManager;
use Ai\Agent\Loop\CancellationToken;
use Ai\Agent\Orchestrator\CompletionCriteria;
use Ai\Agent\Planning\PlanManager;
use Ai\Agent\Planning\PlanStep;
use Ai\Agent\SubAgent\SubAgentManager;

$pass = 0;
$fail = 0;

function ok($cond, $label)
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS: {$label}\n"; }
    else { $fail++; echo "  FAIL: {$label}\n"; }
}

function eq($expected, $actual, $label)
{
    global $pass, $fail;
    if ($expected === $actual) { $pass++; echo "  PASS: {$label}\n"; }
    else {
        $fail++;
        echo "  FAIL: {$label}\n";
        echo "    expected: " . var_export($expected, true) . "\n";
        echo "    actual:   " . var_export($actual, true) . "\n";
    }
}

class StubAI extends \Ai\AI
{
    public $canned = '完成';
    public $calls = 0;
    public $usage = ['prompt_tokens' => 100, 'completion_tokens' => 50];

    public function chat($payload = ''): \Ai\Contracts\AIResponseInterface
    {
        $this->calls++;
        return new \Ai\Response\AIResponse([
            'content' => $this->canned,
            'usage'   => $this->usage,
        ]);
    }
}

// ============================================================
echo "\n=== 一、子 Agent 继承父预算与取消 ===\n";
// ============================================================

$ai = new StubAI(['api_key' => 'test', 'platform' => 'openai']);
$runtime = new AgentRuntime($ai);

$budget = new BudgetManager(['maxTokens' => 100000]);
$sam = new SubAgentManager($ai);

// 先设管理器、后设预算——常见装配顺序，不该要求调用方记顺序
$runtime->setSubAgentManager($sam);
$runtime->setBudget($budget);
ok($sam->getParentBudget() === $budget, '后设的预算同步给了子 Agent 管理器');

$token = new CancellationToken();
$runtime->setCancellation($token);
ok($sam->getParentCancellation() === $token, '后设的取消令牌同步给了子 Agent 管理器');

// 反过来的顺序也要成立
$runtime2 = new AgentRuntime($ai);
$budget2 = new BudgetManager();
$token2 = new CancellationToken();
$runtime2->setBudget($budget2);
$runtime2->setCancellation($token2);
$sam2 = new SubAgentManager($ai);
$runtime2->setSubAgentManager($sam2);
ok($sam2->getParentBudget() === $budget2, '先设预算后设管理器同样生效');
ok($sam2->getParentCancellation() === $token2, '先设取消令牌后设管理器同样生效');

// 子 Agent 的运行时确实拿到了同一本账
$sam->register('worker', ['description' => '干活', 'prompt' => 'x']);
$subRuntime = $sam->buildRuntime($sam->get('worker'));
ok($subRuntime->getCancellation() === $token, '子 Agent 运行时共用父取消令牌');

// 共用同一本账 = 子 Agent 的花费累进父账本
$before = $budget->getTotalTokens();
$budget->record(['prompt_tokens' => 500, 'completion_tokens' => 200]);
ok($budget->getTotalTokens() > $before, '子 Agent 的用量记在父账本上');

// runtime->cancel() 也要往下传
$runtime3 = new AgentRuntime($ai);
$sam3 = new SubAgentManager($ai);
$runtime3->setSubAgentManager($sam3);
$runtime3->cancel('停');
ok($sam3->getParentCancellation() !== null, 'cancel() 自动建的令牌也传给了子 Agent');
ok($sam3->getParentCancellation()->isCancelled(), '传下去的令牌已是取消状态');

// ============================================================
echo "\n=== 二、运行度量自动填进结果 ===\n";
// ============================================================

$ai4 = new StubAI(['api_key' => 'test', 'platform' => 'openai']);
$runtime4 = new AgentRuntime($ai4);
$runtime4->setBudget(new BudgetManager([
    'pricing'    => ['prompt' => 1.0, 'completion' => 2.0],
    'perMillion' => true,
]));
$result = $runtime4->run([['role' => 'user', 'content' => '你好']]);

$extra = $result->getExtra();
ok(isset($extra['duration_ms']), '结果带上运行耗时');
ok($extra['duration_ms'] >= 0, '耗时是个合理的数');
ok(isset($extra['cost']), '结果带上成本');
ok($result->getCost() > 0, '成本按用量算出来了（原先恒为 0）');
ok(isset($extra['budget']), '结果带上预算快照');

$contract = $result->toContract();
ok($contract['cost'] > 0, 'toContract() 的成本不再是 0');
ok($contract['duration_ms'] >= 0, 'toContract() 带上耗时');

// 没挂预算时不该炸，只是没有成本字段
$runtime5 = new AgentRuntime(new StubAI(['api_key' => 'test', 'platform' => 'openai']));
$r5 = $runtime5->run([['role' => 'user', 'content' => '你好']]);
ok(isset($r5->getExtra()['duration_ms']), '没挂预算也有耗时');
eq(0.0, $r5->getCost(), '没挂预算时成本为 0（不假装有数）');

// ============================================================
echo "\n=== 三、压缩保真 ===\n";
// ============================================================

$messages = [];
for ($i = 0; $i < 12; $i++) {
    $messages[] = ['role' => 'user', 'content' => "第 {$i} 条用户消息"];
    $messages[] = ['role' => 'assistant', 'content' => "第 {$i} 条回复"];
}

$digest = "目标：修复登录 Bug\n计划（v2，完成度 50%）：\n  [completed] 定位入口\n  [pending] 跑测试\n已改动的文件：src/Auth.php、src/User.php";

// 摘要正常时，事实也要原样在
$cm = new ContextManager($messages, ['keepRecent' => 4]);
$out = $cm->compact(function () { return '之前做了一些事。'; }, '目标', $digest);
$first = $out[0]['content'];
ok(strpos($first, '之前做了一些事。') !== false, '摘要进了上下文');
ok(strpos($first, 'src/Auth.php') !== false, '改过的文件原样留住（摘要里没提也不丢）');
ok(strpos($first, '[pending] 跑测试') !== false, '未完成的计划步骤原样留住');
ok(strpos($first, '[Preserved state]') !== false, '事实段有明确标记');
ok(count($out) < count($messages), '确实压缩了');

// 摘要失败时最不能丢状态——模型对早期历史一无所知，全靠这段撑住
$cm2 = new ContextManager($messages, ['keepRecent' => 4]);
$out2 = $cm2->compact(function () { return ''; }, '目标', $digest);
$joined = '';
foreach ($out2 as $m) { $joined .= is_string($m['content']) ? $m['content'] : ''; }
ok(strpos($joined, 'src/Auth.php') !== false, '摘要失败时状态仍然保住');
ok(strpos($joined, '[Preserved state]') !== false, '摘要失败时事实段仍然在');

// 不传 preserve 时行为与从前一致
$cm3 = new ContextManager($messages, ['keepRecent' => 4]);
$out3 = $cm3->compact(function () { return '摘要'; }, '目标');
ok(strpos($out3[0]['content'], '[Preserved state]') === false, '不传 preserve 时不加多余段落（默认行为不变）');
ok(strpos($out3[0]['content'], '摘要') !== false, '不传 preserve 时摘要照常');

// ============================================================
echo "\n=== 四、计划与完成判据联动 ===\n";
// ============================================================

$pm = new PlanManager();
$plan = $pm->createPlan('修复登录 Bug', ['steps' => ['定位入口', '改代码', '跑测试']]);

$criteria = CompletionCriteria::lenient();

// 还有 pending 步骤 → 不算完成，哪怕模型说完成了
$outcome = $criteria->evaluate([
    'plan'              => $plan,
    'model_claims_done' => true,
]);
ok(!$outcome['completed'], '计划还有 pending 步骤时不算完成（模型说完了也不算）');
ok(in_array(CompletionCriteria::NO_PENDING_STEPS, $outcome['unmet'], true),
    '未满足项明确指出是「还有未完成步骤」');

// 全部走完 → 完成
foreach ($plan->getSteps() as $step) {
    $step->markCompleted('done');
}
$outcome2 = $criteria->evaluate(['plan' => $plan, 'model_claims_done' => true]);
ok($outcome2['completed'], '步骤全部完成后判为完成');

// skipped 也算处理过，不该卡住
$plan2 = $pm->createPlan('另一个目标', ['steps' => ['做 A', '做 B']]);
$steps2 = $plan2->getSteps();
$steps2[0]->markCompleted('ok');
$steps2[1]->markSkipped('不需要');
ok($criteria->evaluate(['plan' => $plan2])['completed'], 'skipped 的步骤不卡住完成判定');

// 工具还在报错 → 不算完成
$withErrors = $criteria->evaluate([
    'plan'     => $plan,
    'messages' => [[
        'role'    => 'user',
        'content' => [['type' => 'tool_result', 'content' => 'ERROR: 测试失败', 'is_error' => true]],
    ]],
]);
ok(!$withErrors['completed'], '最近工具结果报错时不算完成');

echo "\n=== 结果: {$pass} 通过, {$fail} 失败 ===\n";
exit($fail > 0 ? 1 : 0);
