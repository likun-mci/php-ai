<?php
/**
 * OpenRouter 及常见 AI 中转站使用示例
 *
 * OpenRouter 是一个聚合 AI 平台，通过统一接口访问 OpenAI、Claude、Gemini、DeepSeek 等模型。
 * 库已内置 openrouter 协议，无需手动配置 base_url。
 *
 * 更多中转站（API2D、Cloudflare AI Gateway、one-api 等）见 README.md。
 */

require_once __DIR__ . '/autoload.php';

use Ai\AI;
use Ai\Exceptions\AIException;

// ===========================================
// 示例 1: 通过 OpenRouter 使用 GPT-4o（推荐方式）
// ===========================================
echo "=== 示例 1: OpenRouter GPT-4o（手选协议） ===\n\n";

try {
    $ai = AI::create([
        'model'    => 'openai/gpt-4o',            // OpenRouter 完整模型标识
        'protocol' => 'openrouter',                // 使用内置 OpenRouter 协议
        'api_key'  => 'sk-or-v1-xxxxxxxxx',        // 替换为你的 OpenRouter API Key
        'referer'  => 'https://myapp.com',         // 可选：来源标识
        'title'    => 'MyApp',                     // 可选：应用名称
    ]);

    $response = $ai->chat([
        'messages' => [
            ['role' => 'user', 'content' => '用一句话介绍量子计算']
        ],
        'temperature' => 0.7,
        'max_tokens'  => 200,
    ]);

    echo "回复: " . $response->getContent() . "\n";
    echo "模型: " . $response->getModel() . "\n";
    echo "消耗 Tokens: " . $response->tokens() . "\n";
    $usage = $response->getUsage();
    if (isset($usage['prompt_tokens_details'])) {
        echo "缓存命中: " . ($usage['prompt_tokens_details']['cached_tokens'] ?? 0) . "\n";
    }
    echo "\n";

} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 2: 通过 base_url 传统方式使用 OpenRouter
// ===========================================
echo "=== 示例 2: OpenRouter Claude（传统 base_url 方式） ===\n\n";

try {
    $ai = AI::create([
        'model'    => 'anthropic/claude-sonnet-4-20250514',
        'base_url' => 'https://openrouter.ai/api',  // 传统方式手动指定地址
        'api_key'  => 'sk-or-v1-xxxxxxxxx',
    ]);

    $response = $ai->chat([
        'messages' => [
            ['role' => 'user', 'content' => 'Python 和 PHP 的主要区别是什么？']
        ],
        'max_tokens' => 300,
    ]);

    echo "回复: " . $response->getContent() . "\n";
    echo "消耗 Tokens: " . $response->tokens() . "\n\n";

} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 3: 通过 OpenRouter 使用 DeepSeek
// ===========================================
echo "=== 示例 3: OpenRouter DeepSeek ===\n\n";

try {
    $ai = AI::create([
        'model'    => 'deepseek/deepseek-chat',
        'protocol' => 'openrouter',
        'api_key'  => 'sk-or-v1-xxxxxxxxx',
    ]);

    $response = $ai->chat('介绍一下人工智能的三个主要分支');

    echo "回复: " . $response->getContent() . "\n";
    echo "模型: " . $response->getModel() . "\n";
    echo "消耗 Tokens: " . $response->tokens() . "\n\n";

} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 4: 流式输出 + OpenRouter
// ===========================================
echo "=== 示例 4: OpenRouter 流式输出 ===\n\n";

try {
    $ai = AI::create([
        'model'    => 'openai/gpt-4o',
        'protocol' => 'openrouter',
        'api_key'  => 'sk-or-v1-xxxxxxxxx',
    ]);

    $response = $ai->setStream(true)->chat('写一句关于 AI 的诗');

    // 流式结束后可获取完整内容
    echo "\n\n完整内容: " . $response->getContent() . "\n";
    echo "消耗 Tokens: " . $response->tokens() . "\n\n";

} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 5: one-api / new-api 自建网关
// ===========================================
echo "=== 示例 5: one-api / new-api 自建聚合网关 ===\n\n";

try {
    $ai = AI::create([
        'model'    => 'gpt-4o-mini',
        'protocol' => 'openai',
        'api_key'  => 'sk-xxxxxxxxx',
        'base_url' => 'https://gateway.example.com',  // 替换为你的网关地址
    ]);

    $response = $ai->chat('今天天气怎么样？');

    echo "回复: " . $response->getContent() . "\n";
    echo "消耗 Tokens: " . $response->tokens() . "\n\n";

} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 6: 查询 OpenRouter 模型列表（含定价）
// ===========================================
echo "=== 示例 6: OpenRouter 模型列表 ===\n\n";

try {
    $ai = AI::create([
        'protocol' => 'openrouter',
        'api_key'  => 'sk-or-v1-xxxxxxxxx',
    ]);

    // 先设置模型以初始化协议
    $ai->setModel('openai/gpt-4o');

    // 获取完整模型列表（含 pricing、context_length 等）
    $rawModels = $ai->listModels(true);
    if (is_array($rawModels)) {
        echo "OpenRouter 可用模型（部分）:\n";
        $count = 0;
        foreach ($rawModels as $id => $info) {
            if ($count >= 5) break; // 只显示前5个
            $pricing = '';
            if (is_array($info) && isset($info['pricing'])) {
                $p = $info['pricing'];
                $pricing = " (输入: \${$p['prompt']}/1K, 输出: \${$p['completion']}/1K)";
            }
            echo "  - {$id}{$pricing}\n";
            $count++;
        }
    } else {
        echo "获取模型列表失败（OpenRouter 可能需要更高版本的 Key 或 API 已变更）\n";
    }
    echo "\n";

} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

echo "所有 OpenRouter 示例执行完成！\n";
