<?php
namespace Ai\Agent\Tool;

/**
 * 工具执行器
 *
 * 负责依次执行工具调用，并将结果统一为 ToolResult 格式。
 * 异常路径：工具 handler 抛出的任何 \Throwable 都被捕获并包装为
 * ToolResult::error()，不会穿透 Agent 循环。
 *
 * 用法：
 * ```php
 * $executor = new ToolExecutor($registry);
 * $context  = new ToolContext('/var/www', $emit);
 *
 * $result = $executor->execute($toolCall, $context);
 * $results = $executor->executeAll($toolCalls, $context);
 * ```
 */
class ToolExecutor
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
     * 执行单次工具调用
     *
     * @param array{id: string, name: string, input: array<string, mixed>} $toolCall
     * @param ToolContext $context
     * @return ToolResult
     */
    public function execute(array $toolCall, ToolContext $context)
    {
        $name  = isset($toolCall['name']) ? (string) $toolCall['name'] : '';
        $input = isset($toolCall['input']) && is_array($toolCall['input']) ? $toolCall['input'] : [];

        $tool = $this->registry->get($name);
        if ($tool === null) {
            return ToolResult::error('未知工具 ' . $name);
        }

        try {
            $result = $tool->execute($input, $context);
            // 确保返回值是 ToolResult
            if ($result instanceof ToolResult) {
                return $result;
            }
            return ToolResult::success((string) $result);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    /**
     * 批量执行工具调用（暂为串行，Phase 7 支持并行）
     *
     * @param array<int, array{id: string, name: string, input: array<string, mixed>}> $toolCalls
     * @param ToolContext $context
     * @return array<int, array{type: string, tool_use_id: string, content: string, is_error: bool}>
     */
    public function executeAll(array $toolCalls, ToolContext $context)
    {
        $results = [];
        foreach ($toolCalls as $call) {
            $result = $this->execute($call, $context);
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