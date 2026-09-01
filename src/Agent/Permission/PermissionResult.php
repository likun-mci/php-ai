<?php
namespace Ai\Agent\Permission;

/**
 * 权限检查结果
 *
 * 三种结果：allow（放行）、deny（拒绝）、ask（询问用户）。
 * 拒绝时需附带理由；ask 用于需要用户决策的场景。
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

    /**
     * @param string $status
     * @param string $reason
     */
    protected function __construct($status, $reason = '')
    {
        $this->status = $status;
        $this->reason = $reason;
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
     * @return self
     */
    public static function ask($prompt = '')
    {
        return new self(self::ASK, $prompt);
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
}