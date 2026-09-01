<?php
namespace Ai\Agent\Tool;

/**
 * 并行工具执行器
 *
 * 把一次模型响应中的多个工具调用，按「并行安全」分区后执行：
 *
 *   - 实现了 ParallelSafeToolInterface 且 isParallelSafe() === true 的工具
 *     （read_file / grep / glob / http_fetch…）会被收集到一个批次，可并行执行；
 *   - 其余工具（write_file / edit_file / bash…）按顺序逐个执行（它们共享
 *     文件系统或进程状态，并行会产生竞态）。
 *
 * PHP 单进程默认无法真正并行本地操作，因此本执行器提供 setParallelRunner() 注入点：
 * 在 Swoole / Workerman 协程环境注入一个 runner，批次内的工具就能真正并发；
 * 未注入时按顺序执行（语义正确，只是没有提速）。
 *
 * 用法：
 * ```php
 * $executor = new ParallelToolExecutor($registry);
 * $executor->setParallelRunner(function (array $tasks) {
 *     return \Swoole\Coroutine\parallel(array_map(fn($t) => $t, $tasks));
 * });
 * $results = $executor->executeAll($toolCalls, $context);
 * ```
 */
class ParallelToolExecutor
{
    /** @var ToolRegistry */
    protected $registry;

    /** @var callable|null 并行运行器（协程环境下注入） */
    protected $parallelRunner = null;

    /**
     * @param ToolRegistry $registry
     */
    public function __construct(ToolRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * 注入并行运行器
     *
     * @param callable|null $runner function(array $tasks): array
     * @return $this
     */
    public function setParallelRunner($runner)
    {
        $this->parallelRunner = is_callable($runner) ? $runner : null;
        return $this;
    }

    /**
     * 执行全部工具调用（并行安全的分批执行，其余顺序执行）
     *
     * @param array<int, array{id: string, name: string, input: array<string, mixed>}> $toolCalls
     * @param ToolContext $context
     * @return array<int, array{type: string, tool_use_id: string, content: string, is_error: bool}>
     */
    public function executeAll(array $toolCalls, ToolContext $context)
    {
        $executor = new ToolExecutor($this->registry);
        $results = $this->partitionAndExecute($toolCalls, $executor, $context);
        return $results;
    }

    /**
     * 工具分区执行
     *
     * @param array<int, array{id: string, name: string, input: array<string, mixed>}> $calls
     * @param ToolExecutor $executor
     * @param ToolContext $context
     * @return array<int, array{type: string, tool_use_id: string, content: string, is_error: bool}>
     */
    protected function partitionAndExecute(array $calls, ToolExecutor $executor, ToolContext $context)
    {
        $results = [];   // 存放最终结果
        $pending = [];   // 待并行执行的批次

        foreach ($calls as $call) {
            $name = isset($call['name']) ? (string) $call['name'] : '';
            $tool = $this->registry->get($name);

            if ($tool !== null && $this->isParallelSafe($tool)) {
                $pending[] = $call;
            } else {
                // 先执行完当前批次
                $results = $this->runPendingBatch($pending, $results, $context);
                $toolResult = $executor->execute($call, $context);
                $results[] = $this->formatResult($call, $toolResult);
            }
        }
        $results = $this->runPendingBatch($pending, $results, $context);
        return $results;
    }

    /**
     * 执行一批待并行工具，结果追加到 $results 末尾
     *
     * @param array<int, array{id: string, name: string, input: array<string, mixed>}> $pending
     * @param array<int, array{type: string, tool_use_id: string, content: string, is_error: bool}> $results
     * @param ToolContext $context
     * @return array<int, array{type: string, tool_use_id: string, content: string, is_error: bool}>
     */
    protected function runPendingBatch(array $pending, array $results, ToolContext $context)
    {
        if (!$pending) {
            return $results;
        }
        $executor = new ToolExecutor($this->registry);
        $batch = [];
        foreach ($pending as $call) {
            $batch[] = [
                'call' => $call,
                'run'  => function () use ($executor, $call, $context) {
                    return $executor->execute($call, $context);
                },
            ];
        }

        $batchResults = $this->runBatch($batch, $context);
        foreach ($batchResults as $k => $toolResult) {
            $results[] = $this->formatResult($batch[$k]['call'], $toolResult);
        }
        return $results;
    }

    /**
     * @param AgentToolInterface $tool
     * @return bool
     */
    protected function isParallelSafe($tool)
    {
        if ($tool instanceof ParallelSafeToolInterface) {
            return $tool->isParallelSafe();
        }
        return false;
    }

    /**
     * 执行一批并行任务
     *
     * @param array<int, array{call: array<string, mixed>, run: callable}> $batch
     * @param ToolContext $context
     * @return array<int, ToolResult>
     */
    protected function runBatch(array $batch, ToolContext $context)
    {
        $tasks = [];
        foreach ($batch as $item) {
            $tasks[] = $item['run'];
        }

        if ($this->parallelRunner) {
            try {
                $out = call_user_func($this->parallelRunner, $tasks);
                if (is_array($out) && count($out) === count($tasks)) {
                    return array_values($out);
                }
            } catch (\Throwable $e) {
            }
        }

        $results = [];
        foreach ($tasks as $task) {
            $results[] = $task();
        }
        return $results;
    }

    /**
     * @param array{id: string, name: string, input: array<string, mixed>} $call
     * @param ToolResult $result
     * @return array{type: string, tool_use_id: string, content: string, is_error: bool}
     */
    protected function formatResult(array $call, ToolResult $result)
    {
        return [
            'type'        => 'tool_result',
            'tool_use_id' => isset($call['id']) ? (string) $call['id'] : '',
            'content'     => (string) $result,
            'is_error'    => !$result->isSuccess(),
        ];
    }
}