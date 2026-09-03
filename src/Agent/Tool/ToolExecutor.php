<?php
namespace Ai\Agent\Tool;

use Ai\Helpers\Text;

/**
 * 工具执行器
 *
 * 负责依次执行工具调用，提供重试、超时与输出截断能力。
 *
 * 异常路径：工具 handler 抛出的任何 \Throwable 都被捕获并包装为
 * ToolResult::error()，不会穿透 Agent 循环。
 *
 * 重试策略（RetryPolicy）：
 *   网络超时 / HTTP 429 / HTTP 5xx → 自动重试
 *   参数错误 / 权限拒绝 / 文件不存在 / 用户拒绝 → 不重试
 *
 * 输出截断：超过 maxOutputBytes 时自动截断并附加提示。
 * 超时：超过 executionTimeout 秒时标记为超时（工具自行实现精确超时，如 BashTool 的 proc_terminate）。
 */
class ToolExecutor
{
    /** @var ToolRegistry */
    protected $registry;

    /** @var int 最大输出字节数（0 不限制） */
    protected $maxOutputBytes = 100000;

    /** @var int 执行超时秒数（0 不限制） */
    protected $executionTimeout = 0;

    /** @var int 最大重试次数 */
    protected $maxRetries = 2;

    /**
     * @param ToolRegistry $registry
     */
    public function __construct(ToolRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * 设置最大输出字节数
     *
     * @param int $bytes
     * @return $this
     */
    public function setMaxOutputBytes($bytes)
    {
        $this->maxOutputBytes = max(0, (int) $bytes);
        return $this;
    }

    /**
     * 设置执行超时秒数（0 不限制）
     *
     * @param int $seconds
     * @return $this
     */
    public function setExecutionTimeout($seconds)
    {
        $this->executionTimeout = max(0, (int) $seconds);
        return $this;
    }

    /**
     * 设置最大重试次数
     *
     * @param int $n
     * @return $this
     */
    public function setMaxRetries($n)
    {
        $this->maxRetries = max(0, (int) $n);
        return $this;
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

        // 执行（带重试）
        $result = $this->executeWithRetry($tool, $input, $context);

        // 输出截断
        if ($this->maxOutputBytes > 0) {
            $result = $this->truncateOutput($result);
        }

        return $result;
    }

    /**
     * 执行工具（带重试）
     *
     * executionTimeout 是整个执行过程（含重试）的墙钟上限：一旦超过期限，
     * 不再重试，并把结果标记为超时。
     *
     * @param AgentToolInterface $tool
     * @param array<string, mixed> $input
     * @param ToolContext $context
     * @return ToolResult
     */
    protected function executeWithRetry(AgentToolInterface $tool, array $input, ToolContext $context)
    {
        $maxAttempts = max(1, $this->maxRetries + 1);
        $result = ToolResult::error('执行失败，无有效尝试');

        // 整体执行期限（含重试等待）
        $deadline = $this->executionTimeout > 0 ? microtime(true) + $this->executionTimeout : null;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0) {
                // 重试等待：指数退避 + 抖动；若等待后会超过整体期限则直接放弃
                $delay = min(100000 * pow(2, $attempt - 1), 2000000); // 0.1s → 0.2s → 0.4s → 2s max
                if ($deadline !== null && microtime(true) + $delay / 1000000 > $deadline) {
                    return $this->asTimeoutResult($result, $attempt);
                }
                usleep($delay);
            }

            $startTime = microtime(true);
            try {
                $result = $tool->execute($input, $context);
            } catch (\Throwable $e) {
                $result = ToolResult::error($e->getMessage(), [
                    'exception' => get_class($e),
                ]);
            }

            if (!$result instanceof ToolResult) {
                $result = ToolResult::success((string) $result);
            }

            $duration = round((microtime(true) - $startTime) * 1000, 1);
            $metadata = $result->getMetadata();
            $metadata['duration_ms'] = $duration;
            $metadata['attempt'] = $attempt + 1;

            // 整体超时：即使刚执行完（成功）也视为超时，因为已超过期限
            if ($deadline !== null && microtime(true) > $deadline) {
                return $this->asTimeoutResult($result, $attempt);
            }

            // 重建带元数据的结果（保留原 display）
            $result = new ToolResult([
                'success'    => $result->isSuccess(),
                'content'    => $result->getContent(),
                'error'      => $result->getError(),
                'metadata'   => $metadata,
                'is_partial' => $result->isPartial(),
                'display'    => $result->getDisplay(),
            ]);

            // 成功 → 直接返回
            if ($result->isSuccess()) {
                return $result;
            }

            // 判断是否可重试
            if ($attempt < $maxAttempts - 1 && $this->isRetryable($result)) {
                continue;
            }

            return $result;
        }

        return $result;
    }

    /**
     * 把结果标记为超时
     *
     * @param ToolResult $result
     * @param int $attempt
     * @return ToolResult
     */
    protected function asTimeoutResult(ToolResult $result, $attempt)
    {
        $error = '工具执行超时（超过 ' . $this->executionTimeout . 's，尝试 ' . ($attempt + 1) . ' 次）';
        $content = $result->isSuccess() ? (string) $result->getContent() : $result->getError();
        if ($content !== '') {
            $error .= '，最后输出：' . Text::cutChars($content, 500);
        }
        return ToolResult::error($error, [
            'timeout' => true,
            'timeout_seconds' => $this->executionTimeout,
        ]);
    }

    /**
     * 判断错误是否可重试
     *
     * @param ToolResult $result
     * @return bool
     */
    protected function isRetryable(ToolResult $result)
    {
        $error = $result->getError();
        // 超时类错误
        if (strpos($error, 'timed out') !== false || strpos($error, 'timeout') !== false) {
            return true;
        }
        // 网络错误
        if (strpos($error, 'Connection') !== false || strpos($error, 'could not connect') !== false) {
            return true;
        }
        // HTTP 429 / 5xx
        if (preg_match('/\b(429|50[0-9])\b/', $error)) {
            return true;
        }
        // Shell 退出码指示重试
        if (preg_match('/exit code.*(139|143|255)/i', $error)) {
            return true;
        }
        return false;
    }

    /**
     * 截断输出
     *
     * @param ToolResult $result
     * @return ToolResult
     */
    protected function truncateOutput(ToolResult $result)
    {
        if ($this->maxOutputBytes <= 0) {
            return $result;
        }

        return $result->isSuccess()
            ? $this->truncateSuccess($result)
            : $this->truncateError($result);
    }

    /**
     * 截断成功输出——保留**头部**
     *
     * 读文件、列目录这类结果的开头就是要的东西，后面可以让模型用 offset/limit 再取。
     *
     * @param ToolResult $result
     * @return ToolResult
     */
    protected function truncateSuccess(ToolResult $result)
    {
        $content = $result->getContent();
        if (!is_string($content) || strlen($content) <= $this->maxOutputBytes) {
            return $result;
        }
        $omitted = strlen($content) - $this->maxOutputBytes;
        $truncated = Text::cutBytes($content, $this->maxOutputBytes)
            . "\n\n[Output truncated at " . $this->maxOutputBytes . " bytes, "
            . $omitted . " bytes omitted. Use offset/limit to read more.]";

        $metadata = $result->getMetadata();
        $metadata['truncated_bytes'] = $omitted;
        $metadata['original_bytes'] = strlen($content);

        return new ToolResult([
            'success'    => true,
            'content'    => $truncated,
            'metadata'   => $metadata,
            'is_partial' => true,
            'display'    => $result->getDisplay() . ' (truncated)',
        ]);
    }

    /**
     * 截断失败输出——保留**尾部**
     *
     * 失败结果原先被整段跳过不截，恰好截反了：最长的输出几乎总是失败输出
     * （PHPUnit 的失败详情、PHP 的 stack trace、依赖冲突的完整解算过程），
     * 一次几百 KB 直接灌进上下文，把后面几轮的空间全挤掉。
     *
     * 留尾不留头：结论（Fatal error、失败断言汇总、最终的冲突原因）在末尾，
     * 头部通常是无关的启动日志。模型要的是结论。
     *
     * 失败结果回填给模型的是 `'ERROR: ' . $error`（见 `ToolResult::__toString`），
     * 所以要截的是 `error` 而不是 `content`。
     *
     * @param ToolResult $result
     * @return ToolResult
     */
    protected function truncateError(ToolResult $result)
    {
        $error = $result->getError();
        if (!is_string($error) || strlen($error) <= $this->maxOutputBytes) {
            return $result;
        }
        $omitted = strlen($error) - $this->maxOutputBytes;
        $truncated = "[Error output truncated: leading " . $omitted
            . " bytes omitted, showing the last " . $this->maxOutputBytes . " bytes.]\n\n"
            . Text::cutBytesTail($error, $this->maxOutputBytes);

        $metadata = $result->getMetadata();
        $metadata['truncated_bytes'] = $omitted;
        $metadata['original_bytes'] = strlen($error);

        return new ToolResult([
            'success'    => false,
            'content'    => $result->getContent(),
            'error'      => $truncated,
            'metadata'   => $metadata,
            'is_partial' => true,
            'display'    => $result->getDisplay() . ' (truncated)',
        ]);
    }

    /**
     * 批量执行工具调用
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