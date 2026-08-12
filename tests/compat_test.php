<?php
/**
 * 向后兼容测试
 *
 * 本库有生产环境在使用，多模态开发的第一原则是「已发布的行为不能变」。
 * 这份测试就是那条原则的可执行版本，专门盯三件事：
 *
 *   1) CurlTransport::post() 加了 Content-Type 分流后，**默认路径必须逐字节不变**
 *      —— 这是全部对话请求的必经之路，本阶段风险最高的改动
 *   2) 已发布接口/类的**已有方法参数表**没有被改动
 *      —— v1.8.0 给 AIResponse::cost() 加了个参数，子类覆写随即 Fatal，整个库挂掉
 *   3) 只实现了旧版 6 个方法的协议类，加一行 use 之后仍能正常工作
 *      —— ProtocolInterface 扩展后，老代码的迁移成本必须是「一行」
 *
 * 运行：php tests/compat_test.php
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/fixtures/FakeTransport.php';

use Ai\AI;
use Ai\Contracts\AIResponseInterface;
use Ai\Contracts\ProtocolInterface;
use Ai\Helpers\Media;
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
    echo pad($name, 56), $ok ? "✓\n" : "✗ {$detail}\n";
}

// =====================================================================
// 一、请求体编码：默认路径必须与改造前逐字节一致
// =====================================================================
echo "=== 一、默认请求路径逐字节等价 ===\n\n";

$transport = new CurlTransport();
$encode = new ReflectionMethod($transport, 'encodeRequestBody');
$encode->setAccessible(true);

/**
 * 改造前 post() 里的原始写法，作为对照基准
 * @param array<string, mixed> $data
 */
function legacyEncode(array $data): string
{
    return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$chatPayloads = [
    '纯文本对话'       => ['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => '你好']]],
    '流式对话'         => ['model' => 'deepseek-chat', 'messages' => [['role' => 'user', 'content' => 'hi']], 'stream' => true],
    '含斜杠与中文'     => ['model' => 'x', 'messages' => [['role' => 'user', 'content' => 'https://a.com/p?x=1 中文']]],
    '工具调用定义'     => ['model' => 'claude-3-opus', 'tools' => [['name' => 'f', 'input_schema' => ['type' => 'object', 'properties' => []]]]],
    '深层嵌套'         => ['a' => ['b' => ['c' => ['d' => '值', 'e' => [1, 2, 3]]]]],
    '数值参数'         => ['temperature' => 0.7, 'top_p' => 1, 'max_tokens' => 4096, 'seed' => 0],
    '空数组'           => ['messages' => [], 'tools' => []],
];
foreach ($chatPayloads as $name => $payload) {
    $headers = [];
    $got = $encode->invokeArgs($transport, [$payload, &$headers]);
    check($got === legacyEncode($payload), "无 Content-Type：{$name}");
}

// 显式声明 JSON 的各种写法都要落回默认路径
foreach (['application/json', 'application/json; charset=utf-8', 'APPLICATION/JSON'] as $ct) {
    foreach (['Content-Type', 'content-type', 'CONTENT-TYPE'] as $key) {
        $headers = [$key => $ct];
        $payload = ['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => '中文/斜杠']]];
        $got = $encode->invokeArgs($transport, [$payload, &$headers]);
        check($got === legacyEncode($payload), "显式 JSON：{$key}: {$ct}");
    }
}

// 编码失败时仍抛出原来那个异常
$headers = [];
try {
    $encode->invokeArgs($transport, [['bad' => "\xB4\xF3"], &$headers]);
    check(false, '非 UTF-8 内容仍抛 json_encode_failed', '未抛异常');
} catch (\Ai\Exceptions\RequestException $e) {
    check(strpos($e->getMessage(), '请求体 JSON 编码失败') === 0,
          '非 UTF-8 内容仍抛 json_encode_failed', $e->getMessage());
}

// =====================================================================
// 二、响应解码：只有二进制媒体才跳过 json_decode
// =====================================================================
echo "\n=== 二、响应解码白名单 ===\n\n";

// 用白名单而不是「不是 JSON 就当二进制」：网关异常返回 text/html 时，
// 旧行为是 json_decode 失败后返回空数组，这个行为必须保住
$expectations = [
    'application/json'         => false,
    'application/json; utf-8'  => false,
    'text/event-stream'        => false,   // 流式响应
    'text/html'                => false,   // 网关错误页
    'text/plain'               => false,
    ''                         => false,   // 服务端未声明
    'audio/mpeg'               => true,
    'audio/wav'                => true,
    'image/png'                => true,
    'video/mp4'                => true,
    'application/octet-stream' => true,
];
foreach ($expectations as $ct => $isBinary) {
    check(Media::isBinaryContentType($ct) === $isBinary,
          sprintf('  %-26s → %s', $ct === '' ? '(未声明)' : $ct, $isBinary ? '原始字节' : 'json_decode'));
}

// =====================================================================
// 三、已发布接口的方法签名快照
// =====================================================================
echo "\n=== 三、已有方法签名未被改动 ===\n\n";

/**
 * 取类/接口自身声明的公共方法签名
 * @return array<string, string>
 */
function signatures(string $class): array
{
    $r = new ReflectionClass($class);
    $out = [];
    foreach ($r->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if (!$r->isInterface() && $m->getDeclaringClass()->getName() !== $r->getName()) {
            continue;
        }
        $params = [];
        foreach ($m->getParameters() as $p) {
            $t = $p->getType();
            $tn = $t ? (($t->allowsNull() && $t->getName() !== 'mixed' ? '?' : '') . $t->getName()) : '';
            $params[] = trim($tn . ($p->isVariadic() ? '...' : '') . ' $' . $p->getName() . ($p->isOptional() ? '=' : ''));
        }
        $rt = $m->getReturnType();
        $out[$m->getName()] = implode(', ', $params) . ' : '
            . ($rt ? (($rt->allowsNull() && $rt->getName() !== 'mixed' ? '?' : '') . $rt->getName()) : 'void?');
    }
    ksort($out);
    return $out;
}

// 快照硬编码在这里。**新增方法允许**（只会多一个键），
// 改动或删除已有方法会立刻让这份测试变红
$snapshots = [
    'Ai\Contracts\AIResponseInterface' => [
        'getContent'         => ' : string',
        'getError'           => ' : string',
        'getModel'           => ' : string',
        'getRaw'             => ' : array',
        'getStopReason'      => ' : string',
        'getToolCalls'       => ' : array',
        'getUsage'           => ' : array',
        'hasToolCalls'       => ' : bool',
        'isSuccess'          => ' : bool',
        'toAssistantMessage' => ' : array',
    ],
    'Ai\Contracts\TransportInterface' => [
        'get'               => 'string $url, array $params=, array $headers= : array',
        'post'              => 'string $url, array $data, array $headers= : array',
        'setProxy'          => 'string $proxy : self',
        'setStreamCallback' => '?callable $callback : self',
        'setTimeout'        => 'int $timeout : self',
    ],
    'Ai\Contracts\ModelInterface' => [
        'getConfig'          => ' : array',
        'getEndpoint'        => ' : string',
        'getFeatures'        => ' : array',
        'getName'            => ' : string',
        'getPlatform'        => ' : string',
        'getProtocol'        => ' : string',
        'processAttachments' => 'array $payload, array $attachments : array',
        'supports'           => 'string $feature : bool',
    ],
    'Ai\Response\AIResponse' => [
        '__construct'        => 'array $data : void?',
        '__toString'         => ' : string',
        'cost'               => 'array $pricing=, int $perTokens= : float',
        'costPerMillion'     => 'array $pricing= : float',
        'getContent'         => ' : string',
        'getError'           => ' : string',
        'getModel'           => ' : string',
        'getRaw'             => ' : array',
        'getStopReason'      => ' : string',
        'getToolCalls'       => ' : array',
        'getUsage'           => ' : array',
        'hasToolCalls'       => ' : bool',
        'isSuccess'          => ' : bool',
        'toArray'            => ' : array',
        'toAssistantMessage' => ' : array',
        'tokens'             => ' : int',
    ],
    // ProtocolInterface 本次刻意扩展了 4 个能力方法，
    // 但**原有 6 个的签名一个都不能动**
    'Ai\Contracts\ProtocolInterface' => [
        'buildHeaders'      => 'array $config : array',
        'buildRequest'      => 'array $payload : array',
        'isStreamEnd'       => 'array $chunk : bool',
        'listModels'        => 'array $config, $transport : ?array',
        'parseResponse'     => 'array $response : Ai\Contracts\AIResponseInterface',
        'parseStreamChunk'  => 'array $chunk : ?string',
    ],
];

foreach ($snapshots as $class => $expected) {
    $actual = signatures($class);
    foreach ($expected as $method => $sig) {
        if (!isset($actual[$method])) {
            check(false, "{$class}::{$method}", '方法被删除');
            continue;
        }
        check($actual[$method] === $sig, "{$class}::{$method}",
              $actual[$method] === $sig ? '' : "签名已变：{$actual[$method]}");
    }
}

// =====================================================================
// 四、旧式协议类的迁移成本必须是「一行」
// =====================================================================
echo "\n=== 四、旧式协议类兼容 ===\n\n";

/**
 * 模拟用户在 v1.13.0 时代自己写的协议类：
 * 裸实现 ProtocolInterface，只有当时的 6 个方法。
 * 升级后**只加了一行 use**，其余一字未动
 */
class LegacyUserProtocol implements ProtocolInterface
{
    use \Ai\Protocol\Concerns\CapabilityDefaults;   // ← 用户需要加的唯一一行

    public function buildRequest(array $payload): array
    {
        return ['model' => $payload['model'] ?? '', 'messages' => $payload['messages'] ?? []];
    }

    public function parseResponse(array $response): AIResponseInterface
    {
        return new \Ai\Response\AIResponse([
            'content' => $response['text'] ?? '',
            'model'   => 'legacy-model',
            'usage'   => [],
            'raw'     => $response,
        ]);
    }

    public function buildHeaders(array $config): array
    {
        return ['Content-Type' => 'application/json'];
    }

    public function parseStreamChunk(array $chunk): ?string
    {
        return isset($chunk['text']) ? (string) $chunk['text'] : null;
    }

    public function isStreamEnd(array $chunk): bool
    {
        return !empty($chunk['done']);
    }

    public function listModels(array $config, $transport): ?array
    {
        return null;
    }
}

$legacy = new LegacyUserProtocol();
check($legacy instanceof ProtocolInterface, '旧式协议类仍满足 ProtocolInterface');
check($legacy->capabilities() === [], 'capabilities() 默认返回空数组');
check($legacy->capabilityPath('image') === '', 'capabilityPath() 默认返回空串');
check($legacy->buildRequest(['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'x']]])
      === ['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'x']]],
      '原有 buildRequest 行为未受影响');
try {
    $legacy->buildCapabilityRequest('image', []);
    check(false, '调用未声明的能力时抛异常', '未抛出');
} catch (\Ai\Exceptions\UnsupportedCapabilityException $e) {
    check(strpos($e->getMessage(), '不支持') !== false, '调用未声明的能力时抛异常（而非静默返回空）');
}

// =====================================================================
// 五、内置协议类：40 个协议全部仍可正常构建对话请求
// =====================================================================
echo "\n=== 五、内置协议对话链路未受影响 ===\n\n";

$protocolFiles = glob(__DIR__ . '/../src/Protocol/*.php') ?: [];
$okCount = 0;
$total = 0;
$broken = [];
foreach ($protocolFiles as $file) {
    $class = 'Ai\\Protocol\\' . basename($file, '.php');
    if (!class_exists($class)) {
        continue;   // ModelCatalog 是 trait
    }
    $total++;
    try {
        $p = new $class();
        $req = $p->buildRequest(['model' => 'test-model', 'messages' => [['role' => 'user', 'content' => '你好']]]);
        $headers = $p->buildHeaders(['api_key' => 'sk-test']);
        if (is_array($req) && $req && is_array($headers) && $headers) {
            $okCount++;
        } else {
            $broken[] = $class . '：构建结果为空';
        }
    } catch (\Throwable $e) {
        $broken[] = $class . '：' . $e->getMessage();
    }
}
check($okCount === $total && $total > 0, "全部 {$total} 个内置协议仍能构建对话请求",
      $broken ? implode('; ', array_slice($broken, 0, 3)) : '');

// 每个协议都通过 trait 拿到了能力方法
$missing = [];
foreach ($protocolFiles as $file) {
    $class = 'Ai\\Protocol\\' . basename($file, '.php');
    if (!class_exists($class)) {
        continue;
    }
    foreach (['capabilities', 'buildCapabilityRequest', 'parseCapabilityResponse', 'capabilityPath'] as $m) {
        if (!method_exists($class, $m)) {
            $missing[] = "{$class}::{$m}";
        }
    }
}
check($missing === [], '全部内置协议都具备 4 个能力方法（经由 trait 继承）',
      implode(', ', array_slice($missing, 0, 3)));

// =====================================================================
// 六、AI 对话主流程：注入假传输层跑通一次
// =====================================================================
echo "\n=== 六、AI::chat() 主流程 ===\n\n";

$fake = new FakeTransport();
$fake->queuePost([
    'id'      => 'chatcmpl-1',
    'model'   => 'gpt-4o',
    'choices' => [['message' => ['role' => 'assistant', 'content' => '你好，我是助手'], 'finish_reason' => 'stop']],
    'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
]);

$ai = new AI(['api_key' => 'sk-test', 'model' => 'gpt-4o']);
$ai->setTransport($fake);
$resp = $ai->chat('你好');

check($resp->getContent() === '你好，我是助手', 'chat() 返回内容正确', $resp->getContent());
check($resp->getUsage()['total_tokens'] === 15, 'usage 解析正确');
// 库内统一用 Anthropic 风格的结束原因，OpenAI 的 stop 会被归一成 end_turn。
// 这是已发布的行为，跨平台一致性就靠它，同样不能变
check($resp->getStopReason() === 'end_turn', 'stop_reason 归一为 Anthropic 风格', $resp->getStopReason());
$sent = $fake->lastRequest();
check($sent !== null && isset($sent['data']['messages'][0]['content']), '请求体结构正常');
check($sent !== null && strpos($sent['url'], '/chat/completions') !== false, '对话端点未被改动', $sent ? $sent['url'] : '');

// 扩展能力入口存在且不影响对话
check(method_exists($ai, 'images') && method_exists($ai, 'audio')
      && method_exists($ai, 'video') && method_exists($ai, 'embeddings')
      && method_exists($ai, 'realtime'), '五个扩展能力入口已就位');
// 协议声明扩展能力之后，对话链路必须一点不受影响。
// 断言的是「加了能力不影响 chat」这个持久性质，而不是某一版的能力清单快照——
// 快照会随每期交付过期，性质不会
$fake2 = new FakeTransport();
$fake2->queuePost([
    'model'   => 'gpt-4o',
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
    'usage'   => ['total_tokens' => 3],
]);
$ai2 = new AI(['api_key' => 'sk-test', 'model' => 'gpt-4o']);
$ai2->setTransport($fake2);
$before = $ai2->resolveEndpoint();
$ai2->chat('你好');
$sent2 = $fake2->lastRequest();
check($sent2 !== null && $sent2['url'] === $before, '声明扩展能力后对话端点不变', $before);
check($sent2 !== null && array_keys($sent2['data']) === array_keys($sent['data']),
      '声明扩展能力后对话请求体结构不变');
check(!isset($sent2['data']['input']), '对话请求体不含向量化字段（两条链路互不串味）');

// =====================================================================
echo "\n" . str_repeat('=', 64) . "\n";
if ($failures) {
    echo count($failures) . " 项未通过：\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "全部通过：已发布的行为未发生任何改变\n";
exit(0);
