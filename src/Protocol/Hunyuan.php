<?php
namespace Ai\Protocol;

/**
 * 腾讯混元（OpenAI 兼容）
 *
 * api_key 取混元控制台的 sk-xxx（不是腾讯云 SecretId/SecretKey）。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'hunyuan-turbos-latest',
 *     'protocol' => 'hunyuan',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://cloud.tencent.com/document/product/1729/111007
 */
class Hunyuan extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.hunyuan.cloud.tencent.com';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     */
    public function knownModels(): array
    {
        return [
            'hunyuan-turbos-latest' => '混元 TurboS',
            'hunyuan-t1-latest'     => '混元 T1（推理）',
            'hunyuan-large'         => '混元 Large',
            'hunyuan-standard'      => '混元 Standard',
            'hunyuan-lite'          => '混元 Lite（免费）',
            'hunyuan-vision'        => '混元 Vision',
        ];
    }
}
