<?php
namespace Ai\Agent\Mcp;

/**
 * McpHttpTransport——HTTP / SSE 传输
 *
 * 把 JSON-RPC 请求 POST 到远程 MCP 服务端点。远程服务、团队共享的 MCP
 * 服务器用这种方式，不需要在 Agent 所在机器上装任何东西。
 *
 * 响应有两种形态，都能读：
 *  - `application/json`：一个 JSON-RPC 响应对象
 *  - `text/event-stream`（SSE）：多个 `data:` 事件，取其中 id 对得上的那条
 *
 * ```php
 * $transport = new McpHttpTransport('https://mcp.example.com/rpc', [
 *     'headers' => ['Authorization: Bearer xxx'],
 *     'timeout' => 30,
 * ]);
 * ```
 *
 * 说明：HTTP 是无状态的，`open()` / `close()` 不做实际连接管理，
 * 只是把接口对齐；会话状态靠服务端返回的 `Mcp-Session-Id` 头维持，
 * 拿到后会自动带在后续请求里。
 */
class McpHttpTransport implements McpTransportInterface
{
    /** @var string 端点 URL */
    protected $url = '';

    /** @var string[] 额外请求头 */
    protected $headers = [];

    /** @var int 超时秒数 */
    protected $timeout = 30;

    /** @var string 服务端分配的会话 ID */
    protected $sessionId = '';

    /** @var bool 是否已"打开" */
    protected $open = false;

    /** @var bool 是否声明接受 SSE 响应 */
    protected $sse = true;

    /** @var callable|null 自定义发送器 function(string $url, string $body, string[] $headers, int $timeout): array{body: string, headers: string[]} */
    protected $sender = null;

    /**
     * @param string $url
     * @param array<string, mixed> $options headers / timeout / sse / sender
     */
    public function __construct($url, array $options = [])
    {
        $this->url = (string) $url;
        if (isset($options['headers']) && is_array($options['headers'])) {
            $this->headers = array_values(array_map('strval', $options['headers']));
        }
        if (isset($options['timeout'])) {
            $this->timeout = max(1, (int) $options['timeout']);
        }
        if (isset($options['sse'])) {
            $this->sse = (bool) $options['sse'];
        }
        if (isset($options['sender'])) {
            $this->sender = $options['sender'];
        }
    }

    /**
     * @return string
     */
    public function name()
    {
        return $this->sse ? 'http' : 'http-json';
    }

    /**
     * @return void
     */
    public function open()
    {
        if ($this->url === '') {
            throw new \RuntimeException('MCP HTTP 传输缺少端点 URL');
        }
        $this->open = true;
    }

    /**
     * @return void
     */
    public function close()
    {
        $this->open = false;
        $this->sessionId = '';
    }

    /**
     * @return bool
     */
    public function isOpen()
    {
        return $this->open;
    }

    /**
     * @param array<string, mixed> $payload
     * @param int $timeout
     * @return array<string, mixed>|null
     */
    public function request(array $payload, $timeout)
    {
        $this->open();
        $response = $this->post($payload, (int) $timeout);
        if ($response['body'] === '') {
            return null;
        }

        $expectedId = isset($payload['id']) ? $payload['id'] : null;
        $contentType = $this->headerValue($response['headers'], 'content-type');

        if (stripos($contentType, 'text/event-stream') !== false) {
            return $this->parseSse($response['body'], $expectedId);
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            // 有的服务端不发 content-type，退一步按 SSE 试
            return $this->parseSse($response['body'], $expectedId);
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     * @return void
     */
    public function notify(array $payload)
    {
        try {
            $this->post($payload, $this->timeout);
        } catch (\Throwable $e) {
            // 通知不关心结果，服务端不接受也不该影响主流程
        }
    }

    /**
     * 服务端分配的会话 ID
     *
     * @return string
     */
    public function getSessionId()
    {
        return $this->sessionId;
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * 发一个 POST
     *
     * @param array<string, mixed> $payload
     * @param int $timeout
     * @return array{body: string, headers: string[]}
     */
    protected function post(array $payload, $timeout)
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException('MCP 请求序列化失败');
        }

        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: ' . ($this->sse ? 'application/json, text/event-stream' : 'application/json'),
        ], $this->headers);
        if ($this->sessionId !== '') {
            $headers[] = 'Mcp-Session-Id: ' . $this->sessionId;
        }

        $timeout = $timeout > 0 ? $timeout : $this->timeout;

        if ($this->sender !== null) {
            $result = call_user_func($this->sender, $this->url, $body, $headers, $timeout);
            $result = is_array($result) ? $result : ['body' => (string) $result, 'headers' => []];
        } else {
            $result = $this->curlPost($body, $headers, $timeout);
        }

        $responseHeaders = isset($result['headers']) && is_array($result['headers']) ? $result['headers'] : [];
        $session = $this->headerValue($responseHeaders, 'mcp-session-id');
        if ($session !== '') {
            $this->sessionId = $session;
        }

        return [
            'body'    => isset($result['body']) ? (string) $result['body'] : '',
            'headers' => $responseHeaders,
        ];
    }

    /**
     * 用 curl 发请求
     *
     * @param string $body
     * @param string[] $headers
     * @param int $timeout
     * @return array{body: string, headers: string[]}
     */
    protected function curlPost($body, array $headers, $timeout)
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('MCP HTTP 传输需要 ext-curl，或通过 sender 选项注入自定义发送器');
        }

        $responseHeaders = [];
        $ch = curl_init($this->url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$responseHeaders) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $responseHeaders[] = $trimmed;
            }
            return strlen($line);
        });

        $result = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false) {
            throw new \RuntimeException('MCP HTTP 请求失败：' . $error);
        }
        if ($status >= 400) {
            throw new \RuntimeException(
                'MCP HTTP 返回 ' . $status . '：' . \Ai\Helpers\Text::cutChars((string) $result, 200)
            );
        }
        return ['body' => (string) $result, 'headers' => $responseHeaders];
    }

    /**
     * 从 SSE 响应里取出匹配的 JSON-RPC 消息
     *
     * @param string $body
     * @param mixed $expectedId
     * @return array<string, mixed>|null
     */
    protected function parseSse($body, $expectedId)
    {
        $fallback = null;
        foreach (preg_split('/\r?\n/', (string) $body) ?: [] as $line) {
            if (strpos($line, 'data:') !== 0) {
                continue;
            }
            $json = trim(substr($line, 5));
            if ($json === '') {
                continue;
            }
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                continue;
            }
            if ($expectedId === null) {
                return $decoded;
            }
            if (isset($decoded['id']) && (string) $decoded['id'] === (string) $expectedId) {
                return $decoded;
            }
            if ($fallback === null && isset($decoded['result'])) {
                $fallback = $decoded;
            }
        }
        return $fallback;
    }

    /**
     * 从响应头列表里取值
     *
     * @param string[] $headers
     * @param string $name 小写头名
     * @return string
     */
    protected function headerValue(array $headers, $name)
    {
        $name = strtolower((string) $name);
        foreach ($headers as $header) {
            $pos = strpos($header, ':');
            if ($pos === false) {
                continue;
            }
            if (strtolower(trim(substr($header, 0, $pos))) === $name) {
                return trim(substr($header, $pos + 1));
            }
        }
        return '';
    }
}
