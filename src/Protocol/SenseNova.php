<?php
namespace Ai\Protocol;

/**
 * 商汤日日新（OpenAI 兼容）
 *
 * 该平台没有公开的模型列表接口，listModels() 会回退到内置常用列表。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'SenseChat-5-1202',
 *     'protocol' => 'sensenova',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://console.sensecore.cn/help/docs/model-as-a-service/nova/
 */
class SenseNova extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.sensenova.cn/compatible-mode';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'SenseChat-5-1202' => '日日新 5.1',
            'SenseChat-5'      => '日日新 5.0',
            'SenseChat-Turbo'  => '日日新 Turbo',
            'SenseChat-Vision' => '日日新 Vision',
        ];
    }
}
