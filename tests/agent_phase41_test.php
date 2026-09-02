<?php
/**
 * Phase 4.1 集成测试——Planning / Reflection / Verification / Memory 接入 Agent 运行时
 *
 * 覆盖：
 *   1. AgentRuntime / AgentContext 的 Planning、Reflection、goal 读写
 *   2. 计划摘要注入系统提示词
 *   3. 反思闭环：模型说完了但目标没达成 → 继续迭代
 *   4. 反思判定完成 → 正常结束（且不改变未开启反思时的行为）
 *   5. 只挂验证器（不配命令规则）时验证仍会执行
 *   6. 相关记忆按 goal 检索后注入
 *   7. TaskState 保存 / 还原计划
 *   8. Agent 快捷方法
 *
 * 不联网、不需要 Key。运行：php tests/agent_phase41_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\AgentRuntime;
use Ai\Agent\Planning\Plan;
use Ai\Agent\Planning\PlanManager;
use Ai\Agent\Reflection\ReflectionManager;
use Ai\Agent\Reflection\ReflectionResult;
use Ai\Agent\Task\TaskState;
use Ai\Agent\Verification\PhpSyntaxVerifier;

/** 按顺序回放预置响应的假传输层 */
class ScriptedTransport41 implements \Ai\Contracts\TransportInterface
{
    public $responses = [];
    public $requests  = [];

    public function post(string $url, array $data, array $headers = []): array
    {
        $this->requests[] = $data;
        if (!$this->responses) {
            // 响应耗尽后一律返回"结束"，避免测试卡在循环里
            return ['choices' => [['message' => ['role' => 'assistant', 'content' => '结束'], 'finish_reason' => 'stop']]];
        }
        return array_shift($this->responses);
    }
    public function get(string $url, array $params = [], array $headers = []): array { return []; }
    public function setTimeout(int $t): \Ai\Contracts\TransportInterface { return $this; }
    public function setProxy(string $p): \Ai\Contracts\TransportInterface { return $this; }
    public function setStreamCallback(?callable $cb): \Ai\Contracts\TransportInterface { return $this; }
}

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
    if ($expected !== $actual) {
        echo "    期望: " . var_export($expected, true) . "\n";
        echo "    实际: " . var_export($actual, true) . "\n";
    }
}

/** 造一条纯文本回复 */
function textReply($text)
{
    return ['choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']]];
}

/** 造一条工具调用回复 */
function toolReply($id, $name, $args)
{
    return ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
        'id' => $id, 'type' => 'function',
        'function' => ['name' => $name, 'arguments' => $args],
    ]]], 'finish_reason' => 'tool_calls']]];
}

$tmpDir = sys_get_temp_dir() . '/php_ai_p41_' . getmypid();
@mkdir($tmpDir, 0777, true);

// ===== 一、Runtime / Context 读写 =====

echo "\n=== 一、Runtime / Context 读写 ===\n";

$ai = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$runtime = new AgentRuntime($ai);

assert_eq('默认无 PlanManager', null, $runtime->getPlanManager());
assert_eq('默认无 ReflectionManager', null, $runtime->getReflectionManager());
assert_eq('默认 goal 为空', '', $runtime->getGoal());

$pm = new PlanManager();
$runtime->setPlanManager($pm)->setGoal('修复登录 401');
$plan = $pm->createPlan('修复登录 401', ['steps' => ['定位问题', '改代码', '跑测试']]);
$runtime->setPlanId($plan->getId());

test('setPlanManager 生效', $runtime->getPlanManager() === $pm);
assert_eq('setGoal 生效', '修复登录 401', $runtime->getGoal());
assert_eq('setPlanId 生效', $plan->getId(), $runtime->getPlanId());

$rm = new ReflectionManager();
$runtime->setReflectionManager($rm);
test('setReflectionManager 生效', $runtime->getReflectionManager() === $rm);

// ===== 二、计划摘要注入系统提示词 =====

echo "\n=== 二、计划注入系统提示词 ===\n";

$tr = new ScriptedTransport41();
$tr->responses = [textReply('好的')];
$ai2 = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai2->setTransport($tr);

$rt2 = new AgentRuntime($ai2);
$pm2 = new PlanManager();
$plan2 = $pm2->createPlan('给 Auth 补测试', ['steps' => ['读 Auth.php', '写测试']]);
$rt2->setSystem('你是助手')->setPlanManager($pm2)->setPlanId($plan2->getId());
$rt2->run([['role' => 'user', 'content' => '开始']]);

$sentSystem = isset($tr->requests[0]['messages'][0]['content'])
    ? (string) $tr->requests[0]['messages'][0]['content']
    : '';
if ($sentSystem === '' && isset($tr->requests[0]['system'])) {
    $sentSystem = (string) $tr->requests[0]['system'];
}
test('系统提示词含 <plan> 块', strpos($sentSystem, '<plan>') !== false);
test('计划块含目标', strpos($sentSystem, '给 Auth 补测试') !== false);
test('计划块含步骤', strpos($sentSystem, '读 Auth.php') !== false);

// 未设置计划时不注入
$tr3 = new ScriptedTransport41();
$tr3->responses = [textReply('好的')];
$ai3 = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai3->setTransport($tr3);
$rt3 = new AgentRuntime($ai3);
$rt3->setSystem('你是助手')->run([['role' => 'user', 'content' => '开始']]);
$sys3 = isset($tr3->requests[0]['messages'][0]['content'])
    ? (string) $tr3->requests[0]['messages'][0]['content']
    : (isset($tr3->requests[0]['system']) ? (string) $tr3->requests[0]['system'] : '');
test('无计划时不注入 <plan>', strpos($sys3, '<plan>') === false);

// ===== 三、反思闭环：未完成 → 继续迭代 =====

echo "\n=== 三、反思闭环 ===\n";

$tr4 = new ScriptedTransport41();
$tr4->responses = [
    textReply('我先看看情况'),   // 第 1 轮：反思判定未完成 → 继续
    textReply('已完成，测试全部通过'),  // 第 2 轮：含完成标记 → 结束
];
$ai4 = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai4->setTransport($tr4);

$events = [];
$rt4 = new AgentRuntime($ai4);
$rt4->setSystem('助手')
    ->setReflectionManager(new ReflectionManager())
    ->setGoal('让测试通过')
    ->onEvent(function ($e) use (&$events) { $events[] = $e; });
$result4 = $rt4->run([['role' => 'user', 'content' => '修一下']]);

assert_eq('反思后多跑了一轮', 2, count($tr4->requests));
test('最终正常结束', $result4->isDone());
assert_eq('最终文本是第二轮的', '已完成，测试全部通过', $result4->getText());

$reflectionEvents = array_values(array_filter($events, function ($e) {
    return isset($e['type']) && $e['type'] === 'reflection';
}));
test('触发了 reflection 事件', count($reflectionEvents) >= 2);
test('第一次反思判定未完成', isset($reflectionEvents[0]['success']) && $reflectionEvents[0]['success'] === false);
test('最后一次反思判定完成', $reflectionEvents[count($reflectionEvents) - 1]['success'] === true);

// 反思建议被回填成 user 消息
$secondRequestMessages = $tr4->requests[1]['messages'];
$lastMsg = $secondRequestMessages[count($secondRequestMessages) - 1];
assert_eq('反思结论以 user 消息回填', 'user', $lastMsg['role']);
test('回填内容含下一步建议', is_string($lastMsg['content']) && strpos($lastMsg['content'], '继续') !== false);

// 未开启反思时行为不变
$tr5 = new ScriptedTransport41();
$tr5->responses = [textReply('我先看看情况')];
$ai5 = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai5->setTransport($tr5);
$rt5 = new AgentRuntime($ai5);
$rt5->setSystem('助手')->run([['role' => 'user', 'content' => '修一下']]);
assert_eq('未开启反思时只跑一轮', 1, count($tr5->requests));

// 停用的反思管理器等同于没开
$tr6 = new ScriptedTransport41();
$tr6->responses = [textReply('我先看看情况')];
$ai6 = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai6->setTransport($tr6);
$rt6 = new AgentRuntime($ai6);
$rm6 = new ReflectionManager(['enabled' => false]);
$rt6->setSystem('助手')->setReflectionManager($rm6)->setGoal('让测试通过')
    ->run([['role' => 'user', 'content' => '修一下']]);
assert_eq('停用反思时只跑一轮', 1, count($tr6->requests));

// 自定义反思策略
$tr7 = new ScriptedTransport41();
$tr7->responses = [textReply('第一轮'), textReply('第二轮')];
$ai7 = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai7->setTransport($tr7);
$calls = 0;
$rm7 = new ReflectionManager();
$rm7->setStrategy(function ($messages, $goal) use (&$calls) {
    $calls++;
    return $calls === 1
        ? ReflectionResult::continuing('还差一步', '把最后一步做完')
        : ReflectionResult::completed('好了');
});
$rt7 = new AgentRuntime($ai7);
$rt7->setSystem('助手')->setReflectionManager($rm7)->setGoal('目标')
    ->run([['role' => 'user', 'content' => '干活']]);
assert_eq('自定义策略被调用两次', 2, $calls);
assert_eq('自定义策略驱动了第二轮', 2, count($tr7->requests));

// ===== 四、只挂验证器时验证仍执行 =====

echo "\n=== 四、只挂验证器的验证 ===\n";

$badFile = $tmpDir . '/bad.php';
file_put_contents($badFile, "<?php\nfunction bad( { }\n");

$tr8 = new ScriptedTransport41();
$tr8->responses = [
    toolReply('c1', 'write_file', json_encode(['file_path' => $badFile])),
    textReply('好了'),
];
$ai8 = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai8->setTransport($tr8);

$agent8 = new Agent($ai8);
$agent8->setSystem('助手')
    ->setTools([
        'write_file' => [
            'description'  => '写文件',
            'input_schema' => ['type' => 'object'],
            'handler'      => function (array $in) { return '已写入'; },
        ],
    ])
    ->addVerifier(new PhpSyntaxVerifier());
$agent8->run([['role' => 'user', 'content' => '写个文件']]);

$secondReq = isset($tr8->requests[1]) ? $tr8->requests[1] : null;
$body = $secondReq ? json_encode($secondReq, JSON_UNESCAPED_UNICODE) : '';
test('语法错误被验证器捕获并回填', strpos($body, 'VERIFICATION FAILED') !== false);
test('回填信息带验证器名', strpos($body, 'php_syntax') !== false);

// ===== 五、相关记忆按 goal 注入 =====

echo "\n=== 五、相关记忆注入 ===\n";

$memDir = $tmpDir . '/mem';
@mkdir($memDir, 0777, true);
$mm = new \Ai\Agent\Memory\MemoryManager($memDir);
$mm->write('project', "登录走 JWT，密钥在 config/jwt.php\n前端用 Vue 3，构建走 Vite\n");

$tr9 = new ScriptedTransport41();
$tr9->responses = [textReply('好的')];
$ai9 = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai9->setTransport($tr9);
$rt9 = new AgentRuntime($ai9);
$rt9->setSystem('助手')->setMemoryManager($mm)->setGoal('登录接口报 401')
    ->run([['role' => 'user', 'content' => '看看' ]]);

$sys9 = isset($tr9->requests[0]['messages'][0]['content'])
    ? (string) $tr9->requests[0]['messages'][0]['content']
    : (isset($tr9->requests[0]['system']) ? (string) $tr9->requests[0]['system'] : '');
test('注入了相关记忆块', strpos($sys9, '<memory-relevant') !== false);
test('注入了相关那条', strpos($sys9, 'JWT') !== false);
test('无关那条未注入', strpos($sys9, 'Vite') === false);

// 无 goal 时注入全部记忆（与升级前一致）
$tr10 = new ScriptedTransport41();
$tr10->responses = [textReply('好的')];
$ai10 = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai10->setTransport($tr10);
$rt10 = new AgentRuntime($ai10);
$rt10->setSystem('助手')->setMemoryManager($mm)->run([['role' => 'user', 'content' => '看看']]);
$sys10 = isset($tr10->requests[0]['messages'][0]['content'])
    ? (string) $tr10->requests[0]['messages'][0]['content']
    : (isset($tr10->requests[0]['system']) ? (string) $tr10->requests[0]['system'] : '');
test('无 goal 时退回全量记忆', strpos($sys10, '<memory>') !== false);
test('全量记忆含全部条目', strpos($sys10, 'Vite') !== false);

// ===== 六、TaskState 保存计划 =====

echo "\n=== 六、TaskState 保存计划 ===\n";

$state = new TaskState(['goal' => '修复登录']);
assert_eq('默认无计划', [], $state->getPlan());
assert_eq('无计划时还原为 null', null, $state->restorePlan());

$pm3 = new PlanManager();
$plan3 = $pm3->createPlan('修复登录', ['steps' => ['定位', '修复']]);
$state->setPlan($plan3);
test('计划快照已保存', $state->getPlan() !== []);
test('toSummary 含执行计划', strpos($state->toSummary(), '执行计划') !== false);

$restored = $state->restorePlan();
test('还原出 Plan 对象', $restored instanceof Plan);
assert_eq('还原后目标一致', '修复登录', $restored->getGoal());
assert_eq('还原后步骤数一致', 2, count($restored->getSteps()));

$roundTrip = TaskState::fromJson($state->toJson());
test('JSON 往返后计划仍在', $roundTrip->restorePlan() instanceof Plan);
assert_eq('JSON 往返后计划 ID 一致', $plan3->getId(), $roundTrip->restorePlan()->getId());

$state->setPlan(null);
assert_eq('setPlan(null) 清空计划', [], $state->getPlan());

// ===== 七、Agent 快捷方法 =====

echo "\n=== 七、Agent 快捷方法 ===\n";

$aiX = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$agent = new Agent($aiX);

assert_eq('默认无计划', null, $agent->getPlan());

$p = $agent->plan('重构支付模块', ['读代码', '拆函数', '跑测试']);
test('plan() 返回 Plan', $p instanceof Plan);
assert_eq('plan() 建了 3 个步骤', 3, count($p->getSteps()));
assert_eq('plan() 已启动计划', Plan::STATUS_RUNNING, $p->getStatus());
assert_eq('plan() 同步设置了 goal', '重构支付模块', $agent->getRuntime()->getGoal());
test('getPlan() 取回同一个计划', $agent->getPlan()->getId() === $p->getId());

$agent->setPlanDir($tmpDir . '/plans');
test('setPlanDir 建了 PlanManager', $agent->getRuntime()->getPlanManager() instanceof PlanManager);

$agent->enableReflection(['maxRounds' => 3]);
$rmX = $agent->getRuntime()->getReflectionManager();
test('enableReflection 建了 ReflectionManager', $rmX instanceof ReflectionManager);
assert_eq('maxRounds 透传', 3, $rmX->getMaxRounds());

$agent->setGoal('新目标');
assert_eq('setGoal 生效', '新目标', $agent->getRuntime()->getGoal());

$agent->addVerifier(new PhpSyntaxVerifier());
$vmX = $agent->getRuntime()->getVerificationManager();
test('addVerifier 自动建了 VerificationManager', $vmX !== null);
assert_eq('验证器已挂载', 1, count($vmX->verifiers()));

$agent2 = new Agent($aiX);
$agent2->useDefaultVerifiers(['test' => 'exit 0', 'workdir' => $tmpDir, 'maxFiles' => 5]);
assert_eq('useDefaultVerifiers 挂了 4 个', 4, count($agent2->getRuntime()->getVerificationManager()->verifiers()));

$agent3 = new Agent($aiX);
$agent3->useDefaultVerifiers();
assert_eq('无选项时只挂语法与安全两个', 2, count($agent3->getRuntime()->getVerificationManager()->verifiers()));

// ===== 清理 =====

@unlink($badFile);
foreach (\Ai\Agent\Memory\MemoryManager::validScopes() as $scope) {
    @unlink($memDir . '/' . $scope . '.md');
}
@rmdir($memDir);
foreach (glob($tmpDir . '/plans/*.json') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmpDir . '/plans');
@rmdir($tmpDir);

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);
