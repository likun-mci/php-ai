<?php
namespace Ai\Response;

use Ai\Contracts\AIResponseInterface;

/**
 * AI 响应对象
 */
class AIResponse implements AIResponseInterface
{
    protected $content;
    protected $model;
    protected $usage;
    protected $raw;
    protected $success;
    protected $toolCalls;
    protected $stopReason;
    protected $error;

    public function __construct(array $data)
    {
        $this->content = $data['content'] ?? '';
        $this->model = $data['model'] ?? '';
        $this->usage = $data['usage'] ?? [];
        $this->raw = $data['raw'] ?? [];
        $this->success = $data['success'] ?? true;
        $this->toolCalls = $data['tool_calls'] ?? [];
        $this->stopReason = $data['stop_reason'] ?? '';
        $this->error = $data['error'] ?? '';
    }

    /**
     * 失败原因
     *
     * 只有 chatBatch() 这类「单条失败不抛异常」的场景会填充；
     * chat() 失败时直接抛 AIException，这里始终为空串。
     */
    public function getError(): string
    {
        return $this->error;
    }

    /**
     * 模型发起的工具调用（已归一，各平台格式一致）
     *
     * @return array [['id'=>'调用ID', 'name'=>'工具名', 'input'=>[参数数组]], ...]
     *               模型没有调用工具时返回空数组
     */
    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    /**
     * 本轮模型是否要求调用工具
     */
    public function hasToolCalls(): bool
    {
        return !empty($this->toolCalls);
    }

    /**
     * 结束原因（已归一）
     *
     * 取值：end_turn 正常结束 / tool_use 要调工具 / max_tokens 长度截断 /
     *      stop_sequence 命中停止词 / content_filter 被审核拦下 / refusal 模型拒答
     */
    public function getStopReason(): string
    {
        return $this->stopReason;
    }

    /**
     * 转成可直接回填进 messages 的 assistant 回合
     *
     * 多轮工具调用时把模型这一轮的输出（文本 + tool_use 块）原样接回上下文，
     * 格式为库的统一格式，协议层会按目标平台改写。
     */
    public function toAssistantMessage(): array
    {
        $blocks = [];
        if ($this->content !== '') {
            $blocks[] = ['type' => 'text', 'text' => $this->content];
        }
        foreach ($this->toolCalls as $call) {
            $blocks[] = [
                'type'  => 'tool_use',
                'id'    => $call['id'] ?? '',
                'name'  => $call['name'] ?? '',
                'input' => $call['input'] ?? [],
            ];
        }
        return ['role' => 'assistant', 'content' => $blocks];
    }
    
    /**
     * 获取响应内容
     */
    public function getContent(): string
    {
        return $this->content;
    }
    
    /**
     * 获取原始响应数据
     */
    public function getRaw(): array
    {
        return $this->raw;
    }
    
    /**
     * 获取使用情况统计
     */
    public function getUsage(): array
    {
        return $this->usage;
    }
    
    /**
     * 获取模型名称
     */
    public function getModel(): string
    {
        return $this->model;
    }
    
    /**
     * 是否成功
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }
    
    /**
     * 获取 Token 使用量
     */
    public function tokens(): int
    {
        return $this->usage['total_tokens'] ?? 0;
    }
    
    /**
     * 估算费用（需要自行传入价格表）
     *
     * ⚠️ **本方法的默认计价基数是「每千 token」**，与旧版本保持一致，不会让
     * 已有代码的账算错。但各家官网现在都按**每百万**标价，把官网数字直接抄进
     * 这里会算出 1000 倍的费用——照抄官网数字请用 costPerMillion()。
     *
     * ```php
     * $response->cost(['prompt' => 0.005, 'completion' => 0.025]);          // 每千
     * $response->costPerMillion(['prompt' => 5.0, 'completion' => 25.0]);   // 每百万（推荐）
     * ```
     *
     * 缓存 token：多数平台把命中缓存的输入量单列（OpenAI 系放在
     * `prompt_tokens_details.cached_tokens`，Anthropic 系是
     * `cache_read_input_tokens`）。传了 `cached` 价格时，这部分会从
     * 普通输入里扣除后单独按缓存价计算；不传则全按普通输入价算。
     *
     * @param array $pricing ['prompt'=>输入价, 'completion'=>输出价, 'cached'=>缓存输入价]
     * @param int   $perTokens 计价基数，**默认 1000（每千 token）**，保持与旧版本一致。
     *                         各家官网现在都按每百万标价，直接抄官网数字请用
     *                         costPerMillion()，或显式传 1000000
     * @return float 估算费用，单位与价格表一致（通常是美元）
     */
    public function cost(array $pricing = [], int $perTokens = 1000): float
    {
        if (empty($pricing) || $perTokens <= 0) {
            return 0.0;
        }

        $prompt     = (int) ($this->usage['prompt_tokens'] ?? 0);
        $completion = (int) ($this->usage['completion_tokens'] ?? 0);

        // 命中缓存的输入量：两大家族字段名不同，都认
        $cached = (int) (
            $this->usage['prompt_tokens_details']['cached_tokens']
            ?? $this->usage['cache_read_input_tokens']
            ?? 0
        );

        $cost = 0.0;
        if (isset($pricing['cached']) && $cached > 0) {
            // 缓存部分单独计价，其余按普通输入价
            $cached = min($cached, $prompt);
            $cost  += ($cached / $perTokens) * (float) $pricing['cached'];
            $prompt -= $cached;
        }

        $cost += ($prompt / $perTokens) * (float) ($pricing['prompt'] ?? 0);
        $cost += ($completion / $perTokens) * (float) ($pricing['completion'] ?? 0);

        return $cost;
    }
    
    /**
     * 估算费用——价格按**每百万 token**计，可直接抄各家官网的数字
     *
     * ```php
     * // Claude Opus 5 官网价：输入 $5/1M、输出 $25/1M、缓存读 $0.5/1M
     * $response->costPerMillion([
     *     'prompt'     => 5.0,
     *     'completion' => 25.0,
     *     'cached'     => 0.5,   // 可选，命中缓存的输入价
     * ]);
     * ```
     *
     * 缓存 token：多数平台把命中缓存的输入量单列（OpenAI 系在
     * `prompt_tokens_details.cached_tokens`，Anthropic 系是
     * `cache_read_input_tokens`）。传了 `cached` 价时这部分从普通输入里
     * 扣除后单独计算；不传则全按普通输入价算。
     *
     * @param array $pricing ['prompt'=>每百万输入价, 'completion'=>每百万输出价, 'cached'=>每百万缓存输入价]
     */
    public function costPerMillion(array $pricing = []): float
    {
        return $this->cost($pricing, 1000000);
    }

    /**
     * 转为数组
     */
    public function toArray(): array
    {
        return [
            'content'     => $this->content,
            'model'       => $this->model,
            'usage'       => $this->usage,
            'success'     => $this->success,
            'tool_calls'  => $this->toolCalls,
            'stop_reason' => $this->stopReason,
        ];
    }
    
    /**
     * 转为字符串
     */
    public function __toString(): string
    {
        return $this->content;
    }
}
