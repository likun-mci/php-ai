<?php
namespace Ai\Agent\Task;

/**
 * TaskGraph——任务依赖图（DAG）
 *
 * 回答三个问题：现在哪些任务可以开跑（依赖都满足了）、哪些被卡住了（上游失败）、
 * 依赖里有没有环。调度器照着这张图派活，就能自然做到「B 与 C 并行、D 等 C」。
 *
 * ```php
 * $graph = new TaskGraph();
 * $graph->addTask('a')->addTask('b')->addTask('c')->addTask('d');
 * $graph->dependsOn('b', 'a');
 * $graph->dependsOn('c', 'a');
 * $graph->dependsOn('d', 'c');
 *
 * $graph->ready();                  // ['a']
 * $graph->markCompleted('a');
 * $graph->ready();                  // ['b', 'c'] —— 可并行
 * $graph->markCompleted('c');
 * $graph->ready();                  // ['b', 'd']
 * ```
 *
 * 图只管依赖与状态，不管任务怎么执行——执行交给 `TaskManager` / `AgentQueue`。
 * 状态可以由外部同步进来（`syncFrom()`），也可以直接在图上标记。
 */
class TaskGraph
{
    /** 被硬依赖的上游失败，本任务无法执行 */
    const STATE_BLOCKED = 'blocked';

    /** @var array<string, string> 任务 ID => 状态（复用 TaskStatus 的取值 + blocked） */
    protected $states = [];

    /** @var array<string, TaskDependency[]> 任务 ID => 它依赖的边 */
    protected $edges = [];

    /** @var array<string, string[]> 任务 ID => 依赖它的任务（反向索引） */
    protected $reverse = [];

    /**
     * 加入一个任务
     *
     * @param string $taskId
     * @param string $status 初始状态，默认 queued
     * @return $this
     */
    public function addTask($taskId, $status = TaskStatus::QUEUED)
    {
        $taskId = (string) $taskId;
        if ($taskId === '') {
            return $this;
        }
        if (!isset($this->states[$taskId])) {
            $this->states[$taskId] = (string) $status;
            $this->edges[$taskId] = [];
        }
        return $this;
    }

    /**
     * 声明依赖：`$taskId` 必须等 `$dependsOn` 完成
     *
     * 两端任务不在图里会先自动加入——调用方按什么顺序建图不该影响结果。
     * 会形成环的依赖直接拒绝：环意味着谁都跑不起来，早点报错比运行时死锁好。
     *
     * @param string $taskId
     * @param string $dependsOn
     * @param string $type hard|soft
     * @param string $reason
     * @return bool 成功建立返回 true；自依赖或成环返回 false
     */
    public function dependsOn($taskId, $dependsOn, $type = TaskDependency::TYPE_HARD, $reason = '')
    {
        $taskId = (string) $taskId;
        $dependsOn = (string) $dependsOn;

        if ($taskId === '' || $dependsOn === '' || $taskId === $dependsOn) {
            return false;
        }
        $this->addTask($taskId);
        $this->addTask($dependsOn);

        // 已有同一条边则不重复加
        foreach ($this->edges[$taskId] as $edge) {
            if ($edge->getDependsOn() === $dependsOn) {
                return true;
            }
        }

        // 成环检测：$dependsOn 是否（间接）依赖 $taskId
        if ($this->reaches($dependsOn, $taskId)) {
            return false;
        }

        $this->edges[$taskId][] = new TaskDependency($taskId, $dependsOn, $type, $reason);
        if (!isset($this->reverse[$dependsOn])) {
            $this->reverse[$dependsOn] = [];
        }
        if (!in_array($taskId, $this->reverse[$dependsOn], true)) {
            $this->reverse[$dependsOn][] = $taskId;
        }
        return true;
    }

    /**
     * 现在可以开跑的任务
     *
     * 条件：自身处于 queued，且全部硬依赖已 completed、软依赖已终结。
     *
     * @return string[]
     */
    public function ready()
    {
        $ready = [];
        foreach ($this->states as $taskId => $status) {
            if ($status !== TaskStatus::QUEUED) {
                continue;
            }
            if ($this->isSatisfied($taskId)) {
                $ready[] = $taskId;
            }
        }
        return $ready;
    }

    /**
     * 指定任务的依赖是否都满足了
     *
     * @param string $taskId
     * @return bool
     */
    public function isSatisfied($taskId)
    {
        $taskId = (string) $taskId;
        if (!isset($this->edges[$taskId])) {
            return true;
        }
        foreach ($this->edges[$taskId] as $edge) {
            $upstream = $edge->getDependsOn();
            $status = isset($this->states[$upstream]) ? $this->states[$upstream] : TaskStatus::QUEUED;

            if ($edge->isHard()) {
                if ($status !== TaskStatus::COMPLETED) {
                    return false;
                }
                continue;
            }
            // 软依赖只要求上游已经终结（成功失败都行）
            if (!$this->isTerminal($status)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 被上游硬依赖拖住、永远跑不起来的任务
     *
     * @return string[]
     */
    public function blocked()
    {
        $blocked = [];
        foreach ($this->states as $taskId => $status) {
            if ($this->isTerminal($status)) {
                continue;
            }
            if ($this->hasFailedHardUpstream($taskId)) {
                $blocked[] = $taskId;
            }
        }
        return $blocked;
    }

    /**
     * 标记任务状态
     *
     * 标记为失败或取消时，下游硬依赖任务会被连带标记为 blocked——
     * 让一个注定跑不起来的任务留在队列里等，只会浪费一次调度。
     *
     * @param string $taskId
     * @param string $status
     * @return $this
     */
    public function setStatus($taskId, $status)
    {
        $taskId = (string) $taskId;
        if (!isset($this->states[$taskId])) {
            $this->addTask($taskId, $status);
            return $this;
        }
        $this->states[$taskId] = (string) $status;

        if ($status === TaskStatus::FAILED || $status === TaskStatus::CANCELLED) {
            $this->propagateBlock($taskId);
        }
        return $this;
    }

    /**
     * @param string $taskId
     * @return $this
     */
    public function markCompleted($taskId)
    {
        return $this->setStatus($taskId, TaskStatus::COMPLETED);
    }

    /**
     * @param string $taskId
     * @return $this
     */
    public function markFailed($taskId)
    {
        return $this->setStatus($taskId, TaskStatus::FAILED);
    }

    /**
     * @param string $taskId
     * @return $this
     */
    public function markRunning($taskId)
    {
        return $this->setStatus($taskId, TaskStatus::RUNNING);
    }

    /**
     * 任务状态
     *
     * @param string $taskId
     * @return string 不在图里返回空串
     */
    public function getStatus($taskId)
    {
        $taskId = (string) $taskId;
        return isset($this->states[$taskId]) ? $this->states[$taskId] : '';
    }

    /**
     * 指定任务直接依赖的任务
     *
     * @param string $taskId
     * @return string[]
     */
    public function dependenciesOf($taskId)
    {
        $taskId = (string) $taskId;
        if (!isset($this->edges[$taskId])) {
            return [];
        }
        $deps = [];
        foreach ($this->edges[$taskId] as $edge) {
            $deps[] = $edge->getDependsOn();
        }
        return $deps;
    }

    /**
     * 直接依赖指定任务的任务
     *
     * @param string $taskId
     * @return string[]
     */
    public function dependentsOf($taskId)
    {
        $taskId = (string) $taskId;
        return isset($this->reverse[$taskId]) ? $this->reverse[$taskId] : [];
    }

    /**
     * 按依赖深度分层——同一层的任务可以并行
     *
     * @return array<int, string[]>
     */
    public function layers()
    {
        $remaining = array_keys($this->states);
        $placed = [];
        $layers = [];

        while ($remaining) {
            $layer = [];
            foreach ($remaining as $taskId) {
                $pending = false;
                foreach ($this->dependenciesOf($taskId) as $dep) {
                    if (!isset($placed[$dep])) {
                        $pending = true;
                        break;
                    }
                }
                if (!$pending) {
                    $layer[] = $taskId;
                }
            }
            if (!$layer) {
                // 正常建图不会出现（dependsOn 拒绝成环），兜底避免死循环
                $layers[] = array_values($remaining);
                break;
            }
            foreach ($layer as $taskId) {
                $placed[$taskId] = true;
            }
            $remaining = array_values(array_diff($remaining, $layer));
            $layers[] = $layer;
        }
        return $layers;
    }

    /**
     * 全部任务 ID
     *
     * @return string[]
     */
    public function tasks()
    {
        return array_keys($this->states);
    }

    /**
     * 全部依赖边
     *
     * @return TaskDependency[]
     */
    public function dependencies()
    {
        $all = [];
        foreach ($this->edges as $edges) {
            foreach ($edges as $edge) {
                $all[] = $edge;
            }
        }
        return $all;
    }

    /**
     * 图是否已经全部跑完
     *
     * @return bool
     */
    public function isComplete()
    {
        foreach ($this->states as $status) {
            if (!$this->isTerminal($status) && $status !== self::STATE_BLOCKED) {
                return false;
            }
        }
        return $this->states !== [];
    }

    /**
     * 从 TaskManager 同步任务与状态
     *
     * 依赖边不在 TaskManager 里，同步只更新状态，不动图结构。
     *
     * @param TaskManager $manager
     * @return $this
     */
    public function syncFrom(TaskManager $manager)
    {
        foreach ($manager->all() as $task) {
            $taskId = $task->getId();
            $this->addTask($taskId, $task->getStatus());
            $this->states[$taskId] = $task->getStatus();
        }
        // 状态同步完再重算阻塞，否则上游状态可能还是旧的
        foreach ($this->states as $taskId => $status) {
            if ($status === TaskStatus::FAILED || $status === TaskStatus::CANCELLED) {
                $this->propagateBlock($taskId);
            }
        }
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        $edges = [];
        foreach ($this->dependencies() as $edge) {
            $edges[] = $edge->toArray();
        }
        return [
            'states' => $this->states,
            'edges'  => $edges,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data)
    {
        $graph = new self();
        if (isset($data['states']) && is_array($data['states'])) {
            foreach ($data['states'] as $taskId => $status) {
                $graph->addTask((string) $taskId, (string) $status);
            }
        }
        if (isset($data['edges']) && is_array($data['edges'])) {
            foreach ($data['edges'] as $edgeData) {
                if (!is_array($edgeData)) {
                    continue;
                }
                $edge = TaskDependency::fromArray($edgeData);
                $graph->dependsOn(
                    $edge->getTaskId(),
                    $edge->getDependsOn(),
                    $edge->getType(),
                    $edge->getReason()
                );
            }
        }
        return $graph;
    }

    /**
     * 从 $from 出发能否走到 $target（用于成环检测）
     *
     * @param string $from
     * @param string $target
     * @return bool
     */
    protected function reaches($from, $target)
    {
        $seen = [];
        $stack = [(string) $from];

        while ($stack) {
            $node = array_pop($stack);
            if ($node === $target) {
                return true;
            }
            if (isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;
            foreach ($this->dependenciesOf($node) as $dep) {
                $stack[] = $dep;
            }
        }
        return false;
    }

    /**
     * 把失败向下游传播成 blocked
     *
     * @param string $taskId
     * @return void
     */
    protected function propagateBlock($taskId)
    {
        foreach ($this->dependentsOf($taskId) as $downstream) {
            $status = isset($this->states[$downstream]) ? $this->states[$downstream] : '';
            if ($this->isTerminal($status) || $status === self::STATE_BLOCKED) {
                continue;
            }
            if (!$this->hasFailedHardUpstream($downstream)) {
                continue;
            }
            $this->states[$downstream] = self::STATE_BLOCKED;
            $this->propagateBlock($downstream);
        }
    }

    /**
     * 是否有硬依赖的上游已经失败/取消/阻塞
     *
     * @param string $taskId
     * @return bool
     */
    protected function hasFailedHardUpstream($taskId)
    {
        $taskId = (string) $taskId;
        if (!isset($this->edges[$taskId])) {
            return false;
        }
        foreach ($this->edges[$taskId] as $edge) {
            if (!$edge->isHard()) {
                continue;
            }
            $status = isset($this->states[$edge->getDependsOn()]) ? $this->states[$edge->getDependsOn()] : '';
            if ($status === TaskStatus::FAILED
                || $status === TaskStatus::CANCELLED
                || $status === self::STATE_BLOCKED) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $status
     * @return bool
     */
    protected function isTerminal($status)
    {
        return in_array((string) $status, [
            TaskStatus::COMPLETED,
            TaskStatus::FAILED,
            TaskStatus::CANCELLED,
        ], true);
    }
}
