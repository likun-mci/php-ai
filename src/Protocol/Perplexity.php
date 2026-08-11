<?php
namespace Ai\Protocol;

/**
 * Perplexity Sonar（联网搜索，OpenAI 兼容）
 *
 * 对话路径没有 /v1 前缀，库已内置。
 * 该平台没有模型列表接口，listModels() 会回退到内置常用列表。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'sonar',
 *     'protocol' => 'perplexity',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://docs.perplexity.ai/api-reference/chat-completions-post
 */
class Perplexity extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.perplexity.ai';
    }

    /**
     * 协议对话路径
     */
    public function chatPath(): string
    {
        return '/chat/completions';
    }

    /**
     * 协议模型列表路径
     */
    public function modelsPath(): string
    {
        return '/models';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'sonar'               => 'Sonar',
            'sonar-pro'           => 'Sonar Pro',
            'sonar-reasoning'     => 'Sonar Reasoning',
            'sonar-reasoning-pro' => 'Sonar Reasoning Pro',
            'sonar-deep-research' => 'Sonar Deep Research',
        ];
    }
}
