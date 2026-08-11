<?php
/**
 * 工具调用（function calling）跨平台一致性测试
 *
 * 核心命题：**同一段业务代码，换 protocol 就能跑在任意平台上**。
 * 各家把同一件事写成了两套结构（OpenAI 的 tool_calls / role:'tool'，
 * Anthropic 的 tool_use / tool_result 块），本测试验证协议层把差异吃干净了：
 *
 *   1) 同一份工具定义 → 各平台发出各自正确的请求结构
 *   2) 各平台各自的响应 → 归一成完全相同的 getToolCalls() 结果
 *   3) 同一个 Agent 循环 → 在两个协议家族上产生相同的工具调用序列与最终答案
 *   4) OpenAI 原生写法也能直接喂给 Anthropic 协议（反向兼容）
 *
 * 不联网、不需要 Key。运行：php tests/tools_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Contracts\TransportInterface;
use Ai\Helpers\Protocols;

/** 按顺序回放预置响应的假传输层，并记录收到的请求体 */
class ScriptedTransport implements TransportInterface
{
    public $responses = [];   // 依次返回的响应数组
    public $requests  = [];   // 记录每次实际发出的请求体

    public function post(string $url, array $data, array $headers = []): array
    {
        $this->requests[] = $data;
        return array_shift($this->responses) ?: [];
    }
    public function get(string $url, array $params = [], array $headers = []): array { return []; }
    public function setTimeout(int $t): TransportInterface { return $this; }
    public function setProxy(string $p): TransportInterface { return $this; }
    public function setStreamCallback(?callable $cb): TransportInterface { return $this; }
}

function pad(string $t, int $w): string
{
    $n = $w - mb_strwidth($t, 'UTF-8');
    return $t . ($n > 0 ? str_repeat(' ', $n) : '');
}

$failures = [];
function check(bool $ok, string $name, string $detail = ''): void
{
    global $failures;
    if (!$ok) { $failures[] = $name . ($detail !== '' ? "（{$detail}）" : ''); }
    echo pad($name, 46), $ok ? "✓\n" : "✗ {$detail}\n";
}

// 库的统一工具定义格式
$TOOL = [
    'name'         => 'get_weather',
    'description'  => '查询某城市的天气',
    'input_schema' => [
        'type'       => 'object',
        'properties' => ['city' => ['type' => 'string', 'description' => '城市名']],
        'required'   => ['city'],
    ],
];

// ---------------------------------------------------------------
// 一、同一份工具定义 → 各平台发出正确的请求结构
// ---------------------------------------------------------------
echo "=== 一、工具定义按平台改写 ===\n\n";

$openaiReq = (new \Ai\Protocol\OpenAI())->buildRequest([
    'model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => '北京天气']],
    'tools' => [$TOOL], 'tool_choice' => ['type' => 'auto'],
]);
check(
    ($openaiReq['tools'][0]['type'] ?? '') === 'function'
    && ($openaiReq['tools'][0]['function']['name'] ?? '') === 'get_weather'
    && isset($openaiReq['tools'][0]['function']['parameters']['properties']['city']),
    'OpenAI 系：转成 {type:function, function:{parameters}}'
);
check(($openaiReq['tool_choice'] ?? '') === 'auto', 'OpenAI 系：tool_choice 归一为 auto');

$claudeReq = (new \Ai\Protocol\Claude())->buildRequest([
    'model' => 'claude-opus-5', 'messages' => [['role' => 'user', 'content' => '北京天气']],
    'tools' => [$TOOL],
]);
check(
    ($claudeReq['tools'][0]['name'] ?? '') === 'get_weather'
    && isset($claudeReq['tools'][0]['input_schema']['properties']['city']),
    'Anthropic 系：保持 {name, input_schema}'
);

// 反向：用户直接写 OpenAI 原生格式，喂给 Anthropic 协议也要能用
$claudeFromOpenAi = (new \Ai\Protocol\Claude())->buildRequest([
    'model' => 'claude-opus-5', 'messages' => [],
    'tools' => [['type' => 'function', 'function' => [
        'name' => 'get_weather', 'description' => 'x',
        'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
    ]]],
]);
check(
    ($claudeFromOpenAi['tools'][0]['name'] ?? '') === 'get_weather'
    && isset($claudeFromOpenAi['tools'][0]['input_schema']),
    'OpenAI 原生定义 → Anthropic 协议（反向兼容）'
);

// ---------------------------------------------------------------
// 二、各平台的响应 → 归一成完全相同的结果
// ---------------------------------------------------------------
echo "\n=== 二、响应归一 ===\n\n";

$openaiResp = (new \Ai\Protocol\OpenAI())->parseResponse([
    'choices' => [[
        'message' => [
            'role' => 'assistant', 'content' => null,
            'tool_calls' => [[
                'id' => 'call_abc', 'type' => 'function',
                'function' => ['name' => 'get_weather', 'arguments' => '{"city":"北京"}'],
            ]],
        ],
        'finish_reason' => 'tool_calls',
    ]],
]);
$claudeResp = (new \Ai\Protocol\Claude())->parseResponse([
    'content' => [
        ['type' => 'tool_use', 'id' => 'call_abc', 'name' => 'get_weather', 'input' => ['city' => '北京']],
    ],
    'stop_reason' => 'tool_use',
]);

$want = [['id' => 'call_abc', 'name' => 'get_weather', 'input' => ['city' => '北京']]];
check($openaiResp->getToolCalls() === $want, 'OpenAI 响应 → 统一 getToolCalls()',
      json_encode($openaiResp->getToolCalls(), JSON_UNESCAPED_UNICODE));
check($claudeResp->getToolCalls() === $want, 'Anthropic 响应 → 统一 getToolCalls()',
      json_encode($claudeResp->getToolCalls(), JSON_UNESCAPED_UNICODE));
check($openaiResp->getToolCalls() === $claudeResp->getToolCalls(), '两家结果逐字节相同');
check($openaiResp->getStopReason() === 'tool_use' && $claudeResp->getStopReason() === 'tool_use',
      'stop_reason 归一（tool_calls / tool_use → tool_use）',
      $openaiResp->getStopReason() . ' vs ' . $claudeResp->getStopReason());
check($openaiResp->hasToolCalls() && $claudeResp->hasToolCalls(), 'hasToolCalls() 一致');

// ---------------------------------------------------------------
// 三、结果回填：统一格式 → 各平台的正确结构
// ---------------------------------------------------------------
echo "\n=== 三、工具结果回填 ===\n\n";

$conversation = [
    ['role' => 'user', 'content' => '北京天气'],
    $claudeResp->toAssistantMessage(),          // 统一格式的 assistant 回合
    ['role' => 'user', 'content' => [
        ['type' => 'tool_result', 'tool_use_id' => 'call_abc', 'content' => '晴，25℃'],
    ]],
];

$oReq = (new \Ai\Protocol\OpenAI())->buildRequest(['model' => 'm', 'messages' => $conversation]);
$msgs = $oReq['messages'];
check(
    ($msgs[1]['role'] ?? '') === 'assistant'
    && ($msgs[1]['tool_calls'][0]['id'] ?? '') === 'call_abc'
    && ($msgs[1]['tool_calls'][0]['function']['arguments'] ?? '') === '{"city":"北京"}',
    'OpenAI 系：assistant 回合转成 tool_calls（arguments 为 JSON 字符串）'
);
check(
    ($msgs[2]['role'] ?? '') === 'tool'
    && ($msgs[2]['tool_call_id'] ?? '') === 'call_abc'
    && ($msgs[2]['content'] ?? '') === '晴，25℃',
    'OpenAI 系：tool_result 拆成独立的 role:tool 消息'
);

$cReq = (new \Ai\Protocol\Claude())->buildRequest(['model' => 'm', 'messages' => $conversation]);
check(
    ($cReq['messages'][1]['content'][0]['type'] ?? '') === 'tool_use'
    && ($cReq['messages'][2]['content'][0]['type'] ?? '') === 'tool_result',
    'Anthropic 系：保持 tool_use / tool_result 块'
);

// 反向：OpenAI 原生的 role:'tool' 写法喂给 Anthropic 协议
$cReq2 = (new \Ai\Protocol\Claude())->buildRequest(['model' => 'm', 'messages' => [
    ['role' => 'user', 'content' => 'x'],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
        'id' => 'c1', 'type' => 'function',
        'function' => ['name' => 'f', 'arguments' => '{"a":1}'],
    ]]],
    ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'done'],
]]);
check(
    ($cReq2['messages'][1]['content'][0]['type'] ?? '') === 'tool_use'
    && ($cReq2['messages'][1]['content'][0]['input']['a'] ?? null) === 1
    && ($cReq2['messages'][2]['content'][0]['type'] ?? '') === 'tool_result',
    'OpenAI 原生对话 → Anthropic 协议（反向兼容）'
);

// ---------------------------------------------------------------
// 四、同一个 Agent 循环跑在两个协议家族上
// ---------------------------------------------------------------
echo "\n=== 四、Agent 跨平台一致性 ===\n\n";

/** 两家各自的「先调工具、再给结论」两轮响应脚本 */
$scripts = [
    'openai' => [
        ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
            'id' => 'c1', 'type' => 'function',
            'function' => ['name' => 'get_weather', 'arguments' => '{"city":"北京"}'],
        ]]], 'finish_reason' => 'tool_calls']]],
        ['choices' => [['message' => ['role' => 'assistant', 'content' => '北京今天晴，25℃。'],
                        'finish_reason' => 'stop']]],
    ],
    'claude' => [
        ['content' => [['type' => 'tool_use', 'id' => 'c1', 'name' => 'get_weather',
                        'input' => ['city' => '北京']]], 'stop_reason' => 'tool_use'],
        ['content' => [['type' => 'text', 'text' => '北京今天晴，25℃。']], 'stop_reason' => 'end_turn'],
    ],
];

$runs = [];
foreach ($scripts as $protocol => $responses) {
    $tr = new ScriptedTransport();
    $tr->responses = $responses;

    $ai = AI::create(['protocol' => $protocol, 'model' => 'test-model', 'api_key' => 'k']);
    $ai->setTransport($tr);

    $calls  = [];
    $events = [];
    $agent  = (new Agent($ai))
        ->setSystem('你是天气助手')
        ->setTools([
            'get_weather' => [
                'description'  => '查询天气',
                'input_schema' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
                'handler'      => function (array $in) use (&$calls) {
                    $calls[] = $in;
                    return '晴，25℃';
                },
            ],
        ])
        ->onEvent(function ($e) use (&$events) { $events[] = $e['type']; });

    $agent->run([['role' => 'user', 'content' => '北京天气怎么样']]);

    $runs[$protocol] = [
        'text'     => $agent->lastText(),
        'calls'    => $calls,
        'events'   => $events,
        'requests' => $tr->requests,
    ];
}

check($runs['openai']['calls'] === [['city' => '北京']], 'OpenAI 系：工具被正确调用',
      json_encode($runs['openai']['calls'], JSON_UNESCAPED_UNICODE));
check($runs['claude']['calls'] === [['city' => '北京']], 'Anthropic 系：工具被正确调用',
      json_encode($runs['claude']['calls'], JSON_UNESCAPED_UNICODE));
check($runs['openai']['text'] === $runs['claude']['text'] && $runs['openai']['text'] === '北京今天晴，25℃。',
      '两家最终答案相同', $runs['openai']['text'] . ' vs ' . $runs['claude']['text']);
check($runs['openai']['events'] === $runs['claude']['events'], '两家事件序列相同',
      implode(',', $runs['openai']['events']) . ' vs ' . implode(',', $runs['claude']['events']));

// 第二轮请求里，工具结果必须已按各自平台的格式带上
$oSecond = $runs['openai']['requests'][1]['messages'] ?? [];
$cSecond = $runs['claude']['requests'][1]['messages'] ?? [];
check(
    !empty(array_filter($oSecond, function ($m) { return ($m['role'] ?? '') === 'tool'; })),
    'OpenAI 系：第二轮请求带上了 role:tool 结果'
);
check(
    !empty(array_filter($cSecond, function ($m) {
        return is_array($m['content'] ?? null) && ($m['content'][0]['type'] ?? '') === 'tool_result';
    })),
    'Anthropic 系：第二轮请求带上了 tool_result 块'
);

// system 提示词：OpenAI 系落到 messages 首位，Anthropic 系走顶层字段
check(($runs['openai']['requests'][0]['messages'][0]['role'] ?? '') === 'system',
      'OpenAI 系：system 落到 messages 首位');
check(($runs['claude']['requests'][0]['system'] ?? '') === '你是天气助手',
      'Anthropic 系：system 走顶层字段');

// ---------------------------------------------------------------
// 五、工具异常必须回填给模型而不是中断循环
// ---------------------------------------------------------------
echo "\n=== 五、工具异常处理 ===\n\n";

$tr = new ScriptedTransport();
$tr->responses = $scripts['openai'];
$ai = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai->setTransport($tr);

$events = [];
(new Agent($ai))
    ->setTools(['get_weather' => [
        'description' => 'x', 'input_schema' => ['type' => 'object'],
        // 抛 TypeError（属于 \Error 而非 \Exception），不该穿透 Agent 循环
        'handler' => function (array $in) { throw new \TypeError('handler 内部类型错误'); },
    ]])
    ->onEvent(function ($e) use (&$events) { $events[] = $e; })
    ->run([['role' => 'user', 'content' => 'x']]);

$types = array_column($events, 'type');
check(in_array('tool_error', $types, true), '工具抛 \Error 时发出 tool_error 事件',
      implode(',', $types));
check(in_array('done', $types, true), '工具抛错后循环继续走到 done', implode(',', $types));
$second = $tr->requests[1]['messages'] ?? [];
$toolMsg = array_values(array_filter($second, function ($m) { return ($m['role'] ?? '') === 'tool'; }));
check(!empty($toolMsg) && strpos($toolMsg[0]['content'] ?? '', 'ERROR:') === 0,
      '错误信息被回填给模型，供其换个思路');

// ---------------------------------------------------------------
// 五之二、Agent 不得留下副作用
// ---------------------------------------------------------------
echo "\n=== 五之二、Agent 的 stream 副作用 ===\n\n";

// 工具调用必须走非流式，但 Agent 只应「借用」调用方的 AI 实例，跑完还回去。
// 否则调用方本来开着的流式会被永久关掉，后续无关的 chat() 也跟着不流式。
$sideTr = new ScriptedTransport();
$sideTr->responses = $scripts['openai'];
$sideAi = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$sideAi->setTransport($sideTr)->setStream(true);

check($sideAi->isStreaming() === true, '前置条件：调用方开着流式');
(new Agent($sideAi))
    ->setTools(['get_weather' => ['description' => 'x', 'input_schema' => ['type' => 'object'],
                                  'handler' => function (array $in) { return 'ok'; }]])
    ->onEvent(function ($e) {})
    ->run([['role' => 'user', 'content' => 'x']]);
check($sideAi->isStreaming() === true, 'Agent 跑完后流式状态被还原');

$sentStream = false;
foreach ($sideTr->requests as $req) {
    if (!empty($req['stream'])) { $sentStream = true; }
}
check(!$sentStream, 'Agent 的请求确实以非流式发出');

// 空响应/异常路径下同样要还原
$emptyTr = new ScriptedTransport();
$emptyAi = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$emptyAi->setTransport($emptyTr)->setStream(true);
(new Agent($emptyAi))->setTools([])->onEvent(function ($e) {})->run([['role' => 'user', 'content' => 'x']]);
check($emptyAi->isStreaming() === true, '空响应路径下同样还原');

// ---------------------------------------------------------------
// 六、全部 40 个协议都能构造出带工具的请求
// ---------------------------------------------------------------
echo "\n=== 六、40 个协议均可发出工具请求 ===\n\n";

$bad = [];
foreach (array_keys(Protocols::all()) as $key) {
    $cls   = Protocols::resolveClass($key);
    $proto = new $cls();                       // 不用 new (expr)() —— 那是 PHP 8.0+ 语法，本库要兼容 7.2
    $req   = $proto->buildRequest([
        'model' => 'm', 'messages' => [['role' => 'user', 'content' => 'x']], 'tools' => [$TOOL],
    ]);
    // Anthropic 系用 {name}，OpenAI 系用 {type:function}
    $ok = isset($req['tools'][0])
        && (($req['tools'][0]['name'] ?? '') === 'get_weather'
            || ($req['tools'][0]['function']['name'] ?? '') === 'get_weather');
    if (!$ok) { $bad[] = $key; }
}
check(empty($bad), '40 个协议的 tools 均正确落入请求体', implode(', ', $bad));

echo "\n", str_repeat('=', 60), "\n";
if ($failures) {
    echo count($failures) . " 项未通过：\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    exit(1);
}
echo "全部通过：同一套代码可在 40 个平台上做工具调用\n";
exit(0);
