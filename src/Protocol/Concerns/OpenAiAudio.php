<?php
namespace Ai\Protocol\Concerns;

use Ai\Helpers\Capabilities;
use Ai\Helpers\Media;
use Ai\Response\AudioResponse;

/**
 * OpenAI 兼容格式的语音合成与识别（HTTP 通道）
 *
 * 据各平台官方文档核对（2026-08）：
 *
 *   TTS  POST .../audio/speech          请求 {model, input, voice, response_format, speed}
 *                                       响应**直接是音频字节**（Content-Type: audio/*）
 *   ASR  POST .../audio/transcriptions  multipart 上传 file，响应 JSON {text}
 *
 * OpenAI / 硅基流动 / 阶跃星辰都是这个形态。MiniMax 完全不同
 * （JSON 里塞 hex 编码音频），由 MiniMax 协议类自行覆写。
 *
 * **响应形态不需要协议提前声明**：传输层已经按响应的实际 Content-Type
 * 决定是原样带回字节还是 json_decode，这里只需按拿到的东西分支即可。
 */
trait OpenAiAudio
{
    /**
     * 语音合成接口路径
     */
    public function ttsPath(): string
    {
        return $this->siblingCapabilityPath('audio/speech');
    }

    /**
     * 语音识别接口路径
     */
    public function asrPath(): string
    {
        return $this->siblingCapabilityPath('audio/transcriptions');
    }

    /**
     * 默认音色
     *
     * OpenAI 的 voice 是**必填**参数（据官方 OpenAPI 规范），不给就是 400。
     * 各协议按自家文档给一个通用音色作缺省，让 speech('你好') 开箱可用；
     * 返回空串表示不注入，由调用方自己给。
     */
    public function defaultVoice(): string
    {
        return '';
    }

    /**
     * 本协议已登记的语音合成模型（据官方文档）
     * @return array<int, string>
     */
    public function knownTtsModels(): array
    {
        return [];
    }

    /**
     * 本协议已登记的语音识别模型（据官方文档）
     * @return array<int, string>
     */
    public function knownAsrModels(): array
    {
        return [];
    }

    /**
     * 本协议已登记的音色（据官方文档）
     * @return array<int, string>
     */
    public function knownVoices(): array
    {
        return [];
    }

    /**
     * 构建语音合成请求
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function buildTtsRequest(array $payload): array
    {
        // 库内统一用 format，OpenAI 系叫 response_format
        if (isset($payload['format']) && !isset($payload['response_format'])) {
            $payload['response_format'] = $payload['format'];
        }
        unset($payload['format']);

        // voice 必填，缺省时补一个，免得最常见的调用直接 400
        if (empty($payload['voice'])) {
            $default = $this->defaultVoice();
            if ($default !== '') {
                $payload['voice'] = $default;
            }
        }

        return $payload;
    }

    /**
     * 解析语音合成响应
     *
     * @param array<string, mixed> $response
     */
    public function parseTtsResponse(array $response): AudioResponse
    {
        // 传输层带回原始字节：这是正常路径
        if (isset($response['_raw']) && is_string($response['_raw'])) {
            $contentType = isset($response['_content_type']) ? (string) $response['_content_type'] : '';
            return new AudioResponse(
                Capabilities::TTS,
                $response['_raw'],
                '',
                Media::extensionOf($contentType),
                ['_content_type' => $contentType, '_bytes' => strlen($response['_raw'])],
                '',
                [],
                ''
            );
        }

        // 拿到的是 JSON —— 对本形态的平台而言，这只可能是错误响应。
        // **绝不能把这段 JSON 当音频存盘**：那会写出一堆扩展名是 .mp3、
        // 内容却是错误信息的文件，而且全程没有任何报错
        return new AudioResponse(
            Capabilities::TTS,
            '',
            '',
            '',
            $response,
            '',
            [],
            $this->extractAudioError($response) ?: '平台返回了 JSON 而不是音频数据，原始响应见 getRaw()'
        );
    }

    /**
     * 构建语音识别请求（multipart）
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function buildAsrRequest(array $payload): array
    {
        return $payload;
    }

    /**
     * 解析语音识别响应
     *
     * @param array<string, mixed> $response
     */
    public function parseAsrResponse(array $response): AudioResponse
    {
        $text = '';
        foreach (['text', 'result', 'transcript'] as $key) {
            if (isset($response[$key]) && is_string($response[$key])) {
                $text = $response[$key];
                break;
            }
        }

        // response_format=text 时平台回的是纯文本，被传输层 json_decode 后拿不到数组，
        // 这里兜住原始串
        if ($text === '' && isset($response['_raw']) && is_string($response['_raw'])) {
            $text = $response['_raw'];
        }

        $error = $this->extractAudioError($response);
        if ($error === '' && $text === '') {
            $error = '响应中没有解析到识别文本，原始响应见 getRaw()';
        }

        return new AudioResponse(
            Capabilities::ASR,
            '',
            $text,
            '',
            $response,
            isset($response['model']) ? (string) $response['model'] : '',
            isset($response['usage']) && is_array($response['usage']) ? $response['usage'] : [],
            $error
        );
    }

    /**
     * 从响应里抽错误信息，没有就返回空串
     *
     * @param array<string, mixed> $response
     */
    protected function extractAudioError(array $response): string
    {
        if (!isset($response['error'])) {
            return '';
        }
        if (is_array($response['error'])) {
            return isset($response['error']['message'])
                ? (string) $response['error']['message']
                : (string) json_encode($response['error'], JSON_UNESCAPED_UNICODE);
        }
        return (string) $response['error'];
    }
}
