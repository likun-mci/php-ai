<?php
namespace Ai\Models\DeepSeek;

use Ai\Models\BaseModel;

/**
 * DeepSeek（Anthropic 兼容端点）
 *
 * 使用 DeepSeek 提供的 Anthropic 兼容 API：https://api.deepseek.com/anthropic
 * 复用 Claude(Anthropic) 协议（x-api-key 鉴权 + content_block_delta 流式），
 * 鉴权所需的 api_key 仍取平台 deepseek 的配置键 `deepseek__api_key`。
 */
class DeepSeekAnthropic extends BaseModel
{
    protected $name = 'deepseek-chat';
    protected $platform = 'deepseek';
    protected $protocol = 'Ai\\Protocol\\Claude';
    protected $endpoint = 'https://api.deepseek.com/anthropic/v1/messages';
    /**
     * @var array<mixed>
     */
    protected $features = ['chat', 'stream'];
    /**
     * @var array<mixed>
     */
    protected $config = [
        'max_tokens'  => 1024*256,
        'temperature' => 1.0,
    ];

    /**
     * 处理附件
     * DeepSeek 不支持多模态输入，将附件信息以文本形式附加到消息尾部
     * （避免 Claude 协议的 image/document 块导致 DeepSeek 端报错）
     * @param array<mixed> $attachments
     * @param array<mixed> $payload
     * @return array<mixed>
     */
    public function processAttachments(array $payload, array $attachments): array
    {
        if (empty($attachments) || empty($payload['messages'])) {
            return $payload;
        }

        $lastIndex = count($payload['messages']) - 1;
        $lastMessage = &$payload['messages'][$lastIndex];

        if ($lastMessage['role'] !== 'user') {
            return $payload;
        }

        $attachmentInfo = [];
        foreach ($attachments as $attachment) {
            if ($attachment instanceof \Ai\Helpers\AIFile) {
                $mimeType = $attachment->getMimeType();
                $source = $attachment->getSource();

                if (strpos($mimeType, 'text/') === 0) {
                    $attachmentInfo[] = [
                        'type' => $attachment->getType(),
                        'filename' => basename($source),
                        'mime_type' => $mimeType,
                        'content' => (string) @file_get_contents($source)
                    ];
                } else {
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

        if (!empty($attachmentInfo)) {
            $originalContent = is_string($lastMessage['content']) ? $lastMessage['content'] : '';
            $attachmentJson = json_encode($attachmentInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $lastMessage['content'] = $originalContent . "\n\n[附件信息]\n" . $attachmentJson;
        }

        return $payload;
    }
}
