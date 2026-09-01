<?php
namespace Ai\Agent\Tool;

/**
 * 工具执行上下文
 *
 * 工具在执行时不仅需要模型传入的参数（$input），还需要知道周围的环境：
 * 当前工作目录、取消状态、事件发射器等。这些信息在这里统一传递，
 * 而不是作为 handler 闭包的 use 变量。
 *
 * 用法：
 * ```php
 * class ReadFileTool implements AgentToolInterface
 * {
 *     public function execute(array $input, ToolContext $context): ToolResult
 *     {
 *         if ($context->isCancelled()) {
 *             return ToolResult::error('执行已取消');
 *         }
 *         $path = $context->workdir() . '/' . $input['path'];
 *         // ...
 *     }
 * }
 * ```
 */
class ToolContext
{
    /** @var string */
    protected $workdir = '';

    /** @var callable|null */
    protected $emit = null;

    /** @var bool */
    protected $cancelled = false;

    /**
     * @param string $workdir 当前工作目录
     * @param callable|null $emit 事件发射器
     */
    public function __construct($workdir = '', $emit = null)
    {
        $this->workdir = (string) $workdir;
        $this->emit = is_callable($emit) ? $emit : null;
    }

    /** 当前工作目录
     * @return string
     */
    public function workdir()
    {
        return $this->workdir;
    }

    /** 是否已取消（工具应尽快返回，避免继续执行）
     * @return bool
     */
    public function isCancelled()
    {
        return $this->cancelled;
    }

    /** 标记取消
     * @return $this
     */
    public function cancel()
    {
        $this->cancelled = true;
        return $this;
    }

    /** 发射事件
     * @param string $type 事件类型
     * @param array<string, mixed> $data 事件数据
     * @return void
     */
    public function emit($type, array $data = [])
    {
        if ($this->emit) {
            $event = array_merge(['type' => $type], $data);
            call_user_func($this->emit, $event);
        }
    }

    /** 日志输出（通过 emit 透传）
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function log($message, array $context = [])
    {
        $this->emit('tool_log', ['message' => $message, 'context' => $context]);
    }
}