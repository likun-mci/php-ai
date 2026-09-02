<?php
namespace Ai\Agent\Orchestrator;

/**
 * ModelRouter——按任务选模型
 *
 * 让 explorer 用最强的模型去 grep 代码是浪费，让 coder 用最便宜的模型去改架构
 * 是省小钱花大钱。路由按「任务复杂度 + 角色 + 预算 + 优先级」挑一个合适的。
 *
 * ```php
 * $router = new ModelRouter([
 *     'cheap'    => 'claude-haiku-4-5-20251001',
 *     'standard' => 'claude-sonnet-5',
 *     'premium'  => 'claude-opus-5',
 * ]);
 *
 * $router->route(['agent' => 'explorer']);              // cheap
 * $router->route(['agent' => 'coder']);                 // premium
 * $router->route(['task' => '重构整个认证系统']);        // premium（复杂度高）
 * $router->route(['agent' => 'coder', 'budget_left' => 0.05]);  // cheap（预算快没了）
 * ```
 *
 * **不配置模型名就不路由**：返回空串，调用方沿用当前模型。硬塞一个猜的模型名，
 * 换来的是运行时报「模型不存在」。
 */
class ModelRouter
{
    const TIER_CHEAP    = 'cheap';
    const TIER_STANDARD = 'standard';
    const TIER_PREMIUM  = 'premium';

    /** @var array<string, string> 档位 => 模型名 */
    protected $tiers = [];

    /** @var array<string, string> 角色 => 档位 */
    protected $agentTiers = [
        'explorer' => self::TIER_CHEAP,
        'planner'  => self::TIER_STANDARD,
        'tester'   => self::TIER_STANDARD,
        'debugger' => self::TIER_STANDARD,
        'coder'    => self::TIER_PREMIUM,
        'reviewer' => self::TIER_PREMIUM,
        'security' => self::TIER_PREMIUM,
    ];

    /** @var float 剩余预算比例低于它就降档 */
    protected $budgetFloor = 0.15;

    /** @var callable|null 自定义路由器 */
    protected $resolver = null;

    /**
     * @param array<string, string> $tiers 档位 => 模型名
     * @param array<string, mixed> $options agentTiers / budgetFloor / resolver
     */
    public function __construct(array $tiers = [], array $options = [])
    {
        foreach ($tiers as $tier => $model) {
            $this->tiers[(string) $tier] = (string) $model;
        }
        if (isset($options['agentTiers']) && is_array($options['agentTiers'])) {
            foreach ($options['agentTiers'] as $agent => $tier) {
                $this->agentTiers[(string) $agent] = (string) $tier;
            }
        }
        if (isset($options['budgetFloor'])) {
            $this->budgetFloor = (float) $options['budgetFloor'];
        }
        if (isset($options['resolver'])) {
            $this->resolver = $options['resolver'];
        }
    }

    /**
     * 为一次执行挑模型
     *
     * @param array<string, mixed> $context agent / task / priority / budget_left / complexity
     * @return string 模型名；没配置对应档位时返回空串（沿用当前模型）
     */
    public function route(array $context = [])
    {
        if ($this->resolver !== null) {
            $model = call_user_func($this->resolver, $context);
            if (is_string($model) && $model !== '') {
                return $model;
            }
        }

        $tier = $this->tierFor($context);
        return isset($this->tiers[$tier]) ? $this->tiers[$tier] : '';
    }

    /**
     * 挑出档位（不看有没有配对应模型）
     *
     * @param array<string, mixed> $context
     * @return string
     */
    public function tierFor(array $context = [])
    {
        // 预算见底：一律降到最便宜的档，先把任务跑完比跑得漂亮重要
        if (isset($context['budget_left'])) {
            $left = (float) $context['budget_left'];
            if ($left >= 0 && $left < $this->budgetFloor) {
                return self::TIER_CHEAP;
            }
        }

        // critical 优先级直接上最好的
        if (isset($context['priority']) && $context['priority'] === AgentScheduler::PRIORITY_CRITICAL) {
            return self::TIER_PREMIUM;
        }

        // 角色决定档位
        $agent = isset($context['agent']) ? (string) $context['agent'] : '';
        if ($agent !== '' && isset($this->agentTiers[$agent])) {
            return $this->agentTiers[$agent];
        }

        // 没有角色信息就看任务复杂度
        $complexity = isset($context['complexity'])
            ? (float) $context['complexity']
            : $this->estimateComplexity(isset($context['task']) ? (string) $context['task'] : '');

        if ($complexity >= 0.7) {
            return self::TIER_PREMIUM;
        }
        if ($complexity <= 0.3) {
            return self::TIER_CHEAP;
        }
        return self::TIER_STANDARD;
    }

    /**
     * 粗估任务复杂度（0～1）
     *
     * 看关键词与长度。这只是启发式——真要精确判断复杂度得先理解任务，
     * 那本身就得调一次模型，得不偿失。
     *
     * @param string $task
     * @return float
     */
    public function estimateComplexity($task)
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower((string) $task, 'UTF-8') : strtolower((string) $task);
        if (trim($text) === '') {
            return 0.5;
        }

        $score = 0.4;
        $isHeavy = false;

        $heavy = ['重构', '架构', '迁移', '整个', '全面', '设计', '优化性能',
                  'refactor', 'architect', 'migrate', 'redesign', 'optimize'];
        foreach ($heavy as $keyword) {
            if (strpos($text, $keyword) !== false) {
                $score += 0.35;
                $isHeavy = true;
                break;
            }
        }

        if (!$isHeavy) {
            $light = ['读', '查看', '列出', '搜索', '找一下', 'read', 'list', 'show', 'find', 'grep'];
            foreach ($light as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    $score -= 0.2;
                    break;
                }
            }
        }

        // 长度只是弱信号：中文描述天然比英文短，「重构整个认证系统」九个字并不简单，
        // 所以命中重活关键词时不再按长度往下压
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length > 80) {
            $score += 0.15;
        } elseif ($length < 15 && !$isHeavy) {
            $score -= 0.1;
        }

        return max(0.0, min(1.0, $score));
    }

    /**
     * 配置一个档位的模型
     *
     * @param string $tier
     * @param string $model
     * @return $this
     */
    public function setTier($tier, $model)
    {
        $this->tiers[(string) $tier] = (string) $model;
        return $this;
    }

    /**
     * 指定角色用哪个档位
     *
     * @param string $agent
     * @param string $tier
     * @return $this
     */
    public function setAgentTier($agent, $tier)
    {
        $this->agentTiers[(string) $agent] = (string) $tier;
        return $this;
    }

    /**
     * 注入自定义路由逻辑
     *
     * @param callable|null $resolver function(array $context): string
     * @return $this
     */
    public function setResolver($resolver)
    {
        $this->resolver = $resolver;
        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function tiers()
    {
        return $this->tiers;
    }

    /**
     * 有没有配置任何模型
     *
     * @return bool
     */
    public function isConfigured()
    {
        return $this->tiers !== [];
    }
}
