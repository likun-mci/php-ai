<?php
namespace Ai\Agent\Permission;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;

/**
 * 权限管理器
 *
 * 在工具执行前做权限检查，返回 allow / deny / ask 三种结果。
 * 支持 Claude Code 风格的权限模式与多级规则匹配。
 *
 * ask 结果会创建 PermissionRequest，暂停 Agent 循环，等待业务层
 * 通过 approve() / deny() 响应。请求可被持久化，跨 PHP 请求恢复。
 *
 * 权限模式（setMode）：
 *   - manual       危险工具询问用户
 *   - auto         自动放行已授权的工具
 *   - plan         只读模式：仅允许 read/glob/grep，其余拒绝
 *   - accept_edits 允许文件修改，bash 等高风险操作仍需权限
 *   - dont_ask     自动放行（不询问）
 *   - bypass       全部放行（⚠️ 不安全，不要用于不可信输入）
 *
 * 规则匹配（优先级 deny > allow > 模式默认）：
 *   $pm->allowTool('read_file');
 *   $pm->allowTool('write_file', ['path' => '/var/www/project/*']);
 *   $pm->denyTool('bash', ['command' => 'rm *']);
 *
 * 暂停/恢复：
 *   $pm->onAsk(function (PermissionRequest $req) { ... });
 *   $pm->approve('req_xxx');   // 放行
 *   $pm->deny('req_xxx', '不需要');  // 拒绝
 */
class PermissionManager
{
    const MODE_MANUAL       = 'manual';
    const MODE_AUTO         = 'auto';
    const MODE_PLAN         = 'plan';
    const MODE_ACCEPT_EDITS = 'accept_edits';
    const MODE_DONT_ASK     = 'dont_ask';
    const MODE_BYPASS       = 'bypass';

    /** @var string 当前权限模式 */
    protected $mode = self::MODE_MANUAL;

    /** @var PermissionRule[] */
    protected $rules = [];

    /** @var callable|null 询问用户回调 */
    protected $askHandler = null;

    /** @var string[] plan 模式下放行的只读工具 */
    protected static $readOnlyTools = ['read_file', 'glob', 'grep', 'list_directory', 'search'];

    /** @var string[] accept_edits 模式下自动放行的文件工具 */
    protected static $editTools = ['read_file', 'write_file', 'edit_file', 'glob', 'grep'];

    /** @var string[] 需要询问的危险工具 */
    protected static $dangerousTools = ['bash', 'delete_file', 'rm', 'exec'];

    /** @var array<string, PermissionRequest> 待处理的权限请求 */
    protected $requests = [];

    /** @var int 请求自增序号 */
    protected $requestCounter = 0;

    /** @var string|null 当前会话 ID */
    protected $sessionId = null;

    /**
     * @param string $mode 初始权限模式
     */
    public function __construct($mode = self::MODE_MANUAL)
    {
        $this->setMode($mode);
    }

    /** 设置会话 ID（权限请求归属）
     * @param string $sessionId
     * @return $this
     */
    public function setSessionId($sessionId)
    {
        $this->sessionId = (string) $sessionId;
        return $this;
    }

    /** 设置权限模式
     * @param string $mode
     * @return $this
     */
    public function setMode($mode)
    {
        $valid = [self::MODE_MANUAL, self::MODE_AUTO, self::MODE_PLAN,
                  self::MODE_ACCEPT_EDITS, self::MODE_DONT_ASK, self::MODE_BYPASS];
        if (!in_array($mode, $valid, true)) {
            // 原先是静默忽略：写错一个模式名（`acceptAll`、`acceptEdits`、
            // `bypassPermissions` 都是很自然的猜法），权限悄悄停在 manual，
            // Agent 一路跑到第一个 bash 调用才卡住等授权。到那时已经花掉一次
            // 完整的模型往返，而且现场没有任何线索指向「模式名写错了」。
            // 装配期的错误就该在装配期炸出来。
            throw new \InvalidArgumentException(
                '未知的权限模式 "' . (is_scalar($mode) ? (string) $mode : gettype($mode))
                . '"，可选：' . implode(' / ', $valid)
            );
        }
        $this->mode = $mode;
        return $this;
    }

    /** @return string */
    public function getMode() { return $this->mode; }

    /** 注册询问用户回调
     * @param callable|null $handler function(array $request): bool|PermissionResult
     * @return $this
     */
    public function onAsk($handler)
    {
        $this->askHandler = is_callable($handler) ? $handler : null;
        return $this;
    }

    /** 允许某工具（可带参数模式）
     * @param string $tool
     * @param array<string, string> $argPatterns
     * @return $this
     */
    public function allowTool($tool, array $argPatterns = [])
    {
        $this->rules[] = new PermissionRule('allow', $tool, $argPatterns);
        return $this;
    }

    /** 拒绝某工具（可带参数模式）
     * @param string $tool
     * @param array<string, string> $argPatterns
     * @return $this
     */
    public function denyTool($tool, array $argPatterns = [])
    {
        $this->rules[] = new PermissionRule('deny', $tool, $argPatterns);
        return $this;
    }

    /** 清除全部规则
     * @return $this
     */
    public function clearRules()
    {
        $this->rules = [];
        return $this;
    }

    /** @return PermissionRule[] */
    public function getRules() { return $this->rules; }

    /**
     * 检查某次工具调用是否被允许
     *
     * @param AgentToolInterface $tool
     * @param array<string, mixed> $input
     * @param ToolContext $context
     * @return PermissionResult
     */
    public function check(AgentToolInterface $tool, array $input, ToolContext $context)
    {
        $toolName = $tool->name();

        // 一、规则匹配：deny 优先于 allow
        foreach ($this->rules as $rule) {
            if (!$rule->matches($toolName, $input)) {
                continue;
            }
            if ($rule->getAction() === 'deny') {
                return PermissionResult::deny('已配置禁止该工具' . $this->ruleDetail($rule));
            }
            // 命中了 allow 规则 → 直接放行（不再询问）
            return PermissionResult::allow();
        }

        // 二、按模式判定
        switch ($this->mode) {
            case self::MODE_BYPASS:
                return PermissionResult::allow();

            case self::MODE_PLAN:
                if (in_array($toolName, self::$readOnlyTools, true)) {
                    return PermissionResult::allow();
                }
                return PermissionResult::deny('plan 模式只允许只读工具');

            case self::MODE_ACCEPT_EDITS:
                if (in_array($toolName, self::$editTools, true)) {
                    return PermissionResult::allow();
                }
                return $this->createRequest($toolName, $input);

            case self::MODE_DONT_ASK:
                return PermissionResult::allow();

            case self::MODE_AUTO:
                return PermissionResult::allow();

            case self::MODE_MANUAL:
            default:
                if (in_array($toolName, self::$readOnlyTools, true)) {
                    return PermissionResult::allow();
                }
                if (in_array($toolName, self::$dangerousTools, true)) {
                    return $this->createRequest($toolName, $input);
                }
                return PermissionResult::allow();
        }
    }

    /**
     * 创建权限请求（需要用户决策）
     *
     * @param string $toolName
     * @param array<string, mixed> $input
     * @return PermissionResult
     */
    protected function createRequest($toolName, array $input)
    {
        $this->requestCounter++;
        $requestId = 'req_' . $this->requestCounter . '_' . dechex(time());

        // 检查是否有未决的请求（避免重复）
        $summary = $this->describeInput($toolName, $input);
        $request = new PermissionRequest($requestId, [
            'sessionId'   => $this->sessionId ?: '',
            'toolName'    => $toolName,
            'input'       => $input,
            'description' => $summary,
        ]);
        $this->requests[$requestId] = $request;

        return PermissionResult::ask($this->askMessage($toolName, $summary), $request);
    }

    /**
     * 批准一个权限请求
     *
     * @param string $requestId
     * @return bool 请求是否存在且为 pending
     */
    public function approve($requestId)
    {
        if (!isset($this->requests[$requestId])) {
            return false;
        }
        $req = $this->requests[$requestId];
        if (!$req->isPending()) {
            return false;
        }
        $req->approve();
        return true;
    }

    /**
     * 拒绝一个权限请求
     *
     * @param string $requestId
     * @param string $reason
     * @return bool 请求是否存在且为 pending
     */
    public function deny($requestId, $reason = '')
    {
        if (!isset($this->requests[$requestId])) {
            return false;
        }
        $req = $this->requests[$requestId];
        if (!$req->isPending()) {
            return false;
        }
        $req->deny($reason);
        return true;
    }

    /**
     * 获取请求状态
     *
     * @param string $requestId
     * @return PermissionRequest|null
     */
    public function getRequest($requestId)
    {
        return isset($this->requests[$requestId]) ? $this->requests[$requestId] : null;
    }

    /**
     * 获取所有待处理的请求
     *
     * @return PermissionRequest[]
     */
    public function getPendingRequests()
    {
        $pending = [];
        foreach ($this->requests as $id => $req) {
            if ($req->isPending()) {
                $pending[$id] = $req;
            }
        }
        return $pending;
    }

    /**
     * 清理已处理的请求（approved / denied）
     *
     * @return $this
     */
    public function cleanRequests()
    {
        foreach ($this->requests as $id => $req) {
            if (!$req->isPending()) {
                unset($this->requests[$id]);
            }
        }
        return $this;
    }

    /**
     * @param PermissionRule $rule
     * @return string
     */
    protected function ruleDetail(PermissionRule $rule)
    {
        $patterns = $rule->getArgPatterns();
        if (!$patterns) {
            return '（' . $rule->getTool() . '）';
        }
        return '（' . $rule->getTool() . ' ' . json_encode($patterns, JSON_UNESCAPED_UNICODE) . '）';
    }

    /**
     * @param string $toolName
     * @param string $summary
     * @return string
     */
    protected function askMessage($toolName, $summary)
    {
        return '是否允许执行 ' . $toolName . (($summary !== '') ? '（' . $summary . '）' : '') . '？';
    }

    /**
     * @param string $toolName
     * @param array<string, mixed> $input
     * @return string
     */
    protected function describeInput($toolName, array $input)
    {
        if ($toolName === 'bash' && isset($input['command'])) {
            return mb_substr((string) $input['command'], 0, 120);
        }
        if (isset($input['path'])) {
            return (string) $input['path'];
        }
        return '';
    }
}