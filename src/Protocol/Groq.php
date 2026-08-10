<?php
namespace Ai\Protocol;

/**
 * Groq（高速推理，OpenAI 兼容）
 *
 * LPU 硬件推理，主打低延迟，托管 Llama / Kimi / GPT-OSS 等开源模型。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'llama-3.3-70b-versatile',
 *     'protocol' => 'groq',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://console.groq.com/docs/openai
 */
class Groq extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.groq.com/openai';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     */
    public function knownModels(): array
    {
        return [
            'llama-3.3-70b-versatile'     => 'Llama 3.3 70B',
            'llama-3.1-8b-instant'        => 'Llama 3.1 8B',
            'openai/gpt-oss-120b'         => 'GPT-OSS 120B',
            'moonshotai/kimi-k2-instruct' => 'Kimi K2',
        ];
    }
}
