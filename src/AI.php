<?php
namespace Ai;

use Ai\Contracts\ModelInterface;
use Ai\Contracts\ProtocolInterface;
use Ai\Contracts\TransportInterface;
use Ai\Contracts\AIResponseInterface;
use Ai\Transport\CurlTransport;
use Ai\Exceptions\ConfigException;
use Ai\Exceptions\AIException;

/**
 * AI 主入口类
 */
class AI
{
    /**
     * 配置
     * @var array<mixed>
     */
    protected $config = [];

    /**
     * @var int
     */
    protected $rounds = 0; # 默认保留最近 10 轮对话上下文
    
    /**
     * 模型实例
     * @var ModelInterface
     */
    protected $model;
    
    /**
     * 协议实例
     * @var ProtocolInterface
     */
    protected $protocol;
    
    /**
     * 传输层实例
     * @var TransportInterface
     */
    protected $transport;
    
    /**
     * 回调函数
     * @var array<mixed>
     */
    protected $callbacks = [
        'before' => [],
        'after' => [],
    ];
    
    /**
     * 附件列表
     * @var array<mixed>
     */
    protected $attachments = [];
    
    /**
     * 流式输出
     * @var bool
     */
    protected $stream = false;
    
    /**
     * 流式输出累积的完整内容
     * @var string
     */
    protected $streamAccumulatedContent = '';

    /**
     * 流式输出累积的 usage（部分平台分多帧下发，需要逐帧合并）
     * @var array<mixed>
     */
    protected $streamAccumulatedUsage = [];

    /**
     * 流式过程中平台回传的错误信息
     * @var string
     */
    protected $streamErrorMessage = '';

    /**
     * 流式过程中解析到的结束原因（已归一）
     * @var string
     */
    protected $streamStopReason = '';

    /**
     * 流式分片回调，注册后由调用方接管分片下发（见 setStreamCallback）
     * @var callable|null
     */
    protected $streamCallback = null;

    /**
     * 当前会话标识；不同标识各自独立一份历史，便于常驻进程里复用同一个 AI 实例
     * @var string
     */
    protected $session_id = 'default';

    /**
     * 各会话的历史消息：[会话ID => [消息, ...]]
     * @var array<mixed>
     */
    protected $histories = [];

    /**
     * 可由运行时配置透传到请求体的生成参数白名单
     * （config 里的 api_key、base_url 等连接信息不会进入请求体）
     * @var array<mixed>
     */
    protected static $payloadKeys = [
        'max_tokens', 'max_completion_tokens', 'temperature', 'top_p', 'top_k', 'stop',
        'presence_penalty', 'frequency_penalty', 'seed', 'response_format',
        'system', 'tools', 'tool_choice', 'reasoning_effort', 'thinking',
    ];

    /**
     * 数值型生成参数的取值范围 [最小值, 最大值]
     * 超出范围时自动截断到最近的边界值。
     * @var array<string, array{0: float|int, 1: float|int}>
     */
    protected static $numericRanges = [
        'temperature'       => [0.0, 2.0],
        'top_p'             => [0.0, 1.0],
        'top_k'             => [0, PHP_INT_MAX],
        'presence_penalty'  => [-2.0, 2.0],
        'frequency_penalty' => [-2.0, 2.0],
        'seed'              => [0, PHP_INT_MAX],
        'max_tokens'        => [1, PHP_INT_MAX],
    ];

    /**
     * 净化数值型生成参数：确保类型正确、取值在合法范围内
     *
     * - 字符串数字（如 "0.7"、"1"）转为 float / int
     * - 超出范围的值自动截断到最近的边界值
     * - 非数值类型的值原样保留（pass-through）
     *
     * @param array<string, mixed> $params 配置项或 payload 数组
     * @return array<string, mixed>
     */
    protected static function sanitizePayloadParams(array $params): array
    {
        foreach (self::$numericRanges as $key => [$min, $max]) {
            if (!array_key_exists($key, $params)) {
                continue;
            }
            $value = $params[$key];

            // 跳过 null 和不属于数值/数字字符串的值
            if ($value === null) {
                continue;
            }
            if (!is_numeric($value)) {
                continue;
            }

            // 判断目标类型：整数参（max_tokens / top_k / seed）转 int，其余转 float
            $intKeys = ['max_tokens', 'top_k', 'seed'];
            $num = in_array($key, $intKeys, true)
                ? (int) $value
                : (float) $value;

            // 截断到合法范围
            $params[$key] = max($min, min($max, $num));
        }
        return $params;
    }

    /**
     * 模型名称到类名的映射表
     * @var array<string, class-string<ModelInterface>>
     */
    private $modelMap = [
        'gpt-4.1'            => 'Ai\Models\OpenAI\GPT41',
        'gpt-4o'             => 'Ai\Models\OpenAI\GPT4o',
        'claude-3-opus'      => 'Ai\Models\Claude\Claude3Opus',
        'gemini-2.5-pro'     => 'Ai\Models\Gemini\Gemini25Pro',
        'deepseek-chat'      => 'Ai\Models\DeepSeek\DeepSeekChat',
        'deepseek-reasoner'  => 'Ai\Models\DeepSeek\DeepSeekReasoner',
        'deepseek-anthropic' => 'Ai\Models\DeepSeek\DeepSeekAnthropic',
        'deepseek-v4-pro'    => 'Ai\Models\DeepSeek\DeepSeekV4Pro',
        'deepseek-v4-flash'  => 'Ai\Models\DeepSeek\DeepSeekV4Flash',
    ];
    
    /**
     * 构造函数
     * @param array<mixed> $config
     */
    public function __construct(array $config = [])
    {
        ksort($this->modelMap);
        $this->transport = new CurlTransport();
        $this->setConfig($config);
    }

    /**
     * 切换会话
     *
     * 每个会话各自维护一份历史。常驻进程（Swoole / 队列 worker）里可以复用
     * 同一个 AI 实例服务多个用户，靠这个方法隔离上下文。
     * 仅在 rounds > 0 时有意义。
     */
    public function setSessionId(string $sessionId): self
    {
        $this->session_id = $sessionId !== '' ? $sessionId : 'default';
        return $this;
    }

    /**
     * 当前会话标识
     */
    public function getSessionId(): string
    {
        return $this->session_id;
    }

    /**
     * 读取当前会话的历史消息
     * @return array<int, array<string, mixed>> 标准 messages 结构，可直接塞回 chat()
     */
    public function getHistory(): array
    {
        return $this->histories[$this->session_id] ?? [];
    }

    /**
     * 覆盖当前会话的历史（从数据库/Redis 恢复上下文时用）
     * @param array<int, array<string, mixed>> $messages
     */
    public function setHistory(array $messages): self
    {
        $this->histories[$this->session_id] = array_values($messages);
        return $this;
    }

    /**
     * 清空历史。不传参数清当前会话，传 true 清全部会话
     */
    public function clearHistory(bool $allSessions = false): self
    {
        if ($allSessions) {
            $this->histories = [];
        } else {
            unset($this->histories[$this->session_id]);
        }
        return $this;
    }

    /**
     * 导出全部会话的历史，便于业务层持久化
     * @return array<string, array<int, array<string, mixed>>> [会话ID => [消息, ...]]
     */
    public function exportHistory(): array
    {
        return $this->histories;
    }

    /**
     * 导入全部会话的历史（与 exportHistory() 对应）
     * @param array<string, array<int, array<string, mixed>>> $histories
     */
    public function importHistory(array $histories): self
    {
        $this->histories = $histories;
        return $this;
    }

    /**
     * 按 rounds 裁剪历史：只保留最近 N 轮
     *
     * 一「轮」从一条真正的用户提问开始（只含 tool_result 的 user 消息不算新一轮，
     * 它属于上一轮工具调用的一部分）。按轮切分而不是按条数切，
     * 是为了避免把 assistant 的 tool_use 和对应的 tool_result 切散——
     * 那会让下一次请求直接被平台拒绝。
     * @param array<int, array<string, mixed>> $messages * @return array<mixed>
     * @return array<int, array<string, mixed>>
     */
    protected function trimHistory(array $messages, int $rounds): array
    {
        if ($rounds <= 0 || !$messages) {
            return $messages;
        }

        // 从后往前找第 $rounds 个「真正的用户提问」的位置
        $starts = [];
        foreach ($messages as $i => $msg) {
            if (($msg['role'] ?? '') !== 'user') {
                continue;
            }
            $content = $msg['content'] ?? '';
            $isToolResultOnly = false;
            if (is_array($content) && $content) {
                $isToolResultOnly = true;
                foreach ($content as $block) {
                    if (!is_array($block) || ($block['type'] ?? '') !== 'tool_result') {
                        $isToolResultOnly = false;
                        break;
                    }
                }
            }
            if (!$isToolResultOnly) {
                $starts[] = $i;
            }
        }

        if (count($starts) <= $rounds) {
            return $messages;
        }
        $from = $starts[count($starts) - $rounds];
        return array_values(array_slice($messages, $from));
    }

    /**
     * 设置配置项
     * 
     * 支持的配置项包括：
     * - model: 模型名称或模型实例（优先级最高；可为内置标识，也可为任意自定义模型名）
     * - protocol: 手选协议格式，如 openai / claude(anthropic) / gemini / deepseek / qwen / doubao /
     *             zhipu / moonshot / ernie / hunyuan / grok / mistral ……，或自定义协议类名。
     *             完整清单见 listProtocols() / listProtocolGroups()
     * - base_url: 接口根地址，与协议官方路径智能拼接（接入第三方转发/中转、自建网关）
     * - endpoint: 完整对话端点，原样使用，优先级高于 base_url
     * - endpoint_models: 完整模型列表端点（仅 listModels 生效）
     * - platform: 平台名，仅用于业务层标识（默认取协议对应平台）
     * - api_key: API 密钥（部分平台需要）
     * - headers: 追加/覆盖请求头，值为 null 表示删除协议默认头
     * - extra_body: 追加到请求体的私有参数
     * - organization: 组织 ID（OpenAI 可选）
     * - project_id: 项目 ID（DeepSeek 可选）
     * - rounds: 保留多少轮上下文。**默认 0 表示不启用**，库不碰历史（与旧版本一致）；
     *           设为 N 后 chat() 会自动拼接本会话最近 N 轮对话，并把本轮结果记进历史。
     *           历史按 setSessionId() 分桶，可用 exportHistory() / importHistory() 持久化
     * - 其他生成参数，如 temperature、max_tokens、top_p 等（见 self::$payloadKeys）
     *
     * @param array<string, mixed> $config 配置项数组
     * @return self
     */
    public function setConfig(array $config): self
    {
        $config = self::sanitizePayloadParams($config);
        $this->config = array_merge($this->config, $config);
        if( isset($this->config['rounds']) ){
            $this->rounds = intval( $this->config['rounds'] );
            unset($this->config['rounds']);
        }
        
        // 初始化模型
        if ( isset($this->config['model']) ) {
            $this->setModel($this->config['model']);
        } elseif ( $this->model && $this->protocol && method_exists($this->protocol, 'setConfig') ) {
            // 模型已就绪时，后续追加的配置（如 base_url、headers）同步给协议层
            $this->protocol->setConfig(array_merge($this->config, [
                'endpoint' => $this->currentEndpoint(),
            ]));
        }
        return $this;
    }

    /**
     * 创建 AI 实例（工厂方法）
     * @param array<mixed> $config
     */
    public static function create(array $config = []): self
    {
        return new self($config);
    }
    
    /**
     * 设置模型
     *
     * 支持三种来源（按优先级）：
     * 1) ModelInterface 实例：完全自定义
     * 2) 内置模型标识（见 modelMap）：沿用内置平台/协议/端点
     * 3) 任意模型名：按 config['protocol'] 手选协议格式，或按模型名自动推断协议，
     *    再结合 config['endpoint'] / config['base_url'] 组装端点
     * @param \Ai\Contracts\ModelInterface|string $model 模型实例或模型标识
     */
    public function setModel($model): self
    {
        if ($model instanceof ModelInterface) {
            $this->model = $model;
        } elseif (is_string($model) && trim($model) !== '') {
            $name         = trim($model);
            $protocolConf = trim((string)($this->config['protocol'] ?? ''));

            // 手选了协议格式时以自定义模型为准（即便模型名是内置标识），否则优先用内置模型定义
            if ($protocolConf === '' && isset($this->modelMap[$name])) {
                $modelClass  = $this->modelMap[$name];
                $this->model = new $modelClass();
            } else {
                $this->model = $this->buildCustomModel($name, $protocolConf);
            }
        } else {
            throw new ConfigException('Invalid model type');
        }

        // 初始化协议
        $protocolClass = $this->model->getProtocol();
        $this->protocol = new $protocolClass();

        // 如果协议支持setConfig，传递配置（包含解析后的endpoint）
        if (method_exists($this->protocol, 'setConfig')) {
            $configWithEndpoint = array_merge($this->config, [
                'endpoint' => $this->currentEndpoint()
            ]);
            $this->protocol->setConfig($configWithEndpoint);
        }

        return $this;
    }

    /**
     * 构造自定义模型（任意模型名 + 手选/推断协议 + 自定义接口地址）
     *
     * @param string $name         模型名称，原样提交给接口
     * @param string $protocolConf config['protocol']，为空时按模型名推断
     */
    protected function buildCustomModel(string $name, string $protocolConf): ModelInterface
    {
        $detected = \Ai\Helpers\Protocols::detect($name);   // 由模型名推断的协议家族
        $protocol = $protocolConf !== '' ? $protocolConf : ($detected ?: 'openai');

        // 能确定官方地址（模型名可识别，或用户手选了协议）时才给默认端点，
        // 否则留空，等 base_url/endpoint 配置进来；请求前仍未给出则报错，
        // 避免把第三方 Key 发到不相干的官方域名。
        //
        // 优先级：手选协议 > 模型名推断。当用户手选了协议（如 openrouter）
        // 但模型名推断出另一个协议时（如 openai/gpt-4o → openai），
        // 以手选协议的官方地址为准，避免转发到错误的官方域名。
        $officialBase = $protocolConf !== ''
            ? \Ai\Helpers\Protocols::baseUrlOf($protocolConf)
            : ($detected !== null ? \Ai\Helpers\Protocols::baseUrlOf($detected) : '');

        $endpoint = $officialBase !== ''
            ? \Ai\Helpers\Protocols::endpointOf($protocol, $officialBase)
            : '';

        return new \Ai\Models\CustomModel([
            'name'             => $name,
            'protocol'         => $protocol,
            'endpoint'         => $endpoint,
            'default_endpoint' => false,     // 不用协议官方地址兜底，交由 AI 层统一校验
            // 平台名以模型名推断为准；接第三方接口（模型名无法归属官方平台）时为 custom，
            // 业务层据此去找 {平台}__api_key 之类的配置
            'platform'         => trim((string)($this->config['platform'] ?? ''))
                ?: ($protocolConf !== ''
                    ? \Ai\Helpers\Protocols::platformOf($protocolConf)
                    : ($detected !== null ? \Ai\Helpers\Protocols::platformOf($detected) : 'custom')),
            'features'         => (array)($this->config['features'] ?? []),
            'config'           => $this->collectModelParams(),
        ]);
    }

    /**
     * 从运行时配置里挑出可进入请求体的生成参数
     * @return array<string, mixed>
     */
    protected function collectModelParams(): array
    {
        $params = [];
        foreach (self::$payloadKeys as $key) {
            if (isset($this->config[$key])) {
                $params[$key] = $this->config[$key];
            }
        }
        return $params;
    }

    /**
     * 解析实际请求端点
     *
     * 在模型默认端点基础上，按运行时配置 endpoint / base_url 覆盖，
     * 以支持接入第三方 API 转发/中转服务。解析规则见 Ai\Helpers\Endpoint。
     *
     * @throws ConfigException 自定义模型既无法推断官方地址、也未配置 base_url / endpoint
     */
    public function resolveEndpoint(): string
    {
        $endpoint = $this->currentEndpoint();
        if ($endpoint === '') {
            $name = $this->model ? $this->model->getName() : '';
            throw new ConfigException(
                "Unknown model: {$name}. Please set 'base_url' or 'endpoint' (and optionally 'protocol') for custom API."
            );
        }
        return $endpoint;
    }

    /**
     * 解析实际请求端点（不抛异常版，无法确定时返回空串）
     */
    protected function currentEndpoint(): string
    {
        if (!$this->model) {
            return '';
        }

        $default = $this->model->getEndpoint();
        if ($default !== '') {
            return \Ai\Helpers\Endpoint::resolve($default, $this->config);
        }

        // 自定义模型没有默认端点时，用 endpoint / base_url + 协议对话路径组装
        $endpoint = trim((string)($this->config['endpoint'] ?? ''));
        if ($endpoint !== '') {
            return \Ai\Helpers\Endpoint::withScheme($endpoint);
        }
        $baseUrl = trim((string)($this->config['base_url'] ?? ''));
        if ($baseUrl === '') {
            return '';
        }
        return \Ai\Helpers\Endpoint::join(
            $baseUrl,
            \Ai\Helpers\Protocols::chatPathOf($this->model->getProtocol())
        );
    }

    /**
     * 解析模型类名
     */
    protected function resolveModelClass(string $modelName): string
    {
        if (!isset($this->modelMap[$modelName])) {
            throw new ConfigException("Model not found: {$modelName}");
        }

        return $this->modelMap[$modelName];
    }
    
    /**
     * 设置附件
     * @param array<int, \Ai\Helpers\AIFile> $attachments
     */
    public function setAttachments(array $attachments): self
    {
        $this->attachments = $attachments;
        return $this;
    }
    
    /**
     * 对话
     * @param string|array<string, mixed> $payload 对话内容，可以是字符串（用户消息）或数组（完整请求负载）
     * 格式示例：
     * 字符串模式： "你好，AI！"
     * 数组模式：   [
     *                 'messages' => [
     *                    ['role' => 'user', 'content' => '你好，AI！'],
     *                    ['role' => 'assistant', 'content' => 'xxxxx'],
     *                ],
     *            ]
     * @return AIResponseInterface
     */
    public function chat($payload = ''): AIResponseInterface
    {
        if (!$this->model) {
            throw new ConfigException('Model not set');
        }

        // 如果 payload 是字符串，自动转换为 messages 格式
        if( is_string($payload) ) {
            $payload = [
               'messages' => [
                    ['role' => 'user', 'content' => $payload]
                ],
            ];
        }
        $payload['stream'] = $payload['stream'] ?? $this->stream;

        // rounds > 0 时启用多轮上下文：把本会话的历史拼在本次消息前面。
        // rounds = 0（默认）完全不介入，行为与旧版本一致。
        $newMessages = $payload['messages'] ?? [];
        if ($this->rounds > 0 && $newMessages) {
            $history = $this->trimHistory($this->getHistory(), $this->rounds);
            if ($history) {
                $payload['messages'] = array_merge($history, $newMessages);
            }
        }

        // 重置流式累积状态
        $this->streamAccumulatedContent = '';
        $this->streamAccumulatedUsage = [];
        $this->streamErrorMessage = '';
        $this->streamStopReason = '';
        
        // 合并配置：模型默认参数 < 运行时配置里的生成参数 < 本次 payload
        $payload = array_merge([
            'model' => $this->model->getName(),
            'messages' => [],
        ], $this->model->getConfig(), $this->collectModelParams(), $payload);

        // 对合并后的 payload 做数值参数净化（类型转换 + 范围截断）
        $payload = self::sanitizePayloadParams($payload);
        
        // 处理附件：交由模型层处理模型特定的附件格式
        if (!empty($this->attachments)) {
            $payload = $this->model->processAttachments($payload, $this->attachments);
        }
        
        // 如果设置了流式回调，自动启用 stream
        if ( $payload['stream'] ) {
            $payload['stream'] = true;
            
            // 设置传输层的流式回调，包装用户回调以累积内容
            $this->transport->setStreamCallback(function($data) {
                // 使用协议层解析流式内容（不同平台格式不同）
                $content = $this->protocol->parseStreamChunk($data);
                
                // 累积流式内容
                if ($content !== null) {
                    $this->streamAccumulatedContent .= $content;
                }

                // 累积 usage：Anthropic 等平台把 input/output tokens 拆在不同帧下发，
                // 只保留最后一帧会丢字段，这里逐帧合并
                if (method_exists($this->protocol, 'parseStreamUsage')) {
                    $chunkUsage = $this->protocol->parseStreamUsage($data);
                    if (!empty($chunkUsage)) {
                        $this->streamAccumulatedUsage = array_merge($this->streamAccumulatedUsage, $chunkUsage);
                    }
                }

                // 记录结束原因：流式不走 parseResponse()，不在这里取就永远拿不到
                if (method_exists($this->protocol, 'parseStreamStopReason')) {
                    $chunkStop = $this->protocol->parseStreamStopReason($data);
                    if ($chunkStop !== null && $chunkStop !== '') {
                        $this->streamStopReason = $chunkStop;
                    }
                }

                // 捕获平台在流中回传的错误（这类响应 HTTP 状态码仍是 200）
                if ($this->streamErrorMessage === '' && method_exists($this->protocol, 'parseStreamError')) {
                    $chunkError = $this->protocol->parseStreamError($data);
                    if ($chunkError !== null && $chunkError !== '') {
                        $this->streamErrorMessage = $chunkError;
                    }
                }
                // 输出流式数据块
                $this->emitStream([ 'type' => 'stream_chunk', 'content' => $content, 'raw' => $data ]);
            });
        } else {
            // 清除流式回调
            $this->transport->setStreamCallback(null);
        }
        
        try {
            // 执行 before 回调
            $this->runCallbacks('before', $payload);
            
            // 构建请求
            $requestData = $this->protocol->buildRequest($payload);
            // 自定义接口的私有参数（如 enable_thinking、safe_mode 等）直接并入请求体
            if (!empty($this->config['extra_body']) && is_array($this->config['extra_body'])) {
                $requestData = array_merge($requestData, $this->config['extra_body']);
            }
            $headers = $this->protocol->buildHeaders($this->config);
            $endpoint = $this->resolveEndpoint();
            
            // 发送请求
            $responseData = $this->transport->post($endpoint, $requestData, $headers);

            // 流式模式：用累积的内容和 usage 直接构建响应，不走 parseResponse
            if ( $payload['stream'] ) {
                // 平台在流里报了错（HTTP 200 但没有内容）时，按错误处理而不是返回空响应
                $streamError = $this->streamErrorMessage;
                if ($streamError === '' && method_exists($this->transport, 'getStreamError')) {
                    $streamError = $this->transport->getStreamError();
                }
                if ($streamError !== '' && $this->streamAccumulatedContent === '') {
                    throw new \Ai\Exceptions\RequestException(
                        $streamError,
                        $this->model->getPlatform(),
                        '',
                        $this->streamAccumulatedUsage
                    );
                }

                // usage：优先用协议层逐帧合并的结果，回退到传输层的通用捕获
                $streamUsage = $this->streamAccumulatedUsage;
                if (empty($streamUsage) && method_exists($this->transport, 'getStreamUsage')) {
                    $streamUsage = $this->transport->getStreamUsage();
                }
                if (!empty($streamUsage)) {
                    // 原样保留完整 usage，同时补齐三个标准字段
                    // （Anthropic 系用 input_tokens / output_tokens 命名，需要映射）
                    $streamUsage['prompt_tokens'] = $streamUsage['prompt_tokens']
                        ?? ($streamUsage['input_tokens'] ?? 0);
                    $streamUsage['completion_tokens'] = $streamUsage['completion_tokens']
                        ?? ($streamUsage['output_tokens'] ?? 0);
                    $streamUsage['total_tokens'] = $streamUsage['total_tokens']
                        ?? ((int)$streamUsage['prompt_tokens'] + (int)$streamUsage['completion_tokens']);
                } else {
                    $streamUsage = [];
                }
                // 流式暂不支持工具调用：各平台的 tool_calls 都是按分片下发的
                // （OpenAI 系按 delta.tool_calls[].index 累积 arguments 字符串，
                // Anthropic 系按 content_block_start + input_json_delta 累积），
                // 本库尚未做重组。若不在这里拦住，调用方会拿到一个
                // 「isSuccess() 为 true、内容为空、getToolCalls() 也为空」的响应，
                // 完全看不出模型其实是在要求调用工具——这种静默失败最难排查。
                if ($this->streamStopReason === 'tool_use') {
                    throw new \Ai\Exceptions\RequestException(
                        '流式模式暂不支持工具调用：模型本轮要求调用工具，但流式响应里的 '
                        . 'tool_calls 分片尚未做重组，结果会丢失。请对带 tools 的请求改用非流式'
                        . '（setStream(false)），Agent 内部已默认如此。',
                        $this->model->getPlatform(),
                        'stream_tool_calls_unsupported',
                        $streamUsage
                    );
                }

                $response = new \Ai\Response\AIResponse([
                    'content'     => $this->streamAccumulatedContent,
                    'model'       => $this->model->getName(),
                    'usage'       => $streamUsage,
                    'raw'         => $responseData,
                    'success'     => true,
                    'stop_reason' => $this->streamStopReason,
                ]);
                $this->emitStream([ 'type' => 'stream_end', 'data' => [
                    'content' => $this->streamAccumulatedContent,
                    'model'   => $this->model->getName(),
                    'usage'   => $streamUsage,
                ]]);
            } else {
                // 解析响应
                $response = $this->protocol->parseResponse($responseData);
            }
            
            // 执行 after 回调
            $this->runCallbacks('after', $response);

            // 记录本轮对话到会话历史（仅 rounds > 0 时）
            if ($this->rounds > 0 && $newMessages) {
                $history = $this->getHistory();
                foreach ($newMessages as $msg) {
                    $history[] = $msg;
                }
                $history[] = $response->toAssistantMessage();
                $this->histories[$this->session_id] = $this->trimHistory($history, $this->rounds);
            }

            // 清空附件（避免影响下次对话）
            $this->attachments = [];
            
            return $response;
            
        } catch (\Throwable $e) {
            // 捕获 \Throwable 而非 \Exception：协议层/回调里的 TypeError 等 Error 类异常
            // 此前会直接穿透出去，调用方拿到的错误类型不统一。现在一律包装成 AIException，
            // 原始异常通过 getPrevious() 保留，定位库自身 bug 时堆栈不丢。
            // 清空附件
            $this->attachments = [];
            
            // 提取详细错误信息
            $errorCode = '';
            $rawResponse = [];
            
            if ($e instanceof \Ai\Exceptions\RequestException) {
                $errorCode = $e->getErrorCode();
                $rawResponse = $e->getRawResponse();
            }
            
            throw new AIException(
                $e->getMessage(),
                $this->model->getPlatform(),
                $errorCode,
                $rawResponse,
                $e                      // 保留原始异常，getPrevious() 可取完整堆栈
            );
        }
    }
    
    /**
     * 批量并发对话
     *
     * 批量场景（翻译、摘要、分类、打标签……）串行跑，总耗时是「单条 × 条数」，
     * 且每条都要重做一次 TLS 握手。这里并发发送，总耗时约等于「最慢的一条 × 批次数」。
     *
     * 用法与 chat() 一致，每个元素可以是字符串或完整 payload 数组：
     *
     * ```php
     * $results = $ai->chatBatch([
     *     'title' => '把这句翻译成英文：你好',
     *     'desc'  => ['messages' => [['role'=>'user','content'=>'翻译：世界']]],
     * ], 5);
     *
     * foreach ($results as $key => $r) {
     *     if ($r->isSuccess()) {
     *         echo $key, ': ', $r->getContent(), "\n";
     *     } else {
     *         echo $key, ' 失败: ', $r->getError(), "\n";
     *     }
     * }
     * ```
     *
     * 与 chat() 的差异：
     *   - **单条失败不抛异常**，而是返回一个 isSuccess() 为 false 的响应，
     *     用 getError() 取错误信息——批量场景不该因为一条失败就丢掉其它结果
     *   - 不支持流式（并发流式的分片会互相穿插，没有意义）
     *   - 附件（setAttachments）只作用于 chat()，批量请在各自 payload 里自带
     *
     * @param array<string, string|array<string, mixed>> $payloads 键名任意，返回结果按同样的键对应回来
     * @param int   $concurrency 同时在途的请求数，默认 5；调大易触发平台限流
     * @return array<string, \Ai\Contracts\AIResponseInterface> 与入参同键的响应数组
     */
    public function chatBatch(array $payloads, int $concurrency = 5): array
    {
        if (!$this->model) {
            throw new ConfigException('Model not set');
        }
        if (!method_exists($this->transport, 'postConcurrent')) {
            // 传输层不支持并发时降级为串行，保证功能可用
            $out = [];
            foreach ($payloads as $key => $payload) {
                try {
                    $out[$key] = $this->chat($payload);
                } catch (\Throwable $e) {
                    $out[$key] = new \Ai\Response\AIResponse([
                        'success' => false,
                        'error'   => $e->getMessage(),
                        'model'   => $this->model->getName(),
                    ]);
                }
            }
            return $out;
        }

        $endpoint = $this->resolveEndpoint();
        $headers  = $this->protocol->buildHeaders($this->config);

        $requests = [];
        foreach ($payloads as $key => $payload) {
            if (is_string($payload)) {
                $payload = ['messages' => [['role' => 'user', 'content' => $payload]]];
            }
            $payload = array_merge([
                'model'    => $this->model->getName(),
                'messages' => [],
            ], $this->model->getConfig(), $this->collectModelParams(), (array) $payload);
            unset($payload['stream']);              // 批量不支持流式
            $payload = self::sanitizePayloadParams($payload);

            $body = $this->protocol->buildRequest($payload);
            if (!empty($this->config['extra_body']) && is_array($this->config['extra_body'])) {
                $body = array_merge($body, $this->config['extra_body']);
            }
            $requests[$key] = ['url' => $endpoint, 'data' => $body, 'headers' => $headers];
        }

        $raw = $this->transport->postConcurrent($requests, $concurrency);

        $out = [];
        foreach ($raw as $key => $item) {
            if (!empty($item['ok'])) {
                $out[$key] = $this->protocol->parseResponse($item['response']);
            } else {
                $out[$key] = new \Ai\Response\AIResponse([
                    'success' => false,
                    'error'   => $item['error'] ?? '请求失败',
                    'model'   => $this->model->getName(),
                    'raw'     => $item['response'] ?? [],
                ]);
            }
        }
        return $out;
    }

    /**
     * 实时输出文本（兼容 CLI 和 HTTP 环境）
     *
     * - CLI 模式：直接 print 到 stdout，自动刷新缓冲区
     * - HTTP 模式：首次调用时设置 text/plain 响应头并禁用 Nginx/Apache 缓冲，
     *             后续每次调用立即 flush，适合流式 SSE 输出
     *
     * @param string $text 要输出的文本内容（HTTP 流式场景建议自行加 "data: ...\n\n" 前缀）
     */
    public function flushText(string $text): self
    {
        static $initialized = false;

        $isCli = preg_match('/cli/i', php_sapi_name());

        if (!$initialized) {
            $initialized = true;

            // 关闭输出缓冲，确保实时输出
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            ob_implicit_flush(true);

            if (!$isCli) {
                // HTTP 模式：首次输出前设置响应头
                if (!headers_sent()) {
                    header('Content-Type: text/plain; charset=utf-8');
                    header('Cache-Control: no-cache');
                    header('X-Accel-Buffering: no');   // 禁用 Nginx 反向代理缓冲
                    header('X-Content-Type-Options: nosniff');
                }
            }
        }

        if ($isCli) {
            // CLI 模式：直接输出并换行（非 SSE 场景通常需要可读性）
            echo $text;
        } else {
            // HTTP 模式：原样输出，调用方自行控制格式（如 SSE "data: ...\n\n"）
            echo $text;
        }

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
        return $this;
    }

    /**
     * 注册回调函数
     */
    public function onBefore(callable $callback): self
    {
        $this->callbacks['before'][] = $callback;
        return $this;
    }
    
    public function onAfter(callable $callback): self
    {
        $this->callbacks['after'][] = $callback;
        return $this;
    }
    
    public function onResponse(callable $callback): self
    {
        return $this->onAfter($callback);
    }
    
    /**
     * 运行回调函数
     * @param mixed $data 回调可读写的数据（before 是 payload，after 是响应对象）
     */
    protected function runCallbacks(string $event, &$data): void
    {
        if (isset($this->callbacks[$event])) {
            foreach ($this->callbacks[$event] as $callback) {
                try {
                    $callback($data);
                } catch (\Throwable $e) {
                    // 回调是调用方的代码，抛什么都不能影响主流程
                    \Ai\Helpers\Log::warning('回调抛出异常（已忽略，不影响主流程）', ['event' => $event, 'error' => $e->getMessage()]);
                }
            }
        }
    }
    
    /**
     * 检查模型能力
     */
    public function model(): ?ModelInterface
    {
        return $this->model;
    }
    
    /**
     * 替换传输层
     *
     * 默认使用 Ai\Transport\CurlTransport。业务层可换成自己的实现来：
     *   - 接入连接池 / HTTP 客户端（Guzzle 等）
     *   - 单元测试里注入假传输层，不发真实请求（见 tests/）
     *   - 统一加埋点、审计日志、企业代理策略
     */
    public function setTransport(TransportInterface $transport): self
    {
        $this->transport = $transport;
        return $this;
    }

    /**
     * 获取当前传输层实例
     */
    public function transport(): TransportInterface
    {
        return $this->transport;
    }

    /**
     * 配置自动重试策略
     *
     * 默认对 429（限流）与 5xx（服务端临时故障）重试 2 次，指数退避 + 抖动，
     * 服务端给了 Retry-After 时优先采纳。流式请求不重试（分片已经吐给调用方，
     * 重试会造成重复输出）。
     *
     * @param int      $maxRetries 最大重试次数（不含首次），0 关闭
     * @param int|null $baseMs     退避基数（毫秒）
     * @param int|null $maxDelayMs 单次等待上限（毫秒）
     */
    public function setRetry(int $maxRetries, ?int $baseMs = null, ?int $maxDelayMs = null): self
    {
        if (method_exists($this->transport, 'setRetry')) {
            $this->transport->setRetry($maxRetries, $baseMs, $maxDelayMs);
        }
        return $this;
    }

    /**
     * 设置超时时间
     */
    public function setTimeout(int $timeout): self
    {
        $this->transport->setTimeout($timeout);
        return $this;
    }

    /**
     * 设置连接超时时间（秒）。
     * 独立于总超时，用于快速失败场景。不调用则与总超时一致。
     */
    public function setConnectTimeout(int $seconds): self
    {
        if (method_exists($this->transport, 'setConnectTimeout')) {
            $this->transport->setConnectTimeout($seconds);
        }
        return $this;
    }

    /**
     * 设置 User-Agent。
     * 传空串（默认）则不发送 User-Agent 头。
     */
    public function setUserAgent(string $userAgent): self
    {
        if (method_exists($this->transport, 'setUserAgent')) {
            $this->transport->setUserAgent($userAgent);
        }
        return $this;
    }

    /**
     * 设置是否校验 SSL 证书。
     * 生产环境不应关闭，仅调试/内网自签证书时使用。
     */
    public function setSslVerify(bool $verify): self
    {
        if (method_exists($this->transport, 'setSslVerify')) {
            $this->transport->setSslVerify($verify);
        }
        return $this;
    }

    /**
     * 获取最近一次请求的 cURL info（调试用）
     * @return array<mixed>
     */
    public function getLastInfo(): array
    {
        if (method_exists($this->transport, 'getLastInfo')) {
            return $this->transport->getLastInfo();
        }
        return [];
    }

    /**
     * 设置网络代理
     * 支持格式：
     * - http://host:port
     * - https://host:port  
     * - socks5://host:port
     * - socks5h://host:port (DNS 也通过代理)
     * - socks4://host:port
     * - socks4a://host:port
     */
    public function setProxy(string $proxy): self
    {
        $this->transport->setProxy($proxy);
        return $this;
    }

    /**
     * 注册流式分片回调
     *
     * 默认（未注册回调）时，chat() 会把 SSE 报文直接写进输出缓冲区，这只适用于
     * PHP-FPM / CLI。**Swoole、Workerman、RoadRunner 等常驻内存框架必须注册回调**，
     * 否则 echo 出去的分片会落到进程标准输出，永远送不到客户端。
     *
     * 回调收到的事件结构与库默认输出的 SSE 报文一致，另附平台原始分片：
     *   [ 'type' => 'stream_chunk', 'content' => 增量文本|null, 'raw' => 平台原始分片数组 ]
     *   [ 'type' => 'stream_end',   'data' => [ 'content' =>…, 'model' =>…, 'usage' =>… ] ]
     *
     * `content` 已由协议层归一化，跨平台通用；需要平台专有字段时再取 `raw`。
     *
     * 用法（Swoole）：
     * ```php
     * $ai->setStream(true)->setStreamCallback(function($event) use ($response) {
     *     if ($event['type'] === 'stream_chunk' && $event['content'] !== null) {
     *         $response->write("data: " . json_encode($event) . "\n\n");
     *     }
     * })->chat($messages);
     * ```
     *
     * @param callable|null $callback function(array $event):void；传 null 恢复默认的直接输出
     */
    public function setStreamCallback($callback = null): self
    {
        $this->streamCallback = is_callable($callback) ? $callback : null;
        return $this;
    }

    /**
     * 下发一个流式事件
     *
     * 注册了回调就交给回调，否则维持默认行为：按 SSE 报文写进输出缓冲区。
     * 默认路径不下发 `raw`，报文格式与历史版本完全一致，前端无需改动。
     * @param array<mixed> $event
     */
    protected function emitStream(array $event): void
    {
        if ($this->streamCallback) {
            call_user_func($this->streamCallback, $event);
            return;
        }

        $wire = $event;
        unset($wire['raw']);
        $this->flushText( 'data: ' . json_encode($wire, JSON_UNESCAPED_UNICODE) . "\n\n" );
        if ($event['type'] === 'stream_end') {
            $this->flushText( "data: [DONE]\n\n" );
        }
    }

    public function setStream(bool $stream): self
    {
        $this->stream = $stream;
        return $this;
    }

    /**
     * 当前是否处于流式模式
     *
     * 供调用方在临时切换后恢复原状（Agent 内部即如此使用）。
     */
    public function isStreaming(): bool
    {
        return $this->stream;
    }
    
    /**
     * 平台列表
     *
     * 列举本库支持的全部平台（国内的通义千问、豆包、文心、GLM、Kimi、混元……，
     * 海外的 OpenAI、Claude、Gemini、Grok、Mistral……，以及聚合中转与本地部署）。
     * 平台键即业务层取密钥用的前缀，约定为 {平台}__api_key。
     *
     * @return array<string, string> ['openai' => 'OpenAI', 'deepseek' => 'DeepSeek 深度求索', 'qwen' => '阿里云百炼（通义千问）', ...]
     */
    public function listPlatforms(): array
    {
        $platforms = \Ai\Helpers\Protocols::platforms();

        // 内置模型类里若有协议注册表未覆盖的平台，一并列出
        foreach ($this->modelMap as $modelClass) {
            if (class_exists($modelClass)) {
                $platform = (new $modelClass())->getPlatform();
                if (!isset($platforms[$platform])) {
                    $platforms[$platform] = ucfirst($platform);
                }
            }
        }
        return $platforms;
    }

    /**
     * 协议格式列表
     * 用于后台「手选协议格式」下拉框：接入任意兼容接口时，由用户指定按哪种协议通信
     * @return array<string, string> ['openai' => 'OpenAI 兼容（Chat Completions）', ...]
     */
    public function listProtocols(): array
    {
        return \Ai\Helpers\Protocols::all();
    }

    /**
     * 协议格式列表（按「中国大陆 / 海外主流 / 聚合中转 / 本地部署」分组）
     * 用于后台下拉框的 optgroup 渲染
     * @return array<string, array<string, string>> ['中国大陆' => ['deepseek' => '...', ...], ...]
     */
    public function listProtocolGroups(): array
    {
        return \Ai\Helpers\Protocols::grouped();
    }

    /**
     * 某协议/平台内置的常用模型清单（不发请求）
     *
     * 与 listModels() 的区别：listModels() 实时调用平台接口（需要 Key、有网络开销），
     * 本方法直接返回库内维护的常用模型，适合后台下拉框的默认值与离线渲染。
     *
     * @param string $protocol 协议标识（如 'qwen'、'zhipu'）或协议类名；留空则用当前模型的协议
     * @return array<string, string> ['模型 id' => '显示名']，无内置清单时返回空数组
     */
    public function listKnownModels(string $protocol = ''): array
    {
        $protocol = trim($protocol);
        if ($protocol === '') {
            if (!$this->model) {
                return [];
            }
            $protocol = $this->model->getProtocol();
        }
        return \Ai\Helpers\Protocols::modelsOf($protocol);
    }

    /**
     * 获取当前模型的平台
      * @return string|null 平台名称，如 'openai'、'gemini'，如果未设置模型或模型无效返回 null
     */
    public function getPlatform(): ?string
    {
        return $this->model ? $this->model->getPlatform() : null;
    }

    /**
     * 获取当前使用的协议标识（自定义协议类返回类名）
     * @return string|null 未设置模型返回 null
     */
    public function getProtocolKey(): ?string
    {
        if (!$this->model) {
            return null;
        }
        $class = $this->model->getProtocol();
        return \Ai\Helpers\Protocols::keyOfClass($class) ?: $class;
    }

    /**
     * 推断模型标识所属平台（不实例化模型、不抛异常）
     *
     * 业务层常需要「先由模型名找到该平台的 API Key 配置」，再一次性把配置塞给 AI 实例，
     * 此方法可在设置模型前安全调用。
     *
     * @param string $modelName 模型标识，可为内置标识或任意自定义模型名
     * @return string 平台名，无法判断时返回 'custom'
     */
    public function platformOfModel(string $modelName): string
    {
        $name = trim($modelName);
        if ($name === '') {
            return 'custom';
        }
        if (isset($this->modelMap[$name])) {
            $modelClass = $this->modelMap[$name];
            return (new $modelClass())->getPlatform();
        }
        $detected = \Ai\Helpers\Protocols::detect($name);
        return $detected !== null ? \Ai\Helpers\Protocols::platformOf($detected) : 'custom';
    }
    
    /**
     * 列举可用模型列表
     * 根据当前设置的平台/协议，调用相应的模型列表接口
     * 如果模型配置中没有指定具体模型，则返回所有支持的平台列表（从模型映射表动态获取）
     * 如果平台不支持模型列表API，返回 null
     *
     * @param bool $raw 为 true 时返回平台原始模型数据（含 pricing、context_length 等），
     *                  默认为空则返回 ['model_id' => 'model_name']
     * @return array<mixed> 模型列表，不支持返回 null
     *
     * @example
     * $models = $ai->listModels();
     * // ['gpt-4' => 'gpt-4', 'gpt-3.5-turbo' => 'gpt-3.5-turbo']
     *
     * $models = $ai->listModels(true);
     * // ['gpt-4' => ['id'=>'gpt-4', 'created'=>..., ...], ...]
     */
    public function listModels(bool $raw = false): ?array
    {
        // 如果没有指定模型，返回所有支持的平台列表
        if( ! isset($this->config['model']) ) {
            return array_keys($this->modelMap);
        }
        if (!$this->protocol) {
            throw new ConfigException('Protocol not initialized. Please set a model first.');
        }
        // 把实际对话端点一并传给协议层，便于自定义接口推导出同源的模型列表端点
        $config = $this->config;
        $config['endpoint'] = $this->currentEndpoint();
        if ($raw) {
            $config['__models_raw'] = true;
        }
        return $this->protocol->listModels($config, $this->transport);
    }

}
