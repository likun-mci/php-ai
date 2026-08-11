<?php
namespace Ai\Models\Gemini;

use Ai\Models\BaseModel;

/**
 * Gemini 2.5 Pro 模型
 */
class Gemini25Pro extends BaseModel
{
    protected $name = 'gemini-2.5-pro';
    protected $platform = 'gemini';
    protected $protocol = 'Ai\\Protocol\\Gemini';
    protected $endpoint = 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions';
    protected $features = ['chat'];
    protected $config = [
        'max_tokens' => 8192,
        'temperature' => 1.0,
    ];
    
    /**
     * 处理附件，Gemini OpenAI 兼容模式
     * 使用 messages 字段，但附件格式仍然使用 Gemini 的 inline_data
     */
    public function processAttachments(array $payload, array $attachments): array
    {
        // 没有附件时，保持原始 payload
        if (empty($attachments)) {
            return $payload;
        }
        
        // OpenAI 兼容格式使用 messages
        if (empty($payload['messages'])) {
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
        
        // 添加附件部分（Gemini inline_data 格式）
        foreach ($attachments as $attachment) {
            if ($attachment instanceof \Ai\Helpers\AIFile) {
                $mimeType = $attachment->getMimeType();
                $base64Data = $attachment->getBase64Content();
                
                // Gemini 的附件格式：使用 inline_data
                $contentParts[] = [
                    'type' => 'image_url',  // OpenAI 兼容的 type
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