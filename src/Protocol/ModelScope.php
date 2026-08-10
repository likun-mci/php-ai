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
}
