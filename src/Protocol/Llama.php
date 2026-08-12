<?php
namespace Ai\Protocol;

/**
 * Meta Llama API（OpenAI 兼容）
 *
 * Meta 官方 API。llama 系列开源模型在 Groq / Together 等平台也有托管，
 * 模型名不参与协议自动推断，需显式指定 protocol 或 base_url。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'Llama-4-Maverick-17B-128E-Instruct-FP8',
 *     'protocol' => 'llama',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://llama.developer.meta.com/docs/
 */
class Llama extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.llama.com/compat';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'Llama-4-Maverick-17B-128E-Instruct-FP8' => 'Llama 4 Maverick',
            'Llama-4-Scout-17B-16E-Instruct-FP8'     => 'Llama 4 Scout',
            'Llama-3.3-70B-Instruct'                 => 'Llama 3.3 70B',
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
