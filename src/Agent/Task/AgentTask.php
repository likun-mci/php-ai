<?php
namespace Ai\Agent\Task;

/**
 * AgentTask——任务值对象
 *
 * 代表一个完整用户目标，包含 id、goal、status、parentTaskId、sessionId。
 * Task 与 Loop 分离：Task 负责"整个用户目标"，Loop 负责"这一轮模型说什么"。
 * 一个 Task 包含多个 Turn。
 *
 * 用途：
 * ```php
 * $task = new AgentTask([
 *     'goal'      => '修复登录问题',
 *     'sessionId' => 'sess_abc',
 * ]);
 * echo $task->getId();
 * echo $task->getGoal();
 * echo $task->getStatus(); // 'queued'
 * ```
 */
class AgentTask
{
    /** @var string */
    protected $id;

    /** @var string */
    protected $goal = '';

    /** @var string */
    protected $status = '';

    /** @var string|null */
    protected $parentTaskId = null;

    /** @var string */
    protected $sessionId = '';

    /** @var int */
    protected $createdAt;

    /** @var int */
    protected $updatedAt;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->id           = isset($data['id']) ? (string) $data['id'] : self::generateId();
        $this->goal         = isset($data['goal']) ? (string) $data['goal'] : '';
        $this->status       = isset($data['status']) ? (string) $data['status'] : TaskStatus::QUEUED;
        $this->parentTaskId = isset($data['parentTaskId']) ? (string) $data['parentTaskId'] : null;
        $this->sessionId    = isset($data['sessionId']) ? (string) $data['sessionId'] : '';
        $this->createdAt    = isset($data['createdAt']) ? (int) $data['createdAt'] : time();
        $this->updatedAt    = isset($data['updatedAt']) ? (int) $data['updatedAt'] : time();
    }

    /**
     * 生成唯一任务 ID
     * @return string
     */
    public static function generateId()
    {
        return 'task_' . bin2hex(random_bytes(8));
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getGoal()
    {
        return $this->goal;
    }

    /**
     * @return string
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @return string|null
     */
    public function getParentTaskId()
    {
        return $this->parentTaskId;
    }

    /**
     * @return string
     */
    public function getSessionId()
    {
        return $this->sessionId;
    }

    /**
     * @return int
     */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /**
     * @return int
     */
    public function getUpdatedAt()
    {
        return $this->updatedAt;
    }

    /**
     * 设置状态
     * @param string $status
     * @return $this
     */
    public function setStatus($status)
    {
        $this->status = (string) $status;
        $this->touch();
        return $this;
    }

    /**
     * 设置父任务 ID
     * @param string|null $parentTaskId
     * @return $this
     */
    public function setParentTaskId($parentTaskId)
    {
        $this->parentTaskId = $parentTaskId ? (string) $parentTaskId : null;
        $this->touch();
        return $this;
    }

    /**
     * 更新 updatedAt 时间戳
     * @return void
     */
    protected function touch()
    {
        $this->updatedAt = time();
    }

    // ---- 便捷状态判断 ----

    /**
     * @return bool
     */
    public function isQueued()
    {
        return $this->status === TaskStatus::QUEUED;
    }

    /**
     * @return bool
     */
    public function isRunning()
    {
        return $this->status === TaskStatus::RUNNING;
    }

    /**
     * @return bool
     */
    public function isWaitingPermission()
    {
        return $this->status === TaskStatus::WAITING_PERMISSION;
    }

    /**
     * @return bool
     */
    public function isWaitingUser()
    {
        return $this->status === TaskStatus::WAITING_USER;
    }

    /**
     * @return bool
     */
    public function isPaused()
    {
        return $this->status === TaskStatus::PAUSED;
    }

    /**
     * @return bool
     */
    public function isCompleted()
    {
        return $this->status === TaskStatus::COMPLETED;
    }

    /**
     * @return bool
     */
    public function isFailed()
    {
        return $this->status === TaskStatus::FAILED;
    }

    /**
     * @return bool
     */
    public function isCancelled()
    {
        return $this->status === TaskStatus::CANCELLED;
    }

    /**
     * @return bool
     */
    public function isTerminal()
    {
        return TaskStatus::isTerminal($this->status);
    }

    /**
     * @return bool
     */
    public function isActive()
    {
        return TaskStatus::isActive($this->status);
    }

    /**
     * 转为数组
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'id'           => $this->id,
            'goal'         => $this->goal,
            'status'       => $this->status,
            'parentTaskId' => $this->parentTaskId,
            'sessionId'    => $this->sessionId,
            'createdAt'    => $this->createdAt,
            'updatedAt'    => $this->updatedAt,
        ];
    }
}