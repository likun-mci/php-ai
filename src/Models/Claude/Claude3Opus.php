<?php
namespace Ai\Models\Claude;

use Ai\Models\BaseModel;

/**
 * Claude 3 Opus 模型
 */
class Claude3Opus extends BaseModel
{
    protected $name = 'claude-3-opus-20240229';
    protected $platform = 'claude';
    protected $protocol = 'Ai\\Protocol\\Claude';
    protected $endpoint = 'https://api.anthropic.com/v1/messages';
    /**
     * @var array<mixed>
     */
    protected $features = ['chat', 'vision', 'attachments'];
    /**
     * @var array<mixed>
     */
    protected $config = [
        'max_tokens' => 4096,
        'temperature' => 1.0,
    ];
    
    /**
     * 处理附件，使用 Claude 特定格式
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
        // 整体覆盖会拆散 tool_use↔tool_result 配对，下一次请求直接 400。
        // 字符串转成 text 块、数组原样保留，附件一律**追加**在后面。
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
        
        // 添加附件部分（Claude 格式）
        foreach ($attachments as $attachment) {
            if ($attachment instanceof \Ai\Helpers\AIFile) {
                $mimeType = $attachment->getMimeType();
                $base64Data = $attachment->getBase64Content();
                
                // 处理图片
                if (strpos($mimeType, 'image/') === 0) {
                    $contentParts[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $mimeType,
                            'data' => $base64Data
                        ]
                    ];
                }
                // 处理 PDF 和其他文档
                else {
                    $contentParts[] = [
                        'type' => 'document',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $mimeType,
                            'data' => $base64Data
                        ]
                    ];
                }
            }
        }
        
        // 更新消息内容为多模态格式
        if (!empty($contentParts)) {
            $lastMessage['content'] = $contentParts;
        }
        
        return $payload;
    }
}
