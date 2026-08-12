<?php
namespace Ai\Realtime;

use Ai\Exceptions\RealtimeException;
use Ai\Helpers\Log;

/**
 * RFC 6455 WebSocket 客户端（纯 PHP，零新增依赖）
 *
 * 只用 PHP 核心函数实现：stream_socket_client / stream_socket_enable_crypto /
 * random_bytes / pack / unpack。**不需要 ext-sockets**；
 * wss:// 需要 ext-openssl（几乎所有环境都有，缺失时会给出明确提示）。
 *
 * 刻意只做「一次会话、发完收完就关」这一种模式，够覆盖讯飞的 TTS / ASR。
 * 不做并发多连接、自动重连、服务端模式、permessage-deflate 压缩扩展——
 * 那些会把这个类的复杂度翻几倍，而本库的场景用不到。
 *
 * ## 这类代码为什么危险
 *
 * WebSocket 的失败方式和 HTTP 完全不同：HTTP 有状态码可依，WS 则是
 * **握手成功之后**才可能因为帧格式错误而静默挂死——服务端收到一个解不开的帧，
 * 既不回数据也不发 close，客户端就一直阻塞在 read 上。
 *
 * 所以这里有三道保险，缺一不可：
 *   1) 握手时校验 Sec-WebSocket-Accept（不校验等于裸奔，任何 101 都当成功）
 *   2) 读超时（stream_set_timeout + 每次读后检查 timed_out）
 *   3) 最大帧数与最大载荷上限（服务端不发结束帧时不至于无限循环）
 *
 * 另外客户端发出的帧**必须加掩码**，这是 RFC 6455 的强制要求，
 * 不加会被服务端直接断开，且断开原因往往不明。
 */
class WebSocketClient
{
    /** RFC 6455 规定的握手魔数 */
    const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    const OP_CONTINUATION = 0x0;
    const OP_TEXT         = 0x1;
    const OP_BINARY       = 0x2;
    const OP_CLOSE        = 0x8;
    const OP_PING         = 0x9;
    const OP_PONG         = 0xA;

    /** @var resource|null */
    protected $socket = null;
    /** @var int 读写超时（秒） */
    protected $timeout;
    /** @var int 连接超时（秒） */
    protected $connectTimeout;
    /** @var int 单次会话最多接收的帧数，防服务端不发结束帧导致死循环 */
    protected $maxFrames;
    /** @var int 单帧载荷上限（字节），防超大帧打爆内存 */
    protected $maxPayload;
    /** @var bool 是否校验 TLS 证书 */
    protected $sslVerify;
    /** @var string 接收缓冲：分片消息在这里拼接 */
    protected $fragmentBuffer = '';
    /** @var int 分片消息的首帧 opcode */
    protected $fragmentOpcode = 0;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->timeout        = isset($options['timeout']) ? (int) $options['timeout'] : 30;
        $this->connectTimeout = isset($options['connect_timeout']) ? (int) $options['connect_timeout'] : 10;
        $this->maxFrames      = isset($options['max_frames']) ? (int) $options['max_frames'] : 10000;
        $this->maxPayload     = isset($options['max_payload']) ? (int) $options['max_payload'] : 16 * 1024 * 1024;
        $this->sslVerify      = isset($options['ssl_verify']) ? (bool) $options['ssl_verify'] : true;
    }

    /**
     * 建立连接并完成 WebSocket 握手
     *
     * @param string                $url     ws:// 或 wss:// 地址（可带查询串）
     * @param array<string, string> $headers 额外请求头，如 ['x-api-key' => '...']
     * @throws RealtimeException
     */
    public function connect(string $url, array $headers = []): self
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            throw new RealtimeException("无法解析 WebSocket 地址：{$url}", '', 'ws_bad_url', []);
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'ws';
        if ($scheme !== 'ws' && $scheme !== 'wss') {
            throw new RealtimeException(
                "WebSocket 地址的协议必须是 ws:// 或 wss://，收到：{$scheme}",
                '',
                'ws_bad_scheme',
                []
            );
        }

        $secure = ($scheme === 'wss');
        if ($secure && !extension_loaded('openssl')) {
            throw new RealtimeException(
                'wss:// 连接需要 PHP 的 openssl 扩展，当前环境未安装。'
                . '请启用 ext-openssl，或改用 ws://（仅限可信内网）。',
                '',
                'ws_openssl_missing',
                []
            );
        }

        $host = $parts['host'];
        $port = isset($parts['port']) ? (int) $parts['port'] : ($secure ? 443 : 80);
        $path = (isset($parts['path']) && $parts['path'] !== '') ? $parts['path'] : '/';
        if (!empty($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        // 先按 tcp 连接，握手完成前不加密；wss 在下一步单独启用 TLS，
        // 这样能把「连不上」和「TLS 握手失败」两类错误分开报
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => $this->sslVerify,
                'verify_peer_name'  => $this->sslVerify,
                'SNI_enabled'       => true,
                'peer_name'         => $host,
            ],
        ]);

        $errno  = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errno,
            $errstr,
            $this->connectTimeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new RealtimeException(
                "连接 {$host}:{$port} 失败：{$errstr}（errno {$errno}）",
                '',
                'ws_connect_failed',
                []
            );
        }

        stream_set_timeout($socket, $this->timeout);

        if ($secure) {
            $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            // 常量在不同 PHP 版本上覆盖的协议范围不同，能用更明确的就用
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            $ok = @stream_socket_enable_crypto($socket, true, $method);
            if ($ok !== true) {
                fclose($socket);
                throw new RealtimeException(
                    "与 {$host}:{$port} 的 TLS 握手失败"
                    . ($this->sslVerify ? '（如为自签证书，可关闭证书校验）' : ''),
                    '',
                    'ws_tls_failed',
                    []
                );
            }
        }

        $this->socket = $socket;

        try {
            $this->handshake($host, $port, $path, $secure, $headers);
        } catch (RealtimeException $e) {
            $this->closeSocket();
            throw $e;
        }

        return $this;
    }

    /**
     * HTTP Upgrade 握手
     *
     * @param array<string, string> $headers
     * @throws RealtimeException
     */
    protected function handshake(string $host, int $port, string $path, bool $secure, array $headers): void
    {
        $key = base64_encode(random_bytes(16));

        $hostHeader = $host;
        if (($secure && $port !== 443) || (!$secure && $port !== 80)) {
            $hostHeader .= ':' . $port;
        }

        $lines = [
            "GET {$path} HTTP/1.1",
            "Host: {$hostHeader}",
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Key: {$key}",
            'Sec-WebSocket-Version: 13',
        ];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $request = implode("\r\n", $lines) . "\r\n\r\n";

        $this->writeAll($request);

        $socket = $this->requireSocket();

        // 读响应头，直到空行
        $response = '';
        while (strpos($response, "\r\n\r\n") === false) {
            $line = fgets($socket, 8192);
            if ($line === false || $line === '') {
                throw new RealtimeException(
                    '握手时连接被对端关闭，未收到完整响应头',
                    '',
                    'ws_handshake_eof',
                    ['received' => $response]
                );
            }
            $response .= $line;
            if (strlen($response) > 65536) {
                throw new RealtimeException('握手响应头过大，疑似对端不是 WebSocket 服务', '', 'ws_handshake_too_large', []);
            }
        }

        if (!preg_match('#^HTTP/1\.[01]\s+101\b#i', $response)) {
            $statusLine = strtok($response, "\r\n");
            throw new RealtimeException(
                '握手失败，服务端未返回 101 Switching Protocols：' . $statusLine
                . '（鉴权参数或地址有误时最常见）',
                '',
                'ws_handshake_rejected',
                ['response' => $response]
            );
        }

        // **必须校验 Sec-WebSocket-Accept**：只看 101 就放行，
        // 等于任何返回 101 的中间设备都能冒充 WebSocket 服务
        $expected = base64_encode(sha1($key . self::GUID, true));
        if (!preg_match('#Sec-WebSocket-Accept:\s*(\S+)#i', $response, $m)) {
            throw new RealtimeException(
                '握手响应缺少 Sec-WebSocket-Accept 头，对端不是合规的 WebSocket 服务',
                '',
                'ws_accept_missing',
                ['response' => $response]
            );
        }
        if (!hash_equals($expected, trim($m[1]))) {
            throw new RealtimeException(
                'Sec-WebSocket-Accept 校验失败，握手结果不可信',
                '',
                'ws_accept_mismatch',
                ['expected' => $expected, 'actual' => trim($m[1])]
            );
        }
    }

    /**
     * 发送一帧文本
     * @throws RealtimeException
     */
    public function sendText(string $payload): void
    {
        $this->sendFrame($payload, self::OP_TEXT);
    }

    /**
     * 发送一帧二进制
     * @throws RealtimeException
     */
    public function sendBinary(string $payload): void
    {
        $this->sendFrame($payload, self::OP_BINARY);
    }

    /**
     * 发送一帧
     * @throws RealtimeException
     */
    public function sendFrame(string $payload, int $opcode = self::OP_TEXT, bool $fin = true): void
    {
        $this->writeAll(self::encodeFrame($payload, $opcode, $fin, random_bytes(4)));
    }

    /**
     * 收一条**完整消息**（自动拼接分片、自动回应 ping）
     *
     * @return array{opcode: int, payload: string}|null 收到 close 帧时返回 null
     * @throws RealtimeException
     */
    public function receive(): ?array
    {
        $this->requireSocket();

        for ($i = 0; $i < $this->maxFrames; $i++) {
            $frame = $this->readFrame();
            if ($frame === null) {
                return null;                       // 对端关闭
            }

            switch ($frame['opcode']) {
                case self::OP_PING:
                    // 不回 pong 会被服务端判定掉线，这是长会话里最隐蔽的断连原因
                    $this->sendFrame($frame['payload'], self::OP_PONG);
                    break;

                case self::OP_PONG:
                    break;                          // 忽略

                case self::OP_CLOSE:
                    $this->closeSocket();
                    return null;

                case self::OP_CONTINUATION:
                    $this->fragmentBuffer .= $frame['payload'];
                    if ($frame['fin']) {
                        $message = ['opcode' => $this->fragmentOpcode, 'payload' => $this->fragmentBuffer];
                        $this->fragmentBuffer = '';
                        $this->fragmentOpcode = 0;
                        return $message;
                    }
                    break;

                case self::OP_TEXT:
                case self::OP_BINARY:
                    if ($frame['fin']) {
                        return ['opcode' => $frame['opcode'], 'payload' => $frame['payload']];
                    }
                    $this->fragmentOpcode = $frame['opcode'];
                    $this->fragmentBuffer = $frame['payload'];
                    break;

                default:
                    Log::warning('收到未知的 WebSocket opcode，已忽略', ['opcode' => $frame['opcode']]);
            }
        }

        throw new RealtimeException(
            "接收帧数超过上限 {$this->maxFrames}，已中止。"
            . '通常意味着服务端一直没有发送结束标志，请检查请求参数是否正确。',
            '',
            'ws_too_many_frames',
            []
        );
    }

    /**
     * 持续接收直到回调判定结束
     *
     * @param callable $isDone function(array $message): bool
     * @return array<int, array{opcode: int, payload: string}>
     * @throws RealtimeException
     */
    public function receiveUntil(callable $isDone): array
    {
        $messages = [];
        while (true) {
            $message = $this->receive();
            if ($message === null) {
                break;                              // 对端关闭
            }
            $messages[] = $message;
            if (call_user_func($isDone, $message)) {
                break;
            }
            if (count($messages) >= $this->maxFrames) {
                throw new RealtimeException(
                    "接收消息数超过上限 {$this->maxFrames}，已中止",
                    '',
                    'ws_too_many_frames',
                    []
                );
            }
        }
        return $messages;
    }

    /**
     * 主动关闭连接
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        if ($this->socket === null) {
            return;
        }
        try {
            $this->sendFrame(pack('n', $code) . $reason, self::OP_CLOSE);
        } catch (\Throwable $e) {
            // 关闭阶段的失败无关紧要，socket 马上就要销毁
        }
        $this->closeSocket();
    }

    public function isConnected(): bool
    {
        return $this->socket !== null;
    }

    // =================================================================
    // 帧编解码：纯函数，不碰 socket，便于离线单测按字节比对
    // =================================================================

    /**
     * 编码一帧
     *
     * @param string      $payload 载荷
     * @param int         $opcode  操作码
     * @param bool        $fin     是否是消息的最后一帧
     * @param string|null $maskKey 4 字节掩码；**客户端发帧必须加掩码**（RFC 6455 强制），
     *                             传 null 仅用于测试比对服务端形态的帧
     */
    public static function encodeFrame(string $payload, int $opcode = self::OP_TEXT, bool $fin = true, ?string $maskKey = null): string
    {
        $byte0 = ($fin ? 0x80 : 0x00) | ($opcode & 0x0F);
        $frame = chr($byte0);

        $len    = strlen($payload);
        $masked = ($maskKey !== null) ? 0x80 : 0x00;

        if ($len <= 125) {
            $frame .= chr($masked | $len);
        } elseif ($len <= 0xFFFF) {
            $frame .= chr($masked | 126) . pack('n', $len);
        } else {
            // 64 位长度：高 32 位补零（PHP 的 J 打包本身就是 64 位大端）
            $frame .= chr($masked | 127) . pack('J', $len);
        }

        if ($maskKey !== null) {
            $frame .= $maskKey;
            $payload = self::applyMask($payload, $maskKey);
        }

        return $frame . $payload;
    }

    /**
     * 从一段完整字节里解出一帧
     *
     * @return array{fin: bool, opcode: int, payload: string, length: int}|null 字节不完整时返回 null
     */
    public static function decodeFrame(string $bytes): ?array
    {
        $total = strlen($bytes);
        if ($total < 2) {
            return null;
        }

        $byte0  = ord($bytes[0]);
        $byte1  = ord($bytes[1]);
        $fin    = (bool) ($byte0 & 0x80);
        $opcode = $byte0 & 0x0F;
        $masked = (bool) ($byte1 & 0x80);
        $len    = $byte1 & 0x7F;
        $offset = 2;

        if ($len === 126) {
            if ($total < $offset + 2) {
                return null;
            }
            $unpacked = unpack('n', substr($bytes, $offset, 2));
            if ($unpacked === false) {
                return null;
            }
            $len = (int) $unpacked[1];
            $offset += 2;
        } elseif ($len === 127) {
            if ($total < $offset + 8) {
                return null;
            }
            $unpacked = unpack('J', substr($bytes, $offset, 8));
            if ($unpacked === false) {
                return null;
            }
            $len = (int) $unpacked[1];
            $offset += 8;
        }

        $maskKey = '';
        if ($masked) {
            if ($total < $offset + 4) {
                return null;
            }
            $maskKey = substr($bytes, $offset, 4);
            $offset += 4;
        }

        if ($total < $offset + $len) {
            return null;
        }
        $payload = substr($bytes, $offset, $len);
        if ($masked) {
            $payload = self::applyMask($payload, $maskKey);
        }

        return [
            'fin'     => $fin,
            'opcode'  => $opcode,
            'payload' => $payload,
            'length'  => $offset + $len,
        ];
    }

    /**
     * 掩码运算。RFC 6455 §5.3：逐字节与掩码循环异或，编解码同一个操作
     */
    public static function applyMask(string $payload, string $maskKey): string
    {
        if ($maskKey === '') {
            return $payload;
        }
        $out = '';
        $len = strlen($payload);
        for ($i = 0; $i < $len; $i++) {
            $out .= $payload[$i] ^ $maskKey[$i % 4];
        }
        return $out;
    }

    // =================================================================
    // socket 读写
    // =================================================================

    /**
     * 从 socket 读一帧
     *
     * @return array{fin: bool, opcode: int, payload: string}|null 连接结束时返回 null
     * @throws RealtimeException
     */
    protected function readFrame(): ?array
    {
        $header = $this->readExactly(2);
        if ($header === null) {
            return null;
        }

        $byte0  = ord($header[0]);
        $byte1  = ord($header[1]);
        $fin    = (bool) ($byte0 & 0x80);
        $opcode = $byte0 & 0x0F;
        $masked = (bool) ($byte1 & 0x80);
        $len    = $byte1 & 0x7F;

        if ($len === 126) {
            $ext = $this->readExactly(2);
            if ($ext === null) {
                return null;
            }
            $unpacked = unpack('n', $ext);
            if ($unpacked === false) {
                throw new RealtimeException('帧长度字段解析失败', '', 'ws_bad_frame', []);
            }
            $len = (int) $unpacked[1];
        } elseif ($len === 127) {
            $ext = $this->readExactly(8);
            if ($ext === null) {
                return null;
            }
            $unpacked = unpack('J', $ext);
            if ($unpacked === false) {
                throw new RealtimeException('帧长度字段解析失败', '', 'ws_bad_frame', []);
            }
            $len = (int) $unpacked[1];
        }

        if ($len > $this->maxPayload) {
            throw new RealtimeException(
                "收到超过上限的帧（{$len} 字节 > {$this->maxPayload}），已中止以免打爆内存",
                '',
                'ws_frame_too_large',
                []
            );
        }

        $maskKey = '';
        if ($masked) {
            // 服务端发给客户端的帧不应加掩码（RFC 6455 §5.1），
            // 但收到了也照常解开，宽进严出
            $maskKey = $this->readExactly(4);
            if ($maskKey === null) {
                return null;
            }
        }

        $payload = '';
        if ($len > 0) {
            $payload = $this->readExactly($len);
            if ($payload === null) {
                return null;
            }
            if ($masked) {
                $payload = self::applyMask($payload, $maskKey);
            }
        }

        return ['fin' => $fin, 'opcode' => $opcode, 'payload' => $payload];
    }

    /**
     * 读满指定字节数
     *
     * fread() 可能少读，必须循环补齐；同时每轮检查读超时——
     * 少了这个检查，服务端不发结束帧时会永远阻塞在这里
     *
     * @return string|null 连接结束返回 null
     * @throws RealtimeException 读超时
     */
    protected function readExactly(int $length): ?string
    {
        if ($length <= 0) {
            return '';
        }
        $socket = $this->requireSocket();
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = @fread($socket, max(1, $length - strlen($buffer)));

            $meta = stream_get_meta_data($socket);
            if (!empty($meta['timed_out'])) {
                throw new RealtimeException(
                    "WebSocket 读超时（{$this->timeout} 秒内没有收到数据）。"
                    . '握手成功但帧格式不被服务端接受时就是这个表现——它既不回数据也不发 close。',
                    '',
                    'ws_read_timeout',
                    []
                );
            }

            if ($chunk === false || $chunk === '') {
                if (feof($socket)) {
                    return null;
                }
                continue;
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }

    /**
     * 写满整个缓冲
     * @throws RealtimeException
     */
    protected function writeAll(string $data): void
    {
        $socket = $this->requireSocket();
        $total  = strlen($data);
        $sent   = 0;
        while ($sent < $total) {
            $n = @fwrite($socket, substr($data, $sent));
            if ($n === false || $n === 0) {
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out'])) {
                    throw new RealtimeException('WebSocket 写超时', '', 'ws_write_timeout', []);
                }
                throw new RealtimeException('WebSocket 写入失败，连接可能已断开', '', 'ws_write_failed', []);
            }
            $sent += $n;
        }
    }

    /**
     * 取出已连接的 socket
     *
     * 单独一个方法而不是直接用 $this->socket，是为了把「可能为 null」
     * 收敛在一处：调用方拿到的一定是可用的资源，静态分析也能据此收窄类型
     *
     * @return resource
     * @throws RealtimeException
     */
    protected function requireSocket()
    {
        if ($this->socket === null) {
            throw new RealtimeException('WebSocket 尚未连接，请先调用 connect()', '', 'ws_not_connected', []);
        }
        return $this->socket;
    }

    protected function closeSocket(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    public function __destruct()
    {
        $this->closeSocket();
    }
}
