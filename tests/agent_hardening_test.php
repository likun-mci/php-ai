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

// ============================================================
echo "\n=== 五、实测暴露的三处（用真实网关跑出来的） ===\n";
// ============================================================

// 5.1 模型调用瞬时失败要重试
//     背景：同一份字节完全相同的请求连发四次，三次成功一次 400 Format Error。
//     传输层不重试 4xx（一般是对的），于是一次偶发 400 杀掉整轮 Agent。
class FlakyAI extends \Ai\AI
{
    public $failTimes = 1;
    public $calls = 0;
    public $failWith = 'HTTP Error: 400: Format Error';

    public function chat($payload = ''): \Ai\Contracts\AIResponseInterface
    {
        $this->calls++;
        if ($this->calls <= $this->failTimes) {
            throw new \Ai\Exceptions\AIException($this->failWith, 'test', '400');
        }
        return new \Ai\Response\AIResponse(['content' => '干完了']);
    }
}

$flaky = new FlakyAI(['api_key' => 'test', 'platform' => 'openai']);
$rt = new AgentRuntime($flaky);
$rt->getLoop()->setModelRetries(2, 1);   // 退避 1ms，别让测试白等
$res = $rt->run([['role' => 'user', 'content' => '干活']]);
eq('end_turn', $res->getStopReason(), '偶发 400 重试后跑通（原先整轮暴毙）');
eq('干完了', $res->getText(), '拿到正常结果');
eq(2, $flaky->calls, '重试了一次就成功，没有多试');

// 重试用尽仍失败 → 收尾，但不能无限试
$flaky2 = new FlakyAI(['api_key' => 'test', 'platform' => 'openai']);
$flaky2->failTimes = 99;
$rt2 = new AgentRuntime($flaky2);
$rt2->getLoop()->setModelRetries(2, 1);
$res2 = $rt2->run([['role' => 'user', 'content' => '干活']]);
eq('model_error', $res2->getStopReason(), '重试用尽后如实报 model_error');
eq(3, $flaky2->calls, '总共只试 1+2 次，不无限重试');

// 鉴权失败不该重试——重试只是白等
$auth = new FlakyAI(['api_key' => 'test', 'platform' => 'openai']);
$auth->failTimes = 99;
$auth->failWith = 'HTTP Error: 401: Unauthorized';
$rt3 = new AgentRuntime($auth);
$rt3->getLoop()->setModelRetries(3, 1);
$rt3->run([['role' => 'user', 'content' => '干活']]);
eq(1, $auth->calls, '401 立即放弃，不做无谓重试');

// 关掉重试时行为与从前一致
$off = new FlakyAI(['api_key' => 'test', 'platform' => 'openai']);
$off->failTimes = 99;
$rtOff = new AgentRuntime($off);
$rtOff->getLoop()->setModelRetries(0);
$rtOff->run([['role' => 'user', 'content' => '干活']]);
eq(1, $off->calls, 'setModelRetries(0) 时只调一次');

// 5.2 模型调用失败不能把已有成果一起丢
//     实测最坏的一种：文件已改好、测试已跑过，最后一次调用撞上偶发 400，
//     调用方拿到空字符串，只能判定任务失败。
class LateFailAI extends \Ai\AI
{
    public $calls = 0;
    public function chat($payload = ''): \Ai\Contracts\AIResponseInterface
    {
        $this->calls++;
        if ($this->calls === 1) {
            return new \Ai\Response\AIResponse([
                'content'    => '我已经把 Cart.php 改好并跑通了测试',
                'tool_calls' => [['id' => 'c1', 'name' => 'noop', 'input' => []]],
            ]);
        }
        throw new \Ai\Exceptions\AIException('HTTP Error: 400: Format Error', 'test', '400');
    }
}

$late = new LateFailAI(['api_key' => 'test', 'platform' => 'openai']);
$rt4 = new AgentRuntime($late);
$rt4->getLoop()->setModelRetries(0);
$rt4->setTools(['noop' => ['description' => 'x', 'handler' => function () { return 'ok'; }]]);
$res4 = $rt4->run([['role' => 'user', 'content' => '修 Bug']]);

eq('model_error', $res4->getStopReason(), '如实报模型错误');
ok($res4->getText() !== '', '最后一段文本没有跟着一起丢（原先是空串）');
ok(strpos($res4->getText(), 'Cart.php') !== false, '调用方看得到已经做过什么');
ok(isset($res4->getExtra()['error']), '错误原因也在');

// 5.3 权限模式名写错要当场炸，不要静默忽略
$pmgr = new \Ai\Agent\Permission\PermissionManager();
$threw = false;
try {
    $pmgr->setMode('acceptAll');       // 很自然的猜法，但不是合法值
} catch (\InvalidArgumentException $e) {
    $threw = true;
    ok(strpos($e->getMessage(), 'bypass') !== false, '异常里列出可选值');
}
ok($threw, '非法权限模式当场抛异常（原先静默忽略，跑到第一个 bash 才卡住）');
eq('manual', $pmgr->getMode(), '抛异常后模式保持不变');

$pmgr->setMode('bypass');
eq('bypass', $pmgr->getMode(), '合法模式正常设置');

// ============================================================
echo "\n=== 六、代码 Agent 默认提示词 ===\n";
// ============================================================

// 背景：codeAgent() 原先一个字的系统提示词都没有。工具描述只说明「这工具做什么」，
// 回答不了「什么时候该用」。实测后果具体可测（真网关、三功能多文件任务）：
//   无提示词  ：update_plan 0 次，给已有文件加方法用 write_file 整体重写
//   有提示词  ：update_plan 2~3 次，全部走 edit_file 增量改
$prompt = \Ai\Agent\CodeAgentPrompt::build();
ok($prompt !== '', '生成了提示词');
ok(strpos($prompt, 'edit_file') !== false, '讲了用 edit_file 改已有文件');
ok(strpos($prompt, 'write_file') !== false, '讲了 write_file 的适用范围');
ok(strpos($prompt, 'update_plan') !== false, '讲了什么时候写计划');
ok(strpos($prompt, 'delegate') !== false, '讲了什么时候委派');

$withTest = \Ai\Agent\CodeAgentPrompt::build(['test' => 'composer test']);
ok(strpos($withTest, 'composer test') !== false, '给了测试命令就写进提示词');
ok(strpos(\Ai\Agent\CodeAgentPrompt::build(), 'composer test') === false,
    '没给测试命令时不编一个出来');

$ai6 = new StubAI(['api_key' => 'test', 'platform' => 'openai']);
$agent6 = \Ai\Agent\Agent::create($ai6)->setWorkdir(sys_get_temp_dir())
    ->codeAgent(['noIndex' => true]);
ok($agent6->getRuntime()->getSystem() !== '', 'codeAgent 装上了默认提示词');

// 调用方自己设过就不该被顶掉——那是他们的意图
$agent7 = \Ai\Agent\Agent::create($ai6)->setWorkdir(sys_get_temp_dir())
    ->setSystem('我自己的提示词')->codeAgent(['noIndex' => true]);
eq('我自己的提示词', $agent7->getRuntime()->getSystem(), '自定义提示词不被默认值覆盖');

echo "\n=== 结果: {$pass} 通过, {$fail} 失败 ===\n";
exit($fail > 0 ? 1 : 0);
