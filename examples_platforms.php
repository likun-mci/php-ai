<?php
/**
 * 国内外主流 AI 平台接入示例
 *
 * 本库把各平台的接口地址、鉴权头、路径差异都收进了协议层，
 * 业务代码只需要换 protocol + api_key + model 三个配置，其余写法完全一致。
 *
 * 运行：php examples_platforms.php
 */

require_once __DIR__ . '/autoload.php';

use Ai\AI;
use Ai\Helpers\Protocols;

/** 中英混排的等宽对齐（中文字符占两列） */
function pad(string $text, int $width): string
{
    $pad = $width - mb_strwidth($text, 'UTF-8');
    return $text . ($pad > 0 ? str_repeat(' ', $pad) : '');
}

// ============================================================
// 1. 列举本库支持的平台与协议（后台下拉框数据源，不发请求）
// ============================================================

echo "=== 支持的平台（平台键 => 显示名） ===\n";
$ai = new AI();
foreach ($ai->listPlatforms() as $key => $name) {
    echo pad($key, 22) . $name . "\n";
}

echo "\n=== 协议格式（按地区分组，可直接渲染 optgroup） ===\n";
foreach ($ai->listProtocolGroups() as $group => $items) {
    echo "\n【{$group}】\n";
    foreach ($items as $key => $label) {
        echo '  ' . pad($key, 22) . $label . "\n";
    }
}

echo "\n=== 某平台的常用模型（离线清单，无需 Key） ===\n";
foreach (['qwen', 'zhipu', 'moonshot', 'doubao', 'grok'] as $protocol) {
    echo "\n{$protocol}（" . Protocols::vendorOf($protocol) . "）:\n";
    foreach ($ai->listKnownModels($protocol) as $id => $name) {
        echo '  ' . pad($id, 36) . $name . "\n";
    }
}

// ============================================================
// 2. 中国大陆主流平台：换 protocol 即可，写法完全一致
// ============================================================

$cnPlatforms = [
    // 协议标识            模型                        说明
    ['qwen',      'qwen-plus',              '阿里云百炼 / 通义千问'],
    ['doubao',    'doubao-seed-1-6',        '火山方舟 / 豆包'],
    ['zhipu',     'glm-4.6',                '智谱 GLM'],
    ['moonshot',  'kimi-latest',            '月之暗面 Kimi'],
    ['ernie',     'ernie-4.0-turbo-8k',     '百度千帆 / 文心一言'],
    ['hunyuan',   'hunyuan-turbos-latest',  '腾讯混元'],
    ['spark',     '4.0Ultra',               '讯飞星火（api_key 格式为 APIKey:APISecret）'],
    ['minimax',   'MiniMax-M2',             'MiniMax 稀宇'],
    ['stepfun',   'step-2-16k',             '阶跃星辰'],
    ['deepseek',  'deepseek-chat',          'DeepSeek 深度求索'],
];

echo "\n=== 中国大陆平台的实际请求端点 ===\n";
foreach ($cnPlatforms as [$protocol, $model, $desc]) {
    $client = AI::create([
        'protocol' => $protocol,
        'model'    => $model,
        'api_key'  => 'sk-your-key',   // 换成该平台控制台里的真实 Key
    ]);
    printf("%-12s %-24s %s\n", $protocol, $model, $client->resolveEndpoint());
}

// ============================================================
// 3. 海外主流平台
// ============================================================

$globalPlatforms = [
    ['openai',     'gpt-5.1',               'OpenAI'],
    ['claude',     'claude-opus-5',         'Anthropic Claude'],
    ['gemini',     'gemini-2.5-pro',        'Google Gemini'],
    ['grok',       'grok-4',                'xAI Grok'],
    ['mistral',    'mistral-large-latest',  'Mistral AI'],
    ['perplexity', 'sonar-pro',             'Perplexity（联网搜索）'],
    ['cohere',     'command-a-03-2025',     'Cohere'],
    ['llama',      'Llama-3.3-70B-Instruct','Meta Llama API'],
];

echo "\n=== 海外平台的实际请求端点 ===\n";
foreach ($globalPlatforms as [$protocol, $model, $desc]) {
    $client = AI::create([
        'protocol' => $protocol,
        'model'    => $model,
        'api_key'  => 'sk-your-key',
    ]);
    printf("%-12s %-24s %s\n", $protocol, $model, $client->resolveEndpoint());
}

// ============================================================
// 4. 模型名可自动推断平台：不写 protocol 也能用
// ============================================================

echo "\n=== 由模型名推断平台（省掉 protocol 配置） ===\n";
foreach (['qwen-max', 'glm-4.6', 'kimi-k2-0905-preview', 'doubao-seed-1-6',
          'hunyuan-lite', 'grok-4', 'sonar-pro', 'gpt-4o', 'claude-opus-5'] as $model) {
    printf("%-24s => %s\n", $model, $ai->platformOfModel($model));
}

// ============================================================
// 5. 真正发起对话：三个平台，同一段业务代码
// ============================================================

/**
 * 与任意平台对话——业务层只关心 protocol / model / api_key
 */
function askAnyPlatform(string $protocol, string $model, string $apiKey, string $question): string
{
    $client = AI::create([
        'protocol'    => $protocol,
        'model'       => $model,
        'api_key'     => $apiKey,
        'temperature' => 0.7,
        'max_tokens'  => 1024,
    ]);

    return $client->chat($question)->getContent();
}

// 取消注释并填入真实 Key 即可运行：
// echo askAnyPlatform('qwen',     'qwen-plus',   getenv('QWEN_API_KEY'),     '用一句话介绍你自己') . "\n";
// echo askAnyPlatform('zhipu',    'glm-4.6',     getenv('ZHIPU_API_KEY'),    '用一句话介绍你自己') . "\n";
// echo askAnyPlatform('moonshot', 'kimi-latest', getenv('MOONSHOT_API_KEY'), '用一句话介绍你自己') . "\n";

// ============================================================
// 6. 需要 Agent 工具调用时：选平台的 Anthropic 兼容端点
// ============================================================
//
// 工具调用（tools）目前只有 Claude 协议实现，以下平台提供了 Anthropic 兼容端点，
// 可以用国产模型的价格跑 Anthropic 的 tools 协议：
//
//   protocol = deepseek-anthropic 之外，还有：
//     zhipu-anthropic     智谱 GLM
//     moonshot-anthropic  月之暗面 Kimi
//     qwen-anthropic      阿里云百炼
//
// $agentClient = AI::create([
//     'protocol' => 'zhipu-anthropic',
//     'model'    => 'glm-4.6',
//     'api_key'  => getenv('ZHIPU_API_KEY'),
// ]);

echo "\n=== 支持工具调用（Claude 协议）的平台 ===\n";
foreach (array_keys(Protocols::all()) as $key) {
    if (Protocols::keyOfClass(Protocols::resolveClass($key)) === $key
        && is_a(Protocols::resolveClass($key), 'Ai\\Protocol\\Claude', true)) {
        echo pad($key, 22) . Protocols::vendorOf($key) . "\n";
    }
}

// ============================================================
// 7. 聚合中转与本地部署
// ============================================================

echo "\n=== 聚合中转 / 本地部署 ===\n";
foreach (['openrouter', 'siliconflow', 'modelscope', 'groq', 'together',
          'ollama', 'lmstudio', 'vllm'] as $protocol) {
    echo pad($protocol, 14) . pad(Protocols::vendorOf($protocol), 30)
       . Protocols::endpointOf($protocol) . "\n";
}

// 本地 Ollama：不需要 Key
// $local = AI::create(['protocol' => 'ollama', 'model' => 'qwen2.5']);
// echo $local->chat('你好')->getContent();

// 远程 Ollama：用 base_url 指过去
// $remote = AI::create([
//     'protocol' => 'ollama',
//     'model'    => 'qwen2.5',
//     'base_url' => 'http://10.0.0.9:11434',
// ]);

echo "\n完成。\n";
