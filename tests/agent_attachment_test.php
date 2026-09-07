<?php
/**
 * P3 R1 对话附件测试
 *
 * 覆盖 dev.md 进度表 P3：Agent::chat($input, $attachments)、附件进对话历史、
 * 多轮保持、会话持久化与恢复、悬空 tool_use 场景下带附件仍能合法拼接、
 * 以及「历史里存引用不存 base64」这条核心约定。
 *
 * 用 StubAI 拦住模型调用，不发任何网络请求；全程临时 HOME，结束递归清理。
 *
 * 运行：php tests/agent_attachment_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Agent;
use Ai\Agent\Context\Conversation;
use Ai\Agent\Context\MessagePart;
use Ai\Agent\Media\Attachment;
use Ai\Agent\Media\FileMediaStore;
use Ai\Agent\Media\MediaReference;

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

/**
 * 拦住网络，但**真的跑一遍协议层**
 *
 * 媒体翻译发生在 `Tools::toOpenAiMessages()` / `Claude::convertMessages()` 里，
 * 也就是协议的 buildRequest 阶段。如果 stub 直接把 payload 记下来就返回，
 * 测到的只是「Runtime 递给 AI 的东西」，翻译那一步压根没执行——
 * 那样即使翻译完全坏掉测试也会绿。
 *
 * 所以这里照着真实路径调一次 buildRequest：`$seen` 是 Runtime 递进来的原始
 * payload，`$requests` 是最终会发到平台的请求体。断言图片一律看后者。
 */
class CapturingAI extends \Ai\AI
{
    /** @var array<int, array<string, mixed>> Runtime 递进来的 payload */
    public $seen = [];
    /** @var array<int, array<string, mixed>> 协议层构建后的请求体 */
    public $requests = [];
    /** @var string */
    public $canned = '好的';
    /** @var string openai | anthropic */
    public $family = 'openai';

    public function chat($payload = ''): \Ai\Contracts\AIResponseInterface
    {
        $payload = is_array($payload) ? $payload : ['messages' => [['role' => 'user', 'content' => $payload]]];
        $this->seen[] = $payload;

        $protocol = $this->family === 'anthropic'
            ? new \Ai\Protocol\Claude()
            : new \Ai\Protocol\OpenAI();
        $payload['model'] = $this->family === 'anthropic' ? 'claude-3-opus' : 'gpt-4o';
        $this->requests[] = $protocol->buildRequest($payload);

        return new \Ai\Response\AIResponse([
            'content' => $this->canned,
            'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]);
    }
}

$origHome = getenv('HOME');
$tmp = sys_get_temp_dir() . '/php-ai-attach-test_' . getmypid();
rrmdir($tmp);
@mkdir($tmp . '/home', 0700, true);
@mkdir($tmp . '/work', 0700, true);
@mkdir($tmp . '/uploads', 0700, true);
putenv('HOME=' . $tmp . '/home');

$PNG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$GIF = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
file_put_contents($tmp . '/uploads/a.png', $PNG);
file_put_contents($tmp . '/uploads/b.gif', $GIF);

/** 造一个挂了临时存储的 Agent */
function make_agent($tmp, &$ai)
{
    $ai = new CapturingAI(['api_key' => 'test', 'platform' => 'openai', 'model' => 'gpt-4o']);
    $agent = (new Agent($ai))
        ->setWorkdir($tmp . '/work')
        ->setAgentHome($tmp . '/home/.agent');
    $agent->media(['store' => new FileMediaStore($tmp . '/store')]);
    return $agent;
}

// ===================================================================
echo "\n=== 1. Conversation::appendUserParts ===\n";
// ===================================================================

$store = new FileMediaStore($tmp . '/cstore');
$ref   = $store->put($PNG, ['mime' => 'image/png', 'name' => 'a.png']);
$block = $ref->toBlock();

$msgs = Conversation::appendUserParts([], '看这张图', [$block]);
assert_eq('产生一条消息', 1, count($msgs));
assert_eq('角色是 user', 'user', $msgs[0]['role']);
test('content 是块数组', is_array($msgs[0]['content']));
assert_eq('两个块', 2, count($msgs[0]['content']));
assert_eq('先文本', 'text', $msgs[0]['content'][0]['type']);
assert_eq('后媒体', 'agent_media', $msgs[0]['content'][1]['type']);

$msgsNoText = Conversation::appendUserParts([], '', [$block]);
assert_eq('只有附件没有正文也能成立', 1, count($msgsNoText[0]['content']));

assert_eq('无正文无附件时不产生消息', 0, count(Conversation::appendUserParts([], '', [])));

// 悬空 tool_use：带附件时也要补 tool_result，否则下一次请求 400
$dangling = [
    ['role' => 'user', 'content' => '帮我删文件'],
    ['role' => 'assistant', 'content' => [
        ['type' => 'tool_use', 'id' => 't1', 'name' => 'bash', 'input' => ['command' => 'rm x']],
    ]],
];
$after = Conversation::appendUserParts($dangling, '别删了，看这张图', [$block]);
assert_eq('新增一条消息', 3, count($after));
$blocks = $after[2]['content'];
$types = [];
foreach ($blocks as $b) { $types[] = $b['type']; }
test('补上了 tool_result', in_array('tool_result', $types, true));
test('用户的新指示在里面', in_array('text', $types, true));
test('附件也在同一条消息里', in_array('agent_media', $types, true));
assert_eq('tool_result 排在最前', 'tool_result', $types[0]);

// appendUserText 仍是原行为（薄封装不改语义）
$plain = Conversation::appendUserText([], '你好');
assert_eq('无附件时 content 仍是字符串', '你好', $plain[0]['content']);

// normalize 剔除残缺媒体块
$dirty = Conversation::normalize([
    ['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => 'x'],
        ['type' => 'agent_media', 'media' => 'image'],          // 没有 ref
        $block,
    ]],
]);
assert_eq('残缺媒体块被剔除', 2, count($dirty[0]['content']));

// ===================================================================
echo "\n=== 2. Agent::chat 带附件 ===\n";
// ===================================================================

$agent = make_agent($tmp, $ai);
$result = $agent->chat('这张图里有什么', [Attachment::fromPath($tmp . '/uploads/a.png')]);
test('chat 返回结果', $result instanceof \Ai\Agent\AgentResult);

$conv = $agent->getConversation();
$userMsg = $conv[0];
test('对话历史第一条是 user', $userMsg['role'] === 'user');
test('content 是块数组', is_array($userMsg['content']));

$mediaBlocks = MessagePart::mediaBlocksIn($conv);
assert_eq('历史里有一个媒体块', 1, count($mediaBlocks));
assert_eq('媒体块带 ref', 0, strpos($mediaBlocks[0]['ref'], 'media://'));
assert_eq('记了文件名', 'a.png', $mediaBlocks[0]['name']);

// 核心约定：历史里不含 base64
$convJson = (string) json_encode($conv);
test('历史里没有 base64 数据', strpos($convJson, base64_encode($PNG)) === false);
test('历史体积很小', strlen($convJson) < 600);

// 发出去的 payload 里必须是真图片
assert_eq('模型被调用了一次', 1, count($ai->seen));
test('Runtime 递给 AI 的仍是 agent_media 引用（还没翻译）',
    strpos((string) json_encode($ai->seen[0]), 'agent_media') !== false);
$sentJson = (string) json_encode($ai->requests[0]);
test('发出去的请求里有 base64 图片', strpos($sentJson, base64_encode($PNG)) !== false);
test('发出去的请求里是 image_url（OpenAI 家族）', strpos($sentJson, 'image_url') !== false);
test('agent_media 没漏到平台侧', strpos($sentJson, 'agent_media') === false);

// 附件真的落库了
$mediaStore = $agent->mediaManager()->store();
$id = MediaReference::idOfUri($mediaBlocks[0]['ref']);
assert_eq('落库内容与原文件一致', $PNG, $mediaStore->get($id));

// ===================================================================
echo "\n=== 3. 多轮保持 ===\n";
// ===================================================================

$agent2 = make_agent($tmp, $ai2);
$agent2->chat('第一轮：看这张图', [Attachment::fromPath($tmp . '/uploads/a.png')]);
$agent2->chat('第二轮：随便聊聊');
$agent2->chat('第三轮：刚才那张图里是什么');

$conv2 = $agent2->getConversation();
assert_eq('三轮之后媒体块仍在历史里', 1, count(MessagePart::mediaBlocksIn($conv2)));
assert_eq('模型被调用三次', 3, count($ai2->seen));

// 第三轮的请求里图片仍然被发出去了 —— 这正是坑①要解决的问题
$third = (string) json_encode($ai2->requests[2]);
test('第三轮请求里图片还在', strpos($third, base64_encode($PNG)) !== false);

// 多个附件
$agent3 = make_agent($tmp, $ai3);
$agent3->chat('两张图', [
    Attachment::fromPath($tmp . '/uploads/a.png'),
    $tmp . '/uploads/b.gif',
]);
assert_eq('两个媒体块', 2, count(MessagePart::mediaBlocksIn($agent3->getConversation())));

// ===================================================================
echo "\n=== 4. 会话持久化与恢复 ===\n";
// ===================================================================

$aiA = null;
$agentA = make_agent($tmp, $aiA);
$agentA->setSessionId('sess-media-1')->autoPersist(true);
$agentA->chat('存下来这张图', [Attachment::fromPath($tmp . '/uploads/a.png')]);

$refInA = MessagePart::mediaBlocksIn($agentA->getConversation())[0]['ref'];

// 新建一个 Agent，同 sessionId 恢复
$aiB = null;
$agentB = make_agent($tmp, $aiB);
$agentB->setSessionId('sess-media-1')->autoPersist(true);
$agentB->chat('刚才那张图里有什么');

$convB = $agentB->getConversation();
$mediaB = MessagePart::mediaBlocksIn($convB);
test('会话恢复后媒体块还在', count($mediaB) >= 1);
assert_eq('ref 与写入时一致', $refInA, $mediaB[0]['ref']);

$sentB = (string) json_encode($aiB->requests[0]);
test('恢复后的请求里图片仍能解析出来', strpos($sentB, base64_encode($PNG)) !== false);

// 会话文件本身不含 base64
$sessDir = $agentB->agentHome()->sessionDir('sess-media-1');
$sessJson = '';
if (is_dir($sessDir)) {
    foreach (scandir($sessDir) as $f) {
        if ($f !== '.' && $f !== '..' && is_file($sessDir . '/' . $f)) {
            $sessJson .= (string) file_get_contents($sessDir . '/' . $f);
        }
    }
}
test('会话文件非空（确实落盘了）', $sessJson !== '');
test('会话文件里没有 base64 图片数据', strpos($sessJson, base64_encode($PNG)) === false);

// ===================================================================
echo "\n=== 5. 兼容性与错误处理 ===\n";
// ===================================================================

$aiC = null;
$agentC = make_agent($tmp, $aiC);
$agentC->chat('纯文本，不带附件');
assert_eq('不带附件时 content 仍是字符串', '纯文本，不带附件', $agentC->getConversation()[0]['content']);
test('不带附件时没有媒体块', MessagePart::mediaBlocksIn($agentC->getConversation()) === []);

// 附件不合法时抛出，且不产生半截历史
$aiD = null;
$agentD = make_agent($tmp, $aiD);
file_put_contents($tmp . '/uploads/bad.txt', 'not an image');
$threw = false;
try {
    $agentD->chat('看这个', [$tmp . '/uploads/bad.txt']);
} catch (\Ai\Agent\Media\MediaException $e) {
    $threw = true;
}
test('非法附件抛 MediaException', $threw);
assert_eq('抛出后没有留下半截对话', 0, count($agentD->getConversation()));
assert_eq('抛出后没有调用模型', 0, count($aiD->seen));

// run() 签名不变，媒体由 messages 承载
$aiE = null;
$agentE = make_agent($tmp, $aiE);
$refE = $agentE->mediaManager()->ingest([$tmp . '/uploads/a.png']);
$agentE->run([['role' => 'user', 'content' => MessagePart::compose('看图', $refE)]]);
$sentE = (string) json_encode($aiE->requests[0]);
test('run() 里的媒体块同样被翻译', strpos($sentE, base64_encode($PNG)) !== false);

// ===================================================================
echo "\n=== 6. Anthropic 家族同样能收到图片 ===\n";
// ===================================================================

$aiF = null;
$agentF = make_agent($tmp, $aiF);
$aiF->family = 'anthropic';
$agentF->chat('看这张图', [Attachment::fromPath($tmp . '/uploads/a.png')]);
$sentF = (string) json_encode($aiF->requests[0]);
test('Anthropic 请求里有 base64 图片', strpos($sentF, base64_encode($PNG)) !== false);
test('Anthropic 用的是 image/source 块', strpos($sentF, '"type":"image"') !== false);
test('Anthropic 请求里没有 image_url', strpos($sentF, 'image_url') === false);
test('agent_media 没漏出去', strpos($sentF, 'agent_media') === false);

echo "\n=== 清理 ===\n";
if ($origHome !== false) { putenv('HOME=' . $origHome); } else { putenv('HOME'); }
rrmdir($tmp);
test('临时目录已清理', !is_dir($tmp));

echo "\n========================================\n";
echo "通过: {$passed}  失败: {$failed}\n";
echo "========================================\n";
exit($failed > 0 ? 1 : 0);
