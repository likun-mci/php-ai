<?php
namespace Ai\Protocol;

/**
 * DeepInfra（聚合，OpenAI 兼容）
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'deepseek-ai/DeepSeek-V3',
 *     'protocol' => 'deepinfra',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://deepinfra.com/docs/openai_api
 */
class DeepInfra extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.deepinfra.com/v1/openai';
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
     */
    public function knownModels(): array
    {
        return [
            'deepseek-ai/DeepSeek-V3'           => 'DeepSeek V3',
            'meta-llama/Llama-3.3-70B-Instruct' => 'Llama 3.3 70B',
            'Qwen/Qwen3-235B-A22B'              => 'Qwen3 235B',
        ];
    }
}
