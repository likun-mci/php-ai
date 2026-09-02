<?php
namespace Ai\Agent\Planning;

/**
 * PlanStep——计划步骤值对象
 *
 * 代表计划中的一个执行步骤，有独立的状态管理。
 * 状态流转：pending → running → completed / failed / skipped
 */
class PlanStep
{
    const STATUS_PENDING   = 'pending';
    const STATUS_RUNNING   = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';
    const STATUS_SKIPPED   = 'skipped';

    /** @var int|string */
    protected $id;

    /** @var string */
    protected $action;

    /** @var string */
    protected $status = self::STATUS_PENDING;

    /** @var string|null */
    protected $description = null;

    /** @var array<int, int|string> */
    protected $dependencies = [];

    /** @var array<string, mixed> */
    protected $context = [];

    /** @var string|null */
    protected $result = null;

    /** @var string|null */
    protected $error = null;

    /**
     * @param int|string $id
     * @param string $action
     * @param array<string, mixed> $options
     */
    public function __construct($id, $action, array $options = [])
    {
        $this->id = $id;
        $this->action = $action;
        $this->description = isset($options['description']) ? $options['description'] : null;
        $this->dependencies = isset($options['dependencies']) ? $options['dependencies'] : [];
        $this->context = isset($options['context']) ? $options['context'] : [];
        $this->status = isset($options['status']) ? $options['status'] : self::STATUS_PENDING;
    }

    /**
     * @return int|string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getAction()
    {
        return $this->action;
    }

    /**
     * 修改步骤动作
     *
     * @param string $action
     * @return $this
     */
    public function setAction($action)
    {
        $this->action = $action;
        return $this;
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
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * @return array<int, int|string>
     */
    public function getDependencies()
    {
        return $this->dependencies;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * @return string|null
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * @return string|null
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * 重置为 pending 状态
     *
     * @return $this
     */
    public function markPending()
    {
        $this->status = self::STATUS_PENDING;
        return $this;
    }

    /**
     * 标记为运行中
     *
     * @return $this
     */
    public function markRunning()
    {
        $this->status = self::STATUS_RUNNING;
        return $this;
    }

    /**
     * 标记为已完成
     *
     * @param string|null $result
     * @return $this
     */
    public function markCompleted($result = null)
    {
        $this->status = self::STATUS_COMPLETED;
        if ($result !== null) {
            $this->result = $result;
        }
        return $this;
    }

    /**
     * 标记为失败
     *
     * @param string $error
     * @return $this
     */
    public function markFailed($error)
    {
        $this->status = self::STATUS_FAILED;
        $this->error = $error;
        return $this;
    }

    /**
     * 标记为跳过
     *
     * @param string|null $reason
     * @return $this
     */
    public function markSkipped($reason = null)
    {
        $this->status = self::STATUS_SKIPPED;
        if ($reason !== null) {
            $this->error = $reason;
        }
        return $this;
    }

    /**
     * @return bool
     */
    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * @return bool
     */
    public function isRunning()
    {
        return $this->status === self::STATUS_RUNNING;
    }

    /**
     * @return bool
     */
    public function isCompleted()
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * @return bool
     */
    public function isFailed()
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * @return bool
     */
    public function isSkipped()
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    /**
     * @return bool
     */
    public function isTerminal()
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_SKIPPED,
        ], true);
    }

    /**
     * @param array<int, int|string> $completedStepIds
     * @return bool 是否已准备好执行（依赖都已满足）
     */
    public function isReady(array $completedStepIds = [])
    {
        if (!$this->isPending()) {
            return false;
        }
        foreach ($this->dependencies as $depId) {
            if (!in_array($depId, $completedStepIds, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'status' => $this->status,
            'description' => $this->description,
            'dependencies' => $this->dependencies,
            'context' => $this->context,
            'result' => $this->result,
            'error' => $this->error,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data)
    {
        $step = new self(
            $data['id'],
            $data['action'],
            [
                'description' => isset($data['description']) ? $data['description'] : null,
                'dependencies' => isset($data['dependencies']) ? $data['dependencies'] : [],
                'context' => isset($data['context']) ? $data['context'] : [],
                'status' => isset($data['status']) ? $data['status'] : self::STATUS_PENDING,
            ]
        );
        if (isset($data['result'])) {
            $step->result = $data['result'];
        }
        if (isset($data['error'])) {
            $step->error = $data['error'];
        }
        return $step;
    }
}