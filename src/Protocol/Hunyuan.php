<?php
namespace Ai\Protocol;

/**
 * 腾讯混元（OpenAI 兼容）
 *
 * api_key 取混元控制台的 sk-xxx（不是腾讯云 SecretId/SecretKey）。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'hunyuan-turbos-latest',
 *     'protocol' => 'hunyuan',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://cloud.tencent.com/document/product/1729/111007
 */
class Hunyuan extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.hunyuan.cloud.tencent.com';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'hunyuan-turbos-latest' => '混元 TurboS',
            'hunyuan-t1-latest'     => '混元 T1（推理）',
            'hunyuan-large'         => '混元 Large',
            'hunyuan-standard'      => '混元 Standard',
            'hunyuan-lite'          => '混元 Lite（免费）',
            'hunyuan-vision'        => '混元 Vision',
        ];
    }

    /**
     * 本平台没有图像生成接口（实测 404）
     *
     * 探测方法：带 Authorization 头 POST 真实路径与同前缀假路径作对照；
     * 该平台假路径返回 404（路由优先），故结果可判定。2026-08-12 实测。
     *
     * 此前是从 OpenAI 基线继承来的声明，与事实不符——业务层用
     * capabilities() 渲染功能开关时会点亮一个点下去就 404 的按钮。
     */
    public function imagePath(): string
    {
        return '';
    }

    /**
     * 本平台没有图像编辑接口（实测 404）
     *
     * 探测方法：带 Authorization 头 POST 真实路径与同前缀假路径作对照；
     * 该平台假路径返回 404（路由优先），故结果可判定。2026-08-12 实测。
     *
     * 此前是从 OpenAI 基线继承来的声明，与事实不符——业务层用
     * capabilities() 渲染功能开关时会点亮一个点下去就 404 的按钮。
     */
    public function imageEditPath(): string
    {
        return '';
    }

    /**
     * 本平台没有语音合成接口（实测 404）
     *
     * 探测方法：带 Authorization 头 POST 真实路径与同前缀假路径作对照；
     * 该平台假路径返回 404（路由优先），故结果可判定。2026-08-12 实测。
     *
     * 此前是从 OpenAI 基线继承来的声明，与事实不符——业务层用
     * capabilities() 渲染功能开关时会点亮一个点下去就 404 的按钮。
     */
    public function ttsPath(): string
    {
        return '';
    }

    /**
     * 本平台没有语音识别接口（实测 404）
     *
     * 探测方法：带 Authorization 头 POST 真实路径与同前缀假路径作对照；
     * 该平台假路径返回 404（路由优先），故结果可判定。2026-08-12 实测。
     *
     * 此前是从 OpenAI 基线继承来的声明，与事实不符——业务层用
     * capabilities() 渲染功能开关时会点亮一个点下去就 404 的按钮。
     */
    public function asrPath(): string
    {
        return '';
    }
}
