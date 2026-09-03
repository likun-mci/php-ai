<?php
namespace Ai\Agent\Budget;

/**
 * 预算管理器
 *
 * 跟踪 Agent 运行的 token 用量与估算成本，超过预算时停止 Agent。
 * 结合 AIResponse::cost() / costPerMillion() 的计价逻辑。
 *
 * 用法：
 * ```php
 * $bm = new BudgetManager([
 *     'maxTokens'   => 500000,
 *     'maxBudget'   => 5.0,
 *     'pricing'     => ['prompt' => 5.0, 'completion' => 25.0, 'cached' => 0.5],
 *     'perMillion'  => true,   // pricing 按每百万 token 计（官网价）
 * ]);
 *
 * $bm->record($response->getUsage());
 * if ($bm->exceeded()) { ... 停止 Agent ... }
 * ```
 */
class BudgetManager
{
    /** @var int 最大 token 数（0 = 不限） */
    protected $maxTokens = 0;

    /** @var float 最大预算（美元，0 = 不限） */
    protected $maxBudget = 0.0;

    /** @var array<string, mixed> 价格表 */
    protected $pricing = [];

    /** @var bool pricing 是否按每百万 token 计 */
    protected $perMillion = false;

    /** @var int 累计输入 token */
    protected $totalInputTokens = 0;

    /** @var int 累计输出 token */
    protected $totalOutputTokens = 0;

    /** @var int 累计缓存读取 token */
    protected $totalCachedTokens = 0;

    /** @var int 请求次数 */
    protected $requestCount = 0;

    /** @var float 累计估算成本 */
    protected $totalCost = 0.0;

    /** @var int 墙钟上限（秒，0 = 不限） */
    protected $maxDuration = 0;

    /** @var int 工具调用次数上限（0 = 不限） */
    protected $maxToolCalls = 0;

    /** @var float|null 计时起点（microtime），null = 还没开始 */
    protected $startedAt = null;

    /** @var int 累计工具调用次数 */
    protected $toolCalls = 0;

    /**
     * @param array<string, mixed> $options maxTokens / maxBudget / pricing / perMillion
     */
    public function __construct(array $options = [])
    {
        if (isset($options['maxTokens'])) {
            $this->maxTokens = max(0, (int) $options['maxTokens']);
        }
        if (isset($options['maxBudget'])) {
            $this->maxBudget = max(0.0, (float) $options['maxBudget']);
        }
        if (isset($options['pricing']) && is_array($options['pricing'])) {
            $this->pricing = $options['pricing'];
        }
        if (isset($options['maxDuration'])) {
            $this->maxDuration = max(0, (int) $options['maxDuration']);
        }
        if (isset($options['maxToolCalls'])) {
            $this->maxToolCalls = max(0, (int) $options['maxToolCalls']);
        }
        $this->perMillion = !empty($options['perMillion']);
    }

    /**
     * 开始计时
     *
     * 墙钟上限得有个起点。放在循环开始处调用，而不是构造时——
     * BudgetManager 常常在装配阶段就建好，离真正开跑可能隔着很久。
     * 重复调用不会重置，一次运行只有一个起点。
     *
     * @return $this
     */
    public function start()
    {
        if ($this->startedAt === null) {
            $this->startedAt = microtime(true);
        }
        return $this;
    }

    /**
     * 重置本次运行的计量
     *
     * 同一个实例跑第二个任务时，额度不该还是上一个任务用剩的。
     *
     * @return $this
     */
    public function reset()
    {
        $this->totalInputTokens = 0;
        $this->totalOutputTokens = 0;
        $this->totalCachedTokens = 0;
        $this->requestCount = 0;
        $this->totalCost = 0.0;
        $this->toolCalls = 0;
        $this->startedAt = null;
        return $this;
    }

    /**
     * 记录工具调用次数
     *
     * @param int $n
     * @return $this
     */
    public function recordToolCalls($n = 1)
    {
        $this->toolCalls += max(0, (int) $n);
        return $this;
    }

    /**
     * 已运行秒数
     *
     * @return float 还没 start() 时返回 0
     */
    public function elapsed()
    {
        return $this->startedAt === null ? 0.0 : (microtime(true) - $this->startedAt);
    }

    /** @return int */
    public function getToolCalls()
    {
        return $this->toolCalls;
    }

    /**
     * 记录一次响应的用量
     *
     * @param array<string, mixed> $usage AIResponse::getUsage() 的结构
     * @return $this
     */
    public function record(array $usage)
    {
        $prompt = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
        $cached = (int) (
            $usage['prompt_tokens_details']['cached_tokens']
            ?? $usage['cache_read_input_tokens']
            ?? 0
        );

        $this->totalInputTokens += $prompt;
        $this->totalOutputTokens += $completion;
        $this->totalCachedTokens += $cached;
        $this->requestCount++;

        if ($this->pricing) {
            $this->totalCost += $this->estimateCost($prompt, $completion, $cached);
        }
        return $this;
    }

    /** @return int */
    public function getTotalTokens() { return $this->totalInputTokens + $this->totalOutputTokens; }
    /** @return int */
    public function getInputTokens() { return $this->totalInputTokens; }
    /** @return int */
    public function getOutputTokens() { return $this->totalOutputTokens; }
    /** @return int */
    public function getCachedTokens() { return $this->totalCachedTokens; }
    /** @return int */
    public function getRequestCount() { return $this->requestCount; }
    /** @return float */
    public function getTotalCost() { return $this->totalCost; }

    /**
     * 是否超过预算
     *
     * @return bool
     */
    public function exceeded()
    {
        return $this->summary()['exceeded'];
    }

    /**
     * 预算超限详情
     *
     * @return array{exceeded: bool, reason: string, tokens: int, cost: float,
     *               elapsed: float, tool_calls: int}
     */
    public function summary()
    {
        $exceeded = false;
        $reason = '';
        if ($this->maxTokens > 0 && $this->getTotalTokens() > $this->maxTokens) {
            $exceeded = true;
            $reason = 'token 超限（' . $this->getTotalTokens() . ' > ' . $this->maxTokens . '）';
        }
        if ($this->maxBudget > 0 && $this->totalCost > $this->maxBudget) {
            $exceeded = true;
            $reason = '预算超限（$' . round($this->totalCost, 4) . ' > $' . $this->maxBudget . '）';
        }
        // 墙钟排在 token 之后判：一个卡在慢工具上的任务可能一个 token 都没多花，
        // 却已经占着 HTTP 连接跑了十分钟。生产环境里超时比超支更常见
        $elapsed = $this->elapsed();
        if ($this->maxDuration > 0 && $elapsed > $this->maxDuration) {
            $exceeded = true;
            $reason = '超过墙钟上限（' . round($elapsed, 1) . 's > ' . $this->maxDuration . 's）';
        }
        if ($this->maxToolCalls > 0 && $this->toolCalls > $this->maxToolCalls) {
            $exceeded = true;
            $reason = '工具调用次数超限（' . $this->toolCalls . ' > ' . $this->maxToolCalls . '）';
        }
        return [
            'exceeded'   => $exceeded,
            'reason'     => $reason,
            'tokens'     => $this->getTotalTokens(),
            'cost'       => $this->totalCost,
            'elapsed'    => round($elapsed, 3),
            'tool_calls' => $this->toolCalls,
        ];
    }

    /**
     * 估算单次请求成本
     *
     * @param int $prompt
     * @param int $completion
     * @param int $cached
     * @return float
     */
    protected function estimateCost($prompt, $completion, $cached)
    {
        $perTokens = $this->perMillion ? 1000000 : 1000;
        $prompt = max(0, $prompt - $cached);

        $cost = 0.0;
        if (isset($this->pricing['cached']) && $cached > 0) {
            $cost += ($cached / $perTokens) * (float) $this->pricing['cached'];
        }
        $cost += ($prompt / $perTokens) * (float) ($this->pricing['prompt'] ?? 0);
        $cost += ($completion / $perTokens) * (float) ($this->pricing['completion'] ?? 0);
        return $cost;
    }
}