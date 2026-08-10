<?php
namespace Ai\Protocol;

/**
 * Fireworks AI（聚合，OpenAI 兼容）
 *
 * model 用 accounts/fireworks/models/xxx 的完整路径。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'accounts/fireworks/models/deepseek-v3',
 *     'protocol' => 'fireworks',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://docs.fireworks.ai/api-reference/post-chatcompletions
 */
class Fireworks extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.fireworks.ai/inference';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     */
    public function knownModels(): array
    {
        return [
            'accounts/fireworks/models/deepseek-v3'             => 'DeepSeek V3',
            'accounts/fireworks/models/llama-v3p3-70b-instruct' => 'Llama 3.3 70B',
            'accounts/fireworks/models/qwen3-235b-a22b'         => 'Qwen3 235B',
        ];
    }
}
