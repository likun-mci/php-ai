<?php
/**
 * P5 能力系统测试
 *
 * 覆盖 dev.md 进度表 P5：Modalities、CapabilityRegistry 的匹配优先级、
 * CapabilityResolver 的三层合并、null/false 语义、unknown_policy、
 * 以及 platforms() 打通到主循环后能力真的生效。
 *
 * 不发网络请求；全程临时目录。
 *
 * 运行：php tests/agent_capability_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Agent;
use Ai\Agent\Capability\CapabilityRegistry;
use Ai\Agent\Capability\CapabilityResolver;
use Ai\Agent\Capability\Modalities;
use Ai\Agent\Media\FileMediaStore;

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

$origHome = getenv('HOME');
$tmp = sys_get_temp_dir() . '/php-ai-cap-test_' . getmypid();
rrmdir($tmp);
@mkdir($tmp . '/home', 0700, true);
@mkdir($tmp . '/work', 0700, true);
putenv('HOME=' . $tmp . '/home');

// ===================================================================
echo "\n=== 1. Modalities ===\n";
// ===================================================================

assert_eq('全部模态', ['text', 'image', 'pdf'], Modalities::all());
assert_eq('需判断能力的模态', ['image', 'pdf'], Modalities::rich());
test('isValid', Modalities::isValid('image') && !Modalities::isValid('audio'));
assert_eq('中文名', '图片', Modalities::label('image'));

// ===================================================================
echo "\n=== 2. CapabilityRegistry 模式匹配 ===\n";
// ===================================================================

test('精确匹配', CapabilityRegistry::matches('gpt-4o', 'gpt-4o'));
test('精确不匹配', !CapabilityRegistry::matches('gpt-4o', 'gpt-4'));
test('前缀通配', CapabilityRegistry::matches('gpt-4o-2024-11-20', 'gpt-4o*'));
test('中缀通配', CapabilityRegistry::matches('doubao-1-5-vision-pro', 'doubao-*vision*'));
test('大小写不敏感', CapabilityRegistry::matches('GPT-4O', 'gpt-4o'));
test('空模式不匹配', !CapabilityRegistry::matches('gpt-4o', ''));
test('通配不会误伤', !CapabilityRegistry::matches('claude-3-opus', 'gpt-4o*'));

$reg = new CapabilityRegistry();

// 内置表：确定支持的
$gpt4o = $reg->lookup('gpt-4o');
assert_eq('gpt-4o 支持图片', true, $gpt4o['input']['image']);
assert_eq('gpt-4o 带日期后缀也匹配', true, $reg->lookup('gpt-4o-2024-11-20')['input']['image']);
assert_eq('gpt-4.1 支持图片', true, $reg->lookup('gpt-4.1')['input']['image']);

// 内置表：确定不支持的
assert_eq('gpt-3.5-turbo 不支持图片', false, $reg->lookup('gpt-3.5-turbo')['input']['image']);
assert_eq('deepseek-chat 不支持图片', false, $reg->lookup('deepseek-chat')['input']['image']);
assert_eq('qwen-max 不支持图片', false, $reg->lookup('qwen-max')['input']['image']);

// Claude：图片 + PDF + tool_result 带图
$claude = $reg->lookup('claude-3-opus-20240229');
assert_eq('claude 支持图片', true, $claude['input']['image']);
assert_eq('claude 支持 PDF', true, $claude['input']['pdf']);
assert_eq('claude 的 tool_result 可带图', true, $claude['tool_result']['image']);

// Gemini
assert_eq('gemini-2.5-pro 支持图片', true, $reg->lookup('gemini-2.5-pro')['input']['image']);
assert_eq('gemini-2.5-pro 支持 PDF', true, $reg->lookup('gemini-2.5-pro')['input']['pdf']);

// 更具体的模式要先于宽泛的命中
assert_eq('o1-mini 不支持图片（比 o1* 更具体）', false, $reg->lookup('o1-mini')['input']['image']);
assert_eq('o1 支持图片', true, $reg->lookup('o1')['input']['image']);
assert_eq('gemini-1.0 不支持 PDF（比 gemini-* 更具体）', false, $reg->lookup('gemini-1.0-pro')['input']['pdf']);

// 未知模型返回 null，不是空数组
assert_eq('未知模型返回 null', null, $reg->lookup('some-private-model-v3'));
assert_eq('空模型名也返回 null', null, $reg->lookup(''));

// ===================================================================
echo "\n=== 3. 用户覆盖优先级最高 ===\n";
// ===================================================================

$reg2 = new CapabilityRegistry();
$reg2->override('gpt-4o', ['input' => ['image' => false]]);
assert_eq('override 压过内置表', false, $reg2->lookup('gpt-4o')['input']['image']);

$reg2->override('my-private-vl', ['input' => ['image' => true]]);
assert_eq('override 能给未知模型定能力', true, $reg2->lookup('my-private-vl')['input']['image']);

$reg2->override('gpt-4o', ['input' => ['image' => true]]);
assert_eq('同一模式覆盖两次以最后一次为准', true, $reg2->lookup('gpt-4o')['input']['image']);

$reg2->override('vl-*', ['input' => ['image' => true]]);
assert_eq('override 支持通配', true, $reg2->lookup('vl-7b-chat')['input']['image']);

// ===================================================================
echo "\n=== 4. 平台声明作兜底 ===\n";
// ===================================================================

$reg3 = new CapabilityRegistry();
$reg3->setPlatforms([
    'gemini'   => ['api_key' => 'x', 'modalities' => ['vision', 'pdf']],
    'deepseek' => ['api_key' => 'y', 'modalities' => []],
]);

$viaPlatform = $reg3->lookup('some-unknown-gemini-model', 'gemini');
test('模型名匹配不上时用平台声明', is_array($viaPlatform));
assert_eq('平台声明的 vision 生效', true, $viaPlatform['input']['image']);
assert_eq('平台声明的 pdf 生效', true, $viaPlatform['input']['pdf']);

$emptyDecl = $reg3->lookup('unknown-ds-model', 'deepseek');
assert_eq('声明空列表 = 确定都不支持', false, $emptyDecl['input']['image']);
test('空列表不是 null（区别于未知）', $emptyDecl['input']['image'] !== null);

// 模型名能匹配上时优先用模型名
assert_eq('模型名优先于平台声明', false, $reg3->lookup('deepseek-chat', 'gemini')['input']['image']);
assert_eq('未配置的平台仍返回 null', null, $reg3->lookup('unknown-model', 'openai'));

assert_eq("'vision' 是 image 的别名", true,
    CapabilityRegistry::capsFromModalities(['vision'])['input']['image']);

// ===================================================================
echo "\n=== 5. CapabilityResolver 三层合并与 null 语义 ===\n";
// ===================================================================

$resolver = new CapabilityResolver();

assert_eq('已知支持', true, $resolver->supports('gpt-4o', Modalities::IMAGE));
assert_eq('已知不支持', false, $resolver->supports('deepseek-chat', Modalities::IMAGE));
assert_eq('未知返回 null 而不是 false', null, $resolver->supports('mystery-model', Modalities::IMAGE));
test('null 与 false 是两回事',
    $resolver->supports('mystery-model', Modalities::IMAGE) !== $resolver->supports('deepseek-chat', Modalities::IMAGE));
assert_eq('文本一律支持', true, $resolver->supports('any-model', Modalities::TEXT));

// tool_result 带图按协议家族的硬约束
assert_eq('Anthropic 家族 tool_result 可带图', true,
    $resolver->toolResultSupportsImage('claude-3-opus', ['family' => 'anthropic']));
assert_eq('OpenAI 家族 tool_result 不能带图', false,
    $resolver->toolResultSupportsImage('gpt-4o', ['family' => 'openai']));

// 调用方显式传入的 capabilities 优先级最高
assert_eq('显式 capabilities 覆盖一切', false,
    $resolver->supports('gpt-4o', Modalities::IMAGE, ['capabilities' => ['input' => ['image' => false]]]));

// ===================================================================
echo "\n=== 6. unknown_policy ===\n";
// ===================================================================

$conservative = new CapabilityResolver(null, ['unknown_policy' => 'conservative']);
assert_eq('默认就是保守', 'conservative', (new CapabilityResolver())->unknownPolicy());
assert_eq('保守：不把未知模型当视觉服务方', false, $conservative->canServe('mystery', Modalities::IMAGE));
assert_eq('保守：已知支持的照选', true, $conservative->canServe('gpt-4o', Modalities::IMAGE));
assert_eq('保守：已知不支持的不选', false, $conservative->canServe('deepseek-chat', Modalities::IMAGE));

$optimistic = new CapabilityResolver(null, ['unknown_policy' => 'optimistic']);
assert_eq('乐观：未知也可以被选', true, $optimistic->canServe('mystery', Modalities::IMAGE));
assert_eq('乐观：已知不支持的仍然不选', false, $optimistic->canServe('deepseek-chat', Modalities::IMAGE));

// supportFlags：当前模型未知时乐观（照发不误），不谎称看不到
$flags = $resolver->supportFlags('mystery-model', [], true);
assert_eq('当前模型未知时按支持处理', true, $flags[Modalities::IMAGE]);
$flagsStrict = $resolver->supportFlags('mystery-model', [], false);
assert_eq('作为候选服务方时未知按不支持处理', false, $flagsStrict[Modalities::IMAGE]);
assert_eq('已知不支持时两种口径一致', false, $resolver->supportFlags('deepseek-chat', [], true)[Modalities::IMAGE]);

// ===================================================================
echo "\n=== 7. 接进 Agent：platforms() 打通到主循环 ===\n";
// ===================================================================

class CapAI extends \Ai\AI
{
    /** @var array<int, array<string, mixed>> */
    public $seen = [];
    public function chat($payload = ''): \Ai\Contracts\AIResponseInterface
    {
        $this->seen[] = is_array($payload) ? $payload : [];
        return new \Ai\Response\AIResponse(['content' => 'ok', 'usage' => []]);
    }
}

$PNG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents($tmp . '/work/a.png', $PNG);

function make_cap_agent($tmp, $model, &$ai)
{
    $ai = new CapAI(['api_key' => 'k', 'platform' => 'openai', 'model' => $model]);
    $agent = (new Agent($ai))->setWorkdir($tmp . '/work')->setAgentHome($tmp . '/home/.agent');
    $agent->media(['store' => new FileMediaStore($tmp . '/store_' . md5($model))]);
    return $agent;
}

// 已知支持视觉的模型：图片应当被真的发出去
$aiV = null;
$agentV = make_cap_agent($tmp, 'gpt-4o', $aiV);
$agentV->chat('看图', [$tmp . '/work/a.png']);
$flagsV = $agentV->getRuntime()->getMediaResolver() !== null;
test('装配了媒体解析器', $flagsV);
$sentV = (string) json_encode($aiV->seen[0], JSON_UNESCAPED_UNICODE);
test('gpt-4o：媒体块进入了请求（能力判定为支持）', strpos($sentV, 'agent_media') !== false);

// 已知不支持视觉的模型：翻译时会被降级成明确说明
$aiT = null;
$agentT = make_cap_agent($tmp, 'deepseek-chat', $aiT);
$agentT->chat('看图', [$tmp . '/work/a.png']);
$protocol = new \Ai\Protocol\OpenAI();
\Ai\Helpers\MediaTranslator::begin(
    $agentT->mediaManager()->resolver(),
    $agentT->getRuntime()->getMediaResolver() !== null
        ? $agentT->capabilities()->supportFlags('deepseek-chat', [], true)
        : []
);
$req = $protocol->buildRequest(['model' => 'deepseek-chat', 'messages' => $aiT->seen[0]['messages']]);
\Ai\Helpers\MediaTranslator::end();
$reqJson = (string) json_encode($req, JSON_UNESCAPED_UNICODE);
test('deepseek-chat：图片没有被发出去', strpos($reqJson, base64_encode($PNG)) === false);
test('deepseek-chat：给出明确说明', strpos($reqJson, '无法查看') !== false);

// platforms() 里的 modalities 进了能力表
$aiP = null;
$agentP = make_cap_agent($tmp, 'private-vl-7b', $aiP);
assert_eq('未声明前是未知', null, $agentP->capabilities()->supports('private-vl-7b', Modalities::IMAGE));

$agentP->platforms(['myplat' => ['api_key' => 'k', 'modalities' => ['vision']]]);
assert_eq('platforms() 的配置被 Agent 自己留了一份', true, isset($agentP->platformConfigs()['myplat']));
assert_eq('平台声明进了能力表', true,
    $agentP->capabilities()->supports('any-model', Modalities::IMAGE, ['platform' => 'myplat']));

// multimodal() 的 capabilities 覆盖
$agentP->multimodal(['capabilities' => ['private-vl-7b' => ['input' => ['image' => true]]]]);
assert_eq('multimodal() 的 capabilities 生效', true,
    $agentP->capabilities()->supports('private-vl-7b', Modalities::IMAGE));

$agentP->multimodal(['unknown_policy' => 'optimistic']);
assert_eq('unknown_policy 可配置', 'optimistic', $agentP->capabilities()->unknownPolicy());

// 能力真的同步到了运行时
$aiS = null;
$agentS = make_cap_agent($tmp, 'deepseek-chat', $aiS);
$agentS->chat('随便说说');
$support = $agentS->getRuntime() !== null;
test('运行时拿到了模态支持标志', $support);

echo "\n=== 清理 ===\n";
if ($origHome !== false) { putenv('HOME=' . $origHome); } else { putenv('HOME'); }
rrmdir($tmp);
test('临时目录已清理', !is_dir($tmp));

echo "\n========================================\n";
echo "通过: {$passed}  失败: {$failed}\n";
echo "========================================\n";
exit($failed > 0 ? 1 : 0);
