<?php
/**
 * 平台能力与模型清单审计测试
 *
 * 多模态七期交付后做的一轮文档核实，产出就是这个文件：把「查过官方文档的结论」
 * 固化成断言，防止以后靠印象改回去。
 *
 * 这里断言的不是「代码能跑」，而是**结论是否还成立**：
 *   - 声明了能力的平台，端点路径是否指向文档里那个地址
 *   - 模型清单是否非空、是否包含文档里的关键模型
 *   - 已下线的模型是否已从清单里移除
 *
 * 全离线。运行：php tests/platform_test.php
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/fixtures/FakeTransport.php';

use Ai\AI;
use Ai\Helpers\Capabilities;
use Ai\Helpers\Protocols;
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
    echo pad($name, 60), $ok ? "✓\n" : "✗ {$detail}\n";
}

function proto(string $key)
{
    $class = Protocols::resolveClass($key);
    return new $class();
}

// =====================================================================
echo "=== 一、Gemini（更正 v1.16.0 的误判）===\n\n";

// v1.16.0 曾判定 Gemini 无图像能力，依据是一次**不带鉴权头**的探测（404）。
// 带上鉴权头后是 400（路由存在），假路径才是 404。
$g = proto('gemini');
$caps = $g->capabilities();

check(in_array(Capabilities::IMAGE, $caps, true), 'Gemini 声明图像生成');
check(in_array(Capabilities::VIDEO, $caps, true), 'Gemini 声明视频生成');
check(in_array(Capabilities::EMBEDDING, $caps, true), 'Gemini 声明向量化');
check(!in_array(Capabilities::IMAGE_EDIT, $caps, true), 'Gemini 不声明图像编辑（实测 404）');
check(!in_array(Capabilities::TTS, $caps, true),
      'Gemini 不声明 TTS（有 TTS 模型但只在原生接口，兼容层 404）');
check(!in_array(Capabilities::ASR, $caps, true), 'Gemini 不声明 ASR（实测 404）');

check($g->capabilityPath(Capabilities::IMAGE) === '/v1beta/openai/images/generations',
      'Gemini 图像端点', $g->capabilityPath(Capabilities::IMAGE));
check($g->capabilityPath(Capabilities::VIDEO) === '/v1beta/openai/videos',
      '**Gemini 视频端点是 /videos 而非 /videos/generations**（Sora 兼容形态）',
      $g->capabilityPath(Capabilities::VIDEO));

$imgModels = $g->knownImageModels();
check(count($imgModels) >= 4, 'Gemini 图像模型清单非空', (string) count($imgModels));
check(in_array('gemini-2.5-flash-image', $imgModels, true), '  含 gemini-2.5-flash-image');
check(in_array('gemini-3-pro-image', $imgModels, true), '  含 gemini-3-pro-image');

$vidModels = $g->knownVideoModels();
check(in_array('veo-3.1-generate-preview', $vidModels, true), 'Gemini 视频模型含 veo-3.1-generate-preview',
      implode(',', $vidModels));

// 已关闭的模型不能留在清单里
$chat = array_keys($g->knownModels());
check(!in_array('gemini-2.0-flash', $chat, true),
      '**已关闭的 gemini-2.0-flash 已从清单移除**', implode(',', $chat));
check(!in_array('gemini-2.0-flash-lite', $chat, true), '  gemini-2.0-flash-lite 同样不在清单');
check(in_array('gemini-3.6-flash', $chat, true), '  含当前最新的 gemini-3.6-flash');

// Sora 三跳流程
list($ai, $fake) = [null, new FakeTransport()];
$ai = new AI(['api_key' => 'k', 'model' => 'veo-3.1-generate-preview', 'protocol' => 'gemini']);
$ai->setTransport($fake);
$fake->queuePost(['id' => 'vid_1', 'object' => 'video', 'status' => 'queued', 'progress' => 0]);
$task = $ai->video()->generate('日落');
check($task->getId() === 'vid_1', 'Gemini 视频：任务 ID 解析');

$fake->queueGet(['id' => 'vid_1', 'status' => 'in_progress', 'progress' => 40]);
$task->refresh();
check($task->getStatus() === \Ai\Task\AsyncTask::STATUS_RUNNING,
      'Gemini 视频：in_progress → running', $task->getStatus());

$fake->queueGet(['id' => 'vid_1', 'status' => 'completed', 'progress' => 100]);
$fake->queueGet(['_raw' => 'MP4BYTES', '_content_type' => 'video/mp4', '_status' => 200]);
$task->refresh();
check($task->isSucceeded(), 'Gemini 视频：completed → succeeded');
check($task->getResult() !== null && $task->getResult()->getBytes() === 'MP4BYTES',
      '**Gemini 视频：第三跳取回的是字节而不是 URL**');

$gets = [];
foreach ($fake->getRequests() as $r) {
    if ($r['method'] === 'GET') { $gets[] = $r['url']; }
}
check(isset($gets[2]) && substr($gets[2], -8) === '/content',
      '  第三跳打到 /videos/{id}/content', isset($gets[2]) ? $gets[2] : '(无)');

// 落盘走字节而不是下载
$tmp = sys_get_temp_dir() . '/gem_vid_' . getmypid() . '.mp4';
$task->getResult()->saveTo($tmp);
check(is_file($tmp) && file_get_contents($tmp) === 'MP4BYTES', '  字节可直接落盘（该端点要鉴权，走不了公开下载）');
@unlink($tmp);

// =====================================================================
echo "\n=== 二、讯飞星火（更正继承来的错误声明）===\n\n";

// v1.16.0 起 Spark 继承了 OpenAI 基线的 /v1/images/* 声明，这是错的：
// 讯飞的图片生成在另一个域名上，形态也完全不同
$s = proto('spark');
$caps = $s->capabilities();

check(in_array(Capabilities::IMAGE, $caps, true), '讯飞声明图像生成');
check(!in_array(Capabilities::IMAGE_EDIT, $caps, true),
      '**讯飞不再声明图像编辑**（继承来的 /v1/images/edits 是错的）');
check(!in_array(Capabilities::TTS, $caps, true), '讯飞不声明 HTTP 语音（只有 WebSocket）');
check(in_array(Capabilities::REALTIME, $caps, true), '讯飞声明实时通道');

$ai = new AI(['api_key' => 'K:S', 'app_id' => 'APP1', 'model' => 'general', 'protocol' => 'spark']);
$ai->setTransport(new FakeTransport());
$m = new ReflectionMethod($ai->images(), 'endpoint');
$m->setAccessible(true);
$url = $m->invoke($ai->images());
check(strpos($url, 'https://maas-api.cn-huabei-1.xf-yun.com/v2.1/tti?') === 0,
      '**讯飞图像走独立域名的 TTI 接口**（不是 spark-api-open）', substr($url, 0, 50));

// 签名用 POST（WebSocket 那边是 GET，同一套规则不同方法）
parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
$auth = base64_decode($q['authorization']);
preg_match('/signature="([^"]+)"/', $auth, $sm);
$expect = base64_encode(hash_hmac(
    'sha256',
    "host: maas-api.cn-huabei-1.xf-yun.com\ndate: {$q['date']}\nPOST /v2.1/tti HTTP/1.1",
    'S',
    true
));
check(isset($sm[1]) && $sm[1] === $expect, '  **签名用 POST 作请求行且与文档规则逐字一致**');

// 三段式请求体
$fake = new FakeTransport();
$ai->setTransport($fake);
$fake->queuePost(['header' => ['code' => 0], 'payload' => ['choices' => ['text' => [['content' => base64_encode('PNG')]]]]]);
$res = $ai->images()->generate('一只猫', ['size' => '1024x768', 'seed' => 42]);
$req = $fake->lastRequest();
check(isset($req['data']['header']['app_id']) && $req['data']['header']['app_id'] === 'APP1',
      '  请求体 header.app_id 就位');
check($req['data']['parameter']['chat']['width'] === 1024 && $req['data']['parameter']['chat']['height'] === 768,
      '  **尺寸拆成 width / height 两个整数**', json_encode($req['data']['parameter']['chat']));
check($req['data']['payload']['message']['text'][0]['content'] === '一只猫', '  提示词进 payload.message.text');
check($res->isSuccess() && base64_decode($res->getBase64()[0]) === 'PNG',
      '  图片从 payload.choices.text[].content 解出（base64）');

// header.code 非 0 是失败，此时 HTTP 常常仍是 200
$fake->reset();
$fake->queuePost(['header' => ['code' => 10013, 'message' => '内容审核不通过']]);
$res = $ai->images()->generate('x');
check(!$res->isSuccess() && strpos($res->getError(), '10013') !== false,
      '  **header.code 非 0 判为失败**（HTTP 仍是 200）', $res->getError());

// app_id 缺失要说清楚
$ai2 = new AI(['api_key' => 'K:S', 'model' => 'general', 'protocol' => 'spark']);
$ai2->setTransport(new FakeTransport());
try {
    $ai2->images()->generate('x');
    check(false, '  app_id 缺失时报错', '未抛出');
} catch (\Ai\Exceptions\RequestException $e) {
    check(strpos($e->getMessage(), 'app_id') !== false, '  app_id 缺失时报错并说明来源');
}

// =====================================================================
echo "\n=== 三、模型清单补全 ===\n\n";

$lists = [
    ['zhipu',  'knownAsrModels',   'glm-asr-2512',             '智谱 ASR'],
    ['doubao', 'knownImageModels', 'doubao-seedream-4.0',      '火山方舟图像'],
    ['qwen',   'knownVideoModels', 'wan2.7-t2v',               '通义视频'],
    ['qwen',   'knownImageModels', 'wan2.2-t2i-flash',         '通义图像'],
    ['openai', 'knownImageModels', 'gpt-image-1',              'OpenAI 图像'],
    ['minimax','knownTtsModels',   'speech-2.8-hd',            'MiniMax TTS'],
];
foreach ($lists as [$key, $method, $expect, $label]) {
    $p = proto($key);
    $list = $p->{$method}();
    check(in_array($expect, $list, true), sprintf('  %-14s 含 %s', $label, $expect),
          implode(',', $list) ?: '(空)');
}

// 火山方舟视频模型：文档站是 JS 渲染抓不到正文，搜索也没给出确切 ID。
// 按「查不到就不填」的规则留空——留空会回退到平台的模型列表接口，
// 填错会让用户拿着不存在的模型名去调
check(proto('doubao')->knownVideoModels() === [],
      '  火山方舟视频模型留空（文档未查证，不凭印象填）');

// =====================================================================
echo "\n=== 四、能力声明与模型清单的一致性 ===\n\n";

// 已经查证过的平台，声明了图像/视频能力就该有模型清单
$verified = [
    'openai'      => [Capabilities::IMAGE],
    'gemini'      => [Capabilities::IMAGE, Capabilities::VIDEO],
    'zhipu'       => [Capabilities::IMAGE, Capabilities::VIDEO],
    'qwen'        => [Capabilities::IMAGE, Capabilities::VIDEO],
    'siliconflow' => [Capabilities::IMAGE],
    'stepfun'     => [Capabilities::IMAGE],
    'grok'        => [Capabilities::IMAGE],
];
$missing = [];
foreach ($verified as $key => $caps) {
    $p = proto($key);
    foreach ($caps as $cap) {
        $method = $cap === Capabilities::IMAGE ? 'knownImageModels' : 'knownVideoModels';
        if (!in_array($cap, $p->capabilities(), true) || !$p->{$method}()) {
            $missing[] = "{$key}:{$cap}";
        }
    }
}
check($missing === [], '已查证平台的声明与模型清单一一对应', implode(', ', $missing));

// Anthropic 家族始终只有对话
foreach (['claude', 'qwen-anthropic', 'zhipu-anthropic', 'moonshot-anthropic'] as $key) {
    check(proto($key)->capabilities() === [], "  {$key} 仅对话（Anthropic 无扩展能力接口）",
          implode(',', proto($key)->capabilities()));
}

// =====================================================================
echo "\n=== 五、配置逃生口 ===\n\n";

// 库的判断可能过时或有遗漏——本轮审计就查出 Gemini 图像被误判为「没有」。
// 用户显式配了 {能力}_endpoint 时，不该被库的判断挡死。

$png = tempnam(sys_get_temp_dir(), 'p') . '.png';
file_put_contents($png, 'x');

// 场景 A：协议有该形态的解析器，只是不声明路径（通义的图像编辑实测 404）
$fake = new FakeTransport();
$ai = new AI(['api_key' => 'k', 'model' => 'qwen-plus', 'protocol' => 'qwen']);
$ai->setTransport($fake);
try {
    $ai->images()->edit($png, '改');
    check(false, '未配逃生口时仍明确报错', '未抛出');
} catch (\Ai\Exceptions\UnsupportedCapabilityException $e) {
    check(true, '未配逃生口时仍明确报错');
    check(strpos($e->getMessage(), 'image_edit_endpoint') !== false,
          '  报错里给出逃生口的用法', $e->getMessage());
}

$ai2 = new AI(['api_key' => 'k', 'model' => 'qwen-plus', 'protocol' => 'qwen',
               'image_edit_endpoint' => 'https://gw.internal/edit']);
$ai2->setTransport($fake);
$fake->queuePost(['data' => [['url' => 'https://cdn/e.png']]]);
$res = $ai2->images()->edit($png, '改');
check($res->getUrls() === ['https://cdn/e.png'], '**配了 image_edit_endpoint 后放行**');
check($fake->lastRequest()['url'] === 'https://gw.internal/edit', '  请求打到用户指定的地址');

// 场景 B：协议族根本没有该形态的解析器——逃生口绕不过这一层，
// 但要说清楚是哪一层挡住的，别让用户以为是配置没生效
$ai3 = new AI(['api_key' => 'k', 'model' => 'claude-3-opus', 'protocol' => 'claude',
               'image_endpoint' => 'https://gw.internal/img']);
$ai3->setTransport(new FakeTransport());
try {
    $ai3->images()->generate('猫');
    check(false, 'Claude 无图像解析器时仍报错', '未抛出');
} catch (\Ai\Exceptions\UnsupportedCapabilityException $e) {
    check(strpos($e->getMessage(), '没有「图像生成」的响应解析器') !== false,
          '**无解析器时报错点明是哪一层挡住的**', $e->getMessage());
    check(strpos($e->getMessage(), 'protocol 改成 openai') !== false,
          '  并给出可行的替代做法');
}
@unlink($png);

// 逃生口不能影响未配置时的正常判断
check(!in_array(Capabilities::IMAGE_EDIT, proto('qwen')->capabilities(), true),
      '逃生口不污染协议的默认声明（新实例仍不声明）');

// =====================================================================
echo "\n=== 六、第 2 批：已接入平台清单补全 ===\n\n";

$batch2 = [
    ['openai',      'knownEmbeddingModels', 'text-embedding-3-small',      'OpenAI 向量'],
    ['openai',      'knownTtsModels',       'gpt-4o-mini-tts',             'OpenAI TTS'],
    ['openai',      'knownAsrModels',       'gpt-4o-transcribe',           'OpenAI ASR'],
    ['siliconflow', 'knownAsrModels',       'FunAudioLLM/SenseVoiceSmall', '硅基流动 ASR'],
    ['minimax',     'knownVideoModels',     'MiniMax-Hailuo-02',           'MiniMax 视频'],
    ['gemini',      'knownEmbeddingModels', 'gemini-embedding-001',        'Gemini 向量'],
];
foreach ($batch2 as [$key, $method, $expect, $label]) {
    $list = proto($key)->{$method}();
    check(in_array($expect, $list, true), sprintf('  %-14s 含 %s', $label, $expect),
          implode(',', $list) ?: '(空)');
}

// OpenAI 的清单必须与官方 OpenAPI 规范一致，不能凭印象
$o = proto('openai');
check($o->knownTtsModels() === ['gpt-4o-mini-tts', 'gpt-4o-mini-tts-2025-12-15', 'tts-1-hd', 'tts-1'],
      '  OpenAI TTS 清单与官方规范逐项一致', implode(',', $o->knownTtsModels()));
check(count($o->knownAsrModels()) === 6, '  OpenAI ASR 清单 6 项（与规范一致）',
      (string) count($o->knownAsrModels()));

// 各厂商子类不继承 OpenAI 的清单
foreach (['deepseek', 'moonshot', 'groq'] as $key) {
    $p = proto($key);
    check($p->knownEmbeddingModels() === [] && $p->knownTtsModels() === [],
          "  {$key} 不继承 OpenAI 的模型清单");
}

echo "\n" . str_repeat('=', 66) . "\n";
if ($failures) {
    echo count($failures) . " 项未通过：\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "全部通过：第 1 批平台的能力声明与模型清单已与官方文档对齐\n";
exit(0);
