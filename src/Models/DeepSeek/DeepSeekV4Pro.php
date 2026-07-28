<?php
namespace Ai\Models\DeepSeek;

use Ai\Models\BaseModel;

/**
 * DeepSeek-V4 Pro 模型
 *
 * DeepSeek 新一代旗舰模型，兼容 OpenAI Chat Completions 协议，
 * 端点与鉴权与 deepseek-chat 一致（平台键 deepseek__api_key）。
 */
class DeepSeekV4Pro extends BaseModel
{
    protected $name = 'deepseek-v4-pro';
    protected $platform = 'deepseek';
    protected $protocol = 'Ai\\Protocol\\DeepSeek';
    protected $endpoint = 'https://api.deepseek.com/v1/chat/completions';
    protected $features = ['chat', 'stream', 'function_calling'];
    protected $config = [
        'max_tokens' => 1024*256,
        'temperature' => 1.0,
    ];

    /**
     * 处理附件
     * DeepSeek 不支持多模态输入，将附件信息以JSON格式附加到消息文本中
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
                        'content' => file_get_contents($source)
                    ];
                } else {
                    // 其他类型使用base64编码
                    $attachmentInfo[] = [
                        'type' => $attachment->getType(),
                        'filename' => basename($source),
                        'mime_type' => $mimeType,
                        'content' => [
                            'encoding' => 'base64',
                            'data' => base64_encode(file_get_contents($source))
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
