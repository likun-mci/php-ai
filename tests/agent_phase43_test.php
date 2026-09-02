<?php
/**
 * Phase 4.3 测试——多角色团队 / 长任务检查点 / 人工审批
 *
 * 覆盖：
 *   1. AgentRole 内置角色与自定义角色
 *   2. AgentMessage 五种类型与提示词转换
 *   3. AgentCommunication 收件箱、广播、历史、过滤
 *   4. AgentTeam 成员管理、分派、流水线、工具按角色收窄
 *   5. 检查点扩展保存 plan / goal / workspace，recover 还原
 *   6. ApprovalRequest 状态机与过期
 *   7. ApprovalWorkflow 提交 / 批准 / 驳回 / 落盘跨进程可见
 *   8. Agent 快捷方法
 *
 * 不联网、不需要 Key。运行：php tests/agent_phase43_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\AgentRuntime;
use Ai\Agent\Approval\ApprovalRequest;
use Ai\Agent\Approval\ApprovalWorkflow;
use Ai\Agent\Checkpoint\CheckpointManager;
use Ai\Agent\Planning\Plan;
use Ai\Agent\Planning\PlanManager;
use Ai\Agent\Team\AgentCommunication;
use Ai\Agent\Team\AgentMessage;
use Ai\Agent\Team\AgentRole;
use Ai\Agent\Team\AgentTeam;

/** 按顺序回放预置响应的假传输层 */
class ScriptedTransport43 implements \Ai\Contracts\TransportInterface
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

function textReply43($text)
{
    return ['choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']]];
}

$tmpDir = sys_get_temp_dir() . '/php_ai_p43_' . getmypid();
@mkdir($tmpDir, 0777, true);

// ===== 一、AgentRole =====

echo "\n=== 一、AgentRole ===\n";

$dev = AgentRole::developer();
assert_eq('开发角色名', AgentRole::DEVELOPER, $dev->getName());
test('开发角色有提示词', $dev->getPrompt() !== '');
test('测试角色提示词强调不改实现', strpos(AgentRole::tester()->getPrompt(), '不要自己去改实现') !== false);
test('安全角色提示词要求行号', strpos(AgentRole::security()->getPrompt(), '行号') !== false);
assert_eq('内置角色共 5 个', 5, count(AgentRole::defaults()));

$custom = new AgentRole('dba', [
    'description' => '数据库结构',
    'tools'       => ['read_file', 'bash'],
    'maxIter'     => 5,
]);
assert_eq('自定义角色名', 'dba', $custom->getName());
assert_eq('自定义工具集', ['read_file', 'bash'], $custom->getTools());
assert_eq('自定义最大迭代', 5, $custom->getMaxIter());
assert_eq('没写 prompt 时退回描述', '数据库结构', $custom->getPrompt());

$overridden = AgentRole::developer(['maxIter' => 3, 'prompt' => '自定义提示']);
assert_eq('内置角色可覆盖 maxIter', 3, $overridden->getMaxIter());
assert_eq('内置角色可覆盖 prompt', '自定义提示', $overridden->getPrompt());

// ===== 二、AgentMessage =====

echo "\n=== 二、AgentMessage ===\n";

$bug = AgentMessage::bug('tester', 'developer', 'testLogin 失败', ['file' => 'AuthTest.php', 'line' => 42]);
assert_eq('消息类型', AgentMessage::TYPE_BUG, $bug->getType());
assert_eq('发送者', 'tester', $bug->getFrom());
assert_eq('接收者', 'developer', $bug->getTo());
test('非广播', !$bug->isBroadcast());
assert_eq('元数据可读', 42, $bug->meta('line'));
assert_eq('缺省元数据', 'x', $bug->meta('nope', 'x'));
test('消息 ID 非空', $bug->getId() !== '');

$prompt = $bug->toPrompt();
test('提示词含类型', strpos($prompt, '[BUG') === 0);
test('提示词含发送者', strpos($prompt, 'tester') !== false);
test('提示词含内容', strpos($prompt, 'testLogin 失败') !== false);
test('提示词含元数据', strpos($prompt, 'AuthTest.php') !== false);

$status = AgentMessage::status('manager', '需求冻结');
test('状态消息是广播', $status->isBroadcast());
assert_eq('五种类型', 5, count(AgentMessage::validTypes()));
test('非法类型退回 status', (new AgentMessage('a', 'b', 'nope', 'x'))->getType() === AgentMessage::TYPE_STATUS);

foreach (['task', 'review', 'result'] as $type) {
    $msg = AgentMessage::$type('a', 'b', 'x');
    assert_eq("工厂方法 {$type}", $type, $msg->getType());
}

// ===== 三、AgentCommunication =====

echo "\n=== 三、AgentCommunication ===\n";

$bus = new AgentCommunication();
$bus->addMember('developer')->addMember('tester')->addMember('manager');
assert_eq('三个成员', ['developer', 'tester', 'manager'], $bus->members());

$bus->send(AgentMessage::task('manager', 'developer', '实现登录'));
assert_eq('定向消息只进目标收件箱', 1, $bus->unreadCount('developer'));
assert_eq('其他人收件箱为空', 0, $bus->unreadCount('tester'));

$bus->broadcast(AgentMessage::status('manager', '需求冻结'));
assert_eq('广播进了 developer', 2, $bus->unreadCount('developer'));
assert_eq('广播进了 tester', 1, $bus->unreadCount('tester'));
assert_eq('广播不回给发送者', 0, $bus->unreadCount('manager'));

$peeked = $bus->peek('tester');
assert_eq('peek 不清空', 1, $bus->unreadCount('tester'));
$taken = $bus->inbox('tester');
assert_eq('inbox 取走 1 条', 1, count($taken));
assert_eq('inbox 取走后清空', 0, $bus->unreadCount('tester'));

$bus->send(AgentMessage::bug('tester', 'developer', '登录报 401'));
$inboxPrompt = $bus->inboxPrompt('developer');
test('收件箱提示词含标签', strpos($inboxPrompt, '<team-messages>') === 0);
test('收件箱提示词含全部消息', substr_count($inboxPrompt, '[') >= 3);
assert_eq('inboxPrompt 默认取走', 0, $bus->unreadCount('developer'));
assert_eq('空收件箱返回空串', '', $bus->inboxPrompt('developer'));

assert_eq('历史保留全部消息', 3, count($bus->history()));
assert_eq('按类型过滤历史', 1, count($bus->history(AgentMessage::TYPE_BUG)));
assert_eq('between 查往来', 2, count($bus->between('manager', 'developer')) + count($bus->between('tester', 'developer')));

$logged = [];
$bus->onMessage(function (AgentMessage $m) use (&$logged) { $logged[] = $m->getType(); });
$bus->send(AgentMessage::review('reviewer', 'developer', '这里缺校验'));
assert_eq('钩子被调用', ['review'], $logged);
test('给未登记角色发消息不丢', $bus->unreadCount('developer') === 1);

$bus->removeMember('tester');
test('移除成员', !in_array('tester', $bus->members(), true));
$bus->clear();
assert_eq('clear 清空历史', 0, count($bus->history()));

// ===== 四、AgentTeam =====

echo "\n=== 四、AgentTeam ===\n";

$tr = new ScriptedTransport43();
$tr->responses = [
    textReply43('已实现登录接口'),
    textReply43('补了 3 个测试用例，全部通过'),
    textReply43('代码没问题'),
];
$ai = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai->setTransport($tr);

$events = [];
$team = new AgentTeam($ai, ['system' => '项目是一个 PHP 库']);
$team->onEvent(function ($e) use (&$events) { $events[] = $e['type']; });
$team->addMember(AgentRole::developer())
     ->addMember(AgentRole::tester())
     ->addMember('reviewer');

assert_eq('三名成员', ['developer', 'tester', 'reviewer'], $team->members());
test('按名加入取到内置角色', $team->getRole('reviewer')->getPrompt() !== '');
test('getMember 返回运行时', $team->getMember('developer') instanceof AgentRuntime);
assert_eq('取不存在的成员', null, $team->getMember('ghost'));
test('has 判断成员', $team->has('tester') && !$team->has('ghost'));

$record = $team->assign('developer', '实现登录接口');
assert_eq('分派状态', 'completed', $record['status']);
assert_eq('分派结果文本', '已实现登录接口', $record['text']);
assert_eq('记录了角色', 'developer', $record['role']);
test('记录了耗时', isset($record['duration_ms']));

$ghost = $team->assign('ghost', '干活');
assert_eq('不存在的角色返回 failed', 'failed', $ghost['status']);
assert_eq('原因是 unknown_role', 'unknown_role', $ghost['reason']);

$results = $team->pipeline('给 Auth 补测试', ['tester', 'reviewer']);
assert_eq('流水线两环', 2, count($results));
assert_eq('第二环拿到第一环结果', 'tester', $results[0]['role']);

// 第二环的 prompt 里应带上一环结果
$lastRequest = $tr->requests[count($tr->requests) - 1];
$body = json_encode($lastRequest, JSON_UNESCAPED_UNICODE);
test('流水线把上一环结果传下去', strpos($body, '上一环的结果') !== false);

test('触发了 team_assign 事件', in_array('team_assign', $events, true));
test('触发了 team_result 事件', in_array('team_result', $events, true));

assert_eq('结果记录累计', 4, count($team->getResults()));
test('lastResult 是最后一条', $team->lastResult()['role'] === 'reviewer');
$summary = $team->toSummary();
test('摘要含角色', strpos($summary, '[developer]') !== false);
test('摘要含状态', strpos($summary, 'completed') !== false);

// 工具按角色收窄
$teamTools = new AgentTeam($ai, [
    'tools' => [
        'read_file'  => ['description' => '读', 'input_schema' => ['type' => 'object'], 'handler' => function () { return ''; }],
        'write_file' => ['description' => '写', 'input_schema' => ['type' => 'object'], 'handler' => function () { return ''; }],
        'bash'       => ['description' => '跑', 'input_schema' => ['type' => 'object'], 'handler' => function () { return ''; }],
    ],
]);
$teamTools->addMember(new AgentRole('readonly', ['tools' => ['read_file']]));
$teamTools->addMember(new AgentRole('full'));
assert_eq('限制工具的角色只拿到 1 个', 1, count($teamTools->getMember('readonly')->getToolRegistry()->all()));
assert_eq('不限制的角色拿到全部', 3, count($teamTools->getMember('full')->getToolRegistry()->all()));

$team->reset();
assert_eq('reset 清空结果', 0, count($team->getResults()));
$team->removeMember('reviewer');
test('移除团队成员', !$team->has('reviewer'));

// ===== 五、长任务检查点 =====

echo "\n=== 五、长任务检查点 ===\n";

$cpDir = $tmpDir . '/checkpoints';
$tr2 = new ScriptedTransport43();
// 检查点在工具执行后的每轮迭代末尾保存，所以要让模型真的调一次工具
$tr2->responses = [
    ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
        'id' => 'c1', 'type' => 'function',
        'function' => ['name' => 'noop', 'arguments' => '{}'],
    ]]], 'finish_reason' => 'tool_calls']]],
    textReply43('第一步做完了'),
];
$ai2 = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai2->setTransport($tr2);

$cpm = new CheckpointManager($cpDir);
$pm = new PlanManager();
$plan = $pm->createPlan('迁移数据库', ['steps' => ['备份', '改表', '校验']]);
$pm->start($plan->getId());

$rt = new AgentRuntime($ai2);
$rt->setTools([
        'noop' => [
            'description'  => '什么都不做',
            'input_schema' => ['type' => 'object'],
            'handler'      => function (array $in) { return 'ok'; },
        ],
    ])
   ->setSystem('助手')
   ->setCheckpointManager($cpm)
   ->setTaskId('task-long-1')
   ->setPlanManager($pm)
   ->setPlanId($plan->getId())
   ->setGoal('迁移数据库')
   ->setWorkdir($tmpDir);
$rt->run([['role' => 'user', 'content' => '开始']]);

$saved = $cpm->loadLatest('task-long-1');
test('检查点已保存', $saved !== null);
$extra = $saved->getExtra();
assert_eq('检查点保存了目标', '迁移数据库', $extra['goal']);
test('检查点保存了计划', isset($extra['plan']['id']) && $extra['plan']['id'] === $plan->getId());
assert_eq('计划步骤一并保存', 3, count($extra['plan']['steps']));
test('检查点保存了工作区', isset($extra['workspace']['dir']));

// 新进程恢复
$ai3 = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai3->setTransport(new ScriptedTransport43());
$rt2 = new AgentRuntime($ai3);
$rt2->setCheckpointManager(new CheckpointManager($cpDir));

$messages = $rt2->recover('task-long-1');
test('恢复出消息历史', is_array($messages) && count($messages) > 0);
assert_eq('恢复了目标', '迁移数据库', $rt2->getGoal());
assert_eq('恢复了计划 ID', $plan->getId(), $rt2->getPlanId());

$restoredPlan = $rt2->getPlanManager()->getPlan($plan->getId());
test('恢复出 Plan 对象', $restoredPlan instanceof Plan);
assert_eq('恢复后目标一致', '迁移数据库', $restoredPlan->getGoal());
assert_eq('恢复后步骤数一致', 3, count($restoredPlan->getSteps()));
assert_eq('恢复后状态一致', Plan::STATUS_RUNNING, $restoredPlan->getStatus());
test('getLastCheckpoint 可取', $rt2->getLastCheckpoint() !== null);

$rt3 = new AgentRuntime($ai3);
assert_eq('无检查点管理器时恢复返回 null', null, $rt3->recover('task-long-1'));

// ===== 六、ApprovalRequest =====

echo "\n=== 六、ApprovalRequest ===\n";

$req = new ApprovalRequest('req_1', [
    'summary' => '修改登录逻辑',
    'diff'    => "--- a/src/Auth.php\n+++ b/src/Auth.php\n+新增校验",
    'files'   => ['src/Auth.php'],
]);
assert_eq('初始状态', ApprovalRequest::STATUS_PENDING, $req->getStatus());
test('初始为待审批', $req->isPending());
test('未批准', !$req->isApproved());
test('未驳回', !$req->isRejected());

test('批准成功', $req->approve('张三'));
assert_eq('批准后状态', ApprovalRequest::STATUS_APPROVED, $req->getStatus());
assert_eq('记录审批人', '张三', $req->getReviewer());
test('记录处理时间', $req->getDecidedAt() > 0);
test('重复批准失败', !$req->approve('李四'));
test('批准后不能再驳回', !$req->reject('理由'));

$req2 = new ApprovalRequest('req_2', ['summary' => '删表', 'files' => ['db.sql']]);
test('驳回成功', $req2->reject('缺少备份步骤', '李四'));
assert_eq('驳回后状态', ApprovalRequest::STATUS_REJECTED, $req2->getStatus());
assert_eq('记录驳回理由', '缺少备份步骤', $req2->getReason());

$rejectionPrompt = $req2->toRejectionPrompt();
test('驳回提示词含理由', strpos($rejectionPrompt, '缺少备份步骤') !== false);
test('驳回提示词含文件', strpos($rejectionPrompt, 'db.sql') !== false);
assert_eq('未驳回的请求无驳回提示', '', $req->toRejectionPrompt());

$expired = new ApprovalRequest('req_3', ['summary' => 'x', 'expiresAt' => time() - 10]);
assert_eq('过期请求状态为 expired', ApprovalRequest::STATUS_EXPIRED, $expired->getStatus());
test('过期请求不能批准', !$expired->approve('张三'));
test('isExpired 为真', $expired->isExpired());

$summaryText = $req->toSummary();
test('摘要含 ID', strpos($summaryText, 'req_1') !== false);
test('摘要含文件', strpos($summaryText, 'src/Auth.php') !== false);
test('摘要含 diff', strpos($summaryText, '新增校验') !== false);
test('diffLimit 为 0 时不显示 diff', strpos($req->toSummary(0), '新增校验') === false);

$roundTrip = ApprovalRequest::fromArray($req->toArray());
assert_eq('序列化往返保留状态', ApprovalRequest::STATUS_APPROVED, $roundTrip->getStatus());
assert_eq('序列化往返保留审批人', '张三', $roundTrip->getReviewer());

// ===== 七、ApprovalWorkflow =====

echo "\n=== 七、ApprovalWorkflow ===\n";

$approvalDir = $tmpDir . '/approvals';
$notified = [];
$workflow = new ApprovalWorkflow($approvalDir, [
    'notifier' => function (ApprovalRequest $r) use (&$notified) { $notified[] = $r->getId(); },
]);

$request = $workflow->submitForReview("--- a/x\n+++ b/x\n+改动", [
    'summary' => '修复登录',
    'files'   => ['src/Auth.php'],
    'task_id' => 'task-1',
]);
test('提交返回请求对象', $request instanceof ApprovalRequest);
assert_eq('提交后为待审批', ApprovalRequest::STATUS_PENDING, $request->getStatus());
assert_eq('通知钩子被调用', 1, count($notified));
test('请求已落盘', count(glob($approvalDir . '/req_*.json')) === 1);
test('上下文被保留', $request->getContext()['task_id'] === 'task-1');

assert_eq('待审批列表 1 条', 1, count($workflow->getPendingRequests()));
assert_eq('getStatus 查询', ApprovalRequest::STATUS_PENDING, $workflow->getStatus($request->getId()));
assert_eq('不存在的请求状态为空', '', $workflow->getStatus('req_nope'));

// 模拟另一个进程批准
$other = new ApprovalWorkflow($approvalDir);
assert_eq('另一进程看得到待审批', 1, count($other->getPendingRequests()));
test('另一进程批准成功', $other->approve($request->getId(), '张三'));

// 原进程重新查询应看到最新状态
assert_eq('跨进程状态可见', ApprovalRequest::STATUS_APPROVED, $workflow->getStatus($request->getId()));
assert_eq('批准后不在待审批列表', 0, count($workflow->getPendingRequests()));
assert_eq('审批人跨进程可见', '张三', $workflow->getRequest($request->getId())->getReviewer());

$req2w = $workflow->submitForReview('diff2', ['summary' => '删表']);
test('驳回成功', $workflow->reject($req2w->getId(), '太危险', '李四'));
assert_eq('驳回状态', ApprovalRequest::STATUS_REJECTED, $workflow->getStatus($req2w->getId()));
test('重复处理返回 false', !$workflow->approve($req2w->getId(), '张三'));
test('处理不存在的请求返回 false', !$workflow->approve('req_nope'));

assert_eq('按状态筛选', 1, count($workflow->allRequests(ApprovalRequest::STATUS_APPROVED)));
assert_eq('全部请求', 2, count($workflow->allRequests()));

$purged = $workflow->purgeDecided();
assert_eq('清理掉两条已处理', 2, $purged);
assert_eq('清理后无请求', 0, count($workflow->allRequests()));

// waitFor：已处理的请求立即返回
$req3w = $workflow->submitForReview('diff3', ['summary' => 'x']);
$workflow->approve($req3w->getId());
assert_eq('waitFor 立即返回已处理状态',
    ApprovalRequest::STATUS_APPROVED, $workflow->waitFor($req3w->getId(), 2, 100));

// 内存模式
$memory = new ApprovalWorkflow();
$memReq = $memory->submitForReview('diff', ['summary' => '内存模式']);
assert_eq('内存模式可提交', ApprovalRequest::STATUS_PENDING, $memory->getStatus($memReq->getId()));
test('内存模式可批准', $memory->approve($memReq->getId()));
assert_eq('内存模式不落盘', '', $memory->getBaseDir());

// 自动过审（开发环境）
$auto = new ApprovalWorkflow('', ['autoApprove' => true]);
$autoReq = $auto->submitForReview('diff', ['summary' => 'x']);
assert_eq('自动过审直接批准', ApprovalRequest::STATUS_APPROVED, $autoReq->getStatus());
assert_eq('自动过审记录 auto', 'auto', $autoReq->getReviewer());

// TTL
$ttlFlow = new ApprovalWorkflow('', ['ttl' => 3600]);
$ttlReq = $ttlFlow->submitForReview('diff', ['summary' => 'x']);
test('设置了过期时间', $ttlReq->getExpiresAt() > time());

// ===== 八、Agent 快捷方法 =====

echo "\n=== 八、Agent 快捷方法 ===\n";

$aiX = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$aiX->setTransport(new ScriptedTransport43());
$agent = new Agent($aiX);
$agent->setTools([
    'read_file' => ['description' => '读', 'input_schema' => ['type' => 'object'], 'handler' => function () { return ''; }],
]);
$agent->setWorkdir($tmpDir);

$teamX = $agent->team([AgentRole::developer(), 'tester']);
test('team() 返回团队', $teamX instanceof AgentTeam);
assert_eq('团队成员', ['developer', 'tester'], $teamX->members());
assert_eq('团队继承了 Agent 的工具', 1, count($teamX->getMember('developer')->getToolRegistry()->all()));

$flow = $agent->enableApproval($tmpDir . '/agent_approvals');
test('enableApproval 返回工作流', $flow instanceof ApprovalWorkflow);
test('工作流已挂到运行时', $agent->getRuntime()->getApprovalWorkflow() === $flow);

$submitted = $agent->submitForApproval('diff', ['summary' => '通过 Agent 提交']);
test('通过 Agent 提交审批', $submitted instanceof ApprovalRequest);
assert_eq('提交的摘要', '通过 Agent 提交', $submitted->getSummary());

$agentNoFlow = new Agent($aiX);
assert_eq('未启用审批时提交返回 null', null, $agentNoFlow->submitForApproval('diff'));

// ===== 清理 =====

exec('rm -rf ' . escapeshellarg($tmpDir));

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);
