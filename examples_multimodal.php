<?php
/**
 * Agent 多模态与附件对话 —— 完整可运行示例
 *
 * 直接跑：
 *     php examples_multimodal.php
 *
 * 全程不联网、不需要 API Key：模型调用由内置的假实现顶替，
 * 但**协议层是真的跑的**——你能看到最终发给平台的请求体长什么样。
 *
 * 演示四件事：
 *   1. 附件进对话历史（多轮之后、会话恢复之后都还在）
 *   2. 历史里存的是引用不是 base64
 *   3. 模型看不了图时明确告知，不伪装
 *   4. 主模型看不了图时自动路由到视觉模型
 */

require __DIR__ . '/autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\Capability\ModalityRouter;
use Ai\Agent\Context\MessagePart;
use Ai\Agent\Media\Attachment;
use Ai\Agent\Media\FileMediaStore;
use Ai\Agent\Media\MediaReference;

$tmp = sys_get_temp_dir() . '/php-ai-multimodal-demo_' . getmypid();
@mkdir($tmp . '/uploads', 0700, true);

function demo_title($n, $t)
{
    echo "\n" . str_repeat('=', 68) . "\n" . $n . '. ' . $t . "\n" . str_repeat('=', 68) . "\n";
}

// 造一张真图（1×1 PNG 就够演示，实际用法里换成用户上传的文件）
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents($tmp . '/uploads/screenshot.png', $png);

/** 假模型：不联网，但真的走一遍协议构建，让你看到最终请求体 */
class DemoAI extends AI
{
    /** @var array<int, array<string, mixed>> */
    public $requests = [];
    /** @var string */
    public $reply = '好的。';
    /** @var string */
    public $family = 'openai';

    public function chat($payload = ''): \Ai\Contracts\AIResponseInterface
    {
        $p = is_array($payload) ? $payload : ['messages' => []];
        $p['model'] = $this->family === 'anthropic' ? 'claude-3-opus' : 'gpt-4o';
        $protocol = $this->family === 'anthropic'
            ? new \Ai\Protocol\Claude()
            : new \Ai\Protocol\OpenAI();
        $this->requests[] = $protocol->buildRequest($p);
        return new \Ai\Response\AIResponse(['content' => $this->reply, 'usage' => []]);
    }
}

// =====================================================================
demo_title(1, '给对话带上附件');
// =====================================================================

$ai = new DemoAI(['api_key' => 'demo', 'platform' => 'openai', 'model' => 'gpt-4o']);
$agent = (new Agent($ai))->setWorkdir($tmp);
$agent->media(['store' => new FileMediaStore($tmp . '/media')]);

$agent->chat('这张截图里报的是什么错？', [
    Attachment::fromPath($tmp . '/uploads/screenshot.png'),
    // 也可以直接给路径字符串，或 ['url' => 'https://…']（URL 会走 SSRF 防护）
]);

echo "接口就这一行：\n";
echo "    \$agent->chat('这张截图里报的是什么错？', [Attachment::fromPath(\$path)]);\n\n";

$conv = $agent->getConversation();
echo "对话历史里的这条消息：\n";
echo json_encode($conv[0], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

// =====================================================================
demo_title(2, '历史存引用，不存 base64');
// =====================================================================

$historyJson = (string) json_encode($conv, JSON_UNESCAPED_UNICODE);
$sentJson    = (string) json_encode($ai->requests[0], JSON_UNESCAPED_UNICODE);

echo "对话历史大小:   " . strlen($historyJson) . " 字节\n";
echo "实际请求体大小: " . strlen($sentJson) . " 字节\n";
echo "历史里有 base64 吗: " . (strpos($historyJson, base64_encode($png)) !== false ? '有' : '没有') . "\n";
echo "请求里有 base64 吗: " . (strpos($sentJson, base64_encode($png)) !== false ? '有' : '没有') . "\n\n";

echo "一张 5 MB 的图 base64 后约 6.67 MB。如果把它写进会话 JSONL，\n";
echo "之后每一次 load/save 都要搬运这几 MB——多图长会话很快就不可用了。\n";
echo "所以历史里只留 media://<id>，真实字节交给 MediaStore，发请求那一刻才解析。\n";

// =====================================================================
demo_title(3, '多轮之后图片还在');
// =====================================================================

$agent->chat('先聊点别的。');
$agent->chat('回到刚才那张图。');

echo "三轮之后，历史里的媒体块数量: " . count(MessagePart::mediaBlocksIn($agent->getConversation())) . "\n";
$thirdRequest = (string) json_encode($ai->requests[2], JSON_UNESCAPED_UNICODE);
echo "第三轮的请求里还有图片吗: " . (strpos($thirdRequest, base64_encode($png)) !== false ? '有' : '没有') . "\n\n";
echo "这是 AI::setAttachments() 做不到的：它在每次 chat() 之后无条件清空，\n";
echo "图片只到得了 Agent 循环的第 1 轮，而看图任务几乎必然伴随工具调用。\n";

// =====================================================================
demo_title(4, '两个协议家族的请求形状');
// =====================================================================

$claudeAi = new DemoAI(['api_key' => 'demo', 'platform' => 'openai', 'model' => 'gpt-4o']);
$claudeAi->family = 'anthropic';
$claudeAgent = (new Agent($claudeAi))->setWorkdir($tmp);
$claudeAgent->media(['store' => new FileMediaStore($tmp . '/media2')]);
$claudeAgent->chat('看图', [$tmp . '/uploads/screenshot.png']);

function first_media_block(array $request)
{
    foreach ($request['messages'] as $m) {
        if (!isset($m['content']) || !is_array($m['content'])) {
            continue;
        }
        foreach ($m['content'] as $b) {
            if (is_array($b) && isset($b['type']) && $b['type'] !== 'text') {
                return $b;
            }
        }
    }
    return null;
}

echo "OpenAI 家族:\n";
echo json_encode(first_media_block($ai->requests[0]), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
echo "Anthropic 家族:\n";
$anthropicBlock = first_media_block($claudeAi->requests[0]);
if (is_array($anthropicBlock) && isset($anthropicBlock['source']['data'])) {
    $anthropicBlock['source']['data'] = substr((string) $anthropicBlock['source']['data'], 0, 24) . '…（已截断）';
}
echo json_encode($anthropicBlock, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
echo "对话历史里存的一直是协议无关的 agent_media —— 同一份会话先用 Claude 跑、\n";
echo "后换 OpenAI 跑都不会失效（降级模型、模态路由都会导致换协议）。\n";

// =====================================================================
demo_title(5, '模型看不了图时：明确告知，不伪装');
// =====================================================================

$textAi = new DemoAI(['api_key' => 'demo', 'platform' => 'openai', 'model' => 'gpt-4o']);
$textAgent = (new Agent($textAi))->setWorkdir($tmp);
$textAgent->media(['store' => new FileMediaStore($tmp . '/media3')]);
// 显式声明这个模型看不了图（真实场景里由内置能力表自动判断）
$textAgent->multimodal(['capabilities' => ['gpt-4o' => ['input' => ['image' => false, 'pdf' => false]]]]);
$textAgent->chat('这张图里有什么？', [$tmp . '/uploads/screenshot.png']);

$notice = '';
foreach ($textAi->requests[0]['messages'] as $m) {
    $c = is_array($m['content']) ? json_encode($m['content'], JSON_UNESCAPED_UNICODE) : (string) $m['content'];
    if (strpos((string) $c, '无法查看') !== false) {
        $notice = (string) $c;
    }
}
echo "模型实际收到的内容：\n  " . $notice . "\n\n";
echo "刻意**不**用 `[图片: x.png]` 这种占位符——那看起来像「图已附上」，\n";
echo "会诱导模型写出「从图片可以看出……」，而它根本没看到。\n";

// =====================================================================
demo_title(6, '主模型看不了图时自动路由到视觉模型');
// =====================================================================

$mainAi = new DemoAI(['api_key' => 'demo', 'platform' => 'openai', 'model' => 'gpt-4o']);
$mainAi->reply = '根据描述，这是一个提交按钮。';
$routed = (new Agent($mainAi))->setWorkdir($tmp);
$routed->media(['store' => new FileMediaStore($tmp . '/media4')]);
$routed->multimodal([
    'mode'         => 'auto',
    'capabilities' => ['gpt-4o' => ['input' => ['image' => false]]],   // 假装主模型不支持
]);
$routed->platforms(['vision' => ['api_key' => 'demo', 'model' => 'gemini-2.5-flash']]);

// 视觉模型也用假实现（真实用法里删掉这一段，库会按 platforms() 自己构造）
$routed->modalityRouter()->setAiFactory(function ($model, $config) {
    $vision = new DemoAI(['api_key' => 'demo', 'platform' => 'openai', 'model' => 'gpt-4o']);
    $vision->reply = '图中是一个红色的矩形按钮，上面用白色大写字母写着 SUBMIT，下方有黑色数字 7431。';
    return $vision;
});

$events = [];
$routed->onEvent(function ($e) use (&$events) {
    if (isset($e['type']) && $e['type'] === 'modality_route') {
        $events[] = $e;
    }
});

$routed->chat('这张图里写的什么？', [$tmp . '/uploads/screenshot.png']);

foreach ($events as $e) {
    echo "路由决策: " . $e['decision'] . "   服务方: " . $e['provider'] . "\n";
}

$mainRequest = (string) json_encode($mainAi->requests[0], JSON_UNESCAPED_UNICODE);
echo "主模型收到图片二进制了吗: " . (strpos($mainRequest, base64_encode($png)) !== false ? '收到了' : '没有') . "\n\n";

$block = MessagePart::mediaBlocksIn($routed->getConversation())[0];
echo "写回历史的描述:\n  " . (isset($block['description']) ? $block['description'] : '(无)') . "\n";
echo "来源模型: " . (isset($block['described_by']) ? $block['described_by'] : '(无)') . "\n";
echo "原始引用还在吗: " . (isset($block['ref']) ? $block['ref'] : '(没了)') . "\n\n";

echo "两个要点：\n";
echo "  · 描述回填时**注明来源**，主模型知道这是二手信息，不会当成自己看到的\n";
echo "  · 原 ref 保留 —— 以后换成视觉模型还能重新看原图，同一张图也不会被反复描述\n";

// =====================================================================
demo_title(7, '真实接入长什么样');
// =====================================================================

echo <<<'TXT'
上面为了不联网用了假模型。真实代码是这样：

    use Ai\AI;
    use Ai\Agent\Agent;
    use Ai\Agent\Media\Attachment;

    // 主模型：纯文本也没关系
    $ai = new AI([
        'api_key'  => $scnetKey,
        'protocol' => 'openai',
        'base_url' => 'https://api.scnet.cn/api/llm/v1',
        'model'    => 'GLM-5-Base',
    ]);

    $agent = (new Agent($ai))->setWorkdir(APP_PATH);

    // 配一个能看图的平台，剩下的库自己处理
    $agent->platforms([
        'gemini' => [
            'api_key'    => $geminiKey,
            'platform'   => 'gemini',
            'model'      => 'gemini-2.5-flash',
            'proxy'      => 'http://127.0.0.1:8993',   // 需要代理时
            'modalities' => ['vision'],
        ],
    ]);

    $result = $agent->chat('这张截图里报的什么错？', [
        Attachment::fromPath($_FILES['shot']['tmp_name']),
    ]);

    echo $result->getText();

限额与安全：

    $agent->media([
        'max_attachment_bytes'        => 5 * 1024 * 1024,   // 单文件
        'max_message_bytes'           => 10 * 1024 * 1024,  // 单条消息全部附件
        'max_attachments_per_message' => 8,
        'max_request_bytes'           => 20 * 1024 * 1024,  // 单次模型请求
        'allowed_paths'               => [APP_PATH . '/uploads'],
    ]);

URL 附件强制走 HttpFetch 的 SSRF 防护；本地路径走 realpath 挡 symlink 逃逸，
并做 MIME 内容嗅探 + 扩展名交叉校验（把 .php 改成 .png 传上来过不了）。

TXT;

// =====================================================================
// 清理
// =====================================================================

function demo_rrmdir($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') {
            continue;
        }
        $p = $dir . '/' . $it;
        if (is_dir($p) && !is_link($p)) {
            demo_rrmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}
demo_rrmdir($tmp);

echo "\n临时目录已清理: {$tmp}\n";
echo "完整说明见 README.md 的「Agent 多模态与附件对话」一节。\n";
