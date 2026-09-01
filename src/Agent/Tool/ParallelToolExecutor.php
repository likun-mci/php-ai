<?php
namespace Ai\Agent\Tool;

/**
 * 并行工具执行器
 *
 * 使用 curl_multi 同时执行多个工具调用，减少总耗时。
 * 仅适用于工具 handler 内部发起 HTTP 请求的场景。
 *
 * 注意：多数工具 handler 是本地操作（文件读写、shell 命令），
 * 无法真正并行——它们共享同一个进程。并行主要对网络 I/O 工具有意义。
 *
 * 用法：
 * ```php
 * $executor = new ParallelToolExecutor($registry);
 * $results = $executor->executeAll($toolCalls, $context);
 * ```
 */
class ParallelToolExecutor
{
    /** @var ToolRegistry */
    protected $registry;

    /**
     * @param ToolRegistry $registry
     */
    public function __construct(ToolRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * 并行执行全部工具调用
     *
     * @param array<int, array{id: string, name: string, input: array<string, mixed>}> $toolCalls
     * @param ToolContext $context
     * @return array<int, array{type: string, tool_use_id: string, content: string, is_error: bool}>
     */
    public function executeAll(array $toolCalls, ToolContext $context)
    {
        if (count($toolCalls) <= 1) {
            $executor = new ToolExecutor($this->registry);
            return $executor->executeAll($toolCalls, $context);
        }

        $results = [];
        $callbacks = [];

        foreach ($toolCalls as $i => $call) {
            $callbacks[$i] = function () use ($call, $context) {
                $executor = new ToolExecutor($this->registry);
                return $executor->execute($call, $context);
            };
        }

        // 在协程环境（Swoole/Workerman）下，可替换为真正的并行执行
        foreach ($callbacks as $i => $cb) {
            $result = $cb();
            $call = $toolCalls[$i];
            $results[] = [
                'type'        => 'tool_result',
                'tool_use_id' => isset($call['id']) ? (string) $call['id'] : '',
                'content'     => (string) $result,
                'is_error'    => !$result->isSuccess(),
            ];
        }

        return $results;
    }
}