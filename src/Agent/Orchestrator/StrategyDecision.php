<?php
namespace Ai\Agent\Orchestrator;

/**
 * StrategyDecision——一次策略决策的结果
 *
 * 除了「选了哪种策略」，更重要的是 `reason`——Agent 自主选策略之后，
 * 使用者必须能复盘「为什么它决定派子 Agent 而不是自己干」，否则出了问题无从查起。
 * 所以每个决策都会带着理由进事件流（`strategy_decision` 事件）。
 *
 * ```php
 * $decision = StrategyDecision::delegate('explorer', '任务涉及大规模代码库分析');
 * $decision->getStrategy();   // 'delegate'
 * $decision->getAgent();      // 'explorer'
 * $decision->toArray();       // 进事件流的结构
 * ```
 */
class StrategyDecision
{
    /** @var string 选中的策略 */
    protected $strategy = ExecutionStrategy::DIRECT;

    /** @var string 选择理由 */
    protected $reason = '';

    /** @var string 委派目标子 Agent 名，非委派策略为空串 */
    protected $agent = '';

    /** @var string[] 并行策略下的多个子任务 */
    protected $subtasks = [];

    /** @var bool 是否后台执行 */
    protected $background = false;

    /** @var bool 是否并行执行 */
    protected $parallel = false;

    /** @var float 决策置信度 0～1 */
    protected $confidence = 1.0;

    /** @var array<string, mixed> 额外信息 */
    protected $metadata = [];

    /**
     * @param string $strategy
     * @param string $reason
     * @param array<string, mixed> $options agent / subtasks / background / parallel / confidence / metadata
     */
    public function __construct($strategy, $reason = '', array $options = [])
    {
        $this->strategy = ExecutionStrategy::isValid($strategy)
            ? (string) $strategy
            : ExecutionStrategy::DIRECT;
        $this->reason = (string) $reason;

        if (isset($options['agent'])) {
            $this->agent = (string) $options['agent'];
        }
        if (isset($options['subtasks']) && is_array($options['subtasks'])) {
            $this->subtasks = array_values(array_map('strval', $options['subtasks']));
        }
        if (isset($options['background'])) {
            $this->background = (bool) $options['background'];
        }
        if (isset($options['parallel'])) {
            $this->parallel = (bool) $options['parallel'];
        }
        if (isset($options['confidence'])) {
            $this->confidence = max(0.0, min(1.0, (float) $options['confidence']));
        }
        if (isset($options['metadata']) && is_array($options['metadata'])) {
            $this->metadata = $options['metadata'];
        }

        // 策略与标志位保持一致，避免出现 strategy=background 但 background=false 这种自相矛盾
        if ($this->strategy === ExecutionStrategy::BACKGROUND) {
            $this->background = true;
        }
        if ($this->strategy === ExecutionStrategy::PARALLEL) {
            $this->parallel = true;
        }
    }

    /**
     * 直接执行
     *
     * @param string $reason
     * @param float $confidence
     * @return self
     */
    public static function direct($reason = '任务简单，直接执行', $confidence = 1.0)
    {
        return new self(ExecutionStrategy::DIRECT, $reason, ['confidence' => $confidence]);
    }

    /**
     * 先规划
     *
     * @param string $reason
     * @param string[] $steps 预拆的步骤（可选）
     * @return self
     */
    public static function plan($reason = '任务复杂，先拆解步骤', array $steps = [])
    {
        return new self(ExecutionStrategy::PLAN, $reason, ['subtasks' => $steps]);
    }

    /**
     * 委派给子 Agent
     *
     * @param string $agent
     * @param string $reason
     * @param bool $background
     * @return self
     */
    public static function delegate($agent, $reason = '', $background = false)
    {
        return new self(ExecutionStrategy::DELEGATE, $reason, [
            'agent'      => $agent,
            'background' => $background,
        ]);
    }

    /**
     * 并行执行多个子任务
     *
     * @param string[] $subtasks
     * @param string $reason
     * @param string $agent 统一用哪个子 Agent 跑，空则各自决定
     * @return self
     */
    public static function parallel(array $subtasks, $reason = '多个互不相关的子任务', $agent = '')
    {
        return new self(ExecutionStrategy::PARALLEL, $reason, [
            'subtasks' => $subtasks,
            'agent'    => $agent,
        ]);
    }

    /**
     * 后台执行
     *
     * @param string $reason
     * @param string $agent
     * @return self
     */
    public static function background($reason = '任务耗时较长，转后台执行', $agent = '')
    {
        return new self(ExecutionStrategy::BACKGROUND, $reason, ['agent' => $agent]);
    }

    /**
     * 先问用户
     *
     * @param string $reason
     * @return self
     */
    public static function askUser($reason = '需求不明确，需要用户澄清')
    {
        return new self(ExecutionStrategy::ASK_USER, $reason);
    }

    /**
     * 只做验证
     *
     * @param string $reason
     * @return self
     */
    public static function verify($reason = '任务是验证已有改动')
    {
        return new self(ExecutionStrategy::VERIFY, $reason);
    }

    /** @return string */
    public function getStrategy()
    {
        return $this->strategy;
    }

    /** @return string */
    public function getReason()
    {
        return $this->reason;
    }

    /** @return string */
    public function getAgent()
    {
        return $this->agent;
    }

    /**
     * @param string $agent
     * @return $this
     */
    public function setAgent($agent)
    {
        $this->agent = (string) $agent;
        return $this;
    }

    /** @return string[] */
    public function getSubtasks()
    {
        return $this->subtasks;
    }

    /** @return bool */
    public function isBackground()
    {
        return $this->background;
    }

    /** @return bool */
    public function isParallel()
    {
        return $this->parallel;
    }

    /** @return float */
    public function getConfidence()
    {
        return $this->confidence;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata()
    {
        return $this->metadata;
    }

    /**
     * 是不是某个策略
     *
     * @param string $strategy
     * @return bool
     */
    public function is($strategy)
    {
        return $this->strategy === (string) $strategy;
    }

    /**
     * 转成进事件流的结构
     *
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'strategy'   => $this->strategy,
            'reason'     => $this->reason,
            'agent'      => $this->agent,
            'subtasks'   => $this->subtasks,
            'background' => $this->background,
            'parallel'   => $this->parallel,
            'confidence' => $this->confidence,
            'metadata'   => $this->metadata,
        ];
    }

    /**
     * 一行说明，给人看
     *
     * @return string
     */
    public function toSummary()
    {
        $text = '策略：' . $this->strategy;
        if ($this->agent !== '') {
            $text .= '（' . $this->agent . '）';
        }
        if ($this->reason !== '') {
            $text .= ' —— ' . $this->reason;
        }
        return $text;
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data)
    {
        $strategy = isset($data['strategy']) ? (string) $data['strategy'] : ExecutionStrategy::DIRECT;
        $reason = isset($data['reason']) ? (string) $data['reason'] : '';
        return new self($strategy, $reason, $data);
    }
}
