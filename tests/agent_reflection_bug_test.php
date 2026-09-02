<?php
/**
 * ReflectionManager 判据错位回归测试
 *
 * 对应反馈「php-ai ReflectionManager 判据错位」（v1.55.1）：
 *   #1 工具报错的检测分支永远不成立——它要找 role=tool 的字符串 content，
 *      而库自己回填的是 role=user + 一组 tool_result 块，写入方与读取方对不上
 *   #2 轮次兜底在前两轮无条件判「未达成」，两轮内收工的任务被多逼一轮，
 *      模型对那句无关提示的困惑回复顶掉了真正的结论
 *   #3 maxRounds 收到的是循环迭代号，且这道闸短路在自定义策略之前
 *
 * 不联网、不调模型。运行：php tests/agent_reflection_bug_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\AgentContext;
use Ai\Agent\Reflection\ReflectionManager;
use Ai\Agent\Reflection\ReflectionResult;
use Ai\Agent\Tool\ToolRegistry;

$passed = 0;
$failed = 0;

function test($name, $ok)
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "✓ {$name}\n";
    } else {
        $failed++;
        echo "✗ {$name}\n";
    }
}

function assert_eq($name, $expected, $actual)
{
    test($name, $expected === $actual);
    if ($expected !== $actual) {
        echo "    期望: " . var_export($expected, true) . "\n";
        echo "    实际: " . var_export($actual, true) . "\n";
    }
}

/** 造一段「模型调了工具 → 工具结果按库的真实格式回填」的历史 */
function history(array $batches, $tail = '')
{
    $ctx = new AgentContext(AI::create(['model' => 'deepseek-chat', 'api_key' => 'k']), new ToolRegistry());
    $ctx->appendUser('把 site/index.php 的标题改成「欢迎」');

    $n = 0;
    foreach ($batches as $batch) {
        $blocks  = [];
        $results = [];
        foreach ($batch as $one) {
            $n++;
            $id = 'tu_' . $n;
            $blocks[]  = ['type' => 'tool_use', 'id' => $id, 'name' => 'edit_file', 'input' => []];
            $results[] = [
                'type'        => 'tool_result',
                'tool_use_id' => $id,
                'content'     => $one['content'],
                'is_error'    => !empty($one['is_error']),
            ];
        }
        $ctx->setMessages(array_merge($ctx->getMessages(), [['role' => 'assistant', 'content' => $blocks]]));
        $ctx->appendToolResults($results);
    }

    if ($tail !== '') {
        $ctx->setMessages(array_merge($ctx->getMessages(), [['role' => 'assistant', 'content' => $tail]]));
    }
    return $ctx->getMessages();
}

$fatal = 'ERROR: Fatal error: old_string 在文件中不唯一';

// ===== 一、反馈里的最小复现 =====

$rm = new ReflectionManager();
$msgs = history([[['content' => $fatal, 'is_error' => true]]]);
$r = $rm->reflect($msgs, '把标题改成欢迎', ['iteration' => 5]);
test('工具报了 Fatal error → 判继续', $r->shouldContinue());
test('原因指向工具报错', strpos($r->getReason(), '出错') !== false);
test('报错内容进了 metadata', strpos(json_encode($r->getMetadata(), JSON_UNESCAPED_UNICODE), 'Fatal') !== false);

// 用库自己的 appendToolResults 写出来的就是 role=user + tool_result，确认前提没变
$raw = $msgs[count($msgs) - 1];
assert_eq('工具结果回填的 role 是 user', 'user', $raw['role']);
assert_eq('content 是 tool_result 块', 'tool_result', $raw['content'][0]['type']);

// ===== 二、反馈里的五个场景 =====

// 1. 工具报错未处理（iteration 5）→ 继续
$r1 = $rm->reflect(history([[['content' => $fatal, 'is_error' => true]]]), '目标', ['iteration' => 5]);
test('场景1 工具报错未处理 → 继续', $r1->shouldContinue());

// 2. 报错后下一轮已修好 → 完成
$r2 = $rm->reflect(history([
    [['content' => $fatal, 'is_error' => true]],
    [['content' => '已写入 site/index.php', 'is_error' => false]],
]), '目标', ['iteration' => 5]);
test('场景2 报错后已修好 → 完成', $r2->isSuccess());

// 3. 一个工具都没调过（首轮）→ 继续
$r3 = $rm->reflect([
    ['role' => 'user', 'content' => '帮我解决一个问题'],
    ['role' => 'assistant', 'content' => '让我先分析一下'],
], '目标', ['iteration' => 0, 'isFirstRound' => true]);
test('场景3 首轮没调工具 → 继续', $r3->shouldContinue());

// 4. 调过工具、无报错、给出结论（iteration 1）→ 完成
//    ——这条是本次踩到的：结论被兜底判据顶掉
$r4 = $rm->reflect(history(
    [[['content' => '4928 字节', 'is_error' => false]]],
    '结论已经有了。页面标题输出在 layouts/main.php 第 8 行。'
), '页面标题在哪里输出', ['iteration' => 1]);
test('场景4 有结论无报错 → 完成（不再多逼一轮）', $r4->isSuccess());

// 5. 连撞 5 次同一个报错（iteration 8）→ 完成（继续重试没有进展）
$r5 = $rm->reflect(history(array_fill(0, 5, [['content' => $fatal, 'is_error' => true]])), '目标', ['iteration' => 8]);
test('场景5 同一报错反复出现 → 停手', $r5->isSuccess());
test('场景5 标记为 stalled', $r5->meta('stalled') === true);

// ===== 三、只看最后一批工具结果 =====

// 旧报错已被后续批次覆盖，不该拿它反复逼模型
$r6 = $rm->reflect(history([
    [['content' => $fatal, 'is_error' => true]],
    [['content' => 'ok', 'is_error' => false]],
    [['content' => 'ok', 'is_error' => false]],
]), '目标', ['iteration' => 3]);
test('旧报错不再触发继续', $r6->isSuccess());

// 一批里有一个报错就算这批有错
$r7 = $rm->reflect(history([[
    ['content' => 'ok', 'is_error' => false],
    ['content' => $fatal, 'is_error' => true],
]]), '目标', ['iteration' => 3]);
test('一批里混有报错 → 继续', $r7->shouldContinue());

// 工具成功但正文里带 "error" 字样，不该被误判成报错
$r8 = $rm->reflect(history([[
    ['content' => "function handleError() {\n  // error handling\n}", 'is_error' => false],
]]), '目标', ['iteration' => 3]);
test('正文含 error 但 is_error=false → 完成', $r8->isSuccess());

// ===== 四、OpenAI 风格 role=tool 的历史仍然认得 =====

$r9 = $rm->reflect([
    ['role' => 'user', 'content' => '修改代码'],
    ['role' => 'assistant', 'content' => [
        ['type' => 'text', 'text' => '我来修改文件'],
        ['type' => 'tool_use', 'name' => 'edit_file', 'id' => 't1'],
    ]],
    ['role' => 'tool', 'content' => 'Parse error: syntax error'],
], '修改代码', ['iteration' => 3]);
test('role=tool 的字符串报错也认得', $r9->shouldContinue());

// ===== 五、maxRounds 不再短路自定义策略 =====

$called = false;
$rmS = new ReflectionManager([
    'maxRounds' => 3,
    'strategy'  => function ($messages, $goal, $context) use (&$called) {
        $called = true;
        return ReflectionResult::continuing('自定义判据说没完', '接着干');
    },
]);
$rS = $rmS->reflect([], '目标', ['iteration' => 99]);
test('迭代号远超 maxRounds 时自定义策略仍被调用', $called);
test('超限后仍由上限收口（不会无限继续）', $rS->isSuccess());

// 未超限时自定义策略的结论原样返回
$rS2 = $rmS->reflect([], '目标', ['reflection_round' => 0]);
assert_eq('未超限时保留自定义原因', '自定义判据说没完', $rS2->getReason());
assert_eq('未超限时保留下一步', '接着干', $rS2->getNextAction());

// 策略返回了不是 ReflectionResult 的东西时不炸
$rmBad = new ReflectionManager(['strategy' => function () { return null; }]);
test('策略返回非 ReflectionResult → 按完成处理', $rmBad->reflect([], '目标')->isSuccess());

// ===== 六、reflection_round 与 iteration 分开 =====

$rmR = new ReflectionManager(['maxRounds' => 3]);
$errMsgs = history([[['content' => $fatal, 'is_error' => true]]]);

// 迭代号很大但只反思过 1 轮 → 不该被上限收口，报错要继续修
$rR1 = $rmR->reflect($errMsgs, '目标', ['iteration' => 20, 'reflection_round' => 1]);
test('迭代号大但反思轮数小 → 报错仍判继续', $rR1->shouldContinue());

// 反思轮数到顶 → 收口
$rR2 = $rmR->reflect($errMsgs, '目标', ['iteration' => 20, 'reflection_round' => 3]);
test('反思轮数到顶 → 收口', $rR2->isSuccess());

// 没给 reflection_round 时退回迭代号（兼容旧调用方）
$rR3 = $rmR->reflect($errMsgs, '目标', ['iteration' => 5]);
test('缺 reflection_round 时退回迭代号', $rR3->isSuccess());

// ===== 七、端到端：模型交卷后不再被硬逼一轮 =====

class ReflectTransport implements \Ai\Contracts\TransportInterface
{
    public $rounds = 0;

    public function post(string $url, array $data, array $headers = []): array
    {
        $this->rounds++;
        if ($this->rounds === 1) {
            return ['choices' => [['message' => [
                'role'       => 'assistant',
                'content'    => '',
                'tool_calls' => [[
                    'id'       => 'call_1',
                    'type'     => 'function',
                    'function' => ['name' => 'read_file', 'arguments' => '{"path":"layouts/main.php"}'],
                ]],
            ], 'finish_reason' => 'tool_calls']]];
        }
        if ($this->rounds === 2) {
            return ['choices' => [['message' => [
                'role'    => 'assistant',
                'content' => '结论已经有了。页面标题输出在 layouts/main.php 第 8 行。',
            ], 'finish_reason' => 'stop']]];
        }
        // 被硬逼出来的第三轮——模型只会困惑地反问，这句话不该成为最终答案
        return ['choices' => [['message' => [
            'role'    => 'assistant',
            'content' => '这个反思似乎是系统残留的通用提示，并非针对我当前任务。',
        ], 'finish_reason' => 'stop']]];
    }
    public function get(string $url, array $params = [], array $headers = []): array { return []; }
    public function setTimeout(int $t): \Ai\Contracts\TransportInterface { return $this; }
    public function setProxy(string $p): \Ai\Contracts\TransportInterface { return $this; }
    public function setStreamCallback(?callable $cb): \Ai\Contracts\TransportInterface { return $this; }
}

$tr = new ReflectTransport();
$ai = AI::create(['protocol' => 'openai', 'model' => 'deepseek-anthropic', 'api_key' => 'sk-test']);
$ai->setTransport($tr);

$agent = Agent::create($ai)
    ->tools(['read_file' => [
        'description'  => '读文件',
        'input_schema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]],
        'handler'      => function (array $input) { return '<title><?= $title ?></title>'; },
    ]])
    ->setPermissionMode('bypass')
    ->enableReflection();
$agent->setGoal('页面标题在哪里输出');

$reflections = [];
$agent->onEvent(function ($event) use (&$reflections) {
    if (isset($event['type']) && $event['type'] === 'reflection') {
        $reflections[] = $event;
    }
});
$agent->run([['role' => 'user', 'content' => '页面标题在哪里输出？']]);

assert_eq('模型只被调用两轮，没有被硬逼第三轮', 2, $tr->rounds);
assert_eq('最终答案是模型给出的结论', '结论已经有了。页面标题输出在 layouts/main.php 第 8 行。', $agent->lastText());
assert_eq('反思只发生一次', 1, count($reflections));
test('那一次反思判定为完成', !empty($reflections[0]['success']));

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);
