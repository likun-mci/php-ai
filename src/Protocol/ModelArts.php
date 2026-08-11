<?php
namespace Ai\Protocol;

/**
 * 华为云 ModelArts MaaS（OpenAI 兼容）
 *
 * 华为云 MaaS 按区域分域名，非华北区请用 base_url 覆盖，
 * 例如 https://api.modelarts-maas.com/v1 之外的区域端点。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'DeepSeek-V3',
 *     'protocol' => 'modelarts',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://support.huaweicloud.com/api-modelarts-maas/
 */
class ModelArts extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.modelarts-maas.com';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'DeepSeek-V3' => 'DeepSeek V3',
            'DeepSeek-R1' => 'DeepSeek R1',
            'Qwen2.5-72B' => 'Qwen2.5 72B',
        ];
    }
}
