<?php
namespace Ai\Agent\Mcp;

/**
 * McpClient——MCP 服务器 JSON-RPC 客户端
 *
 * 负责 MCP 协议本身：握手、列工具、调工具、关闭。字节怎么送出去由
 * `McpTransportInterface` 决定——本地进程走 stdio，远程服务走 HTTP / SSE，
 * 需要长连接走 WebSocket。
 *
 * 生命周期：initialize → tools/list → tools/call → shutdown
 *
 * ```php
 * // stdio（默认）：把 MCP 服务器作为子进程拉起来
 * $client = new McpClient('npx', ['-y', '@modelcontextprotocol/server-fs', '/path']);
 * $client->initialize();
 * $tools = $client->listTools();
 * $result = $client->callTool('read_file', ['path' => '/tmp/test.txt']);
 * $client->shutdown();
 *
 * // HTTP / SSE：连远程 MCP 服务
 * $client = McpClient::fromConfig([
 *     'transport' => 'http',
 *     'url'       => 'https://mcp.example.com/rpc',
 *     'headers'   => ['Authorization: Bearer xxx'],
 * ]);
 *
 * // WebSocket
 * $client = McpClient::fromConfig(['transport' => 'websocket', 'url' => 'wss://mcp.example.com/ws']);
 * ```
 */
class McpClient
{
    /** @var string 可执行文件 */
    protected $command = '';

    /** @var string[] 参数 */
    protected $args = [];

    /** @var McpTransportInterface 传输层 */
    protected $transport;

    /** @var int 请求 ID 自增 */
    protected $requestId = 0;

    /** @var bool 是否已初始化 */
    protected $initialized = false;

    /** @var string 服务器名称 */
    protected $serverName = '';

    /** @var string 服务器版本 */
    protected $serverVersion = '';

    /** @var int 超时秒数 */
    protected $timeout = 15;

    /** @var string 进程描述名 */
    protected $label = '';

    /**
     * @param string $command 可执行文件
     * @param string[] $args 参数列表
     * @param array<string, mixed> $options 可选：timeout, label
     */
    public function __construct($command, array $args = [], array $options = [])
    {
        $this->command = (string) $command;
        $this->args = $args;
        $this->label = isset($options['label']) ? (string) $options['label'] : basename((string) $command);
        if (isset($options['timeout'])) {
            $this->timeout = (int) $options['timeout'];
        }
        $this->transport = isset($options['transport']) && $options['transport'] instanceof McpTransportInterface
            ? $options['transport']
            : new McpStdioTransport($this->command, $this->args, $options);
    }

    /**
     * 按配置造一个客户端
     *
     * `transport` 可以是 `stdio` / `http` / `sse` / `websocket`，也可以直接传一个
     * 实现了 `McpTransportInterface` 的对象。
     *
     * ```php
     * McpClient::fromConfig(['command' => 'npx', 'args' => ['-y', 'server-fs']]);
     * McpClient::fromConfig(['transport' => 'http', 'url' => 'https://example.com/mcp']);
     * McpClient::fromConfig(['transport' => 'websocket', 'url' => 'wss://example.com/mcp']);
     * ```
     *
     * @param array<string, mixed> $config transport / command / args / url / headers / timeout / label
     * @return self
     */
    public static function fromConfig(array $config)
    {
        $options = $config;
        $transport = isset($config['transport']) ? $config['transport'] : 'stdio';

        if (!$transport instanceof McpTransportInterface) {
            $url = isset($config['url']) ? (string) $config['url'] : '';
            switch (strtolower((string) $transport)) {
                case 'http':
                case 'sse':
                    $transport = new McpHttpTransport($url, $config);
                    break;
                case 'websocket':
                case 'ws':
                    $transport = new McpWebSocketTransport($url, $config);
                    break;
                default:
                    $transport = null;   // stdio：交给构造函数按 command / args 建
            }
        }

        if ($transport !== null) {
            $options['transport'] = $transport;
            if (!isset($options['label'])) {
                $options['label'] = isset($config['url']) ? (string) $config['url'] : $transport->name();
            }
        }

        return new self(
            isset($config['command']) ? (string) $config['command'] : '',
            isset($config['args']) && is_array($config['args']) ? $config['args'] : [],
            $options
        );
    }

    /**
     * 当前传输层
     *
     * @return McpTransportInterface
     */
    public function getTransport()
    {
        return $this->transport;
    }

    /**
     * 传输方式名称
     *
     * @return string
     */
    public function getTransportName()
    {
        return $this->transport->name();
    }

    /** @return string */
    public function getLabel()
    {
        return $this->label;
    }

    /** @return bool */
    public function isRunning()
    {
        return $this->transport->isOpen();
    }

    /** @return bool */
    public function isInitialized()
    {
        return $this->initialized;
    }

    /**
     * 启动 MCP 服务器进程
     *
     * @return void
     * @throws \RuntimeException
     */
    public function start()
    {
        if ($this->transport->isOpen()) {
            return;
        }
        $this->transport->open();
        $this->requestId = 0;
        $this->initialized = false;
    }

    /**
     * 执行 MCP 初始化握手
     *
     * @return array<string, mixed>
     * @throws \RuntimeException
     */
    public function initialize()
    {
        $this->start();

        $response = $this->sendRequest('initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
            'clientInfo' => [
                'name'    => 'php-ai',
                'version' => '1.0.0',
            ],
        ]);

        if (!is_array($response)) {
            throw new \RuntimeException('MCP 初始化失败：无效响应');
        }

        $result = isset($response['result']) ? $response['result'] : $response;
        if (isset($result['serverInfo'])) {
            $this->serverName = isset($result['serverInfo']['name']) ? (string) $result['serverInfo']['name'] : '';
            $this->serverVersion = isset($result['serverInfo']['version']) ? (string) $result['serverInfo']['version'] : '';
        }

        // 发送 initialized 通知
        $this->sendNotification('notifications/initialized', []);

        $this->initialized = true;
        return $result;
    }

    /**
     * 获取工具清单
     *
     * @return array<int, array{name: string, description?: string, inputSchema?: array<string, mixed>}>
     * @throws \RuntimeException
     */
    public function listTools()
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        $response = $this->sendRequest('tools/list', []);

        if (!is_array($response) || !isset($response['result'])) {
            return [];
        }

        $result = $response['result'];
        $tools = isset($result['tools']) && is_array($result['tools']) ? $result['tools'] : [];

        $formatted = [];
        foreach ($tools as $tool) {
            $formatted[] = [
                'name'        => isset($tool['name']) ? (string) $tool['name'] : '',
                'description' => isset($tool['description']) ? (string) $tool['description'] : '',
                'inputSchema' => isset($tool['inputSchema']) && is_array($tool['inputSchema'])
                    ? $tool['inputSchema']
                    : ['type' => 'object', 'properties' => new \stdClass()],
            ];
        }

        return $formatted;
    }

    /**
     * 调用 MCP 工具
     *
     * @param string $toolName
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> 工具返回的 content 数组
     * @throws \RuntimeException
     */
    public function callTool($toolName, array $arguments = [])
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        try {
            $response = $this->sendRequest('tools/call', [
                'name'      => (string) $toolName,
                'arguments' => (object) $arguments,
            ]);
        } catch (\Throwable $e) {
            // MCP 服务器返回错误（如未知工具）→ 转成工具错误结果回填给模型
            return [
                'content'  => 'MCP 错误：' . $e->getMessage(),
                'is_error' => true,
                'raw'      => [],
            ];
        }

        if (!is_array($response) || !isset($response['result'])) {
            return ['error' => 'MCP 工具调用失败：无效响应'];
        }

        $result = $response['result'];
        $content = isset($result['content']) && is_array($result['content']) ? $result['content'] : [];
        $isError = !empty($result['isError']);

        // 将 content 数组转为文本
        $text = '';
        foreach ($content as $block) {
            if (is_array($block) && isset($block['text'])) {
                $text .= $block['text'];
            } elseif (is_array($block) && isset($block['type'])) {
                $text .= '[' . $block['type'] . ']';
            }
        }

        return [
            'content'  => $text,
            'is_error' => $isError,
            'raw'      => $content,
        ];
    }

    /**
     * 关闭 MCP 服务器
     *
     * @return void
     */
    public function shutdown()
    {
        if (!$this->transport->isOpen()) {
            $this->initialized = false;
            return;
        }

        try {
            $this->sendNotification('shutdown', []);
        } catch (\Throwable $e) {
            // 忽略关闭时的错误
        }

        $this->transport->close();
        $this->initialized = false;
    }

    /**
     * 发送 JSON-RPC 请求并等待响应
     *
     * @param string $method
     * @param array<string, mixed> $params
     * @return array<string, mixed> 解码后的响应
     * @throws \RuntimeException
     */
    protected function sendRequest($method, array $params = [])
    {
        $this->requestId++;
        $id = $this->requestId;

        $response = $this->transport->request([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'method'  => (string) $method,
            'params'  => (object) $params,
        ], $this->timeout);

        if (!is_array($response)) {
            throw new \RuntimeException("MCP 无响应：{$method}");
        }

        $filtered = $this->filterResponse($response, $id);
        if ($filtered === null) {
            // 传输层已按 ID 匹配过，走到这里说明服务端回了一条对不上的消息
            throw new \RuntimeException("MCP 响应 ID 不匹配：{$method}");
        }
        return $filtered;
    }

    /**
     * 发送 JSON-RPC 通知（无需响应）
     *
     * @param string $method
     * @param array<string, mixed> $params
     * @return void
     */
    protected function sendNotification($method, array $params = [])
    {
        $this->transport->notify([
            'jsonrpc' => '2.0',
            'method'  => (string) $method,
            'params'  => (object) $params,
        ]);
    }

    /**
     * 过滤响应：检查错误，匹配 ID
     *
     * @param array<string, mixed> $decoded
     * @param int $expectedId
     * @return array<string, mixed>|null ID 不匹配时返回 null
     * @throws \RuntimeException 有错误时抛出
     */
    protected function filterResponse(array $decoded, $expectedId)
    {
        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $msg = isset($decoded['error']['message']) ? $decoded['error']['message'] : 'MCP 错误';
            throw new \RuntimeException("MCP 错误：{$msg}");
        }
        $respId = isset($decoded['id']) ? $decoded['id'] : null;
        if ($respId !== null && $respId !== $expectedId) {
            return null; // ID 不匹配，让调用方继续等待
        }
        return $decoded;
    }
}