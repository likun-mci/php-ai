<?php
namespace Ai\Agent\Tool;

/**
 * 并行安全工具接口
 *
 * 标记一个工具是否可以在同一次模型响应中与其他工具并行执行。
 * 只读工具（read_file / grep / glob / http_fetch）是并行安全的；
 * 写操作（write_file / edit_file / bash）不是——它们共享文件系统或进程状态，
 * 并行执行会产生竞态。
 *
 * 用法：
 * ```php
 * class ReadFileTool implements AgentToolInterface, ParallelSafeToolInterface
 * {
 *     public function isParallelSafe() { return true; }
 * }
 * ```
 *
 * 不实现本接口的工具默认按「不可并行」处理（安全优先）。
 */
interface ParallelSafeToolInterface
{
    /** 是否可与其他工具并行执行
     * @return bool
     */
    public function isParallelSafe();
}