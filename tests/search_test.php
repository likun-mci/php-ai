<?php
/**
 * 联网搜索统一配置测试
 *
 * 全离线，用 FakeTransport 捕获请求体，断言统一配置被翻译成了各平台的真实写法。
 * 各平台的字段名与结构均以官方文档为准，出处写在对应协议类的 applyWebSearch() 注释里。
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/fixtures/FakeTransport.php';

use Ai\AI;
use Ai\Exceptions\ConfigException;
use Ai\Helpers\Protocols;
use Ai\Helpers\Tools;
use Ai\Helpers\WebSearch;
use Tests\Fixtures\FakeTransport;

$passed = 0;
$failed = 0;

/**
 * @param mixed $actual
 */
function check(bool $ok, string $name, $actual = null): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ✓ {$name}\n";
        return;
    }
    $failed++;
    echo "  ✗ {$name}";
    if ($actual !== null) {
        echo ' —— 实际: ' . (is_scalar($actual) ? $actual : json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    echo "\n";
}

/**
 * 发一次请求，返回捕获到的请求体
 *
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function captureRequest(string $protocol, string $model, array $config): array
{
    $fake = new FakeTransport();
    $fake->queuePost([
        'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        'content' => [['type' => 'text', 'text' => 'ok']],
    ]);
    $ai = new AI(array_merge(['api_key' => 'sk-test', 'model' => $model, 'protocol' => $protocol], $config));
    $ai->setTransport($fake);
    $ai->chat('今天有什么新闻？');
    $requests = $fake->getRequests();
    return $requests ? $requests[0]['data'] : [];
}

echo "\n=== 一、配置归一化 ===\n\n";

check(WebSearch::normalize(true) === ['enable' => true], 'true → 开启');
check(WebSearch::normalize(false) === null, 'false → 不开启');
check(WebSearch::normalize(null) === null, 'null → 不开启');
check(WebSearch::normalize([]) === ['enable' => true], '空数组 → 按默认开启');
check(WebSearch::normalize(['enable' => false, 'count' => 5]) === null, '显式 enable:false 压过其它配置项');
check(WebSearch::normalize(['count' => 3]) === ['enable' => true, 'count' => 3], '省略 enable 视为开启');
check(WebSearch::normalize(['count' => '7'])['count'] === 7, 'count 转成整数');
check(WebSearch::normalize(['count' => 0])['count'] === 1, 'count 下限截断到 1');
check(WebSearch::normalize(['recency' => 'WEEK'])['recency'] === 'week', 'recency 大小写归一');
check(
    WebSearch::normalize(['allowed_domains' => 'a.com'])['allowed_domains'] === ['a.com'],
    '单个域名字符串包装成数组'
);

try {
    WebSearch::normalize(['enable_search' => true]);
    check(false, '未知配置项报错', '未抛异常');
} catch (ConfigException $e) {
    check(strpos($e->getMessage(), 'extra_body') !== false, '未知配置项报错并指向 extra_body', $e->getMessage());
}

try {
    WebSearch::normalize(['recency' => 'decade']);
    check(false, 'recency 非法取值报错', '未抛异常');
} catch (ConfigException $e) {
    check(true, 'recency 非法取值报错');
}

try {
    WebSearch::normalize(['allowed_domains' => ['a.com'], 'blocked_domains' => ['b.com']]);
    check(false, '域名黑白名单互斥报错', '未抛异常');
} catch (ConfigException $e) {
    check(true, '域名黑白名单互斥报错（Claude 同时收到两者会 400）');
}

echo "\n=== 二、各平台的翻译结果 ===\n\n";

// —— Claude：tools 里的服务端工具 ——
$req = captureRequest('claude', 'claude-sonnet-4-20250514', ['search' => true]);
check(
    isset($req['tools'][0]) && $req['tools'][0] === ['type' => 'web_search_20250305', 'name' => 'web_search'],
    'Claude：最简配置生成官方服务端工具',
    $req['tools'] ?? null
);

$req = captureRequest('claude', 'claude-sonnet-4-20250514', [
    'search' => ['max_uses' => 3, 'blocked_domains' => ['spam.com']],
]);
check(($req['tools'][0]['max_uses'] ?? null) === 3, 'Claude：max_uses 映射', $req['tools'][0] ?? null);
check(($req['tools'][0]['blocked_domains'] ?? null) === ['spam.com'], 'Claude：blocked_domains 映射');

// 用户自定义工具必须保留
$req = captureRequest('claude', 'claude-sonnet-4-20250514', [
    'search' => true,
    'tools'  => [['name' => 'get_weather', 'description' => '查天气', 'input_schema' => ['type' => 'object']]],
]);
check(count($req['tools'] ?? []) === 2, 'Claude：搜索工具追加而非覆盖用户工具', $req['tools'] ?? null);
check(($req['tools'][0]['name'] ?? '') === 'get_weather', '  用户工具仍在首位');

// —— 通义千问：顶层 enable_search ——
$req = captureRequest('qwen', 'qwen-plus', ['search' => true]);
check(($req['enable_search'] ?? null) === true, '通义：enable_search 落到请求体顶层', $req);
check(!isset($req['search_options']), '  未配细项时不发空的 search_options');

$req = captureRequest('qwen', 'qwen-plus', [
    'search' => ['forced' => true, 'citation' => true, 'sources' => true],
]);
check(
    ($req['search_options'] ?? null) === [
        'forced_search'   => true,
        'enable_source'   => true,
        'enable_citation' => true,
    ],
    '通义：forced / citation / sources 映射到 search_options',
    $req['search_options'] ?? null
);

// —— 智谱：tools[web_search] ——
$req = captureRequest('zhipu', 'glm-4-plus', [
    'search' => ['count' => 5, 'recency' => 'week', 'query' => 'PHP 8.5', 'forced' => true],
]);
$ws = $req['tools'][0]['web_search'] ?? [];
check(($req['tools'][0]['type'] ?? '') === 'web_search', '智谱：生成 web_search 类型工具', $req['tools'][0] ?? null);
check(($ws['enable'] ?? null) === true, '  enable:true');
check(($ws['count'] ?? null) === 5, '  count 映射');
check(($ws['search_recency_filter'] ?? '') === 'oneWeek', '  recency → 智谱的驼峰写法 oneWeek', $ws);
check(($ws['search_query'] ?? '') === 'PHP 8.5', '  query → search_query');
check(($ws['require_search'] ?? null) === true, '  forced → require_search');

check(WebSearch::recencyToZhipu('hour') === 'oneDay', '智谱无「一小时内」，hour 并到 oneDay');

// —— Kimi：builtin_function ——
$req = captureRequest('moonshot', 'kimi-k2-0905-preview', ['search' => true]);
check(
    ($req['tools'][0] ?? null) === ['type' => 'builtin_function', 'function' => ['name' => '$web_search']],
    'Kimi：生成 builtin_function 声明',
    $req['tools'][0] ?? null
);

// —— 文心：顶层 web_search 对象 ——
$req = captureRequest('ernie', 'ernie-4.5-turbo-128k', [
    'search' => ['citation' => true, 'count' => 50],
]);
check(($req['web_search']['enable'] ?? null) === true, '文心：顶层 web_search.enable', $req['web_search'] ?? null);
check(($req['web_search']['enable_citation'] ?? null) === true, '  citation 映射');
check(($req['web_search']['search_number'] ?? null) === 10, '  count 超过官方上限 10 时截断');

// —— OpenRouter：plugins ——
$req = captureRequest('openrouter', 'openai/gpt-4o', [
    'search' => ['count' => 3, 'allowed_domains' => ['wikipedia.org']],
]);
check(($req['plugins'][0]['id'] ?? '') === 'web', 'OpenRouter：生成 web 插件', $req['plugins'] ?? null);
check(($req['plugins'][0]['max_results'] ?? null) === 3, '  count → max_results');
check(($req['plugins'][0]['include_domains'] ?? null) === ['wikipedia.org'], '  allowed_domains → include_domains');
check(!isset($req['model']) || strpos((string) $req['model'], ':online') === false, '  不改动用户填的模型名');

// —— Perplexity：顶层过滤参数 ——
$req = captureRequest('perplexity', 'sonar-pro', [
    'search' => ['recency' => 'day', 'blocked_domains' => ['spam.com']],
]);
check(($req['search_recency_filter'] ?? '') === 'day', 'Perplexity：recency 取值与统一配置一致', $req);
check(
    ($req['search_domain_filter'] ?? null) === ['-spam.com'],
    '  blocked_domains → 带减号前缀的 search_domain_filter',
    $req['search_domain_filter'] ?? null
);

echo "\n=== 三、不支持的平台要明确报错 ===\n\n";

// OpenAI 的 Chat Completions 端点没有搜索开关，只有 gpt-5-search-api 这类专用模型
try {
    captureRequest('openai', 'gpt-4o', ['search' => true]);
    check(false, 'OpenAI：配了 search 应当报错', '未抛异常');
} catch (ConfigException $e) {
    check(true, 'OpenAI：Chat Completions 无搜索开关，明确报错');
    check(strpos($e->getMessage(), 'extra_body') !== false, '  报错里指向 extra_body 逃生口', $e->getMessage());
    check(strpos($e->getMessage(), 'claude') !== false, '  报错里列出可用平台', $e->getMessage());
}

try {
    captureRequest('deepseek', 'deepseek-chat', ['search' => true]);
    check(false, 'DeepSeek：配了 search 应当报错', '未抛异常');
} catch (ConfigException $e) {
    check(true, 'DeepSeek：官方接口无联网搜索，明确报错');
}

// 显式关掉的不该触发报错
$req = captureRequest('openai', 'gpt-4o', ['search' => false]);
check(!isset($req['enable_search']) && !empty($req['model']), '不支持的平台上 search:false 不报错、正常发请求');

echo "\n=== 四、Anthropic 兼容网关不得继承 Claude 的声明 ===\n\n";

foreach (['qwen-anthropic' => '百炼', 'zhipu-anthropic' => '智谱', 'moonshot-anthropic' => 'Kimi'] as $key => $vendor) {
    check(
        Protocols::supportsWebSearch($key) === false,
        "{$key}：{$vendor} 自建网关不声明支持（Anthropic 的服务端工具不随协议格式过来）"
    );
}
check(Protocols::supportsWebSearch('claude') === true, 'claude：官方端点仍然支持');

$expected = ['claude', 'qwen', 'ernie', 'zhipu', 'moonshot', 'perplexity', 'openrouter'];
$actual = Protocols::withWebSearch();
sort($expected);
sort($actual);
check($expected === $actual, '协议清单与逐个声明一致', $actual);

echo "\n=== 五、服务端工具不再被静默丢弃 ===\n\n";

// 修复前：无 name 的服务端工具会被 toOpenAiDefs 丢掉，还留下一个空 tools 数组
$defs = Tools::toOpenAiDefs([['type' => 'web_search']]);
check($defs === [['type' => 'web_search']], 'toOpenAiDefs：服务端工具原样放行', $defs);

$defs = Tools::toOpenAiDefs([['type' => 'web_search', 'filters' => ['allowed_domains' => ['a.com']]]]);
check(($defs[0]['filters'] ?? null) !== null, '  带参数的服务端工具不丢字段', $defs);

// Claude 的服务端工具同时带 name，不能被当成统一格式改写
$claudeTool = ['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5];
$defs = Tools::toClaudeDefs([$claudeTool]);
check($defs === [$claudeTool], 'toClaudeDefs：带 name 的服务端工具不被改写', $defs);
$defs = Tools::toOpenAiDefs([$claudeTool]);
check($defs === [$claudeTool], 'toOpenAiDefs：同上，不被误当成函数工具', $defs);

// 普通函数工具的转换不能受影响
$defs = Tools::toOpenAiDefs([['name' => 'f', 'description' => 'd', 'input_schema' => ['type' => 'object']]]);
check(($defs[0]['type'] ?? '') === 'function', '统一格式的函数工具仍正常转换', $defs);
check(($defs[0]['function']['name'] ?? '') === 'f', '  函数名保留');

// 全部无法识别时不留空数组
$req = captureRequest('openai', 'gpt-4o', ['tools' => [['description' => '没有 name 也没有 type']]]);
check(!array_key_exists('tools', $req), '工具定义全部无法识别时不发空的 tools 数组', $req);

$req = captureRequest('claude', 'claude-sonnet-4-20250514', ['tools' => [['description' => '碎片']]]);
check(!array_key_exists('tools', $req), '  Claude 协议同上', $req);

echo "\n=== 六、与 extra_body 逃生口并存 ===\n\n";

// 平台独有参数仍可用 extra_body 补，且优先级更高
$req = captureRequest('qwen', 'qwen-plus', [
    'search'     => ['forced' => true],
    'extra_body' => ['search_options' => ['search_strategy' => 'max']],
]);
check(($req['enable_search'] ?? null) === true, 'extra_body 不影响统一配置生成的其它字段');
check(
    ($req['search_options'] ?? null) === ['search_strategy' => 'max'],
    'extra_body 里的同名字段整体覆盖统一配置的结果',
    $req['search_options'] ?? null
);

// 不支持的平台用 extra_body 照样能发平台原生参数
$req = captureRequest('openai', 'gpt-4o', [
    'extra_body' => ['tools' => [['type' => 'web_search']]],
]);
check(($req['tools'][0]['type'] ?? '') === 'web_search', '不支持的平台仍可用 extra_body 直发原生参数', $req);

echo "\n" . str_repeat('=', 46) . "\n";
echo "通过 {$passed} 项，失败 {$failed} 项\n";
exit($failed > 0 ? 1 : 0);
