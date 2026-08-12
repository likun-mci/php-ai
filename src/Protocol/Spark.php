<?php
namespace Ai\Protocol;

/**
 * 讯飞星火（OpenAI 兼容）
 *
 * api_key 的格式是「APIKey:APISecret」（用英文冒号拼接），控制台可查。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => '4.0Ultra',
 *     'protocol' => 'spark',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://www.xfyun.cn/doc/spark/HTTP%E8%B0%83%E7%94%A8%E6%96%87%E6%A1%A3.html
 */
class Spark extends OpenAI implements \Ai\Contracts\RealtimeProtocolInterface
{
    use \Ai\Protocol\Concerns\XfyunRealtime;

    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://spark-api-open.xf-yun.com';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            '4.0Ultra'    => '星火 4.0 Ultra',
            'generalv3.5' => '星火 Max',
            'max-32k'     => '星火 Max 32K',
            'pro-128k'    => '星火 Pro 128K',
            'lite'        => '星火 Lite（免费）',
            'x1'          => '星火 X1（推理）',
        ];
    }

    /**
     * 讯飞的语音合成只提供 WebSocket，没有 HTTP 接口
     *
     * 返回空串让 $ai->audio()->speech() 给出「不支持」的明确报错，
     * 错误信息里会列出本协议支持的能力（含「实时通道」），把用户导向
     * $ai->realtime()->useWebSocket()->speech()。
     *
     * 比让请求打到一个不存在的 HTTP 路径、再拿一个含糊的 404 要好。
     */
    public function ttsPath(): string
    {
        return '';
    }

    /**
     * 同上，语音听写也只有 WebSocket 通道
     */
    public function asrPath(): string
    {
        return '';
    }
}
