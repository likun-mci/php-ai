<?php
namespace Ai\Agent\Mcp;

use Ai\Realtime\WebSocketClient;

/**
 * McpWebSocketTransport——WebSocket 传输
 *
 * 长连接双向通信。适合需要服务端主动推送、或一次会话里要连续调很多次工具的场景——
 * 省掉 HTTP 每次请求的握手开销，连接状态也天然持续。
 *
 * 复用本库的 `Ai\Realtime\WebSocketClient`，`wss://` 需要 openssl 扩展。
 *
 * ```php
 * $transport = new McpWebSocketTransport('wss://mcp.example.com/ws', [
 *     'headers' => ['Authorization' => 'Bearer xxx'],
 *     'timeout' => 30,
 * ]);
 * $client = McpClient::fromConfig(['transport' => $transport]);
 * ```
 */
class McpWebSocketTransport implements McpTransportInterface
{
    /** @var string ws:// 或 wss:// 地址 */
    protected $url = '';

    /** @var array<string, string> 握手请求头 */
    protected $headers = [];

    /** @var WebSocketClient|null */
    protected $client = null;

    /** @var array<string, mixed> WebSocketClient 构造选项 */
    protected $clientOptions = [];

    /**
     * @param string $url
     * @param array<string, mixed> $options headers / timeout / connect_timeout / ssl_verify
     */
    public function __construct($url, array $options = [])
    {
        $this->url = (string) $url;
        if (isset($options['headers']) && is_array($options['headers'])) {
            $this->headers = $options['headers'];
        }
        foreach (['timeout', 'connect_timeout', 'max_frames', 'max_payload', 'ssl_verify'] as $key) {
            if (isset($options[$key])) {
                $this->clientOptions[$key] = $options[$key];
            }
        }
    }

    /**
     * @return string
     */
    public function name()
    {
        return 'websocket';
    }

    /**
     * @return void
     */
    public function open()
    {
        if ($this->client !== null && $this->client->isConnected()) {
            return;
        }
        if ($this->url === '') {
            throw new \RuntimeException('MCP WebSocket 传输缺少地址');
        }

        try {
            $client = new WebSocketClient($this->clientOptions);
            $client->connect($this->url, $this->headers);
            $this->client = $client;
        } catch (\Throwable $e) {
            $this->client = null;
            throw new \RuntimeException('MCP WebSocket 连接失败：' . $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function close()
    {
        if ($this->client !== null) {
            try {
                $this->client->close();
            } catch (\Throwable $e) {
                // 关闭阶段的异常无关紧要
            }
            $this->client = null;
        }
    }

    /**
     * @return bool
     */
    public function isOpen()
    {
        return $this->client !== null && $this->client->isConnected();
    }

    /**
     * @param array<string, mixed> $payload
     * @param int $timeout
     * @return array<string, mixed>|null
     */
    public function request(array $payload, $timeout)
    {
        $this->open();
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('MCP 请求序列化失败');
        }

        $client = $this->client;
        if ($client === null) {
            throw new \RuntimeException('MCP WebSocket 未连接');
        }
        $expectedId = isset($payload['id']) ? $payload['id'] : null;

        try {
            $client->sendText($json);
            $matched = null;
            // 服务端可能穿插发通知，收到 id 对得上的那条才算数
            $client->receiveUntil(function (array $message) use ($expectedId, &$matched) {
                $decoded = json_decode(isset($message['payload']) ? $message['payload'] : '', true);
                if (!is_array($decoded)) {
                    return false;
                }
                if ($expectedId === null) {
                    $matched = $decoded;
                    return true;
                }
                if (isset($decoded['id']) && (string) $decoded['id'] === (string) $expectedId) {
                    $matched = $decoded;
                    return true;
                }
                return false;
            });
            return $matched;
        } catch (\Throwable $e) {
            throw new \RuntimeException('MCP WebSocket 请求失败：' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return void
     */
    public function notify(array $payload)
    {
        $client = $this->client;
        if ($client === null || !$client->isConnected()) {
            return;
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        try {
            $client->sendText($json);
        } catch (\Throwable $e) {
            // 通知不关心结果
        }
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }
}
