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
     * 构建请求数据
     */
    public function buildRequest(array $payload): array
    {
        $request = [
            'model' => $payload['model'] ?? 'gpt-4',
            'messages' => $payload['messages'] ?? [],
        ];
        
        // 可选参数
        if (isset($payload['temperature'])) {
            $request['temperature'] = $payload['temperature'];
        }
        if (isset($payload['max_tokens'])) {
            $request['max_tokens'] = $payload['max_tokens'];
        }
        if (isset($payload['stream'])) {
            $request['stream'] = $payload['stream'];
            // 开启流式 usage 返回，便于最终计算 tokens
            if ($payload['stream'] === true) {
                $request['stream_options'] = ['include_usage' => true];
            }
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
            $usage = [
                'prompt_tokens' => $response['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $response['usage']['total_tokens'] ?? 0,
            ];
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
     * 列举可用模型列表
     */
    public function listModels(array $config, $transport): ?array
    {
        try {
            $endpoint = $this->modelsEndpoint($config);
            $headers  = $this->buildHeaders($config);
            
            $response = $transport->get($endpoint, [], $headers);
            
            if (!isset($response['data']) || !is_array($response['data'])) {
                return null;
            }
            
            $models = [];
            foreach ($response['data'] as $model) {
                if (isset($model['id'])) {
                    // 使用 id 作为键和值，也可以用 owned_by 或其他字段作为描述
                    $models[$model['id']] = $model['id'];
                }
            }
            
            return $models;
            
        } catch (\Exception $e) {
            error_log('Failed to list OpenAI models: ' . $e->getMessage());
            return null;
        }
    }
}
