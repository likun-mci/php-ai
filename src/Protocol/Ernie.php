<?php
namespace Ai\Protocol;

/**
 * 百度千帆 / 文心一言（OpenAI 兼容）
 *
 * api_key 用千帆的应用 API Key（bce-v3/ALTAK-xxx/xxx）或 IAM Bearer Token。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'ernie-5.0-turbo-32k',
 *     'protocol' => 'ernie',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://cloud.baidu.com/doc/qianfan-api/s/Fm2vrveyu
 */
class Ernie extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://qianfan.baidubce.com';
    }

    /**
     * 协议对话路径
     */
    public function chatPath(): string
    {
        return '/v2/chat/completions';
    }

    /**
     * 协议模型列表路径
     */
    public function modelsPath(): string
    {
        return '/v2/models';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'ernie-5.0-turbo-32k'  => '文心 5.0 Turbo 32K',
            'ernie-4.5-turbo-128k' => '文心 4.5 Turbo 128K',
            'ernie-4.0-turbo-8k'   => '文心 4.0 Turbo 8K',
            'ernie-3.5-8k'         => '文心 3.5 8K',
            'ernie-speed-128k'     => '文心 Speed 128K',
            'ernie-x1-turbo-32k'   => '文心 X1 Turbo（推理）',
        ];
    }
}
