<?php
namespace Ai\Contracts;

use Ai\Response\AudioResponse;

/**
 * WebSocket 实时语音通道的协议契约
 *
 * 与 ProtocolInterface **并列**，不继承——实时通道是可选能力，
 * 把这些方法塞进 ProtocolInterface 会让 40 个协议类全都要实现一遍。
 *
 * 用独立接口而不是鸭子类型（method_exists），是因为这里是**成套方法**：
 * 实现了 buildXxxFrames 却没实现 parseXxxFrames 的半残状态，
 * 靠逐个探测发现不了，等到运行到解析那一步才炸。instanceof 一次判定到位。
 * （对照：chat 链路的 parseStreamUsage 等钩子彼此独立、可单独实现，
 * 那种才适合 method_exists。）
 */
interface RealtimeProtocolInterface
{
    /**
     * 构造带鉴权签名的 WebSocket 连接地址
     *
     * 签名通常带时间戳且有效期很短，**每次连接都要重算**，不可缓存
     *
     * @param string               $capability 能力标识（tts / asr）
     * @param array<string, mixed> $config     运行时配置
     * @throws \Ai\Exceptions\RealtimeException 凭据缺失或该能力不支持
     */
    public function realtimeUrl(string $capability, array $config): string;

    /**
     * 组装语音合成的请求帧序列
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $config
     * @return array<int, string> 按顺序发送的帧原文
     */
    public function buildXfyunTtsFrames(string $text, array $options, array $config): array;

    /**
     * 组装语音识别的请求帧序列
     *
     * @param string               $audio 原始音频字节
     * @param array<string, mixed> $options
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    public function buildXfyunAsrFrames(string $audio, array $options, array $config): array;

    /**
     * 把收到的帧拼成语音合成结果
     * @param array<int, string> $payloads
     */
    public function parseXfyunTtsFrames(array $payloads): AudioResponse;

    /**
     * 把收到的帧拼成识别文本
     * @param array<int, string> $payloads
     */
    public function parseXfyunAsrFrames(array $payloads): AudioResponse;

    /**
     * 判断一帧是否是本次会话的结束帧
     *
     * 服务端分多帧下发，没有这个判定就不知道什么时候停，只能等超时
     */
    public function isXfyunFinalFrame(string $payload): bool;
}
