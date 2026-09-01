<?php
namespace Ai\Agent\Hooks;

/**
 * 钩子执行结果——统一返回值
 *
 * 支持五种结果类型，覆盖所有钩子场景：
 *
 *   CONTINUE — 继续执行（默认）
 *   ALLOW    — 放行（钩子允许）
 *   DENY     — 拒绝（钩子阻止）
 *   MODIFY   — 修改输入后继续
 *   STOP     — 停止 Agent
 *
 * 用法：
 * ```php
 * return HookResult::deny('禁止生产环境执行 DROP TABLE');
 * return HookResult::modify(['command' => 'git diff']);
 * return HookResult::continue();
 * ```
 */
class HookResult
{
    const CONTINUE = 'continue';
    const ALLOW    = 'allow';
    const DENY     = 'deny';
    const MODIFY   = 'modify';
    const STOP     = 'stop';

    /** @var string */
    protected $action;

    /** @var string */
    protected $reason = '';

    /** @var mixed 修改后的数据（MODIFY 时使用） */
    protected $data = null;

    /**
     * @param string $action
     * @param string $reason
     * @param mixed $data
     */
    public function __construct($action, $reason = '', $data = null)
    {
        $this->action = (string) $action;
        $this->reason = (string) $reason;
        $this->data = $data;
    }

    /** @return self */
    public static function go()
    {
        return new self(self::CONTINUE);
    }

    /** @return self */
    public static function allow()
    {
        return new self(self::ALLOW);
    }

    /**
     * @param string $reason
     * @return self
     */
    public static function deny($reason = '')
    {
        return new self(self::DENY, $reason);
    }

    /**
     * @param mixed $data
     * @return self
     */
    public static function modify($data = null)
    {
        return new self(self::MODIFY, '', $data);
    }

    /**
     * @param string $reason
     * @return self
     */
    public static function stop($reason = '')
    {
        return new self(self::STOP, $reason);
    }

    /** @return string */
    public function getAction() { return $this->action; }

    /** @return string */
    public function getReason() { return $this->reason; }

    /** @return mixed */
    public function getData() { return $this->data; }

    /** @return bool */
    public function isContinue() { return $this->action === self::CONTINUE; }

    /** @return bool */
    public function isAllow() { return $this->action === self::ALLOW; }

    /** @return bool */
    public function isDeny() { return $this->action === self::DENY; }

    /** @return bool */
    public function isModify() { return $this->action === self::MODIFY; }

    /** @return bool */
    public function isStop() { return $this->action === self::STOP; }
}