<?php
namespace Ai\Models;

use Ai\Helpers\Protocols;

/**
 * 通用自定义模型
 *
 * 用于「任意模型名 + 手选协议格式 + 自定义接口地址」的场景：
 * 只要目标接口遵循本库已实现的某种协议（OpenAI / Claude / Gemini），
 * 就无需为它单独写一个模型类，直接运行时构造即可。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'qwen-max',                       // 任意模型名，原样发给接口
 *     'protocol' => 'openai',                          // 手选协议格式
 *     'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode',
 *     'api_key'  => 'sk-xxx',
 * ]);
 * ```
 */
class CustomModel extends BaseModel
{
    protected $name     = '';
    protected $platform = 'custom';
    protected $protocol = 'Ai\\Protocol\\OpenAI';
    protected $endpoint = '';
    protected $features = ['chat', 'stream'];
    protected $config   = [];

    /**
     * @param array $options 支持的键：
     *   string name      模型名称（原样提交给接口）
     *   string protocol  协议标识或协议类名
     *   string endpoint  完整对话端点
     *   bool   default_endpoint  endpoint 为空时是否回落到协议官方地址（默认 true）
     *   string platform  平台名（默认取协议对应平台）
     *   array  features  能力列表，供 supports() 查询
     *   array  config    模型级默认参数（max_tokens、temperature 等）
     */
    public function __construct(array $options = [])
    {
        $this->name = trim((string)($options['name'] ?? ''));

        $protocol = trim((string)($options['protocol'] ?? 'openai'));
        $this->protocol = Protocols::resolveClass($protocol);

        $this->endpoint = trim((string)($options['endpoint'] ?? ''));
        if ($this->endpoint === '' && ($options['default_endpoint'] ?? true)) {
            $this->endpoint = Protocols::endpointOf($this->protocol);
        }

        $platform = trim((string)($options['platform'] ?? ''));
        $this->platform = $platform !== '' ? $platform : Protocols::platformOf($this->protocol);

        // 自定义接口的真实能力由对方决定，这里给出乐观默认值，可用 features 覆盖
        if (!empty($options['features']) && is_array($options['features'])) {
            $this->features = $options['features'];
        } else {
            $this->features = ['chat', 'stream', 'function_calling', 'vision', 'attachments'];
        }

        if (!empty($options['config']) && is_array($options['config'])) {
            $this->config = $options['config'];
        }
    }

    /**
     * 处理附件
     * Claude 协议用 Anthropic 的 image block，其余走 OpenAI 的 image_url（BaseModel 默认实现）
     */
    public function processAttachments(array $payload, array $attachments): array
    {
        if (!$this->isClaudeProtocol()) {
            return parent::processAttachments($payload, $attachments);
        }

        if (empty($attachments) || empty($payload['messages'])) {
            return $payload;
        }

        $lastIndex   = count($payload['messages']) - 1;
        $lastMessage = &$payload['messages'][$lastIndex];
        if ($lastMessage['role'] !== 'user') {
            return $payload;
        }

        $textContent  = is_string($lastMessage['content']) ? $lastMessage['content'] : '';
        $contentParts = [];
        if ($textContent !== '') {
            $contentParts[] = ['type' => 'text', 'text' => $textContent];
        }

        foreach ($attachments as $attachment) {
            if ($attachment instanceof \Ai\Helpers\AIFile) {
                $contentParts[] = [
                    'type'   => 'image',
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => $attachment->getMimeType(),
                        'data'       => $attachment->getBase64Content(),
                    ],
                ];
            }
        }

        if (!empty($contentParts)) {
            $lastMessage['content'] = $contentParts;
        }

        return $payload;
    }

    /**
     * 当前是否使用 Claude(Anthropic) 协议
     */
    protected function isClaudeProtocol(): bool
    {
        return Protocols::keyOfClass($this->protocol) === 'claude'
            || is_subclass_of($this->protocol, 'Ai\\Protocol\\Claude')
            || ltrim($this->protocol, '\\') === 'Ai\\Protocol\\Claude';
    }
}
