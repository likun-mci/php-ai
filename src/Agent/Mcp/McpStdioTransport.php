<?php
namespace Ai\Agent\Mcp;

/**
 * McpStdioTransport——stdio 子进程传输
 *
 * 把 MCP 服务器作为子进程拉起来，通过 stdin / stdout 收发 JSON-RPC。
 * 本地工具（文件系统、Git、数据库客户端）用这种方式，没有网络开销，
 * 进程生命周期跟着 Agent 走。
 *
 * 消息按 MCP 规范的 `Content-Length` 头分帧；不少实现直接发裸 JSON 换行分隔，
 * 两种都能读。
 *
 * ```php
 * $transport = new McpStdioTransport('npx', ['-y', '@modelcontextprotocol/server-fs', '/tmp']);
 * $transport->open();
 * $response = $transport->request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], 15);
 * ```
 */
class McpStdioTransport implements McpTransportInterface
{
    /** @var string */
    protected $command = '';

    /** @var string[] */
    protected $args = [];

    /** @var resource|null */
    protected $process = null;

    /** @var resource|null */
    protected $stdin = null;

    /** @var resource|null */
    protected $stdout = null;

    /** @var string 未消费完的读缓冲 */
    protected $buffer = '';

    /** @var array<string, mixed> 环境变量，null 表示继承父进程 */
    protected $env = [];

    /** @var string 工作目录 */
    protected $cwd = '';

    /**
     * @param string $command
     * @param string[] $args
     * @param array<string, mixed> $options env / cwd
     */
    public function __construct($command, array $args = [], array $options = [])
    {
        $this->command = (string) $command;
        $this->args = array_values(array_map('strval', $args));
        if (isset($options['env']) && is_array($options['env'])) {
            $this->env = $options['env'];
        }
        if (isset($options['cwd'])) {
            $this->cwd = (string) $options['cwd'];
        }
    }

    /**
     * @return string
     */
    public function name()
    {
        return 'stdio';
    }

    /**
     * @return void
     */
    public function open()
    {
        if ($this->process !== null) {
            return;
        }

        $cmd = $this->command;
        foreach ($this->args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cwd = $this->cwd !== '' && is_dir($this->cwd) ? $this->cwd : null;
        $env = $this->env ? $this->env : null;

        $proc = @proc_open($cmd, $descriptors, $pipes, $cwd, $env);
        if ($proc === false) {
            throw new \RuntimeException("无法启动 MCP 服务器：{$this->command}");
        }

        $this->process = $proc;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->buffer = '';
        stream_set_blocking($this->stdout, false);
    }

    /**
     * @return bool
     */
    public function isOpen()
    {
        return $this->process !== null;
    }

    /**
     * @return void
     */
    public function close()
    {
        if ($this->process === null) {
            return;
        }

        if ($this->stdin) {
            @fclose($this->stdin);
            $this->stdin = null;
        }
        if ($this->stdout) {
            @fclose($this->stdout);
            $this->stdout = null;
        }

        if (is_resource($this->process)) {
            if (function_exists('proc_terminate')) {
                @proc_terminate($this->process, 15);
            }
            for ($i = 0; $i < 10; $i++) {
                $status = @proc_get_status($this->process);
                if (!is_array($status) || empty($status['running'])) {
                    break;
                }
                usleep(100000);
            }
            @proc_close($this->process);
        }
        $this->process = null;
        $this->buffer = '';
    }

    /**
     * @param array<string, mixed> $payload
     * @param int $timeout
     * @return array<string, mixed>|null
     */
    public function request(array $payload, $timeout)
    {
        $this->open();
        $this->write($payload);

        $expectedId = isset($payload['id']) ? $payload['id'] : null;
        $timeout = max(1, (int) $timeout);
        $start = time();

        while (true) {
            if ((time() - $start) > $timeout) {
                throw new \RuntimeException("MCP 响应超时（{$timeout}s）");
            }
            if ($this->stdout) {
                $chunk = @fread($this->stdout, 65536);
                if ($chunk !== false && $chunk !== '') {
                    $this->buffer .= $chunk;
                    $message = $this->tryParseMessage($expectedId);
                    if ($message !== null) {
                        return $message;
                    }
                }
            }
            usleep(50000);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return void
     */
    public function notify(array $payload)
    {
        if ($this->process === null) {
            return;
        }
        $this->write($payload);
    }

    /**
     * 写一条 JSON-RPC 消息
     *
     * @param array<string, mixed> $payload
     * @return void
     */
    protected function write(array $payload)
    {
        if (!$this->stdin) {
            throw new \RuntimeException('MCP stdin 未打开');
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('MCP 请求序列化失败');
        }
        $message = 'Content-Length: ' . strlen($json) . "\r\n\r\n" . $json;
        $written = @fwrite($this->stdin, $message);
        @fflush($this->stdin);
        if ($written === false) {
            throw new \RuntimeException('MCP 写入失败');
        }
    }

    /**
     * 从缓冲里解析出一条匹配的消息
     *
     * @param mixed $expectedId
     * @return array<string, mixed>|null
     */
    protected function tryParseMessage($expectedId)
    {
        // 标准分帧：Content-Length 头 + JSON 体
        if (preg_match('/Content-Length: (\d+)\r?\n\r?\n/', $this->buffer, $m, PREG_OFFSET_CAPTURE)) {
            $length = (int) $m[1][0];
            $bodyStart = $m[0][1] + strlen($m[0][0]);
            if (strlen($this->buffer) >= $bodyStart + $length) {
                $json = substr($this->buffer, $bodyStart, $length);
                $this->buffer = substr($this->buffer, $bodyStart + $length);
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    return $this->matchId($decoded, $expectedId);
                }
            }
            return null;   // 还没收够
        }

        // 兼容裸 JSON 换行分隔
        $lines = explode("\n", $this->buffer);
        if (count($lines) > 1) {
            $last = count($lines) - 1;
            $this->buffer = $lines[$last];
            for ($i = 0; $i < $last; $i++) {
                $line = trim($lines[$i]);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $matched = $this->matchId($decoded, $expectedId);
                    if ($matched !== null) {
                        return $matched;
                    }
                }
            }
        }
        return null;
    }

    /**
     * ID 对得上才算这次请求的响应——服务器可能穿插发通知
     *
     * @param array<string, mixed> $decoded
     * @param mixed $expectedId
     * @return array<string, mixed>|null
     */
    protected function matchId(array $decoded, $expectedId)
    {
        if ($expectedId === null) {
            return $decoded;
        }
        if (!isset($decoded['id'])) {
            return null;   // 通知，不是响应
        }
        return (string) $decoded['id'] === (string) $expectedId ? $decoded : null;
    }
}
