<?php
namespace Ai\Protocol;

/**
 * 月之暗面 Kimi（Anthropic 兼容，支持工具调用）
 *
 * Kimi 的 Anthropic 兼容端点，用 Claude 协议通信，因此 Agent 工具调用可用。
 * 鉴权仍用 Kimi 的 API Key（平台键 moonshot__api_key）。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'kimi-k2-turbo-preview',
 *     'protocol' => 'moonshot-anthropic',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://platform.moonshot.cn/docs/guide/agent-support
 */
class MoonshotAnthropic extends Claude
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.moonshot.cn/anthropic';
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
        ];
    }
}
