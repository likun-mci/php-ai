<?php
namespace Ai\Protocol;

use Ai\Contracts\AIResponseInterface;
use Ai\Helpers\Capabilities;
use Ai\Response\AudioResponse;

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
     * 向量化接口路径
     *
     * MiniMax 的对话路径是 /v1/text/chatcompletion_v2，不是标准的
     * .../chat/completions 形态，同级推导拿不到结果，只能显式写出来
     */
    public function embeddingPath(): string
    {
        return '/v1/embeddings';
    }

    /**
     * 解析响应数据
     *
     * MiniMax 出错时仍返回 HTTP 200，错误信息放在 base_resp 里（status_code 非 0），
     * 不特殊处理会得到一个「成功但内容为空」的响应，这里统一抛成异常，
     * 与其它平台的错误处理方式保持一致。
     * @param array<string, mixed> $response
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
     * 从流式数据块中解析平台错误
     *
     * 与非流式一样，MiniMax 出错时 HTTP 状态码仍是 200，错误码在 base_resp 里。
     * @return string|null 该帧无错误时返回 null
     * @param array<string, mixed> $chunk
     */
    public function parseStreamError(array $chunk): ?string
    {
        $status = $chunk['base_resp']['status_code'] ?? 0;
        if ((int)$status !== 0) {
            return (string)($chunk['base_resp']['status_msg'] ?? 'MiniMax request failed');
        }
        return parent::parseStreamError($chunk);
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'MiniMax-M2'      => 'MiniMax M2',
            'MiniMax-Text-01' => 'MiniMax Text 01',
            'abab6.5s-chat'   => 'abab6.5s',
        ];
    }

    /**
     * MiniMax 语音合成接口路径
     *
     * 据官方文档（2026-08）：POST /v1/t2a_v2，与 OpenAI 的 audio/speech 完全不同，
     * 同级推导拿不到，只能显式写出。
     */
    public function ttsPath(): string
    {
        return '/v1/t2a_v2';
    }

    /**
     * MiniMax 的语音识别不是 OpenAI 兼容形态，本期不接入
     */
    public function asrPath(): string
    {
        return '';
    }

    /**
     * MiniMax 语音合成模型（据官方文档，2026-08）
     * @return array<int, string>
     */
    public function knownTtsModels(): array
    {
        return [
            'speech-2.8-hd', 'speech-2.8-turbo',
            'speech-2.6-hd', 'speech-2.6-turbo',
            'speech-02-hd', 'speech-02-turbo',
            'speech-01-hd', 'speech-01-turbo',
        ];
    }

    /**
     * 构建 MiniMax 语音合成请求
     *
     * 字段结构与 OpenAI 系差别很大（据官方文档，2026-08）：
     *   文本      text          （不是 input）
     *   音色/语速 voice_setting  {voice_id, speed[0.5-2], vol, pitch, emotion}
     *   音频参数  audio_setting  {format, sample_rate, bitrate}
     *
     * 这里把库内统一的 input / voice / speed / format 映射过去，
     * 让调用方在各平台间保持同一套写法；已经按 MiniMax 结构写好的
     * voice_setting / audio_setting 以调用方的为准，不覆盖。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function buildTtsRequest(array $payload): array
    {
        if (isset($payload['input'])) {
            $payload['text'] = $payload['input'];
            unset($payload['input']);
        }

        $voiceSetting = isset($payload['voice_setting']) && is_array($payload['voice_setting'])
            ? $payload['voice_setting']
            : [];
        if (!empty($payload['voice']) && !isset($voiceSetting['voice_id'])) {
            $voiceSetting['voice_id'] = (string) $payload['voice'];
        }
        if (isset($payload['speed']) && !isset($voiceSetting['speed'])) {
            $voiceSetting['speed'] = (float) $payload['speed'];
        }
        unset($payload['voice'], $payload['speed']);
        if ($voiceSetting) {
            $payload['voice_setting'] = $voiceSetting;
        }

        $audioSetting = isset($payload['audio_setting']) && is_array($payload['audio_setting'])
            ? $payload['audio_setting']
            : [];
        foreach (['format', 'response_format'] as $key) {
            if (!empty($payload[$key]) && !isset($audioSetting['format'])) {
                $audioSetting['format'] = (string) $payload[$key];
            }
            unset($payload[$key]);
        }
        if ($audioSetting) {
            $payload['audio_setting'] = $audioSetting;
        }

        return $payload;
    }

    /**
     * 解析 MiniMax 语音合成响应
     *
     * MiniMax 不直接回音频字节，而是回 JSON，音频放在 data.audio 里，
     * **采用 hex 编码**（不是 base64）。用 base64_decode 去解会得到一堆垃圾字节，
     * 且不会报任何错，只表现为「文件存下来了但放不出声」。
     *
     * 另外它的错误不体现在 HTTP 状态码上：base_resp.status_code 非 0 才是失败，
     * 此时 HTTP 仍是 200。不看这个字段就会把失败当成功。
     *
     * @param array<string, mixed> $response
     */
    public function parseTtsResponse(array $response): AudioResponse
    {
        $cap = Capabilities::TTS;

        // 平台级错误：HTTP 200 但 base_resp.status_code 非 0
        $code = isset($response['base_resp']['status_code']) ? (int) $response['base_resp']['status_code'] : 0;
        if ($code !== 0) {
            $msg = isset($response['base_resp']['status_msg'])
                ? (string) $response['base_resp']['status_msg']
                : ('MiniMax 返回错误码 ' . $code);
            return new AudioResponse($cap, '', '', '', $response, '', [], $msg);
        }

        $hex = isset($response['data']['audio']) && is_string($response['data']['audio'])
            ? $response['data']['audio']
            : '';
        if ($hex === '') {
            return new AudioResponse(
                $cap, '', '', '', $response, '', [],
                '响应中没有音频数据（data.audio 为空），原始响应见 getRaw()'
            );
        }

        $bytes = @hex2bin($hex);
        if ($bytes === false || $bytes === '') {
            return new AudioResponse(
                $cap, '', '', '', $response, '', [],
                'data.audio 不是合法的 hex 编码，无法还原音频（长度 ' . strlen($hex) . '）'
            );
        }

        $format = '';
        if (isset($response['extra_info']['audio_format']) && is_string($response['extra_info']['audio_format'])) {
            $format = $response['extra_info']['audio_format'];
        }

        return new AudioResponse(
            $cap,
            $bytes,
            '',
            $format,
            $response,
            '',
            isset($response['extra_info']) && is_array($response['extra_info']) ? $response['extra_info'] : [],
            ''
        );
    }

    use \Ai\Protocol\Concerns\AsyncVideoTask;

    /**
     * MiniMax 视频生成（异步任务式，**三段流程**）
     *
     * 据官方文档（2026-08）：
     *   1) 提交 POST {origin}/v1/video_generation           → task_id
     *   2) 查询 GET  {origin}/v1/query/video_generation?task_id=…
     *      status 取值 Preparing / Queueing / Processing / Success / Fail，
     *      成功时只给 **file_id**，没有下载地址
     *   3) 再取 GET  {origin}/v1/files/retrieve?file_id=…    → 下载地址（有效期 9 小时）
     *
     * 比其它三家多一步，靠 parseTaskStatus() 返回 result_url 触发，
     * 由 AsyncTask 去发第三次请求——协议层拿不到传输层，
     * 让它自己发请求会把两层职责搅在一起。
     */
    public function videoPath(): string
    {
        return '/v1/video_generation';
    }

    /**
     * @param array<string, mixed> $response
     * @return array{id: string, query_url: string}
     */
    public function parseTaskSubmit(string $capability, array $response, string $submitUrl = ''): array
    {
        $id = isset($response['task_id']) ? (string) $response['task_id'] : '';

        return [
            'id'        => $id,
            'query_url' => ($id !== '' && $submitUrl !== '')
                ? $this->taskSiblingUrl(
                    $submitUrl,
                    $this->videoPath(),
                    '/v1/query/video_generation?task_id=' . rawurlencode($id)
                )
                : '',
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array{status: string, error: string, result: \Ai\Contracts\CapabilityResponseInterface|null, result_url: string}
     */
    public function parseTaskStatus(string $capability, array $response, string $queryUrl = ''): array
    {
        $status = isset($response['status']) ? (string) $response['status'] : '';
        $error  = '';

        // MiniMax 的失败不体现在 HTTP 状态码上，base_resp.status_code 非 0 才是失败
        $code = (int) $this->dig($response, 'base_resp.status_code');
        if ($code !== 0) {
            return [
                'status'     => 'Fail',
                'error'      => $this->taskError($response) ?: ('MiniMax 返回错误码 ' . $code),
                'result'     => null,
                'result_url' => '',
            ];
        }

        $resultUrl = '';
        if (strtolower($status) === 'success') {
            $fileId = isset($response['file_id']) ? (string) $response['file_id'] : '';
            if ($fileId !== '') {
                // 第三步的地址由**查询地址**同级推导，而不是写死官方域名——
                // 用户配了自建网关时这一跳也得走同一个网关
                $base = $queryUrl !== '' ? $queryUrl : $this->defaultBaseUrl() . '/v1/query/video_generation';
                $pos  = strpos($base, '?');
                if ($pos !== false) {
                    $base = substr($base, 0, $pos);
                }
                $resultUrl = $this->taskSiblingUrl(
                    $base,
                    '/v1/query/video_generation',
                    '/v1/files/retrieve?file_id=' . rawurlencode($fileId)
                );
            }
        } elseif (strtolower($status) === 'fail') {
            $error = $this->taskError($response);
        }

        return ['status' => $status, 'error' => $error, 'result' => null, 'result_url' => $resultUrl];
    }

    /**
     * 第三步的响应：文件下载地址
     *
     * @param array<string, mixed> $response
     */
    public function parseTaskResult(string $capability, array $response): \Ai\Contracts\CapabilityResponseInterface
    {
        $url = (string) $this->dig($response, 'file.download_url');
        if ($url === '') {
            $url = (string) $this->dig($response, 'file.backup_download_url');
        }

        return new \Ai\Response\VideoResponse(
            $url,
            '',
            0.0,
            $response,
            '',
            [],
            $url === '' ? '取回文件时没有拿到 download_url，原始响应见 getRaw()' : ''
        );
    }
}
