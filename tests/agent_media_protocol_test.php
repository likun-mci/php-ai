<?php
/**
 * P2 协议层媒体翻译测试
 *
 * 覆盖 dev.md 进度表 P2：agent_media → 两个协议家族的图片块、
 * 不支持模态时的「明确告知」而非静默降级、以及坑③（附件覆盖数组 content
 * 破坏 tool_use/tool_result 配对）在全部 8 个模型类上的回归。
 *
 * 全程临时目录，不发网络请求。
 *
 * 运行：php tests/agent_media_protocol_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Media\FileMediaStore;
use Ai\Agent\Media\MediaResolver;
use Ai\Helpers\MediaTranslator;
use Ai\Helpers\Tools;

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
    if ($expected !== $actual) {
        echo "  期望: " . var_export($expected, true) . "\n  实际: " . var_export($actual, true) . "\n";
    }
    test($name, $expected === $actual);
}
function rrmdir($dir)
{
    if (!is_dir($dir)) { return; }
    $items = scandir($dir);
    if ($items === false) { return; }
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') { continue; }
        $p = $dir . '/' . $it;
        if (is_dir($p) && !is_link($p)) { rrmdir($p); } else { @unlink($p); }
    }
    @rmdir($dir);
}
/** 从一批消息里找出第一个指定类型的块 */
function find_block(array $messages, $type)
{
    foreach ($messages as $m) {
        if (!isset($m['content']) || !is_array($m['content'])) { continue; }
        foreach ($m['content'] as $b) {
            if (is_array($b) && isset($b['type']) && $b['type'] === $type) { return $b; }
        }
    }
    return null;
}

$tmp = sys_get_temp_dir() . '/php-ai-media-proto_' . getmypid();
rrmdir($tmp);
@mkdir($tmp, 0700, true);

$PNG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n";

$store = new FileMediaStore($tmp . '/store');
$imgRef = $store->put($PNG, ['mime' => 'image/png', 'name' => 'screenshot.png']);
$pdfRef = $store->put($PDF, ['mime' => 'application/pdf', 'name' => 'doc.pdf']);
$imgBlock = $imgRef->toBlock();
$pdfBlock = $pdfRef->toBlock();

$resolver = new MediaResolver($store);

// ===================================================================
echo "\n=== 1. 家族归一化 ===\n";
// ===================================================================

assert_eq('anthropic', 'anthropic', MediaTranslator::normalizeFamily('anthropic'));
assert_eq('claude 也归到 anthropic', 'anthropic', MediaTranslator::normalizeFamily('claude'));
assert_eq('openai', 'openai', MediaTranslator::normalizeFamily('openai'));
assert_eq('未知家族按 openai 处理', 'openai', MediaTranslator::normalizeFamily('whatever'));

// ===================================================================
echo "\n=== 2. Anthropic 家族翻译 ===\n";
// ===================================================================

MediaTranslator::begin($resolver, ['image' => true, 'pdf' => true]);
$content = [['type' => 'text', 'text' => '看这张图'], $imgBlock];
$out = MediaTranslator::translateContent($content, MediaTranslator::FAMILY_ANTHROPIC);

assert_eq('块数不变', 2, count($out));
assert_eq('文本块原样保留', 'text', $out[0]['type']);
assert_eq('媒体块变成 image', 'image', $out[1]['type']);
assert_eq('source.type', 'base64', $out[1]['source']['type']);
assert_eq('source.media_type', 'image/png', $out[1]['source']['media_type']);
assert_eq('source.data 是正确的 base64', base64_encode($PNG), $out[1]['source']['data']);
test('翻译后不再有 agent_media', json_encode($out) !== false && strpos(json_encode($out), 'agent_media') === false);

$pdfOut = MediaTranslator::translateContent([$pdfBlock], MediaTranslator::FAMILY_ANTHROPIC);
assert_eq('PDF 翻成 document 块', 'document', $pdfOut[0]['type']);
assert_eq('PDF 的 media_type', 'application/pdf', $pdfOut[0]['source']['media_type']);
assert_eq('没有被降级', 0, count(MediaTranslator::skipped()));
MediaTranslator::end();

// ===================================================================
echo "\n=== 3. OpenAI 家族翻译 ===\n";
// ===================================================================

MediaTranslator::begin($resolver, ['image' => true, 'pdf' => false]);
$out = MediaTranslator::translateContent([['type' => 'text', 'text' => '看图'], $imgBlock], MediaTranslator::FAMILY_OPENAI);
assert_eq('媒体块变成 image_url', 'image_url', $out[1]['type']);
assert_eq('data URI 前缀正确', 'data:image/png;base64,' . base64_encode($PNG), $out[1]['image_url']['url']);
MediaTranslator::end();

// ===================================================================
echo "\n=== 4. 不支持时明确告知，不静默降级 ===\n";
// ===================================================================

MediaTranslator::begin($resolver, ['image' => false, 'pdf' => false]);
$out = MediaTranslator::translateContent([['type' => 'text', 'text' => '看图'], $imgBlock], MediaTranslator::FAMILY_OPENAI);
assert_eq('媒体块被换成 text', 'text', $out[1]['type']);
$notice = $out[1]['text'];
test('说明里点了文件名', strpos($notice, 'screenshot.png') !== false);
test('说明里写明「无法查看」', strpos($notice, '无法查看') !== false);
test('明确要求模型不要臆测', strpos($notice, '不要臆测') !== false);
test('不使用会诱导模型的占位写法', strpos($notice, '[图片:') === false);

$skipped = MediaTranslator::skipped();
assert_eq('降级被记录', 1, count($skipped));
assert_eq('降级原因', 'image_not_supported', $skipped[0]['reason']);
assert_eq('降级记录带文件名', 'screenshot.png', $skipped[0]['name']);
MediaTranslator::end();

// PDF 不支持但图片支持：只降级 PDF
MediaTranslator::begin($resolver, ['image' => true, 'pdf' => false]);
$out = MediaTranslator::translateContent([$imgBlock, $pdfBlock], MediaTranslator::FAMILY_OPENAI);
assert_eq('图片正常翻译', 'image_url', $out[0]['type']);
assert_eq('PDF 被降级成说明', 'text', $out[1]['type']);
assert_eq('只降级了一个', 1, count(MediaTranslator::skipped()));
assert_eq('降级原因是 pdf', 'pdf_not_supported', MediaTranslator::skipped()[0]['reason']);
MediaTranslator::end();

// 媒体文件被清理掉
MediaTranslator::begin($resolver, ['image' => true, 'pdf' => true]);
$ghost = ['type' => 'agent_media', 'media' => 'image', 'mime' => 'image/png',
          'name' => 'gone.png', 'ref' => 'media://ffffffffffffffffff'];
$out = MediaTranslator::translateContent([$ghost], MediaTranslator::FAMILY_OPENAI);
assert_eq('媒体丢失时降级成说明而不是崩溃', 'text', $out[0]['type']);
test('说明里点出文件已不在', strpos($out[0]['text'], '已不在存储中') !== false);
assert_eq('丢失被记录', 'media_missing', MediaTranslator::skipped()[0]['reason']);
MediaTranslator::end();

// 没装配 resolver（比如应用直接用 AI 层发了带媒体的消息）
MediaTranslator::begin(null, ['image' => true]);
$out = MediaTranslator::translateContent([$imgBlock], MediaTranslator::FAMILY_OPENAI);
assert_eq('没有 resolver 时降级', 'text', $out[0]['type']);
assert_eq('原因是 no_resolver', 'no_resolver', MediaTranslator::skipped()[0]['reason']);
MediaTranslator::end();

test('end() 之后不再 active', !MediaTranslator::active());

// ===================================================================
echo "\n=== 5. 无媒体的内容零改动 ===\n";
// ===================================================================

MediaTranslator::begin($resolver, ['image' => true]);
assert_eq('字符串 content 原样返回', '你好', MediaTranslator::translateContent('你好', 'openai'));
$plain = [['type' => 'text', 'text' => 'a'], ['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => 'ok']];
assert_eq('不含媒体的块数组原样返回', $plain, MediaTranslator::translateContent($plain, 'openai'));
MediaTranslator::end();

// ===================================================================
echo "\n=== 6. 接进 Tools::toOpenAiMessages ===\n";
// ===================================================================

MediaTranslator::begin($resolver, ['image' => true]);
$messages = [
    ['role' => 'user', 'content' => [['type' => 'text', 'text' => '看这张图'], $imgBlock]],
];
$converted = Tools::toOpenAiMessages($messages);
$imgOut = find_block($converted, 'image_url');
test('toOpenAiMessages 里媒体被翻译成 image_url', $imgOut !== null);
test('没有 agent_media 漏到平台侧', strpos((string) json_encode($converted), 'agent_media') === false);
MediaTranslator::end();

// tool_result 与媒体共存：tool 消息必须被拆出来，媒体留在 user 消息
MediaTranslator::begin($resolver, ['image' => true]);
$messages = [
    ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'read_file', 'input' => []]]],
    ['role' => 'user', 'content' => [
        ['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => '读取完成'],
        $imgBlock,
    ]],
];
$converted = Tools::toOpenAiMessages($messages);
$roles = [];
foreach ($converted as $m) { $roles[] = $m['role']; }
test('tool 消息被正确拆出', in_array('tool', $roles, true));
test('媒体仍被翻译', find_block($converted, 'image_url') !== null);
MediaTranslator::end();

// ===================================================================
echo "\n=== 7. 接进 Claude::convertMessages ===\n";
// ===================================================================

MediaTranslator::begin($resolver, ['image' => true, 'pdf' => true]);
$claude = new \Ai\Protocol\Claude();
$req = $claude->buildRequest([
    'model'    => 'claude-3-opus',
    'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => '看图'], $imgBlock]]],
]);
$json = (string) json_encode($req);
test('Claude 请求里出现 image 块', strpos($json, '"type":"image"') !== false);
test('Claude 请求里有 base64 source', strpos($json, '"media_type":"image\/png"') !== false
    || strpos($json, '"media_type":"image/png"') !== false);
test('没有 agent_media 漏出去', strpos($json, 'agent_media') === false);
MediaTranslator::end();

// ===================================================================
echo "\n=== 8. 坑③回归：附件不得覆盖数组 content ===\n";
// ===================================================================

$png = $tmp . '/t.png';
file_put_contents($png, $PNG);
$file = \Ai\Helpers\AIFile::fromPath($png);

// 最后一条是 tool_result（Agent 循环第 2 轮及以后的形状）
$payloadTemplate = [
    'messages' => [
        ['role' => 'user', 'content' => '看图'],
        ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'read_file', 'input' => []]]],
        ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => '读完了']]],
    ],
];

$models = [
    'BaseModel(GPT4o)'  => new \Ai\Models\OpenAI\GPT4o(),
    'Claude3Opus'       => new \Ai\Models\Claude\Claude3Opus(),
    'Gemini25Pro'       => new \Ai\Models\Gemini\Gemini25Pro(),
    'CustomModel'       => new \Ai\Models\CustomModel(['name' => 'x', 'protocol' => 'openai']),
    'DeepSeekChat'      => new \Ai\Models\DeepSeek\DeepSeekChat(),
    'DeepSeekReasoner'  => new \Ai\Models\DeepSeek\DeepSeekReasoner(),
    'DeepSeekAnthropic' => new \Ai\Models\DeepSeek\DeepSeekAnthropic(),
    'DeepSeekV4Pro'     => new \Ai\Models\DeepSeek\DeepSeekV4Pro(),
    'DeepSeekV4Flash'   => new \Ai\Models\DeepSeek\DeepSeekV4Flash(),
];

foreach ($models as $label => $model) {
    $out = $model->processAttachments($payloadTemplate, [$file]);
    $last = $out['messages'][2]['content'];

    // 核心断言：tool_result 必须还在。丢了就会让下一次请求 400
    $hasToolResult = false;
    if (is_array($last)) {
        foreach ($last as $b) {
            if (is_array($b) && isset($b['type']) && $b['type'] === 'tool_result') {
                $hasToolResult = true;
            }
        }
    }
    test("[{$label}] 附件不覆盖 tool_result", $hasToolResult);
    test("[{$label}] content 仍是数组", is_array($last));
}

// 最后一条是普通 user 字符串消息时，行为保持不变（向后兼容）
$plainPayload = ['messages' => [['role' => 'user', 'content' => '这张图里有什么']]];
$out = (new \Ai\Models\OpenAI\GPT4o())->processAttachments($plainPayload, [$file]);
$last = $out['messages'][0]['content'];
test('字符串 content 仍被转成块数组', is_array($last) && count($last) === 2);
assert_eq('第一块是原文本', 'text', $last[0]['type']);
assert_eq('原文本内容没丢', '这张图里有什么', $last[0]['text']);
assert_eq('第二块是附件', 'image_url', $last[1]['type']);

echo "\n=== 清理 ===\n";
rrmdir($tmp);
test('临时目录已清理', !is_dir($tmp));

echo "\n========================================\n";
echo "通过: {$passed}  失败: {$failed}\n";
echo "========================================\n";
exit($failed > 0 ? 1 : 0);
