<?php
namespace Ai\Protocol;

/**
 * 阶跃星辰 Step（OpenAI 兼容）
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'step-2-16k',
 *     'protocol' => 'stepfun',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://platform.stepfun.com/docs/api-reference/chat
 */
class StepFun extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.stepfun.com';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     */
    public function knownModels(): array
    {
        return [
            'step-2-16k'  => 'Step-2 16K',
            'step-2-mini' => 'Step-2 Mini',
            'step-1-8k'   => 'Step-1 8K',
            'step-1v-8k'  => 'Step-1V 8K（视觉）',
        ];
    }
}
