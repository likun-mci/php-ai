<?php
namespace Ai\Protocol;

use Ai\Contracts\ProtocolInterface;
use Ai\Contracts\AIResponseInterface;
use Ai\Response\AIResponse;

/**
 * OpenAI 协议实现
 */
class OpenAI implements ProtocolInterface
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
        return 'https://api.openai.com';
    }

    /**
     * 协议对话路径
     */
    public function chatPath(): string
    {
        return '/v1/chat/completions';
    }

    /**
     * 协议模型列表路径
     */
    public function modelsPath(): string
    {
        return '/v1/models';
    }

    /**
     * 透传白名单：buildRequest 放行的 OpenAI Chat Completions 参数
     */
    protected static $passthroughKeys = [
        'temperature', 'max_tokens', 'max_completion_tokens', 'top_p', 'top_k',
        'stop', 'presence_penalty', 'frequency_penalty', 'seed', 'response_format',
        'system', 'tools', 'tool_choice', 'reasoning_effort', 'thinking',
        'metadata', 'user', 'parallel_tool_calls',
    ];

    /**
     * 构建请求数据
     */
    public function buildRequest(array $payload): array
    {
        $request = [
            'model' => $payload['model'] ?? 'gpt-4',
            'messages' => $payload['messages'] ?? [],
        ];

        // 白名单透传所有已知生成参数（文档 & setConfig() 列出的参数都能实际送到接口）
        foreach (self::$passthroughKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $request[$key] = $payload[$key];
            }
        }

        // 流式
        if (!empty($payload['stream'])) {
            $request['stream'] = true;
            $request['stream_options'] = ['include_usage' => true];
        }

        return $request;
    }
    
    /**
     * 解析响应数据
     */
    public function parseResponse(array $response): AIResponseInterface
    {
        $content = '';
        $usage = [];

        if (isset($response['choices'][0]['message']['content'])) {
            $content = $response['choices'][0]['message']['content'];
        }

        if (isset($response['usage'])) {
            // 原样保留平台返回的完整 usage 对象（含 cached_tokens、prompt_tokens_details 等）
            $usage = $response['usage'];
            // 确保三个标准字段向下兼容
            $usage['prompt_tokens'] = $usage['prompt_tokens'] ?? ($usage['input_tokens'] ?? 0);
            $usage['completion_tokens'] = $usage['completion_tokens'] ?? ($usage['output_tokens'] ?? 0);
            $usage['total_tokens'] = $usage['total_tokens'] ?? (int)$usage['prompt_tokens'] + (int)$usage['completion_tokens'];
        }

        return new AIResponse([
            'content' => $content,
            'model' => $response['model'] ?? '',
            'usage' => $usage,
            'raw' => $response,
            'success' => isset($response['choices']),
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
        
        if (isset($config['api_key'])) {
            $headers['Authorization'] = 'Bearer ' . $config['api_key'];
        }
        
        if (isset($config['organization'])) {
            $headers['OpenAI-Organization'] = $config['organization'];
        }

        if (isset($config['project_id'])) {
            $headers['OpenAI-Project'] = $config['project_id'];
        }

        // 自定义/第三方接口可通过 config['headers'] 追加或覆盖任意请求头
        return \Ai\Helpers\Headers::apply($headers, $config);
    }
    
    /**
     * 解析流式数据块
     * OpenAI 格式: {"choices":[{"delta":{"content":"text"}}]}
     */
    public function parseStreamChunk(array $chunk): ?string
    {
        if (isset($chunk['choices'][0]['delta']['content'])) {
            return $chunk['choices'][0]['delta']['content'];
        }
        return null;
    }
    
    /**
     * 判断流式数据是否结束
     */
    public function isStreamEnd(array $chunk): bool
    {
        // OpenAI 在结束时 finish_reason 不为 null
        return isset($chunk['choices'][0]['finish_reason']) 
            && $chunk['choices'][0]['finish_reason'] !== null;
    }
    
    /**
     * 解析模型列表端点
     * 优先级：endpoint_models > base_url > 由实际对话端点推导 > 官方地址
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
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     */
    public function knownModels(): array
    {
        return [
            'gpt-5.1'      => 'GPT-5.1',
            'gpt-5'        => 'GPT-5',
            'gpt-5-mini'   => 'GPT-5 mini',
            'gpt-4.1'      => 'GPT-4.1',
            'gpt-4.1-mini' => 'GPT-4.1 mini',
            'gpt-4o'       => 'GPT-4o',
            'gpt-4o-mini'  => 'GPT-4o mini',
            'o3'           => 'o3（推理）',
            'o4-mini'      => 'o4-mini（推理）',
        ];
    }

    /**
     * 列举可用模型列表
     *
     * config 中设置 '__models_raw' => true 可返回完整的模型数据对象而非仅 id。
     * 拉取失败或返回为空时，若请求的正是本协议官方域名，则回退到 knownModels()。
     */
    public function listModels(array $config, $transport): ?array
    {
        try {
            $endpoint = $this->modelsEndpoint($config);
            $headers  = $this->buildHeaders($config);

            $response = $transport->get($endpoint, [], $headers);

            if (!isset($response['data']) || !is_array($response['data'])) {
                return $this->fallbackModels($config);
            }

            $raw = !empty($config['__models_raw']);
            $models = [];
            foreach ($response['data'] as $model) {
                if (isset($model['id'])) {
                    $models[$model['id']] = $raw ? $model : $model['id'];
                }
            }

            return $models ?: $this->fallbackModels($config);

        } catch (\Exception $e) {
            error_log('Failed to list models (' . static::class . '): ' . $e->getMessage());
            return $this->fallbackModels($config);
        }
    }
}
