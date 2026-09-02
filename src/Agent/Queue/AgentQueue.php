<?php
namespace Ai\Agent\Queue;

use Ai\Agent\AgentRuntime;
use Ai\Agent\AgentResult;
use Ai\Agent\Task\AgentTask;
use Ai\Agent\Task\TaskManager;
use Ai\Agent\Task\TaskStatus;

/**
 * AgentQueue——Agent 任务队列
 *
 * 将任务（Task）+ 运行时（AgentRuntime）按入队顺序排队执行。
 * 适用场景：PHP-FPM 下把任务投递进队列，由 CLI Worker / 常驻进程消费；
 * Swoole / Workerman 下则由 worker 协程逐个处理。
 *
 * 用法：
 * ```php
 * $queue = new AgentQueue($taskManager);
 * $queue->dispatch('检查并修复整个项目', $messages, $runtime);
 *
 * // Worker 循环中
 * while ($queue->hasPending()) {
 *     $result = $queue->processNext();
 * }
 *
 * // 或处理指定任务
 * $result = $queue->process('task_xxx');
 *
 * // 控制
 * $task = $queue->get('task_xxx');
 * echo $task->getStatus();      // running / completed / ...
 * $queue->cancel('task_xxx');
 * $queue->resume('task_xxx');
 * ```
 */
class AgentQueue
{
    /** @var TaskManager */
    protected $taskManager;

    /** @var string[] 等待执行的任务 ID（FIFO） */
    protected $queue = [];

    /** @var array<string, AgentRuntime> 任务 ID => 运行时 */
    protected $runtimes = [];

    /** @var array<string, AgentResult> 已完成任务的结果 */
    protected $results = [];

    /** @var array<string, array<int, array<string, mixed>>> 每个任务的入队消息 */
    protected $messages = [];

    /** @var bool 自动将 done/failed 任务从队列移除 */
    protected $autoDequeue = false;

    /**
     * @param TaskManager|null $tm 任务管理器（可选，需要时自动创建）
     */
    public function __construct($tm = null)
    {
        $this->taskManager = $tm !== null ? $tm : new TaskManager();
    }

    /**
     * @return TaskManager
     */
    public function getTaskManager()
    {
        return $this->taskManager;
    }

    /**
     * 投递一个任务入队
     *
     * @param string $goal 任务目标
     * @param AgentRuntime $runtime 执行任务的运行时
     * @param array<int, array<string, mixed>> $messages 初始消息
     * @param string $sessionId 会话 ID
     * @return AgentTask
     */
    public function dispatch($goal, AgentRuntime $runtime, array $messages = [], $sessionId = '')
    {
        $task = $this->taskManager->create($goal, $sessionId);
        $taskId = $task->getId();

        $this->queue[] = $taskId;
        $this->runtimes[$taskId] = $runtime;
        $this->messages[$taskId] = $messages;

        return $task;
    }

    /**
     * 取下一个待执行任务
     *
     * @return AgentTask|null
     */
    public function next()
    {
        $taskId = $this->nextTaskId();
        if ($taskId === null) {
            return null;
        }
        return $this->taskManager->get($taskId);
    }

    /**
     * 处理下一个待执行任务（Worker 主循环）
     *
     * @return AgentResult|null 无任务时返回 null
     */
    public function processNext()
    {
        $taskId = array_shift($this->queue);
        if ($taskId === null) {
            return null;
        }
        return $this->process($taskId);
    }

    /**
     * 处理指定任务
     *
     * @param string $taskId
     * @return AgentResult|null 任务不存在返回 null
     */
    public function process($taskId)
    {
        $taskId = (string) $taskId;
        $task = $this->taskManager->get($taskId);
        if ($task === null) {
            return null;
        }
        $runtime = isset($this->runtimes[$taskId]) ? $this->runtimes[$taskId] : null;
        if ($runtime === null) {
            return null;
        }
        $messages = isset($this->messages[$taskId]) ? $this->messages[$taskId] : [];

        $result = $this->taskManager->start($taskId, $runtime, $messages);
        $this->results[$taskId] = $result;

        return $result;
    }

    /**
     * 是否还有待执行任务
     *
     * @return bool
     */
    public function hasPending()
    {
        return count($this->queue) > 0;
    }

    /**
     * 待执行任务数量
     *
     * @return int
     */
    public function pendingCount()
    {
        return count($this->queue);
    }

    /**
     * 获取任务
     *
     * @param string $taskId
     * @return AgentTask|null
     */
    public function get($taskId)
    {
        return $this->taskManager->get((string) $taskId);
    }

    /**
     * 任务最新结果
     *
     * @param string $taskId
     * @return AgentResult|null
     */
    public function result($taskId)
    {
        $taskId = (string) $taskId;
        return isset($this->results[$taskId]) ? $this->results[$taskId] : null;
    }

    /**
     * 取消任务（queued / running -> cancelled）
     *
     * @param string $taskId
     * @return bool
     */
    public function cancel($taskId)
    {
        $taskId = (string) $taskId;
        // 从待执行队列移除
        $idx = array_search($taskId, $this->queue, true);
        if ($idx !== false) {
            array_splice($this->queue, (int) $idx, 1);
        }
        return $this->taskManager->cancel($taskId);
    }

    /**
     * 恢复任务（paused -> queued）
     *
     * @param string $taskId
     * @return bool
     */
    public function resume($taskId)
    {
        $taskId = (string) $taskId;
        $task = $this->taskManager->get($taskId);
        if ($task === null) {
            return false;
        }
        if (!$task->isPaused()) {
            return false;
        }
        // 重新入队
        if (!in_array($taskId, $this->queue, true)) {
            $this->queue[] = $taskId;
        }
        return $this->taskManager->resume($taskId);
    }

    /**
     * 全部任务
     *
     * @return array<string, AgentTask>
     */
    public function all()
    {
        return $this->taskManager->all();
    }

    /**
     * 下一个待执行任务的 ID（不消费）
     *
     * @return string|null
     */
    protected function nextTaskId()
    {
        $taskId = isset($this->queue[0]) ? $this->queue[0] : null;
        if ($taskId === null) {
            return null;
        }
        // 跳过已取消/已完成的
        $task = $this->taskManager->get($taskId);
        if ($task !== null && !$task->isQueued()) {
            array_shift($this->queue);
            return $this->nextTaskId();
        }
        return $taskId;
    }
}