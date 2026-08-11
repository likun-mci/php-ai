<?php
namespace Ai\Protocol;

use Ai\Contracts\ProtocolInterface;
use Ai\Contracts\AIResponseInterface;
use Ai\Response\AIResponse;

/**
 * Claude (Anthropic) 协议实现
 */
class Claude implements ProtocolInterface
{
    use ModelCatalog;

    /**
     * @var array<string, mixed>
     */
    protected $config = [];
    
    /**
     * 设置配置
     * @param array<string, mixed> $config
     */
    public function setConfig(array $config): self
    {
        $this->config = $config;
        return $this;
    }

    /**
     * 协议官方默认接口根地址（自定义模型未配置 base_url 时使用）
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.anthropic.com';
    }

    /**
     * 协议对话路径
     */
    public function chatPath(): string
    {
        return '/v1/messages';
    }

    /**
     * 协议模型列表路径
     */
    public function modelsPath(): string
    {
        return '/v1/models';
    }

    /**
     * 透传白名单：buildRequest 放行的 Anthropic Messages 参数
     * @var string[]
     */
    protected static $passthroughKeys = [
        'max_tokens', 'temperature', 'top_p', 'top_k', 'stop_sequences',
        'system', 'metadata', 'tools', 'tool_choice', 'thinking',
    ];

    /**
     * 构建请求数据
     * @param array<string, mixed> $payload 已合并的请求负载
     * @return array<string, mixed> 发给平台的请求体
     */
    public function buildRequest(array $payload): array
    {
        $request = [
            'model' => $payload['model'] ?? 'claude-3-opus-20240229',
            // 先把 OpenAI 写法（role:'tool' / assistant.tool_calls）归一成 Anthropic 块结构，
            // 再走原有的角色过滤
            'messages' => $this->convertMessages(
                \Ai\Helpers\Tools::toClaudeMessages($payload['messages'] ?? [])
            ),
            'max_tokens' => $payload['max_tokens'] ?? 4096,
        ];

        // 白名单透传所有已知生成参数
        foreach (self::$passthroughKeys as $key) {
            if ($key === 'max_tokens') continue; // 已在 base 中设置
            if (array_key_exists($key, $payload)) {
                $request[$key] = $payload[$key];
            }
        }

        // 工具定义：允许调用方直接写 OpenAI 原生格式
        if (!empty($payload['tools']) && is_array($payload['tools'])) {
            $request['tools'] = \Ai\Helpers\Tools::toClaudeDefs($payload['tools']);
        }

        // 流式开关必须写入请求体，否则服务端按非流式返回
        if (!empty($payload['stream'])) {
            $request['stream'] = true;
        }

        return $request;
    }
    
    /**
     * 转换消息格式
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    protected function convertMessages(array $messages): array
    {
        $converted = [];
        foreach ($messages as $msg) {
            // Claude 不支持 system 角色在 messages 中
            if ($msg['role'] === 'system') {
                continue;
            }
            $converted[] = [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];
        }
        return $converted;
    }
    
    /**
     * 解析响应数据
     * @param array<string, mixed> $response 平台返回的原始数据
     */
    public function parseResponse(array $response): AIResponseInterface
    {
        $content = '';
        $usage = [];

        // content 是块数组，可能混有 thinking / tool_use 块（开启思考的模型必然如此）。
        // 只取 text 块并按序拼接——直接读 content[0]['text'] 会在首块是 thinking 时拿到空串。
        if (!empty($response['content']) && is_array($response['content'])) {
            foreach ($response['content'] as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $type = $block['type'] ?? '';
                if (($type === 'text' || $type === '') && isset($block['text'])) {
                    $content .= (string)$block['text'];
                }
            }
        }

        if (isset($response['usage'])) {
            // 原样保留平台返回的完整 usage 对象（含 cache_creation_input_tokens 等）
            $usage = $response['usage'];
            // 确保三个标准字段向下兼容
            $usage['prompt_tokens'] = $usage['prompt_tokens'] ?? ($usage['input_tokens'] ?? 0);
            $usage['completion_tokens'] = $usage['completion_tokens'] ?? ($usage['output_tokens'] ?? 0);
            $usage['total_tokens'] = $usage['total_tokens'] ?? (int)$usage['prompt_tokens'] + (int)$usage['completion_tokens'];
        }

        return new AIResponse([
            'content'     => $content,
            'model'       => $response['model'] ?? '',
            'usage'       => $usage,
            'raw'         => $response,
            'success'     => isset($response['content']),
            'tool_calls'  => \Ai\Helpers\Tools::fromClaudeContent($response['content'] ?? []),
            'stop_reason' => \Ai\Helpers\Tools::normalizeStopReason($response['stop_reason'] ?? ''),
        ]);
    }
    
    /**
     * 构建请求头
     * @param array<string, mixed> $config
     * @return array<string, string> 请求头名 => 值
     */
    public function buildHeaders(array $config): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'anthropic-version' => '2023-06-01',
        ];
        
        if (isset($config['api_key'])) {
            $headers['x-api-key'] = $config['api_key'];
        }

        // 自定义/第三方接口可通过 config['headers'] 追加或覆盖任意请求头
        return \Ai\Helpers\Headers::apply($headers, $config);
    }
    
    /**
     * 解析流式数据块
     * Claude 格式: {"type":"content_block_delta","delta":{"type":"text_delta","text":"text"}}
     * @param array<string, mixed> $chunk 单个 SSE 分片
     */
    public function parseStreamChunk(array $chunk): ?string
    {
        // Claude 流式响应有多种事件类型
        if (isset($chunk['type'])) {
            // content_block_delta 事件包含文本内容
            if ($chunk['type'] === 'content_block_delta' && isset($chunk['delta']['text'])) {
                return $chunk['delta']['text'];
            }
            // message_delta 也可能包含内容
            if ($chunk['type'] === 'message_delta' && isset($chunk['delta']['content'])) {
                return $chunk['delta']['content'];
            }
        }
        return null;
    }
    
    /**
     * 从流式数据块中解析 usage
     *
     * Anthropic 的用量分两帧下发，且位置不同：
     *   message_start —— usage 嵌在 message 下，含 input_tokens
     *   message_delta —— usage 在顶层，只有 output_tokens
     * 只认顶层 usage 会漏掉 input_tokens，AI 层会把两帧的结果合并。
     * @return array<mixed> 该帧不含 usage 时返回 null
     * @param array<string, mixed> $chunk
     */
    public function parseStreamUsage(array $chunk): ?array
    {
        if (($chunk['type'] ?? '') === 'message_start'
            && !empty($chunk['message']['usage']) && is_array($chunk['message']['usage'])) {
            return $chunk['message']['usage'];
        }
        return (!empty($chunk['usage']) && is_array($chunk['usage'])) ? $chunk['usage'] : null;
    }

    /**
     * 从流式数据块中解析平台错误
     * Anthropic 的流式错误帧形如 {"type":"error","error":{"type":...,"message":...}}
     * @return string|null 该帧不含错误时返回 null
     * @param array<string, mixed> $chunk
     */
    public function parseStreamError(array $chunk): ?string
    {
        if (!isset($chunk['error'])) {
            return null;
        }
        $err = $chunk['error'];
        if (is_array($err)) {
            return (string)($err['message'] ?? json_encode($err, JSON_UNESCAPED_UNICODE));
        }
        return (string)$err;
    }

    /**
     * 判断流式数据是否结束
     * @param array<string, mixed> $chunk
     */
    public function isStreamEnd(array $chunk): bool
    {
        // Claude 发送 message_stop 事件表示结束
        return isset($chunk['type']) && $chunk['type'] === 'message_stop';
    }
    
    /**
     * 解析模型列表端点
     * 优先级：endpoint_models > base_url > 由实际对话端点推导 > 官方地址
     * @param array<string, mixed> $config
     */
    public function modelsEndpoint(array $config): string
    {
        return \Ai\Helpers\Endpoint::resolveModels(
            $config,
            \Ai\Helpers\Endpoint::join($this->defaultBaseUrl(), $this->modelsPath()),
            $this->modelsPath()
        );
    }

    /**
     * 常用模型（供后台离线渲染下拉框；接口不可用时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'claude-opus-5'     => 'Claude Opus 5',
            'claude-sonnet-5'   => 'Claude Sonnet 5',
            'claude-fable-5'    => 'Claude Fable 5',
            'claude-opus-4-8'   => 'Claude Opus 4.8',
            'claude-opus-4-7'   => 'Claude Opus 4.7',
            'claude-sonnet-4-6' => 'Claude Sonnet 4.6',
            'claude-haiku-4-5'  => 'Claude Haiku 4.5',
        ];
    }

    /**
     * 列举可用模型列表
     * Anthropic 已提供 GET /v1/models，第三方兼容网关不一定实现，
     * 失败时若请求的正是本协议官方域名，则回退到 knownModels()
     * @param array<string, mixed> $config
     * @param \Ai\Contracts\TransportInterface $transport
     * @return array<string, mixed>|null 模型 id => 名称或完整数据
     */
    public function listModels(array $config, $transport): ?array
    {
        try {
            $response = $transport->get($this->modelsEndpoint($config), [], $this->buildHeaders($config));

            if (isset($response['data']) && is_array($response['data'])) {
                $raw = !empty($config['__models_raw']);
                $models = [];
                foreach ($response['data'] as $model) {
                    if (isset($model['id'])) {
                        $models[$model['id']] = $raw ? $model : ($model['display_name'] ?? $model['id']);
                    }
                }
                if ($models) {
                    return $models;
                }
            }
        } catch (\Throwable $e) {
            // 列模型是「尽力而为」的旁路操作，任何异常都该降级到兜底清单
            \Ai\Helpers\Log::warning('拉取模型列表失败', ['protocol' => static::class, 'error' => $e->getMessage()]);
        }

        return $this->fallbackModels($config);
    }
}

