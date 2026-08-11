<?php
/**
 * 流式 / 非流式基础能力回归测试
 *
 * 不联网、不需要任何 API Key：把各平台真实的 SSE 报文喂进完整的
 * AI::chat() 流程（AI 层 → 协议层 → 传输层的 SSE 解析全部走真实代码），
 * 逐个校验 40 个协议的三项基础能力：
 *
 *   1) 普通对话   —— parseResponse 能取出正文
 *   2) 流式对话   —— SSE 分片能被正确重组并累积成完整正文
 *   3) token 统计 —— prompt_tokens / completion_tokens 能正确归一
 *
 * 运行：php tests/stream_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Helpers\Protocols;
use Ai\Transport\CurlTransport;

/**
 * 回放预置 SSE 字节的假传输层
 * 继承自 CurlTransport，复用其真实的 SSE 解析逻辑，只把网络 IO 换成回放
 */
class ReplayTransport extends CurlTransport
{
    /** @var string 要回放的响应体（流式为 SSE 文本，非流式为 JSON） */
    public $body = '';

    /** @var int 每次投喂的字节数，故意切碎以暴露跨包重组问题 */
    public $chunkSize = 5;

    public function post(string $url, array $data, array $headers = []): array
    {
        $ref = new ReflectionClass(CurlTransport::class);
        foreach (['streamBuffer' => '', 'streamFullContent' => '', 'streamError' => ''] as $name => $init) {
            $p = $ref->getProperty($name); $p->setAccessible(true); $p->setValue($this, $init);
        }
        $p = $ref->getProperty('streamLastUsage'); $p->setAccessible(true); $p->setValue($this, []);

        $cb = $ref->getProperty('streamCallback'); $cb->setAccessible(true);
        if ($cb->getValue($this) === null) {
            return json_decode($this->body, true) ?: [];      // 非流式
        }

        $feed  = new ReflectionMethod($this, 'handleStreamData'); $feed->setAccessible(true);
        foreach (str_split($this->body, $this->chunkSize) as $part) {
            $feed->invoke($this, $part);
        }
        $flush = new ReflectionMethod($this, 'flushStreamBuffer'); $flush->setAccessible(true);
        $flush->invoke($this);
        return [];
    }
}

/** 造一个把传输层换成回放器的 AI 实例 */
function replayAI(array $config, string $body, bool $stream): AI
{
    $ai = AI::create($config);
    $tr = new ReplayTransport();
    $tr->body = $body;
    $ai->setTransport($tr);          // 公开 API 注入，无需反射
    if ($stream) {
        // 注册回调，避免测试时把 SSE 直接 echo 到标准输出
        $ai->setStream(true)->setStreamCallback(function ($event) {});
    }
    return $ai;
}

function pad(string $text, int $width): string
{
    $n = $width - mb_strwidth($text, 'UTF-8');
    return $text . ($n > 0 ? str_repeat(' ', $n) : '');
}

$failures = [];
function check(bool $ok, string $name, string $detail = ''): void
{
    global $failures;
    if (!$ok) { $failures[] = $name . ($detail !== '' ? " （{$detail}）" : ''); }
}

// ---------------------------------------------------------------
// 各协议家族的真实报文样本
// ---------------------------------------------------------------
$SSE_OPENAI =
      "data: {\"choices\":[{\"delta\":{\"content\":\"你\"}}]}\n\n"
    . "data: {\"choices\":[{\"delta\":{\"content\":\"好\"}}]}\n\n"
    . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}],"
    . "\"usage\":{\"prompt_tokens\":9,\"completion_tokens\":2,\"total_tokens\":11}}\n\n"
    . "data: [DONE]\n\n";

// Anthropic：用量拆在两帧下发，input 在 message_start，output 在 message_delta
$SSE_CLAUDE =
      "event: message_start\n"
    . "data: {\"type\":\"message_start\",\"message\":{\"usage\":{\"input_tokens\":9,\"output_tokens\":1}}}\n\n"
    . "event: content_block_delta\n"
    . "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"你\"}}\n\n"
    . "event: content_block_delta\n"
    . "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"好\"}}\n\n"
    . "event: message_delta\n"
    . "data: {\"type\":\"message_delta\",\"usage\":{\"output_tokens\":2}}\n\n"
    . "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n";

$JSON_OPENAI = json_encode([
    'choices' => [['message' => ['content' => '你好']]],
    'usage'   => ['prompt_tokens' => 9, 'completion_tokens' => 2, 'total_tokens' => 11],
]);
$JSON_CLAUDE = json_encode([
    'content' => [['type' => 'text', 'text' => '你好']],
    'usage'   => ['input_tokens' => 9, 'output_tokens' => 2],
]);

// ---------------------------------------------------------------
// 一、40 个协议 × 普通对话 / 流式对话 / token 统计
// ---------------------------------------------------------------
echo "=== 40 个协议的基础对话能力 ===\n\n";
echo pad('协议', 20), pad('普通对话', 11), pad('流式对话', 11), "token 统计\n";
echo str_repeat('-', 62), "\n";

foreach (array_keys(Protocols::all()) as $key) {
    $isClaude = is_a(Protocols::resolveClass($key), 'Ai\Protocol\Claude', true);
    $config   = ['protocol' => $key, 'model' => 'test-model', 'api_key' => 'k'];
    if (Protocols::baseUrlOf($key) === '') {
        $config['base_url'] = 'https://example.com';    // Azure 等无公共域名的平台
    }

    $plain  = replayAI($config, $isClaude ? $JSON_CLAUDE : $JSON_OPENAI, false)->chat('hi');
    $stream = replayAI($config, $isClaude ? $SSE_CLAUDE  : $SSE_OPENAI,  true)->chat('hi');
    $usage  = $stream->getUsage();

    $okPlain  = $plain->getContent() === '你好';
    $okStream = $stream->getContent() === '你好';
    $okUsage  = (int)($usage['prompt_tokens'] ?? -1) === 9
             && (int)($usage['completion_tokens'] ?? -1) === 2;

    check($okPlain,  "{$key} 普通对话", '正文 "' . $plain->getContent() . '"');
    check($okStream, "{$key} 流式对话", '正文 "' . $stream->getContent() . '"');
    check($okUsage,  "{$key} token 统计",
        ($usage['prompt_tokens'] ?? '—') . '/' . ($usage['completion_tokens'] ?? '—'));

    echo pad($key, 20), pad($okPlain ? '✓' : '✗', 11),
         pad($okStream ? '✓' : '✗', 11), ($okUsage ? '✓' : '✗'), "\n";
}

// ---------------------------------------------------------------
// 二、SSE 报文格式的兼容性（历史上出过问题的写法）
// ---------------------------------------------------------------
echo "\n=== SSE 报文格式兼容性 ===\n\n";

$formats = [
    // 名称                          协议        报文
    ['data: 带空格（OpenAI 规范写法）', 'openai',  $SSE_OPENAI],
    ['data: 不带空格（星火等）',        'spark',   str_replace('data: ', 'data:', $SSE_OPENAI)],
    ['CRLF 行尾（部分网关改写）',       'openai',  str_replace("\n", "\r\n", $SSE_OPENAI)],
    ['末尾无换行符（收尾帧易丢）',      'qwen',    rtrim($SSE_OPENAI, "\n")],
    ['夹杂 event: / id: 等其它字段',    'claude',  $SSE_CLAUDE],
];
foreach ($formats as [$name, $key, $sse]) {
    $r  = replayAI(['protocol' => $key, 'model' => 'm', 'api_key' => 'k'], $sse, true)->chat('hi');
    $u  = $r->getUsage();
    $ok = $r->getContent() === '你好' && (int)($u['prompt_tokens'] ?? 0) === 9;
    check($ok, $name, '正文 "' . $r->getContent() . '" prompt=' . ($u['prompt_tokens'] ?? '—'));
    echo pad($name, 36), $ok ? '✓' : '✗', "\n";
}

// ---------------------------------------------------------------
// 三、平台在流里报错（HTTP 200）时必须抛异常，而不是返回空内容
// ---------------------------------------------------------------
echo "\n=== 流式错误处理 ===\n\n";

$errors = [
    ['OpenAI 系流中报错', 'openai',
     "data: {\"error\":{\"message\":\"rate limit exceeded\"}}\n\n"],
    ['MiniMax base_resp 错误', 'minimax',
     "data: {\"choices\":[],\"base_resp\":{\"status_code\":1004,\"status_msg\":\"login fail\"}}\n\n"],
    ['Anthropic 流中报错', 'claude',
     "event: error\ndata: {\"type\":\"error\",\"error\":{\"message\":\"overloaded\"}}\n\n"],
];
foreach ($errors as [$name, $key, $sse]) {
    try {
        replayAI(['protocol' => $key, 'model' => 'm', 'api_key' => 'k'], $sse, true)->chat('hi');
        check(false, $name, '未抛异常');
        echo pad($name, 36), "✗ 未抛异常\n";
    } catch (\Ai\Exceptions\AIException $e) {
        echo pad($name, 36), '✓ ', $e->getMessage(), "\n";
    }
}

// ---------------------------------------------------------------
// 三之二、流式下的 stop_reason 与工具调用
// ---------------------------------------------------------------
echo "\n=== 流式 stop_reason 与工具调用 ===\n\n";

// 流式不走 parseResponse()，stop_reason 若不单独解析就永远是空串，
// 调用方无从判断这一轮是正常结束还是被 max_tokens 截断
$stopCases = [
    ['正常结束 → end_turn', 'openai',
     "data: {\"choices\":[{\"delta\":{\"content\":\"好\"}}]}\n\n"
     . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}]}\n\ndata: [DONE]\n\n",
     'end_turn'],
    ['被截断 → max_tokens', 'openai',
     "data: {\"choices\":[{\"delta\":{\"content\":\"好\"}}]}\n\n"
     . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"length\"}]}\n\ndata: [DONE]\n\n",
     'max_tokens'],
    ['Anthropic 正常结束', 'claude',
     "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"好\"}}\n\n"
     . "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"}}\n\n",
     'end_turn'],
    ['Anthropic 被截断', 'claude',
     "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"好\"}}\n\n"
     . "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"max_tokens\"}}\n\n",
     'max_tokens'],
];
foreach ($stopCases as [$name, $proto, $sse, $want]) {
    $r   = replayAI(['protocol' => $proto, 'model' => 'm', 'api_key' => 'k'], $sse, true)->chat('hi');
    $got = $r->getStopReason();
    $ok  = ($got === $want);
    check($ok, "流式 stop_reason：{$name}", '实际 "' . $got . '"');
    echo pad($name, 30), $ok ? "✓ {$got}\n" : "✗ 得到 \"{$got}\"，期望 \"{$want}\"\n";
}

// 流式工具调用：两家的分片结构完全不同，重组后必须得到一致的结果
echo "\n";
$want = [['id' => 'c1', 'name' => 'get_weather', 'input' => ['city' => '北京']]];

// OpenAI 系：按 delta.tool_calls[].index 累积 arguments 字符串（这里切成 3 片）
$openaiTool =
      "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"c1\",\"type\":\"function\","
    . "\"function\":{\"name\":\"get_weather\",\"arguments\":\"\"}}]}}]}\n\n"
    . "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"arguments\":\"{\\\"city\\\"\"}}]}}]}\n\n"
    . "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"arguments\":\":\\\"北京\\\"}\"}}]}}]}\n\n"
    . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"tool_calls\"}]}\n\ndata: [DONE]\n\n";

// Anthropic 系：content_block_start 给 id/name，input_json_delta 给参数片段。
// 注意工具块的 index 是 1（0 被文本块占了），重组不能假定从 0 开始
$claudeTool =
      "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":0,"
    . "\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n"
    . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,"
    . "\"delta\":{\"type\":\"text_delta\",\"text\":\"我查一下\"}}\n\n"
    . "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":1,"
    . "\"content_block\":{\"type\":\"tool_use\",\"id\":\"c1\",\"name\":\"get_weather\",\"input\":{}}}\n\n"
    . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":1,"
    . "\"delta\":{\"type\":\"input_json_delta\",\"partial_json\":\"{\\\"city\\\"\"}}\n\n"
    . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":1,"
    . "\"delta\":{\"type\":\"input_json_delta\",\"partial_json\":\":\\\"北京\\\"}\"}}\n\n"
    . "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"tool_use\"}}\n\n";

$rOpenAi = replayAI(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k'], $openaiTool, true)->chat('hi');
$rClaude = replayAI(['protocol' => 'claude', 'model' => 'm', 'api_key' => 'k'], $claudeTool, true)->chat('hi');

foreach (['OpenAI 系' => $rOpenAi, 'Anthropic 系' => $rClaude] as $name => $r) {
    $ok = ($r->getToolCalls() === $want);
    check($ok, "流式工具调用重组：{$name}", json_encode($r->getToolCalls(), JSON_UNESCAPED_UNICODE));
    echo pad("流式工具调用重组 {$name}", 30),
         $ok ? "✓ " . json_encode($r->getToolCalls(), JSON_UNESCAPED_UNICODE) . "\n" : "✗\n";
}
$same = ($rOpenAi->getToolCalls() === $rClaude->getToolCalls());
check($same, '两家流式重组结果一致');
echo pad('两家重组结果逐字节相同', 30), $same ? "✓\n" : "✗\n";

$textKept = ($rClaude->getContent() === '我查一下');
check($textKept, 'Anthropic 同轮的文本块不被工具调用挤掉', $rClaude->getContent());
echo pad('同轮文本与工具调用并存', 30), $textKept ? "✓\n" : "✗\n";

// 参数 JSON 非法时降级为空参数，而不是丢掉整个调用
$broken = "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"c9\",\"type\":\"function\","
        . "\"function\":{\"name\":\"f\",\"arguments\":\"{截断的\"}}]}}]}\n\n"
        . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"tool_calls\"}]}\n\ndata: [DONE]\n\n";
$rBroken = replayAI(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k'], $broken, true)->chat('hi');
$degraded = ($rBroken->getToolCalls() === [['id' => 'c9', 'name' => 'f', 'input' => []]]);
check($degraded, '参数非法时降级为空参数而非丢掉调用',
      json_encode($rBroken->getToolCalls(), JSON_UNESCAPED_UNICODE));
echo pad('参数非法时降级保留调用', 30), $degraded ? "✓\n" : "✗\n";

// ---------------------------------------------------------------
// 四、Anthropic 协议的正文提取（开启思考的模型首块是 thinking）
// ---------------------------------------------------------------
echo "\n=== Anthropic 多内容块解析 ===\n\n";

$withThinking = json_encode([
    'content' => [
        ['type' => 'thinking', 'thinking' => '推理过程……'],
        ['type' => 'text',     'text'     => '最终答案'],
    ],
    'usage' => ['input_tokens' => 9, 'output_tokens' => 2],
]);
$r  = replayAI(['protocol' => 'claude', 'model' => 'm', 'api_key' => 'k'], $withThinking, false)->chat('hi');
$ok = $r->getContent() === '最终答案';
check($ok, '带 thinking 块时取正文', '正文 "' . $r->getContent() . '"');
echo pad('首块为 thinking 时取到正文', 36), $ok ? '✓' : '✗', "\n";

// ---------------------------------------------------------------
echo "\n", str_repeat('=', 62), "\n";
if ($failures) {
    echo count($failures) . " 项未通过：\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    exit(1);
}
echo "全部通过\n";
exit(0);
