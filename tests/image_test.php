<?php
/**
 * 图像生成测试
 *
 * 全离线，用 FakeTransport 预置响应，不打真实接口（图像接口按张计费）。
 *
 * 重点是**各平台的字段偏差**。图像接口远没有对话/向量那么统一，
 * 下面每一条偏差都来自各家官方文档（2026-08 核对）：
 *   - 硅基流动：响应 images[] 而非 data[]；请求 image_size / batch_size
 *   - xAI：没有 size，用 aspect_ratio + resolution
 *   - 豆包：response_format 取值是 "base64" 而非 "b64_json"
 *   - 通义：兼容模式下根本没有同步文生图接口
 *
 * 运行：php tests/image_test.php
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/fixtures/FakeTransport.php';

use Ai\AI;
use Ai\Helpers\Capabilities;
use Ai\Response\ImageResponse;
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

function makeAI(string $protocol, string $model = 'gpt-image-1', array $extra = []): array
{
    $fake = new FakeTransport();
    $ai = new AI(array_merge(['api_key' => 'sk-test', 'model' => $model, 'protocol' => $protocol], $extra));
    $ai->setTransport($fake);
    return [$ai, $fake];
}

/** 一张 1x1 的合法 PNG，用于落盘测试 */
function tinyPngBase64(): string
{
    return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
}

// =====================================================================
echo "=== 一、能力声明与路径推导 ===\n\n";

$expect = [
    'openai'      => '/v1/images/generations',
    'zhipu'       => '/v4/images/generations',
    'doubao'      => '/api/v3/images/generations',
    'grok'        => '/v1/images/generations',
    'siliconflow' => '/v1/images/generations',
    'stepfun'     => '/v1/images/generations',
];
foreach ($expect as $key => $path) {
    $class = \Ai\Helpers\Protocols::resolveClass($key);
    $p = new $class();
    check($p->capabilityPath(Capabilities::IMAGE) === $path, sprintf('  %-12s → %s', $key, $path),
          $p->capabilityPath(Capabilities::IMAGE));
}

// 通义在 OpenAI 兼容模式下无图像路由（实测 404），走的是原生异步接口。
// 它**声明**图像能力，但形态是异步的，详见第八节
$qwen = new \Ai\Protocol\Qwen();
check(in_array(Capabilities::IMAGE, $qwen->capabilities(), true),
      '  qwen 声明图像能力（走原生异步接口，非兼容模式）');
check($qwen->capabilityPath(Capabilities::IMAGE) !== '/v1/images/generations',
      '  qwen 不走 OpenAI 兼容路径（实测 404）', $qwen->capabilityPath(Capabilities::IMAGE));

// Anthropic 家族同样没有
foreach (['claude', 'zhipu-anthropic'] as $key) {
    $class = \Ai\Helpers\Protocols::resolveClass($key);
    $p = new $class();
    check(!in_array(Capabilities::IMAGE, $p->capabilities(), true), "  {$key} 不声明图像能力");
}

// =====================================================================
echo "\n=== 二、端点解析 ===\n\n";

foreach ([['openai', []], ['zhipu', []], ['doubao', ['base_url' => 'https://gw.internal/ark']],
          ['siliconflow', ['base_url' => 'https://gw.internal/sf']]] as [$proto, $extra]) {
    list($ai) = makeAI($proto, 'img-model', $extra);
    $m = new ReflectionMethod($ai->images(), 'endpoint');
    $m->setAccessible(true);
    $img  = $m->invoke($ai->images());
    $chat = $ai->resolveEndpoint();
    $want = preg_replace('#/chat/completions$#', '/images/generations', $chat);
    $label = $proto . ($extra ? '（自建网关）' : '');
    check($img === $want && parse_url($img, PHP_URL_HOST) === parse_url($chat, PHP_URL_HOST),
          "  {$label}：图像端点与对话端点同源同前缀", "chat={$chat} img={$img}");
}

// =====================================================================
echo "\n=== 三、请求构建：基线与各平台偏差 ===\n\n";

// 基线：OpenAI 形态原样透传
list($ai, $fake) = makeAI('openai');
$fake->queuePost(['created' => 1, 'data' => [['url' => 'https://x/a.png']]]);
$ai->images()->generate('一只猫', ['size' => '1024x1536', 'n' => 2, 'quality' => 'high']);
$req = $fake->lastRequest();
check($req['data']['prompt'] === '一只猫', 'OpenAI：prompt 就位');
check($req['data']['size'] === '1024x1536', 'OpenAI：size 原样透传');
check($req['data']['n'] === 2, 'OpenAI：n 原样透传');
check($req['data']['quality'] === 'high', 'OpenAI：quality 原样透传');

// 硅基流动：size → image_size，n → batch_size
list($ai, $fake) = makeAI('siliconflow', 'Kwai-Kolors/Kolors');
$fake->queuePost(['images' => [['url' => 'https://x/a.png']], 'seed' => 42]);
$ai->images()->generate('一只猫', ['size' => '1024x1024', 'n' => 2, 'negative_prompt' => '模糊']);
$req = $fake->lastRequest();
check($req['data']['image_size'] === '1024x1024', '硅基流动：size → image_size',
      json_encode($req['data']));
check($req['data']['batch_size'] === 2, '硅基流动：n → batch_size');
check(!isset($req['data']['size']) && !isset($req['data']['n']), '硅基流动：原字段名不残留');
check($req['data']['negative_prompt'] === '模糊', '硅基流动：negative_prompt 透传');

// xAI：size → aspect_ratio + resolution
list($ai, $fake) = makeAI('grok', 'grok-imagine-image-quality');
$fake->queuePost(['data' => [['url' => 'https://x/a.png']]]);
$ai->images()->generate('一只猫', ['size' => '1024x1024']);
$req = $fake->lastRequest();
check($req['data']['aspect_ratio'] === '1:1', 'xAI：1024x1024 → aspect_ratio 1:1',
      json_encode($req['data']));
check($req['data']['resolution'] === '1k', 'xAI：resolution 由长边推出');
check(!isset($req['data']['size']), 'xAI：size 不残留（平台不认）');

$fake->reset();
$fake->queuePost(['data' => [['url' => 'https://x/a.png']]]);
$ai->images()->generate('宽幅', ['size' => '1920x1080']);
$req = $fake->lastRequest();
check($req['data']['aspect_ratio'] === '16:9', 'xAI：1920x1080 → 16:9', json_encode($req['data']));
check($req['data']['resolution'] === '2k', 'xAI：长边 >1536 → 2k');

$fake->reset();
$fake->queuePost(['data' => [['url' => 'https://x/a.png']]]);
$ai->images()->generate('用户自定', ['size' => '1024x1024', 'aspect_ratio' => '3:2']);
$req = $fake->lastRequest();
check($req['data']['aspect_ratio'] === '3:2', 'xAI：用户显式传 aspect_ratio 时不被覆盖');

// 豆包：response_format 取值不同
list($ai, $fake) = makeAI('doubao', 'doubao-seedream-4.0');
$fake->queuePost(['data' => [['url' => 'https://x/a.png']]]);
$ai->images()->generate('一只猫', ['response_format' => 'b64_json']);
$req = $fake->lastRequest();
check($req['data']['response_format'] === 'base64', '豆包：b64_json → base64',
      json_encode($req['data']));

$fake->reset();
$fake->queuePost(['data' => [['url' => 'https://x/a.png']]]);
$ai->images()->generate('一只猫', ['response_format' => 'url', 'watermark' => false]);
$req = $fake->lastRequest();
check($req['data']['response_format'] === 'url', '豆包：url 不受影响');
check($req['data']['watermark'] === false, '豆包：watermark 透传');

// 空提示词
list($ai) = makeAI('openai');
try {
    $ai->images()->generate('   ');
    check(false, '空提示词报错', '未抛出');
} catch (\Ai\Exceptions\RequestException $e) {
    check(true, '空提示词报错');
}

// =====================================================================
echo "\n=== 四、响应解析：多种形态 ===\n\n";

// OpenAI：url
list($ai, $fake) = makeAI('openai');
$fake->queuePost(['created' => 1, 'model' => 'gpt-image-1', 'data' => [
    ['url' => 'https://cdn/a.png', 'revised_prompt' => '一只戴眼镜的猫'],
    ['url' => 'https://cdn/b.png'],
]]);
$res = $ai->images()->generate('猫');
check($res instanceof ImageResponse, '返回 ImageResponse');
check($res->isSuccess(), 'isSuccess()');
check(count($res) === 2, 'count() 得到张数');
check($res->getUrls() === ['https://cdn/a.png', 'https://cdn/b.png'], 'URL 列表解析');
check($res->getUrl(0) === 'https://cdn/a.png', 'getUrl(0)');
check($res->getRevisedPrompt() === '一只戴眼镜的猫', 'revised_prompt 解析');
check($res->getCapability() === Capabilities::IMAGE, '能力标识');

// OpenAI：GPT 图像模型只返回 b64_json（官方规范明确 url 不支持）
$fake->reset();
$fake->queuePost(['data' => [['b64_json' => tinyPngBase64()]]]);
$res = $ai->images()->generate('猫');
check(count($res->getBase64()) === 1, 'b64_json 解析');
check($res->getUrls() === [], '只有 base64 时 URL 为空');
check($res->isSuccess(), 'b64 响应算成功');

// 硅基流动：images[] 容器
list($ai, $fake) = makeAI('siliconflow', 'Kwai-Kolors/Kolors');
$fake->queuePost(['images' => [['url' => 'https://sf/a.png']], 'timings' => ['inference' => 1.2], 'seed' => 7]);
$res = $ai->images()->generate('猫');
check($res->getUrls() === ['https://sf/a.png'], '硅基流动：images[] 容器也能解析',
      json_encode($res->getUrls()));

// 豆包：base64 字段名
list($ai, $fake) = makeAI('doubao', 'doubao-seedream-4.0');
$fake->queuePost(['data' => [['base64' => tinyPngBase64()]]]);
$res = $ai->images()->generate('猫');
check(count($res->getBase64()) === 1, '豆包：base64 字段名也能解析');

// data URI 前缀要剥掉
list($ai, $fake) = makeAI('openai');
$fake->queuePost(['data' => [['b64_json' => 'data:image/png;base64,' . tinyPngBase64()]]]);
$res = $ai->images()->generate('猫');
check($res->getBase64()[0] === tinyPngBase64(), 'data: 前缀被剥掉，只留纯 base64');

// 平台报错
$fake->reset();
$fake->queuePost(['error' => ['message' => '内容不合规']]);
$res = $ai->images()->generate('猫');
check(!$res->isSuccess(), '平台报错时 isSuccess() 为 false');
check($res->getError() === '内容不合规', '错误信息透传');

// 既没报错也没图 —— 不能静默返回空
$fake->reset();
$fake->queuePost(['created' => 1, 'data' => []]);
$res = $ai->images()->generate('猫');
check(!$res->isSuccess(), '空响应不算成功');
check(strpos($res->getError(), '没有解析到任何图像') !== false,
      '**空响应给出可排查的说明**（而不是静默空结果）', $res->getError());

// =====================================================================
echo "\n=== 五、落盘 ===\n\n";

$dir = sys_get_temp_dir() . '/ai_img_test_' . getmypid();
@mkdir($dir, 0777, true);

list($ai, $fake) = makeAI('openai');
$fake->queuePost(['data' => [['b64_json' => tinyPngBase64()], ['b64_json' => tinyPngBase64()]]]);
$res = $ai->images()->generate('猫');
$paths = $res->saveTo($dir, 'cat');

check(count($paths) === 2, 'saveTo 返回两条路径', (string) count($paths));
check(is_file($paths[0]) && is_file($paths[1]), '文件确实写到磁盘');
check(basename($paths[0]) === 'cat_1.png' && basename($paths[1]) === 'cat_2.png',
      '多张时文件名带序号', implode(',', array_map('basename', $paths)));
check(filesize($paths[0]) === strlen(base64_decode(tinyPngBase64())), '内容长度正确');
check(substr(file_get_contents($paths[0]), 1, 3) === 'PNG', '写出的是真正的 PNG');

// 单张不带序号
$fake->reset();
$fake->queuePost(['data' => [['b64_json' => tinyPngBase64()]]]);
$paths2 = $ai->images()->generate('猫')->saveTo($dir, 'single');
check(basename($paths2[0]) === 'single.png', '单张时文件名不带序号', basename($paths2[0]));

// 目录不存在 —— 报错而不是自动创建
$fake->reset();
$fake->queuePost(['data' => [['b64_json' => tinyPngBase64()]]]);
try {
    $ai->images()->generate('猫')->saveTo($dir . '/not/exist');
    check(false, '目录不存在时报错（不自动创建，免得路径写错时散落空目录）', '未抛出');
} catch (\Ai\Exceptions\RequestException $e) {
    check(strpos($e->getMessage(), '目录不存在') !== false,
          '目录不存在时报错（不自动创建，免得路径写错时散落空目录）', $e->getMessage());
}
check(!is_dir($dir . '/not/exist'), '确实没有偷偷创建目录');

array_map('unlink', glob($dir . '/*'));
@rmdir($dir);

// =====================================================================
echo "\n=== 六、模型清单（据官方文档）===\n\n";

$models = [
    'openai'      => 'gpt-image-1',
    'zhipu'       => 'cogview-4',
    'grok'        => 'grok-imagine-image-quality',
    'siliconflow' => 'Kwai-Kolors/Kolors',
    'stepfun'     => 'step-1x-medium',
    'doubao'      => 'doubao-seedream-4.0',   // 审计后按官方文档更正（原为带日期的版本号）
];
foreach ($models as $key => $expectModel) {
    $class = \Ai\Helpers\Protocols::resolveClass($key);
    $p = new $class();
    $list = $p->knownImageModels();
    check(in_array($expectModel, $list, true), "  {$key} 登记了 {$expectModel}", implode(',', $list));
}
// 没有图像能力的平台不该报出 OpenAI 的模型名
check((new \Ai\Protocol\DeepSeek())->knownImageModels() === [],
      '  未登记的平台返回空清单（不继承 OpenAI 的模型名）');

// =====================================================================
echo "\n=== 七、跨平台一致性 ===\n\n";

// 同一套调用代码在各平台上都应拿到结构一致的结果
$shapes = [];
foreach (['openai', 'zhipu', 'stepfun', 'grok', 'siliconflow', 'doubao'] as $key) {
    list($ai, $fake) = makeAI($key, 'img-model');
    $container = ($key === 'siliconflow') ? 'images' : 'data';
    $fake->queuePost([$container => [['url' => 'https://cdn/x.png']]]);
    $res = $ai->images()->generate('统一的调用代码', ['size' => '1024x1024']);
    $shapes[$key] = [$res->getUrls(), count($res), $res->isSuccess()];
}
$first = reset($shapes);
$diff = [];
foreach ($shapes as $k => $v) {
    if ($v !== $first) {
        $diff[] = $k;
    }
}
check($diff === [], '6 个平台的解析结果结构完全一致', implode(',', $diff));

// 不支持同步生成的协议要明确报错并指路
list($ai) = makeAI('qwen', 'qwen-plus');
try {
    $ai->images()->generate('猫');
    check(false, 'qwen 上同步 generate() 抛异常', '未抛出');
} catch (\Ai\Exceptions\UnsupportedCapabilityException $e) {
    check(strpos($e->getMessage(), 'generateAsync') !== false,
          'qwen 上同步 generate() 抛异常并指向 generateAsync()', $e->getMessage());
}
// 完全没有图像能力的协议族（Anthropic Messages 不走 OpenAiImages）
list($ai) = makeAI('claude', 'claude-3-opus');
try {
    $ai->images()->edit(__FILE__, '猫');
    check(false, 'Claude 无图像编辑能力时抛异常', '未抛出');
} catch (\Ai\Exceptions\UnsupportedCapabilityException $e) {
    check(strpos($e->getMessage(), '图像编辑') !== false, 'Claude 无图像编辑能力时抛异常并点名能力',
          $e->getMessage());
}

// 声明策略在 v1.23.0 的平台审计中改成了「查证优先」：
// DeepSeek 官方文档明确只有 /chat/completions，故不再继承任何扩展能力声明。
// 确知可用时仍可用 image_edit_endpoint 逃生口绕过
check((new \Ai\Protocol\DeepSeek())->capabilityPath(Capabilities::IMAGE_EDIT) === '',
      'DeepSeek 不再继承编辑路径（官方文档确认无此接口）');
check((new \Ai\Protocol\Moonshot())->capabilityPath(Capabilities::IMAGE) === '/v1/images/generations',
      '月之暗面保留图像声明（实测存在：401 vs 假路径 404）');

// =====================================================================
echo "\n=== 八、异步文生图（通义万相）===\n\n";

$qwen = new \Ai\Protocol\Qwen();
check($qwen->imageIsAsync(), '万相声明图像生成为异步任务式');
check(!(new \Ai\Protocol\OpenAI())->imageIsAsync(), 'OpenAI 是同步的');
check($qwen->capabilityPath(Capabilities::IMAGE) === '/api/v1/services/aigc/text2image/image-synthesis',
      '万相图像端点是原生异步接口', $qwen->capabilityPath(Capabilities::IMAGE));
check($qwen->capabilityHeaders(Capabilities::IMAGE) === ['X-DashScope-Async' => 'enable'],
      '**万相图像也要带 X-DashScope-Async 头**');

// 同步入口在异步平台上必须明确报错，而不是返回「成功但没有图」
list($ai, $fake) = makeAI('qwen', 'wan2.2-t2i-flash');
try {
    $ai->images()->generate('猫');
    check(false, '**异步平台上 generate() 明确报错**（不返回空结果）', '未抛出');
} catch (\Ai\Exceptions\UnsupportedCapabilityException $e) {
    check(strpos($e->getMessage(), 'generateAsync') !== false,
          '**异步平台上 generate() 报错并指向 generateAsync()**', $e->getMessage());
}
// 反过来也要挡住
list($ai2) = makeAI('openai', 'gpt-image-1');
try {
    $ai2->images()->generateAsync('猫');
    check(false, '同步平台上 generateAsync() 报错', '未抛出');
} catch (\Ai\Exceptions\UnsupportedCapabilityException $e) {
    check(strpos($e->getMessage(), 'generate()') !== false,
          '同步平台上 generateAsync() 报错并指向 generate()', $e->getMessage());
}

// 提交
list($ai, $fake) = makeAI('qwen', 'wan2.2-t2i-flash');
$fake->queuePost(['output' => ['task_id' => 'IMG1', 'task_status' => 'PENDING'], 'request_id' => 'r']);
$task = $ai->images()->generateAsync('一只在看书的猫', ['size' => '1024x1024', 'n' => 2, 'negative_prompt' => '模糊']);

check($task instanceof \Ai\Task\AsyncTask, 'generateAsync() 返回 AsyncTask');
check($task->getId() === 'IMG1', '任务 ID 解析');
check($task->getCapability() === Capabilities::IMAGE, '能力标识为 image');
check(!$task->isDone(), '刚提交时未完成');

$req = $fake->lastRequest();
check(isset($req['data']['input']['prompt']) && $req['data']['input']['prompt'] === '一只在看书的猫',
      '万相：prompt 进 input 段', json_encode($req['data'], JSON_UNESCAPED_UNICODE));
check($req['data']['input']['negative_prompt'] === '模糊', '万相：negative_prompt 进 input 段');
check($req['data']['parameters']['n'] === 2, '万相：n 进 parameters 段');
check($req['data']['parameters']['size'] === '1024*1024',
      '**万相：尺寸分隔符 x → \***（传错不会被容错，直接判非法参数）',
      $req['data']['parameters']['size']);
check(isset($req['headers']['X-DashScope-Async']), '万相：异步头随请求发出');

// 轮询到成功，多图
$fake->queueGet(['output' => ['task_status' => 'SUCCEEDED', 'results' => [
    ['url' => 'https://cdn/a.png'], ['url' => 'https://cdn/b.png'],
]]]);
$task->refresh();
check($task->isSucceeded(), '轮询到成功');
$result = $task->getResult();
check($result instanceof ImageResponse, '结果是 ImageResponse');
check($result->getUrls() === ['https://cdn/a.png', 'https://cdn/b.png'],
      '**output.results[] 数组全部取出**', json_encode($result->getUrls()));
check(count($result) === 2, '张数正确');

$gets = [];
foreach ($fake->getRequests() as $r) {
    if ($r['method'] === 'GET') { $gets[] = $r['url']; }
}
check(isset($gets[0]) && strpos($gets[0], '/api/v1/tasks/IMG1') !== false,
      '查询地址是 /api/v1/tasks/{id}', isset($gets[0]) ? $gets[0] : '(无)');

// 万相 2.6 换了结果结构，也要能解析
$parsed = $qwen->parseTaskStatus(Capabilities::IMAGE, ['output' => [
    'task_status' => 'SUCCEEDED',
    'choices' => [['message' => ['content' => [['image' => 'https://cdn/new.png']]]]],
]]);
check($parsed['result'] instanceof ImageResponse
      && $parsed['result']->getUrls() === ['https://cdn/new.png'],
      '**万相 2.6 的 output.choices[] 结构也能解析**（只认一种会静默取不到图）');

// 成功但没图要说清楚
$parsed = $qwen->parseTaskStatus(Capabilities::IMAGE, ['output' => ['task_status' => 'SUCCEEDED']]);
check(!$parsed['result']->isSuccess(), '成功但无图时不算成功');
check(strpos($parsed['result']->getError(), '没有解析到图片') !== false, '给出可排查的说明');

// 失败
list($ai, $fake) = makeAI('qwen', 'wan2.2-t2i-flash');
$fake->queuePost(['output' => ['task_id' => 'IMG2']]);
$task = $ai->images()->generateAsync('x');
$fake->queueGet(['output' => ['task_status' => 'FAILED', 'message' => '内容不合规']]);
$task->refresh();
check($task->isFailed(), '万相：FAILED 判为失败');
check($task->getError() === '内容不合规', '错误信息透传', $task->getError());

// 序列化恢复
list($ai, $fake) = makeAI('qwen', 'wan2.2-t2i-flash');
$fake->queuePost(['output' => ['task_id' => 'IMG3']]);
$task = $ai->images()->generateAsync('x');
$data = json_decode((string) json_encode($task->toArray()), true);

list($ai3, $fake3) = makeAI('qwen', 'wan2.2-t2i-flash');
$restored = \Ai\Task\AsyncTask::fromArray($data, $ai3);
$fake3->queueGet(['output' => ['task_status' => 'SUCCEEDED', 'results' => [['url' => 'https://cdn/r.png']]]]);
$restored->refresh();
check($restored->isSucceeded() && $restored->getResult()->getUrl(0) === 'https://cdn/r.png',
      '**图像任务同样能跨请求恢复**');

// 自建网关下仍跟随
list($ai, $fake) = makeAI('qwen', 'wan2.2-t2i-flash', ['base_url' => 'https://gw.internal/ds']);
$m = new ReflectionMethod($ai->images(), 'endpoint');
$m->setAccessible(true);
check($m->invoke($ai->images()) === 'https://gw.internal/ds/api/v1/services/aigc/text2image/image-synthesis',
      '自建网关下图像端点保留路径前缀', $m->invoke($ai->images()));

// =====================================================================
echo "\n=== 九、图像编辑 ===\n\n";

$editPaths = [
    'openai'  => '/v1/images/edits',
    'stepfun' => '/v1/images/edits',
    'grok'    => '/v1/images/edits',
    'zhipu'   => '/v4/images/edits',
];
foreach ($editPaths as $key => $path) {
    $class = \Ai\Helpers\Protocols::resolveClass($key);
    $p = new $class();
    check($p->capabilityPath(Capabilities::IMAGE_EDIT) === $path, sprintf('  %-8s → %s', $key, $path),
          $p->capabilityPath(Capabilities::IMAGE_EDIT));
}
// 实测无该路由的两家
check((new \Ai\Protocol\SiliconFlow())->capabilityPath(Capabilities::IMAGE_EDIT) === '',
      '  硅基流动不声明（实测 404，图生图并进了 generations）');
check((new \Ai\Protocol\Qwen())->capabilityPath(Capabilities::IMAGE_EDIT) === '',
      '  通义不声明（兼容模式实测 404）');

// 编辑请求：multipart
$png = sys_get_temp_dir() . '/ai_edit_test_' . getmypid() . '.png';
file_put_contents($png, base64_decode(tinyPngBase64()));
$maskPng = sys_get_temp_dir() . '/ai_edit_mask_' . getmypid() . '.png';
file_put_contents($maskPng, base64_decode(tinyPngBase64()));

list($ai, $fake) = makeAI('openai', 'gpt-image-1');
$fake->queuePost(['data' => [['b64_json' => tinyPngBase64()]]]);
$res = $ai->images()->edit($png, '把背景换成星空', ['size' => '1024x1024']);

check($res instanceof ImageResponse, '编辑返回 ImageResponse');
check($res->isSuccess(), '编辑成功');
$req = $fake->lastRequest();
check(strpos($req['url'], '/v1/images/edits') !== false, '打到编辑端点', $req['url']);
check(isset($req['headers']['Content-Type']) && $req['headers']['Content-Type'] === 'multipart/form-data',
      '声明 multipart 意图（传输层负责摘除并交给 curl 生成 boundary）');
check($req['data']['image'] instanceof \Ai\Helpers\AIFile, 'image 字段是 AIFile');
check($req['data']['prompt'] === '把背景换成星空', '编辑指令就位');
check($req['data']['size'] === '1024x1024', '其余参数透传');
check(!isset($req['data']['mask']), '未传 mask 时不出现该字段');

// 蒙版
$fake->reset();
$fake->queuePost(['data' => [['b64_json' => tinyPngBase64()]]]);
$ai->images()->edit($png, '去掉这只手', ['mask' => $maskPng]);
$req = $fake->lastRequest();
check($req['data']['mask'] instanceof \Ai\Helpers\AIFile, 'mask 传入后是 AIFile');

// AIFile 实例
$fake->reset();
$fake->queuePost(['data' => [['b64_json' => tinyPngBase64()]]]);
check($ai->images()->edit(\Ai\Helpers\AIFile::fromPath($png), 'x')->isSuccess(), '接受 AIFile 实例');

// 远端 URL 必须先落地
try {
    $ai->images()->edit(\Ai\Helpers\AIFile::fromUrl('https://example.com/a.png'), 'x');
    check(false, '远端 URL 明确报错（不偷偷下载）', '未抛出');
} catch (\Ai\Exceptions\RequestException $e) {
    check(strpos($e->getMessage(), 'Media::download') !== false,
          '远端 URL 明确报错并给出正确做法', $e->getMessage());
}
// 空指令
try {
    $ai->images()->edit($png, '  ');
    check(false, '空编辑指令报错', '未抛出');
} catch (\Ai\Exceptions\RequestException $e) {
    check(true, '空编辑指令报错');
}
// 文件不存在
try {
    $ai->images()->edit('/no/such/file.png', 'x');
    check(false, '文件不存在时报错', '未抛出');
} catch (\InvalidArgumentException $e) {
    check(true, '文件不存在时报错');
}
// 不支持的平台
list($ai4) = makeAI('siliconflow', 'Kwai-Kolors/Kolors');
try {
    $ai4->images()->edit($png, 'x');
    check(false, '硅基流动不支持编辑时报错', '未抛出');
} catch (\Ai\Exceptions\UnsupportedCapabilityException $e) {
    check(strpos($e->getMessage(), '图像编辑') !== false,
          '硅基流动不支持编辑时报错并点名能力', $e->getMessage());
}

@unlink($png);
@unlink($maskPng);

echo "\n" . str_repeat('=', 64) . "\n";
if ($failures) {
    echo count($failures) . " 项未通过：\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "全部通过：各平台字段偏差已归一，同一套代码产出一致结果\n";
exit(0);
