<?php
namespace Ai\Protocol;

/**
 * 智谱 GLM（Anthropic 兼容，支持工具调用）
 *
 * 智谱的 Anthropic 兼容端点，用 Claude 协议通信，因此 Agent 工具调用可用。
 * 鉴权仍用智谱的 API Key（平台键 zhipu__api_key）。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'glm-4.6',
 *     'protocol' => 'zhipu-anthropic',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://docs.bigmodel.cn/cn/guide/develop/claude
 */
class ZhipuAnthropic extends Claude
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://open.bigmodel.cn/api/anthropic';
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
