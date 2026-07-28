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
    protected $features = ['chat', 'vision', 'attachments'];
    protected $config = [
        'max_tokens' => 4096,
        'temperature' => 1.0,
    ];
    
    /**
     * 处理附件，使用 Claude 特定格式
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
        
        // 将文本内容转换为 content 数组格式
        $textContent = is_string($lastMessage['content']) ? $lastMessage['content'] : '';
        $contentParts = [];
        
        // 添加文本部分
        if (!empty($textContent)) {
            $contentParts[] = [
                'type' => 'text',
                'text' => $textContent
            ];
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
