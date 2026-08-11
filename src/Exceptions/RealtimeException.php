<?php
namespace Ai\Exceptions;

/**
 * 实时通道（WebSocket）异常
 *
 * 覆盖握手失败、帧解析错误、连接被对端关闭、读超时等情况。
 * 与 RequestException 分开，是因为 WebSocket 的失败形态和 HTTP 完全不同：
 * HTTP 失败有状态码可依，WS 可能握手成功后才因帧格式问题静默挂死。
 */
class RealtimeException extends AIException
{
}
