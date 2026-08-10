<?php
namespace Ai\Protocol;

/**
 * Z.ai（智谱国际站，OpenAI 兼容）
 *
 * 智谱面向海外的站点，模型标识与国内站一致，Key 不通用。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'glm-4.6',
 *     'protocol' => 'zai',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://docs.z.ai/
 */
class ZAI extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.z.ai/api/paas';
    }

    /**
     * 协议对话路径
     */
    public function chatPath(): string
    {
        return '/v4/chat/completions';
    }

    /**
     * 协议模型列表路径
     */
    public function modelsPath(): string
    {
        return '/v4/models';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     */
    public function knownModels(): array
    {
        return [
            'glm-4.6'     => 'GLM-4.6',
            'glm-4.5'     => 'GLM-4.5',
            'glm-4.5-air' => 'GLM-4.5-Air',
        ];
    }
}
