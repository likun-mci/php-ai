<?php
namespace Ai\Protocol;

/**
 * 月之暗面 Kimi（OpenAI 兼容）
 *
 * 国际站请把 base_url 换成 https://api.moonshot.ai。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'kimi-k2-turbo-preview',
 *     'protocol' => 'moonshot',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://platform.moonshot.cn/docs/api/chat
 */
class Moonshot extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.moonshot.cn';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     */
    public function knownModels(): array
    {
        return [
            'kimi-k2-turbo-preview' => 'Kimi K2 Turbo',
            'kimi-k2-0905-preview'  => 'Kimi K2 0905',
            'kimi-latest'           => 'Kimi 最新版',
            'moonshot-v1-128k'      => 'Moonshot v1 128K',
            'moonshot-v1-32k'       => 'Moonshot v1 32K',
            'moonshot-v1-8k'        => 'Moonshot v1 8K',
        ];
    }
}
