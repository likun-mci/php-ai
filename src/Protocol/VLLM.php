<?php
namespace Ai\Protocol;

/**
 * vLLM（自建推理服务，OpenAI 兼容）
 *
 * vllm serve 默认端口 8000；SGLang / Xinference 等同样兼容，改 base_url 即可。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => '模型名',
 *     'protocol' => 'vllm',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://docs.vllm.ai/en/latest/serving/openai_compatible_server.html
 */
class VLLM extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'http://localhost:8000';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [];
    }
}
