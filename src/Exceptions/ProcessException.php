<?php
namespace Ai\Exceptions;

/**
 * 本地进程执行异常
 *
 * 用于 Claude Code CLI 等本地/远程程序调用失败的场景：
 * 可执行文件无法启动、执行超时、进程退出码非零等。
 */
class ProcessException extends AIException
{
}
