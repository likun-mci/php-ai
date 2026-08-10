<?php
namespace Ai\Protocol;

use Ai\Contracts\AIResponseInterface;

/**
 * MiniMax 稀宇（OpenAI 兼容）
 *
 * 对话路径是 /v1/text/chatcompletion_v2，与标准 OpenAI 路径不同，库已内置。
 * 老域名 api.minimax.chat 同样可用，改 base_url 即可。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'MiniMax-M2',
 *     'protocol' => 'minimax',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://platform.minimaxi.com/document/ChatCompletion
 */
class MiniMax extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.minimaxi.com';
    }

    /**
     * 协议对话路径
     */
    public function chatPath(): string
    {
        return '/v1/text/chatcompletion_v2';
    }

    /**
     * 协议模型列表路径
     */
    public function modelsPath(): string
    {
        return '/v1/models';
    }

    /**
     * 解析响应数据
     *
     * MiniMax 出错时仍返回 HTTP 200，错误信息放在 base_resp 里（status_code 非 0），
     * 不特殊处理会得到一个「成功但内容为空」的响应，这里统一抛成异常，
     * 与其它平台的错误处理方式保持一致。
     */
    public function parseResponse(array $response): AIResponseInterface
    {
        $status = $response['base_resp']['status_code'] ?? 0;
        if ((int)$status !== 0) {
            throw new \Ai\Exceptions\RequestException(
                (string)($response['base_resp']['status_msg'] ?? 'MiniMax request failed'),
                'minimax',
                (string)$status,
                $response
            );
        }
        return parent::parseResponse($response);
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     */
    public function knownModels(): array
    {
        return [
            'MiniMax-M2'      => 'MiniMax M2',
            'MiniMax-Text-01' => 'MiniMax Text 01',
            'abab6.5s-chat'   => 'abab6.5s',
        ];
    }
}
