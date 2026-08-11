<?php
namespace Ai\Protocol;

/**
 * 360 智脑（OpenAI 兼容）
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => '360gpt2-pro',
 *     'protocol' => 'zhinao',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://ai.360.com/open/docs/
 */
class Zhinao extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.360.cn';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            '360gpt2-pro'  => '360 智脑 2 Pro',
            '360gpt-pro'   => '360 智脑 Pro',
            '360gpt-turbo' => '360 智脑 Turbo',
        ];
    }
}
