<?php
namespace Ai\Agent\Session;

/**
 * Agent 会话（值对象）
 *
 * 保存 Agent 运行时的完整状态，支持暂停/恢复/中断。
 * 由 SessionStore 持久化，可跨请求恢复。
 */
class AgentSession
{
    /** @var string */
    protected $id;

    /** @var array<int, array<string, mixed>> */
    protected $messages = [];

    /** @var string */
    protected $system = '';

    /** @var string running|paused|interrupted|completed|failed */
    protected $status = 'running';

    /** @var int */
    protected $createdAt;

    /** @var int */
    protected $updatedAt;

    /** @var string */
    protected $model = '';

    /** @var string */
    protected $workdir = '';

    /** @var array<string, mixed> */
    protected $extra = [];

    /** @var int */
    protected $iteration = 0;

    /** @var string */
    protected $stopReason = '';

    /** @var array<string, mixed> */
    protected $budgetState = [];

    /** @var string|null */
    protected $pendingPermissionId = null;

    /** @var string 会话归属用户（原始 userId，用于 ownership 校验；空表示未绑定） */
    protected $userId = '';

    /** @var string 会话归属项目身份（项目 slug，用于 ownership 校验；空表示未绑定） */
    protected $projectId = '';

    /**
     * @param string $id
     * @param array<string, mixed> $data
     */
    public function __construct($id, array $data = [])
    {
        $this->id = (string) $id;
        $this->messages  = isset($data['messages']) && is_array($data['messages']) ? $data['messages'] : [];
        $this->system    = isset($data['system']) ? (string) $data['system'] : '';
        $this->status    = isset($data['status']) ? (string) $data['status'] : 'running';
        $this->createdAt = isset($data['created_at']) ? (int) $data['created_at'] : time();
        $this->updatedAt = isset($data['updated_at']) ? (int) $data['updated_at'] : time();
        $this->model     = isset($data['model']) ? (string) $data['model'] : '';
        $this->workdir   = isset($data['workdir']) ? (string) $data['workdir'] : '';
        $this->extra     = isset($data['extra']) && is_array($data['extra']) ? $data['extra'] : [];
        $this->iteration  = isset($data['iteration']) ? (int) $data['iteration'] : 0;
        $this->stopReason = isset($data['stop_reason']) ? (string) $data['stop_reason'] : '';
        $this->budgetState    = isset($data['budget_state']) && is_array($data['budget_state']) ? $data['budget_state'] : [];
        $this->pendingPermissionId = isset($data['pending_permission_id']) ? (string) $data['pending_permission_id'] : null;
        $this->userId    = isset($data['user_id']) ? (string) $data['user_id'] : '';
        $this->projectId = isset($data['project_id']) ? (string) $data['project_id'] : '';
    }

    /** @return string */
    public function getId() { return $this->id; }
    /** @return array<int, array<string, mixed>> */
    public function getMessages() { return $this->messages; }
    /** @return string */
    public function getSystem() { return $this->system; }
    /** @return string */
    public function getStatus() { return $this->status; }
    /** @return int */
    public function getCreatedAt() { return $this->createdAt; }
    /** @return int */
    public function getUpdatedAt() { return $this->updatedAt; }
    /** @return string */
    public function getModel() { return $this->model; }
    /** @return string */
    public function getWorkdir() { return $this->workdir; }
    /** @return array<string, mixed> */
    public function getExtra() { return $this->extra; }
    /** @return string */
    public function getUserId() { return $this->userId; }
    /** @return string */
    public function getProjectId() { return $this->projectId; }

    /**
     * @param string $system
     * @return $this
     */
    public function setSystem($system) { $this->system = (string) $system; $this->touch(); return $this; }
    /**
     * @param array<string, mixed> $extra
     * @return $this
     */
    public function setExtra(array $extra) { $this->extra = $extra; $this->touch(); return $this; }
    /**
     * @param string $userId
     * @return $this
     */
    public function setUserId($userId) { $this->userId = (string) $userId; $this->touch(); return $this; }
    /**
     * @param string $projectId
     * @return $this
     */
    public function setProjectId($projectId) { $this->projectId = (string) $projectId; $this->touch(); return $this; }

    /**
     * ownership 校验：当前 userId / project 身份是否与本会话记录一致
     *
     * 会话未绑定该维度（空字符串）时对该维度不设限——路径隔离已是主防线，
     * 本校验是深度防御（见 dev.md 4.3 第三层）。路径正确不代替授权校验。
     *
     * @param string $userId 当前 userId
     * @param string $projectId 当前项目身份（slug）
     * @return bool
     */
    public function belongsTo($userId, $projectId)
    {
        if ($this->userId !== '' && $this->userId !== (string) $userId) {
            return false;
        }
        if ($this->projectId !== '' && $this->projectId !== (string) $projectId) {
            return false;
        }
        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return $this
     */
    public function setMessages(array $messages) { $this->messages = $messages; $this->touch(); return $this; }
    /**
     * @param string $status
     * @return $this
     */
    public function setStatus($status) { $this->status = (string) $status; $this->touch(); return $this; }

    /** 标记为暂停
     * @return $this
     */
    public function pause() { $this->status = 'paused'; $this->touch(); return $this; }
    /** 标记为恢复运行
     * @return $this
     */
    public function resume() { $this->status = 'running'; $this->touch(); return $this; }
    /** 标记为中断
     * @return $this
     */
    public function interrupt() { $this->status = 'interrupted'; $this->touch(); return $this; }
    /** 标记为完成
     * @return $this
     */
    public function complete() { $this->status = 'completed'; $this->touch(); return $this; }
    /** 标记为失败
     * @return $this
     */
    public function fail() { $this->status = 'failed'; $this->touch(); return $this; }

    /** @return bool */
    public function isRunning() { return $this->status === 'running'; }
    /** @return bool */
    public function isPaused() { return $this->status === 'paused'; }
    /** @return bool */
    public function isCompleted() { return $this->status === 'completed'; }

    /** @return int */
    public function getIteration() { return $this->iteration; }
    /** @return string */
    public function getStopReason() { return $this->stopReason; }
    /** @return array<string, mixed> */
    public function getBudgetState() { return $this->budgetState; }
    /** @return string|null */
    public function getPendingPermissionId() { return $this->pendingPermissionId; }

    /**
     * @param int $iteration
     * @return $this
     */
    public function setIteration($iteration) { $this->iteration = (int) $iteration; $this->touch(); return $this; }
    /**
     * @param string $stopReason
     * @return $this
     */
    public function setStopReason($stopReason) { $this->stopReason = (string) $stopReason; $this->touch(); return $this; }
    /**
     * @param array<string, mixed> $budgetState
     * @return $this
     */
    public function setBudgetState(array $budgetState) { $this->budgetState = $budgetState; $this->touch(); return $this; }
    /**
     * @param string|null $id
     * @return $this
     */
    public function setPendingPermissionId($id) { $this->pendingPermissionId = $id ? (string) $id : null; $this->touch(); return $this; }

    /** 转为数组
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'id'         => $this->id,
            'messages'   => $this->messages,
            'system'     => $this->system,
            'status'     => $this->status,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'model'      => $this->model,
            'workdir'    => $this->workdir,
            'extra'      => $this->extra,
            'iteration'  => $this->iteration,
            'stop_reason' => $this->stopReason,
            'budget_state' => $this->budgetState,
            'pending_permission_id' => $this->pendingPermissionId,
            'user_id'    => $this->userId,
            'project_id' => $this->projectId,
        ];
    }

    /** 更新时间戳
     * @return void
     */
    protected function touch()
    {
        $this->updatedAt = time();
    }
}