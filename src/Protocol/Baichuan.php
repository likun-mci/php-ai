<?php
namespace Ai\Protocol;

/**
 * 百川智能（OpenAI 兼容）
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'Baichuan4-Turbo',
 *     'protocol' => 'baichuan',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://platform.baichuan-ai.com/docs/api
 */
class Baichuan extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.baichuan-ai.com';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     */
    public function knownModels(): array
    {
        return [
            'Baichuan4-Turbo'      => 'Baichuan4 Turbo',
            'Baichuan4-Air'        => 'Baichuan4 Air',
            'Baichuan4'            => 'Baichuan4',
            'Baichuan3-Turbo-128k' => 'Baichuan3 Turbo 128K',
        ];
    }
}
