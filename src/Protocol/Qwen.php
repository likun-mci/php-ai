<?php
namespace Ai\Protocol;

/**
 * 阿里云百炼 / 通义千问（OpenAI 兼容）
 *
 * api_key 取百炼控制台的 sk-xxx，国际站请把 base_url 换成
 * https://dashscope-intl.aliyuncs.com/compatible-mode。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'qwen3-max',
 *     'protocol' => 'qwen',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://help.aliyun.com/zh/model-studio/compatibility-of-openai-with-dashscope
 */
class Qwen extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://dashscope.aliyuncs.com/compatible-mode';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'qwen3-max'   => '通义千问 3 Max',
            'qwen-max'    => '通义千问 Max',
            'qwen-plus'   => '通义千问 Plus',
            'qwen-turbo'  => '通义千问 Turbo',
            'qwen-long'   => '通义千问 Long（长文档）',
            'qwq-plus'    => 'QwQ Plus（推理）',
            'qwen-vl-max' => '通义千问 VL Max（视觉）',
        ];
    }

    /**
     * 通义不提供 OpenAI 兼容格式的同步文生图
     *
     * 实测 2026-08：POST {dashscope}/compatible-mode/v1/images/generations 返回 404，
     * 而同前缀下的假路径也返回 404（该网关是路由优先），可以确认此路由确实不存在。
     *
     * 通义万相走的是原生**异步任务**接口
     * （/api/v1/services/aigc/text2image/image-synthesis，提交后轮询 task_id），
     * 形态与同步接口完全不同，安排在异步任务那一期实现。
     *
     * 返回空串即表示本协议不声明图像能力，调用方会得到明确报错而不是 404。
     */
    public function imagePath(): string
    {
        return '';
    }

    /**
     * 通义兼容模式不提供语音合成
     *
     * 实测 2026-08：POST {dashscope}/compatible-mode/v1/audio/speech 返回 404，
     * 同前缀假路径同样 404（该网关路由优先），可确认此路由不存在。
     * 通义的语音走 DashScope 原生接口，形态与 OpenAI 差异很大，暂不接入。
     */
    public function ttsPath(): string
    {
        return '';
    }

    /**
     * 同上，兼容模式亦无语音识别接口
     */
    public function asrPath(): string
    {
        return '';
    }
}
