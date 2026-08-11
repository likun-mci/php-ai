<?php
namespace Ai\Protocol;

/**
 * LM Studio（本地，OpenAI 兼容）
 *
 * 本机默认端口 1234，无需 api_key。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => '模型名',
 *     'protocol' => 'lmstudio',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://lmstudio.ai/docs/app/api/endpoints/openai
 */
class LMStudio extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'http://localhost:1234';
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
