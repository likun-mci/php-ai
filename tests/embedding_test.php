<?php
/**
 * 文本向量化测试
 *
 * 全离线，用 FakeTransport 预置响应，不需要任何 API Key。
 *
 * 重点盯两类**不会报错但会出错**的问题：
 *   1) 向量与原文错位 —— 平台不保证 data 数组顺序与输入一致，
 *      不按 index 归位就会静默错位，后续检索莫名其妙地不准
 *   2) 分批合并时顺序或数量出问题 —— 同样静默，同样难查
 *
 * 运行：php tests/embedding_test.php
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/fixtures/FakeTransport.php';

use Ai\AI;
use Ai\Helpers\Capabilities;
use Ai\Response\EmbeddingResponse;
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
    echo pad($name, 54), $ok ? "✓\n" : "✗ {$detail}\n";
}

/**
 * 造一个挂着假传输层的 AI
 */
function makeAI(string $protocol, string $model = 'text-embedding-3-small', array $extra = []): array
{
    $fake = new FakeTransport();
    $ai = new AI(array_merge(['api_key' => 'sk-test', 'model' => $model, 'protocol' => $protocol], $extra));
    $ai->setTransport($fake);
    return [$ai, $fake];
}

/**
 * 构造一份标准 OpenAI 格式的向量响应
 * @param array<int, array<int, float>> $vectors
 */
function fakeEmbeddingResponse(array $vectors, string $model = 'text-embedding-3-small', bool $shuffle = false): array
{
    $data = [];
    foreach ($vectors as $i => $v) {
        $data[] = ['object' => 'embedding', 'index' => $i, 'embedding' => $v];
    }
    if ($shuffle) {
        $data = array_reverse($data);   // 故意打乱，模拟平台不保证顺序
    }
    return [
        'object' => 'list',
        'data'   => $data,
        'model'  => $model,
        'usage'  => ['prompt_tokens' => 10 * count($vectors), 'total_tokens' => 10 * count($vectors)],
    ];
}

// =====================================================================
echo "=== 一、能力声明与路径推导 ===\n\n";

$expectPaths = [
    'openai'     => '/v1/embeddings',
    'qwen'       => '/v1/embeddings',
    'zhipu'      => '/v4/embeddings',
    'doubao'     => '/api/v3/embeddings',
    'gemini'     => '/v1beta/openai/embeddings',
    'ernie'      => '/v2/embeddings',
    'azure'      => '/openai/v1/embeddings',
    'minimax'    => '/v1/embeddings',
    'perplexity' => '/embeddings',
];
foreach ($expectPaths as $key => $expected) {
    $class = \Ai\Helpers\Protocols::resolveClass($key);
    $p = new $class();
    $got = $p->capabilityPath(Capabilities::EMBEDDING);
    check($got === $expected, sprintf('  %-11s → %s', $key, $expected), $got);
}

// DeepSeek 官方文档明确只有 /chat/completions，v1.23.0 起不再声明向量化
check(!in_array(Capabilities::EMBEDDING, (new \Ai\Protocol\DeepSeek())->capabilities(), true),
      '  deepseek 不声明向量化（官方文档：只有 /chat/completions）');

// Anthropic 家族没有向量化接口，不能声明支持
foreach (['claude', 'qwen-anthropic', 'zhipu-anthropic'] as $key) {
    $class = \Ai\Helpers\Protocols::resolveClass($key);
    $p = new $class();
    check(!in_array(Capabilities::EMBEDDING, $p->capabilities(), true),
          "  {$key} 不声明向量化（Anthropic 无此接口）");
}

// =====================================================================
echo "\n=== 二、端点解析（含自定义网关）===\n\n";

// 绝对值断言：几个最常见的组合
$endpointCases = [
    ['openai', [], 'https://api.openai.com/v1/embeddings'],
    ['zhipu',  [], 'https://open.bigmodel.cn/api/paas/v4/embeddings'],
    ['openai', ['base_url' => 'https://my-gw.com/proxy/v1'], 'https://my-gw.com/proxy/v1/embeddings'],
    // 显式指定整条能力端点时优先
    ['openai', ['embedding_endpoint' => 'https://other.com/custom/embed'], 'https://other.com/custom/embed'],
];
foreach ($endpointCases as [$proto, $extra, $expected]) {
    list($ai) = makeAI($proto, 'text-embedding-3-small', $extra);
    $m = new ReflectionMethod($ai->embeddings(), 'endpoint');
    $m->setAccessible(true);
    $got = $m->invoke($ai->embeddings());
    $label = $proto . ($extra ? '（' . implode(',', array_keys($extra)) . '）' : '');
    check($got === $expected, "  {$label}", $got);
}

// 不变量断言：向量端点 == 对话端点把 chat/completions 换成 embeddings。
//
// 这条比硬编码 URL 更能防回归，也更贴合真实语义：base_url 的既有含义是
// 「换主机、保留厂商完整路径」（比如通义的默认路径含 /compatible-mode，
// 配了 base_url 之后这一段仍在），向量端点要做的就是忠实跟随对话端点，
// 而不是自己另拼一套——一旦另拼，用户配了自建网关却把数据发去了别处。
$invariantCases = [
    ['openai',    []],
    ['qwen',      []],
    ['qwen',      ['base_url' => 'https://relay.example.com/v1']],
    ['zhipu',     ['base_url' => 'https://gw.internal/zp']],
    ['doubao',    ['base_url' => 'https://gw.internal/db']],
    ['gemini',    ['base_url' => 'https://gw.internal/gm']],
    ['siliconflow', ['base_url' => 'https://gw.internal/sf']],
];
foreach ($invariantCases as [$proto, $extra]) {
    list($ai) = makeAI($proto, 'embed-model', $extra);
    $m = new ReflectionMethod($ai->embeddings(), 'endpoint');
    $m->setAccessible(true);
    $embed = $m->invoke($ai->embeddings());
    $chat  = $ai->resolveEndpoint();
    $want  = preg_replace('#/chat/completions$#', '/embeddings', $chat);

    $sameHost = parse_url($chat, PHP_URL_HOST) === parse_url($embed, PHP_URL_HOST);
    $label = $proto . ($extra ? '（自建网关）' : '');
    check($embed === $want && $sameHost, "  {$label}：向量端点与对话端点同源同前缀",
          "chat={$chat} embed={$embed}");
}

// 对话路径非标准形态的协议要单独断言：MiniMax 的对话路径是
// /v1/text/chatcompletion_v2，不是 .../chat/completions，靠猜后缀剥不掉。
// v1.15.0 就是在这里出的错——推导出了
// https://api.minimaxi.com/v1/text/chatcompletion_v2/v1/embeddings，
// 而上面那组不变量断言只覆盖标准路径的协议，没能拦住
$nonStandard = [
    ['minimax', 'https://api.minimaxi.com/v1/embeddings'],
];
foreach ($nonStandard as [$proto, $expected]) {
    list($ai) = makeAI($proto, 'embo-01');
    $m = new ReflectionMethod($ai->embeddings(), 'endpoint');
    $m->setAccessible(true);
    $got = $m->invoke($ai->embeddings());
    check($got === $expected, "  {$proto}（对话路径非标准形态）", $got);
}

// 自定义网关时**绝不能**回落官方地址：那等于把数据发到用户没指定的服务器
list($ai) = makeAI('openai', 'm', ['base_url' => 'https://private-gw.internal/v1']);
$m = new ReflectionMethod($ai->embeddings(), 'endpoint');
$m->setAccessible(true);
check(strpos($m->invoke($ai->embeddings()), 'api.openai.com') === false,
      '  自定义网关下不回落官方地址（安全）');

// =====================================================================
echo "\n=== 三、请求构建 ===\n\n";

list($ai, $fake) = makeAI('openai');
$fake->queuePost(fakeEmbeddingResponse([[0.1, 0.2]]));
$ai->embeddings()->create('单条文本');
$req = $fake->lastRequest();
check($req !== null && $req['data']['input'] === '单条文本',
      '单条输入发出字符串（兼容性最好的写法）',
      $req ? json_encode($req['data']['input'], JSON_UNESCAPED_UNICODE) : '');
check($req !== null && $req['data']['model'] === 'text-embedding-3-small', '自动带上当前模型名');
check($req !== null && strpos($req['url'], '/v1/embeddings') !== false, '打到向量化端点');

$fake->reset();
$fake->queuePost(fakeEmbeddingResponse([[0.1], [0.2], [0.3]]));
$ai->embeddings()->create(['a', 'b', 'c']);
$req = $fake->lastRequest();
check($req !== null && $req['data']['input'] === ['a', 'b', 'c'], '多条输入发出数组');

// 平台私有参数透传
$fake->reset();
$fake->queuePost(fakeEmbeddingResponse([[0.1]]));
$ai->embeddings()->create('x', ['dimensions' => 512, 'encoding_format' => 'float']);
$req = $fake->lastRequest();
check($req !== null && $req['data']['dimensions'] === 512, 'dimensions 原样透传');
check($req !== null && $req['data']['encoding_format'] === 'float', 'encoding_format 原样透传');

// 库内部控制项不能发给平台
$fake->reset();
$fake->queuePost(fakeEmbeddingResponse([[0.1]]));
$ai->embeddings()->create('x', ['batch_size' => 5]);
$req = $fake->lastRequest();
check($req !== null && !isset($req['data']['batch_size']), 'batch_size 是库内控制项，不发给平台');

// 扩展能力请求前必须清掉对话遗留的流式回调
$fake->reset();
$ai->setStreamCallback(function ($e) { });
$ai->setStream(true);
$fake->queuePost(fakeEmbeddingResponse([[0.1]]));
$ai->embeddings()->create('x');
check(!$fake->hasStreamCallback(), '清掉对话遗留的流式回调（否则会把响应当 SSE 解析）');

// =====================================================================
echo "\n=== 四、响应解析 ===\n\n";

list($ai, $fake) = makeAI('openai');
$fake->queuePost(fakeEmbeddingResponse([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]));
$res = $ai->embeddings()->create(['a', 'b']);

check($res instanceof EmbeddingResponse, '返回 EmbeddingResponse');
check($res->isSuccess(), 'isSuccess() 为 true');
check(count($res) === 2, 'count() 得到条数');
check($res->getDimensions() === 3, 'getDimensions() 正确');
check($res->getVector(0) === [0.1, 0.2, 0.3], '第 0 条向量正确');
check($res->getVector(1) === [0.4, 0.5, 0.6], '第 1 条向量正确');
check($res->getVector(9) === [], '越界返回空数组而不是报错');
check($res->getModel() === 'text-embedding-3-small', '模型名解析');
check($res->getUsage()['total_tokens'] === 20, 'usage 解析');
check($res->getCapability() === Capabilities::EMBEDDING, '能力标识正确');

// —— 关键：平台乱序返回时必须按 index 归位 ——
$fake->reset();
$fake->queuePost(fakeEmbeddingResponse([[1.0], [2.0], [3.0]], 'm', true));   // 响应顺序被反转
$res = $ai->embeddings()->create(['一', '二', '三']);
check($res->getVector(0) === [1.0], '**乱序响应按 index 归位**：第 0 条', json_encode($res->getVector(0)));
check($res->getVector(1) === [2.0], '**乱序响应按 index 归位**：第 1 条', json_encode($res->getVector(1)));
check($res->getVector(2) === [3.0], '**乱序响应按 index 归位**：第 2 条', json_encode($res->getVector(2)));

// base64 编码的向量
$floats = [0.5, -0.25, 1.5];
$packed = '';
foreach ($floats as $f) {
    $packed .= pack('g', $f);
}
$fake->reset();
$fake->queuePost([
    'data'  => [['index' => 0, 'embedding' => base64_encode($packed)]],
    'model' => 'm',
    'usage' => [],
]);
$res = $ai->embeddings()->create('x', ['encoding_format' => 'base64']);
$got = $res->getVector(0);
check(count($got) === 3 && abs($got[0] - 0.5) < 1e-6 && abs($got[1] + 0.25) < 1e-6,
      'base64 编码的向量能正确解开', json_encode($got));

// HTTP 200 但体内报错
$fake->reset();
$fake->queuePost(['error' => ['message' => '模型不存在'], 'data' => []]);
$res = $ai->embeddings()->create('x');
check(!$res->isSuccess(), '体内 error 时 isSuccess() 为 false');
check($res->getError() === '模型不存在', '错误信息透传', $res->getError());

// =====================================================================
echo "\n=== 五、自动分批 ===\n\n";

list($ai, $fake) = makeAI('openai');
// 7 条文本、每批 3 条 → 3 批（3 + 3 + 1）
$fake->queuePost(fakeEmbeddingResponse([[1.0], [2.0], [3.0]]))
     ->queuePost(fakeEmbeddingResponse([[4.0], [5.0], [6.0]]))
     ->queuePost(fakeEmbeddingResponse([[7.0]]));

$texts = ['t1', 't2', 't3', 't4', 't5', 't6', 't7'];
$res = $ai->embeddings()->create($texts, ['batch_size' => 3]);

check(count($fake->getRequests()) === 3, '7 条 / 每批 3 条 → 发出 3 个请求',
      (string) count($fake->getRequests()));
check(count($res) === 7, '合并后条数正确', (string) count($res));

// 顺序必须严格保持
$order = [];
for ($i = 0; $i < 7; $i++) {
    $v = $res->getVector($i);
    $order[] = $v ? $v[0] : null;
}
check($order === [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0],
      '**分批合并后顺序严格保持**', json_encode($order));

// 各批的文本要分对
$reqs = $fake->getRequests();
check($reqs[0]['data']['input'] === ['t1', 't2', 't3'], '第 1 批文本正确');
check($reqs[1]['data']['input'] === ['t4', 't5', 't6'], '第 2 批文本正确');
check($reqs[2]['data']['input'] === 't7', '第 3 批只剩 1 条，发字符串');

// usage 要累加，不能只剩最后一批
check($res->getUsage()['total_tokens'] === 30 + 30 + 10,
      'usage 逐批累加（30+30+10=70）', json_encode($res->getUsage()));

// 不指定 batch_size 时不分批
$fake->reset();
$fake->queuePost(fakeEmbeddingResponse(array_fill(0, 7, [1.0])));
$ai->embeddings()->create($texts);
check(count($fake->getRequests()) === 1, '不指定 batch_size 时一次发完（不无谓拆分）');

// 条数不足一批时也只发一次
$fake->reset();
$fake->queuePost(fakeEmbeddingResponse([[1.0], [2.0]]));
$ai->embeddings()->create(['a', 'b'], ['batch_size' => 10]);
check(count($fake->getRequests()) === 1, '条数少于批量上限时只发一次');

// —— 关键：返回条数与提交条数不符必须当场失败 ——
$fake->reset();
$fake->queuePost(fakeEmbeddingResponse([[1.0], [2.0], [3.0]]))
     ->queuePost(fakeEmbeddingResponse([[4.0]]));   // 提交 3 条只回 1 条
try {
    $ai->embeddings()->create(['a', 'b', 'c', 'd', 'e', 'f'], ['batch_size' => 3]);
    check(false, '**批内条数不符时当场失败**（否则后续下标全错位）', '未抛异常');
} catch (\Ai\Exceptions\RequestException $e) {
    check(strpos($e->getMessage(), '错位') !== false,
          '**批内条数不符时当场失败**（否则后续下标全错位）', $e->getMessage());
}

// 某一批失败要指出是第几批
$fake->reset();
$fake->queuePost(fakeEmbeddingResponse([[1.0], [2.0], [3.0]]))
     ->queuePost(['error' => ['message' => '限流'], 'data' => []]);
try {
    $ai->embeddings()->create(['a', 'b', 'c', 'd'], ['batch_size' => 3]);
    check(false, '批次失败时指出第几批', '未抛异常');
} catch (\Ai\Exceptions\RequestException $e) {
    check(strpos($e->getMessage(), '第 2/2 批') !== false, '批次失败时指出第几批', $e->getMessage());
}

// =====================================================================
echo "\n=== 六、跨平台一致性 ===\n\n";

// 同一套调用代码，在不同协议上应产出结构一致的请求
$platforms = ['openai', 'qwen', 'zhipu', 'doubao', 'moonshot', 'siliconflow', 'gemini', 'minimax'];
$shapes = [];
foreach ($platforms as $key) {
    list($ai, $fake) = makeAI($key, 'embed-model');
    $fake->queuePost(fakeEmbeddingResponse([[0.1, 0.2]]));
    $res = $ai->embeddings()->create('统一的调用代码');
    $req = $fake->lastRequest();
    $shapes[$key] = [
        'keys'    => $req ? implode(',', array_keys($req['data'])) : '',
        'input'   => $req ? $req['data']['input'] : null,
        'vectors' => $res->getVectors(),
    ];
}
$first = $shapes['openai'];
$diff = [];
foreach ($shapes as $key => $shape) {
    if ($shape['keys'] !== $first['keys'] || $shape['input'] !== $first['input']
        || $shape['vectors'] !== $first['vectors']) {
        $diff[] = $key;
    }
}
check($diff === [], count($platforms) . ' 个平台的请求结构与解析结果完全一致', implode(',', $diff));

// 不支持的协议要明确报错，不能静默返回空
list($ai) = makeAI('claude', 'claude-3-opus');
try {
    $ai->embeddings()->create('x');
    check(false, 'Claude 不支持时抛异常', '未抛出');
} catch (\Ai\Exceptions\UnsupportedCapabilityException $e) {
    check(strpos($e->getMessage(), '文本向量化') !== false,
          'Claude 不支持时抛异常并点名能力', $e->getMessage());
}

// 空输入
list($ai, $fake) = makeAI('openai');
try {
    $ai->embeddings()->create('');
    check(false, '空输入报错', '未抛出');
} catch (\Ai\Exceptions\RequestException $e) {
    check(true, '空输入报错');
}

// =====================================================================
echo "\n" . str_repeat('=', 62) . "\n";
if ($failures) {
    echo count($failures) . " 项未通过：\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "全部通过：同一套代码可在 35 个平台上做文本向量化\n";
exit(0);
