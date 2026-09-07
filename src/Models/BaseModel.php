<?php
namespace Ai\Models;

use Ai\Contracts\ModelInterface;

/**
 * 模型基类
 */
abstract class BaseModel implements ModelInterface
{
    /**
     * 模型名称
     * @var string
     */
    protected $name;
    
    /**
     * 所属平台
     * @var string
     */
    protected $platform;
    
    /**
     * 使用的协议类
     * @var class-string<\Ai\Contracts\ProtocolInterface>
     */
    protected $protocol;

    /**
     * API 端点 URL
     * @var string
     */
    protected $endpoint;
    
    /**
     * 支持的功能列表
     * @var array<mixed>
     */
    protected $features = [];
    
    /**
     * 模型配置
     * @var array<mixed>
     */
    protected $config = [];
    
    /**
     * 获取模型名称
     */
    public function getName(): string
    {
        return $this->name;
    }
    
    /**
     * 获取所属平台
     */
    public function getPlatform(): string
    {
        return $this->platform;
    }
    
    /**
     * 获取使用的协议
     * @return class-string<\Ai\Contracts\ProtocolInterface>
     */
    public function getProtocol(): string
    {
        return $this->protocol;
    }
    
    /**
     * 获取 API 端点
     */
    public function getEndpoint(): string
    {
        return $this->endpoint;
    }
    
    /**
     * 检查是否支持某功能
     */
    public function supports(string $feature): bool
    {
        return in_array($feature, $this->features);
    }
    
    /**
     * 获取支持的功能列表
     * @return array<mixed>
     */
    public function getFeatures(): array
    {
        return $this->features;
    }
    
    /**
     * 获取模型配置
     * @return array<mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }
    
    /**
     * 设置配置
     * @param array<mixed> $config
     */
    public function setConfig(array $config): self
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }
    
    /**
     * 处理附件，默认实现（OpenAI 格式）
     * 子类可以重写此方法以实现平台特定的附件处理
     * @param array<mixed> $attachments
     * @param array<mixed> $payload
     * @return array<mixed>
     */
    public function processAttachments(array $payload, array $attachments): array
    {
        if (empty($attachments) || empty($payload['messages'])) {
            return $payload;
        }
        
        // 获取最后一条消息
        $lastIndex = count($payload['messages']) - 1;
        $lastMessage = &$payload['messages'][$lastIndex];
        
        // 只处理用户消息
        if ($lastMessage['role'] !== 'user') {
            return $payload;
        }
        
        // 原有内容必须**保留**：数组型 content 里可能有 tool_use / tool_result，
        // 整体覆盖会拆散 Anthropic 的 tool_use↔tool_result 配对，下一次请求直接 400。
        // 所以字符串转成 text 块、数组原样保留，附件一律**追加**在后面。
        $contentParts = [];
        if (is_string($lastMessage['content'])) {
            if ($lastMessage['content'] !== '') {
                $contentParts[] = [
                    'type' => 'text',
                    'text' => $lastMessage['content']
                ];
            }
        } elseif (is_array($lastMessage['content'])) {
            $contentParts = $lastMessage['content'];
        }
        
        // 添加附件部分（OpenAI Vision 格式）
        foreach ($attachments as $attachment) {
            if ($attachment instanceof \Ai\Helpers\AIFile) {
                $mimeType = $attachment->getMimeType();
                $base64Data = $attachment->getBase64Content();
                
                // 所有文件类型都使用 image_url 格式
                $contentParts[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => "data:{$mimeType};base64,{$base64Data}"
                    ]
                ];
            }
        }
        
        // 更新消息内容为多模态格式
        if (!empty($contentParts)) {
            $lastMessage['content'] = $contentParts;
        }
        
        return $payload;
    }
}
