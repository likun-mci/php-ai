<?php
namespace Ai\Protocol;

/**
 * 火山方舟 / 豆包（OpenAI 兼容）
 *
 * model 可传模型名（doubao-seed-1-6），也可传方舟推理接入点 ID（ep-xxxxxx）。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'doubao-seed-1-6',
 *     'protocol' => 'doubao',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://www.volcengine.com/docs/82379/1330626
 */
class Doubao extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://ark.cn-beijing.volces.com';
    }

    /**
     * 协议对话路径
     */
    public function chatPath(): string
    {
        return '/api/v3/chat/completions';
    }

    /**
     * 协议模型列表路径
     */
    public function modelsPath(): string
    {
        return '/api/v3/models';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'doubao-seed-1-6'          => '豆包 Seed 1.6',
            'doubao-seed-1-6-flash'    => '豆包 Seed 1.6 Flash',
            'doubao-seed-1-6-thinking' => '豆包 Seed 1.6 Thinking',
            'doubao-1-5-pro-32k'       => '豆包 1.5 Pro 32K',
            'doubao-1-5-lite-32k'      => '豆包 1.5 Lite 32K',
        ];
    }
}
