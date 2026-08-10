<?php
namespace Ai\Protocol;

/**
 * Mistral AI（OpenAI 兼容）
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'mistral-large-latest',
 *     'protocol' => 'mistral',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://docs.mistral.ai/api/
 */
class Mistral extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.mistral.ai';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     */
    public function knownModels(): array
    {
        return [
            'mistral-large-latest'    => 'Mistral Large',
            'mistral-medium-latest'   => 'Mistral Medium',
            'mistral-small-latest'    => 'Mistral Small',
            'magistral-medium-latest' => 'Magistral（推理）',
            'codestral-latest'        => 'Codestral（代码）',
            'pixtral-large-latest'    => 'Pixtral Large（视觉）',
        ];
    }
}
