<?php
namespace Ai\Agent\Orchestrator;

use Ai\Agent\Task\TaskGraph;

/**
 * AgentScheduler——任务调度与并发上限
 *
 * 决定「下一个跑哪个」以及「同时最多跑几个」。**一个 PHP 请求不该产生几十个 Agent**：
 * 每个子 Agent 都是独立的模型调用与上下文，失控的并发既烧钱又会把内存吃满。
 *
 * ```php
 * $scheduler = new AgentScheduler([
 *     'max_tasks'             => 20,
 *     'max_subagents'         => 4,
 *     'max_background_agents' => 2,
 *     'max_parallel_tools'    => 8,
 * ]);
 *
 * $scheduler->submit('task_1', '扫描安全问题', AgentScheduler::PRIORITY_HIGH);
 * $scheduler->submit('task_2', '更新文档', AgentScheduler::PRIORITY_LOW);
 *
 * $next = $scheduler->next();       // 'task_1' —— 高优先级先跑
 * $scheduler->start($next);
 * $scheduler->finish($next, true);  // 成功
 * ```
 *
 * 挂上 `TaskGraph` 之后，只有依赖已满足的任务才会被调度出来。
 */
class AgentScheduler
{
    const PRIORITY_CRITICAL = 'critical';
    const PRIORITY_HIGH     = 'high';
    const PRIORITY_NORMAL   = 'normal';
    const PRIORITY_LOW      = 'low';

    /** @var array<string, int> 优先级 => 权重，越大越先 */
    protected static $weights = [
        self::PRIORITY_CRITICAL => 40,
        self::PRIORITY_HIGH     => 30,
        self::PRIORITY_NORMAL   => 20,
        self::PRIORITY_LOW      => 10,
    ];

    /** @var array<string, array<string, mixed>> task_id => 条目 */
    protected $queue = [];

    /** @var array<string, bool> 正在跑的任务 */
    protected $running = [];

    /** @var array<string, array<string, mixed>> 已终结的任务 */
    protected $finished = [];

    /** @var TaskGraph|null */
    protected $graph = null;

    /** @var array<string, int> 各类并发上限 */
    protected $limits = [
        'max_tasks'             => 100,
        'max_subagents'         => 4,
        'max_background_agents' => 2,
        'max_parallel_tools'    => 8,
        'max_concurrent'        => 4,
    ];

    /** @var array<string, int> 当前各类占用 */
    protected $usage = [
        'subagents'         => 0,
        'background_agents' => 0,
        'parallel_tools'    => 0,
    ];

    /** @var int 默认最大重试次数 */
    protected $maxRetries = 2;

    /**
     * @param array<string, mixed> $limits 见 $limits 默认值；另有 maxRetries
     */
    public function __construct(array $limits = [])
    {
        foreach ($this->limits as $key => $value) {
            if (isset($limits[$key])) {
                $this->limits[$key] = max(1, (int) $limits[$key]);
            }
        }
        if (isset($limits['maxRetries'])) {
            $this->maxRetries = max(0, (int) $limits['maxRetries']);
        }
    }

    /**
     * 提交一个任务
     *
     * @param string $taskId
     * @param string $goal
     * @param string $priority
     * @param array<string, mixed> $options retries / payload
     * @return bool 超出 max_tasks 时返回 false
     */
    public function submit($taskId, $goal = '', $priority = self::PRIORITY_NORMAL, array $options = [])
    {
        $taskId = (string) $taskId;
        if ($taskId === '' || isset($this->queue[$taskId]) || isset($this->running[$taskId])) {
            return false;
        }
        if (count($this->queue) + count($this->running) >= $this->limits['max_tasks']) {
            return false;
        }

        $this->queue[$taskId] = [
            'task_id'   => $taskId,
            'goal'      => (string) $goal,
            'priority'  => isset(self::$weights[$priority]) ? (string) $priority : self::PRIORITY_NORMAL,
            'retries'   => isset($options['retries']) ? (int) $options['retries'] : 0,
            'payload'   => isset($options['payload']) ? $options['payload'] : null,
            'submitted' => microtime(true),
        ];
        if ($this->graph !== null) {
            $this->graph->addTask($taskId);
        }
        return true;
    }

    /**
     * 取下一个该跑的任务
     *
     * 排序：优先级高的先跑，同优先级按提交顺序（先来先服务）。
     * 并发已满或依赖未满足时返回 null。
     *
     * @return string|null
     */
    public function next()
    {
        if (count($this->running) >= $this->limits['max_concurrent']) {
            return null;
        }

        $candidates = [];
        foreach ($this->queue as $taskId => $item) {
            if ($this->graph !== null && !$this->graph->isSatisfied($taskId)) {
                continue;   // 依赖还没满足，跑了也是白跑
            }
            $candidates[] = $item;
        }
        if (!$candidates) {
            return null;
        }

        usort($candidates, function ($a, $b) {
            $wa = self::$weights[$a['priority']];
            $wb = self::$weights[$b['priority']];
            if ($wa !== $wb) {
                return $wa > $wb ? -1 : 1;
            }
            if ($a['submitted'] === $b['submitted']) {
                return 0;
            }
            return $a['submitted'] < $b['submitted'] ? -1 : 1;
        });

        return $candidates[0]['task_id'];
    }

    /**
     * 标记任务开始执行
     *
     * @param string $taskId
     * @return bool 并发已满或任务不在队列里返回 false
     */
    public function start($taskId)
    {
        $taskId = (string) $taskId;
        if (!isset($this->queue[$taskId])) {
            return false;
        }
        if (count($this->running) >= $this->limits['max_concurrent']) {
            return false;
        }

        $this->running[$taskId] = true;
        unset($this->queue[$taskId]);
        if ($this->graph !== null) {
            $this->graph->markRunning($taskId);
        }
        return true;
    }

    /**
     * 标记任务结束
     *
     * 失败且还有重试次数时自动重新入队——瞬时故障（网络抖动、模型超时）
     * 重试一次多半就好了，让人手动重投没有意义。
     *
     * @param string $taskId
     * @param bool $success
     * @param string $error
     * @return string completed / failed / requeued
     */
    public function finish($taskId, $success = true, $error = '')
    {
        $taskId = (string) $taskId;
        unset($this->running[$taskId]);

        if ($success) {
            $this->finished[$taskId] = ['status' => 'completed', 'error' => ''];
            if ($this->graph !== null) {
                $this->graph->markCompleted($taskId);
            }
            return 'completed';
        }

        $retries = isset($this->finished[$taskId]['retries']) ? (int) $this->finished[$taskId]['retries'] : 0;
        if ($retries < $this->maxRetries) {
            $this->queue[$taskId] = [
                'task_id'   => $taskId,
                'goal'      => '',
                'priority'  => self::PRIORITY_HIGH,   // 重试的排前面，别让它一直排队
                'retries'   => $retries + 1,
                'payload'   => null,
                'submitted' => microtime(true),
            ];
            $this->finished[$taskId] = ['status' => 'requeued', 'error' => (string) $error, 'retries' => $retries + 1];
            return 'requeued';
        }

        $this->finished[$taskId] = ['status' => 'failed', 'error' => (string) $error, 'retries' => $retries];
        if ($this->graph !== null) {
            $this->graph->markFailed($taskId);
        }
        return 'failed';
    }

    /**
     * 申请一个并发额度
     *
     * @param string $kind subagents / background_agents / parallel_tools
     * @return bool 已达上限返回 false
     */
    public function acquire($kind)
    {
        $kind = (string) $kind;
        if (!isset($this->usage[$kind])) {
            return false;
        }
        $limitKey = 'max_' . $kind;
        $limit = isset($this->limits[$limitKey]) ? $this->limits[$limitKey] : 0;

        if ($this->usage[$kind] >= $limit) {
            return false;
        }
        $this->usage[$kind]++;
        return true;
    }

    /**
     * 归还一个并发额度
     *
     * @param string $kind
     * @return $this
     */
    public function release($kind)
    {
        $kind = (string) $kind;
        if (isset($this->usage[$kind]) && $this->usage[$kind] > 0) {
            $this->usage[$kind]--;
        }
        return $this;
    }

    /**
     * 某类并发还有额度吗
     *
     * @param string $kind
     * @return bool
     */
    public function hasCapacity($kind)
    {
        $kind = (string) $kind;
        if (!isset($this->usage[$kind])) {
            return true;
        }
        $limit = isset($this->limits['max_' . $kind]) ? $this->limits['max_' . $kind] : 0;
        return $this->usage[$kind] < $limit;
    }

    /**
     * @param TaskGraph|null $graph
     * @return $this
     */
    public function setGraph($graph)
    {
        $this->graph = $graph instanceof TaskGraph ? $graph : null;
        return $this;
    }

    /**
     * @return TaskGraph|null
     */
    public function getGraph()
    {
        return $this->graph;
    }

    /**
     * 排队中的任务数
     *
     * @return int
     */
    public function pendingCount()
    {
        return count($this->queue);
    }

    /**
     * 正在跑的任务数
     *
     * @return int
     */
    public function runningCount()
    {
        return count($this->running);
    }

    /**
     * 排队中的任务 ID
     *
     * @return string[]
     */
    public function pending()
    {
        return array_keys($this->queue);
    }

    /**
     * 正在跑的任务 ID
     *
     * @return string[]
     */
    public function running()
    {
        return array_keys($this->running);
    }

    /**
     * 某个任务的终态
     *
     * @param string $taskId
     * @return array<string, mixed>|null
     */
    public function statusOf($taskId)
    {
        $taskId = (string) $taskId;
        if (isset($this->running[$taskId])) {
            return ['status' => 'running', 'error' => ''];
        }
        if (isset($this->queue[$taskId])) {
            return ['status' => 'queued', 'error' => ''];
        }
        return isset($this->finished[$taskId]) ? $this->finished[$taskId] : null;
    }

    /**
     * 调度器整体状态
     *
     * @return array<string, mixed>
     */
    public function stats()
    {
        return [
            'pending'  => count($this->queue),
            'running'  => count($this->running),
            'finished' => count($this->finished),
            'limits'   => $this->limits,
            'usage'    => $this->usage,
        ];
    }

    /**
     * 上限配置
     *
     * @return array<string, int>
     */
    public function limits()
    {
        return $this->limits;
    }
}
