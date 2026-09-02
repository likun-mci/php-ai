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

    /** @var \Ai\Agent\Task\TaskGraph|null 任务依赖图，挂了之后只调度就绪任务 */
    protected $graph = null;

    /**
     * @param TaskManager|null $tm 任务管理器（可选，需要时自动创建）
     */
    public function __construct($tm = null)
    {
        $this->taskManager = $tm !== null ? $tm : new TaskManager();
    }

    /**
     * 挂上任务依赖图
     *
     * 挂了之后队列只调度「依赖已满足」的任务：`processNext()` 会跳过还在等上游的，
     * 直接取下一个就绪的。不挂则维持先进先出。
     *
     * @param \Ai\Agent\Task\TaskGraph|null $graph
     * @return $this
     */
    public function setGraph($graph)
    {
        $this->graph = $graph instanceof \Ai\Agent\Task\TaskGraph ? $graph : null;
        return $this;
    }

    /**
     * @return \Ai\Agent\Task\TaskGraph|null
     */
    public function getGraph()
    {
        return $this->graph;
    }

    /**
     * 声明任务依赖（自动创建依赖图）
     *
     * @param string $taskId
     * @param string $dependsOn
     * @param string $type hard|soft
     * @return bool 成环或自依赖时返回 false
     */
    public function dependsOn($taskId, $dependsOn, $type = \Ai\Agent\Task\TaskDependency::TYPE_HARD)
    {
        if ($this->graph === null) {
            $this->graph = new \Ai\Agent\Task\TaskGraph();
            $this->graph->syncFrom($this->taskManager);
        }
        return $this->graph->dependsOn($taskId, $dependsOn, $type);
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
        // 走 nextTaskId() 而不是直接 array_shift：挂了依赖图时队首任务可能还没就绪
        $taskId = $this->nextTaskId();
        if ($taskId === null) {
            return null;
        }
        $this->removeFromQueue($taskId);
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

        // 把新状态同步进依赖图：失败会连带把下游标成 blocked，
        // 下一轮调度就不会再去取那些注定跑不起来的任务
        if ($this->graph !== null) {
            $updated = $this->taskManager->get($taskId);
            if ($updated !== null) {
                $this->graph->setStatus($taskId, $updated->getStatus());
            }
        }

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
        $count = count($this->queue);
        for ($i = 0; $i < $count; $i++) {
            $taskId = isset($this->queue[$i]) ? $this->queue[$i] : null;
            if ($taskId === null) {
                break;
            }

            // 跳过已取消/已完成的
            $task = $this->taskManager->get($taskId);
            if ($task !== null && !$task->isQueued()) {
                array_splice($this->queue, $i, 1);
                $i--;
                $count--;
                continue;
            }

            // 挂了依赖图的话，依赖没满足的任务先跳过——它排在队首也跑不起来，
            // 硬跑只会失败一次再回来重排
            if ($this->graph !== null && !$this->graph->isSatisfied($taskId)) {
                continue;
            }
            return $taskId;
        }
        return null;
    }

    /**
     * 从队列里摘掉指定任务
     *
     * @param string $taskId
     * @return void
     */
    protected function removeFromQueue($taskId)
    {
        $index = array_search((string) $taskId, $this->queue, true);
        if ($index !== false) {
            array_splice($this->queue, (int) $index, 1);
        }
    }
}