<?php
/**
 * P6 R3 模态路由测试
 *
 * 覆盖 dev.md 进度表 P6：四种模式（auto/native/describe/switch）、
 * 视觉服务方的挑选与 unknown_policy、describe 的派生上下文语义
 * （原 ref 保留、不重复描述、回填时注明来源）、以及没有视觉模型时的降级。
 *
 * 视觉模型用 stub 顶替，不发网络请求；全程临时目录。
 *
 * 运行：php tests/agent_modality_router_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Agent;
use Ai\Agent\Capability\CapabilityRegistry;
use Ai\Agent\Capability\CapabilityResolver;
use Ai\Agent\Capability\ModalityRouter;
use Ai\Agent\Capability\Modalities;
use Ai\Agent\Context\MessagePart;
use Ai\Agent\Media\FileMediaStore;
use Ai\Agent\Media\MediaManager;
use Ai\Helpers\MediaTranslator;

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

/** 假的视觉模型：记录收到的请求，返回固定描述 */
class VisionStubAI extends \Ai\AI
{
    /** @var int */
    public static $calls = 0;
    /** @var array<int, array<string, mixed>> */
    public static $seen = [];
    /** @var string */
    public static $answer = '图中是一个红色的圆形按钮，上面写着「提交订单」，右下角有数字 42。';
    /** @var bool */
    public static $fail = false;

    public function chat($payload = ''): \Ai\Contracts\AIResponseInterface
    {
        self::$calls++;
        self::$seen[] = is_array($payload) ? $payload : [];
        if (self::$fail) {
            throw new \RuntimeException('视觉模型暂时不可用');
        }
        // 真的跑一遍协议构建，确认图片确实被翻译进去了
        $protocol = new \Ai\Protocol\OpenAI();
        $p = is_array($payload) ? $payload : [];
        $p['model'] = 'vision-stub';
        self::$seen[count(self::$seen) - 1]['_request'] = $protocol->buildRequest($p);
        return new \Ai\Response\AIResponse(['content' => self::$answer, 'usage' => []]);
    }
}

$origHome = getenv('HOME');
$tmp = sys_get_temp_dir() . '/php-ai-router-test_' . getmypid();
rrmdir($tmp);
@mkdir($tmp . '/home', 0700, true);
@mkdir($tmp . '/work', 0700, true);
putenv('HOME=' . $tmp . '/home');

$PNG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents($tmp . '/work/a.png', $PNG);

$store   = new FileMediaStore($tmp . '/store');
$manager = new MediaManager($store);
$blocks  = $manager->ingest([$tmp . '/work/a.png']);
$mediaBlock = $blocks[0];

/** 造一条带图的消息 */
function msg_with_media($block, $text = '这张图里有什么')
{
    return [['role' => 'user', 'content' => MessagePart::compose($text, [$block])]];
}

/** 造一个挂了假视觉模型的路由器 */
function make_router($manager, array $options = [], array $platforms = [])
{
    $registry = new CapabilityRegistry();
    $registry->setPlatforms($platforms);
    $resolver = new CapabilityResolver($registry);
    $router = new ModalityRouter($resolver, array_merge(['platforms' => $platforms], $options));
    $router->setResolver($manager->resolver());
    $router->setAiFactory(function ($model, $config) {
        return new VisionStubAI(['api_key' => 'k', 'platform' => 'openai', 'model' => 'gpt-4o']);
    });
    return $router;
}

$platforms = [
    'gemini'   => ['api_key' => 'gk', 'model' => 'gemini-2.5-pro'],
    'deepseek' => ['api_key' => 'dk', 'model' => 'deepseek-chat'],
];

// ===================================================================
echo "\n=== 1. 没有媒体时什么都不做 ===\n";
// ===================================================================

$router = make_router($manager, [], $platforms);
$r = $router->route([['role' => 'user', 'content' => '纯文本']], 'deepseek-chat');
assert_eq('决策是 none', ModalityRouter::DECISION_NONE, $r['decision']);
assert_eq('消息没被改动', [['role' => 'user', 'content' => '纯文本']], $r['messages']);
assert_eq('没调用视觉模型', 0, VisionStubAI::$calls);

// ===================================================================
echo "\n=== 2. auto：主模型自己支持时直发，不多花一次调用 ===\n";
// ===================================================================

VisionStubAI::$calls = 0;
$router = make_router($manager, [], $platforms);
$r = $router->route(msg_with_media($mediaBlock), 'gpt-4o');
assert_eq('决策是 native', ModalityRouter::DECISION_NATIVE, $r['decision']);
assert_eq('没有调用视觉模型', 0, VisionStubAI::$calls);
assert_eq('support 标志正确', true, $r['support'][Modalities::IMAGE]);
test('消息没被改写', !isset($r['messages'][0]['content'][1]['description']));

// ===================================================================
echo "\n=== 3. auto：主模型不支持时自动路由到视觉模型 ===\n";
// ===================================================================

VisionStubAI::$calls = 0;
VisionStubAI::$seen = [];
$router = make_router($manager, [], $platforms);
$r = $router->route(msg_with_media($mediaBlock), 'deepseek-chat');

assert_eq('决策是 describe', ModalityRouter::DECISION_DESCRIBE, $r['decision']);
assert_eq('选中了 gemini', 'gemini-2.5-pro', $r['provider']);
assert_eq('视觉模型被调用一次', 1, VisionStubAI::$calls);
assert_eq('主模型的 support 仍是不支持', false, $r['support'][Modalities::IMAGE]);

$routedBlock = $r['messages'][0]['content'][1];
test('描述被写进媒体块', isset($routedBlock['description']) && $routedBlock['description'] !== '');
assert_eq('记录了描述来源', 'gemini-2.5-pro', $routedBlock['described_by']);

// 派生上下文不替代原始引用（设计文档 §5.3）
assert_eq('原 ref 原样保留', $mediaBlock['ref'], $routedBlock['ref']);
assert_eq('原 mime 保留', $mediaBlock['mime'], $routedBlock['mime']);
assert_eq('块类型仍是 agent_media', 'agent_media', $routedBlock['type']);

// 视觉模型收到的确实是图片
$visionReq = (string) json_encode(VisionStubAI::$seen[0]['_request']);
test('视觉模型收到了真图片', strpos($visionReq, base64_encode($PNG)) !== false);
test('视觉模型收到了描述指令', strpos((string) json_encode(VisionStubAI::$seen[0], JSON_UNESCAPED_UNICODE), '详细描述') !== false);

// 不重复描述
VisionStubAI::$calls = 0;
$r2 = $router->route($r['messages'], 'deepseek-chat');
assert_eq('已描述过的不再重复调用', 0, VisionStubAI::$calls);
assert_eq('第二次决策仍是 native（已无缺失模态）', ModalityRouter::DECISION_NATIVE, $r2['decision']);

// ===================================================================
echo "\n=== 4. 描述回填时注明来源，不伪装成主模型看到的 ===\n";
// ===================================================================

MediaTranslator::begin($manager->resolver(), ['image' => false, 'pdf' => false]);
$translated = MediaTranslator::translateContent($r['messages'][0]['content'], MediaTranslator::FAMILY_OPENAI);
MediaTranslator::end();

$textOut = '';
foreach ($translated as $b) {
    if (isset($b['type']) && $b['type'] === 'text') { $textOut .= $b['text']; }
}
test('描述内容出现在请求里', strpos($textOut, '提交订单') !== false);
test('注明了由哪个模型生成', strpos($textOut, 'gemini-2.5-pro') !== false);
test('注明了当前模型看不到原文件', strpos($textOut, '无法直接查看') !== false);
test('没有出现「我看到了」这类伪装', strpos($textOut, '无法查看图片内容') === false);

// ===================================================================
echo "\n=== 5. native 模式：强制直发，不路由 ===\n";
// ===================================================================

VisionStubAI::$calls = 0;
$router = make_router($manager, ['mode' => 'native'], $platforms);
$r = $router->route(msg_with_media($mediaBlock), 'deepseek-chat');
assert_eq('决策是 native', ModalityRouter::DECISION_NATIVE, $r['decision']);
assert_eq('没有调用视觉模型', 0, VisionStubAI::$calls);

// ===================================================================
echo "\n=== 6. describe 模式：即便主模型支持也走 ===\n";
// ===================================================================

VisionStubAI::$calls = 0;
$router = make_router($manager, ['mode' => 'describe'], $platforms);
$r = $router->route(msg_with_media($mediaBlock), 'gpt-4o');
assert_eq('决策是 describe', ModalityRouter::DECISION_DESCRIBE, $r['decision']);
assert_eq('调用了视觉模型', 1, VisionStubAI::$calls);

// ===================================================================
echo "\n=== 7. switch 模式：换模型，不改消息 ===\n";
// ===================================================================

VisionStubAI::$calls = 0;
$router = make_router($manager, ['mode' => 'switch'], $platforms);
$original = msg_with_media($mediaBlock);
$r = $router->route($original, 'deepseek-chat');
assert_eq('决策是 switch', ModalityRouter::DECISION_SWITCH, $r['decision']);
assert_eq('给出了要换用的模型', 'gemini-2.5-pro', $r['provider']);
assert_eq('消息一个字没改（图片原样发过去）', $original, $r['messages']);
assert_eq('没有预先调用视觉模型', 0, VisionStubAI::$calls);

// ===================================================================
echo "\n=== 8. 挑不到视觉模型时明确告知 ===\n";
// ===================================================================

VisionStubAI::$calls = 0;
$onlyText = ['deepseek' => ['api_key' => 'dk', 'model' => 'deepseek-chat']];
$router = make_router($manager, [], $onlyText);
$r = $router->route(msg_with_media($mediaBlock), 'deepseek-chat');
assert_eq('决策是 unavailable', ModalityRouter::DECISION_UNAVAILABLE, $r['decision']);
assert_eq('没有服务方', '', $r['provider']);
assert_eq('没有调用任何模型', 0, VisionStubAI::$calls);
test('给出了原因', isset($r['notes'][0]['reason']) && $r['notes'][0]['reason'] === 'no_provider');

// 一个平台都没配
$router = make_router($manager, [], []);
$r = $router->route(msg_with_media($mediaBlock), 'deepseek-chat');
assert_eq('没配平台时也是 unavailable', ModalityRouter::DECISION_UNAVAILABLE, $r['decision']);

// ===================================================================
echo "\n=== 9. 能力未知的模型默认不被选为服务方 ===\n";
// ===================================================================

$mystery = ['mystery' => ['api_key' => 'mk', 'model' => 'unknown-vl-model']];
$router = make_router($manager, [], $mystery);
$r = $router->route(msg_with_media($mediaBlock), 'deepseek-chat');
assert_eq('保守策略下不选未知模型', ModalityRouter::DECISION_UNAVAILABLE, $r['decision']);

// 平台显式声明后就能被选
$declared = ['mystery' => ['api_key' => 'mk', 'model' => 'unknown-vl-model', 'modalities' => ['vision']]];
VisionStubAI::$calls = 0;
$router = make_router($manager, [], $declared);
$r = $router->route(msg_with_media($mediaBlock), 'deepseek-chat');
assert_eq('声明后可被选中', ModalityRouter::DECISION_DESCRIBE, $r['decision']);
assert_eq('选中的是声明过的模型', 'unknown-vl-model', $r['provider']);

// 显式指定 model 时直接用
VisionStubAI::$calls = 0;
$router = make_router($manager, ['model' => 'my-vision'], $platforms);
$r = $router->route(msg_with_media($mediaBlock), 'deepseek-chat');
assert_eq('显式指定的模型被采用', 'my-vision', $r['provider']);

// ===================================================================
echo "\n=== 10. 视觉模型调用失败时不拖垮整轮 ===\n";
// ===================================================================

VisionStubAI::$calls = 0;
VisionStubAI::$fail = true;
$router = make_router($manager, [], $platforms);
$r = $router->route(msg_with_media($mediaBlock), 'deepseek-chat');
VisionStubAI::$fail = false;

assert_eq('仍然返回结果而不是抛异常', ModalityRouter::DECISION_DESCRIBE, $r['decision']);
test('没有写入描述', !isset($r['messages'][0]['content'][1]['description']));
$hasFailNote = false;
foreach ($r['notes'] as $n) {
    if (isset($n['reason']) && $n['reason'] === 'describe_failed') { $hasFailNote = true; }
}
test('失败被记进 notes', $hasFailNote);

// ===================================================================
echo "\n=== 11. 端到端：主模型纯文本 + 自动路由 ===\n";
// ===================================================================

class MainTextAI extends \Ai\AI
{
    /** @var array<int, array<string, mixed>> */
    public $requests = [];
    public function chat($payload = ''): \Ai\Contracts\AIResponseInterface
    {
        $p = is_array($payload) ? $payload : [];
        $p['model'] = 'deepseek-chat';
        $this->requests[] = (new \Ai\Protocol\OpenAI())->buildRequest($p);
        return new \Ai\Response\AIResponse(['content' => '根据描述，按钮上的数字是 42。', 'usage' => []]);
    }
}

VisionStubAI::$calls = 0;
$mainAi = new MainTextAI(['api_key' => 'k', 'platform' => 'deepseek', 'model' => 'deepseek-chat']);
$agent = (new Agent($mainAi))
    ->setWorkdir($tmp . '/work')
    ->setAgentHome($tmp . '/home/.agent');
$agent->media(['store' => new FileMediaStore($tmp . '/e2e')]);
$agent->platforms(['gemini' => ['api_key' => 'gk', 'model' => 'gemini-2.5-pro']]);
$agent->multimodal(['mode' => 'auto']);

// 视觉模型换成 stub：不换的话它会去真连 Gemini，失败后走 describe_failed 分支，
// 主模型照样收到「无法…」的文字——测试会绿，但绿的原因是调用失败而不是路由成功
$agent->modalityRouter()->setAiFactory(function ($model, $config) {
    return new VisionStubAI(['api_key' => 'k', 'platform' => 'openai', 'model' => 'gpt-4o']);
});

$events = [];
$agent->onEvent(function ($e) use (&$events) { $events[] = $e; });

$agent->chat('这张图里的数字是多少', [$tmp . '/work/a.png']);

assert_eq('端到端：视觉模型被调用了一次', 1, VisionStubAI::$calls);

$sent = (string) json_encode($mainAi->requests[0], JSON_UNESCAPED_UNICODE);
test('端到端：主模型请求里没有图片二进制', strpos($sent, base64_encode($PNG)) === false);
test('端到端：主模型收到了视觉模型写的描述', strpos($sent, '提交订单') !== false);
test('端到端：描述注明了来源', strpos($sent, 'gemini-2.5-pro') !== false);

// 描述落回了对话历史，下一轮不会重描
VisionStubAI::$calls = 0;
$agent->chat('那个按钮是什么颜色');
assert_eq('第二轮不再重复描述', 0, VisionStubAI::$calls);

$mediaInHistory = MessagePart::mediaBlocksIn($agent->getConversation());
test('历史里的媒体块带上了描述', isset($mediaInHistory[0]['description']));
assert_eq('历史里原 ref 仍在', 0, strpos($mediaInHistory[0]['ref'], 'media://'));

// 事件里能看到路由决策
$routeEvent = null;
foreach ($events as $e) {
    if (isset($e['type']) && $e['type'] === 'modality_route') { $routeEvent = $e; }
}
test('发出了 modality_route 事件', $routeEvent !== null);

// 路由器被缓存，定制不会丢
$agent->modalityRouter()->setMode('native');
assert_eq('路由器是同一个实例（定制不丢）', 'native', $agent->modalityRouter()->mode());

echo "\n=== 清理 ===\n";
if ($origHome !== false) { putenv('HOME=' . $origHome); } else { putenv('HOME'); }
rrmdir($tmp);
test('临时目录已清理', !is_dir($tmp));

echo "\n========================================\n";
echo "通过: {$passed}  失败: {$failed}\n";
echo "========================================\n";
exit($failed > 0 ? 1 : 0);
