<?php
/**
 * Phase 5.2 测试——协作、验证与状态
 *
 * 覆盖：
 *   1. AgentMessage 新增四种类型（request / response / error / handoff）
 *   2. AgentHandoff 交接留痕与交回
 *   3. AgentTeam::handoff / handoffBack / handoffChain
 *   4. SessionBus 跨 Session 消息（落盘 + 内存两种模式）
 *   5. Plan 版本链（modifyPlan 不覆盖旧版本）
 *   6. VerificationPolicy 按任务类型选验证链
 *   7. VerificationGate 闸门放行与拦截
 *   8. CompletionCriteria 完成判据
 *   9. WorkspaceSnapshot 快照与差集
 *  10. Worktree merge / discard
 *  11. Skill 生命周期事件与依赖检查
 *  12. Instruction 就近发现与去重
 *  13. MemoryConsolidator 候选筛选与去重
 *  14. Agent 快捷方法
 *
 * 不联网、不需要 Key。运行：php tests/agent_phase52_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\Instruction\InstructionManager;
use Ai\Agent\Memory\MemoryConsolidator;
use Ai\Agent\Memory\MemoryManager;
use Ai\Agent\Orchestrator\CompletionCriteria;
use Ai\Agent\Planning\PlanManager;
use Ai\Agent\Session\SessionBus;
use Ai\Agent\Skill\SkillManager;
use Ai\Agent\SubAgent\BuiltinAgents;
use Ai\Agent\SubAgent\SubAgentManager;
use Ai\Agent\Team\AgentHandoff;
use Ai\Agent\Team\AgentMessage;
use Ai\Agent\Team\AgentRole;
use Ai\Agent\Team\AgentTeam;
use Ai\Agent\Verification\PhpSyntaxVerifier;
use Ai\Agent\Verification\SecurityVerifier;
use Ai\Agent\Verification\VerificationGate;
use Ai\Agent\Verification\VerificationManager;
use Ai\Agent\Verification\VerificationPolicy;
use Ai\Agent\Workspace\WorkspaceSnapshot;

class ScriptedTransport52 implements \Ai\Contracts\TransportInterface
{
    public $responses = [];
    public $requests  = [];

    public function post(string $url, array $data, array $headers = []): array
    {
        $this->requests[] = $data;
        if (!$this->responses) {
            return ['choices' => [['message' => ['role' => 'assistant', 'content' => '完成'], 'finish_reason' => 'stop']]];
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

function makeAI52(array $responses = [])
{
    $tr = new ScriptedTransport52();
    $tr->responses = $responses;
    $ai = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
    $ai->setTransport($tr);
    return [$ai, $tr];
}

function textReply52($text)
{
    return ['choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']]];
}

$tmpDir = sys_get_temp_dir() . '/php_ai_p52_' . getmypid();
@mkdir($tmpDir, 0777, true);

// ===== 一、新增消息类型 =====

echo "\n=== 一、消息类型扩展 ===\n";

assert_eq('九种消息类型', 9, count(AgentMessage::validTypes()));
foreach (['request', 'response', 'error', 'handoff'] as $type) {
    test("含 {$type} 类型", AgentMessage::isValidType($type));
}

$req = AgentMessage::request('coder', 'dba', '这张表有索引吗');
assert_eq('request 类型', AgentMessage::TYPE_REQUEST, $req->getType());

$resp = AgentMessage::respondTo($req, '没有，需要补');
assert_eq('response 方向反转', 'dba', $resp->getFrom());
assert_eq('response 目标是提问方', 'coder', $resp->getTo());
assert_eq('response 关联到请求', $req->getId(), $resp->getReplyTo());
assert_eq('非回应的 replyTo 为空', '', $req->getReplyTo());

$err = AgentMessage::error('tester', '', '测试环境起不来');
assert_eq('error 类型', AgentMessage::TYPE_ERROR, $err->getType());
test('error 可广播', $err->isBroadcast());

// ===== 二、AgentHandoff =====

echo "\n=== 二、任务交接 ===\n";

$handoff = new AgentHandoff('coder', 'dba', 'task_1', '发现是索引缺失', [
    'context_summary' => '已定位到 UserRepo::findByEmail，全表扫描 12 万行',
    'files'           => ['src/Repo/UserRepo.php'],
]);

assert_eq('交出方', 'coder', $handoff->getSourceAgent());
assert_eq('接手方', 'dba', $handoff->getTargetAgent());
assert_eq('关联任务', 'task_1', $handoff->getTaskId());
assert_eq('交接原因', '发现是索引缺失', $handoff->getReason());
test('进展摘要已记录', strpos($handoff->getContextSummary(), 'findByEmail') !== false);
test('额外字段收进 metadata', isset($handoff->getMetadata()['files']));
assert_eq('初始状态', AgentHandoff::STATUS_PENDING, $handoff->getStatus());
test('ID 非空', $handoff->getId() !== '');

$prompt = $handoff->toPrompt();
test('交接说明含双方', strpos($prompt, 'coder → dba') !== false);
test('交接说明含原因', strpos($prompt, '索引缺失') !== false);
test('交接说明含进展', strpos($prompt, 'findByEmail') !== false);

$msg = $handoff->toMessage();
assert_eq('转成 handoff 消息', AgentMessage::TYPE_HANDOFF, $msg->getType());
assert_eq('消息带 handoff_id', $handoff->getId(), $msg->meta('handoff_id'));

$handoff->accept();
assert_eq('接手后状态', AgentHandoff::STATUS_ACCEPTED, $handoff->getStatus());

$back = $handoff->reverse('索引已补', '慢查询从 3s 降到 20ms');
assert_eq('交回方向反转', 'dba', $back->getSourceAgent());
assert_eq('交回目标是原交出方', 'coder', $back->getTargetAgent());
assert_eq('交回保留任务 ID', 'task_1', $back->getTaskId());
assert_eq('原记录标记为已交回', AgentHandoff::STATUS_RETURNED, $handoff->getStatus());
assert_eq('交回记录来源', $handoff->getId(), $back->getMetadata()['returned_from']);

$rt = AgentHandoff::fromArray($handoff->toArray());
assert_eq('序列化往返：ID', $handoff->getId(), $rt->getId());
assert_eq('序列化往返：状态', AgentHandoff::STATUS_RETURNED, $rt->getStatus());

// ===== 三、团队交接 =====

echo "\n=== 三、团队交接 ===\n";

list($aiT) = makeAI52([textReply52('已处理')]);
$team = new AgentTeam($aiT);
$team->addMember(AgentRole::developer())->addMember(new AgentRole('dba', ['description' => '数据库']));

$events = [];
$team->onEvent(function ($e) use (&$events) { $events[] = $e['type']; });

$h = $team->handoff('developer', 'dba', '慢查询定位到索引', ['task_id' => 'task_9']);
test('交接成功', $h instanceof AgentHandoff);
test('触发 handoff 事件', in_array('handoff', $events, true));
assert_eq('dba 收到交接消息', 1, $team->communication()->unreadCount('dba'));
assert_eq('交接记录 1 条', 1, count($team->handoffs()));
assert_eq('按任务过滤交接', 1, count($team->handoffs('task_9')));
assert_eq('无关任务无交接', 0, count($team->handoffs('task_other')));

$team->handoffBack($h, '补好了');
assert_eq('交接链两跳', ['developer → dba', 'dba → developer'], $team->handoffChain('task_9'));
assert_eq('developer 收到交回消息', 1, $team->communication()->unreadCount('developer'));

assert_eq('交给不存在的成员返回 null', null, $team->handoff('developer', 'ghost', 'x'));

$team->reset();
assert_eq('reset 清空交接记录', 0, count($team->handoffs()));

// ===== 四、SessionBus =====

echo "\n=== 四、跨 Session 消息 ===\n";

$busDir = $tmpDir . '/bus';
$bus = new SessionBus($busDir);

test('投递成功', $bus->send('session_main', AgentMessage::status('background', '扫描完成')));
assert_eq('未读 1 条', 1, $bus->pendingCount('session_main'));
test('hasPending', $bus->hasPending('session_main'));

// 另一个进程（新实例）读得到
$bus2 = new SessionBus($busDir);
assert_eq('另一进程看得到', 1, $bus2->pendingCount('session_main'));
$peeked = $bus2->peek('session_main');
assert_eq('peek 不消费', 1, $bus2->pendingCount('session_main'));
assert_eq('消息内容正确', '扫描完成', $peeked[0]->getContent());
assert_eq('发送方正确', 'background', $peeked[0]->getFrom());

$received = $bus2->receive('session_main');
assert_eq('收取 1 条', 1, count($received));
assert_eq('收完即清空', 0, $bus2->pendingCount('session_main'));
assert_eq('原实例也看不到了', 0, $bus->pendingCount('session_main'));

$bus->send('session_main', AgentMessage::status('bg', 'A'));
$bus->send('session_main', AgentMessage::error('bg', 'session_main', 'B 出错'));
$promptText = $bus->toPrompt('session_main');
test('提示词含标签', strpos($promptText, '<session-messages>') === 0);
test('提示词含两条', substr_count($promptText, '[') >= 2);
assert_eq('toPrompt 默认消费', 0, $bus->pendingCount('session_main'));
assert_eq('空收件箱提示词为空', '', $bus->toPrompt('session_main'));

// 订阅（同进程内）
$notified = [];
$bus->subscribe('session_x', function (AgentMessage $m, $sid) use (&$notified) {
    $notified[] = $sid . ':' . $m->getContent();
});
$bus->send('session_x', AgentMessage::status('bg', 'hi'));
assert_eq('订阅回调被触发', ['session_x:hi'], $notified);

$bus->unsubscribe('session_x');
$bus->send('session_x', AgentMessage::status('bg', 'again'));
assert_eq('取消订阅后不再触发', 1, count($notified));

$bus->send('session_y', AgentMessage::status('bg', 'y'));
test('sessions 列出有未读的', in_array('session_y', $bus->sessions(), true));
$bus->clear('session_y');
assert_eq('clear 生效', 0, $bus->pendingCount('session_y'));

// 内存模式
$memBus = new SessionBus();
$memBus->send('s1', AgentMessage::status('a', 'mem'));
assert_eq('内存模式可投递', 1, $memBus->pendingCount('s1'));
assert_eq('内存模式可收取', 1, count($memBus->receive('s1')));
assert_eq('内存模式无目录', '', $memBus->getBaseDir());
test('空 session id 投递失败', !$bus->send('', AgentMessage::status('a', 'x')));

// ===== 五、Plan 版本链 =====

echo "\n=== 五、Plan 版本链 ===\n";

$pm = new PlanManager();
$plan = $pm->createPlan('迁移数据库', ['steps' => ['备份', '改表']]);
assert_eq('初始版本 1', 1, $plan->getVersion());
assert_eq('版本标签', 'plan_v1', $plan->getVersionLabel());
test('摘要含版本号', strpos($plan->toSummary(), 'plan_v1') !== false);

$pm->modifyPlan($plan->getId(), ['append' => ['校验数据']], '发现漏了校验');
assert_eq('修改后版本 2', 2, $pm->getPlan($plan->getId())->getVersion());
assert_eq('步骤数增加', 3, count($pm->getPlan($plan->getId())->getSteps()));

$versions = $pm->versions($plan->getId());
test('v1 快照已保存', isset($versions[1]));
assert_eq('v1 快照仍是两步', 2, count($versions[1]['steps']));

$v1 = $pm->getVersion($plan->getId(), 1);
test('可取回 v1', $v1 !== null);
assert_eq('v1 步骤数不变', 2, count($v1->getSteps()));
$v2 = $pm->getVersion($plan->getId(), 2);
assert_eq('v2 是当前版本', 3, count($v2->getSteps()));
assert_eq('不存在的版本返回 null', null, $pm->getVersion($plan->getId(), 9));

$diff = $pm->diffVersions($plan->getId(), 1, 2);
assert_eq('版本差异：新增', ['校验数据'], $diff['added']);
assert_eq('版本差异：无删除', [], $diff['removed']);
assert_eq('版本差异带原因', '发现漏了校验', $diff['reason']);

$pm->modifyPlan($plan->getId(), ['append' => ['通知运维']], '还要通知');
assert_eq('第三次修改版本 3', 3, $pm->getPlan($plan->getId())->getVersion());
assert_eq('两个历史版本', 2, count($pm->versions($plan->getId())));
assert_eq('修订记录 3 条不含初始', 2, count($pm->getPlan($plan->getId())->getRevisions()));

// ===== 六、VerificationPolicy =====

echo "\n=== 六、验证策略 ===\n";

assert_eq('bugFix 策略名', VerificationPolicy::TYPE_BUG_FIX, VerificationPolicy::bugFix()->getName());
test('feature 含安全检查', in_array('security', VerificationPolicy::feature()->steps(), true));
test('refactor 含改动规模检查', in_array('git_diff', VerificationPolicy::refactor()->steps(), true));
test('security 策略安全必过', VerificationPolicy::security()->isRequired('security'));
test('security 策略测试非必过', !VerificationPolicy::security()->isRequired('unit_test'));
test('未配 required 时全部必过', VerificationPolicy::basic()->isRequired('php_syntax'));

assert_eq('识别安全任务', VerificationPolicy::TYPE_SECURITY, VerificationPolicy::detectType('检查项目的安全漏洞'));
assert_eq('识别 Bug 修复', VerificationPolicy::TYPE_BUG_FIX, VerificationPolicy::detectType('修复登录报错'));
assert_eq('识别重构', VerificationPolicy::TYPE_REFACTOR, VerificationPolicy::detectType('重构认证模块'));
assert_eq('识别新功能', VerificationPolicy::TYPE_FEATURE, VerificationPolicy::detectType('实现 OAuth 登录'));
assert_eq('认不出用默认', VerificationPolicy::TYPE_DEFAULT, VerificationPolicy::detectType('看看这个文件'));
assert_eq('forType 未知返回 basic', VerificationPolicy::TYPE_DEFAULT, VerificationPolicy::forType('nope')->getName());

$custom = new VerificationPolicy('custom', ['steps' => ['php_syntax'], 'failFast' => false]);
test('failFast 可关', !$custom->isFailFast());
$custom->addStep('security');
assert_eq('追加步骤', 2, count($custom->steps()));

// ===== 七、VerificationGate =====

echo "\n=== 七、验证闸门 ===\n";

$goodFile = $tmpDir . '/good.php';
file_put_contents($goodFile, "<?php\nfunction good() { return 1; }\n");
$badFile = $tmpDir . '/bad.php';
file_put_contents($badFile, "<?php\nfunction bad( { }\n");

$vm = new VerificationManager();
$vm->addVerifier(new PhpSyntaxVerifier());
$vm->addVerifier(new SecurityVerifier());

$gateEvents = [];
$gate = new VerificationGate($vm, VerificationPolicy::basic());
$gate->onEvent(function ($e) use (&$gateEvents) { $gateEvents[] = $e['type']; });

$outcome = $gate->check(['tool_name' => 'write_file', 'file_path' => $goodFile]);
test('合法文件放行', $outcome['passed']);
assert_eq('两个步骤都跑了', 2, count($outcome['results']));
assert_eq('放行时无失败项', 0, count($outcome['failed']));
assert_eq('放行时无回填文本', '', $outcome['prompt']);
test('触发 started 事件', in_array('verification_started', $gateEvents, true));
test('触发 passed 事件', in_array('verification_passed', $gateEvents, true));

$outcome = $gate->check(['tool_name' => 'write_file', 'file_path' => $badFile]);
test('语法错误被拦下', !$outcome['passed']);
assert_eq('失败项 1 条', 1, count($outcome['failed']));
test('回填文本含文件与行号', strpos($outcome['prompt'], 'bad.php') !== false);
test('回填文本说明必过', strpos($outcome['prompt'], '必过') !== false);
test('触发 failed 事件', in_array('verification_failed', $gateEvents, true));

// failFast：语法失败后不再跑安全扫描
assert_eq('failFast 生效', 1, count($outcome['results']));

// 策略里的步骤没挂验证器 → 跳过而不是卡住
$gateSkip = new VerificationGate($vm, new VerificationPolicy('x', ['steps' => ['nonexistent', 'php_syntax']]));
$outcome = $gateSkip->check(['file_path' => $goodFile]);
test('缺验证器的步骤被跳过', in_array('nonexistent', $outcome['skipped'], true));
test('其余步骤照常执行', $outcome['passed']);

test('passes() 快捷判断', $gate->passes(['file_path' => $goodFile]));
test('历史已记录', count($gate->history()) >= 3);
test('lastOutcome 可取', $gate->lastOutcome() !== null);

$gate->policyForTask('修复登录报错');
assert_eq('按任务自动选策略', VerificationPolicy::TYPE_BUG_FIX, $gate->getPolicy()->getName());

// ===== 八、CompletionCriteria =====

echo "\n=== 八、完成判据 ===\n";

$criteria = new CompletionCriteria();
assert_eq('默认三条判据', 3, count($criteria->required()));

$outcome = $criteria->evaluate([]);
test('什么都没有时不算完成', !$outcome['completed']);
test('未验证被标为未达成', in_array(CompletionCriteria::VERIFICATION_PASSED, $outcome['unmet'], true));

$outcome = $criteria->evaluate(['verification_passed' => true]);
test('验证通过后达成', $outcome['completed']);

$outcome = $criteria->evaluate(['verification_passed' => false]);
test('验证失败不算完成', !$outcome['completed']);
test('说明验证未通过', strpos($outcome['prompt'], '验证未通过') !== false);

// 计划还有未完成步骤
$pm2 = new PlanManager();
$plan2 = $pm2->createPlan('目标', ['steps' => ['A', 'B']]);
$outcome = $criteria->evaluate(['verification_passed' => true, 'plan' => $plan2]);
test('计划未做完不算完成', !$outcome['completed']);
test('说明剩几步', strpos($outcome['prompt'], '2 个步骤未完成') !== false);

foreach ($plan2->getSteps() as $step) {
    $pm2->completeStep($plan2->getId(), $step->getId());
}
$outcome = $criteria->evaluate(['verification_passed' => true, 'plan' => $pm2->getPlan($plan2->getId())]);
test('计划做完后达成', $outcome['completed']);

// 工具报错
$messagesWithError = [
    ['role' => 'user', 'content' => [
        ['type' => 'tool_result', 'tool_use_id' => 'c1', 'content' => 'Fatal error: 语法错误', 'is_error' => true],
    ]],
];
$outcome = $criteria->evaluate(['verification_passed' => true, 'messages' => $messagesWithError]);
test('有工具报错时不算完成', !$outcome['completed']);
test('带上报错内容', strpos($outcome['prompt'], 'Fatal error') !== false);

$outcome = $criteria->evaluate(['verification_passed' => true, 'errors' => ['e1', 'e2']]);
test('显式错误列表也拦下', !$outcome['completed']);

// 宽松与严格
$lenient = CompletionCriteria::lenient();
test('宽松判据不要求验证', $lenient->isMet([]));
$strict = CompletionCriteria::strict();
assert_eq('严格判据四条', 4, count($strict->required()));
test('严格判据要求模型确认', !$strict->isMet(['verification_passed' => true]));
test('模型确认后达成', $strict->isMet(['verification_passed' => true, 'model_claims_done' => true]));

// 自定义判据
$customCriteria = new CompletionCriteria([CompletionCriteria::NO_PENDING_ERRORS]);
$customCriteria->addCriterion('has_changelog', function (array $context) {
    return ['met' => !empty($context['changelog']), 'reason' => '还没写 changelog'];
});
test('自定义判据未满足', !$customCriteria->isMet([]));
test('自定义判据的理由可读', strpos($customCriteria->evaluate([])['prompt'], 'changelog') !== false);
test('自定义判据满足后达成', $customCriteria->isMet(['changelog' => 'x']));
$customCriteria->remove('has_changelog');
test('移除判据生效', $customCriteria->isMet([]));

// ===== 九、WorkspaceSnapshot =====

echo "\n=== 九、工作区快照 ===\n";

$repo = $tmpDir . '/repo';
@mkdir($repo, 0777, true);
exec('cd ' . escapeshellarg($repo)
    . ' && git init -q && git config user.email t@t && git config user.name t'
    . ' && echo base > a.txt && git add -A && git commit -q -m init 2>&1', $ig, $initCode);

if ($initCode === 0) {
    $before = WorkspaceSnapshot::capture($repo);
    test('识别为 git 仓库', $before->isGitRepo());
    test('拿到分支名', $before->getBranch() !== '');
    test('拿到 commit', strlen($before->getCommit()) >= 8);
    assert_eq('短 commit 8 位', 8, strlen($before->getShortCommit()));
    test('初始工作区干净', $before->isClean());

    file_put_contents($repo . '/a.txt', "base\nchanged\n");
    file_put_contents($repo . '/b.txt', "new file\n");
    $after = WorkspaceSnapshot::capture($repo);

    test('检测到已修改文件', in_array('a.txt', $after->getModifiedFiles(), true));
    test('文件名完整（没被 trim 吃掉首字符）', !in_array('.txt', $after->getModifiedFiles(), true));
    test('检测到未跟踪文件', in_array('b.txt', $after->getUntrackedFiles(), true));
    test('工作区不再干净', !$after->isClean());
    test('diff 哈希变了', $before->getDiffHash() !== $after->getDiffHash());

    $diff = WorkspaceSnapshot::diff($before, $after);
    test('差集：新增', in_array('b.txt', $diff['added'], true));
    test('差集：修改', in_array('a.txt', $diff['modified'], true));
    test('差集：内容变了', $diff['content_changed']);
    test('差集：分支没变', !$diff['branch_changed']);
    test('differsFrom 判断', $after->differsFrom($before));

    $rtSnap = WorkspaceSnapshot::fromArray($after->toArray());
    assert_eq('序列化往返：分支', $after->getBranch(), $rtSnap->getBranch());
    assert_eq('序列化往返：修改文件', $after->getModifiedFiles(), $rtSnap->getModifiedFiles());
    test('摘要可读', strpos($after->toSummary(), '分支') !== false);
} else {
    echo "  （跳过 git 相关用例：git init 失败）\n";
}

$notRepo = WorkspaceSnapshot::capture($tmpDir . '/plain');
test('非 git 目录也能快照', !$notRepo->isGitRepo());
assert_eq('非 git 目录无分支', '', $notRepo->getBranch());

// ===== 十、Worktree merge / discard =====

echo "\n=== 十、Worktree 收尾 ===\n";

list($aiW) = makeAI52();
$samW = new SubAgentManager($aiW);
$samW->setWorkdir($repo);
$samW->setTranscriptDir($tmpDir . '/wt');
BuiltinAgents::register($samW, ['coder']);

assert_eq('未知运行无法合并', 'unknown_run', $samW->mergeWorktreeRun('nope')['reason']);
test('未知运行无法丢弃', !$samW->discardWorktreeRun('nope'));

// 造一条带 diff 的假记录
$fakeDiff = "--- a/a.txt\n+++ b/a.txt\n@@ -1,2 +1,3 @@\n base\n changed\n+from worktree\n";
$reflection = new ReflectionClass($samW);
$runs = $reflection->getProperty('runs');
$runs->setAccessible(true);
$current = $runs->getValue($samW);
$current['sub_fake'] = ['task_id' => 'sub_fake', 'agent' => 'coder', 'status' => 'completed', 'diff' => $fakeDiff];
$runs->setValue($samW, $current);

if ($initCode === 0) {
    $check = $samW->mergeWorktreeRun('sub_fake', true);
    test('试打补丁通过', $check['applied']);
    $applied = $samW->mergeWorktreeRun('sub_fake');
    test('补丁已应用', $applied['applied']);
    test('文件内容已合入', strpos((string) file_get_contents($repo . '/a.txt'), 'from worktree') !== false);
    test('记录标记为已合并', $samW->getTranscript('sub_fake')['merged'] === true);
}

$current = $runs->getValue($samW);
$current['sub_bad'] = ['task_id' => 'sub_bad', 'agent' => 'coder', 'status' => 'completed', 'diff' => ''];
$runs->setValue($samW, $current);
assert_eq('空 diff 无法合并', 'empty_diff', $samW->mergeWorktreeRun('sub_bad')['reason']);

test('丢弃成功', $samW->discardWorktreeRun('sub_bad', '方案不对'));
assert_eq('记录了丢弃原因', '方案不对', $samW->getTranscript('sub_bad')['discard_reason']);

// ===== 十一、Skill 生命周期 =====

echo "\n=== 十一、Skill 生命周期 ===\n";

$skillsDir = $tmpDir . '/skills';
@mkdir($skillsDir . '/deploy', 0777, true);
file_put_contents($skillsDir . '/deploy/SKILL.md', <<<'MD'
---
name: deploy
description: 部署流程
allowed-tools:
  - bash
required-tools:
  - bash
  - ssh
dependencies:
  - docker
---
# 部署

构建 → 上传 → 重启
MD
);

$skillEvents = [];
$sm = new SkillManager();
$sm->onEvent(function ($e) use (&$skillEvents) { $skillEvents[] = $e['type']; });

$sm->discover($skillsDir);
test('触发 discovered 事件', in_array('skill_discovered', $skillEvents, true));
assert_eq('读到 required-tools', ['bash', 'ssh'], $sm->get('deploy')->getRequiredTools());
assert_eq('读到 dependencies', ['docker'], $sm->get('deploy')->getDependencies());

$sm->loadByName('deploy');
test('触发 loaded 事件', in_array('skill_loaded', $skillEvents, true));

$sm->useSkill('deploy');
test('触发 activated 事件', in_array('skill_activated', $skillEvents, true));
test('已激活', $sm->get('deploy')->isActive());
test('合并了 allowed-tools', in_array('bash', $sm->getAllowedTools(), true));

test('停用成功', $sm->deactivate('deploy'));
test('触发 deactivated 事件', in_array('skill_deactivated', $skillEvents, true));
test('停用后不再激活', !$sm->get('deploy')->isActive());
assert_eq('停用后清掉允许工具', 0, count($sm->getAllowedTools()));
test('重复停用返回 false', !$sm->deactivate('deploy'));
test('停用不存在的技能返回 false', !$sm->deactivate('nope'));

$check = $sm->checkRequirements('deploy', ['bash']);
test('依赖不满足', !$check['satisfied']);
test('列出缺失的工具', in_array('ssh', $check['missing'], true));
test('列出缺失的技能依赖', in_array('skill:docker', $check['missing'], true));

$check = $sm->checkRequirements('nope', []);
test('未知技能依赖不满足', !$check['satisfied']);

// ===== 十二、Instruction 就近发现 =====

echo "\n=== 十二、指令就近发现 ===\n";

$proj = $tmpDir . '/proj';
@mkdir($proj . '/src/Admin', 0777, true);
file_put_contents($proj . '/CLAUDE.md', '# 项目规则');
file_put_contents($proj . '/src/AGENTS.md', '# src 规则');
file_put_contents($proj . '/src/Admin/AI.md', '# Admin 规则');

$im = new InstructionManager();
$im->setProjectRoot($proj);
$loaded = $im->discoverFor($proj . '/src/Admin/User.php');

assert_eq('三层规则都加载了', 3, count($loaded));
test('AI.md 被识别', in_array('AI.md', $im->getFilenames(), true));

$prompt = $im->toSystemPrompt();
$posProject = strpos($prompt, '项目规则');
$posAdmin = strpos($prompt, 'Admin 规则');
test('就近的规则排在后面（优先级更高）', $posProject !== false && $posAdmin !== false && $posAdmin > $posProject);

$again = $im->discoverFor($proj . '/src/Admin/User.php');
assert_eq('重复发现不重复加载', 0, count($again));
assert_eq('指令仍是 3 条', 3, count($im->getInstructions()));

$im2 = new InstructionManager();
$im2->setProjectRoot($proj);
$loaded2 = $im2->discoverFor($proj . '/src');
assert_eq('只到 src 层加载两条', 2, count($loaded2));
assert_eq('空路径不加载', 0, count($im2->discoverFor('')));

$im->clear();
assert_eq('clear 后可重新加载', 3, count($im->discoverFor($proj . '/src/Admin/User.php')));

// ===== 十三、MemoryConsolidator =====

echo "\n=== 十三、记忆整理 ===\n";

$memDir = $tmpDir . '/mem';
@mkdir($memDir, 0777, true);
$mm = new MemoryManager($memDir);
$consolidator = new MemoryConsolidator($mm);

test('提出候选', $consolidator->propose('project', '登录走 JWT，密钥在 config/jwt.php', ['confidence' => 0.9]));
test('非法作用域被拒', !$consolidator->propose('nope', 'x'));
test('空内容被拒', !$consolidator->propose('project', '   '));
$consolidator->propose('project', '这条置信度太低不该写入', ['confidence' => 0.2]);
$consolidator->propose('project', '登录走 JWT，密钥在 config/jwt.php', ['confidence' => 0.9]);
assert_eq('候选队列 3 条', 3, count($consolidator->candidates()));

assert_eq('只写入 1 条（去重 + 滤掉低置信度）', 1, $consolidator->consolidate());
assert_eq('整理后候选清空', 0, count($consolidator->candidates()));
test('记忆已写入', strpos($mm->read('project'), 'JWT') !== false);

$consolidator->propose('project', '登录走 JWT，密钥放在 config/jwt.php 里', ['confidence' => 0.9]);
assert_eq('与已有记忆重复的不写', 0, $consolidator->consolidate());

$consolidator->propose('task', '正在修改 Auth.php', ['confidence' => 0.8]);
$consolidator->discard();
assert_eq('discard 后不写入', 0, $consolidator->consolidate());

// 自定义筛选器
$filtered = new MemoryConsolidator($mm);
$filtered->setFilter(function (array $candidate) {
    return strpos($candidate['content'], '密码') === false;
});
$filtered->propose('agent', '数据库密码是 123456', ['confidence' => 0.9]);
$filtered->propose('agent', '这条可以记', ['confidence' => 0.9]);
assert_eq('筛选器挡掉一条', 1, $filtered->consolidate());
test('敏感内容没写进去', strpos($mm->read('agent'), '123456') === false);

// maxPerRun
$capped = new MemoryConsolidator($mm, ['maxPerRun' => 2]);
for ($i = 0; $i < 5; $i++) {
    $capped->propose('session', '条目 ' . $i . ' 内容各不相同 ' . str_repeat('x', $i), ['confidence' => 0.9]);
}
assert_eq('单次最多写 2 条', 2, $capped->consolidate());

// 从反思与结果提候选
$reflection = \Ai\Agent\Reflection\ReflectionResult::completed('登录问题的根因是 token 过期未处理');
$fromRef = new MemoryConsolidator($mm);
test('从反思提候选', $fromRef->proposeFromReflection($reflection));
test('候选带结论标记', strpos($fromRef->candidates()[0]['content'], '[结论]') === 0);

$result = \Ai\Agent\AgentResult::done("这是首段结论\n\n这是第二段应当被丢掉");
test('从结果提候选', $fromRef->proposeFromResult($result));
test('只取首段', strpos($fromRef->candidates()[1]['content'], '第二段') === false);

// ===== 十四、Agent 快捷方法 =====

echo "\n=== 十四、Agent 快捷方法 ===\n";

list($aiA) = makeAI52([textReply52('改完了')]);
$agent = (new Agent($aiA))->setWorkdir($tmpDir);
$agent->addVerifier(new PhpSyntaxVerifier());

$gateA = $agent->useVerificationGate(VerificationPolicy::basic());
test('useVerificationGate 返回闸门', $gateA instanceof VerificationGate);
test('闸门已挂到编排器', $agent->orchestrator()->verificationGate() === $gateA);

$agent->setCompletionCriteria([CompletionCriteria::NO_PENDING_ERRORS]);
assert_eq('判据已设置', 1, count($agent->orchestrator()->criteria()->required()));

$agent->run([['role' => 'user', 'content' => '改一下']]);
assert_eq('记录了消息历史', 1, count($agent->getLastMessages()));

$busA = $agent->sessionBus($tmpDir . '/agent_bus');
test('sessionBus 惰性创建', $busA instanceof SessionBus);
test('sessionBus 复用同一实例', $agent->sessionBus() === $busA);

assert_eq('未设记忆时无整理器', null, $agent->memoryConsolidator());
$agent->setMemoryDir($tmpDir . '/agent_mem');
test('设了记忆后有整理器', $agent->memoryConsolidator() instanceof MemoryConsolidator);

// ===== 清理 =====

exec('rm -rf ' . escapeshellarg($tmpDir));

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);
