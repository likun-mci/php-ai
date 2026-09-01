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
 * 询问用户回调：
 *   $pm->onAsk(function (array $request) { ... return true/false; });
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

    /**
     * @param string $mode 初始权限模式
     */
    public function __construct($mode = self::MODE_MANUAL)
    {
        $this->setMode($mode);
    }

    /** 设置权限模式
     * @param string $mode
     * @return $this
     */
    public function setMode($mode)
    {
        $valid = [self::MODE_MANUAL, self::MODE_AUTO, self::MODE_PLAN,
                  self::MODE_ACCEPT_EDITS, self::MODE_DONT_ASK, self::MODE_BYPASS];
        if (in_array($mode, $valid, true)) {
            $this->mode = $mode;
        }
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
                return $this->ask($toolName, $input);

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
                    return $this->ask($toolName, $input);
                }
                return PermissionResult::allow();
        }
    }

    /**
     * 询问用户（manual/accept_edits 模式的危险操作走这里）
     *
     * @param string $toolName
     * @param array<string, mixed> $input
     * @return PermissionResult
     */
    protected function ask($toolName, array $input)
    {
        if ($this->askHandler) {
            $request = [
                'tool'  => $toolName,
                'input' => $input,
            ];
            $decision = call_user_func($this->askHandler, $request);
            if ($decision instanceof PermissionResult) {
                return $decision;
            }
            if ($decision) {
                return PermissionResult::allow();
            }
            return PermissionResult::deny('用户拒绝执行 ' . $toolName);
        }
        // 没有询问回调 → 默认拒绝（安全优先）
        return PermissionResult::deny('缺少权限询问回调，已拒绝执行 ' . $toolName);
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
}