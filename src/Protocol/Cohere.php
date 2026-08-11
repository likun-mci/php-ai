<?php
namespace Ai\Protocol;

/**
 * Cohere（OpenAI 兼容）
 *
 * 走 Cohere 的 OpenAI 兼容端点（/compatibility/v1），原生 v2 接口未接入。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'command-a-03-2025',
 *     'protocol' => 'cohere',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://docs.cohere.com/docs/compatibility-api
 */
class Cohere extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.cohere.ai/compatibility';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'command-a-03-2025'      => 'Command A',
            'command-r-plus-08-2024' => 'Command R+',
            'command-r-08-2024'      => 'Command R',
            'command-r7b-12-2024'    => 'Command R7B',
        ];
    }
}
