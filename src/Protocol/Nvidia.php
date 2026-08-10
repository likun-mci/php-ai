<?php
namespace Ai\Protocol;

/**
 * NVIDIA NIM（OpenAI 兼容）
 *
 * api_key 取 build.nvidia.com 的 nvapi-xxx。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'deepseek-ai/deepseek-r1',
 *     'protocol' => 'nvidia',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://docs.api.nvidia.com/nim/reference/llm-apis
 */
class Nvidia extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://integrate.api.nvidia.com';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     */
    public function knownModels(): array
    {
        return [
            'deepseek-ai/deepseek-r1'                => 'DeepSeek R1',
            'meta/llama-3.3-70b-instruct'            => 'Llama 3.3 70B',
            'qwen/qwen3-235b-a22b'                   => 'Qwen3 235B',
            'nvidia/llama-3.1-nemotron-70b-instruct' => 'Nemotron 70B',
        ];
    }
}
