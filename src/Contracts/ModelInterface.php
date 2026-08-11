<?php
namespace Ai\Contracts;

/**
 * 模型接口
 */
interface ModelInterface
{
    /**
     * 获取模型名称
     * @return string
     */
    public function getName(): string;
    
    /**
     * 获取所属平台
     * @return string
     */
    public function getPlatform(): string;
    
    /**
     * 获取使用的协议
     * @return string 协议类名
     */
    public function getProtocol(): string;

    /**
     * 获取模型的默认 API 端点，无默认端点时返回空串
     */
    public function getEndpoint(): string;
    
    /**
     * 检查是否支持某功能
     * @param string $feature 功能名称
     * @return bool
     */
    public function supports(string $feature): bool;
    
    /**
     * 获取支持的功能列表
     * @return array<mixed>
     */
    public function getFeatures(): array;
    
    /**
     * 获取模型配置
     * @return array<string, mixed>
     */
    public function getConfig(): array;
    
    /**
     * 处理附件，将附件转换为模型特定格式
     * @param array<string, mixed> $payload 请求负载（包含messages）
     * @param array<int, \Ai\Helpers\AIFile> $attachments 附件数组（AIFile对象数组）
     * @return array<string, mixed> 处理后的请求负载
     */
    public function processAttachments(array $payload, array $attachments): array;
}
