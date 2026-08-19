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
     * 该端点不支持联网搜索
     *
     * 这是智谱自建的 Anthropic 兼容网关，只是把 Anthropic 的**请求格式**翻译成
     * 自家模型的调用，Anthropic 那个 web_search 服务端工具是 Anthropic 自己的
     * 服务端能力，不会随协议格式一起过来。若不在这里显式收回，会从 Claude 基类
     * 继承到一个「支持」的错误声明，发出去的 tools 里带着平台不认识的
     * web_search_20250305，换来一次没必要的失败。
     *
     * 要在智谱上用联网搜索，改用 `zhipu` 协议（OpenAI 兼容端点）。
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
            'glm-4.6'     => 'GLM-4.6',
            'glm-4.5'     => 'GLM-4.5',
            'glm-4.5-air' => 'GLM-4.5-Air',
        ];
    }
}
