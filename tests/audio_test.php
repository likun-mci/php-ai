<?php
/**
 * 语音合成与识别测试（HTTP 通道）
 *
 * 全离线。重点盯这一期特有的两类静默故障：
 *
 *   1) **错误 JSON 被当音频存盘** —— 平台出错时回的是
 *      Content-Type: application/json，不是音频。不判别就会写出一堆
 *      扩展名 .mp3、内容是错误信息的文件，全程无报错
 *   2) **MiniMax 的 hex 被当 base64 解** —— 两者都是可打印字符，
 *      用错解码函数不报错，只表现为「文件有了但放不出声」
 *
 * 还覆盖 multipart 的 boundary 陷阱：手写的 Content-Type 没有 boundary，
 * 必须摘掉交给 curl 生成，否则服务端拿到无法解析的 body 且往往仍回 200。
 *
 * 运行：php tests/audio_test.php
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/fixtures/FakeTransport.php';

use Ai\AI;
use Ai\Helpers\AIFile;
use Ai\Helpers\Capabilities;
use Ai\Response\AudioResponse;
use Ai\Transport\CurlTransport;
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
    echo pad($name, 58), $ok ? "✓\n" : "✗ {$detail}\n";
}

function makeAI(string $protocol, string $model = 'tts-1', array $extra = []): array
{
    $fake = new FakeTransport();
    $ai = new AI(array_merge(['api_key' => 'sk-test', 'model' => $model, 'protocol' => $protocol], $extra));
    $ai->setTransport($fake);
    return [$ai, $fake];
}

/** 假装是一段 mp3 字节 */
function fakeMp3(): string
{
    return "ID3\x03\x00\x00\x00" . str_repeat("\xFF\xFB\x90\x00", 32);
}

/** 传输层对二进制响应的包装形态 */
function binaryResponse(string $bytes, string $type = 'audio/mpeg'): array
{
    return ['_raw' => $bytes, '_content_type' => $type, '_status' => 200];
}

// =====================================================================
echo "=== 一、能力声明与路径推导 ===\n\n";

$expect = [
    'openai'      => ['/v1/audio/speech', '/v1/audio/transcriptions'],
    'siliconflow' => ['/v1/audio/speech', '/v1/audio/transcriptions'],
    'stepfun'     => ['/v1/audio/speech', '/v1/audio/transcriptions'],
    'zhipu'       => ['/v4/audio/speech', '/v4/audio/transcriptions'],
    'doubao'      => ['/api/v3/audio/speech', '/api/v3/audio/transcriptions'],
];
foreach ($expect as $key => [$tts, $asr]) {
    $class = \Ai\Helpers\Protocols::resolveClass($key);
    $p = new $class();
    check($p->capabilityPath(Capabilities::TTS) === $tts && $p->capabilityPath(Capabilities::ASR) === $asr,
          sprintf('  %-12s tts=%s', $key, $tts), $p->capabilityPath(Capabilities::TTS));
}

// MiniMax 路径完全不同，且不提供 OpenAI 兼容的 ASR
$mm = new \Ai\Protocol\MiniMax();
check($mm->capabilityPath(Capabilities::TTS) === '/v1/t2a_v2',
      '  minimax      tts=/v1/t2a_v2（非同级推导）', $mm->capabilityPath(Capabilities::TTS));
check(!in_array(Capabilities::ASR, $mm->capabilities(), true), '  minimax 不声明 ASR');

// 通义兼容模式实测 404
$qwen = new \Ai\Protocol\Qwen();
check(!in_array(Capabilities::TTS, $qwen->capabilities(), true),
      '  qwen 不声明语音（兼容模式实测 404）');

// =====================================================================
echo "\n=== 二、TTS 请求构建 ===\n\n";

list($ai, $fake) = makeAI('openai', 'tts-1');
$fake->queuePost(binaryResponse(fakeMp3()));
$ai->audio()->speech('你好世界');
$req = $fake->lastRequest();
check($req['data']['input'] === '你好世界', 'OpenAI：文本进 input');
check($req['data']['voice'] === 'alloy', '**voice 缺省自动补上**（OpenAI 该字段必填，不补就是 400）',
      isset($req['data']['voice']) ? $req['data']['voice'] : '(缺失)');
check(strpos($req['url'], '/v1/audio/speech') !== false, '打到语音合成端点');

$fake->reset();
$fake->queuePost(binaryResponse(fakeMp3()));
$ai->audio()->speech('你好', ['voice' => 'sage', 'format' => 'wav', 'speed' => 1.5]);
$req = $fake->lastRequest();
check($req['data']['voice'] === 'sage', '用户指定 voice 时不被覆盖');
check($req['data']['response_format'] === 'wav', '库内统一的 format → response_format');
check(!isset($req['data']['format']), '原字段名不残留');
check($req['data']['speed'] === 1.5, 'speed 透传');

// MiniMax：结构完全不同
list($ai, $fake) = makeAI('minimax', 'speech-2.8-hd');
$fake->queuePost(['data' => ['audio' => bin2hex(fakeMp3()), 'status' => 2], 'base_resp' => ['status_code' => 0]]);
$ai->audio()->speech('你好世界', ['voice' => 'male-qn-qingse', 'speed' => 1.2, 'format' => 'mp3']);
$req = $fake->lastRequest();
check($req['data']['text'] === '你好世界', 'MiniMax：input → text', json_encode($req['data'], JSON_UNESCAPED_UNICODE));
check(!isset($req['data']['input']), 'MiniMax：input 不残留');
check(isset($req['data']['voice_setting']['voice_id']) && $req['data']['voice_setting']['voice_id'] === 'male-qn-qingse',
      'MiniMax：voice → voice_setting.voice_id');
check(isset($req['data']['voice_setting']['speed']) && $req['data']['voice_setting']['speed'] === 1.2,
      'MiniMax：speed → voice_setting.speed');
check(isset($req['data']['audio_setting']['format']) && $req['data']['audio_setting']['format'] === 'mp3',
      'MiniMax：format → audio_setting.format');
check($req['url'] === 'https://api.minimaxi.com/v1/t2a_v2', 'MiniMax：端点正确', $req['url']);

// 用户已按 MiniMax 结构写好时不覆盖
$fake->reset();
$fake->queuePost(['data' => ['audio' => bin2hex(fakeMp3())], 'base_resp' => ['status_code' => 0]]);
$ai->audio()->speech('x', ['voice_setting' => ['voice_id' => 'custom', 'emotion' => 'happy']]);
$req = $fake->lastRequest();
check($req['data']['voice_setting']['voice_id'] === 'custom', 'MiniMax：用户自写 voice_setting 不被覆盖');
check($req['data']['voice_setting']['emotion'] === 'happy', 'MiniMax：emotion 等私有字段保留');

// 空文本
list($ai) = makeAI('openai');
try {
    $ai->audio()->speech('  ');
    check(false, '空文本报错', '未抛出');
} catch (\Ai\Exceptions\RequestException $e) {
    check(true, '空文本报错');
}

// =====================================================================
echo "\n=== 三、TTS 响应解析 ===\n\n";

list($ai, $fake) = makeAI('openai');
$fake->queuePost(binaryResponse(fakeMp3(), 'audio/mpeg'));
$res = $ai->audio()->speech('你好');
check($res instanceof AudioResponse, '返回 AudioResponse');
check($res->isSuccess(), 'isSuccess()');
check($res->getBytes() === fakeMp3(), '音频字节完整还原');
check($res->getSize() === strlen(fakeMp3()), 'getSize()');
check($res->getFormat() === 'mp3', 'audio/mpeg → 格式 mp3', $res->getFormat());
check($res->getCapability() === Capabilities::TTS, '能力标识为 tts');

// wav
$fake->reset();
$fake->queuePost(binaryResponse('RIFFxxxx', 'audio/wav'));
check($ai->audio()->speech('x')->getFormat() === 'wav', 'audio/wav → 格式 wav');

// —— 关键：平台报错时回的是 JSON，绝不能当音频 ——
$fake->reset();
$fake->queuePost(['error' => ['message' => '余额不足']]);
$res = $ai->audio()->speech('你好');
check(!$res->isSuccess(), '**错误 JSON 不算成功**');
check($res->getError() === '余额不足', '错误信息透传', $res->getError());
check($res->getBytes() === '', '**错误响应不产生任何音频字节**');
try {
    $res->saveTo(sys_get_temp_dir() . '/should_not_exist.mp3');
    check(false, '**错误响应 saveTo 直接报错**（而不是写出坏文件）', '未抛出');
} catch (\Ai\Exceptions\RequestException $e) {
    check(true, '**错误响应 saveTo 直接报错**（而不是写出坏文件）');
}
check(!is_file(sys_get_temp_dir() . '/should_not_exist.mp3'), '磁盘上没留下坏文件');

// MiniMax：hex 解码
list($ai, $fake) = makeAI('minimax', 'speech-2.8-hd');
$fake->queuePost([
    'data'       => ['audio' => bin2hex(fakeMp3()), 'status' => 2],
    'extra_info' => ['audio_length' => 1200, 'audio_format' => 'mp3', 'audio_size' => strlen(fakeMp3())],
    'base_resp'  => ['status_code' => 0, 'status_msg' => 'success'],
]);
$res = $ai->audio()->speech('你好');
check($res->isSuccess(), 'MiniMax：解析成功');
check($res->getBytes() === fakeMp3(), '**MiniMax hex 正确还原**（用 base64 解会得到垃圾且不报错）');
check($res->getFormat() === 'mp3', 'MiniMax：格式取自 extra_info');
check($res->getUsage()['audio_length'] === 1200, 'MiniMax：extra_info 进 usage');

// MiniMax：HTTP 200 但 base_resp 非 0
$fake->reset();
$fake->queuePost(['base_resp' => ['status_code' => 1004, 'status_msg' => 'API key 无效']]);
$res = $ai->audio()->speech('你好');
check(!$res->isSuccess(), '**MiniMax base_resp 非 0 判为失败**（HTTP 仍是 200）');
check($res->getError() === 'API key 无效', 'MiniMax：错误信息透传', $res->getError());

// MiniMax：非法 hex
$fake->reset();
$fake->queuePost(['data' => ['audio' => 'zzzz-not-hex'], 'base_resp' => ['status_code' => 0]]);
$res = $ai->audio()->speech('你好');
check(!$res->isSuccess(), '非法 hex 判为失败而不是写出坏文件');
check(strpos($res->getError(), 'hex') !== false, '错误信息点明是 hex 问题', $res->getError());

// =====================================================================
echo "\n=== 四、ASR ===\n\n";

$wav = sys_get_temp_dir() . '/ai_asr_test_' . getmypid() . '.wav';
file_put_contents($wav, "RIFF\x24\x00\x00\x00WAVEfmt ");

list($ai, $fake) = makeAI('openai', 'whisper-1');
$fake->queuePost(['text' => '这是识别出来的文本']);
$res = $ai->audio()->transcribe($wav, ['language' => 'zh']);

check($res->getText() === '这是识别出来的文本', 'ASR：文本解析', $res->getText());
check($res->isSuccess(), 'ASR：isSuccess()');
check($res->getCapability() === Capabilities::ASR, '能力标识为 asr');

$req = $fake->lastRequest();
check(isset($req['headers']['Content-Type']) && $req['headers']['Content-Type'] === 'multipart/form-data',
      'ASR：声明 multipart 意图（传输层负责摘掉并交给 curl）');
check($req['data']['file'] instanceof AIFile, 'ASR：file 字段是 AIFile');
check($req['data']['language'] === 'zh', 'ASR：language 透传');
check(strpos($req['url'], '/v1/audio/transcriptions') !== false, 'ASR：打到识别端点');

// AIFile 实例也能传
$fake->reset();
$fake->queuePost(['text' => 'ok']);
check($ai->audio()->transcribe(AIFile::fromPath($wav))->getText() === 'ok', 'ASR：接受 AIFile 实例');

// 远端 URL 必须先落地
$fake->reset();
try {
    $ai->audio()->transcribe(AIFile::fromUrl('https://example.com/a.wav'));
    check(false, 'ASR：远端 URL 明确报错（不偷偷下载）', '未抛出');
} catch (\Ai\Exceptions\RequestException $e) {
    check(strpos($e->getMessage(), 'Media::download') !== false,
          'ASR：远端 URL 明确报错并给出正确做法', $e->getMessage());
}

// 文件不存在
try {
    $ai->audio()->transcribe('/no/such/file.wav');
    check(false, 'ASR：文件不存在时报错', '未抛出');
} catch (\InvalidArgumentException $e) {
    check(true, 'ASR：文件不存在时报错');
}

// 没解析到文本要说清楚
$fake->reset();
$fake->queuePost(['some_other_field' => 'x']);
$res = $ai->audio()->transcribe($wav);
check(!$res->isSuccess(), '没解析到文本时不算成功');
check(strpos($res->getError(), '没有解析到识别文本') !== false, '给出可排查的说明', $res->getError());

// =====================================================================
echo "\n=== 五、传输层：Content-Type 分流 ===\n\n";

$t = new CurlTransport();
$m = new ReflectionMethod($t, 'encodeRequestBody');
$m->setAccessible(true);

// 默认路径必须与改造前逐字节一致
$h = ['Authorization' => 'Bearer k'];
$body = $m->invokeArgs($t, [['model' => 'gpt-4o', 'messages' => []], &$h]);
check(is_string($body) && $body === '{"model":"gpt-4o","messages":[]}',
      '无 Content-Type 时走 JSON 原路径（对话链路逐字节不变）', is_string($body) ? $body : gettype($body));
check($h === ['Authorization' => 'Bearer k'], '默认路径不动 headers');

$h = ['Content-Type' => 'application/json'];
$body = $m->invokeArgs($t, [['a' => 1], &$h]);
check(is_string($body) && $body === '{"a":1}', '显式声明 JSON 时同样走原路径');

// multipart：Content-Type 必须被摘掉
$h = ['Authorization' => 'Bearer k', 'Content-Type' => 'multipart/form-data'];
$body = $m->invokeArgs($t, [['model' => 'whisper-1', 'file' => AIFile::fromPath($wav), 'n' => 2], &$h]);
check(is_array($body), 'multipart 时请求体是数组（交给 curl 触发 multipart）');
check($body['file'] instanceof \CURLFile, 'AIFile 被转成 CURLFile');
check($body['n'] === '2', '标量值转成字符串');
check(!isset($h['Content-Type']),
      '**手写的 Content-Type 被摘除**（没有 boundary 会让服务端拿到无法解析的 body 且仍回 200）');
check($h === ['Authorization' => 'Bearer k'], '其它头部保留');

// 大小写不敏感
$h = ['content-type' => 'multipart/form-data'];
$body = $m->invokeArgs($t, [['file' => AIFile::fromPath($wav)], &$h]);
check(!isset($h['content-type']) && !isset($h['Content-Type']), 'Content-Type 匹配大小写不敏感');

// 二进制响应识别
check(\Ai\Helpers\Media::isBinaryContentType('audio/mpeg'), 'audio/* 判为二进制');
check(\Ai\Helpers\Media::isBinaryContentType('image/png; charset=x'), '带参数的 MIME 也能判');
check(\Ai\Helpers\Media::isBinaryContentType('application/octet-stream'), 'octet-stream 判为二进制');
check(!\Ai\Helpers\Media::isBinaryContentType('application/json'), 'JSON 不判为二进制');
check(!\Ai\Helpers\Media::isBinaryContentType('text/html'),
      '**text/html 不判为二进制**（异常响应仍走原 json_decode 路径，对话行为不变）');
check(!\Ai\Helpers\Media::isBinaryContentType(''), '空 Content-Type 不判为二进制');

// =====================================================================
echo "\n=== 六、落盘 ===\n\n";

$dir = sys_get_temp_dir() . '/ai_audio_test_' . getmypid();
@mkdir($dir, 0777, true);

list($ai, $fake) = makeAI('openai');
$fake->queuePost(binaryResponse(fakeMp3()));
$path = $ai->audio()->speech('你好')->saveTo($dir . '/hello.mp3');
check(is_file($path), '音频写到磁盘');
check(file_get_contents($path) === fakeMp3(), '内容与原字节一致');
check(substr(file_get_contents($path), 0, 3) === 'ID3', '写出的是真正的 mp3 头');

$fake->reset();
$fake->queuePost(binaryResponse(fakeMp3()));
try {
    $ai->audio()->speech('你好')->saveTo($dir . '/no/such/dir/a.mp3');
    check(false, '目录不存在时报错', '未抛出');
} catch (\Ai\Exceptions\RequestException $e) {
    check(strpos($e->getMessage(), '目录不存在') !== false, '目录不存在时报错', $e->getMessage());
}

// ASR 响应没有音频，saveTo 要给出对的提示
$fake->reset();
$fake->queuePost(['text' => 'hi']);
try {
    $ai->audio()->transcribe($wav)->saveTo($dir . '/x.mp3');
    check(false, 'ASR 响应 saveTo 报错并提示用 getText()', '未抛出');
} catch (\Ai\Exceptions\RequestException $e) {
    check(strpos($e->getMessage(), 'getText()') !== false,
          'ASR 响应 saveTo 报错并提示用 getText()', $e->getMessage());
}

array_map('unlink', glob($dir . '/*'));
@rmdir($dir);
@unlink($wav);

// =====================================================================
echo "\n=== 七、模型清单（据官方文档）===\n\n";

$cases = [
    ['openai', 'knownTtsModels', 'tts-1'],
    ['openai', 'knownAsrModels', 'whisper-1'],
    ['openai', 'knownVoices', 'alloy'],
    ['siliconflow', 'knownTtsModels', 'FunAudioLLM/CosyVoice2-0.5B'],
    ['siliconflow', 'knownVoices', 'alex'],
    ['stepfun', 'knownTtsModels', 'step-tts-mini'],
    ['minimax', 'knownTtsModels', 'speech-2.8-hd'],
    ['zhipu', 'knownTtsModels', 'glm-tts'],
];
foreach ($cases as [$key, $method, $expect]) {
    $class = \Ai\Helpers\Protocols::resolveClass($key);
    $p = new $class();
    check(in_array($expect, $p->{$method}(), true), sprintf('  %-12s %s 含 %s', $key, $method, $expect),
          implode(',', $p->{$method}()));
}
// OpenAI 早年的音色已不在枚举里，不该继续报出来
check(!in_array('nova', (new \Ai\Protocol\OpenAI())->knownVoices(), true),
      '  OpenAI 音色清单已更新（nova/fable/onyx 已不在官方枚举）');
check((new \Ai\Protocol\DeepSeek())->knownVoices() === [],
      '  未登记的平台返回空清单（不继承 OpenAI 的音色）');

// =====================================================================
echo "\n=== 八、跨平台一致性 ===\n\n";

$shapes = [];
foreach (['openai', 'siliconflow', 'stepfun', 'zhipu'] as $key) {
    list($ai, $fake) = makeAI($key, 'tts-model');
    $fake->queuePost(binaryResponse(fakeMp3()));
    $res = $ai->audio()->speech('统一的调用代码', ['voice' => 'v1', 'format' => 'mp3']);
    $shapes[$key] = [$res->getBytes(), $res->getFormat(), $res->isSuccess()];
}
// MiniMax 走完全不同的编码，结果也必须一致
list($ai, $fake) = makeAI('minimax', 'speech-2.8-hd');
$fake->queuePost([
    'data' => ['audio' => bin2hex(fakeMp3())],
    'extra_info' => ['audio_format' => 'mp3'],
    'base_resp' => ['status_code' => 0],
]);
$res = $ai->audio()->speech('统一的调用代码', ['voice' => 'v1', 'format' => 'mp3']);
$shapes['minimax'] = [$res->getBytes(), $res->getFormat(), $res->isSuccess()];

$first = reset($shapes);
$diff = [];
foreach ($shapes as $k => $v) {
    if ($v !== $first) {
        $diff[] = $k;
    }
}
check($diff === [], '5 个平台（含形态完全不同的 MiniMax）产出一致结果', implode(',', $diff));

// 不支持的协议明确报错
list($ai) = makeAI('qwen', 'qwen-plus');
try {
    $ai->audio()->speech('你好');
    check(false, 'qwen 不支持时抛异常', '未抛出');
} catch (\Ai\Exceptions\UnsupportedCapabilityException $e) {
    check(strpos($e->getMessage(), '语音合成') !== false, 'qwen 不支持时抛异常并点名能力', $e->getMessage());
}
list($ai) = makeAI('minimax', 'speech-2.8-hd');
try {
    $ai->audio()->transcribe(__FILE__);
    check(false, 'MiniMax 不支持 ASR 时抛异常', '未抛出');
} catch (\Ai\Exceptions\UnsupportedCapabilityException $e) {
    check(strpos($e->getMessage(), '语音识别') !== false, 'MiniMax 不支持 ASR 时抛异常', $e->getMessage());
}

echo "\n" . str_repeat('=', 66) . "\n";
if ($failures) {
    echo count($failures) . " 项未通过：\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "全部通过：二进制与 JSON-hex 两种形态已归一，错误 JSON 不会被当音频存盘\n";
exit(0);
