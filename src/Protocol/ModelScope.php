<?php
namespace Ai\Protocol;

/**
 * 魔搭 ModelScope（聚合，OpenAI 兼容）
 *
 * 阿里魔搭社区的免费推理 API，model 用「组织/模型」格式。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'Qwen/Qwen3-235B-A22B',
 *     'protocol' => 'modelscope',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://modelscope.cn/docs/model-service/API-Inference/intro
 */
class ModelScope extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api-inference.modelscope.cn';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'Qwen/Qwen3-235B-A22B'        => 'Qwen3 235B',
            'deepseek-ai/DeepSeek-V3.1'   => 'DeepSeek V3.1',
            'ZhipuAI/GLM-4.5'             => 'GLM-4.5',
            'moonshotai/Kimi-K2-Instruct' => 'Kimi K2',
        ];
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
