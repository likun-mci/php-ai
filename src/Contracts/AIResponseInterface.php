<?php
namespace Ai\Contracts;

/**
 * AI 响应接口
 */
interface AIResponseInterface
{
    /**
     * 获取响应内容
     * @return string
     */
    public function getContent(): string;
    
    /**
     * 获取原始响应数据
     * @return array<string, mixed>
     */
    public function getRaw(): array;
    
    /**
     * 获取使用情况统计
     * @return array<string, mixed>
     */
    public function getUsage(): array;
    
    /**
     * 获取模型名称
     * @return string
     */
    public function getModel(): string;
    
    /**
     * 是否成功
     * @return bool
     */
    public function isSuccess(): bool;

    /**
     * 模型发起的工具调用（已跨平台归一）
     * @return array<int, array{id: string, name: string, input: array<string, mixed>}> [['id'=>'调用ID','name'=>'工具名','input'=>[参数]], ...]
     */
    public function getToolCalls(): array;

    /**
     * 本轮是否要求调用工具
     */
    public function hasToolCalls(): bool;

    /**
     * 结束原因（已归一）：end_turn / tool_use / max_tokens / content_filter / refusal
     */
    public function getStopReason(): string;

    /**
     * 转成可回填进 messages 的 assistant 回合
     * @return array{role: string, content: array<int, array<string, mixed>>}
     */
    public function toAssistantMessage(): array;

    /**
     * 失败原因（仅 chatBatch() 等不抛异常的场景会填充）
     */
    public function getError(): string;
}
