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
     * @var array
     */
    protected $config = [];

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
     * @var array
     */
    protected $callbacks = [
        'before' => [],
        'after' => [],
    ];
    
    /**
     * 附件列表
     * @var array
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

    protected $session_id = null;

    /**
     * 可由运行时配置透传到请求体的生成参数白名单
     * （config 里的 api_key、base_url 等连接信息不会进入请求体）
     * @var array
     */
    protected static $payloadKeys = [
        'max_tokens', 'max_completion_tokens', 'temperature', 'top_p', 'top_k', 'stop',
        'presence_penalty', 'frequency_penalty', 'seed', 'response_format',
        'system', 'tools', 'tool_choice', 'reasoning_effort', 'thinking',
    ];

    /**
     * 数值型生成参数的取值范围 [最小值, 最大值]
     * 超出范围时自动截断到最近的边界值。
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
     * @param array $params 配置项或 payload 数组
     * @return array
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
     * @var array
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
     */
    public function __construct(array $config = [])
    {
        ksort($this->modelMap);
        $this->transport = new CurlTransport();
        $this->setConfig($config);
    }

    public function setSessionId(string $sessionId): self
    {
        $this->session_id = $sessionId;
        return $this;
    }

    /**
     * 设置配置项
     * 
     * 支持的配置项包括：
     * - model: 模型名称或模型实例（优先级最高；可为内置标识，也可为任意自定义模型名）
     * - protocol: 手选协议格式，openai / claude(anthropic) / gemini / deepseek，或自定义协议类名
     * - base_url: 接口根地址，与协议官方路径智能拼接（接入第三方转发/中转、自建网关）
     * - endpoint: 完整对话端点，原样使用，优先级高于 base_url
     * - endpoint_models: 完整模型列表端点（仅 listModels 生效）
     * - platform: 平台名，仅用于业务层标识（默认取协议对应平台）
     * - api_key: API 密钥（部分平台需要）
     * - headers: 追加/覆盖请求头，值为 null 表示删除协议默认头
     * - extra_body: 追加到请求体的私有参数
     * - organization: 组织 ID（OpenAI 可选）
     * - project_id: 项目 ID（DeepSeek 可选）
     * - rounds: 自定义对话轮数，控制上下文保留多少轮对话
     * - 其他生成参数，如 temperature、max_tokens、top_p 等（见 self::$payloadKeys）
     *
     * @param array $config 配置项数组
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
     */
    public function setAttachments(array $attachments): self
    {
        $this->attachments = $attachments;
        return $this;
    }
    
    /**
     * 对话
     * @param array|string $payload 对话内容，可以是字符串（用户消息）或数组（完整请求负载）
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
        // 重置流式累积内容
        $this->streamAccumulatedContent = '';
        
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
                // 输出流式数据块
                $flushData = json_encode( [ "type"=>"stream_chunk", "content"=>$content ], JSON_UNESCAPED_UNICODE);
                $this->flushText( "data: {$flushData}\n\n" );
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
                // 从 transport 获取完整 usage（含 prompt_tokens_details 等）
                $streamUsage = [];
                if (method_exists($this->transport, 'getStreamUsage')) {
                    $rawUsage = $this->transport->getStreamUsage();
                    if (!empty($rawUsage)) {
                        $streamUsage = $rawUsage; // 原样保留完整 usage
                        // 确保三个标准字段向下兼容
                        $streamUsage['prompt_tokens'] = $streamUsage['prompt_tokens'] ?? 0;
                        $streamUsage['completion_tokens'] = $streamUsage['completion_tokens'] ?? 0;
                        $streamUsage['total_tokens'] = $streamUsage['total_tokens'] ?? 0;
                    }
                }
                $response = new \Ai\Response\AIResponse([
                    'content' => $this->streamAccumulatedContent,
                    'model'   => $this->model->getName(),
                    'usage'   => $streamUsage,
                    'raw'     => $responseData,
                    'success' => true,
                ]);
                $flushData = json_encode( [ "type"=>"stream_end", "data"=>[
                    'content' => $this->streamAccumulatedContent,
                    'model'   => $this->model->getName(),
                    'usage'   => $streamUsage,
                ]], JSON_UNESCAPED_UNICODE);
                $this->flushText( "data: {$flushData}\n\n" )
                    ->flushText( "data: [DONE]\n\n" );
            } else {
                // 解析响应
                $response = $this->protocol->parseResponse($responseData);
            }
            
            // 执行 after 回调
            $this->runCallbacks('after', $response);
            
            // 清空附件（避免影响下次对话）
            $this->attachments = [];
            
            return $response;
            
        } catch (\Exception $e) {
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
                $rawResponse
            );
        }
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
     */
    protected function runCallbacks(string $event, &$data): void
    {
        if (isset($this->callbacks[$event])) {
            foreach ($this->callbacks[$event] as $callback) {
                try {
                    $callback($data);
                } catch (\Exception $e) {
                    // 回调异常不影响主流程
                    error_log('Callback error: ' . $e->getMessage());
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
     * @return array
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

    public function setStream(bool $stream): self
    {
        $this->stream = $stream;
        return $this;
    }
    
    /**
     * 平台列表
     * 列举当前类库支持哪些平台（如 openai、gemini、deepseek 等）
     * 根据模型映射表动态获取支持的平台列表，避免硬编码
     * @return array 平台列表 ['platform_key' => 'Platform Name']
     */
    public function listPlatforms(): array
    {
        $platforms = [];
        foreach ($this->modelMap as $modelClass) {
            if (class_exists($modelClass)) {
                $modelInstance = new $modelClass();
                $platforms[$modelInstance->getPlatform()] = ucfirst($modelInstance->getPlatform());
            }
        }
        $platforms = array_unique($platforms);
        asort($platforms);
        return $platforms;
    }

    /**
     * 协议格式列表
     * 用于后台「手选协议格式」下拉框：接入任意兼容接口时，由用户指定按哪种协议通信
     * @return array ['openai' => 'OpenAI 兼容（Chat Completions）', ...]
     */
    public function listProtocols(): array
    {
        return \Ai\Helpers\Protocols::all();
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
     * @return array|null 模型列表，不支持返回 null
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
