<?php
/**
 * AI 标准库 - 自定义接口示例
 *
 * 演示「任意模型名 + 手选协议格式 + 自定义接口地址」：
 * 既能接大模型平台的标准接口，也能接第三方中转、聚合网关、自建服务。
 *
 * 运行：php examples_custom.php
 * （只演示配置解析，不会真的发请求；把 API Key 换成自己的后可打开末尾的 chat 示例）
 */

require_once __DIR__ . '/autoload.php';

use Ai\AI;
use Ai\Exceptions\AIException;
use Ai\Exceptions\ConfigException;

echo "========================================\n";
echo "AI SDK - 自定义模型 / 协议 / 接口地址\n";
echo "========================================\n\n";

// ===========================================
// 示例 1: 可选的协议格式（后台下拉框用）
// ===========================================
echo "=== 示例 1: 协议格式列表 ===\n";
foreach ((new AI())->listProtocols() as $key => $label) {
    echo "  {$key}\t{$label}\n";
}
echo "\n";

// ===========================================
// 示例 2: 内置清单之外的官方新模型
// 按模型名识别协议家族与官方端点，不必等库更新
// ===========================================
echo "=== 示例 2: 官方新模型直接用 ===\n";
foreach (['claude-sonnet-4-5', 'gpt-5.1', 'gemini-3-pro', 'deepseek-v3'] as $name) {
    $ai = AI::create(['model' => $name, 'api_key' => 'sk-xxx']);
    printf("  %-20s 平台=%-9s 协议=%-9s 端点=%s\n",
        $name, $ai->getPlatform(), $ai->getProtocolKey(), $ai->resolveEndpoint());
}
echo "\n";

// ===========================================
// 示例 3: 第三方平台（任意模型名 + 手选协议 + 自定义地址）
// ===========================================
echo "=== 示例 3: 第三方 / 自建接口 ===\n";

$cases = [
    '阿里云百炼(OpenAI兼容)' => [
        'model'    => 'qwen-max',
        'protocol' => 'openai',
        'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
        'api_key'  => 'sk-xxx',
    ],
    '聚合网关(带路径前缀)' => [
        'model'    => 'glm-4.6',
        'protocol' => 'openai',
        'base_url' => 'https://gateway.example.com/openai',
        'api_key'  => 'sk-xxx',
    ],
    '自建Anthropic兼容网关' => [
        'model'    => 'my-agent-model',
        'protocol' => 'anthropic',          // 需要 Agent 工具调用时必须用 claude/anthropic 协议
        'base_url' => 'http://127.0.0.1:8080/gw',
        'api_key'  => 'k',
    ],
    '完整端点覆盖' => [
        'model'    => 'any-model',
        'protocol' => 'openai',
        'endpoint' => 'https://proxy.example.com/my/custom/path',
        'api_key'  => 'k',
    ],
    '内网服务(私有鉴权头)' => [
        'model'    => 'llama3',
        'protocol' => 'openai',
        'base_url' => 'http://10.0.0.9:11434/v1',
        'headers'  => ['Authorization' => null, 'X-Internal-Token' => 't'],
    ],
];

foreach ($cases as $title => $config) {
    $ai = AI::create($config);
    printf("  %-24s => %s\n", $title, $ai->resolveEndpoint());
}
echo "\n";

// ===========================================
// 示例 4: 缺少接口地址时的保护
// 模型名无法归属官方平台、又没给 base_url/endpoint 时报错，
// 避免把第三方 Key 发到不相干的官方域名
// ===========================================
echo "=== 示例 4: 未知模型缺少地址 ===\n";
try {
    AI::create(['model' => 'qwen-max', 'api_key' => 'sk-xxx'])->resolveEndpoint();
    echo "  不应该走到这里\n";
} catch (ConfigException $e) {
    echo "  ConfigException: " . $e->getMessage() . "\n";
}
echo "\n";

// ===========================================
// 示例 5: 列举自定义网关上的模型
// listModels() 跟随 base_url / endpoint，列的是网关的模型
// ===========================================
echo "=== 示例 5: 拉取网关模型列表（需真实地址与 Key）===\n";
/*
$ai = AI::create([
    'model'    => 'qwen-max',
    'protocol' => 'openai',
    'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
    'api_key'  => 'sk-xxx',
]);
$models = $ai->listModels();   // 实际请求 .../compatible-mode/v1/models
print_r($models);
*/
echo "  （示例代码见文件内注释）\n\n";

// ===========================================
// 示例 6: 真实对话 + 接口私有参数
// ===========================================
echo "=== 示例 6: 对话（需真实地址与 Key）===\n";
/*
try {
    $ai = AI::create([
        'model'      => 'qwen-max',
        'protocol'   => 'openai',
        'base_url'   => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
        'api_key'    => 'sk-xxx',
        'max_tokens' => 2048,
        'extra_body' => ['enable_thinking' => false],   // 接口私有参数直接并入请求体
    ]);
    echo $ai->setTimeout(120)->chat('用一句话介绍你自己')->getContent(), "\n";
} catch (AIException $e) {
    echo "错误: {$e->getMessage()}（平台：{$e->getPlatform()}）\n";
}
*/
echo "  （示例代码见文件内注释）\n";
