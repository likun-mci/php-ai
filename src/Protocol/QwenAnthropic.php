<?php
namespace Ai\Protocol;

/**
 * 阿里云百炼（Anthropic 兼容，支持工具调用）
 *
 * 百炼的 Anthropic 兼容端点，用 Claude 协议通信，因此 Agent 工具调用可用。
 * 鉴权仍用百炼的 sk-xxx（平台键 qwen__api_key）。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'qwen3-max',
 *     'protocol' => 'qwen-anthropic',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://help.aliyun.com/zh/model-studio/claude-code
 */
class QwenAnthropic extends Claude
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://dashscope.aliyuncs.com/api/v2/apps/claude-code-proxy';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'qwen3-max'        => '通义千问 3 Max',
            'qwen3-coder-plus' => '通义千问 3 Coder Plus',
            'qwen-plus'        => '通义千问 Plus',
        ];
    }
}
