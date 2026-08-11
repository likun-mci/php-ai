<?php
namespace Ai\Protocol;

/**
 * xAI Grok（OpenAI 兼容）
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'grok-4',
 *     'protocol' => 'grok',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://docs.x.ai/docs/api-reference
 */
class Grok extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.x.ai';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'grok-4'           => 'Grok 4',
            'grok-4-fast'      => 'Grok 4 Fast',
            'grok-3'           => 'Grok 3',
            'grok-3-mini'      => 'Grok 3 Mini',
            'grok-code-fast-1' => 'Grok Code Fast',
        ];
    }
}
