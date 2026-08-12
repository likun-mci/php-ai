<?php
/**
 * WebSocket 通道测试（RFC 6455 + 讯飞语音）
 *
 * 本期是整个多模态计划里**唯一从零实现协议**的部分，出错方式和 HTTP 完全不同：
 * HTTP 有状态码可依，WS 则是**握手成功之后**才因帧格式错误而静默挂死——
 * 服务端既不回数据也不发 close，客户端永远阻塞在 read 上。
 *
 * 所以这里分两层测：
 *   1) 帧编解码按 **RFC 6455 §5.7 的官方测试向量**逐字节比对
 *   2) 起一个独立实现的最小 WS 服务端，端到端跑通握手 / 掩码 / 分片 / ping
 *      —— 服务端是另写的，不与被测代码共用编解码，否则只能测出「自己和自己一致」
 *
 * 全离线，不连任何外部服务。运行：php tests/websocket_test.php
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/fixtures/FakeTransport.php';

use Ai\AI;
use Ai\Facade\RealtimeFacade;
use Ai\Helpers\Capabilities;
use Ai\Realtime\WebSocketClient;
use Tests\Fixtures\FakeTransport;

function pad(string $t, int $w): string
{
    $n = $w - mb_strwidth($t, 'UTF-8');
    return $t . ($n > 0 ? str_repeat(' ', $n) : '');
}

$failures = [];
function check(bool $ok, string $name, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? "（{$detail}）" : '');
    }
    echo pad($name, 56), $ok ? "✓\n" : "✗ {$detail}\n";
}

function hexdump(string $s): string
{
    return implode(' ', array_map(function ($c) {
        return sprintf('%02x', ord($c));
    }, str_split($s)));
}

// =====================================================================
echo "=== 一、帧编码：RFC 6455 §5.7 官方测试向量 ===\n\n";

// 单帧、不加掩码的文本消息 "Hello"
$expect = "\x81\x05\x48\x65\x6c\x6c\x6f";
$got = WebSocketClient::encodeFrame('Hello', WebSocketClient::OP_TEXT, true, null);
check($got === $expect, '不加掩码的文本帧 "Hello"', hexdump($got));

// 单帧、加掩码的文本消息 "Hello"，掩码 0x37fa213d
$expect = "\x81\x85\x37\xfa\x21\x3d\x7f\x9f\x4d\x51\x58";
$got = WebSocketClient::encodeFrame('Hello', WebSocketClient::OP_TEXT, true, "\x37\xfa\x21\x3d");
check($got === $expect, '加掩码的文本帧 "Hello"（RFC 向量）', hexdump($got));

// 分片消息："Hel" + "lo"
$f1 = WebSocketClient::encodeFrame('Hel', WebSocketClient::OP_TEXT, false, null);
$f2 = WebSocketClient::encodeFrame('lo', WebSocketClient::OP_CONTINUATION, true, null);
check($f1 === "\x01\x03\x48\x65\x6c", '分片首帧（FIN=0, opcode=text）', hexdump($f1));
check($f2 === "\x80\x02\x6c\x6f", '分片末帧（FIN=1, opcode=continuation）', hexdump($f2));

// Ping / Pong "Hello"
check(WebSocketClient::encodeFrame('Hello', WebSocketClient::OP_PING, true, null) === "\x89\x05Hello",
      'Ping 帧');
check(WebSocketClient::encodeFrame('Hello', WebSocketClient::OP_PONG, true, null) === "\x8a\x05Hello",
      'Pong 帧');

// 256 字节二进制：长度用 2 字节扩展
$payload = str_repeat("\x01", 256);
$got = WebSocketClient::encodeFrame($payload, WebSocketClient::OP_BINARY, true, null);
check(substr($got, 0, 4) === "\x82\x7e\x01\x00", '256 字节 → 126 + 16 位长度', hexdump(substr($got, 0, 4)));
check(strlen($got) === 4 + 256, '256 字节帧总长正确');

// 65536 字节二进制：长度用 8 字节扩展
$payload = str_repeat("\x01", 65536);
$got = WebSocketClient::encodeFrame($payload, WebSocketClient::OP_BINARY, true, null);
check(substr($got, 0, 10) === "\x82\x7f\x00\x00\x00\x00\x00\x01\x00\x00",
      '65536 字节 → 127 + 64 位长度', hexdump(substr($got, 0, 10)));
check(strlen($got) === 10 + 65536, '65536 字节帧总长正确');

// 边界：125 / 126 字节
check(strlen(WebSocketClient::encodeFrame(str_repeat('a', 125), 1, true, null)) === 2 + 125,
      '125 字节仍用 7 位长度（边界）');
check(strlen(WebSocketClient::encodeFrame(str_repeat('a', 126), 1, true, null)) === 4 + 126,
      '126 字节起用 16 位长度（边界）');

// 空载荷
check(WebSocketClient::encodeFrame('', 1, true, null) === "\x81\x00", '空载荷帧');

// =====================================================================
echo "\n=== 二、帧解码 ===\n\n";

$frame = WebSocketClient::decodeFrame("\x81\x05\x48\x65\x6c\x6c\x6f");
check($frame !== null && $frame['payload'] === 'Hello' && $frame['opcode'] === 1 && $frame['fin'],
      '解出不加掩码的 "Hello"');
check($frame['length'] === 7, '报告的帧总长正确（用于从流里切下一帧）');

$frame = WebSocketClient::decodeFrame("\x81\x85\x37\xfa\x21\x3d\x7f\x9f\x4d\x51\x58");
check($frame !== null && $frame['payload'] === 'Hello', '解出加掩码的 "Hello"');

// 掩码是对称运算，编码再解码应还原
$round = WebSocketClient::decodeFrame(
    WebSocketClient::encodeFrame('中文内容测试', WebSocketClient::OP_TEXT, true, "\xAB\xCD\xEF\x01")
);
check($round !== null && $round['payload'] === '中文内容测试', '掩码往返：UTF-8 中文完整还原');

$big = str_repeat('x', 70000);
$round = WebSocketClient::decodeFrame(
    WebSocketClient::encodeFrame($big, WebSocketClient::OP_BINARY, true, "\x01\x02\x03\x04")
);
check($round !== null && $round['payload'] === $big, '掩码往返：70000 字节（走 64 位长度分支）');

// 字节不完整时必须返回 null，不能瞎解
check(WebSocketClient::decodeFrame("\x81") === null, '字节不足 2 → null');
check(WebSocketClient::decodeFrame("\x81\x05\x48\x65") === null, '载荷不完整 → null');
check(WebSocketClient::decodeFrame("\x81\x7e\x01") === null, '扩展长度不完整 → null');
check(WebSocketClient::decodeFrame("\x81\x85\x37\xfa") === null, '掩码键不完整 → null');

// 掩码运算本身
check(WebSocketClient::applyMask(WebSocketClient::applyMask('abcdef', 'MASK'), 'MASK') === 'abcdef',
      'applyMask 是对合运算（两次还原）');

// =====================================================================
echo "\n=== 三、握手校验 ===\n\n";

// RFC 6455 §1.3 的示例：key dGhlIHNhbXBsZSBub25jZQ== → accept s3pPLMBiTxaQ9kYGzzhZRbK+xOo=
$key = 'dGhlIHNhbXBsZSBub25jZQ==';
$accept = base64_encode(sha1($key . WebSocketClient::GUID, true));
check($accept === 's3pPLMBiTxaQ9kYGzzhZRbK+xOo=', 'Sec-WebSocket-Accept 计算符合 RFC 示例', $accept);
check(WebSocketClient::GUID === '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', 'GUID 常量正确');

// 地址校验
$client = new WebSocketClient();
foreach ([['http://x.com', 'ws_bad_scheme'], ['not a url', 'ws_bad_url']] as [$url, $expectCode]) {
    try {
        $client->connect($url);
        check(false, "拒绝非法地址 {$url}", '未抛出');
    } catch (\Ai\Exceptions\RealtimeException $e) {
        check($e->getErrorCode() === $expectCode, "拒绝非法地址 {$url}", $e->getErrorCode());
    }
}
try {
    (new WebSocketClient())->sendText('x');
    check(false, '未连接就发送时报错', '未抛出');
} catch (\Ai\Exceptions\RealtimeException $e) {
    check($e->getErrorCode() === 'ws_not_connected', '未连接就发送时报错');
}

// =====================================================================
echo "\n=== 四、端到端：与独立实现的服务端通信 ===\n\n";

$scriptFile = sys_get_temp_dir() . '/ws_script_' . getmypid() . '.json';
$responses = [
    json_encode(['code' => 0, 'message' => 'success', 'sid' => 's1',
                 'data' => ['audio' => base64_encode('AUDIO-PART-1'), 'status' => 1, 'ced' => '2']]),
    json_encode(['code' => 0, 'message' => 'success', 'sid' => 's1',
                 'data' => ['audio' => base64_encode('AUDIO-PART-2'), 'status' => 2, 'ced' => '4']]),
];
file_put_contents($scriptFile, json_encode(['responses' => $responses]));

$serverScript = __DIR__ . '/fixtures/ws_server.php';
$proc = proc_open(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($serverScript) . ' 0 ' . escapeshellarg($scriptFile),
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);

$portOk = false;
$port = 0;
if (is_resource($proc)) {
    $line = fgets($pipes[1]);
    $port = (int) trim((string) $line);
    $portOk = $port > 0;
}
check($portOk, '测试服务端已启动', $portOk ? "端口 {$port}" : '启动失败');

if ($portOk) {
    $client = new WebSocketClient(['timeout' => 10, 'connect_timeout' => 5]);
    $connected = false;
    try {
        $client->connect("ws://127.0.0.1:{$port}/v2/tts?a=1");
        $connected = true;
    } catch (\Ai\Exceptions\RealtimeException $e) {
        check(false, '握手成功', $e->getMessage());
    }
    check($connected, '握手成功（含 Sec-WebSocket-Accept 校验）');

    if ($connected) {
        check($client->isConnected(), 'isConnected() 为 true');

        // 发一帧带 data.status=2 的 JSON，服务端据此回两帧再关闭
        $client->sendText(json_encode(['data' => ['status' => 2, 'text' => base64_encode('你好')]]));

        $messages = $client->receiveUntil(function (array $m) {
            $d = json_decode($m['payload'], true);
            return isset($d['data']['status']) && (int) $d['data']['status'] === 2;
        });

        check(count($messages) === 2, '收到两帧响应', (string) count($messages));

        $audio = '';
        foreach ($messages as $m) {
            $d = json_decode($m['payload'], true);
            $audio .= base64_decode($d['data']['audio']);
        }
        check($audio === 'AUDIO-PART-1AUDIO-PART-2', '多帧音频按序拼接', $audio);

        $client->close();
        check(!$client->isConnected(), 'close() 后连接已释放');
    }

    // 服务端把收到的帧落了盘，验证客户端确实加了掩码（未加掩码服务端会拒绝）
    $recvFile = $scriptFile . '.received';
    if (is_file($recvFile)) {
        $received = json_decode((string) file_get_contents($recvFile), true);
        check(is_array($received) && count($received) === 1, '服务端收到 1 帧', (string) count((array) $received));
        $decoded = json_decode($received[0], true);
        check(isset($decoded['data']['status']) && $decoded['data']['status'] === 2,
              '**客户端发出的帧带掩码且内容正确**（服务端会拒绝未加掩码的帧）');
        @unlink($recvFile);
    } else {
        check(false, '服务端记录了收到的帧', '文件不存在');
    }

    if (is_resource($proc)) {
        foreach ($pipes as $p) {
            if (is_resource($p)) { fclose($p); }
        }
        proc_terminate($proc);
        proc_close($proc);
    }
}
@unlink($scriptFile);

// =====================================================================
echo "\n=== 五、讯飞：鉴权签名 ===\n\n";

$spark = new \Ai\Protocol\Spark();
check(in_array(Capabilities::REALTIME, $spark->capabilities(), true), 'Spark 声明实时通道能力');

$config = ['api_key' => 'MYKEY:MYSECRET', 'app_id' => 'APP123'];
$url = $spark->realtimeUrl(Capabilities::TTS, $config);

check(strpos($url, 'wss://tts-api.xfyun.cn/v2/tts?') === 0, 'TTS 地址正确', substr($url, 0, 40));
parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
check(isset($q['authorization'], $q['date'], $q['host']), '三个查询参数齐备', implode(',', array_keys($q)));
check($q['host'] === 'tts-api.xfyun.cn', 'host 参数正确', $q['host']);

// 手工复算签名，验证拼接规则与文档一致
$auth = base64_decode($q['authorization']);
check(strpos($auth, 'api_key="MYKEY"') !== false, 'authorization 含 api_key', $auth);
check(strpos($auth, 'algorithm="hmac-sha256"') !== false, 'authorization 含算法');
check(strpos($auth, 'headers="host date request-line"') !== false, 'authorization 含 headers 声明');

preg_match('/signature="([^"]+)"/', $auth, $sm);
$signatureOrigin = "host: tts-api.xfyun.cn\ndate: {$q['date']}\nGET /v2/tts HTTP/1.1";
$expectSig = base64_encode(hash_hmac('sha256', $signatureOrigin, 'MYSECRET', true));
check(isset($sm[1]) && $sm[1] === $expectSig, '**签名值与文档规则逐字一致**', isset($sm[1]) ? $sm[1] : '(无)');

// 签名带时间戳，每次都要重算
$url2 = $spark->realtimeUrl(Capabilities::ASR, $config);
check(strpos($url2, 'wss://iat-api.xfyun.cn/v2/iat?') === 0, 'ASR 地址正确', substr($url2, 0, 40));

// 凭据缺失要说清楚
foreach ([['api_key' => 'onlykey'], []] as $bad) {
    try {
        $spark->realtimeUrl(Capabilities::TTS, $bad);
        check(false, '凭据缺失时报错', '未抛出');
    } catch (\Ai\Exceptions\RealtimeException $e) {
        check(strpos($e->getMessage(), 'APISecret') !== false, '凭据缺失时报错并说明格式', $e->getMessage());
    }
}
// 分开配置的写法
$url3 = $spark->realtimeUrl(Capabilities::TTS, ['xfyun_api_key' => 'K', 'api_secret' => 'S', 'app_id' => 'A']);
parse_str((string) parse_url($url3, PHP_URL_QUERY), $q3);
check(strpos(base64_decode($q3['authorization']), 'api_key="K"') !== false,
      'xfyun_api_key / api_secret 分开配也支持', base64_decode($q3['authorization']));

// =====================================================================
echo "\n=== 六、讯飞：请求帧构造 ===\n\n";

$frames = $spark->buildXfyunTtsFrames('你好世界', ['voice' => 'aisjinger', 'speed' => 60], $config);
check(count($frames) === 1, 'TTS 一帧发完');
$f = json_decode($frames[0], true);
check($f['common']['app_id'] === 'APP123', 'TTS：app_id 就位');
check($f['business']['vcn'] === 'aisjinger', 'TTS：voice → business.vcn');
check($f['business']['speed'] === 60, 'TTS：speed 透传');
check($f['business']['aue'] === 'lame', 'TTS：默认 aue=lame（直接产出 mp3）');
check($f['data']['status'] === 2, 'TTS：data.status=2 表示文本发完');
check(base64_decode($f['data']['text']) === '你好世界', '**TTS：文本经 base64 编码**', $f['data']['text']);

// app_id 缺失
try {
    $spark->buildXfyunTtsFrames('x', [], ['api_key' => 'K:S']);
    check(false, 'app_id 缺失时报错', '未抛出');
} catch (\Ai\Exceptions\RealtimeException $e) {
    check(strpos($e->getMessage(), 'app_id') !== false, 'app_id 缺失时报错并说明来源', $e->getMessage());
}

// ASR：分片规则
$audio = str_repeat("\x00\x01", 1600);          // 3200 字节
$frames = $spark->buildXfyunAsrFrames($audio, [], $config);
check(count($frames) === 4, 'ASR：3200 字节 / 每片 1280 → 3 片 + 1 末帧', (string) count($frames));

$first = json_decode($frames[0], true);
check($first['data']['status'] === 0, 'ASR：首帧 status=0');
check(isset($first['common']['app_id']) && isset($first['business']),
      'ASR：首帧带 common 与 business');
check($first['business']['language'] === 'zh_cn', 'ASR：默认中文');
check($first['data']['format'] === 'audio/L16;rate=16000', 'ASR：默认 16k 采样');

$mid = json_decode($frames[1], true);
check($mid['data']['status'] === 1, 'ASR：中间帧 status=1');
check(!isset($mid['common']), 'ASR：中间帧不重复带 common');

$last = json_decode($frames[3], true);
check($last['data']['status'] === 2, '**ASR：末帧 status=2**（不发服务端会一直等）');

// 分片内容拼回去要和原音频一致
$rebuilt = '';
for ($i = 0; $i < 3; $i++) {
    $d = json_decode($frames[$i], true);
    $rebuilt .= base64_decode($d['data']['audio']);
}
check($rebuilt === $audio, '**分片拼回与原音频逐字节一致**', strlen($rebuilt) . ' vs ' . strlen($audio));

// =====================================================================
echo "\n=== 七、讯飞：响应帧解析 ===\n\n";

$ttsFrames = [
    json_encode(['code' => 0, 'data' => ['audio' => base64_encode('PART1'), 'status' => 1]]),
    json_encode(['code' => 0, 'data' => ['audio' => base64_encode('PART2'), 'status' => 2]]),
];
$res = $spark->parseXfyunTtsFrames($ttsFrames);
check($res->isSuccess(), 'TTS 解析成功');
check($res->getBytes() === 'PART1PART2', '多帧音频按序拼接', $res->getBytes());
check($res->getCapability() === Capabilities::TTS, '能力标识为 tts');

$res = $spark->parseXfyunTtsFrames([json_encode(['code' => 10005, 'message' => 'appid 授权失败'])]);
check(!$res->isSuccess(), 'TTS：code 非 0 判为失败');
check(strpos($res->getError(), '10005') !== false && strpos($res->getError(), 'appid') !== false,
      'TTS：错误码与信息都带上', $res->getError());

$res = $spark->parseXfyunTtsFrames([json_encode(['code' => 0, 'data' => ['status' => 2]])]);
check(!$res->isSuccess(), 'TTS：没有音频数据时不算成功');

// ASR：文本散落在 ws[].cw[].w
$asrFrames = [
    json_encode(['code' => 0, 'data' => ['status' => 0, 'result' => ['ws' => [
        ['cw' => [['w' => '今天']]], ['cw' => [['w' => '天气']]],
    ]]]]),
    json_encode(['code' => 0, 'data' => ['status' => 2, 'result' => ['ws' => [
        ['cw' => [['w' => '不错']]],
    ]]]]),
];
$res = $spark->parseXfyunAsrFrames($asrFrames);
check($res->getText() === '今天天气不错', '**ASR：逐层取出 ws[].cw[].w 并顺序拼接**', $res->getText());
check($res->getCapability() === Capabilities::ASR, '能力标识为 asr');

$res = $spark->parseXfyunAsrFrames([json_encode(['code' => 10114, 'message' => '会话超时'])]);
check(!$res->isSuccess() && strpos($res->getError(), '10114') !== false, 'ASR：错误码透传', $res->getError());

// 结束帧判定
check($spark->isXfyunFinalFrame(json_encode(['code' => 0, 'data' => ['status' => 2]])), '结束帧判定：status=2');
check(!$spark->isXfyunFinalFrame(json_encode(['code' => 0, 'data' => ['status' => 1]])), '结束帧判定：status=1 不是');
check($spark->isXfyunFinalFrame(json_encode(['code' => 10005])), '结束帧判定：出错也算结束（别再等）');
check(!$spark->isXfyunFinalFrame('not json'), '结束帧判定：非 JSON 不算结束');

// =====================================================================
echo "\n=== 八、WAV 头剥离 ===\n\n";

// 造一个标准 44 字节头的 WAV
$pcm = str_repeat("\x11\x22", 100);
$wav = 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVE'
     . 'fmt ' . pack('V', 16) . pack('vvVVvv', 1, 1, 16000, 32000, 2, 16)
     . 'data' . pack('V', strlen($pcm)) . $pcm;
check(RealtimeFacade::extractPcm($wav) === $pcm, '**从 WAV 中取出裸 PCM**（整包灌进去会被当采样，只表现为噪音）');

// fmt 段带扩展字段（长度 18）时，写死 44 偏移会错位
$wav2 = 'RIFF' . pack('V', 38 + strlen($pcm)) . 'WAVE'
      . 'fmt ' . pack('V', 18) . pack('vvVVvv', 1, 1, 16000, 32000, 2, 16) . pack('v', 0)
      . 'data' . pack('V', strlen($pcm)) . $pcm;
check(RealtimeFacade::extractPcm($wav2) === $pcm, 'fmt 段长度非 16 时同样正确（不写死 44 偏移）');

// 带 LIST 段的 WAV
$list = 'LIST' . pack('V', 4) . 'INFO';
$wav3 = 'RIFF' . pack('V', 100) . 'WAVE'
      . 'fmt ' . pack('V', 16) . pack('vvVVvv', 1, 1, 16000, 32000, 2, 16)
      . $list . 'data' . pack('V', strlen($pcm)) . $pcm;
check(RealtimeFacade::extractPcm($wav3) === $pcm, '跳过 LIST 等无关段');

check(RealtimeFacade::extractPcm($pcm) === $pcm, '不是 WAV 时原样返回');
check(RealtimeFacade::extractPcm('') === '', '空输入不炸');

// =====================================================================
echo "\n=== 九、门面：默认关闭 ===\n\n";

$fake = new FakeTransport();
$ai = new AI(['api_key' => 'K:S', 'app_id' => 'A', 'model' => 'x1', 'protocol' => 'spark']);
$ai->setTransport($fake);

check($ai->realtime()->getChannel() === null, '**通道默认为 null（不显式启用不建连接）**');

try {
    $ai->realtime()->speech('你好');
    check(false, '未启用通道时报错', '未抛出');
} catch (\Ai\Exceptions\RealtimeException $e) {
    check($e->getErrorCode() === 'realtime_channel_not_set', '未启用通道时报错');
    check(strpos($e->getMessage(), 'useWebSocket') !== false,
          '错误信息直接给出解决办法', $e->getMessage());
}
try {
    $ai->realtime()->transcribe(__FILE__);
    check(false, 'transcribe 同样默认关闭', '未抛出');
} catch (\Ai\Exceptions\RealtimeException $e) {
    check($e->getErrorCode() === 'realtime_channel_not_set', 'transcribe 同样默认关闭');
}

check($ai->realtime()->useWebSocket()->getChannel() === 'websocket', 'useWebSocket() 启用后通道就位');

// 讯飞的 HTTP 语音路径应当关闭，把用户导向 realtime()
check($spark->capabilityPath(Capabilities::TTS) === '', '讯飞不声明 HTTP 语音（只有 WS）');
try {
    $ai->audio()->speech('你好');
    check(false, 'audio()->speech() 在讯飞上明确报错', '未抛出');
} catch (\Ai\Exceptions\UnsupportedCapabilityException $e) {
    check(strpos($e->getMessage(), '实时通道') !== false,
          'audio() 报错时列出「实时通道」，把用户导向 realtime()', $e->getMessage());
}

// 不支持 WS 的协议
$ai2 = new AI(['api_key' => 'k', 'model' => 'gpt-4o', 'protocol' => 'openai']);
$ai2->setTransport(new FakeTransport());
try {
    $ai2->realtime()->useWebSocket()->speech('你好');
    check(false, 'OpenAI 无 WS 通道时报错', '未抛出');
} catch (\Ai\Exceptions\UnsupportedCapabilityException $e) {
    check(true, 'OpenAI 无 WS 通道时报错');
} catch (\Ai\Exceptions\RealtimeException $e) {
    check(true, 'OpenAI 无 WS 通道时报错');
}

echo "\n" . str_repeat('=', 62) . "\n";
if ($failures) {
    echo count($failures) . " 项未通过：\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "全部通过：RFC 6455 帧编解码符合官方向量，端到端握手与讯飞签名均正确\n";
exit(0);
