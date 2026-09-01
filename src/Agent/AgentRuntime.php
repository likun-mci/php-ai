<?php
namespace Ai\Agent;

use Ai\AI;
use Ai\Agent\Loop\LoopController;
use Ai\Agent\Tool\ToolRegistry;

/**
 * Agent Runtime——执行引擎
 *
 * 组装所有组件（ToolRegistry、LoopController、AgentContext），
 * 驱动 Agent 自循环。对外不直接暴露，由 Agent 类代理调用。
 *
 * 职责：
 *  - 管理工具注册表
 *  - 管理循环控制器
 *  - 组装 AgentContext
 *  - 调度 LoopController::run()
 *
 * 设计原则：运行时与业务层解耦，后续 Phase 可叠加
 * PermissionManager、ContextManager、BudgetManager 等组件，
 * 只需在 AgentRuntime 里组装，不改变 Agent 的 public API。
 */
class AgentRuntime
{
    /** @var AI */
    protected $ai;

    /** @var ToolRegistry */
    protected $toolRegistry;

    /** @var LoopController */
    protected $loop;

    /** @var string */
    protected $system = '';

    /** @var callable|null */
    protected $emit = null;

    /** @var bool */
    protected $stream = false;

    /**
     * @param AI $ai
     */
    public function __construct(AI $ai)
    {
        $this->ai = $ai;
        $this->toolRegistry = new ToolRegistry();
        $this->loop = new LoopController();
    }

    /**
     * @param mixed $system
     * @return $this
     */
    public function setSystem($system)
    {
        $this->system = (string) $system;
        return $this;
    }

    /**
     * @param array<string, mixed> $tools
     * @return $this
     */
    public function setTools(array $tools)
    {
        $this->toolRegistry->clear()->registerAll($tools);
        return $this;
    }

    /**
     * @param int $n
     * @return $this
     */
    public function setMaxIter($n)
    {
        $this->loop->setMaxIter($n);
        return $this;
    }

    /**
     * @return $this
     */
    public function onEvent(callable $emit)
    {
        $this->emit = $emit;
        return $this;
    }

    /**
     * @param bool $stream
     * @return $this
     */
    public function setStream($stream = true)
    {
        $this->stream = (bool) $stream;
        return $this;
    }

    /**
     * 运行 Agent 循环
     *
     * @param array<int, array<string, mixed>> $messages 初始消息
     * @return AgentResult
     */
    public function run(array $messages)
    {
        // 构造上下文
        $context = new AgentContext($this->ai, $this->toolRegistry, $this->emit);
        $context->setMessages($messages);
        $context->setSystem($this->system);

        // 临时切换流式开关
        $streamWasOn = $this->ai->isStreaming();
        $this->ai->setStream($this->stream);

        try {
            $result = $this->loop->run($context);
            return $result;
        } finally {
            $this->ai->setStream($streamWasOn);
        }
    }

    /**
     * @return ToolRegistry
     */
    public function getToolRegistry()
    {
        return $this->toolRegistry;
    }

    /**
     * @return LoopController
     */
    public function getLoop()
    {
        return $this->loop;
    }
}