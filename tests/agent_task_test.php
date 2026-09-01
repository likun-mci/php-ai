<?php
/**
 * Agent Task Runtime 测试（P0-1）
 *
 * 覆盖：
 *   1. TaskStatus 枚举常量
 *   2. AgentTask 值对象（id, goal, status, parentTaskId, sessionId）
 *   3. TaskState 任务状态（completed, pending, blocked, importantFacts, modifiedFiles, errors, subtasks）
 *   4. TaskManager 任务管理器（create, start, pause, resume, cancel, complete, fail）
 *   5. TaskManager 集成 AgentRuntime
 *   6. Agent 通过 setTaskManager() 注入
 *
 * 不联网、不需要 Key。运行：php tests/agent_task_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\AgentRuntime;
use Ai\Agent\AgentResult;
use Ai\Agent\Task\AgentTask;
use Ai\Agent\Task\TaskState;
use Ai\Agent\Task\TaskStatus;
use Ai\Agent\Task\TaskManager;

$failures = [];
function check_t($ok, $name, $detail = '')
{
    global $failures;
    if (!$ok) { $failures[] = $name . ($detail !== '' ? "（{$detail}）" : ''); }
    echo ($ok ? "✓ " : "✗ ") . $name . ($ok ? '' : " — {$detail}") . "\n";
}

// 临时工作目录
$workdir = sys_get_temp_dir() . '/agent_task_' . getmypid();
if (!is_dir($workdir)) { mkdir($workdir, 0755, true); }

// ---------------------------------------------------------------
// 一、TaskStatus 枚举常量
// ---------------------------------------------------------------
echo "=== 一、TaskStatus ===\n\n";

check_t(TaskStatus::QUEUED === 'queued', 'QUEUED 常量');
check_t(TaskStatus::RUNNING === 'running', 'RUNNING 常量');
check_t(TaskStatus::WAITING_PERMISSION === 'waiting_permission', 'WAITING_PERMISSION 常量');
check_t(TaskStatus::WAITING_USER === 'waiting_user', 'WAITING_USER 常量');
check_t(TaskStatus::PAUSED === 'paused', 'PAUSED 常量');
check_t(TaskStatus::COMPLETED === 'completed', 'COMPLETED 常量');
check_t(TaskStatus::FAILED === 'failed', 'FAILED 常量');
check_t(TaskStatus::CANCELLED === 'cancelled', 'CANCELLED 常量');

check_t(count(TaskStatus::all()) === 8, 'all() 返回 8 个状态');
check_t(count(TaskStatus::terminal()) === 3, 'terminal() 返回 3 个最终状态');
check_t(TaskStatus::isTerminal('completed'), 'isTerminal(completed) 为 true');
check_t(TaskStatus::isTerminal('failed'), 'isTerminal(failed) 为 true');
check_t(!TaskStatus::isTerminal('running'), 'isTerminal(running) 为 false');
check_t(TaskStatus::isActive('running'), 'isActive(running) 为 true');
check_t(!TaskStatus::isActive('completed'), 'isActive(completed) 为 false');
check_t(TaskStatus::isValid('queued'), 'isValid(queued) 为 true');
check_t(!TaskStatus::isValid('unknown'), 'isValid(unknown) 为 false');

// ---------------------------------------------------------------
// 二、AgentTask 值对象
// ---------------------------------------------------------------
echo "\n=== 二、AgentTask ===\n\n";

$task = new AgentTask([
    'goal'      => '修复登录问题',
    'sessionId' => 'sess_abc',
]);

check_t($task->getId() !== '', 'getId() 返回非空 ID');
check_t($task->getGoal() === '修复登录问题', 'getGoal() 正确');
check_t($task->getStatus() === TaskStatus::QUEUED, 'getStatus() 默认 queued');
check_t($task->getSessionId() === 'sess_abc', 'getSessionId() 正确');
check_t($task->getParentTaskId() === null, 'getParentTaskId() 默认 null');
check_t($task->isQueued(), 'isQueued() 为 true');
check_t(!$task->isRunning(), 'isRunning() 为 false');
check_t(!$task->isTerminal(), 'isTerminal() 为 false');
check_t($task->isActive(), 'isActive() 为 true');
check_t($task->getCreatedAt() > 0, 'getCreatedAt() 有值');
check_t($task->getUpdatedAt() > 0, 'getUpdatedAt() 有值');

// 状态流转
$task->setStatus(TaskStatus::RUNNING);
check_t($task->isRunning(), 'setStatus(RUNNING) 后 isRunning() 为 true');
check_t(!$task->isQueued(), 'setStatus(RUNNING) 后 isQueued() 为 false');

$task->setStatus(TaskStatus::WAITING_PERMISSION);
check_t($task->isWaitingPermission(), 'WAITING_PERMISSION 状态正确');

$task->setStatus(TaskStatus::WAITING_USER);
check_t($task->isWaitingUser(), 'WAITING_USER 状态正确');

$task->setStatus(TaskStatus::PAUSED);
check_t($task->isPaused(), 'PAUSED 状态正确');

$task->setStatus(TaskStatus::COMPLETED);
check_t($task->isCompleted(), 'COMPLETED 状态正确');
check_t($task->isTerminal(), 'COMPLETED 后 isTerminal() 为 true');

// 父子任务
$parent = new AgentTask(['goal' => '父任务', 'sessionId' => 'sess_1']);
$child = new AgentTask([
    'goal'         => '子任务',
    'sessionId'    => 'sess_1',
    'parentTaskId' => $parent->getId(),
]);
check_t($child->getParentTaskId() === $parent->getId(), '子任务 parentTaskId 指向父任务');

// toArray
$arr = $task->toArray();
check_t(isset($arr['id']) && isset($arr['goal']) && isset($arr['status']), 'toArray() 包含所有字段');

// generateId 唯一性
$id1 = AgentTask::generateId();
$id2 = AgentTask::generateId();
check_t($id1 !== $id2, 'generateId() 生成唯一 ID');

// ---------------------------------------------------------------
// 三、TaskState
// ---------------------------------------------------------------
echo "\n=== 三、TaskState ===\n\n";

$state = new TaskState(['goal' => '修复登录问题']);

$state->addCompleted('找到 Auth.php');
$state->addCompleted('修复 session 判断');
$state->addPending('运行 PHPUnit');
$state->addPending('验证结果');
$state->addModifiedFile('Auth.php');
$state->addImportantFact('session 过期时间设置错误');
$state->addError('PHPUnit 测试未通过');

check_t(count($state->getCompleted()) === 2, 'getCompleted() 返回 2 项');
check_t(count($state->getPending()) === 2, 'getPending() 返回 2 项');
check_t(in_array('Auth.php', $state->getModifiedFiles(), true), '修改文件包含 Auth.php');
check_t($state->getGoal() === '修复登录问题', 'getGoal() 正确');

// completePending
$state->completePending('运行 PHPUnit');
check_t(count($state->getCompleted()) === 3, 'completePending 后 completed 为 3 项');
check_t(count($state->getPending()) === 1, 'completePending 后 pending 为 1 项');
check_t(!in_array('运行 PHPUnit', $state->getPending(), true), '运行 PHPUnit 已从 pending 移除');

// 去重 modifiedFiles
$state->addModifiedFile('Auth.php');
check_t(count($state->getModifiedFiles()) === 1, 'addModifiedFile 去重');

// 子任务
$state->addSubtask('task_child_1', ['status' => 'completed', 'summary' => '完成']);
$state->updateSubtask('task_child_1', ['summary' => '成功完成']);
check_t(count($state->getSubtasks()) === 1, 'addSubtask 添加子任务');
$sub = $state->getSubtasks();
check_t($sub['task_child_1']['summary'] === '成功完成', 'updateSubtask 更新子任务');

// toSummary
$summary = $state->toSummary();
check_t(strpos($summary, '# 任务状态') !== false, 'toSummary() 包含标题');
check_t(strpos($summary, '找到 Auth.php') !== false, 'toSummary() 包含已完成项');
check_t(strpos($summary, '验证结果') !== false, 'toSummary() 包含待处理项');
check_t(strpos($summary, 'session 过期') !== false, 'toSummary() 包含关键发现');

// toArray / toJson / fromJson
$arr = $state->toArray();
check_t($arr['goal'] === '修复登录问题', 'toArray() 包含 goal');
check_t(count($arr['completed']) === 3, 'toArray() 包含 completed');

$json = $state->toJson();
check_t($json !== '', 'toJson() 返回非空');

$state2 = TaskState::fromJson($json);
check_t($state2->getGoal() === '修复登录问题', 'fromJson() 恢复 goal');
check_t(count($state2->getCompleted()) === 3, 'fromJson() 恢复 completed');

// 空构造
$empty = new TaskState();
check_t($empty->getGoal() === '', '空构造 goal 为空');
check_t($empty->getCompleted() === [], '空构造 completed 为空');

// ---------------------------------------------------------------
// 四、TaskManager 生命周期
// ---------------------------------------------------------------
echo "\n=== 四、TaskManager 生命周期 ===\n\n";

$tm = new TaskManager();

// create
$task1 = $tm->create('修复登录问题', 'sess_1');
check_t($task1 !== null, 'create() 返回任务');
check_t($task1->isQueued(), '新任务状态为 queued');
check_t($tm->get($task1->getId()) !== null, 'get() 返回任务');
check_t($tm->getState($task1->getId()) !== null, 'getState() 返回任务状态');

// 同一会话多个任务
$task2 = $tm->create('优化性能', 'sess_1');
$task3 = $tm->create('更新文档', 'sess_2');
check_t(count($tm->all()) === 3, 'all() 返回 3 个任务');
check_t(count($tm->getSessionTasks('sess_1')) === 2, 'getSessionTasks(sess_1) 返回 2 个');
check_t(count($tm->getSessionTasks('sess_2')) === 1, 'getSessionTasks(sess_2) 返回 1 个');

// 父任务
$parent = $tm->create('重构项目', 'sess_3');
$child = $tm->create('重构 Auth 模块', 'sess_3', $parent->getId());
check_t(count($tm->getChildTasks($parent->getId())) === 1, 'getChildTasks 返回子任务');

// pause / resume
$task1->setStatus(TaskStatus::RUNNING);  // 先设为 running，才能 pause
check_t($tm->pause($task1->getId()), 'pause() 返回 true');
check_t($tm->get($task1->getId())->isPaused(), 'pause 后状态为 paused');

check_t($tm->resume($task1->getId()), 'resume() 返回 true');
check_t($tm->get($task1->getId())->isRunning(), 'resume 后状态为 running');

// 不能暂停已完成任务
$tm->complete($task1->getId());
check_t(!$tm->pause($task1->getId()), '已完成任务不能 pause');
check_t(!$tm->cancel($task1->getId()), '已完成任务不能 cancel');

// cancel
$task4 = $tm->create('临时任务', 'sess_4');
check_t($tm->cancel($task4->getId()), 'cancel() 返回 true');
check_t($tm->get($task4->getId())->isCancelled(), 'cancel 后状态为 cancelled');

// fail
$task5 = $tm->create('可能失败的任务', 'sess_5');
check_t($tm->fail($task5->getId()), 'fail() 返回 true');
check_t($tm->get($task5->getId())->isFailed(), 'fail 后状态为 failed');

// 不存在任务
check_t($tm->get('nonexistent') === null, 'get() 不存在返回 null');
check_t($tm->pause('nonexistent') === false, 'pause() 不存在返回 false');
check_t($tm->cancel('nonexistent') === false, 'cancel() 不存在返回 false');

// delete
$task6 = $tm->create('待删除', 'sess_6');
check_t($tm->delete($task6->getId()), 'delete() 返回 true');
check_t($tm->get($task6->getId()) === null, 'delete 后 get() 返回 null');

// stats
$stats = $tm->stats();
check_t($stats['total'] >= 6, 'stats 包含 total');
check_t($stats['completed'] >= 1, 'stats 包含 completed');
check_t($stats['cancelled'] >= 1, 'stats 包含 cancelled');

// getProgress
$progress = $tm->getProgress($task1->getId());
check_t(strpos($progress, $task1->getId()) !== false, 'getProgress 包含任务 ID');
check_t(strpos($progress, $task1->getGoal()) !== false, 'getProgress 包含目标');

// activeTasks
$active = $tm->activeTasks();
check_t(count($active) > 0, 'activeTasks 返回活跃任务');

// ---------------------------------------------------------------
// 五、TaskManager 集成 AgentRuntime
// ---------------------------------------------------------------
echo "\n=== 五、TaskManager 集成 AgentRuntime ===\n\n";

// 假传输层
class TaskScriptedTransport implements \Ai\Contracts\TransportInterface
{
    public $responses = [];
    public $requests  = [];
    public function post(string $url, array $data, array $headers = []): array
    {
        $this->requests[] = $data;
        if (!$this->responses) { return []; }
        return array_shift($this->responses);
    }
    public function get(string $url, array $params = [], array $headers = []): array { return []; }
    public function setTimeout(int $t): \Ai\Contracts\TransportInterface { return $this; }
    public function setProxy(string $p): \Ai\Contracts\TransportInterface { return $this; }
    public function setStreamCallback(?callable $cb): \Ai\Contracts\TransportInterface { return $this; }
}

$tr = new TaskScriptedTransport();
$tr->responses = [
    [
        'choices' => [['message' => ['role' => 'assistant', 'content' => '测试完成。'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    ],
];

$ai = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai->setTransport($tr);

$tm2 = new TaskManager();
$task = $tm2->create('测试任务', 'task_sess_1');

$runtime = new AgentRuntime($ai);
$runtime->setSystem('你好助手');
$result = $tm2->start($task->getId(), $runtime, [['role' => 'user', 'content' => '你好']]);

check_t($result instanceof AgentResult, 'start() 返回 AgentResult');
check_t($result->getText() === '测试完成。', '集成 Agent 运行正常');
check_t($tm2->get($task->getId())->isCompleted(), '任务自动标记为 completed');

// 验证 getRuntime
check_t($tm2->getRuntime($task->getId()) !== null, 'getRuntime() 返回运行时');

// ---------------------------------------------------------------
// 六、Agent 通过 setTaskManager 注入
// ---------------------------------------------------------------
echo "\n=== 六、Agent 注入 TaskManager ===\n\n";

$tr2 = new TaskScriptedTransport();
$tr2->responses = [
    [
        'choices' => [['message' => ['role' => 'assistant', 'content' => '注入成功。'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8],
    ],
];

$ai2 = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai2->setTransport($tr2);

$tm3 = new TaskManager();
$task3 = $tm3->create('Agent 注入测试', 'agent_sess_1');

$agent = new Agent($ai2);
$events = [];
$agent
    ->setSystem('助手')
    ->setTaskManager($tm3)
    ->setTaskId($task3->getId())
    ->onEvent(function ($e) use (&$events) { $events[] = $e['type']; });

$agent->run([['role' => 'user', 'content' => '测试']]);

check_t($agent->lastText() === '注入成功。', 'Agent 注入 TaskManager 正常运行');
check_t($tm3->get($task3->getId())->isCompleted(), 'Agent 完成后任务标记为 completed');

// 验证事件序列包含 task 事件
check_t(in_array('task_start', $events, true), '触发 task_start 事件');
check_t(in_array('task_complete', $events, true), '触发 task_complete 事件');

// 验证 Agent 上的快捷方法
$agent2 = new Agent($ai2);
$agent2->setTaskManager($tm3);
check_t($agent2->getRuntime()->getTaskManager() !== null, 'Agent->getRuntime()->getTaskManager() 返回管理器');

// 清理
foreach (glob($workdir . '/*') as $f) { @unlink($f); }
@rmdir($workdir);

echo "\n", str_repeat('=', 60), "\n";
if ($failures) {
    echo count($failures) . " 项未通过：\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    exit(1);
}
echo "全部通过：Agent Task Runtime P0-1 工作正常\n";
exit(0);