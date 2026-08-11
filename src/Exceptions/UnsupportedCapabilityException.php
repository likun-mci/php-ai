<?php
namespace Ai\Exceptions;

/**
 * 协议不支持所请求的扩展能力
 *
 * 例如拿一个纯对话协议去调 $ai->images()->generate()。
 *
 * 这个异常存在的意义是**绝不静默降级**：v1.12.0 时流式下的工具调用不被支持，
 * 当时的代码是静默返回空数组，用户拿到空结果完全不知道发生了什么，
 * 只能靠「怎么没生效」反推。所以这里宁可抛出，也要让调用方立刻知道原因。
 */
class UnsupportedCapabilityException extends AIException
{
}
