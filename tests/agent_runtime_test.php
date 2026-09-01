<?php
/**
 * Agent Runtime 组件测试
 *
 * 验证 Phase 1 新增组件的功能：
 *   1. ToolResult：成功/失败工厂方法与取值
 *   2. ToolRegistry：对象 + 旧格式闭包两种注册方式
 *   3. LoopGuard：连续重复工具调用检测（防死循环）
 *   4. AgentRuntime：完整跑一个 Agent 循环
 *   5. Agent 对象：通过 getRuntime() 访问新组件
 *
 * 不联网、不需要 Key。运行：php tests/agent_runtime_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\AgentResult;
use Ai\Agent\AgentRuntime;
use Ai\Agent\Loop\LoopGuard;
use Ai\Agent\Loop\StopReason;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;
use Ai\Agent\Tool\ToolRegistry;
use Ai\Agent\Tool\AgentToolInterface;

/** 按顺序回放预置响应的假传输层 */
class ScriptedTransport2 implements \Ai\Contracts\TransportInterface
{
    public $responses = [];
    public $requests  = [];
    /** true 时响应耗尽后复用最后一条（模拟模型持续调工具） */
    public $loopLast = false;

    public function post(string $url, array $data, array $headers = []): array
    {
        $this->requests[] = $data;
        if (!$this->responses) {
            return [];
        }
        $resp = array_shift($this->responses);
        // loopLast：响应耗尽后复用最后一条（模拟模型持续调工具）
        if ($this->loopLast && !$this->responses) {
            $this->responses[] = $resp;
        }
        return $resp;
    }
    public function get(string $url, array $params = [], array $headers = []): array { return []; }
    public function setTimeout(int $t): \Ai\Contracts\TransportInterface { return $this; }
    public function setProxy(string $p): \Ai\Contracts\TransportInterface { return $this; }
    public function setStreamCallback(?callable $cb): \Ai\Contracts\TransportInterface { return $this; }
}

$failures = [];
function check(bool $ok, string $name, string $detail = ''): void
{
    global $failures;
    if (!$ok) { $failures[] = $name . ($detail !== '' ? "（{$detail}）" : ''); }
    echo ($ok ? "✓ " : "✗ ") . $name . ($ok ? '' : " — {$detail}") . "\n";
}

// ---------------------------------------------------------------
// 一、ToolResult 值对象
// ---------------------------------------------------------------
echo "=== 一、ToolResult ===\n\n";

$ok = ToolResult::success('文件内容', ['file' => 'a.php']);
check($ok->isSuccess(), 'success() 创建成功结果');
check($ok->getContent() === '文件内容', 'success() 的 content 正确');
check($ok->getMetadata() === ['file' => 'a.php'], 'success() 的 metadata 正确');
check((string) $ok === '文件内容', '__toString() 返回 content');

$err = ToolResult::error('文件不存在');
check(!$err->isSuccess(), 'error() 创建失败结果');
check($err->getError() === '文件不存在', 'error() 的 error 正确');
check((string) $err === 'ERROR: 文件不存在', 'error() 的 __toString() 带 ERROR 前缀');

// ---------------------------------------------------------------
// 二、ToolRegistry：两种注册方式
// ---------------------------------------------------------------
echo "\n=== 二、ToolRegistry ===\n\n";

class WeatherTool implements AgentToolInterface
{
    public function name() { return 'get_weather'; }
    public function description() { return '查询天气'; }
    public function schema() { return ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]]; }
    public function execute(array $input, ToolContext $context) { return ToolResult::success('晴，25℃'); }
}

$registry = new ToolRegistry();
$registry->register(new WeatherTool());                                    // 对象方式
$registry->register('search_order', [                                     // 旧格式闭包
    'description'  => '查订单',
    'input_schema' => ['type' => 'object'],
    'handler'      => function (array $in) { return '已发货'; },
]);

check($registry->has('get_weather'), '对象方式注册成功');
check($registry->has('search_order'), '闭包方式注册成功');
check($registry->get('get_weather') instanceof AgentToolInterface, 'get() 返回 AgentToolInterface');
check(count($registry->all()) === 2, 'all() 返回全部工具');

$defs = $registry->defs();
check(
    isset($defs[0]['name']) && !isset($defs[0]['handler']),
    'defs() 去掉 handler，只保留元数据'
);

// 旧格式闭包工具通过接口执行
$ctx = new ToolContext('/var/www');
$result = $registry->get('search_order')->execute(['order_no' => 'A1'], $ctx);
check($result instanceof ToolResult && $result->getContent() === '已发货', '闭包工具 execute() 正常');

// 未知工具：get() 返回 null，execute 交给 ToolExecutor 返回失败结果
$executor = new \Ai\Agent\Tool\ToolExecutor($registry);
$result2 = $executor->execute(['id' => 'x', 'name' => 'nope', 'input' => []], $ctx);
check($result2 instanceof ToolResult && !$result2->isSuccess(), '未知工具返回失败结果');

// ---------------------------------------------------------------
// 三、ToolContext
// ---------------------------------------------------------------
echo "\n=== 三、ToolContext ===\n\n";

$emitted = [];
$ctx = new ToolContext('/tmp/work', function ($e) use (&$emitted) { $emitted[] = $e; });
check($ctx->workdir() === '/tmp/work', 'workdir() 正确');
check(!$ctx->isCancelled(), '初始未取消');
$ctx->cancel();
check($ctx->isCancelled(), 'cancel() 标记取消');
$ctx->emit('tool_log', ['message' => 'hi']);
check($emitted === [['type' => 'tool_log', 'message' => 'hi']], 'emit() 透传给回调');

// ---------------------------------------------------------------
// 四、LoopGuard：防死循环
// ---------------------------------------------------------------
echo "\n=== 四、LoopGuard ===\n\n";

$guard = new LoopGuard(3);
$r1 = $guard->check('read_file', ['path' => 'a.php']);
$r2 = $guard->check('read_file', ['path' => 'a.php']);
$r3 = $guard->check('read_file', ['path' => 'a.php']);
check($r1['ok'] && $r2['ok'], '前两次重复不触发');
check(!$r3['ok'] && $r3['reason'] === 'no_progress', '第三次连续重复触发 no_progress');

// 参数不同不触发
$guard2 = new LoopGuard(3);
$guard2->check('read_file', ['path' => 'a.php']);
$guard2->check('read_file', ['path' => 'b.php']);
$guard2->check('read_file', ['path' => 'c.php']);
check($guard2->count() === 3, '参数不同不触发重复（历史仍在累积）');

// 打断重复序列后计数重置
// 序列：a, a, grep, a, a → grep 打断计数；随后 a, a（第 4、5 次），再第 6 次 a 才连续满 3
$guard3 = new LoopGuard(3);
$guard3->check('read_file', ['path' => 'a.php']);
$guard3->check('read_file', ['path' => 'a.php']);
$guard3->check('grep', ['pattern' => 'x']);
$guard3->check('read_file', ['path' => 'a.php']);
$guard3->check('read_file', ['path' => 'a.php']);
$r6 = $guard3->check('read_file', ['path' => 'a.php']);
check(!$r6['ok'] && $r6['reason'] === 'no_progress', '打断后计数从 1 重新开始，连续满 3 触发');

// 指纹区分参数
check($guard->fingerprint('a', ['x' => 1]) !== $guard->fingerprint('a', ['x' => 2]), '指纹区分不同参数');

// ---------------------------------------------------------------
// 五、AgentRuntime 完整循环
// ---------------------------------------------------------------
echo "\n=== 五、AgentRuntime 完整循环 ===\n\n";

$tr = new ScriptedTransport2();
$tr->responses = [
    ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
        'id' => 'c1', 'type' => 'function',
        'function' => ['name' => 'get_weather', 'arguments' => '{"city":"北京"}'],
    ]]], 'finish_reason' => 'tool_calls']]],
    ['choices' => [['message' => ['role' => 'assistant', 'content' => '北京今天晴，25℃。'], 'finish_reason' => 'stop']]],
];

$ai = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai->setTransport($tr);

$events = [];
$runtime = new AgentRuntime($ai);
$runtime
    ->setSystem('你是天气助手')
    ->setTools([
        'get_weather' => [
            'description'  => '查询天气',
            'input_schema' => ['type' => 'object'],
            'handler'      => function (array $in) { return '晴，25℃'; },
        ],
    ])
    ->onEvent(function ($e) use (&$events) { $events[] = $e; });

$result = $runtime->run([['role' => 'user', 'content' => '北京天气']]);

check($result instanceof AgentResult, 'run() 返回 AgentResult');
check($result->getText() === '北京今天晴，25℃。', '最终文本正确');
check($result->isDone(), '正常结束（end_turn）');
check($result->getStopReason() === 'end_turn', 'stop_reason 为 end_turn');
check($result->getIterations() === 2, '迭代 2 次');
check(!$result->isError(), 'isError() 为 false');
check(in_array('tool_call', array_column($events, 'type'), true), '发出 tool_call 事件');
check(in_array('done', array_column($events, 'type'), true), '发出 done 事件');

// ---------------------------------------------------------------
// 六、Agent 对象向后兼容 + 新组件访问
// ---------------------------------------------------------------
echo "\n=== 六、Agent 对象 ===\n\n";

$tr2 = new ScriptedTransport2();
$tr2->responses = [
    ['choices' => [['message' => ['role' => 'assistant', 'content' => '你好。'], 'finish_reason' => 'stop']]],
];

$ai2 = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai2->setTransport($tr2);

$events2 = [];
$agent = (new Agent($ai2))
    ->setSystem('助手')
    ->setTools([])
    ->onEvent(function ($e) use (&$events2) { $events2[] = $e['type']; });
$agent->run([['role' => 'user', 'content' => '你好']]);

check($agent->lastText() === '你好。', 'lastText() 兼容');
check(in_array('done', $events2, true), 'onEvent() 事件兼容');
check($agent->getRuntime() instanceof AgentRuntime, 'getRuntime() 返回 AgentRuntime 实例');

// 迭代上限
$tr3 = new ScriptedTransport2();
$tr3->loopLast = true;
$tr3->responses = [
    ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
        'id' => 'c1', 'type' => 'function',
        'function' => ['name' => 'get_weather', 'arguments' => '{"city":"北京"}'],
    ]]], 'finish_reason' => 'tool_calls']]],
];

$ai3 = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai3->setTransport($tr3);
$events3 = [];
$agent3 = (new Agent($ai3))
    ->setTools(['get_weather' => ['description' => 'x', 'input_schema' => ['type' => 'object'],
                                  'handler' => function (array $in) { return 'ok'; }]])
    ->setMaxIter(2)
    ->onEvent(function ($e) use (&$events3) { $events3[] = $e; });
$agent3->run([['role' => 'user', 'content' => 'x']]);

$types3 = array_column($events3, 'type');
check(in_array('error', $types3, true), 'maxIter 截断时发出 error 事件');

// LoopGuard 在真实循环中触发 no_progress
$tr4 = new ScriptedTransport2();
$tr4->responses = [
    ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
        'id' => 'c1', 'type' => 'function',
        'function' => ['name' => 'get_weather', 'arguments' => '{"city":"北京"}'],
    ]]], 'finish_reason' => 'tool_calls']]],
    ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
        'id' => 'c2', 'type' => 'function',
        'function' => ['name' => 'get_weather', 'arguments' => '{"city":"北京"}'],
    ]]], 'finish_reason' => 'tool_calls']]],
    ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
        'id' => 'c3', 'type' => 'function',
        'function' => ['name' => 'get_weather', 'arguments' => '{"city":"北京"}'],
    ]]], 'finish_reason' => 'tool_calls']]],
];

$ai4 = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai4->setTransport($tr4);
$events4 = [];
$agent4 = (new Agent($ai4))
    ->setTools(['get_weather' => ['description' => 'x', 'input_schema' => ['type' => 'object'],
                                  'handler' => function (array $in) { return 'ok'; }]])
    ->setMaxIter(10)
    ->onEvent(function ($e) use (&$events4) { $events4[] = $e; });
$agent4->run([['role' => 'user', 'content' => 'x']]);

$types4 = array_column($events4, 'type');
check(in_array('error', $types4, true), 'no_progress 触发时发出 error 事件');
check($tr4->requests[2] !== null, 'no_progress 在第二次重复后触发（发过 2 次请求）');

echo "\n", str_repeat('=', 60), "\n";
if ($failures) {
    echo count($failures) . " 项未通过：\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    exit(1);
}
echo "全部通过：Agent Runtime Phase 1 组件工作正常\n";
exit(0);
