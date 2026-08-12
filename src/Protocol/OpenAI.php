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
    use \Ai\Protocol\Concerns\CapabilityDefaults;
    use \Ai\Protocol\Concerns\OpenAiEmbeddings;

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
     * @var string[]
     */
    protected static $passthroughKeys = [
        'temperature', 'max_tokens', 'max_completion_tokens', 'top_p', 'top_k',
        'stop', 'presence_penalty', 'frequency_penalty', 'seed', 'response_format',
        'system', 'tools', 'tool_choice', 'reasoning_effort', 'thinking',
        'metadata', 'user', 'parallel_tool_calls',
    ];

    /**
     * 构建请求数据
     * @param array<string, mixed> $payload 已合并的请求负载
     * @return array<string, mixed> 发给平台的请求体
     */
    public function buildRequest(array $payload): array
    {
        $request = [
            'model' => $payload['model'] ?? 'gpt-4',
            // 统一格式（Anthropic 风格的 tool_use / tool_result 块）转成 OpenAI 的
            // tool_calls / role:'tool' 结构；本来就是 OpenAI 写法的原样放行
            'messages' => \Ai\Helpers\Tools::toOpenAiMessages($payload['messages'] ?? []),
        ];

        // 白名单透传所有已知生成参数（文档 & setConfig() 列出的参数都能实际送到接口）
        foreach (self::$passthroughKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $request[$key] = $payload[$key];
            }
        }

        // 工具定义与 tool_choice 同样按目标平台格式改写
        if (!empty($payload['tools']) && is_array($payload['tools'])) {
            $request['tools'] = \Ai\Helpers\Tools::toOpenAiDefs($payload['tools']);
        }
        if (isset($payload['tool_choice'])) {
            $request['tool_choice'] = \Ai\Helpers\Tools::toOpenAiToolChoice($payload['tool_choice']);
        }

        // OpenAI 系没有顶层 system 字段，统一格式里的 system 要落到 messages 首位
        if (!empty($payload['system']) && is_string($payload['system'])) {
            array_unshift($request['messages'], ['role' => 'system', 'content' => $payload['system']]);
            unset($request['system']);
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
     * @param array<string, mixed> $response 平台返回的原始数据
     */
    public function parseResponse(array $response): AIResponseInterface
    {
        $content = '';
        $usage = [];

        $message = $response['choices'][0]['message'] ?? [];
        if (isset($message['content']) && is_string($message['content'])) {
            $content = $message['content'];
        }
        // 工具调用归一成统一格式，业务层无需关心平台差异
        $toolCalls  = \Ai\Helpers\Tools::fromOpenAiToolCalls($message);
        $stopReason = \Ai\Helpers\Tools::normalizeStopReason(
            $response['choices'][0]['finish_reason'] ?? ''
        );

        if (isset($response['usage'])) {
            // 原样保留平台返回的完整 usage 对象（含 cached_tokens、prompt_tokens_details 等）
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
            'success'     => isset($response['choices']),
            'tool_calls'  => $toolCalls,
            'stop_reason' => $stopReason,
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
     * @param array<string, mixed> $chunk 单个 SSE 分片
     */
    public function parseStreamChunk(array $chunk): ?string
    {
        if (isset($chunk['choices'][0]['delta']['content'])) {
            return $chunk['choices'][0]['delta']['content'];
        }
        return null;
    }
    
    /**
     * 从流式数据块中解析 usage
     *
     * OpenAI 系开启 stream_options.include_usage 后，在收尾帧的顶层返回完整 usage。
     * @return array<mixed> 该帧不含 usage 时返回 null
     * @param array<string, mixed> $chunk
     */
    public function parseStreamUsage(array $chunk): ?array
    {
        return (!empty($chunk['usage']) && is_array($chunk['usage'])) ? $chunk['usage'] : null;
    }

    /**
     * 从流式数据块中解析平台错误
     *
     * 有些平台出错时 HTTP 状态码仍是 200，错误信息混在流里，
     * 不识别就会得到一个「成功但内容为空」的响应。
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
     * 从流式数据块中解析工具调用分片
     *
     * OpenAI 系的 tool_calls 是**按分片下发**的：第一帧给出 index / id / name，
     * 之后若干帧只带同一个 index 下的 arguments 片段，需要按 index 把
     * arguments 字符串拼起来才能得到完整的 JSON。形如：
     *
     *   {"delta":{"tool_calls":[{"index":0,"id":"call_1","type":"function",
     *                            "function":{"name":"get_weather","arguments":""}}]}}
     *   {"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{\"city\""}}]}}
     *   {"delta":{"tool_calls":[{"index":0,"function":{"arguments":":\"北京\"}"}}]}}
     *
     * 这里只负责把单帧拆成「按 index 归并」的增量，拼接与 JSON 解析由 AI 层完成。
     *
     * @param array<string, mixed> $chunk
     * @return array<int, array{id?: string, name?: string, arguments?: string}>|null
     *         该帧不含工具调用分片时返回 null
     */
    public function parseStreamToolCalls(array $chunk): ?array
    {
        $deltas = $chunk['choices'][0]['delta']['tool_calls'] ?? null;
        if (!is_array($deltas) || !$deltas) {
            return null;
        }

        $out = [];
        foreach ($deltas as $i => $d) {
            if (!is_array($d)) {
                continue;
            }
            // index 缺省时退回数组下标：个别兼容实现不下发 index
            $index = isset($d['index']) ? (int) $d['index'] : (int) $i;
            $part  = [];
            if (isset($d['id']) && $d['id'] !== '') {
                $part['id'] = (string) $d['id'];
            }
            if (isset($d['function']['name']) && $d['function']['name'] !== '') {
                $part['name'] = (string) $d['function']['name'];
            }
            if (isset($d['function']['arguments'])) {
                $part['arguments'] = (string) $d['function']['arguments'];
            }
            if ($part) {
                $out[$index] = $part;
            }
        }
        return $out ?: null;
    }

    /**
     * 从流式数据块中解析结束原因（已归一）
     *
     * 流式响应不走 parseResponse()，stop_reason 若不在这里取，
     * getStopReason() 在流式下就永远是空串——调用方无从判断这一轮是正常结束、
     * 被 max_tokens 截断，还是模型在要求调用工具。
     *
     * @param array<string, mixed> $chunk
     * @return string|null 该帧不含结束原因时返回 null
     */
    public function parseStreamStopReason(array $chunk): ?string
    {
        $reason = $chunk['choices'][0]['finish_reason'] ?? null;
        if ($reason === null || $reason === '') {
            return null;
        }
        return \Ai\Helpers\Tools::normalizeStopReason($reason);
    }

    /**
     * 判断流式数据是否结束
     * @param array<string, mixed> $chunk
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
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
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
     * @param array<string, mixed> $config
     * @param \Ai\Contracts\TransportInterface $transport
     * @return array<string, mixed>|null 模型 id => 名称或完整数据
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

        } catch (\Throwable $e) {
            // 列模型是「尽力而为」的旁路操作，任何异常都该降级到兜底清单而不是中断调用方
            \Ai\Helpers\Log::warning('拉取模型列表失败', ['protocol' => static::class, 'error' => $e->getMessage()]);
            return $this->fallbackModels($config);
        }
    }
}
