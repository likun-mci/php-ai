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
     * 该端点不支持联网搜索
     *
     * 这是Kimi自建的 Anthropic 兼容网关，只是把 Anthropic 的**请求格式**翻译成
     * 自家模型的调用，Anthropic 那个 web_search 服务端工具是 Anthropic 自己的
     * 服务端能力，不会随协议格式一起过来。若不在这里显式收回，会从 Claude 基类
     * 继承到一个「支持」的错误声明，发出去的 tools 里带着平台不认识的
     * web_search_20250305，换来一次没必要的失败。
     *
     * 要在Kimi上用联网搜索，改用 `moonshot` 协议（OpenAI 兼容端点）。
     */
    public function supportsWebSearch(): bool
    {
        return false;
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
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
