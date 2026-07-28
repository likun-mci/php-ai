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
    
    public function __construct(array $data)
    {
        $this->content = $data['content'] ?? '';
        $this->model = $data['model'] ?? '';
        $this->usage = $data['usage'] ?? [];
        $this->raw = $data['raw'] ?? [];
        $this->success = $data['success'] ?? true;
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
            'content' => $this->content,
            'model' => $this->model,
            'usage' => $this->usage,
            'success' => $this->success,
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
