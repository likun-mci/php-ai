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

    // =================================================================
    // 图片生成（据官方文档核对，2026-08-12）
    //
    // 更正一处错误：v1.16.0 起 Spark 继承了 OpenAI 基线的
    // /v1/images/generations 与 /v1/images/edits 声明——这是错的。
    // 讯飞的图片生成**在另一个域名上**，形态也完全不同：
    //
    //   端点   https://maas-api.cn-huabei-1.xf-yun.com/v2.1/tti
    //          （Kolors 模型走 xingchen-api.cn-huabei-1.xf-yun.com/v2.1/tti）
    //   鉴权   HMAC-SHA256 签名拼进 URL，与 WebSocket 那套同规则（方法为 POST）
    //   请求   header / parameter.chat / payload.message.text 三段式
    //   响应   payload.choices.text[].content 里是 **base64 图片**，
    //          header.code 为 0 才算成功
    //
    // spark-api-open.xf-yun.com 是鉴权优先的网关（任何路径都回 401），
    // 探测给不出结论，所以这里以官方文档为准。
    // =================================================================

    /** 图片生成默认端点（大模型版） */
    const IMAGE_ENDPOINT = 'https://maas-api.cn-huabei-1.xf-yun.com/v2.1/tti';

    /**
     * 声明图片生成能力
     *
     * 返回值只用于能力声明；真实地址由 capabilityEndpoint() 现算——
     * 它与对话不在同一个域名下，推导不出来。
     */
    public function imagePath(): string
    {
        return '/v2.1/tti';
    }

    /**
     * 讯飞没有 OpenAI 形态的图像编辑端点
     *
     * 之前继承基线声明的 /v1/images/edits 是错的：讯飞的图片生成本身
     * 就不在那个域名上，编辑更谈不上。
     */
    public function imageEditPath(): string
    {
        return '';
    }

    /**
     * 讯飞图片生成的可用尺寸（据官方文档，2026-08-12）
     * @return array<int, string>
     */
    public function knownImageSizes(): array
    {
        return ['768x768', '1024x1024', '576x1024', '768x1024', '1024x576', '1024x768'];
    }

    /**
     * 图片生成的完整地址（带签名）
     *
     * @param array<string, mixed> $config
     */
    public function capabilityEndpoint(string $capability, string $chatEndpoint, array $config): string
    {
        if ($capability !== \Ai\Helpers\Capabilities::IMAGE) {
            return '';
        }

        $base = !empty($config['image_endpoint']) && is_string($config['image_endpoint'])
            ? $config['image_endpoint']
            : self::IMAGE_ENDPOINT;

        list($apiKey, $apiSecret) = $this->splitXfyunKey($config);
        return $this->xfyunSignedUrl($base, 'POST', $apiKey, $apiSecret);
    }

    /**
     * 构建图片生成请求
     *
     * 三段式结构与库内其它平台差别很大，逐字段映射。
     * 尺寸库内统一写 "1024x1024"，讯飞要拆成 width / height 两个整数。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function buildImageRequest(array $payload): array
    {
        $config = $this->configSnapshot();
        $appId  = isset($config['app_id']) ? trim((string) $config['app_id']) : '';
        if ($appId === '') {
            throw new \Ai\Exceptions\RequestException(
                '讯飞图片生成需要 app_id（控制台里的 APPID），请在配置中给出',
                '',
                'xfyun_app_id_missing',
                []
            );
        }

        $width  = 1024;
        $height = 1024;
        if (!empty($payload['size']) && is_string($payload['size'])) {
            $wh = $this->parseSize($payload['size']);
            if ($wh !== null) {
                $width  = $wh[0];
                $height = $wh[1];
            }
        }

        $chat = [
            'domain' => isset($payload['model']) && $payload['model'] !== '' ? (string) $payload['model'] : 'general',
            'width'  => $width,
            'height' => $height,
        ];
        foreach (['seed', 'num_inference_steps', 'guidance_scale', 'scheduler'] as $key) {
            if (isset($payload[$key])) {
                $chat[$key] = $payload[$key];
            }
        }

        $text = [['role' => 'user', 'content' => isset($payload['prompt']) ? (string) $payload['prompt'] : '']];
        if (!empty($payload['negative_prompt'])) {
            $text[] = ['role' => 'user', 'content' => (string) $payload['negative_prompt'], 'type' => 'negative_prompts'];
        }

        return [
            'header'    => ['app_id' => $appId],
            'parameter' => ['chat' => $chat],
            'payload'   => ['message' => ['text' => $text]],
        ];
    }

    /**
     * 解析图片生成响应
     *
     * 图片在 payload.choices.text[].content，是 base64；
     * header.code 非 0 即失败（此时 HTTP 往往仍是 200）。
     *
     * @param array<string, mixed> $response
     */
    public function parseImageResponse(array $response): \Ai\Response\ImageResponse
    {
        $code = isset($response['header']['code']) ? (int) $response['header']['code'] : 0;
        if ($code !== 0) {
            $msg = isset($response['header']['message']) ? (string) $response['header']['message'] : '';
            return new \Ai\Response\ImageResponse(
                [], [], $response, '', [], '',
                sprintf('讯飞返回错误码 %d%s', $code, $msg !== '' ? '：' . $msg : '')
            );
        }

        $base64 = [];
        $items  = isset($response['payload']['choices']['text']) ? $response['payload']['choices']['text'] : null;
        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_array($item) && !empty($item['content']) && is_string($item['content'])) {
                    $base64[] = $this->stripDataUri($item['content']);
                }
            }
        }

        return new \Ai\Response\ImageResponse(
            [], $base64, $response, '', [], '',
            $base64 ? '' : '响应中没有解析到图片（payload.choices.text 为空），原始响应见 getRaw()'
        );
    }

    /**
     * 取构建请求时可见的配置
     *
     * 协议层拿不到 AI 实例，但 setConfig() 会在请求前把配置注进来
     *
     * @return array<string, mixed>
     */
    protected function configSnapshot(): array
    {
        return is_array($this->config) ? $this->config : [];
    }
}
