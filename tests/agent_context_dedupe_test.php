<?php
/**
 * ContextManager：文件读取去重 + 可插拔 tokenizer（dev.md 第二梯队 3）
 *
 * 运行：php tests/agent_context_dedupe_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Context\ContextManager;

$passed = 0;
$failed = 0;
function test($name, $ok)
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "✓ {$name}\n"; }
    else { $failed++; echo "✗ {$name}\n"; }
}

/** 构造一条 read_file 的 tool_result 消息（Anthropic 形态） */
function readMsg($id, $path, $body)
{
    return [
        'role' => 'user',
        'content' => [[
            'type' => 'tool_result',
            'tool_use_id' => $id,
            'content' => "File: {$path} (3 lines, 100 bytes)\n" . str_repeat('-', 40) . "\n" . $body,
        ]],
    ];
}
function useMsg($id)
{
    return ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => $id, 'name' => 'read_file', 'input' => []]]];
}

// ===== 一、同文件多次读取只留最新 =====
echo "=== 一、文件读取去重 ===\n";
$msgs = [
    ['role' => 'user', 'content' => '看看 a.php'],
    useMsg('t1'), readMsg('t1', 'src/a.php', 'VERSION-1'),
    useMsg('t2'), readMsg('t2', 'src/b.php', 'B-ONLY'),
    useMsg('t3'), readMsg('t3', 'src/a.php', 'VERSION-2'),
    useMsg('t4'), readMsg('t4', 'src/a.php', 'VERSION-3-LATEST'),
];
$cm = new ContextManager($msgs);
$n = $cm->dedupeFileReads();
test('折叠了 2 份较早的 a.php', $n === 2);

$all = json_encode($cm->messages(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
test('最新一份 VERSION-3 保留', strpos($all, 'VERSION-3-LATEST') !== false);
test('较早 VERSION-1 已省略', strpos($all, 'VERSION-1') === false);
test('较早 VERSION-2 已省略', strpos($all, 'VERSION-2') === false);
test('占位说明含文件路径', strpos($all, 'src/a.php') !== false);
test('不同文件 b.php 不受影响', strpos($all, 'B-ONLY') !== false);

// 结构完整性：tool_use / tool_result 配对没被破坏
$out = $cm->messages();
test('消息条数不变', count($out) === count($msgs));
$ids = [];
foreach ($out as $m) {
    if (!is_array($m['content'])) { continue; }
    foreach ($m['content'] as $b) {
        if (isset($b['type']) && $b['type'] === 'tool_result') { $ids[] = $b['tool_use_id']; }
    }
}
test('全部 tool_use_id 保留', $ids === ['t1', 't2', 't3', 't4']);

// ===== 二、幂等 =====
echo "\n=== 二、幂等 ===\n";
test('再次去重无可折叠', $cm->dedupeFileReads() === 0);

// ===== 三、OpenAI 形态（role=tool，content 为字符串）=====
echo "\n=== 三、OpenAI 形态 ===\n";
$oa = [
    ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => "File: x.php (1 lines, 10 bytes)\n----\nOLD"],
    ['role' => 'tool', 'tool_call_id' => 'c2', 'content' => "File: x.php (1 lines, 10 bytes)\n----\nNEW"],
];
$cm2 = new ContextManager($oa);
test('OpenAI 形态折叠 1 份', $cm2->dedupeFileReads() === 1);
$s2 = json_encode($cm2->messages(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
test('保留 NEW', strpos($s2, 'NEW') !== false);
test('省略 OLD', strpos($s2, 'OLD') === false);

// ===== 四、非文件读取结果不动 =====
echo "\n=== 四、非文件结果不受影响 ===\n";
$other = [
    useMsg('b1'),
    ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'b1', 'content' => 'Bash: 命令输出 abc']]],
];
$cm3 = new ContextManager($other);
test('bash 结果不被折叠', $cm3->dedupeFileReads() === 0);
test('bash 内容原样', strpos(json_encode($cm3->messages(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'abc') !== false);

// ===== 五、可插拔 tokenizer =====
echo "\n=== 五、可插拔 tokenizer ===\n";
$cm4 = new ContextManager([['role' => 'user', 'content' => 'hello world']]);
$builtin = $cm4->tokenCount();
test('内置估算 > 0', $builtin > 0);
$cm4->setTokenizer(function ($text) { return 999; });
test('自定义 tokenizer 生效', $cm4->tokenCount() === 999);
$cm4->setTokenizer(null);
test('置空后回到内置估算', $cm4->tokenCount() === $builtin);

// ===== 六、compact 会先去重 =====
echo "\n=== 六、compact 前置去重 ===\n";
$big = [['role' => 'user', 'content' => 'go']];
for ($i = 0; $i < 12; $i++) {
    $big[] = useMsg("k{$i}");
    $big[] = readMsg("k{$i}", 'src/same.php', 'CONTENT-' . $i);
}
$cm5 = new ContextManager($big, ['keepRecent' => 4]);
$cm5->compact(function ($text) { return '摘要'; });
$s5 = json_encode($cm5->messages(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
test('compact 后最新内容仍在', strpos($s5, 'CONTENT-11') !== false);
test('compact 后较早重复内容已省', strpos($s5, 'CONTENT-0"') === false && strpos($s5, 'CONTENT-1"') === false);

echo "\n" . str_repeat('=', 50) . "\n";
if ($failed === 0) { echo "全部通过：{$passed} 通过，0 失败\n"; exit(0); }
echo "有失败：{$passed} 通过，{$failed} 失败\n";
exit(1);
