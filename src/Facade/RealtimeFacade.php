<?php
namespace Ai\Facade;

use Ai\Exceptions\RealtimeException;
use Ai\Helpers\Capabilities;

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
 * $ai->realtime()->useWebSocket()->speech('你好世界')->saveTo('/tmp/a.mp3');
 * ```
 */
class RealtimeFacade extends BaseFacade
{
    /** @var string|null 通道协议：null 表示未指定 */
    protected $channel = null;

    protected function capability(): string
    {
        return Capabilities::REALTIME;
    }

    /**
     * 启用 WebSocket 通道
     *
     * 必须显式调用。见类注释里「默认关闭」的理由。
     */
    public function useWebSocket(): self
    {
        $this->channel = 'websocket';
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
     * @param array<string, mixed> $options
     * @throws RealtimeException
     */
    public function speech(string $text, array $options = []): \Ai\Response\AudioResponse
    {
        $this->requireChannel();
        throw new RealtimeException(
            'WebSocket 通道尚未实现（计划在阶段 4 交付）。当前可用的语音能力请走 $ai->audio()。',
            '',
            'realtime_not_implemented',
            []
        );
    }

    /**
     * 语音识别（WebSocket 通道）
     *
     * @param string|\Ai\Helpers\AIFile $file
     * @param array<string, mixed>      $options
     * @throws RealtimeException
     */
    public function transcribe($file, array $options = []): \Ai\Response\AudioResponse
    {
        $this->requireChannel();
        throw new RealtimeException(
            'WebSocket 通道尚未实现（计划在阶段 4 交付）。当前可用的语音能力请走 $ai->audio()。',
            '',
            'realtime_not_implemented',
            []
        );
    }
}
