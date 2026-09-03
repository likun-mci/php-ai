<?php
/**
 * Agent::chat() 多轮对话测试
 *
 * 验证：
 *   1. Conversation：消息拼接规则（悬空 tool_use 补结果、相邻 user 合并）
 *   2. chat() 多轮：上下文自己接得住，返回 AgentResult
 *   3. 会话持久化下的多轮：新一轮的话不会被会话里的旧消息覆盖掉
 *   4. 工具往返后接着聊：assistant 回合与工具结果都在上下文里
 *   5. 等授权时直接说新的：补 tool_result（id 对得上）+ 新指示同处一条消息
 *   6. run() 的旧行为原样不变
 *
 * 不联网、不需要 Key。运行：php tests/agent_chat_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\AgentResult;
use Ai\Agent\Context\Conversation;
use Ai\Agent\Loop\StopReason;
use Ai\Agent\Session\FileSessionStore;
use Ai\Agent\Session\SessionManager;

/** 按顺序回放预置响应的假传输层 */
class ChatTransport implements \Ai\Contracts\TransportInterface
{
    /** @var array<int, array<string, mixed>> */
    public $responses = [];
    /** @var array<int, array<string, mixed>> 收到过的请求体 */
    public $requests = [];

    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }
    public function post(string $url, array $data, array $headers = []): array
    {
        $this->requests[] = $data;
        if (!$this->responses) {
            return self::text('（响应耗尽）');
        }
        return array_shift($this->responses);
    }
    public function get(string $url, array $params = [], array $headers = []): array { return []; }
    public function setTimeout(int $t): \Ai\Contracts\TransportInterface { return $this; }
    public function setProxy(string $p): \Ai\Contracts\TransportInterface { return $this; }
    public function setStreamCallback(?callable $cb): \Ai\Contracts\TransportInterface { return $this; }

    /** 一条普通文本回复 */
    public static function text($content)
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']]];
    }

    /** 一条发起工具调用的回复 */
    public static function toolCall($id, $name, array $args)
    {
        return ['choices' => [['message' => [
            'role'       => 'assistant',
            'content'    => null,
            'tool_calls' => [[
                'id'       => $id,
                'type'     => 'function',
                'function' => ['name' => $name, 'arguments' => json_encode($args)],
            ]],
        ], 'finish_reason' => 'tool_calls']]];
    }
}

$failures = [];
function check($ok, $name, $detail = '')
{
    global $failures;
    if (!$ok) { $failures[] = $name . ($detail !== '' ? "（{$detail}）" : ''); }
    echo ($ok ? "✓ " : "✗ ") . $name . ($ok ? '' : " — {$detail}") . "\n";
}

/** 造一个挂了假传输的 AI */
function makeAi(ChatTransport $tr)
{
    $ai = AI::create(['protocol' => 'openai', 'model' => 'gpt-4o', 'api_key' => 'test']);
    $ai->setTransport($tr);
    return $ai;
}

/** 把请求体里的 messages 压成 "role:文本" 便于断言 */
function flatten(array $messages)
{
    $out = [];
    foreach ($messages as $m) {
        $c = isset($m['content']) ? $m['content'] : '';
        if (is_array($c)) { $c = json_encode($c, JSON_UNESCAPED_UNICODE); }
        $line = $m['role'] . ':' . (string) $c;
        if (isset($m['tool_calls'])) { $line .= '[tool_calls]'; }
        if (isset($m['tool_call_id'])) { $line .= '#' . $m['tool_call_id']; }
        $out[] = $line;
    }
    return $out;
}

// ---------------------------------------------------------------
// 一、Conversation：消息拼接规则
// ---------------------------------------------------------------
echo "=== 一、Conversation 拼接规则 ===\n\n";

// 统一格式的悬空 tool_use
$msgs = [
    ['role' => 'user', 'content' => '删掉临时文件'],
    ['role' => 'assistant', 'content' => [
        ['type' => 'text', 'text' => '这就删'],
        ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'delete_file', 'input' => ['path' => 'a.tmp']],
    ]],
];
$dangling = Conversation::danglingToolUses($msgs);
check($dangling === ['toolu_01' => 'delete_file'], '统一格式：找出悬空 tool_use', json_encode($dangling));

$appended = Conversation::appendUserText($msgs, '别删了，先备份');
$last = end($appended);
check(count($appended) === 3 && $last['role'] === 'user', '悬空时只新增一条 user 消息', count($appended));
check(
    $last['content'][0]['type'] === 'tool_result' && $last['content'][0]['tool_use_id'] === 'toolu_01',
    'tool_result 的 id 与 tool_use 对得上',
    json_encode($last['content'][0], JSON_UNESCAPED_UNICODE)
);
check(!empty($last['content'][0]['is_error']), '补的结果标记为 is_error');
check(
    $last['content'][1]['type'] === 'text' && $last['content'][1]['text'] === '别删了，先备份',
    '新指示与 tool_result 同处一条消息，排在后面'
);
check(Conversation::danglingToolUses($appended) === [], '补完之后不再有悬空 tool_use');

// OpenAI 原生写法同样认得
$native = [
    ['role' => 'user', 'content' => 'x'],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['id' => 'call_9', 'type' => 'function', 'function' => ['name' => 'bash', 'arguments' => '{}']],
    ]],
];
check(Conversation::danglingToolUses($native) === ['call_9' => 'bash'], 'OpenAI 原生 tool_calls 也算悬空');
$native2 = $native;
$native2[] = ['role' => 'tool', 'tool_call_id' => 'call_9', 'content' => 'ok'];
check(Conversation::danglingToolUses($native2) === [], 'OpenAI 原生 role:tool 回填后不再悬空');

// 相邻 user 合并
$merged = Conversation::appendUserText([['role' => 'user', 'content' => '第一句']], '第二句');
check(count($merged) === 1 && $merged[0]['content'] === "第一句\n\n第二句", '相邻 user 消息合并，不连发同角色', json_encode($merged, JSON_UNESCAPED_UNICODE));

// 正常追加
$normal = Conversation::appendUserText([
    ['role' => 'user', 'content' => '你好'],
    ['role' => 'assistant', 'content' => '你好'],
], '再问一句');
check(count($normal) === 3 && $normal[2] === ['role' => 'user', 'content' => '再问一句'], 'assistant 之后正常新起一条 user');

// 空输入不产生空消息
check(Conversation::appendUserText([['role' => 'user', 'content' => 'a']], '  ') === [['role' => 'user', 'content' => 'a']], '空白输入不追加消息');
check(Conversation::normalize('') === [], 'normalize：空字符串 → 空列表');
check(Conversation::normalize('hi') === [['role' => 'user', 'content' => 'hi']], 'normalize：字符串 → user 消息');
check(Conversation::normalize(['role' => 'user', 'content' => 'hi']) === [['role' => 'user', 'content' => 'hi']], 'normalize：单条消息');

// ---------------------------------------------------------------
// 二、chat() 多轮（无会话持久化）
// ---------------------------------------------------------------
echo "\n=== 二、chat() 多轮 ===\n\n";

$tr = new ChatTransport([ChatTransport::text('你好，小明'), ChatTransport::text('你叫小明')]);
$agent = new Agent(makeAi($tr));

$r1 = $agent->chat('我叫小明');
check($r1 instanceof AgentResult, 'chat() 返回 AgentResult');
check($r1->getText() === '你好，小明', 'AgentResult 带回本轮文本', $r1->getText());
check($agent->lastText() === '你好，小明', 'lastText() 同步更新');

$r2 = $agent->chat('我叫什么？');
$second = flatten($tr->requests[1]['messages']);
check($second === ['user:我叫小明', 'assistant:你好，小明', 'user:我叫什么？'], '第二轮带上了完整上下文', json_encode($second, JSON_UNESCAPED_UNICODE));
check($r2->getText() === '你叫小明', '第二轮拿到第二条回复');
check(count($agent->getConversation()) === 4, 'getConversation() 累积了四条', count($agent->getConversation()));

$agent->clearConversation();
check($agent->getConversation() === [], 'clearConversation() 清空上下文');

// ---------------------------------------------------------------
// 三、会话持久化下的多轮
// ---------------------------------------------------------------
echo "\n=== 三、会话持久化 ===\n\n";

$dir = sys_get_temp_dir() . '/php_ai_chat_test_' . getmypid();
$store = new FileSessionStore($dir);

$tr2 = new ChatTransport([ChatTransport::text('你好，小明'), ChatTransport::text('你叫小明')]);
$agent2 = (new Agent(makeAi($tr2)))
    ->setSessionId('u1')
    ->setSessionManager(new SessionManager($store));

$agent2->chat('我叫小明');
$agent2->chat('我叫什么？');
$second2 = flatten($tr2->requests[1]['messages']);
check(
    in_array('user:我叫什么？', $second2, true),
    '挂了 SessionManager 时，第二轮的新消息不会被会话覆盖掉',
    json_encode($second2, JSON_UNESCAPED_UNICODE)
);
check(count($store->load('u1')->getMessages()) === 4, '会话里存下了四条消息', count($store->load('u1')->getMessages()));

// 换一个新实例（模拟下一个请求）继续聊
$tr3 = new ChatTransport([ChatTransport::text('还是小明')]);
$agent3 = (new Agent(makeAi($tr3)))
    ->setSessionId('u1')
    ->setSessionManager(new SessionManager($store));
$agent3->chat('再确认一次');
$third = flatten($tr3->requests[0]['messages']);
check(
    $third === ['user:我叫小明', 'assistant:你好，小明', 'user:我叫什么？', 'assistant:你叫小明', 'user:再确认一次'],
    '新进程/新实例接着聊，上下文从会话里恢复',
    json_encode($third, JSON_UNESCAPED_UNICODE)
);

// run() 的旧行为不变：会话消息仍然是权威的
$tr4 = new ChatTransport([ChatTransport::text('ok')]);
$agent4 = (new Agent(makeAi($tr4)))
    ->setSessionId('u1')
    ->setSessionManager(new SessionManager($store));
$agent4->run([['role' => 'user', 'content' => '这条会被会话覆盖']]);
$fourth = flatten($tr4->requests[0]['messages']);
check(!in_array('user:这条会被会话覆盖', $fourth, true), 'run() 维持原行为：会话消息覆盖传入的副本', json_encode($fourth, JSON_UNESCAPED_UNICODE));

array_map('unlink', glob($dir . '/*'));
@rmdir($dir);

// ---------------------------------------------------------------
// 四、工具往返之后接着聊
// ---------------------------------------------------------------
echo "\n=== 四、工具往返后继续对话 ===\n\n";

$tr5 = new ChatTransport([
    ChatTransport::toolCall('call_w1', 'get_weather', ['city' => '北京']),
    ChatTransport::text('北京今天晴，25 度'),
    ChatTransport::text('上海也是晴'),
]);
$agent5 = (new Agent(makeAi($tr5)))->setTools([
    'get_weather' => [
        'description'  => '查天气',
        'input_schema' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
        'handler'      => function (array $in) { return '晴 25℃'; },
    ],
]);

$agent5->chat('北京天气怎么样');
$agent5->chat('上海呢');
$last5 = flatten($tr5->requests[2]['messages']);
check(count($tr5->requests) === 3, '两轮对话共发了三次请求（含一次工具回合）', count($tr5->requests));
check(in_array('assistant:[tool_calls]', $last5, true), '上下文里留着 assistant 的工具调用回合', json_encode($last5, JSON_UNESCAPED_UNICODE));
check(in_array('tool:晴 25℃#call_w1', $last5, true), '上下文里留着工具结果，id 对得上');
check(end($last5) === 'user:上海呢', '新一轮的问题排在最后');

// ---------------------------------------------------------------
// 五、等授权时直接说新的
// ---------------------------------------------------------------
echo "\n=== 五、等授权时继续对话 ===\n\n";

$tr6 = new ChatTransport([
    ChatTransport::toolCall('call_rm', 'delete_file', ['path' => 'a.tmp']),
    ChatTransport::text('好的，改成备份'),
]);
$agent6 = (new Agent(makeAi($tr6)))
    ->setPermissionMode('accept_edits')     // 非编辑类工具一律要授权
    ->setTools([
        'delete_file' => [
            'description'  => '删文件',
            'input_schema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]],
            'handler'      => function (array $in) { return '已删除'; },
        ],
    ]);

$paused = $agent6->chat('删掉 a.tmp');
check($paused->getStopReason() === StopReason::PERMISSION_DENIED, '未授权时停下来等决策', $paused->getStopReason());
check($agent6->isAwaitingPermission(), 'isAwaitingPermission() 为真');

$resumed = $agent6->chat('别删了，改成备份到 a.bak');
check(count($tr6->requests) === 2, '继续对话又发了一次请求', count($tr6->requests));

$after = flatten($tr6->requests[1]['messages']);
$toolLine = '';
foreach ($after as $line) {
    if (strpos($line, 'tool:') === 0) { $toolLine = $line; }
}
check($toolLine !== '', '悬空的工具调用被补上了结果', json_encode($after, JSON_UNESCAPED_UNICODE));
check(strpos($toolLine, '#call_rm') !== false, '补的结果 id 与暂停时的调用一致', $toolLine);
check(end($after) === 'user:别删了，改成备份到 a.bak', '新指示作为 user 消息跟在结果之后');

$toolPos = array_search($toolLine, $after, true);
check($toolPos === count($after) - 2, 'role:tool 紧跟在 assistant 回合之后（OpenAI 要求的顺序）', (string) $toolPos);
check(!$agent6->isAwaitingPermission(), '继续对话后不再处于等授权状态');
check($resumed->getText() === '好的，改成备份', '拿到了继续之后的回复', $resumed->getText());

// ---------------------------------------------------------------
// 六、Anthropic 侧：块结构原样落地
// ---------------------------------------------------------------
echo "\n=== 六、Anthropic 消息结构 ===\n\n";

$tr7 = new ChatTransport([
    ['content' => [['type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'delete_file', 'input' => ['path' => 'a.tmp']]],
     'stop_reason' => 'tool_use'],
    ['content' => [['type' => 'text', 'text' => '改成备份了']], 'stop_reason' => 'end_turn'],
]);
$ai7 = AI::create(['protocol' => 'claude', 'model' => 'claude-opus-5', 'api_key' => 'test']);
$ai7->setTransport($tr7);
$agent7 = (new Agent($ai7))
    ->setPermissionMode('accept_edits')
    ->setTools([
        'delete_file' => [
            'description'  => '删文件',
            'input_schema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]],
            'handler'      => function (array $in) { return '已删除'; },
        ],
    ]);

$agent7->chat('删掉 a.tmp');
$agent7->chat('别删了，改成备份');

$sent    = $tr7->requests[1]['messages'];
$lastMsg = end($sent);
check($lastMsg['role'] === 'user', 'Anthropic：最后一条是 user 消息', $lastMsg['role']);
check(
    isset($lastMsg['content'][0]['type']) && $lastMsg['content'][0]['type'] === 'tool_result'
    && $lastMsg['content'][0]['tool_use_id'] === 'toolu_9',
    'Anthropic：tool_result 块在前，id 对得上',
    json_encode($lastMsg['content'], JSON_UNESCAPED_UNICODE)
);
check(
    isset($lastMsg['content'][1]['type']) && $lastMsg['content'][1]['type'] === 'text'
    && $lastMsg['content'][1]['text'] === '别删了，改成备份',
    'Anthropic：新指示作为 text 块跟在同一条消息里'
);
$roles = [];
foreach ($sent as $m) { $roles[] = $m['role']; }
$adjacent = false;
for ($i = 1; $i < count($roles); $i++) {
    if ($roles[$i] === $roles[$i - 1]) { $adjacent = true; }
}
check(!$adjacent, 'Anthropic：没有相邻的同角色消息', implode(',', $roles));

echo "\n", str_repeat('=', 60), "\n";
if ($failures) {
    echo count($failures) . " 项未通过：\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    exit(1);
}
echo "全部通过：Agent::chat() 多轮对话工作正常\n";
exit(0);
