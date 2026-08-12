<?php
namespace Ai\Facade;

use Ai\Contracts\RealtimeProtocolInterface;
use Ai\Exceptions\RealtimeException;
use Ai\Helpers\AIFile;
use Ai\Helpers\Capabilities;
use Ai\Realtime\WebSocketClient;
use Ai\Response\AudioResponse;

/**
 * 实时通道（WebSocket）
 *
 * 讯飞等平台的语音能力**只提供 WebSocket**，没有等价的 HTTP 接口。
 * 本门面把这类平台纳进来，让用户不必自己从零对接一套 WS。
 *
 * **默认关闭**：通道协议初值为 null，不显式启用就不会建立任何连接。
 * 这样设计是因为 WS 的行为与 HTTP 差别很大——长连接、需要手动关闭、
 * 超时语义不同、失败方式也不同（握手成功后仍可能因帧格式问题静默挂死），
 * 不该让用户在不知情的状态下切换到这条路径上。
 *
 * ```php
 * $ai = AI::create([
 *     'protocol' => 'spark',
 *     'app_id'   => '<APPID>',
 *     'api_key'  => '<APIKey>:<APISecret>',
 * ]);
 * $ai->realtime()->useWebSocket()->speech('你好世界')->saveTo('/tmp/a.mp3');
 * ```
 */
class RealtimeFacade extends BaseFacade
{
    /** @var string|null 通道协议：null 表示未指定 */
    protected $channel = null;
    /** @var array<string, mixed> WebSocket 连接参数 */
    protected $wsOptions = [];

    protected function capability(): string
    {
        return Capabilities::REALTIME;
    }

    /**
     * 启用 WebSocket 通道
     *
     * 必须显式调用。见类注释里「默认关闭」的理由。
     *
     * @param array<string, mixed> $options timeout / connect_timeout / max_frames / ssl_verify
     */
    public function useWebSocket(array $options = []): self
    {
        $this->channel   = 'websocket';
        $this->wsOptions = $options;
        return $this;
    }

    /**
     * 当前通道协议，未指定时为 null
     */
    public function getChannel(): ?string
    {
        return $this->channel;
    }

    /**
     * 确认通道已指定，否则给出可执行的提示而不是含糊的失败
     *
     * @throws RealtimeException
     */
    protected function requireChannel(): string
    {
        if ($this->channel === null) {
            throw new RealtimeException(
                '未指定实时通道协议。当前平台的语音能力只能通过 WebSocket 访问，'
                . '请显式调用 ->useWebSocket() 启用。'
                . '需要显式确认的原因：WebSocket 是长连接，超时与错误语义都与普通 HTTP 请求不同，'
                . '不应在你不知情时自动切换过去。',
                '',
                'realtime_channel_not_set',
                []
            );
        }
        return $this->channel;
    }

    /**
     * 语音合成（WebSocket 通道）
     *
     * @param array<string, mixed> $options voice / speed / volume / pitch / aue 等
     * @throws RealtimeException
     */
    public function speech(string $text, array $options = []): AudioResponse
    {
        $this->requireChannel();
        if (trim($text) === '') {
            throw new RealtimeException('待合成的文本为空', '', 'empty_text', []);
        }

        $protocol = $this->realtimeProtocol();

        $config = $this->ai->getConfig();
        $frames = $protocol->buildXfyunTtsFrames($text, $options, $config);
        $url    = $protocol->realtimeUrl(Capabilities::TTS, $config);

        $payloads = $this->exchange($url, $frames, $protocol);

        return $protocol->parseXfyunTtsFrames($payloads);
    }

    /**
     * 语音识别（WebSocket 通道）
     *
     * ⚠️ 讯飞听写要的是**裸 PCM**（16k / 16bit / 单声道）。传 .wav 时库会自动
     * 剥掉文件头取出 data 块；其它容器格式（mp3/m4a 等）请自行转码后再传，
     * 本库不做转码——那需要引入 ffmpeg 之类的外部依赖。
     *
     * @param string|AIFile        $file
     * @param array<string, mixed> $options language / accent / format / encoding 等
     * @throws RealtimeException
     */
    public function transcribe($file, array $options = []): AudioResponse
    {
        $this->requireChannel();

        $audio = $file instanceof AIFile ? $file : AIFile::fromPath((string) $file);
        if ($audio->getType() !== 'path') {
            throw new RealtimeException(
                '语音识别只接受本地文件；远端音频请先用 Ai\\Helpers\\Media::download() 取回并落盘',
                '',
                'asr_needs_local_file',
                []
            );
        }

        $bytes = @file_get_contents($audio->getSource());
        if ($bytes === false || $bytes === '') {
            throw new RealtimeException(
                '音频文件读取失败或内容为空：' . $audio->getSource(),
                '',
                'asr_file_unreadable',
                []
            );
        }
        $bytes = self::extractPcm($bytes);

        $protocol = $this->realtimeProtocol();

        $config = $this->ai->getConfig();
        $frames = $protocol->buildXfyunAsrFrames($bytes, $options, $config);
        $url    = $protocol->realtimeUrl(Capabilities::ASR, $config);

        $payloads = $this->exchange($url, $frames, $protocol, isset($options['frame_interval_us'])
            ? (int) $options['frame_interval_us']
            : 40000);

        return $protocol->parseXfyunAsrFrames($payloads);
    }

    /**
     * 连接 → 依次发帧 → 收到结束帧为止 → 关闭
     *
     * 连接一定要关：WebSocket 是长连接，异常路径上漏关会让 socket 一直挂着，
     * 常驻进程里跑几小时就耗尽文件描述符。所以走 try/finally。
     *
     * @param array<int, string>        $frames     待发送的帧
     * @param RealtimeProtocolInterface $protocol
     * @param int                       $intervalUs 帧间隔（微秒），语音听写需要按节奏送
     * @return array<int, string> 收到的帧原文
     * @throws RealtimeException
     */
    protected function exchange(string $url, array $frames, RealtimeProtocolInterface $protocol, int $intervalUs = 0): array
    {
        $client = new WebSocketClient($this->wsOptions);
        $client->connect($url);

        try {
            foreach ($frames as $i => $frame) {
                $client->sendText($frame);
                // 音频分片要按节奏送，一次灌完会被服务端判为异常流量
                if ($intervalUs > 0 && $i < count($frames) - 1) {
                    usleep($intervalUs);
                }
            }

            $messages = $client->receiveUntil(function (array $message) use ($protocol) {
                return $protocol->isXfyunFinalFrame($message['payload']);
            });
        } finally {
            $client->close();
        }

        $payloads = [];
        foreach ($messages as $message) {
            $payloads[] = $message['payload'];
        }
        return $payloads;
    }

    /**
     * 取协议实例并确认它实现了 WebSocket 语音通道
     *
     * 用 instanceof 而不是逐个 method_exists：这是一组必须成套实现的方法，
     * 「实现了一半」的状态靠逐个探测发现不了，要等运行到解析那步才炸。
     *
     * @throws RealtimeException
     */
    protected function realtimeProtocol(): RealtimeProtocolInterface
    {
        $protocol = $this->protocol();
        if (!$protocol instanceof RealtimeProtocolInterface) {
            throw new RealtimeException(
                '当前协议没有实现 WebSocket 语音通道。目前只有讯飞（protocol=spark）支持这条路径。',
                '',
                'realtime_protocol_unsupported',
                []
            );
        }
        return $protocol;
    }

    /**
     * 从 WAV 容器里取出裸 PCM 数据
     *
     * 讯飞听写要的是不带文件头的 PCM。直接把整个 .wav 灌进去，
     * 头部那 44 字节会被当成音频采样，表现为开头一小段噪音或识别结果异常，
     * **不会报错**，很难往文件格式上想。
     *
     * 不是 WAV 时原样返回。
     */
    public static function extractPcm(string $bytes): string
    {
        if (strlen($bytes) < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE') {
            return $bytes;
        }

        // 逐个 chunk 找 data 段：fmt 段长度并不固定，写死 44 字节偏移会在
        // 带扩展字段的 wav 上错位
        $offset = 12;
        $total  = strlen($bytes);
        while ($offset + 8 <= $total) {
            $id       = substr($bytes, $offset, 4);
            $unpacked = unpack('V', substr($bytes, $offset + 4, 4));
            if ($unpacked === false) {
                return $bytes;
            }
            $size = (int) $unpacked[1];
            $offset += 8;

            if ($id === 'data') {
                return substr($bytes, $offset, $size > 0 ? $size : null);
            }
            $offset += $size + ($size % 2);      // chunk 按偶数字节对齐
        }

        return $bytes;
    }
}
