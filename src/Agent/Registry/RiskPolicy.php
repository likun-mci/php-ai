<?php
namespace Ai\Agent\Registry;

use Ai\Agent\Tool\ToolDefinition;

/**
 * 风险策略 —— 决定一个 Tool 执行前要不要人工确认
 *
 * **风险不是权限**（规范 §4.4）。`article.delete` 即使权限校验通过，也可以因为
 * `risk = high` 而要求用户点一下确认。反过来，风险再低也不能替代权限校验。
 *
 * 默认策略（规范 §16）：
 *
 * | 等级 | 默认行为 |
 * |---|---|
 * | low | 直接执行 |
 * | medium | 直接执行（权限由 Controller 把关） |
 * | high | 需要确认 |
 * | critical | 需要确认，且**不能**被阈值绕过 |
 *
 * 应用可以覆盖：
 * ```php
 * $policy = new RiskPolicy();
 * $policy->setThreshold(ToolDefinition::RISK_MEDIUM);        // 中风险起就要确认
 * $policy->setOverride('article.delete', false);             // 单个 Tool 免确认
 * ```
 *
 * `critical` 的强制确认是最后一道闸：只有显式调用
 * `allowCriticalWithoutConfirm(true)` 才能关掉，避免「调了个阈值，顺手把删库也放行了」。
 */
class RiskPolicy
{
    /** @var string 从这个等级起需要确认 */
    protected $threshold = ToolDefinition::RISK_HIGH;

    /** @var array<string, bool> Tool 名 => 是否需要确认（覆盖一切推导） */
    protected $overrides = [];

    /** @var bool critical 是否允许免确认（默认否） */
    protected $allowCriticalWithoutConfirm = false;

    /**
     * @param array<string, mixed> $options threshold / overrides / allow_critical_without_confirm
     */
    public function __construct(array $options = [])
    {
        if (isset($options['threshold'])) {
            $this->setThreshold($options['threshold']);
        }
        if (isset($options['overrides']) && is_array($options['overrides'])) {
            foreach ($options['overrides'] as $name => $need) {
                $this->setOverride((string) $name, (bool) $need);
            }
        }
        if (isset($options['allow_critical_without_confirm'])) {
            $this->allowCriticalWithoutConfirm = (bool) $options['allow_critical_without_confirm'];
        }
    }

    /**
     * @param string $risk low/medium/high/critical
     * @return $this
     */
    public function setThreshold($risk)
    {
        $this->threshold = ToolDefinition::normalizeRisk($risk);
        return $this;
    }

    /** @return string */
    public function getThreshold()
    {
        return $this->threshold;
    }

    /**
     * @param string $toolName
     * @param bool $needsConfirmation
     * @return $this
     */
    public function setOverride($toolName, $needsConfirmation)
    {
        $this->overrides[(string) $toolName] = (bool) $needsConfirmation;
        return $this;
    }

    /**
     * 允许 critical 级 Tool 免确认
     *
     * ⚠️ 只有在应用自己已经有等价的二次确认机制时才该打开。
     *
     * @param bool $allow
     * @return $this
     */
    public function allowCriticalWithoutConfirm($allow = true)
    {
        $this->allowCriticalWithoutConfirm = (bool) $allow;
        return $this;
    }

    /**
     * 这个 Tool 需要人工确认吗
     *
     * 判定顺序：Tool 级 override → critical 强制 → PHPDoc 里显式写过的
     * `@agent-confirm` → 风险等级阈值。
     *
     * @param ToolDefinition $tool
     * @return bool
     */
    public function needsConfirmation(ToolDefinition $tool)
    {
        $name = $tool->getName();
        if (array_key_exists($name, $this->overrides)) {
            // critical 的强制确认连 override 也不让绕，除非显式打开开关
            if ($this->overrides[$name] === false
                && $tool->getRisk() === ToolDefinition::RISK_CRITICAL
                && !$this->allowCriticalWithoutConfirm
            ) {
                return true;
            }
            return $this->overrides[$name];
        }

        if ($tool->getRisk() === ToolDefinition::RISK_CRITICAL) {
            return !$this->allowCriticalWithoutConfirm;
        }

        // 源码里显式写了 @agent-confirm true/false，尊重它
        if ($tool->isConfirmDeclared()) {
            return $tool->requiresConfirmation();
        }

        return ToolDefinition::riskWeight($tool->getRisk())
            >= ToolDefinition::riskWeight($this->threshold);
    }

    /**
     * 是否为「强制确认」（用户不能通过配置跳过）
     *
     * @param ToolDefinition $tool
     * @return bool
     */
    public function isForced(ToolDefinition $tool)
    {
        return $tool->getRisk() === ToolDefinition::RISK_CRITICAL
            && !$this->allowCriticalWithoutConfirm;
    }
}
