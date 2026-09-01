<?php
namespace Ai\Agent;

use Ai\AI;
use Ai\Agent\Tool\ToolRegistry;

/**
 * Agent 运行时上下文
 *
 * 承载 Agent 运行过程中的全部状态：消息历史、迭代计数、lastText、
 * 工具注册表、事件发射器等。由 LoopController 在每次迭代中读取和更新。
 *
 * 设计上不包含业务逻辑，只是一个结构化的状态容器，
 * 后续 Phase 可在此基础上叠加 Context compaction、token 计数等。
 */
class AgentContext
{
    /** @var array<int, array<string, mixed>> */
    protected $messages = [];

    /** @var AI */
    protected $ai;

    /** @var string */
    protected $system = '';

    /** @var ToolRegistry */
    protected $toolRegistry;

    /** @var callable|null */
    protected $emit = null;

    /** @var string */
    protected $lastText = '';

    /** @var int */
    protected $iterations = 0;

    /** @var bool */
    protected $stopped = false;

    /**
     * @param AI $ai
     * @param ToolRegistry $toolRegistry
     * @param callable|null $emit
     */
    public function __construct(AI $ai, ToolRegistry $toolRegistry, $emit = null)
    {
        $this->ai = $ai;
        $this->toolRegistry = $toolRegistry;
        $this->emit = is_callable($emit) ? $emit : null;
    }

    /* ---------- 消息管理 ---------- */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMessages()
    {
        return $this->messages;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return $this
     */
    public function setMessages(array $messages)
    {
        $this->messages = $messages;
        return $this;
    }

    /**
     * 追加 assistant 回合（文本 + tool_use 块）
     *
     * @param \Ai\Contracts\AIResponseInterface $response
     * @return $this
     */
    public function appendAssistant($response)
    {
        $this->messages[] = $response->toAssistantMessage();
        return $this;
    }

    /**
     * 追加工具结果（作为 user 消息）
     *
     * @param array<int, array<string, mixed>> $results
     * @return $this
     */
    public function appendToolResults(array $results)
    {
        $this->messages[] = ['role' => 'user', 'content' => $results];
        return $this;
    }

    /* ---------- 系统提示词 ---------- */

    /**
     * @return string
     */
    public function getSystem()
    {
        return $this->system;
    }

    /**
     * @param string $system
     * @return $this
     */
    public function setSystem($system)
    {
        $this->system = (string) $system;
        return $this;
    }

    /* ---------- AI 实例 ---------- */

    /**
     * @return AI
     */
    public function getAI()
    {
        return $this->ai;
    }

    /* ---------- 工具注册表 ---------- */

    /**
     * @return ToolRegistry
     */
    public function getToolRegistry()
    {
        return $this->toolRegistry;
    }

    /**
     * 获取给 AI 模型的工具定义
     *
     * @return array<int, array<string, mixed>>
     */
    public function toolDefs()
    {
        return $this->toolRegistry->defs();
    }

    /* ---------- 事件发射 ---------- */

    /**
     * @return callable|null
     */
    public function getEmitter()
    {
        return $this->emit;
    }

    /**
     * @param string $type
     * @param array<string, mixed> $data
     * @return void
     */
    public function emit($type, array $data = [])
    {
        if ($this->emit) {
            $data['type'] = $type;
            call_user_func($this->emit, $data);
        }
    }

    /* ---------- 状态管理 ---------- */

    /**
     * @return string
     */
    public function getLastText()
    {
        return $this->lastText;
    }

    /**
     * @param string $text
     * @return $this
     */
    public function setLastText($text)
    {
        $this->lastText = (string) $text;
        return $this;
    }

    /**
     * @return int
     */
    public function getIteration()
    {
        return $this->iterations;
    }

    /**
     * @param int $n
     * @return $this
     */
    public function setIteration($n)
    {
        $this->iterations = (int) $n;
        return $this;
    }

    /**
     * @return bool
     */
    public function isStopped()
    {
        return $this->stopped;
    }

    /**
     * @param bool $stopped
     * @return $this
     */
    public function setStopped($stopped = true)
    {
        $this->stopped = (bool) $stopped;
        return $this;
    }
}