<?php
namespace Ai\Models\DeepSeek;

use Ai\Models\BaseModel;

/**
 * DeepSeek-R1 推理模型
 */
class DeepSeekReasoner extends BaseModel
{
    protected $name = 'deepseek-reasoner';
    protected $platform = 'deepseek';
    protected $protocol = 'Ai\\Protocol\\DeepSeek';
    protected $endpoint = 'https://api.deepseek.com/v1/chat/completions';
    /**
     * @var array<mixed>
     */
    protected $features = ['chat', 'stream'];
    /**
     * @var array<mixed>
     */
    protected $config = [
        'max_tokens' => 1024*256,
        'temperature' => 1.0,
    ];
    
    /**
     * 处理附件
     * DeepSeek Reasoner 不支持多模态输入，将附件信息以JSON格式附加到消息文本中
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
        
        // 提取附件元信息
        $attachmentInfo = [];
        foreach ($attachments as $attachment) {
            if ($attachment instanceof \Ai\Helpers\AIFile) {
                $mimeType = $attachment->getMimeType();
                $source = $attachment->getSource();
                
                // 判断是否为文本类型
                if (strpos($mimeType, 'text/') === 0) {
                    // 文本类型直接读取内容
                    $attachmentInfo[] = [
                        'type' => $attachment->getType(),
                        'filename' => basename($source),
                        'mime_type' => $mimeType,
                        'content' => (string) @file_get_contents($source)
                    ];
                } else {
                    // 其他类型使用base64编码
                    $attachmentInfo[] = [
                        'type' => $attachment->getType(),
                        'filename' => basename($source),
                        'mime_type' => $mimeType,
                        'content' => [
                            'encoding' => 'base64',
                            // 读不到时 base64_encode(false) 在 PHP 8 上是 TypeError
                            'data' => base64_encode((string) @file_get_contents($source))
                        ]
                    ];
                }
            }
        }
        
        // 将附件信息以JSON格式附加到消息尾部
        if (!empty($attachmentInfo)) {
            $originalContent = is_string($lastMessage['content']) ? $lastMessage['content'] : '';
            $attachmentJson = json_encode($attachmentInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $lastMessage['content'] = $originalContent . "\n\n[附件信息]\n" . $attachmentJson;
        }
        
        return $payload;
    }
}
