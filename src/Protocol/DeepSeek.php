<?php
namespace Ai\Protocol;

/**
 * DeepSeek 协议实现
 * DeepSeek 兼容 OpenAI Chat Completions 格式，仅端点和鉴权头不同
 */
class DeepSeek extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.deepseek.com';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        // 据官方文档核对（2026-08-12）：当前模型为 deepseek-v4-pro / deepseek-v4-flash。
        // deepseek-chat / deepseek-reasoner 是长期存在的别名，仍可用，一并保留——
        // 直接删掉会让照着旧文档写的代码突然从下拉框里找不到模型
        return [
            'deepseek-v4-pro'   => 'DeepSeek V4 Pro',
            'deepseek-v4-flash' => 'DeepSeek V4 Flash',
            'deepseek-chat'     => 'DeepSeek Chat（别名）',
            'deepseek-reasoner' => 'DeepSeek Reasoner（推理，别名）',
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

    /**
     * 本平台没有文本向量化接口（实测 404）
     *
     * 探测方法：带 Authorization 头 POST 真实路径与同前缀假路径作对照；
     * 该平台假路径返回 404（路由优先），故结果可判定。2026-08-12 实测。
     *
     * 此前是从 OpenAI 基线继承来的声明，与事实不符——业务层用
     * capabilities() 渲染功能开关时会点亮一个点下去就 404 的按钮。
     */
    public function embeddingPath(): string
    {
        return '';
    }
}
