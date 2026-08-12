<?php
namespace Ai\Protocol;

/**
 * Cerebras（高速推理，OpenAI 兼容）
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'llama-3.3-70b',
 *     'protocol' => 'cerebras',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://inference-docs.cerebras.ai/api-reference/chat-completions
 */
class Cerebras extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.cerebras.ai';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'llama-3.3-70b' => 'Llama 3.3 70B',
            'llama3.1-8b'   => 'Llama 3.1 8B',
            'qwen-3-32b'    => 'Qwen3 32B',
            'gpt-oss-120b'  => 'GPT-OSS 120B',
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

    /**
     * 本平台没有语音合成接口
     *
     * 实测 2026-08-12：带 Authorization 头 POST 该路径返回 404，
     * 同前缀假路径同样 404、而对话路径返回 401，可确认此路由不存在。
     * 此前是从 OpenAI 基线继承来的声明，与事实不符。
     */
    public function ttsPath(): string
    {
        return '';
    }

    /**
     * 本平台没有语音识别接口
     *
     * 实测 2026-08-12：带 Authorization 头 POST 该路径返回 404，
     * 同前缀假路径同样 404、而对话路径返回 401，可确认此路由不存在。
     * 此前是从 OpenAI 基线继承来的声明，与事实不符。
     */
    public function asrPath(): string
    {
        return '';
    }

    /**
     * 本平台没有文本向量化接口
     *
     * 实测 2026-08-12：带 Authorization 头 POST 该路径返回 404，
     * 同前缀假路径同样 404、而对话路径返回 401，可确认此路由不存在。
     * 此前是从 OpenAI 基线继承来的声明，与事实不符。
     */
    public function embeddingPath(): string
    {
        return '';
    }
}
