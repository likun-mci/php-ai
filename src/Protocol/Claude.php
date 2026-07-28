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
     * 构建请求数据
     */
    public function buildRequest(array $payload): array
    {
        $request = [
            'model' => $payload['model'] ?? 'claude-3-opus-20240229',
            'messages' => $this->convertMessages($payload['messages'] ?? []),
            'max_tokens' => $payload['max_tokens'] ?? 4096,
        ];
        
        // 可选参数
        if (isset($payload['temperature'])) {
            $request['temperature'] = $payload['temperature'];
        }
        if (isset($payload['system'])) {
            $request['system'] = $payload['system'];
        }
        // 流式开关必须写入请求体，否则服务端按非流式返回，传输层按 SSE 解析会得到空内容
        if (isset($payload['stream'])) {
            $request['stream'] = (bool) $payload['stream'];
        }

        // 工具调用（Anthropic tools 规范）：tools=[{name,description,input_schema}]
        if (isset($payload['tools']) && is_array($payload['tools']) && $payload['tools']) {
            $request['tools'] = $payload['tools'];
        }
        if (isset($payload['tool_choice'])) {
            $request['tool_choice'] = $payload['tool_choice'];
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
     */
    public function parseResponse(array $response): AIResponseInterface
    {
        $content = '';
        $usage = [];
        
        if (isset($response['content'][0]['text'])) {
            $content = $response['content'][0]['text'];
        }
        
        if (isset($response['usage'])) {
            $usage = [
                'prompt_tokens' => $response['usage']['input_tokens'] ?? 0,
                'completion_tokens' => $response['usage']['output_tokens'] ?? 0,
                'total_tokens' => ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0),
            ];
        }
        
        return new AIResponse([
            'content' => $content,
            'model' => $response['model'] ?? '',
            'usage' => $usage,
            'raw' => $response,
            'success' => isset($response['content']),
        ]);
    }
    
    /**
     * 构建请求头
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
     * 判断流式数据是否结束
     */
    public function isStreamEnd(array $chunk): bool
    {
        // Claude 发送 message_stop 事件表示结束
        return isset($chunk['type']) && $chunk['type'] === 'message_stop';
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
     * Anthropic 已提供 GET /v1/models，第三方兼容网关不一定实现，失败时回退到内置列表
     */
    public function listModels(array $config, $transport): ?array
    {
        try {
            $response = $transport->get($this->modelsEndpoint($config), [], $this->buildHeaders($config));

            if (isset($response['data']) && is_array($response['data'])) {
                $models = [];
                foreach ($response['data'] as $model) {
                    if (isset($model['id'])) {
                        $models[$model['id']] = $model['display_name'] ?? $model['id'];
                    }
                }
                if ($models) {
                    return $models;
                }
            }
        } catch (\Exception $e) {
            error_log('Failed to list Claude models: ' . $e->getMessage());
        }

        // 回退：常用模型标识（接口不可用时仍可让业务层渲染下拉框）
        return [
            'claude-sonnet-4-5'        => 'Claude Sonnet 4.5',
            'claude-opus-4-1'          => 'Claude Opus 4.1',
            'claude-3-7-sonnet-latest' => 'Claude 3.7 Sonnet',
            'claude-3-5-haiku-latest'  => 'Claude 3.5 Haiku',
            'claude-3-opus-20240229'   => 'Claude 3 Opus',
        ];
    }
}

