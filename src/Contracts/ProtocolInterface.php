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
     * @param array<string, mixed> $payload 标准化的请求数据
     * @return array<string, mixed> 平台特定的请求数据
     */
    public function buildRequest(array $payload): array;
    
    /**
     * 解析响应数据
     * @param array<string, mixed> $response 平台返回的原始数据
     * @return AIResponseInterface 统一的响应对象
     */
    public function parseResponse(array $response): AIResponseInterface;
    
    /**
     * 构建请求头
     * @param array<string, mixed> $config 配置信息
     * @return array<string, string> HTTP 请求头
     */
    public function buildHeaders(array $config): array;
    
    /**
     * 解析流式数据块，提取内容
     * @param array<string, mixed> $chunk 流式数据块
     * @return string|null 提取的文本内容，无内容返回 null
     */
    public function parseStreamChunk(array $chunk): ?string;
    
    /**
     * 判断流式数据是否结束
     * @param array<string, mixed> $chunk 流式数据块
     * @return bool 是否为结束标记
     */
    public function isStreamEnd(array $chunk): bool;
    
    /**
     * 列举可用模型列表
     * @param array<string, mixed> $config 配置信息（包含 API key 等）
     * @param TransportInterface $transport 传输层实例
     * @return array<string, mixed>|null 模型列表数组 ['model_id' => 'model_name']，不支持返回 null
     */
    public function listModels(array $config, $transport): ?array;

    // =================================================================
    // 以下为对话之外的扩展能力（图像 / 语音 / 视频 / 向量 / 实时通道）
    //
    // 设计成「能力标识做参数」而不是每种能力各给一组方法，是为了让接口
    // 就此定型：将来再加能力（比如 3D 生成）只需在 capabilities() 里多
    // 返回一个字符串，这个接口一个字都不用改，也就不会再波及实现者。
    //
    // 三个直接实现类（OpenAI / Claude / Gemini）已经通过
    // Protocol\Concerns\CapabilityDefaults 给出默认实现，
    // 继承它们的协议类无需任何改动。裸实现本接口的类加一行
    // `use \Ai\Protocol\Concerns\CapabilityDefaults;` 即可。
    // =================================================================

    /**
     * 本协议支持的扩展能力
     *
     * @return array<int, string> 取值见 \Ai\Helpers\Capabilities，不支持任何扩展能力时返回空数组
     */
    public function capabilities(): array;

    /**
     * 构建扩展能力的请求体
     *
     * @param string               $capability 能力标识
     * @param array<string, mixed> $payload    标准化的请求数据
     * @return array<string, mixed> 平台特定的请求数据
     */
    public function buildCapabilityRequest(string $capability, array $payload): array;

    /**
     * 解析扩展能力的响应
     *
     * @param string               $capability 能力标识
     * @param array<string, mixed> $response   平台返回的原始数据。
     *                                         二进制响应（如 TTS 音频）由传输层包装成
     *                                         ['_raw' => 字节, '_content_type' => ..., '_status' => ...]
     * @return CapabilityResponseInterface
     */
    public function parseCapabilityResponse(string $capability, array $response): CapabilityResponseInterface;

    /**
     * 扩展能力对应的接口相对路径
     *
     * @param string $capability 能力标识
     * @return string 如 '/v1/images/generations'；不支持该能力时返回空串
     */
    public function capabilityPath(string $capability): string;
}
