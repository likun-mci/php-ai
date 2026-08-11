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
     * @return array
     */
    public function getRaw(): array;
    
    /**
     * 获取使用情况统计
     * @return array
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
}
