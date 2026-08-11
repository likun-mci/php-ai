<?php
namespace Ai\Protocol;

use Ai\Contracts\ProtocolInterface;
use Ai\Contracts\AIResponseInterface;
use Ai\Response\AIResponse;

/**
 * Gemini (Google) 协议实现
 */
class Gemini implements ProtocolInterface
{
    use ModelCatalog;

    protected $config = [];
    
    /**
     * 设置配置
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
        return 'https://generativelanguage.googleapis.com';
    }

    /**
     * 协议对话路径（走 Gemini 的 OpenAI 兼容端点）
     */
    public function chatPath(): string
    {
        return '/v1beta/openai/chat/completions';
    }

    /**
     * 协议模型列表路径（原生路径，不在 openai 兼容目录下）
     */
    public function modelsPath(): string
    {
        return '/v1beta/models';
    }

    /**
     * 透传白名单：buildRequest 放行的参数（Gemini OpenAI 兼容端点的标准参数）
     */
    protected static $passthroughKeys = [
        'temperature', 'max_tokens', 'top_p', 'top_k',
        'stop', 'presence_penalty', 'frequency_penalty', 'seed', 'response_format',
        'system', 'tools', 'tool_choice',
    ];

    /**
     * 构建请求数据
     */
    public function buildRequest(array $payload): array
    {
        $request = [
            'model' => $payload['model'] ?? 'gemini-pro',
            // 走的是 OpenAI 兼容端点，工具相关结构与 OpenAI 一致
            'messages' => \Ai\Helpers\Tools::toOpenAiMessages($payload['messages'] ?? []),
        ];

        // 白名单透传所有已知生成参数
        foreach (self::$passthroughKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $request[$key] = $payload[$key];
            }
        }

        if (!empty($payload['tools']) && is_array($payload['tools'])) {
            $request['tools'] = \Ai\Helpers\Tools::toOpenAiDefs($payload['tools']);
        }
        if (isset($payload['tool_choice'])) {
            $request['tool_choice'] = \Ai\Helpers\Tools::toOpenAiToolChoice($payload['tool_choice']);
        }
        if (!empty($payload['system']) && is_string($payload['system'])) {
            array_unshift($request['messages'], ['role' => 'system', 'content' => $payload['system']]);
            unset($request['system']);
        }

        // 流式：走的是 OpenAI 兼容端点，必须显式要 usage，否则整个流都不回传 token 用量
        if (!empty($payload['stream'])) {
            $request['stream'] = true;
            $request['stream_options'] = ['include_usage' => true];
        }

        return $request;
    }
    
    /**
     * 转换消息格式
     */
    protected function convertMessages(array $messages): array
    {
        $converted = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] === 'assistant' ? 'model' : 'user';
            $converted[] = [
                'role' => $role,
                'parts' => [
                    ['text' => $msg['content']]
                ],
            ];
        }
        return $converted;
    }
    
    /**
     * 解析响应数据
     */
    public function parseResponse(array $response): AIResponseInterface
    {
        $content = '';
        $usage = [];

        // 对话走的是 OpenAI 兼容端点，返回体为 OpenAI 结构；同时兼容原生 Gemini 结构
        if (isset($response['choices'][0]['message']['content'])) {
            $content = $response['choices'][0]['message']['content'];
        } elseif (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            $content = $response['candidates'][0]['content']['parts'][0]['text'];
        }

        if (isset($response['usage'])) {
            // 原样保留（OpenAI 兼容结构）
            $usage = $response['usage'];
            $usage['prompt_tokens'] = $usage['prompt_tokens'] ?? ($usage['input_tokens'] ?? 0);
            $usage['completion_tokens'] = $usage['completion_tokens'] ?? ($usage['output_tokens'] ?? 0);
            $usage['total_tokens'] = $usage['total_tokens'] ?? (int)$usage['prompt_tokens'] + (int)$usage['completion_tokens'];
        } elseif (isset($response['usageMetadata'])) {
            $usage = $response['usageMetadata']; // 原样保留
            $usage['prompt_tokens'] = $usage['prompt_tokens'] ?? ($usage['promptTokenCount'] ?? 0);
            $usage['completion_tokens'] = $usage['completion_tokens'] ?? ($usage['candidatesTokenCount'] ?? 0);
            $usage['total_tokens'] = $usage['total_tokens'] ?? ($usage['totalTokenCount'] ?? 0);
        }

        return new AIResponse([
            'content'     => $content,
            'model'       => $response['model'] ?? ($response['modelVersion'] ?? ''),
            'usage'       => $usage,
            'raw'         => $response,
            'success'     => isset($response['choices']) || isset($response['candidates']),
            'tool_calls'  => \Ai\Helpers\Tools::fromOpenAiToolCalls($response['choices'][0]['message'] ?? []),
            'stop_reason' => \Ai\Helpers\Tools::normalizeStopReason($response['choices'][0]['finish_reason'] ?? ''),
        ]);
    }
    
    /**
     * 构建请求头
     */
    public function buildHeaders(array $config): array
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];
        
        // Gemini OpenAI 兼容端点使用 Authorization Bearer 头
        if (isset($config['api_key'])) {
            $headers['Authorization'] = 'Bearer ' . $config['api_key'];
        }

        // 自定义/第三方接口可通过 config['headers'] 追加或覆盖任意请求头
        return \Ai\Helpers\Headers::apply($headers, $config);
    }
    
    /**
     * 解析流式数据块
     * Gemini OpenAI 兼容端点使用 OpenAI 格式: {"choices":[{"delta":{"content":"text"}}]}
     */
    public function parseStreamChunk(array $chunk): ?string
    {
        // OpenAI 兼容格式
        if (isset($chunk['choices'][0]['delta']['content'])) {
            return $chunk['choices'][0]['delta']['content'];
        }
        return null;
    }
    
    /**
     * 从流式数据块中解析 usage（走的是 OpenAI 兼容端点，收尾帧顶层带 usage）
     * @return array|null 该帧不含 usage 时返回 null
     */
    public function parseStreamUsage(array $chunk): ?array
    {
        if (!empty($chunk['usage']) && is_array($chunk['usage'])) {
            return $chunk['usage'];
        }
        // 原生 Gemini 结构兜底
        if (!empty($chunk['usageMetadata']) && is_array($chunk['usageMetadata'])) {
            return $chunk['usageMetadata'];
        }
        return null;
    }

    /**
     * 从流式数据块中解析平台错误
     * @return string|null 该帧不含错误时返回 null
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
     */
    public function isStreamEnd(array $chunk): bool
    {
        // OpenAI 兼容格式检查 finish_reason
        return isset($chunk['choices'][0]['finish_reason']) && $chunk['choices'][0]['finish_reason'] !== null;
    }
    
    /**
     * 解析模型列表端点
     * 优先级：endpoint_models > base_url > 由实际对话端点推导 > 官方地址
     * 注：Gemini 的模型列表在原生路径下（/v1beta/models），推导前先摘掉对话端点里的 openai 兼容目录
     */
    public function modelsEndpoint(array $config): string
    {
        if (!empty($config['endpoint'])) {
            $config['endpoint'] = preg_replace(
                '#/openai/(chat/completions)$#i', '/$1', (string)$config['endpoint']
            );
        }
        return \Ai\Helpers\Endpoint::resolveModels(
            $config,
            \Ai\Helpers\Endpoint::join($this->defaultBaseUrl(), $this->modelsPath()),
            $this->modelsPath()
        );
    }

    /**
     * 常用模型（供后台离线渲染下拉框；拉取失败时作为兜底）
     */
    public function knownModels(): array
    {
        return [
            'gemini-2.5-pro'        => 'Gemini 2.5 Pro',
            'gemini-2.5-flash'      => 'Gemini 2.5 Flash',
            'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash Lite',
            'gemini-2.0-flash'      => 'Gemini 2.0 Flash',
        ];
    }

    /**
     * 列举可用模型列表
     * 拉取失败或为空时，若请求的正是本协议官方域名，则回退到 knownModels()
     */
    public function listModels(array $config, $transport): ?array
    {
        try {
            $endpoint = $this->modelsEndpoint($config);

            // Gemini 使用查询参数传递 API key
            $params = [];
            if (isset($config['api_key'])) {
                $params['key'] = $config['api_key'];
            }

            $headers = $this->buildHeaders($config);
            $response = $transport->get($endpoint, $params, $headers);

            if (!isset($response['models']) || !is_array($response['models'])) {
                return $this->fallbackModels($config);
            }

            $raw = !empty($config['__models_raw']);
            $models = [];
            foreach ($response['models'] as $model) {
                if (isset($model['name'])) {
                    // name 格式为 "models/gemini-pro"，提取模型名称
                    $modelId = str_replace('models/', '', $model['name']);
                    $models[$modelId] = $raw ? $model : ($model['displayName'] ?? $modelId);
                }
            }

            return $models ?: $this->fallbackModels($config);

        } catch (\Exception $e) {
            \Ai\Helpers\Log::warning('拉取模型列表失败', ['protocol' => static::class, 'error' => $e->getMessage()]);
            return $this->fallbackModels($config);
        }
    }
}
