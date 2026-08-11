<?php
namespace Ai\Protocol;

/**
 * Cerebras（高速推理，OpenAI 兼容）
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'llama-3.3-70b',
 *     'protocol' => 'cerebras',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://inference-docs.cerebras.ai/api-reference/chat-completions
 */
class Cerebras extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.cerebras.ai';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'llama-3.3-70b' => 'Llama 3.3 70B',
            'llama3.1-8b'   => 'Llama 3.1 8B',
            'qwen-3-32b'    => 'Qwen3 32B',
            'gpt-oss-120b'  => 'GPT-OSS 120B',
        ];
    }
}
