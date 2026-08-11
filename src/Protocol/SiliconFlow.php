<?php
namespace Ai\Protocol;

/**
 * 硅基流动 SiliconCloud（聚合，OpenAI 兼容）
 *
 * 国内可直连的开源模型聚合平台，model 用「组织/模型」格式。
 * 海外站点请把 base_url 换成 https://api.siliconflow.com。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'deepseek-ai/DeepSeek-V3.2-Exp',
 *     'protocol' => 'siliconflow',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://docs.siliconflow.cn/cn/api-reference/chat-completions/chat-completions
 */
class SiliconFlow extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.siliconflow.cn';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'deepseek-ai/DeepSeek-V3.2-Exp' => 'DeepSeek V3.2',
            'deepseek-ai/DeepSeek-R1'       => 'DeepSeek R1',
            'Qwen/Qwen3-235B-A22B'          => 'Qwen3 235B',
            'moonshotai/Kimi-K2-Instruct'   => 'Kimi K2',
            'zai-org/GLM-4.6'               => 'GLM-4.6',
        ];
    }
}
