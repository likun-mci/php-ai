<?php
namespace Ai\Agent\Task;

use Ai\Agent\AgentRuntime;
use Ai\Agent\AgentResult;

/**
 * TaskManager——任务管理器
 *
 * 管理任务生命周期（queued -> running -> waiting_permission/waiting_user -> paused -> completed/failed/cancelled）。
 * 一个 TaskManager 管理多个任务的创建、调度、暂停、恢复、取消、完成。
 *
 * Task 与 Loop 分离：
 * - TaskManager 负责"整个用户目标"
 * - LoopController 负责"这一轮模型说什么"
 * - 一个 Task 包含多个 Turn
 *
 * 用法：
 * ```php
 * $tm = new TaskManager();
 * $task = $tm->create('修复登录问题', 'sess_abc');
 * $tm->start($task->getId(), $messages);
 * $tm->pause($task->getId());
 * $tm->resume($task->getId(), $messages);
 * $tm->cancel($task->getId());
 * ```
 */
class TaskManager
{
    /** @var array<string, AgentTask> */
    protected $tasks = [];

    /** @var array<string, TaskState> */
    protected $taskStates = [];

    /** @var array<string, AgentRuntime> */
    protected $runtimes = [];

    /**
     * 创建一个新任务
     *
     * @param string $goal 任务目标
     * @param string $sessionId 会话 ID
     * @param string|null $parentTaskId 父任务 ID（子任务用）
     * @param array<string, mixed> $extra 额外数据
     * @return AgentTask
     */
    public function create($goal, $sessionId = '', $parentTaskId = null, array $extra = [])
    {
        $task = new AgentTask(array_merge([
            'goal'         => $goal,
            'sessionId'    => $sessionId,
            'parentTaskId' => $parentTaskId,
            'status'       => TaskStatus::QUEUED,
        ], $extra));

        $this->tasks[$task->getId()] = $task;
        $this->taskStates[$task->getId()] = new TaskState(['goal' => $goal]);

        return $task;
    }

    /**
     * 获取任务
     *
     * @param string $taskId
     * @return AgentTask|null
     */
    public function get($taskId)
    {
        return isset($this->tasks[(string) $taskId]) ? $this->tasks[(string) $taskId] : null;
    }

    /**
     * 获取任务状态
     *
     * @param string $taskId
     * @return TaskState|null
     */
    public function getState($taskId)
    {
        return isset($this->taskStates[(string) $taskId]) ? $this->taskStates[(string) $taskId] : null;
    }

    /**
     * 获取所有任务
     *
     * @return array<string, AgentTask>
     */
    public function all()
    {
        return $this->tasks;
    }

    /**
     * 获取所有活跃任务
     *
     * @return array<string, AgentTask>
     */
    public function activeTasks()
    {
        $active = [];
        foreach ($this->tasks as $id => $task) {
            if ($task->isActive()) {
                $active[$id] = $task;
            }
        }
        return $active;
    }

    /**
     * 获取指定会话的任务列表
     *
     * @param string $sessionId
     * @return array<string, AgentTask>
     */
    public function getSessionTasks($sessionId)
    {
        $sessionId = (string) $sessionId;
        $result = [];
        foreach ($this->tasks as $id => $task) {
            if ($task->getSessionId() === $sessionId) {
                $result[$id] = $task;
            }
        }
        return $result;
    }

    /**
     * 获取子任务列表
     *
     * @param string $parentTaskId
     * @return array<string, AgentTask>
     */
    public function getChildTasks($parentTaskId)
    {
        $parentTaskId = (string) $parentTaskId;
        $result = [];
        foreach ($this->tasks as $id => $task) {
            if ($task->getParentTaskId() === $parentTaskId) {
                $result[$id] = $task;
            }
        }
        return $result;
    }

    /**
     * 开始执行任务（queued -> running）
     *
     * @param string $taskId
     * @param AgentRuntime $runtime
     * @param array<int, array<string, mixed>> $messages
     * @return AgentResult
     */
    public function start($taskId, AgentRuntime $runtime, array $messages)
    {
        $task = $this->get($taskId);
        if ($task === null) {
            return AgentResult::stopped('model_error', '', ['error' => '任务不存在：' . $taskId]);
        }

        // 设置任务状态为 running
        $task->setStatus(TaskStatus::RUNNING);
        $this->runtimes[$taskId] = $runtime;

        // 使用运行时执行
        $result = $runtime->run($messages);

        // 根据结果更新任务状态
        $this->updateTaskStatus($task, $result);

        return $result;
    }

    /**
     * 根据 AgentResult 更新任务状态
     *
     * @param AgentTask $task
     * @param AgentResult $result
     * @return void
     */
    protected function updateTaskStatus(AgentTask $task, AgentResult $result)
    {
        if ($result->isDone()) {
            $task->setStatus(TaskStatus::COMPLETED);
        } elseif ($result->getStopReason() === 'permission_denied') {
            $task->setStatus(TaskStatus::WAITING_PERMISSION);
        } elseif ($result->getStopReason() === 'waiting_user') {
            $task->setStatus(TaskStatus::WAITING_USER);
        } elseif ($result->isError()) {
            $task->setStatus(TaskStatus::FAILED);
        }
    }

    /**
     * 暂停任务（running -> paused）
     *
     * @param string $taskId
     * @return bool
     */
    public function pause($taskId)
    {
        $task = $this->get($taskId);
        if ($task === null) {
            return false;
        }
        // 只有 running/waiting 状态可以暂停
        if (!$task->isRunning() && !$task->isWaitingPermission() && !$task->isWaitingUser()) {
            return false;
        }
        $task->setStatus(TaskStatus::PAUSED);
        return true;
    }

    /**
     * 恢复任务（paused -> running）
     *
     * @param string $taskId
     * @return bool
     */
    public function resume($taskId)
    {
        $task = $this->get($taskId);
        if ($task === null) {
            return false;
        }
        if (!$task->isPaused()) {
            return false;
        }
        $task->setStatus(TaskStatus::RUNNING);
        return true;
    }

    /**
     * 取消任务（any -> cancelled）
     *
     * @param string $taskId
     * @return bool
     */
    public function cancel($taskId)
    {
        $task = $this->get($taskId);
        if ($task === null) {
            return false;
        }
        if ($task->isTerminal()) {
            return false;
        }
        $task->setStatus(TaskStatus::CANCELLED);
        return true;
    }

    /**
     * 标记任务完成（any -> completed）
     *
     * @param string $taskId
     * @return bool
     */
    public function complete($taskId)
    {
        $task = $this->get($taskId);
        if ($task === null) {
            return false;
        }
        $task->setStatus(TaskStatus::COMPLETED);
        return true;
    }

    /**
     * 标记任务失败（any -> failed）
     *
     * @param string $taskId
     * @return bool
     */
    public function fail($taskId)
    {
        $task = $this->get($taskId);
        if ($task === null) {
            return false;
        }
        if ($task->isTerminal()) {
            return false;
        }
        $task->setStatus(TaskStatus::FAILED);
        return true;
    }

    /**
     * 批准权限请求（waiting_permission -> running）
     *
     * @param string $taskId
     * @param string $requestId
     * @param array<int, array<string, mixed>> $messages
     * @return AgentResult|null
     */
    public function approvePermission($taskId, $requestId, array $messages)
    {
        $task = $this->get($taskId);
        if ($task === null) {
            return null;
        }
        $runtime = $this->getRuntime($taskId);
        if ($runtime === null) {
            return null;
        }

        $task->setStatus(TaskStatus::RUNNING);
        $result = $runtime->approve($requestId, $messages);
        $this->updateTaskStatus($task, $result);
        return $result;
    }

    /**
     * 拒绝权限请求（waiting_permission -> failed）
     *
     * @param string $taskId
     * @param string $requestId
     * @param string $reason
     * @param array<int, array<string, mixed>> $messages
     * @return AgentResult|null
     */
    public function denyPermission($taskId, $requestId, $reason, array $messages)
    {
        $task = $this->get($taskId);
        if ($task === null) {
            return null;
        }
        $runtime = $this->getRuntime($taskId);
        if ($runtime === null) {
            return null;
        }

        $task->setStatus(TaskStatus::RUNNING);
        $result = $runtime->deny($requestId, $reason, $messages);
        $this->updateTaskStatus($task, $result);
        return $result;
    }

    /**
     * 回答用户提问（waiting_user -> running）
     *
     * @param string $taskId
     * @param string $answer
     * @param array<int, array<string, mixed>> $messages
     * @return AgentResult|null
     */
    public function answerUser($taskId, $answer, array $messages)
    {
        $task = $this->get($taskId);
        if ($task === null) {
            return null;
        }
        $runtime = $this->getRuntime($taskId);
        if ($runtime === null) {
            return null;
        }

        // 将用户回答追加到消息中，继续执行
        $messages[] = ['role' => 'user', 'content' => $answer];

        $task->setStatus(TaskStatus::RUNNING);
        $result = $runtime->run($messages);
        $this->updateTaskStatus($task, $result);
        return $result;
    }

    /**
     * 获取任务关联的运行时
     *
     * @param string $taskId
     * @return AgentRuntime|null
     */
    public function getRuntime($taskId)
    {
        return isset($this->runtimes[(string) $taskId]) ? $this->runtimes[(string) $taskId] : null;
    }

    /**
     * 删除任务
     *
     * @param string $taskId
     * @return bool
     */
    public function delete($taskId)
    {
        $taskId = (string) $taskId;
        if (!isset($this->tasks[$taskId])) {
            return false;
        }
        unset($this->tasks[$taskId]);
        unset($this->taskStates[$taskId]);
        unset($this->runtimes[$taskId]);
        return true;
    }

    /**
     * 获取任务进度摘要
     *
     * @param string $taskId
     * @return string
     */
    public function getProgress($taskId)
    {
        $task = $this->get($taskId);
        if ($task === null) {
            return '';
        }

        $state = $this->getState($taskId);
        $summary = $state ? $state->toSummary() : '';

        $lines = [];
        $lines[] = '任务 ID：' . $task->getId();
        $lines[] = '目标：' . $task->getGoal();
        $lines[] = '状态：' . $task->getStatus();
        if ($task->getParentTaskId()) {
            $lines[] = '父任务：' . $task->getParentTaskId();
        }
        $lines[] = '';
        if ($summary) {
            $lines[] = $summary;
        }

        return implode("\n", $lines);
    }

    /**
     * 获取任务统计
     *
     * @return array<string, int>
     */
    public function stats()
    {
        $stats = [
            'total'    => count($this->tasks),
            'queued'   => 0,
            'running'  => 0,
            'paused'   => 0,
            'completed' => 0,
            'failed'   => 0,
            'cancelled' => 0,
        ];
        foreach ($this->tasks as $task) {
            $status = $task->getStatus();
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
        }
        return $stats;
    }
}