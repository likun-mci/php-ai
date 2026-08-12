<?php
namespace Ai\Protocol\Concerns;

use Ai\Exceptions\RealtimeException;
use Ai\Helpers\Capabilities;
use Ai\Response\AudioResponse;

/**
 * 讯飞 WebSocket 语音（TTS 语音合成 / IAT 语音听写）
 *
 * 讯飞的语音能力**只提供 WebSocket**，没有等价的 HTTP 接口，
 * 且与库内其它平台有三处根本不同（据官方文档 2026-08）：
 *
 *   1) **鉴权不走请求头**，而是用 APIKey/APISecret 算 HMAC-SHA256 签名后
 *      拼进 URL 查询串。签名里含时间戳，有效期很短，**每次连接都要重算**，
 *      不能缓存 URL
 *   2) 需要 app_id，是 APIKey/APISecret 之外的第三个凭据
 *   3) 不是「发一次收一次」，服务端**分多帧下发**，直到 data.status == 2
 *
 * 因此这套逻辑无法复用 buildHeaders() / capabilityPath() 那条路，单独成 trait。
 *
 * @see https://www.xfyun.cn/doc/tts/online_tts/API.html
 * @see https://www.xfyun.cn/doc/asr/voicedictation/API.html
 */
trait XfyunRealtime
{
    /**
     * 声明支持实时通道能力
     *
     * 返回值只用于能力声明，真正的连接地址由 realtimeUrl() 现算——
     * 讯飞语音与对话不在同一个域名下，推导不出来。
     */
    public function realtimePath(): string
    {
        return '/v2/tts';
    }

    /**
     * 各动作的默认 wss 地址（据官方文档，2026-08）
     *
     * @return array<string, string>
     */
    public function realtimeEndpoints(): array
    {
        return [
            Capabilities::TTS => 'wss://tts-api.xfyun.cn/v2/tts',
            Capabilities::ASR => 'wss://iat-api.xfyun.cn/v2/iat',
        ];
    }

    /**
     * 讯飞已登记的合成音色（据官方文档，2026-08）
     *
     * 讯飞的音色远不止这些，且与账号开通情况有关，这里只列最常用的几个作缺省参考。
     *
     * @return array<int, string>
     */
    public function knownVoices(): array
    {
        return ['xiaoyan', 'aisjiuxu', 'aisxping', 'aisjinger', 'aisbabyxu'];
    }

    /**
     * 构造带签名的连接地址
     *
     * 签名规则（据官方文档）：
     *   signature_origin = "host: {host}\ndate: {date}\nGET {path} HTTP/1.1"
     *   signature        = base64(HMAC-SHA256(signature_origin, APISecret))
     *   authorization    = base64('api_key="..", algorithm="hmac-sha256",
     *                              headers="host date request-line", signature=".."')
     *   最终 URL 追加 authorization / date / host 三个查询参数
     *
     * @param string               $capability tts 或 asr
     * @param array<string, mixed> $config     运行时配置
     * @throws RealtimeException 凭据缺失
     */
    public function realtimeUrl(string $capability, array $config): string
    {
        $endpoints = $this->realtimeEndpoints();
        $base = isset($config[$capability . '_endpoint']) && is_string($config[$capability . '_endpoint'])
                && $config[$capability . '_endpoint'] !== ''
            ? $config[$capability . '_endpoint']
            : (isset($endpoints[$capability]) ? $endpoints[$capability] : '');

        if ($base === '') {
            throw new RealtimeException(
                sprintf('讯飞实时通道不支持「%s」能力', Capabilities::label($capability)),
                '',
                'xfyun_unsupported_action',
                []
            );
        }

        list($apiKey, $apiSecret) = $this->splitXfyunKey($config);

        $parts = parse_url($base);
        $host  = isset($parts['host']) ? $parts['host'] : '';
        $path  = isset($parts['path']) ? $parts['path'] : '/';
        if ($host === '') {
            throw new RealtimeException("无法解析讯飞地址：{$base}", '', 'xfyun_bad_endpoint', []);
        }

        // RFC1123 GMT。签名有时效，每次连接都要重算，缓存 URL 会导致鉴权失败
        $date = gmdate('D, d M Y H:i:s') . ' GMT';

        $signatureOrigin = "host: {$host}\ndate: {$date}\nGET {$path} HTTP/1.1";
        $signature       = base64_encode(hash_hmac('sha256', $signatureOrigin, $apiSecret, true));

        $authorizationOrigin = sprintf(
            'api_key="%s", algorithm="hmac-sha256", headers="host date request-line", signature="%s"',
            $apiKey,
            $signature
        );

        $query = http_build_query([
            'authorization' => base64_encode($authorizationOrigin),
            'date'          => $date,
            'host'          => $host,
        ]);

        return $base . (strpos($base, '?') === false ? '?' : '&') . $query;
    }

    /**
     * 从配置里取出 APIKey 与 APISecret
     *
     * 讯飞控制台给的是三件套 APPID / APIKey / APISecret。本库沿用 Spark 协议
     * 已有的约定：api_key 写成「APIKey:APISecret」，app_id 单独配。
     *
     * @param array<string, mixed> $config
     * @return array{0: string, 1: string}
     * @throws RealtimeException
     */
    protected function splitXfyunKey(array $config): array
    {
        $raw = isset($config['api_key']) ? trim((string) $config['api_key']) : '';

        $apiKey    = isset($config['xfyun_api_key']) ? trim((string) $config['xfyun_api_key']) : '';
        $apiSecret = isset($config['api_secret']) ? trim((string) $config['api_secret']) : '';

        if ($apiKey === '' || $apiSecret === '') {
            $pos = strpos($raw, ':');
            if ($pos !== false) {
                $apiKey    = $apiKey !== '' ? $apiKey : substr($raw, 0, $pos);
                $apiSecret = $apiSecret !== '' ? $apiSecret : substr($raw, $pos + 1);
            }
        }

        if ($apiKey === '' || $apiSecret === '') {
            throw new RealtimeException(
                '讯飞语音需要 APIKey 与 APISecret：把 api_key 写成「APIKey:APISecret」，'
                . '或分别用 xfyun_api_key / api_secret 两个配置项给出。'
                . '另外还需要 app_id（控制台的 APPID）。',
                '',
                'xfyun_credentials_missing',
                []
            );
        }

        return [$apiKey, $apiSecret];
    }

    /**
     * @param array<string, mixed> $config
     * @throws RealtimeException
     */
    protected function requireAppId(array $config): string
    {
        $appId = isset($config['app_id']) ? trim((string) $config['app_id']) : '';
        if ($appId === '') {
            throw new RealtimeException(
                '讯飞语音需要 app_id（控制台里的 APPID），请在配置中给出：'
                . "AI::create(['protocol' => 'spark', 'app_id' => '...', 'api_key' => 'APIKey:APISecret'])",
                '',
                'xfyun_app_id_missing',
                []
            );
        }
        return $appId;
    }

    /**
     * 组装语音合成的请求帧（一次发完）
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $config
     * @return array<int, string> 待依次发送的帧（JSON 串）
     * @throws RealtimeException
     */
    public function buildXfyunTtsFrames(string $text, array $options, array $config): array
    {
        $appId = $this->requireAppId($config);

        // aue=lame 直接产出 mp3；raw 是裸 PCM，多数场景还得自己封装才能播
        $business = [
            'aue'    => isset($options['aue']) ? $options['aue'] : 'lame',
            'sfl'    => 1,
            'auf'    => isset($options['auf']) ? $options['auf'] : 'audio/L16;rate=16000',
            'vcn'    => !empty($options['voice']) ? $options['voice'] : 'xiaoyan',
            'tte'    => 'UTF8',
            'speed'  => isset($options['speed']) ? (int) $options['speed'] : 50,
            'volume' => isset($options['volume']) ? (int) $options['volume'] : 50,
            'pitch'  => isset($options['pitch']) ? (int) $options['pitch'] : 50,
        ];
        // 调用方按讯飞字段名写的私有参数原样并入
        foreach (['bgs', 'reg', 'rdn', 'rhy'] as $key) {
            if (isset($options[$key])) {
                $business[$key] = $options[$key];
            }
        }

        $frame = [
            'common'   => ['app_id' => $appId],
            'business' => $business,
            'data'     => [
                'status' => 2,                       // 文本一次发完
                'text'   => base64_encode($text),
            ],
        ];

        return [(string) json_encode($frame, JSON_UNESCAPED_UNICODE)];
    }

    /**
     * 组装语音听写的请求帧序列
     *
     * 据官方文档：首帧 status=0，中间帧 status=1，末帧 status=2；
     * PCM 每帧 1280 字节，帧间隔建议 40ms，单次会话最长 60 秒。
     *
     * @param string               $audio   原始音频字节（PCM raw）
     * @param array<string, mixed> $options
     * @param array<string, mixed> $config
     * @return array<int, string>
     * @throws RealtimeException
     */
    public function buildXfyunAsrFrames(string $audio, array $options, array $config): array
    {
        $appId = $this->requireAppId($config);

        $business = [
            'language' => isset($options['language']) ? $options['language'] : 'zh_cn',
            'domain'   => isset($options['domain']) ? $options['domain'] : 'iat',
            'accent'   => isset($options['accent']) ? $options['accent'] : 'mandarin',
        ];
        foreach (['dwa', 'ptt', 'rlang', 'vinfo', 'nunum', 'vad_eos'] as $key) {
            if (isset($options[$key])) {
                $business[$key] = $options[$key];
            }
        }

        $format   = isset($options['format']) ? $options['format'] : 'audio/L16;rate=16000';
        $encoding = isset($options['encoding']) ? $options['encoding'] : 'raw';

        $chunkSize = isset($options['chunk_size']) ? (int) $options['chunk_size'] : 1280;
        if ($chunkSize <= 0) {
            $chunkSize = 1280;
        }
        $chunks = str_split($audio, $chunkSize);
        if (!$chunks) {
            $chunks = [''];
        }

        $frames = [];
        foreach ($chunks as $i => $chunk) {
            $data = [
                'status'   => $i === 0 ? 0 : 1,
                'format'   => $format,
                'encoding' => $encoding,
                'audio'    => base64_encode($chunk),
            ];
            $frame = $i === 0
                ? ['common' => ['app_id' => $appId], 'business' => $business, 'data' => $data]
                : ['data' => $data];
            $frames[] = (string) json_encode($frame, JSON_UNESCAPED_UNICODE);
        }

        // 末帧：必须显式告知结束，否则服务端会一直等下去
        $frames[] = (string) json_encode([
            'data' => ['status' => 2, 'format' => $format, 'encoding' => $encoding, 'audio' => ''],
        ], JSON_UNESCAPED_UNICODE);

        return $frames;
    }

    /**
     * 把收到的帧拼成语音合成结果
     *
     * @param array<int, string> $payloads 各帧的 JSON 原文
     */
    public function parseXfyunTtsFrames(array $payloads): AudioResponse
    {
        $audio = '';
        $raw   = [];
        $error = '';

        foreach ($payloads as $payload) {
            $frame = json_decode($payload, true);
            if (!is_array($frame)) {
                continue;
            }
            $raw[] = $frame;

            $code = isset($frame['code']) ? (int) $frame['code'] : 0;
            if ($code !== 0) {
                $error = sprintf(
                    '讯飞返回错误码 %d：%s',
                    $code,
                    isset($frame['message']) ? (string) $frame['message'] : ''
                );
                break;
            }
            if (!empty($frame['data']['audio']) && is_string($frame['data']['audio'])) {
                $decoded = base64_decode($frame['data']['audio'], true);
                if ($decoded !== false) {
                    $audio .= $decoded;
                }
            }
        }

        if ($error === '' && $audio === '') {
            $error = '未收到任何音频数据，原始帧见 getRaw()';
        }

        return new AudioResponse(
            Capabilities::TTS,
            $audio,
            '',
            'mp3',
            ['frames' => $raw],
            '',
            [],
            $error
        );
    }

    /**
     * 把收到的帧拼成识别文本
     *
     * 文本散落在 data.result.ws[].cw[].w 里，要逐层取出再顺序拼接
     *
     * @param array<int, string> $payloads
     */
    public function parseXfyunAsrFrames(array $payloads): AudioResponse
    {
        $text  = '';
        $raw   = [];
        $error = '';

        foreach ($payloads as $payload) {
            $frame = json_decode($payload, true);
            if (!is_array($frame)) {
                continue;
            }
            $raw[] = $frame;

            $code = isset($frame['code']) ? (int) $frame['code'] : 0;
            if ($code !== 0) {
                $error = sprintf(
                    '讯飞返回错误码 %d：%s',
                    $code,
                    isset($frame['message']) ? (string) $frame['message'] : ''
                );
                break;
            }

            if (!isset($frame['data']['result']['ws']) || !is_array($frame['data']['result']['ws'])) {
                continue;
            }
            foreach ($frame['data']['result']['ws'] as $ws) {
                if (!isset($ws['cw']) || !is_array($ws['cw'])) {
                    continue;
                }
                foreach ($ws['cw'] as $cw) {
                    if (isset($cw['w']) && is_string($cw['w'])) {
                        $text .= $cw['w'];
                    }
                }
            }
        }

        if ($error === '' && $text === '') {
            $error = '未识别出任何文本，原始帧见 getRaw()';
        }

        return new AudioResponse(
            Capabilities::ASR,
            '',
            $text,
            '',
            ['frames' => $raw],
            '',
            [],
            $error
        );
    }

    /**
     * 判断一帧是否是本次会话的结束帧（data.status == 2）
     */
    public function isXfyunFinalFrame(string $payload): bool
    {
        $frame = json_decode($payload, true);
        if (!is_array($frame)) {
            return false;
        }
        if (isset($frame['code']) && (int) $frame['code'] !== 0) {
            return true;                              // 出错也算结束，别再等了
        }
        return isset($frame['data']['status']) && (int) $frame['data']['status'] === 2;
    }
}
