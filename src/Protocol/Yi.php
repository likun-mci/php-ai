<?php
namespace Ai\Protocol;

/**
 * 零一万物 Yi（OpenAI 兼容）
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'yi-lightning',
 *     'protocol' => 'yi',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://platform.lingyiwanwu.com/docs
 */
class Yi extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.lingyiwanwu.com';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     */
    public function knownModels(): array
    {
        return [
            'yi-lightning' => 'Yi-Lightning',
            'yi-large'     => 'Yi-Large',
            'yi-medium'    => 'Yi-Medium',
            'yi-vision-v2' => 'Yi-Vision（视觉）',
        ];
    }
}
