<?php
namespace Ai\Agent\Mcp;

/**
 * McpClient——MCP 服务器 stdio JSON-RPC 客户端
 *
 * 通过子进程的 stdio 管道与 MCP 服务器通信。
 * 每个 McpClient 对应一个独立的 MCP 服务器进程。
 *
 * MCP 协议版本：JSON-RPC 2.0 over stdio
 * 生命周期：initialize → tools/list → tools/call → shutdown
 *
 * 用法：
 * ```php
 * $client = new McpClient('npx', ['-y', '@modelcontextprotocol/server-fs', '/path']);
 * $client->initialize();
 * $tools = $client->listTools();
 * $result = $client->callTool('read_file', ['path' => '/tmp/test.txt']);
 * $client->shutdown();
 * ```
 */
class McpClient
{
    /** @var string 可执行文件 */
    protected $command = '';

    /** @var string[] 参数 */
    protected $args = [];

    /** @var resource|null proc_open 进程句柄 */
    protected $process = null;

    /** @var resource|null stdout 管道 */
    protected $stdout = null;

    /** @var resource|null stdin 管道 */
    protected $stdin = null;

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
    }

    /** @return string */
    public function getLabel()
    {
        return $this->label;
    }

    /** @return bool */
    public function isRunning()
    {
        return $this->process !== null;
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
        if ($this->process !== null) {
            return;
        }

        $cmd = $this->command;
        if ($this->args) {
            foreach ($this->args as $arg) {
                $cmd .= ' ' . escapeshellarg((string) $arg);
            }
        }

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $proc = @proc_open($cmd, $descriptors, $pipes);
        if ($proc === false) {
            throw new \RuntimeException("无法启动 MCP 服务器：{$this->command}");
        }
        $this->process = $proc;

        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->requestId = 0;
        $this->initialized = false;

        // 设置流为非阻塞
        stream_set_blocking($this->stdout, false);
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
        if ($this->process === null) {
            return;
        }

        try {
            $this->sendNotification('shutdown', []);
        } catch (\Throwable $e) {
            // 忽略关闭时的错误
        }

        // 关闭管道
        if ($this->stdin) {
            @fclose($this->stdin);
            $this->stdin = null;
        }
        if ($this->stdout) {
            @fclose($this->stdout);
            $this->stdout = null;
        }

        // 终止进程
        if (is_resource($this->process)) {
            $status = @proc_get_status($this->process);
            if ($status !== false) {
                // 发送 SIGTERM
                if (function_exists('proc_terminate')) {
                    @proc_terminate($this->process, 15); // SIGTERM
                }
                // 等待退出
                for ($i = 0; $i < 10; $i++) {
                    $status = @proc_get_status($this->process);
                    if (!is_array($status) || empty($status['running'])) {
                        break;
                    }
                    usleep(100000); // 100ms
                }
            }
            @proc_close($this->process);
            $this->process = null;
        }

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

        $request = json_encode([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'method'  => $method,
            'params'  => (object) $params,
        ], JSON_UNESCAPED_UNICODE);

        if ($request === false) {
            throw new \RuntimeException('MCP 请求序列化失败');
        }

        $this->write($request);

        return $this->readResponse($id);
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
        $request = json_encode([
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => (object) $params,
        ], JSON_UNESCAPED_UNICODE);

        if ($request === false) {
            return;
        }

        $this->write($request);
    }

    /**
     * 写入 stdin
     *
     * MCP over stdio 使用 Content-Length 头 + JSON 体
     *
     * @param string $data
     * @return void
     * @throws \RuntimeException
     */
    protected function write($data)
    {
        if (!$this->stdin) {
            throw new \RuntimeException('MCP stdin 未打开');
        }

        $message = "Content-Length: " . strlen($data) . "\r\n\r\n" . $data;
        $written = @fwrite($this->stdin, $message);
        @fflush($this->stdin);

        if ($written === false) {
            throw new \RuntimeException('MCP 写入失败');
        }
    }

    /**
     * 读取响应
     *
     * 解析 MCP Content-Length 头 + JSON 体格式
     *
     * @param int $expectedId
     * @return array<string, mixed>
     * @throws \RuntimeException
     */
    protected function readResponse($expectedId)
    {
        $buffer = '';
        $start = time();

        while (true) {
            // 检查超时
            if ((time() - $start) > $this->timeout) {
                throw new \RuntimeException("MCP 响应超时（{$this->timeout}s）");
            }

            // 读取 stdout
            if ($this->stdout) {
                $chunk = @fread($this->stdout, 65536);
                if ($chunk !== false && $chunk !== '') {
                    $buffer .= $chunk;

                    $decoded = $this->tryParseMessage($buffer, $expectedId);
                    if ($decoded !== null) {
                        return $decoded;
                    }
                }
            }

            usleep(50000); // 50ms
        }
    }

    /**
     * 尝试从 buffer 中解析 MCP 消息
     *
     * 标准格式：Content-Length: N\r\n\r\n{json}
     * 兼容格式：裸 JSON 换行分隔
     *
     * @param string $buffer
     * @param int $expectedId
     * @return array<string, mixed>|null 解析成功且 ID 匹配时返回解码后的数组
     */
    protected function tryParseMessage(&$buffer, $expectedId)
    {
        // 尝试标准 Content-Length 格式
        if (preg_match('/Content-Length: (\d+)\r?\n\r?\n/', $buffer, $headerMatch, PREG_OFFSET_CAPTURE)) {
            $contentLength = (int) $headerMatch[1][0];
            $headerEnd = $headerMatch[0][0];
            $bodyStartPos = $headerMatch[0][1] + strlen($headerEnd);

            if (strlen($buffer) >= $bodyStartPos + $contentLength) {
                $json = substr($buffer, $bodyStartPos, $contentLength);
                $decoded = json_decode($json, true);
                // 移除已处理的部分
                $buffer = substr($buffer, $bodyStartPos + $contentLength);
                if (is_array($decoded)) {
                    return $this->filterResponse($decoded, $expectedId);
                }
            }
            return null; // 还没收够字节
        }

        // 尝试裸 JSON 换行分隔（兼容简化实现）
        $lines = explode("\n", $buffer);
        if (count($lines) > 1) {
            $lastIdx = count($lines) - 1;
            $buffer = $lines[$lastIdx]; // 保留未完成的行
            for ($i = 0; $i < $lastIdx; $i++) {
                $line = trim($lines[$i]);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $result = $this->filterResponse($decoded, $expectedId);
                    if ($result !== null) {
                        return $result;
                    }
                }
            }
        }

        return null;
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