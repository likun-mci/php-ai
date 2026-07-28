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
     * 构建请求数据
     */
    public function buildRequest(array $payload): array
    {
        $request = [
            'model' => $payload['model'] ?? 'gemini-pro',
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
            $usage = [
                'prompt_tokens'     => $response['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
                'total_tokens'      => $response['usage']['total_tokens'] ?? 0,
            ];
        } elseif (isset($response['usageMetadata'])) {
            $usage = [
                'prompt_tokens' => $response['usageMetadata']['promptTokenCount'] ?? 0,
                'completion_tokens' => $response['usageMetadata']['candidatesTokenCount'] ?? 0,
                'total_tokens' => $response['usageMetadata']['totalTokenCount'] ?? 0,
            ];
        }
        
        return new AIResponse([
            'content' => $content,
            'model' => $response['model'] ?? ($response['modelVersion'] ?? ''),
            'usage' => $usage,
            'raw' => $response,
            'success' => isset($response['choices']) || isset($response['candidates']),
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
     * 列举可用模型列表
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
                return null;
            }
            
            $models = [];
            foreach ($response['models'] as $model) {
                if (isset($model['name'])) {
                    // name 格式为 "models/gemini-pro"，提取模型名称
                    $modelId = str_replace('models/', '', $model['name']);
                    $displayName = $model['displayName'] ?? $modelId;
                    $models[$modelId] = $displayName;
                }
            }
            
            return $models;
            
        } catch (\Exception $e) {
            error_log('Failed to list Gemini models: ' . $e->getMessage());
            return null;
        }
    }
}
