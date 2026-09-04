<?php
/**
 * LlmReflectionStrategy 测试（dev.md 第三梯队 2）
 *
 * 覆盖：JSON 契约解析、done/未完成两条路、围栏与废话容错、异常与不可解析时降级到
 * fallback、无 fallback 时保守判完成、transcript 抽取工具调用/报错、接入 ReflectionManager。
 *
 * 运行：php tests/agent_llm_reflection_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Reflection\ReflectionManager;
use Ai\Agent\Reflection\ReflectionResult;
use Ai\Agent\Reflection\LlmReflectionStrategy;

class ScriptedTransportLR implements \Ai\Contracts\TransportInterface
{
    public $responses = [];
    public $requests  = [];
    public $throw = false;
    public function post(string $url, array $data, array $headers = []): array
    {
        $this->requests[] = $data;
        if ($this->throw) { throw new \RuntimeException('网络炸了'); }
        if (!$this->responses) {
            return ['choices' => [['message' => ['role' => 'assistant', 'content' => '{"done":true,"reason":"默认"}'], 'finish_reason' => 'stop']]];
        }
        return array_shift($this->responses);
    }
    public function get(string $url, array $params = [], array $headers = []): array { return []; }
    public function setTimeout(int $t): \Ai\Contracts\TransportInterface { return $this; }
    public function setProxy(string $p): \Ai\Contracts\TransportInterface { return $this; }
    public function setStreamCallback(?callable $cb): \Ai\Contracts\TransportInterface { return $this; }
}

$passed = 0;
$failed = 0;
function test($name, $ok)
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "✓ {$name}\n"; }
    else { $failed++; echo "✗ {$name}\n"; }
}
function reply($text)
{
    return ['choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']]];
}
function makeAi(array $responses, $throw = false)
{
    $t = new ScriptedTransportLR();
    $t->responses = $responses;
    $t->throw = $throw;
    $ai = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
    $ai->setTransport($t);
    return [$ai, $t];
}

$msgs = [
    ['role' => 'user', 'content' => '把登录改成 JWT'],
    ['role' => 'assistant', 'content' => '好的，我来改'],
];

// ===== 一、判定完成 =====
echo "=== 一、判定完成 ===\n";
list($ai) = makeAi([reply('{"done":true,"reason":"JWT 已接入并通过测试"}')]);
$s = new LlmReflectionStrategy($ai);
$r = $s($msgs, '把登录改成 JWT');
test('返回 ReflectionResult', $r instanceof ReflectionResult);
test('判完成', $r->isSuccess() && !$r->shouldContinue());
test('带回模型理由', strpos($r->getReason(), 'JWT 已接入') !== false);
test('metadata 标记 llm', $r->meta('strategy') === 'llm');

// ===== 二、判定未完成 + next =====
echo "\n=== 二、判定未完成 ===\n";
list($ai) = makeAi([reply('{"done":false,"reason":"还没写测试","next":"补充单元测试"}')]);
$r = (new LlmReflectionStrategy($ai))($msgs, '把登录改成 JWT');
test('判继续', $r->shouldContinue());
test('理由正确', strpos($r->getReason(), '还没写测试') !== false);
test('给出下一步', $r->getNextAction() === '补充单元测试');

// ===== 三、容错：围栏与前后废话 =====
echo "\n=== 三、输出容错 ===\n";
list($ai) = makeAi([reply("好的，我的判断如下：\n```json\n{\"done\": true, \"reason\": \"完事\"}\n```\n以上。")]);
$r = (new LlmReflectionStrategy($ai))($msgs, 'x');
test('能从围栏+废话里抽出 JSON', $r->isSuccess() && strpos($r->getReason(), '完事') !== false);

// ===== 四、降级：请求异常 → fallback =====
echo "\n=== 四、异常降级 ===\n";
list($ai) = makeAi([], true);
$called = false;
$fallback = function ($m, $g, $c) use (&$called) {
    $called = true;
    return ReflectionResult::continuing('兜底判继续');
};
$r = (new LlmReflectionStrategy($ai, ['fallback' => $fallback]))($msgs, 'x');
test('异常时调用了 fallback', $called);
test('采用 fallback 结果', $r->shouldContinue() && $r->getReason() === '兜底判继续');

// ===== 五、降级：不可解析 → fallback =====
echo "\n=== 五、不可解析降级 ===\n";
list($ai) = makeAi([reply('我觉得应该差不多完成了吧')]);
$called2 = false;
$fb2 = function ($m, $g, $c) use (&$called2) { $called2 = true; return ReflectionResult::continuing('兜底2'); };
$r = (new LlmReflectionStrategy($ai, ['fallback' => $fb2]))($msgs, 'x');
test('不可解析时走 fallback', $called2 && $r->getReason() === '兜底2');

// ===== 六、无 fallback 时保守判完成 =====
echo "\n=== 六、无 fallback ===\n";
list($ai) = makeAi([reply('不是 JSON')]);
$r = (new LlmReflectionStrategy($ai))($msgs, 'x');
test('无兜底时判完成（不空转）', $r->isSuccess() && !$r->shouldContinue());
test('原因写明降级', strpos($r->getReason(), '按完成处理') !== false);
test('metadata 标记降级', $r->meta('strategy') === 'llm_degraded');

// ===== 七、transcript 抽取工具调用与报错 =====
echo "\n=== 七、提示词内容 ===\n";
list($ai, $t) = makeAi([reply('{"done":false,"reason":"仍在报错"}')]);
$toolMsgs = [
    ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'name' => 'bash', 'id' => 'x1']]],
    ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'x1', 'is_error' => true, 'content' => 'PHP Fatal error: 语法错']]],
];
$r = (new LlmReflectionStrategy($ai))($toolMsgs, '修复语法错');
$sent = json_encode($t->requests[0], JSON_UNESCAPED_UNICODE);
test('提示词含目标', strpos($sent, '修复语法错') !== false);
test('提示词标出工具调用', strpos($sent, '调用工具 bash') !== false);
test('提示词标出工具报错', strpos($sent, '[工具报错]') !== false);
test('要求 JSON 输出契约', strpos($sent, 'done') !== false && strpos($sent, '任务完成度评审') !== false);

// ===== 八、接入 ReflectionManager + defaultStrategy 兜底 =====
echo "\n=== 八、接入 ReflectionManager ===\n";
$rm = new ReflectionManager();
test('defaultStrategy 返回可调用', is_callable($rm->defaultStrategy()));
$dr = call_user_func($rm->defaultStrategy(), $msgs, 'x', []);
test('defaultStrategy 可用', $dr instanceof ReflectionResult);

list($ai) = makeAi([reply('{"done":false,"reason":"继续干","next":"写测试"}')]);
$rm->setStrategy(new LlmReflectionStrategy($ai, ['fallback' => $rm->defaultStrategy()]));
$r = $rm->reflect($msgs, '把登录改成 JWT', ['reflection_round' => 1]);
test('管理器采用 LLM 策略', $r->shouldContinue() && $r->meta('strategy') === 'llm');

// 轮数上限仍是最后一道闸
list($ai) = makeAi([reply('{"done":false,"reason":"还要继续"}')]);
$rm2 = new ReflectionManager(['maxRounds' => 2]);
$rm2->setStrategy(new LlmReflectionStrategy($ai));
$r = $rm2->reflect($msgs, 'x', ['reflection_round' => 5]);
test('超过 maxRounds 仍会收口', !$r->shouldContinue());

echo "\n" . str_repeat('=', 50) . "\n";
if ($failed === 0) { echo "全部通过：{$passed} 通过，0 失败\n"; exit(0); }
echo "有失败：{$passed} 通过，{$failed} 失败\n";
exit(1);
