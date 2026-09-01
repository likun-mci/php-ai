<?php
namespace Ai\Agent\Permission;

/**
 * 权限检查结果
 *
 * 三种结果：allow（放行）、deny（拒绝）、ask（询问用户）。
 * 拒绝时需附带理由；ask 用于需要用户决策的场景，并携带 PermissionRequest
 * 供业务层 approve() / deny() 响应。
 */
class PermissionResult
{
    const ALLOW = 'allow';
    const DENY  = 'deny';
    const ASK   = 'ask';

    /** @var string */
    protected $status;

    /** @var string */
    protected $reason = '';

    /** @var PermissionRequest|null */
    protected $request = null;

    /**
     * @param string $status
     * @param string $reason
     * @param PermissionRequest|null $request
     */
    protected function __construct($status, $reason = '', $request = null)
    {
        $this->status = $status;
        $this->reason = $reason;
        $this->request = $request;
    }

    /** 放行
     * @return self
     */
    public static function allow()
    {
        return new self(self::ALLOW);
    }

    /** 拒绝
     * @param string $reason
     * @return self
     */
    public static function deny($reason = '')
    {
        return new self(self::DENY, $reason);
    }

    /** 询问用户
     * @param string $prompt
     * @param PermissionRequest|null $request
     * @return self
     */
    public static function ask($prompt = '', $request = null)
    {
        return new self(self::ASK, $prompt, $request);
    }

    /** @return string */
    public function getStatus() { return $this->status; }
    /** @return string */
    public function getReason() { return $this->reason; }
    /** @return bool */
    public function isAllowed() { return $this->status === self::ALLOW; }
    /** @return bool */
    public function isDenied() { return $this->status === self::DENY; }
    /** @return bool */
    public function needsAsk() { return $this->status === self::ASK; }
    /** @return PermissionRequest|null */
    public function getRequest() { return $this->request; }
}