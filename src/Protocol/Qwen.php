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
}
