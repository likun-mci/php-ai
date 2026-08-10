<?php
namespace Ai\Protocol;

/**
 * Meta Llama API（OpenAI 兼容）
 *
 * Meta 官方 API。llama 系列开源模型在 Groq / Together 等平台也有托管，
 * 模型名不参与协议自动推断，需显式指定 protocol 或 base_url。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'Llama-4-Maverick-17B-128E-Instruct-FP8',
 *     'protocol' => 'llama',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://llama.developer.meta.com/docs/
 */
class Llama extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.llama.com/compat';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     */
    public function knownModels(): array
    {
        return [
            'Llama-4-Maverick-17B-128E-Instruct-FP8' => 'Llama 4 Maverick',
            'Llama-4-Scout-17B-16E-Instruct-FP8'     => 'Llama 4 Scout',
            'Llama-3.3-70B-Instruct'                 => 'Llama 3.3 70B',
        ];
    }
}
