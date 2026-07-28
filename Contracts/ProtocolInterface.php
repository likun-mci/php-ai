<?php
namespace Ai\Contracts;

/**
 * 协议接口
 * 不同 AI 平台使用不同的 API 协议与字段格式
 */
interface ProtocolInterface
{
    /**
     * 构建请求数据
     * @param array $payload 标准化的请求数据
     * @return array 平台特定的请求数据
     */
    public function buildRequest(array $payload): array;
    
    /**
     * 解析响应数据
     * @param array $response 平台返回的原始数据
     * @return AIResponseInterface 统一的响应对象
     */
    public function parseResponse(array $response): AIResponseInterface;
    
    /**
     * 构建请求头
     * @param array $config 配置信息
     * @return array HTTP 请求头
     */
    public function buildHeaders(array $config): array;
    
    /**
     * 解析流式数据块，提取内容
     * @param array $chunk 流式数据块
     * @return string|null 提取的文本内容，无内容返回 null
     */
    public function parseStreamChunk(array $chunk): ?string;
    
    /**
     * 判断流式数据是否结束
     * @param array $chunk 流式数据块
     * @return bool 是否为结束标记
     */
    public function isStreamEnd(array $chunk): bool;
    
    /**
     * 列举可用模型列表
     * @param array $config 配置信息（包含 API key 等）
     * @param TransportInterface $transport 传输层实例
     * @return array|null 模型列表数组 ['model_id' => 'model_name']，不支持返回 null
     */
    public function listModels(array $config, $transport): ?array;
}
