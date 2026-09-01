<?php
namespace Ai\Agent\Tool;

/**
 * 工具执行上下文
 *
 * 工具在执行时不仅需要模型传入的参数（$input），还需要知道周围的环境：
 * 当前工作目录、会话 ID、Agent ID、迭代计数、取消状态、事件发射器等。
 * 这些信息在这里统一传递，而不是作为 handler 闭包的 use 变量。
 *
 * 由 AgentRuntime 在每次循环中构造，工具通过 ToolContext 获取运行时信息。
 */
class ToolContext
{
    /** @var string */
    protected $workdir = '';

    /** @var string */
    protected $sessionId = '';

    /** @var string */
    protected $agentId = '';

    /** @var string */
    protected $parentAgentId = '';

    /** @var callable|null */
    protected $emit = null;

    /** @var bool */
    protected $cancelled = false;

    /** @var int */
    protected $iteration = 0;

    /** @var string */
    protected $toolCallId = '';

    /**
     * @param array<string, mixed>|string $options 选项数组，或旧版「工作目录字符串」兼容写法
     * @param callable|null $oldEmit 旧版「事件发射器」参数（仅当 $options 为字符串时使用）
     */
    public function __construct($options = [], $oldEmit = null)
    {
        // 旧版写法：new ToolContext('/var/www', $emit)
        if (is_string($options)) {
            $this->workdir = $options;
            $this->emit = is_callable($oldEmit) ? $oldEmit : null;
            return;
        }
        $options = (array) $options;
        $this->workdir      = isset($options['workdir']) ? (string) $options['workdir'] : '';
        $this->sessionId    = isset($options['sessionId']) ? (string) $options['sessionId'] : '';
        $this->agentId      = isset($options['agentId']) ? (string) $options['agentId'] : '';
        $this->parentAgentId = isset($options['parentAgentId']) ? (string) $options['parentAgentId'] : '';
        $this->emit         = isset($options['emit']) && is_callable($options['emit']) ? $options['emit'] : null;
        $this->cancelled    = !empty($options['cancelled']);
        $this->iteration    = isset($options['iteration']) ? (int) $options['iteration'] : 0;
        $this->toolCallId   = isset($options['toolCallId']) ? (string) $options['toolCallId'] : '';
    }

    /** @return string */
    public function workdir() { return $this->workdir; }

    /** @return string */
    public function sessionId() { return $this->sessionId; }

    /** @return string */
    public function agentId() { return $this->agentId; }

    /** @return string */
    public function parentAgentId() { return $this->parentAgentId; }

    /** @return bool */
    public function isCancelled() { return $this->cancelled; }

    /** @return int */
    public function iteration() { return $this->iteration; }

    /** @return string */
    public function toolCallId() { return $this->toolCallId; }

    /** @return $this */
    public function cancel()
    {
        $this->cancelled = true;
        return $this;
    }

    /**
     * @param string $type
     * @param array<string, mixed> $data
     * @return void
     */
    public function emit($type, array $data = [])
    {
        if ($this->emit) {
            $event = array_merge(['type' => $type], $data);
            call_user_func($this->emit, $event);
        }
    }

    /**
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function log($message, array $context = [])
    {
        $this->emit('tool_log', ['message' => $message, 'context' => $context]);
    }
}