<?php
namespace Ai\Protocol;

/**
 * Fireworks AI（聚合，OpenAI 兼容）
 *
 * model 用 accounts/fireworks/models/xxx 的完整路径。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'accounts/fireworks/models/deepseek-v3',
 *     'protocol' => 'fireworks',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://docs.fireworks.ai/api-reference/post-chatcompletions
 */
class Fireworks extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.fireworks.ai/inference';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'accounts/fireworks/models/deepseek-v3'             => 'DeepSeek V3',
            'accounts/fireworks/models/llama-v3p3-70b-instruct' => 'Llama 3.3 70B',
            'accounts/fireworks/models/qwen3-235b-a22b'         => 'Qwen3 235B',
        ];
    }

    /**
     * 本平台没有图像生成接口
     *
     * 实测 2026-08-12：带 Authorization 头 POST 该路径返回 404，
     * 同前缀假路径同样 404、而对话路径返回 401，可确认此路由不存在。
     * 此前是从 OpenAI 基线继承来的声明，与事实不符。
     */
    public function imagePath(): string
    {
        return '';
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
