<?php
/**
 * 测试用的最小 WebSocket 服务端（RFC 6455）
 *
 * 只为验证 WebSocketClient 的握手与帧编解码能真的跑通，不追求完整性。
 * 与被测代码**完全独立实现**——如果两边共用一份编解码，
 * 那测的就只是「自己和自己一致」，掩码写反之类的错误照样测不出来。
 *
 * 用法：php ws_server.php <port> <脚本文件>
 * 脚本文件是 JSON：{"responses": ["<帧原文>", ...], "close_after": true}
 * 服务端在收到 data.status==2 的帧后，把 responses 逐帧发出再关闭。
 */

$port   = isset($argv[1]) ? (int) $argv[1] : 0;
$script = isset($argv[2]) ? json_decode((string) file_get_contents($argv[2]), true) : [];
$responses = isset($script['responses']) ? $script['responses'] : [];

$server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}
// 把实际端口告诉父进程
$name = stream_socket_get_name($server, false);
fwrite(STDOUT, substr($name, strrpos($name, ':') + 1) . "\n");
fflush(STDOUT);

$conn = @stream_socket_accept($server, 10);
if (!$conn) {
    exit(2);
}
stream_set_timeout($conn, 10);

// ---- 握手 ----
$request = '';
while (strpos($request, "\r\n\r\n") === false) {
    $line = fgets($conn, 8192);
    if ($line === false) { exit(3); }
    $request .= $line;
}
if (!preg_match('#Sec-WebSocket-Key:\s*(\S+)#i', $request, $m)) {
    fwrite($conn, "HTTP/1.1 400 Bad Request\r\n\r\n");
    exit(4);
}
$accept = base64_encode(sha1(trim($m[1]) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
fwrite($conn, "HTTP/1.1 101 Switching Protocols\r\n"
    . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
    . "Sec-WebSocket-Accept: {$accept}\r\n\r\n");

// ---- 收发 ----
function readExact($conn, $n) {
    $buf = '';
    while (strlen($buf) < $n) {
        $c = fread($conn, $n - strlen($buf));
        if ($c === false || $c === '') {
            if (feof($conn)) { return null; }
            continue;
        }
        $buf .= $c;
    }
    return $buf;
}

function readFrame($conn) {
    $h = readExact($conn, 2);
    if ($h === null) { return null; }
    $b0 = ord($h[0]); $b1 = ord($h[1]);
    $opcode = $b0 & 0x0F;
    $masked = ($b1 & 0x80) !== 0;
    $len = $b1 & 0x7F;
    if ($len === 126) { $e = readExact($conn, 2); $len = unpack('n', $e)[1]; }
    elseif ($len === 127) { $e = readExact($conn, 8); $len = (int) unpack('J', $e)[1]; }
    $mask = $masked ? readExact($conn, 4) : '';
    $payload = $len > 0 ? readExact($conn, $len) : '';
    if ($payload === null) { return null; }
    if ($masked) {
        $out = '';
        for ($i = 0; $i < strlen($payload); $i++) { $out .= $payload[$i] ^ $mask[$i % 4]; }
        $payload = $out;
    }
    return ['opcode' => $opcode, 'payload' => $payload, 'masked' => $masked];
}

/** 服务端发帧不加掩码（RFC 6455 §5.1） */
function writeFrame($conn, $payload, $opcode = 0x1) {
    $len = strlen($payload);
    $frame = chr(0x80 | $opcode);
    if ($len <= 125) { $frame .= chr($len); }
    elseif ($len <= 0xFFFF) { $frame .= chr(126) . pack('n', $len); }
    else { $frame .= chr(127) . pack('J', $len); }
    fwrite($conn, $frame . $payload);
}

$received = [];
while (true) {
    $frame = readFrame($conn);
    if ($frame === null) { break; }

    if ($frame['opcode'] === 0x8) { break; }              // close
    if ($frame['opcode'] === 0x9) {                        // ping → pong
        writeFrame($conn, $frame['payload'], 0xA);
        continue;
    }

    // 客户端发来的帧**必须**是带掩码的，这是 RFC 强制要求
    if (!$frame['masked']) {
        writeFrame($conn, json_encode(['error' => 'client frame not masked']));
        break;
    }

    $received[] = $frame['payload'];

    $decoded = json_decode($frame['payload'], true);
    $isFinal = is_array($decoded) && isset($decoded['data']['status'])
               && (int) $decoded['data']['status'] === 2;

    if ($isFinal) {
        foreach ($responses as $resp) {
            writeFrame($conn, $resp);
        }
        writeFrame($conn, pack('n', 1000), 0x8);          // close
        break;
    }
}

// 把收到的帧写到脚本文件旁边，供测试断言
if (isset($argv[2])) {
    file_put_contents($argv[2] . '.received', json_encode($received, JSON_UNESCAPED_UNICODE));
}
fclose($conn);
fclose($server);
