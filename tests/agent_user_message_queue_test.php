<?php
/**
 * 运行途中追加用户消息（AgentContext::queueUserMessage / setUserMessageSource）
 *
 * 覆盖三件事：
 *   1. 进程内队列：开跑前排队的消息，第一次请求模型时就带上
 *   2. 跨进程来源：模型给出终稿、正要收尾时才送到的消息，不能石沉大海
 *   3. 悬空 tool_use：上一轮停在工具调用上时注入，补 tool_result 且不产生相邻 user
 *
 * 运行：php tests/agent_user_message_queue_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\AgentContext;
use Ai\Agent\Loop\LoopController;
use Ai\Agent\Tool\ToolRegistry;
use Ai\Response\AIResponse;

$passed = 0;
$failed = 0;
function test($name, $ok)
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "✓ {$name}\n"; }
    else { $failed++; echo "✗ {$name}\n"; }
}
function assert_eq($name, $expected, $actual)
{
    global $passed, $failed;
    if ($expected === $actual) { $passed++; echo "✓ {$name}\n"; }
    else {
        $failed++;
        echo "✗ {$name}\n    期望: " . var_export($expected, true) . "\n    实际: " . var_export($actual, true) . "\n";
    }
}

/** 逐次回放的假模型：每次调用记录收到的 messages，按脚本回复 */
class QueueProbeAI extends \Ai\AI
{
    /** @var int */
    public $calls = 0;
    /** @var array<int, array<int, array<string, mixed>>> */
    public $seen = [];
    /** @var array<int, array<string, mixed>> 每次调用回什么 */
    public $script = [];
    /** @var callable|null 每次调用后回调（用来模拟「这时用户发来一条」） */
    public $afterCall = null;

    public function chat($payload = ''): \Ai\Contracts\AIResponseInterface
    {
        $payload = is_array($payload) ? $payload : ['messages' => []];
        $this->seen[] = isset($payload['messages']) ? $payload['messages'] : [];
        $this->calls++;

        $spec = isset($this->script[$this->calls - 1]) ? $this->script[$this->calls - 1] : ['text' => '好，做完了。'];

        if ($this->afterCall !== null) {
            call_user_func($this->afterCall, $this->calls);
        }

        if (isset($spec['tool'])) {
            return new AIResponse([
                'content'    => '',
                'tool_calls' => [[
                    'id'    => 'call_' . $this->calls,
                    'name'  => $spec['tool'],
                    'input' => isset($spec['input']) ? $spec['input'] : [],
                ]],
                'usage'      => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ]);
        }
        return new AIResponse([
            'content' => $spec['text'],
            'usage'   => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ]);
    }
}

/** 把一批消息拍平成可搜索的文本 */
function flatten($messages)
{
    return json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function makeContext(QueueProbeAI $ai, $emit, array $messages)
{
    $context = new AgentContext($ai, new ToolRegistry(), $emit);
    $context->setMessages($messages);
    $context->setSystem('你是测试助手。');
    return $context;
}

// ===================================================================
echo "\n=== 1. 进程内队列：开跑前排队，第一次请求就带上 ===\n";
// ===================================================================

$ai = new QueueProbeAI();
$events = [];
$ctx = makeContext($ai, function ($ev) use (&$events) { $events[] = $ev; }, [['role' => 'user', 'content' => '把登录页改一下']]);
$ctx->queueUserMessage('顺便把注册页也改了', [], ['queued_id' => 'q-1']);

test('队列非空', $ctx->hasQueuedUserMessages() === true);

$result = (new LoopController(5))->run($ctx);

assert_eq('模型被调用一次', 1, $ai->calls);
$first = flatten($ai->seen[0]);
test('第一次请求就带上了后来排队的消息', strpos($first, '顺便把注册页也改了') !== false);
test('原话仍在', strpos($first, '把登录页改一下') !== false);
test('同一轮里合进了一条 user 消息（没有相邻 user）', count($ai->seen[0]) === 1);
test('队列已排空', $ctx->hasQueuedUserMessages() === false);

$types = [];
foreach ($events as $ev) { if (is_array($ev) && isset($ev['type'])) $types[] = $ev['type']; }
test('发了 user_message 事件', in_array('user_message', $types, true));
$found = null;
foreach ($events as $ev) { if (isset($ev['type']) && $ev['type'] === 'user_message') $found = $ev; }
test('事件带 text 与 queued 标记', $found && $found['text'] === '顺便把注册页也改了' && $found['queued'] === true);
test('meta 原样带回（气泡 id 对得上号）', $found && isset($found['queued_id']) && $found['queued_id'] === 'q-1');

// ===================================================================
echo "\n=== 2. 跨进程来源：正要收尾时送到的话不会丢 ===\n";
// ===================================================================

$pending = [];
$ai = new QueueProbeAI();
$ai->script = [
    ['text' => '第一步做完了。'],
    ['text' => '好，第二步也做完了。'],
];
// 第一次模型调用之后，用户才发来一条
$ai->afterCall = function ($n) use (&$pending) {
    if ($n === 1) {
        $pending[] = ['text' => '再帮我把 README 更新一下', 'blocks' => []];
    }
};
$events = [];
$ctx = makeContext($ai, function ($ev) use (&$events) { $events[] = $ev; }, [['role' => 'user', 'content' => '做第一步']]);
$ctx->setUserMessageSource(function () use (&$pending) {
    $batch = $pending;
    $pending = [];
    return $batch;
});

$result = (new LoopController(5))->run($ctx);

assert_eq('没有直接收尾，又跑了一轮', 2, $ai->calls);
test('第二次请求里带上了新需求', strpos(flatten($ai->seen[1]), '再帮我把 README 更新一下') !== false);
assert_eq('收尾结果是最后一次的正文', '好，第二步也做完了。', $result->getText());
test('来源已取空', $pending === []);

// ===================================================================
echo "\n=== 3. 悬空 tool_use：补结果 + 不产生相邻 user ===\n";
// ===================================================================

$ai = new QueueProbeAI();
$ai->script = [
    ['text' => '改好了。'],
];
$events = [];
// 上一轮停在「模型要求调工具、结果还没回填」的状态
$ctx = makeContext($ai, function ($ev) use (&$events) { $events[] = $ev; }, [
    ['role' => 'user', 'content' => '改一下 config'],
    ['role' => 'assistant', 'content' => [
        ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'write_file', 'input' => ['path' => 'config.php']],
    ]],
]);
$ctx->queueUserMessage('算了，别改了');

(new LoopController(5))->run($ctx);

$msgs = $ai->seen[0];
assert_eq('消息条数：补了一条 user', 3, count($msgs));
test('末条是 user', $msgs[2]['role'] === 'user');
$blocks = is_array($msgs[2]['content']) ? $msgs[2]['content'] : [];
$hasResult = false;
foreach ($blocks as $b) {
    if (isset($b['type']) && $b['type'] === 'tool_result' && $b['tool_use_id'] === 'tu_1') {
        $hasResult = true;
    }
}
test('补上了 tu_1 的 tool_result', $hasResult);
$userCount = 0;
for ($i = 1; $i < count($msgs); $i++) {
    if ($msgs[$i]['role'] === 'user' && $msgs[$i - 1]['role'] === 'user') { $userCount++; }
}
assert_eq('没有相邻的两条 user', 0, $userCount);
test('新需求也在同一条 user 消息里', strpos(flatten($msgs[2]), '算了，别改了') !== false);

// ===================================================================
echo "\n=== 4. 空消息不入队 ===\n";
// ===================================================================

$ctx = makeContext(new QueueProbeAI(), null, []);
$ctx->queueUserMessage('   ');
$ctx->queueUserMessage('');
test('空白消息被忽略', $ctx->hasQueuedUserMessages() === false);

// ===================================================================
echo "\n" . ($failed === 0 ? "全部通过" : "有失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);
