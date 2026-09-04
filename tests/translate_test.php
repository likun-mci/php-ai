<?php
/**
 * Translate helper 测试：Edge 免费接口 + LLM 兜底
 *
 * 覆盖 dev.md v2.1 §1.5：chunk 分片、parseEdgeResponse 解析、to() 门面形状与
 * 彻底失败原样返回。实网络用例用 PHPAI_LIVE_NET=1 门控，默认跳过（CI 无外网也过）。
 *
 * 运行：php tests/translate_test.php
 * 实网络：PHPAI_LIVE_NET=1 php tests/translate_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Helpers\Translate;

$passed = 0;
$failed = 0;
function test($name, $ok)
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "✓ {$name}\n"; }
    else { $failed++; echo "✗ {$name}\n"; }
}

// ===== 一、chunk 分片 =====
echo "=== 一、chunk 分片 ===\n";
$many = array_fill(0, 1200, 'x');
$chunks = Translate::chunk($many, 500, 45000);
test('按条数分片：1200 → 3 片', count($chunks) === 3);
test('每片不超过 500 条', count($chunks[0]) === 500 && count($chunks[2]) === 200);

$longs = ['a', str_repeat('中', 40000), str_repeat('文', 40000), 'b'];
$chunks2 = Translate::chunk($longs, 500, 45000);
// 两个 4 万字符项合起来 8 万 > 45000，必被拆到不同片
test('按字符数分片成 2 片', count($chunks2) === 2);
test('两个超长项不在同一片', count($chunks2[0]) === 2 && count($chunks2[1]) === 2);

test('空数组返回空', Translate::chunk([]) === []);

// ===== 二、parseEdgeResponse =====
echo "\n=== 二、parseEdgeResponse ===\n";
$good = '[{"translations":[{"text":"Hello","to":"en"}]},{"translations":[{"text":"World","to":"en"}]}]';
$parsed = Translate::parseEdgeResponse($good, 2);
test('正常解析按序译文', $parsed === ['Hello', 'World']);
test('条数不符返回 null', Translate::parseEdgeResponse($good, 3) === null);
test('结构不符返回 null', Translate::parseEdgeResponse('[{"foo":1}]', 1) === null);
test('非法 JSON 返回 null', Translate::parseEdgeResponse('不是json', 1) === null);

// ===== 三、to() 门面形状 =====
echo "\n=== 三、to() 门面 ===\n";
// engine=llm 且无 ai → llm 返回 []，to() 彻底失败原样返回输入（绝不吞成空）
$rStr = Translate::to('你好', 'en', ['engine' => 'llm']);
test('标量失败原样返回字符串', $rStr === '你好');
$rArr = Translate::to(['你好', '世界'], 'en', ['engine' => 'llm']);
test('数组失败原样返回数组', $rArr === ['你好', '世界']);
test('空输入标量返回空串', Translate::to('', 'en', ['engine' => 'llm']) === '');
test('空输入数组返回空数组', Translate::to([], 'en', ['engine' => 'llm']) === []);

// ===== 四、实网络 Edge（默认跳过）=====
echo "\n=== 四、实网络 Edge（PHPAI_LIVE_NET 门控）===\n";
if (getenv('PHPAI_LIVE_NET') === '1') {
    $out = Translate::edge(['项目使用 CodeIgniter 3', '默认持久化'], 'en', 'zh-Hans');
    test('Edge 返回 2 条译文', count($out) === 2);
    test('译文非空且像英文', $out && stripos($out[0], 'CodeIgniter') !== false);
    $one = Translate::to('你好世界', 'en', ['from' => 'zh-Hans']);
    test('to() 标量翻译成功', is_string($one) && $one !== '你好世界');
} else {
    echo "  (未设 PHPAI_LIVE_NET=1，跳过实网络用例)\n";
}

// ===== 五、TranslateTool（离线：参数校验 + 权限）=====
echo "\n=== 五、TranslateTool ===\n";
$tt = new \Ai\Agent\Tools\TranslateTool();
$ctx = new \Ai\Agent\Tool\ToolContext([]);
test('translate 名', $tt->name() === 'translate');
test('空 text 报错', !$tt->execute(['text' => '', 'to' => 'en'], $ctx)->isSuccess());
test('空 to 报错', !$tt->execute(['text' => 'x', 'to' => ''], $ctx)->isSuccess());

$manual = new \Ai\Agent\Permission\PermissionManager(\Ai\Agent\Permission\PermissionManager::MODE_MANUAL);
$pr = $manual->check($tt, ['text' => 'x', 'to' => 'en'], $ctx);
test('translate 归外呼档：manual 询问', $pr->needsAsk() || $pr->isDenied());
$dontAsk = new \Ai\Agent\Permission\PermissionManager(\Ai\Agent\Permission\PermissionManager::MODE_DONT_ASK);
test('dont_ask 放行 translate', $dontAsk->check($tt, [], $ctx)->isAllowed());

$web = \Ai\Agent\Tools\ClaudeCodeTools::web();
test('web() 含 translate', isset($web['translate']) && $web['translate'] instanceof \Ai\Agent\Tools\TranslateTool);

echo "\n" . str_repeat('=', 50) . "\n";
if ($failed === 0) { echo "全部通过：{$passed} 通过，0 失败\n"; exit(0); }
echo "有失败：{$passed} 通过，{$failed} 失败\n";
exit(1);
