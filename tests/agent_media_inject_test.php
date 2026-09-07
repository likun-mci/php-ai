<?php
/**
 * P4 R2 工作区图片读取 + Runtime Media Injection 测试
 *
 * 覆盖 dev.md 进度表 P4：ToolResult 携带媒体、ReadFileTool 落库并交出引用
 * （不碰协议格式）、Runtime 把媒体拼进同一条 user 消息且排在 tool_result 之后、
 * 两个协议家族下最终请求的形状、并行工具路径不丢媒体。
 *
 * 不发网络请求；全程临时目录，结束递归清理。
 *
 * 运行：php tests/agent_media_inject_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Agent;
use Ai\Agent\Context\MessagePart;
use Ai\Agent\Media\FileMediaStore;
use Ai\Agent\Media\MediaManager;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;
use Ai\Agent\Tools\PathSafety;
use Ai\Agent\Tools\ReadFileTool;

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

/** 先让模型调一次 read_file，第二轮再给出结论 */
class ToolThenAnswerAI extends \Ai\AI
{
    /** @var int */
    public $calls = 0;
    /** @var array<int, array<string, mixed>> */
    public $seen = [];
    /** @var array<int, array<string, mixed>> */
    public $requests = [];
    /** @var string */
    public $family = 'openai';
    /** @var string */
    public $file = 'shot.png';

    public function chat($payload = ''): \Ai\Contracts\AIResponseInterface
    {
        $payload = is_array($payload) ? $payload : ['messages' => []];
        $this->seen[] = $payload;

        $protocol = $this->family === 'anthropic'
            ? new \Ai\Protocol\Claude()
            : new \Ai\Protocol\OpenAI();
        $payload['model'] = $this->family === 'anthropic' ? 'claude-3-opus' : 'gpt-4o';
        $this->requests[] = $protocol->buildRequest($payload);

        $this->calls++;
        if ($this->calls === 1) {
            return new \Ai\Response\AIResponse([
                'content'    => '',
                'tool_calls' => [[
                    'id'    => 'call_1',
                    'name'  => 'read_file',
                    'input' => ['path' => $this->file],
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]);
        }
        return new \Ai\Response\AIResponse([
            'content' => '我看到了这张图。',
            'usage'   => ['prompt_tokens' => 20, 'completion_tokens' => 8],
        ]);
    }
}

$origHome = getenv('HOME');
$tmp = sys_get_temp_dir() . '/php-ai-inject-test_' . getmypid();
rrmdir($tmp);
@mkdir($tmp . '/home', 0700, true);
@mkdir($tmp . '/work', 0700, true);
putenv('HOME=' . $tmp . '/home');

$PNG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
// 真实 PDF 含二进制流；纯 ASCII 的「PDF」会被当文本读（那是正确行为），测不到媒体分支
$PDF = "%PDF-1.4\n1 0 obj\n<</Length 5>>\nstream\n\x00\x01\x02\xFF\xFE\nendstream\nendobj\n%%EOF\n";
file_put_contents($tmp . '/work/shot.png', $PNG);
file_put_contents($tmp . '/work/doc.pdf', $PDF);
file_put_contents($tmp . '/work/notes.txt', "第一行\n第二行\n");

// ===================================================================
echo "\n=== 1. ToolResult 携带媒体 ===\n";
// ===================================================================

$plain = ToolResult::success('ok');
test('默认没有媒体', !$plain->hasMedia());
assert_eq('getMedia 返回空数组', [], $plain->getMedia());

$block = ['type' => 'agent_media', 'media' => 'image', 'mime' => 'image/png',
          'name' => 'a.png', 'ref' => 'media://abc123'];
$withMedia = new ToolResult(['success' => true, 'content' => 'x', 'media' => [$block]]);
test('构造时可带媒体', $withMedia->hasMedia());
assert_eq('媒体块能取回', $block, $withMedia->getMedia()[0]);
test('toArray 里有 media', isset($withMedia->toArray()['media']));

$viaSetter = ToolResult::success('y')->withMedia([$block]);
test('withMedia 也能挂上', $viaSetter->hasMedia());

// ===================================================================
echo "\n=== 2. ReadFileTool 落库并交出引用 ===\n";
// ===================================================================

$store   = new FileMediaStore($tmp . '/store');
$manager = new MediaManager($store);
$tool    = new ReadFileTool(new PathSafety($tmp . '/work'));

$ctxWith = new ToolContext(['workdir' => $tmp . '/work']);
$ctxWith->setMediaManager($manager);

$res = $tool->execute(['path' => 'shot.png'], $ctxWith);
test('读图成功', $res->isSuccess());
test('结果带媒体引用', $res->hasMedia());
assert_eq('一个媒体块', 1, count($res->getMedia()));
assert_eq('块类型是 agent_media', 'agent_media', $res->getMedia()[0]['type']);
assert_eq('记了原文件名', 'shot.png', $res->getMedia()[0]['name']);
test('内容里说明已附加', strpos((string) $res->getContent(), '已附加') !== false);

// 关键：工具不碰协议格式
$resJson = (string) json_encode($res->toArray());
test('结果里没有 image_url（不关心 OpenAI 格式）', strpos($resJson, 'image_url') === false);
test('结果里没有 source/base64 块（不关心 Anthropic 格式）', strpos($resJson, '"source"') === false);
test('结果里没有 base64 原始数据', strpos($resJson, base64_encode($PNG)) === false);

// 真的落库了
$id = \Ai\Agent\Media\MediaReference::idOfUri($res->getMedia()[0]['ref']);
assert_eq('落库内容与原文件一致', $PNG, $store->get($id));

// PDF 同样处理
$resPdf = $tool->execute(['path' => 'doc.pdf'], $ctxWith);
test('PDF 也被附加', $resPdf->hasMedia());
assert_eq('PDF 的媒体大类', 'pdf', $resPdf->getMedia()[0]['media']);

// 没有媒体门面时降级成纯元数据（向后兼容）
$ctxWithout = new ToolContext(['workdir' => $tmp . '/work']);
$resNoMm = $tool->execute(['path' => 'shot.png'], $ctxWithout);
test('没有门面时仍然成功', $resNoMm->isSuccess());
test('没有门面时不带媒体', !$resNoMm->hasMedia());
test('没有门面时给出原有提示', strpos((string) $resNoMm->getContent(), '附件传给') !== false);

// 超限时如实说明，不静默吞掉
$tinyManager = new MediaManager(new FileMediaStore($tmp . '/store2'), ['max_attachment_bytes' => 10]);
$ctxTiny = new ToolContext(['workdir' => $tmp . '/work']);
$ctxTiny->setMediaManager($tinyManager);
$resTiny = $tool->execute(['path' => 'shot.png'], $ctxTiny);
test('超限时工具仍然成功返回', $resTiny->isSuccess());
test('超限时不带媒体', !$resTiny->hasMedia());
test('超限时说明了原因', strpos((string) $resTiny->getContent(), '未能附加') !== false);

// 文本文件不受影响
$resTxt = $tool->execute(['path' => 'notes.txt'], $ctxWith);
test('文本文件正常读取', $resTxt->isSuccess() && strpos((string) $resTxt->getContent(), '第一行') !== false);
test('文本文件不带媒体', !$resTxt->hasMedia());

// ===================================================================
echo "\n=== 3. Runtime Media Injection：拼进同一条 user 消息 ===\n";
// ===================================================================

function make_agent($tmp, &$ai, $family = 'openai')
{
    $ai = new ToolThenAnswerAI(['api_key' => 'test', 'platform' => 'openai', 'model' => 'gpt-4o']);
    $ai->family = $family;
    $agent = (new Agent($ai))
        ->setWorkdir($tmp . '/work')
        ->setAgentHome($tmp . '/home/.agent');
    $agent->media(['store' => new FileMediaStore($tmp . '/astore')]);
    $agent->setTools(['read_file' => new ReadFileTool(new PathSafety($tmp . '/work'))]);
    return $agent;
}

$ai = null;
$agent = make_agent($tmp, $ai);
$agent->chat('看看 shot.png 里是什么');

assert_eq('模型被调用两次', 2, $ai->calls);

// 第二次请求的消息里应当有 tool_result + 媒体，且在同一条 user 消息
$secondMessages = $ai->seen[1]['messages'];
$toolResultMsg = null;
foreach ($secondMessages as $m) {
    if (isset($m['content']) && is_array($m['content'])) {
        foreach ($m['content'] as $b) {
            if (isset($b['type']) && $b['type'] === 'tool_result') { $toolResultMsg = $m; break 2; }
        }
    }
}
test('找到带 tool_result 的消息', $toolResultMsg !== null);

$types = [];
foreach ($toolResultMsg['content'] as $b) { $types[] = isset($b['type']) ? $b['type'] : '?'; }
test('同一条消息里既有 tool_result 也有 agent_media',
    in_array('tool_result', $types, true) && in_array('agent_media', $types, true));
assert_eq('tool_result 排在媒体前面',
    true, array_search('tool_result', $types, true) < array_search('agent_media', $types, true));

// 没有产生连续两条 user 消息（Anthropic 会拒）
$roles = [];
foreach ($secondMessages as $m) { $roles[] = isset($m['role']) ? $m['role'] : '?'; }
$consecutiveUser = false;
for ($i = 1; $i < count($roles); $i++) {
    if ($roles[$i] === 'user' && $roles[$i - 1] === 'user') { $consecutiveUser = true; }
}
test('统一格式里没有连续两条 user 消息', !$consecutiveUser);

// ===================================================================
echo "\n=== 4. 两个家族的最终请求形状 ===\n";
// ===================================================================

$reqOpenAI = $ai->requests[1];
$jsonOpenAI = (string) json_encode($reqOpenAI);
test('[OpenAI] 请求里有 image_url', strpos($jsonOpenAI, 'image_url') !== false);
test('[OpenAI] 请求里有图片 base64', strpos($jsonOpenAI, base64_encode($PNG)) !== false);
test('[OpenAI] agent_media 没漏出去', strpos($jsonOpenAI, 'agent_media') === false);

// OpenAI 家族：tool 消息不能带图片，图片必须落在随后的 user 消息里
$oaRoles = [];
foreach ($reqOpenAI['messages'] as $m) { $oaRoles[] = $m['role']; }
test('[OpenAI] 拆出了 role:tool 消息', in_array('tool', $oaRoles, true));
$toolMsgHasImage = false;
$userAfterToolHasImage = false;
foreach ($reqOpenAI['messages'] as $i => $m) {
    $c = (string) json_encode(isset($m['content']) ? $m['content'] : '');
    if ($m['role'] === 'tool' && strpos($c, 'image_url') !== false) { $toolMsgHasImage = true; }
    if ($m['role'] === 'user' && strpos($c, 'image_url') !== false) { $userAfterToolHasImage = true; }
}
test('[OpenAI] role:tool 消息里没有图片', !$toolMsgHasImage);
test('[OpenAI] 图片落在 user 消息里', $userAfterToolHasImage);

$aiC = null;
$agentC = make_agent($tmp, $aiC, 'anthropic');
$agentC->chat('看看 shot.png 里是什么');
$jsonClaude = (string) json_encode($aiC->requests[1]);
test('[Anthropic] 请求里有 image 块', strpos($jsonClaude, '"type":"image"') !== false);
test('[Anthropic] 请求里有图片 base64', strpos($jsonClaude, base64_encode($PNG)) !== false);
test('[Anthropic] 没有 image_url', strpos($jsonClaude, 'image_url') === false);
test('[Anthropic] agent_media 没漏出去', strpos($jsonClaude, 'agent_media') === false);

// Anthropic：tool_result 必须排在该消息的最前
$claudeMsgs = $aiC->requests[1]['messages'];
foreach ($claudeMsgs as $m) {
    if (!is_array($m['content'])) { continue; }
    $firstType = isset($m['content'][0]['type']) ? $m['content'][0]['type'] : '';
    $hasTr = false;
    foreach ($m['content'] as $b) {
        if (isset($b['type']) && $b['type'] === 'tool_result') { $hasTr = true; }
    }
    if ($hasTr) {
        assert_eq('[Anthropic] tool_result 是该消息的首块', 'tool_result', $firstType);
    }
}

// ===================================================================
echo "\n=== 5. 模型不支持视觉时明确告知 ===\n";
// ===================================================================

$aiD = null;
$agentD = make_agent($tmp, $aiD);
// P5 起模态支持由能力系统计算（syncMediaSupport 是唯一权威），
// 所以这里用公开 API 声明「这个模型看不了图」，而不是直接改运行时标志
$agentD->multimodal(['capabilities' => ['gpt-4o' => ['input' => ['image' => false, 'pdf' => false]]]]);
$agentD->chat('看看 shot.png');

// 默认 json_encode 会把中文转义成 \uXXXX，断言中文时必须关掉
$jsonD = (string) json_encode($aiD->requests[1], JSON_UNESCAPED_UNICODE);
test('不支持时请求里没有图片数据', strpos($jsonD, base64_encode($PNG)) === false);
test('不支持时给出明确说明', strpos($jsonD, '无法查看') !== false);
test('不支持时要求模型不要臆测', strpos($jsonD, '不要臆测') !== false);

// ===================================================================
echo "\n=== 6. 并行工具路径不丢媒体 ===\n";
// ===================================================================

$parallelExec = new \Ai\Agent\Tool\ParallelToolExecutor(
    (new \Ai\Agent\Tool\ToolRegistry())->register(new ReadFileTool(new PathSafety($tmp . '/work')))
);
$results = $parallelExec->executeAll(
    [['id' => 'c1', 'name' => 'read_file', 'input' => ['path' => 'shot.png']]],
    $ctxWith
);
test('并行结果里保留了 media 键', isset($results[0]['media']) && count($results[0]['media']) === 1);
assert_eq('并行结果的媒体块类型', 'agent_media', $results[0]['media'][0]['type']);

echo "\n=== 清理 ===\n";
if ($origHome !== false) { putenv('HOME=' . $origHome); } else { putenv('HOME'); }
rrmdir($tmp);
test('临时目录已清理', !is_dir($tmp));

echo "\n========================================\n";
echo "通过: {$passed}  失败: {$failed}\n";
echo "========================================\n";
exit($failed > 0 ? 1 : 0);
