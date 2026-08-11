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

    public function __construct(array $data)
    {
        $this->content = $data['content'] ?? '';
        $this->model = $data['model'] ?? '';
        $this->usage = $data['usage'] ?? [];
        $this->raw = $data['raw'] ?? [];
        $this->success = $data['success'] ?? true;
        $this->toolCalls = $data['tool_calls'] ?? [];
        $this->stopReason = $data['stop_reason'] ?? '';
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
     * 估算费用（需要配置价格表）
     */
    public function cost(array $pricing = []): float
    {
        if (empty($pricing)) {
            return 0.0;
        }
        
        $promptTokens = $this->usage['prompt_tokens'] ?? 0;
        $completionTokens = $this->usage['completion_tokens'] ?? 0;
        
        $promptCost = ($promptTokens / 1000) * ($pricing['prompt'] ?? 0);
        $completionCost = ($completionTokens / 1000) * ($pricing['completion'] ?? 0);
        
        return $promptCost + $completionCost;
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
