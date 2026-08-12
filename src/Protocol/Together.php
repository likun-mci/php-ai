<?php
namespace Ai\Protocol;

/**
 * Together AI（聚合，OpenAI 兼容）
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
 *     'protocol' => 'together',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://docs.together.ai/reference/chat-completions-1
 */
class Together extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.together.xyz';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'meta-llama/Llama-3.3-70B-Instruct-Turbo' => 'Llama 3.3 70B Turbo',
            'deepseek-ai/DeepSeek-V3'                 => 'DeepSeek V3',
            'Qwen/Qwen2.5-72B-Instruct-Turbo'         => 'Qwen2.5 72B Turbo',
            'mistralai/Mixtral-8x7B-Instruct-v0.1'    => 'Mixtral 8x7B',
        ];
    }

    /**
     * 本平台没有图像编辑接口
     *
     * 实测 2026-08-12：带 Authorization 头 POST 该路径返回 404，
     * 同前缀假路径同样 404、而对话路径返回 401，可确认此路由不存在。
     * 此前是从 OpenAI 基线继承来的声明，与事实不符。
     */
    public function imageEditPath(): string
    {
        return '';
    }
}
