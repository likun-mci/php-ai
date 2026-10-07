<?php
/**
 * UTF-8 截断安全测试
 *
 * 这组用例来自一次真实故障：`bash` 工具把中文输出按字节截断，切出半个汉字，
 * 下一次模型请求的 json_encode() 直接返回 false，整个 Agent 运行中断。
 *
 * 覆盖：
 *   1. Text::cutBytes 按字节切且不劈开字符
 *   2. Text::cutChars / ellipsis 按字符切
 *   3. 所有会回填给模型的截断点都产出合法 UTF-8
 *   4. 截断后的内容必须能 json_encode（这是真正的失败点）
 *
 * 运行：php tests/text_truncate_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Approval\ApprovalRequest;
use Ai\Agent\Orchestrator\ArtifactManager;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tools\BashTool;
use Ai\Helpers\Text;

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

/** 内容能否放进模型请求体 */
function encodable($content)
{
    return json_encode(['messages' => [['role' => 'user', 'content' => $content]]], JSON_UNESCAPED_UNICODE) !== false;
}

$zh = str_repeat('修复登录接口的越权漏洞', 60);   // 660 字符 / 1980 字节

// ===== 一、Text::cutBytes =====

echo "\n=== 一、按字节截断 ===\n";

// 汉字 3 字节，逐个字节试过去，每一个切点都必须是合法 UTF-8
$allValid = true;
$allWithinLimit = true;
for ($n = 1; $n <= 60; $n++) {
    $cut = Text::cutBytes($zh, $n);
    if (!Text::isValidUtf8($cut)) {
        $allValid = false;
    }
    if (strlen($cut) > $n) {
        $allWithinLimit = false;
    }
}
test('任意字节切点都产出合法 UTF-8', $allValid);
test('任意字节切点都不超出上限', $allWithinLimit);

test('切点落在字符中间时向下取整', strlen(Text::cutBytes($zh, 100)) === 99);
assert_eq('未超长时原样返回', '短文本', Text::cutBytes('短文本', 1000));
assert_eq('上限为 0 时原样返回', $zh, Text::cutBytes($zh, 0));
assert_eq('负数上限原样返回', $zh, Text::cutBytes($zh, -5));
assert_eq('空串安全', '', Text::cutBytes('', 10));
test('纯 ASCII 精确截断', Text::cutBytes('abcdefghij', 4) === 'abcd');

// 对比：错误写法确实会切坏（这条用例说明为什么需要这个助手）
test('substr 会切出非法 UTF-8（反例）', !Text::isValidUtf8(substr($zh, 0, 100)));
test('mb_substr 不限字节（反例）', strlen(mb_substr($zh, 0, 100, 'UTF-8')) === 300);

// ===== 二、按字符截断 =====

echo "\n=== 二、按字符截断 ===\n";

assert_eq('cutChars 按字符数', 100, Text::length(Text::cutChars($zh, 100)));
test('cutChars 结果合法', Text::isValidUtf8(Text::cutChars($zh, 100)));
assert_eq('未超长不截断', '短', Text::cutChars('短', 10));
assert_eq('ellipsis 补省略号', '修复登…', Text::ellipsis('修复登录接口', 3));
assert_eq('ellipsis 未超长不加后缀', '短', Text::ellipsis('短', 10));
assert_eq('length 数字符不数字节', 660, Text::length($zh));
test('isValidUtf8 判合法', Text::isValidUtf8($zh));
test('isValidUtf8 判非法', !Text::isValidUtf8(substr($zh, 0, 100)));

// ===== 三、BashTool 输出截断（真实故障点）=====

echo "\n=== 三、BashTool 输出截断 ===\n";

$tool = new BashTool(10, 1024);   // (timeout, maxOutputBytes)，下限 1024
$ctx  = new ToolContext(['workdir' => sys_get_temp_dir()]);
$result = $tool->execute(
    ['command' => 'php -r \'echo str_repeat("修复登录接口的越权漏洞", 60);\''],
    $ctx
);
$content = $result->getContent();

test('输出确实被截断了', strlen($content) < strlen($zh));
test('截断后仍是合法 UTF-8', Text::isValidUtf8($content));
test('截断后能放进模型请求体', encodable($content));

// ===== 四、其它会回填给模型的截断点 =====

echo "\n=== 四、其它截断点 ===\n";

$artifacts = new ArtifactManager();
$ref = $artifacts->put('task_1', 'log.txt', $zh);
$preview = $artifacts->preview($ref, 100);
test('产物预览合法', Text::isValidUtf8($preview));
test('产物预览可编码', encodable($preview));

$request = new ApprovalRequest('req_1', ['summary' => '改动', 'diff' => $zh]);
$summary = $request->toSummary(100);
test('审批摘要合法', Text::isValidUtf8($summary));
test('审批摘要可编码', encodable($summary));

$aggregator = new \Ai\Agent\Orchestrator\ResultAggregator(['perResultLimit' => 55]);
$agg = $aggregator->aggregate([
    ['agent' => 'explorer', 'task' => '调查', 'status' => 'completed', 'summary' => $zh],
]);
test('聚合摘要合法', Text::isValidUtf8($agg['summary']));
test('聚合摘要可编码', encodable($agg['summary']));

// 反思里的错误摘要
$rm = new \Ai\Agent\Reflection\ReflectionManager();
$reflection = $rm->reflect([
    ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'c1', 'name' => 'bash', 'input' => []]]],
    ['role' => 'tool', 'content' => 'error: ' . $zh],
], '目标');
$meta = $reflection->getMetadata();
if (isset($meta['errors'][0])) {
    test('反思错误摘要合法', Text::isValidUtf8($meta['errors'][0]));
    test('反思错误摘要可编码', encodable($meta['errors'][0]));
} else {
    echo "  （反思未产生错误摘要，跳过）\n";
}

// ===== 五、sanitizeUtf8：外部灌进来的坏字节 =====
// 截断只是「自己别切出坏字节」，管不了 bash 里 cat 一张图、抓回 GBK 网页带进来的坏字节。
// 那是同一个故障的另一半：坏字节一旦落进消息历史，**之后每一轮**请求都发不出去。

echo "\n=== 五、外部坏字节净化 ===\n";

$gbk  = mb_convert_encoding('汉字', 'GBK', 'UTF-8');
$latin = "\xB4\xF3\xD6\xD0";

assert_eq('合法文本原样返回', '中文 ok', Text::sanitizeUtf8('中文 ok'));
test('GBK 字节被洗成合法 UTF-8', Text::isValidUtf8(Text::sanitizeUtf8($gbk)));
test('latin1 字节被洗成合法 UTF-8', Text::isValidUtf8(Text::sanitizeUtf8($latin)));
test('洗过的内容可放进请求体', encodable(Text::sanitizeUtf8($latin)));
test('合法部分一字不动', Text::sanitizeUtf8("前缀 ok {$latin} 后缀 ok") === "前缀 ok \u{FFFD} 后缀 ok");

// 截断的 emoji（末字节被切掉）也是常见来源
$emoji = 'abc' . substr('😀', 0, 3);
test('截断的 emoji 被洗成合法 UTF-8', Text::isValidUtf8(Text::sanitizeUtf8($emoji)));
test('完整 emoji 不受影响', Text::sanitizeUtf8('😀 测试') === '😀 测试');

// 连续坏字节折叠成一个替换字符：否则一行二进制会灌成上万个 �
$blob = str_repeat("\xFF", 200);
test('连续坏字节折叠成一个替换字符', Text::sanitizeUtf8($blob) === "\u{FFFD}");
test('整段二进制洗成合法 UTF-8', Text::isValidUtf8(Text::sanitizeUtf8(random_bytes(4096))));

// 嵌套结构的所有层都要过一遍：json_encode 会在任意一层失败
$deep = ['m' => [['role' => 'user', 'content' => [['text' => "bad {$latin}"]]]]];
test('sanitizeDeep 逐层净化', encodable(Text::sanitizeDeep($deep)));

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);
