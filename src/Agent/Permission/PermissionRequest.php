<?php
namespace Ai\Agent\Permission;

/**
 * 权限请求——当工具需要用户决策时产生的请求
 *
 * 状态流转：
 *   pending → approved → 执行工具
 *   pending → denied   → 回填错误结果
 *   pending → expired  → 超时自动拒绝
 *   pending → cancelled → 被新请求取代
 *
 * 每个请求有唯一 ID，Agent 通过 approve() / deny() 响应。
 * 请求由 Session 持久化，跨请求可恢复。
 */
class PermissionRequest
{
    const PENDING   = 'pending';
    const APPROVED  = 'approved';
    const DENIED    = 'denied';
    const EXPIRED   = 'expired';
    const CANCELLED = 'cancelled';

    /** @var string */
    protected $id;

    /** @var string */
    protected $sessionId = '';

    /** @var string */
    protected $toolName = '';

    /** @var array<string, mixed> */
    protected $input = [];

    /** @var string */
    protected $description = '';

    /** @var string */
    protected $status = self::PENDING;

    /** @var int */
    protected $createdAt;

    /** @var string */
    protected $denyReason = '';

    /**
     * @param string $id
     * @param array<string, mixed> $data
     */
    public function __construct($id, array $data = [])
    {
        $this->id          = (string) $id;
        $this->sessionId   = isset($data['sessionId']) ? (string) $data['sessionId'] : '';
        $this->toolName    = isset($data['toolName']) ? (string) $data['toolName'] : '';
        $this->input       = isset($data['input']) && is_array($data['input']) ? $data['input'] : [];
        $this->description = isset($data['description']) ? (string) $data['description'] : '';
        $this->status      = isset($data['status']) ? (string) $data['status'] : self::PENDING;
        $this->createdAt   = isset($data['created_at']) ? (int) $data['created_at'] : time();
        $this->denyReason  = isset($data['denyReason']) ? (string) $data['denyReason'] : '';
    }

    /** @return string */
    public function getId() { return $this->id; }
    /** @return string */
    public function getSessionId() { return $this->sessionId; }
    /** @return string */
    public function getToolName() { return $this->toolName; }
    /** @return array<string, mixed> */
    public function getInput() { return $this->input; }
    /** @return string */
    public function getDescription() { return $this->description; }
    /** @return string */
    public function getStatus() { return $this->status; }
    /** @return int */
    public function getCreatedAt() { return $this->createdAt; }
    /** @return string */
    public function getDenyReason() { return $this->denyReason; }

    /** @return $this */
    public function approve() { $this->status = self::APPROVED; return $this; }
    /**
     * @param string $reason
     * @return $this
     */
    public function deny($reason = '') { $this->status = self::DENIED; $this->denyReason = $reason; return $this; }
    /** @return $this */
    public function expire() { $this->status = self::EXPIRED; return $this; }
    /** @return $this */
    public function cancel() { $this->status = self::CANCELLED; return $this; }

    /** @return bool */
    public function isPending() { return $this->status === self::PENDING; }
    /** @return bool */
    public function isApproved() { return $this->status === self::APPROVED; }
    /** @return bool */
    public function isDenied() { return $this->status === self::DENIED; }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'id'          => $this->id,
            'sessionId'   => $this->sessionId,
            'toolName'    => $this->toolName,
            'input'       => $this->input,
            'description' => $this->description,
            'status'      => $this->status,
            'created_at'  => $this->createdAt,
            'denyReason'  => $this->denyReason,
        ];
    }
}