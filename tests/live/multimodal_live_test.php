<?php
/**
 * 多模态实网络测试（默认跳过）
 *
 * 覆盖 dev.md 进度表 P7 的 5 个场景：
 *   1. R1  直接把图发给多模态模型
 *   2. R3  纯文本主模型 + 自动路由到视觉模型
 *   3. R2  工作区图片读取（read_file → 媒体注入）
 *   4. 多轮保持：第 1 轮发图，第 3 轮再问图里的内容
 *   5. 降级：没有可用视觉模型时明确告知，不静默忽略、不假装看到
 *
 * **不进 composer test**——它会真的发请求、花钱、依赖网络与代理。
 * 没有密钥文件时整体跳过且退出码为 0。
 *
 * 运行：php tests/live/multimodal_live_test.php
 * 详见同目录 README.md。
 */

require __DIR__ . '/../../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\Capability\ModalityRouter;
use Ai\Agent\Context\MessagePart;
use Ai\Agent\Media\FileMediaStore;
use Ai\Agent\Tools\PathSafety;
use Ai\Agent\Tools\ReadFileTool;

$passed = 0;
$failed = 0;
$skipped = 0;

function test($name, $ok)
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "  ✓ {$name}\n"; }
    else { $failed++; echo "  ✗ {$name}\n"; }
}
function section($title)
{
    echo "\n" . str_repeat('=', 62) . "\n" . $title . "\n" . str_repeat('=', 62) . "\n";
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

// ===== 密钥：没有就整体跳过 =====
$secretsDir = __DIR__ . '/../../.claude/secrets';
$scnetKey  = is_file($secretsDir . '/scnet.key')  ? trim((string) file_get_contents($secretsDir . '/scnet.key'))  : '';
$geminiKey = is_file($secretsDir . '/gemini.key') ? trim((string) file_get_contents($secretsDir . '/gemini.key')) : '';

if ($scnetKey === '' || $geminiKey === '') {
    echo "跳过：缺少密钥文件（.claude/secrets/scnet.key、gemini.key）\n";
    echo "这是实网络测试，不进 composer test。详见 tests/live/README.md\n";
    exit(0);
}

$proxy = getenv('GEMINI_PROXY');
if ($proxy === false || $proxy === '') {
    $proxy = 'http://127.0.0.1:8993';
}

$tmp = sys_get_temp_dir() . '/php-ai-live_' . getmypid();
rrmdir($tmp);
@mkdir($tmp . '/work', 0700, true);

// ===== 造一张内容可验证的图 =====
$imagePath = $tmp . '/work/probe.png';
if (!function_exists('imagettftext')) {
    echo "跳过：需要 GD + FreeType 才能生成可验证的测试图\n";
    exit(0);
}
$fonts = glob('/usr/share/fonts/truetype/*/*.ttf');
if (!$fonts) {
    echo "跳过：找不到可用的 TrueType 字体\n";
    exit(0);
}
$font = $fonts[0];
$im = imagecreatetruecolor(420, 220);
imagefilledrectangle($im, 0, 0, 420, 220, imagecolorallocate($im, 255, 255, 255));
imagefilledrectangle($im, 40, 40, 380, 120, imagecolorallocate($im, 200, 30, 40));
imagettftext($im, 34, 0, 90, 95, imagecolorallocate($im, 255, 255, 255), $font, 'SUBMIT');
imagettftext($im, 40, 0, 150, 195, imagecolorallocate($im, 20, 20, 20), $font, '7431');
imagepng($im, $imagePath);
imagedestroy($im);

/** @return AI SCNet 的 GLM-5-Base：推理模型，max_tokens 要给够，否则只拿到空串 */
function scnet_ai($key)
{
    return new AI([
        'api_key'    => $key,
        'protocol'   => 'openai',
        'base_url'   => 'https://api.scnet.cn/api/llm/v1',
        'model'      => 'GLM-5-Base',
        'timeout'    => 180,
        'max_tokens' => 4000,
    ]);
}

/** @return array<string, mixed> Gemini 平台配置（本机需走代理） */
function gemini_platform($key, $proxy)
{
    return [
        'api_key'    => $key,
        'platform'   => 'gemini',
        'model'      => 'gemini-2.5-flash',
        'proxy'      => $proxy,
        'timeout'    => 120,
        'max_tokens' => 2000,
        'modalities' => ['vision'],
    ];
}

function make_agent($ai, $tmp, $suffix)
{
    $agent = (new Agent($ai))->setWorkdir($tmp . '/work');
    $agent->media(['store' => new FileMediaStore($tmp . '/store_' . $suffix)]);
    return $agent;
}

// ===================================================================
section('1. R1 —— 直接把图发给多模态模型（Gemini）');
// ===================================================================

$gAi = new AI(gemini_platform($geminiKey, $proxy));
$agent1 = make_agent($gAi, $tmp, 'r1');
$t = microtime(true);
$res1 = $agent1->chat('这张图里红色按钮上的英文单词是什么？下面的四位数字是多少？只回答这两项。', [$imagePath]);
$text1 = trim($res1->getText());
echo "  回答: {$text1}\n  耗时: " . round(microtime(true) - $t, 1) . "s\n";

test('模型认出了按钮上的 SUBMIT', stripos($text1, 'SUBMIT') !== false);
test('模型认出了数字 7431', strpos($text1, '7431') !== false);
test('对话历史里留下了媒体引用', count(MessagePart::mediaBlocksIn($agent1->getConversation())) === 1);

// ===================================================================
section('2. R3 —— 纯文本主模型（SCNet GLM-5-Base）+ 自动路由');
// ===================================================================

$agent2 = make_agent(scnet_ai($scnetKey), $tmp, 'r3');
$agent2->platforms(['gemini' => gemini_platform($geminiKey, $proxy)]);
$agent2->multimodal(['mode' => 'auto']);

$routeEvents = [];
$agent2->onEvent(function ($e) use (&$routeEvents) {
    if (isset($e['type']) && $e['type'] === 'modality_route') { $routeEvents[] = $e; }
});

$t = microtime(true);
$res2 = $agent2->chat('图里红色按钮上的英文单词是什么？下面那个四位数字是多少？', [$imagePath]);
$text2 = trim($res2->getText());
echo "  回答: {$text2}\n  耗时: " . round(microtime(true) - $t, 1) . "s\n";

$decision = '';
$provider = '';
foreach ($routeEvents as $e) {
    $decision = isset($e['decision']) ? (string) $e['decision'] : '';
    $provider = isset($e['provider']) ? (string) $e['provider'] : '';
}
echo "  决策: {$decision}  服务方: {$provider}\n";

test('决策是 describe（主模型看不了图，路由出去）', $decision === ModalityRouter::DECISION_DESCRIBE);
test('服务方是 Gemini', $provider === 'gemini-2.5-flash');
test('纯文本主模型也答出了 SUBMIT', stripos($text2, 'SUBMIT') !== false);
test('纯文本主模型也答出了 7431', strpos($text2, '7431') !== false);

$blocks2 = MessagePart::mediaBlocksIn($agent2->getConversation());
test('描述被写进了媒体块', isset($blocks2[0]['description']) && $blocks2[0]['description'] !== '');
test('描述记了来源模型', isset($blocks2[0]['described_by']) && $blocks2[0]['described_by'] === 'gemini-2.5-flash');
test('原始媒体引用仍在（派生上下文不替代原图）', strpos((string) $blocks2[0]['ref'], 'media://') === 0);

// ===================================================================
section('3. R2 —— 工作区图片读取');
// ===================================================================

$gAi3 = new AI(gemini_platform($geminiKey, $proxy));
$agent3 = make_agent($gAi3, $tmp, 'r2');
$agent3->setTools(['read_file' => new ReadFileTool(new PathSafety($tmp . '/work'))]);

$t = microtime(true);
$res3 = $agent3->chat('用 read_file 读取 probe.png，然后告诉我图里那个四位数字是多少。');
$text3 = trim($res3->getText());
echo "  回答: {$text3}\n  耗时: " . round(microtime(true) - $t, 1) . "s\n";

test('通过 read_file 看到了图里的数字', strpos($text3, '7431') !== false);

// ===================================================================
section('4. 多轮保持 —— 第 1 轮发图，第 3 轮再问');
// ===================================================================

$gAi4 = new AI(gemini_platform($geminiKey, $proxy));
$agent4 = make_agent($gAi4, $tmp, 'multi');
$agent4->chat('看看这张图，先不用回答内容。', [$imagePath]);
$agent4->chat('先聊点别的：PHP 的数组是有序的吗？一句话。');
$res4 = $agent4->chat('回到最开始那张图 —— 上面那个四位数字是多少？');
$text4 = trim($res4->getText());
echo "  第三轮回答: {$text4}\n";

test('三轮之后图片仍然可用', strpos($text4, '7431') !== false);
test('历史里仍只有一个媒体块（没重复入库）',
    count(MessagePart::mediaBlocksIn($agent4->getConversation())) === 1);

// ===================================================================
section('5. 降级 —— 没有可用视觉模型时明确告知');
// ===================================================================

$agent5 = make_agent(scnet_ai($scnetKey), $tmp, 'degrade');
// 只配主模型自己，没有任何视觉平台
$agent5->multimodal(['mode' => 'auto']);

$events5 = [];
$agent5->onEvent(function ($e) use (&$events5) {
    if (isset($e['type']) && $e['type'] === 'modality_route') { $events5[] = $e; }
});

$t = microtime(true);
$res5 = $agent5->chat('这张图里的四位数字是多少？如果你看不到图片，就直接说你看不到。', [$imagePath]);
$text5 = trim($res5->getText());
echo "  回答: {$text5}\n  耗时: " . round(microtime(true) - $t, 1) . "s\n";

$decision5 = '';
foreach ($events5 as $e) {
    $decision5 = isset($e['decision']) ? (string) $e['decision'] : '';
}
echo "  决策: {$decision5}\n";

test('决策是 unavailable', $decision5 === ModalityRouter::DECISION_UNAVAILABLE);
test('请求没有因为发了图片而失败', $text5 !== '');
test('模型没有编造数字（没说出 7431）', strpos($text5, '7431') === false);

echo "\n";
rrmdir($tmp);

echo str_repeat('=', 62) . "\n";
echo "通过: {$passed}  失败: {$failed}\n";
echo str_repeat('=', 62) . "\n";
exit($failed > 0 ? 1 : 0);
