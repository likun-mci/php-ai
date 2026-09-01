<?php
namespace Ai\Agent;

use Ai\AI;
use Ai\Agent\Budget\BudgetManager;
use Ai\Agent\Context\ContextManager;
use Ai\Agent\Hooks\AgentHooks;
use Ai\Agent\Permission\PermissionManager;
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

    /** @var PermissionManager|null */
    protected $permission = null;

    /** @var ContextManager|null */
    protected $contextManager = null;

    /** @var BudgetManager|null */
    protected $budget = null;

    /** @var string */
    protected $workdir = '';

    /** @var string */
    protected $sessionId = '';

    /** @var string */
    protected $agentId = '';

    /** @var int 事件计数器 */
    protected $eventCounter = 0;

    /** @var string|null 待授权的请求 ID */
    protected $pendingPermissionId = null;

    /** @var array<string, mixed>|null 待授权的工具调用 */
    protected $pendingPermissionCall = null;

    /** @var AgentHooks|null */
    protected $hooks = null;

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

    /* ---------- 预算管理 ---------- */

    /**
     * @param BudgetManager $bm
     * @return $this
     */
    public function setBudget($bm)
    {
        $this->budget = $bm;
        return $this;
    }

    /**
     * @return BudgetManager|null
     */
    public function getBudget()
    {
        return $this->budget;
    }

    /* ---------- 钩子系统 ---------- */

    /**
     * @param AgentHooks $hooks
     * @return $this
     */
    public function setHooks($hooks)
    {
        $this->hooks = $hooks;
        return $this;
    }

    /**
     * @return AgentHooks|null
     */
    public function getHooks()
    {
        return $this->hooks;
    }

    /* ---------- 上下文管理 ---------- */

    /**
     * @param ContextManager $cm
     * @return $this
     */
    public function setContextManager($cm)
    {
        $this->contextManager = $cm;
        return $this;
    }

    /**
     * @return ContextManager|null
     */
    public function getContextManager()
    {
        return $this->contextManager;
    }

    /* ---------- 权限管理 ---------- */

    /**
     * @param PermissionManager $pm
     * @return $this
     */
    public function setPermission($pm)
    {
        $this->permission = $pm;
        return $this;
    }

    /**
     * @return PermissionManager|null
     */
    public function getPermission()
    {
        return $this->permission;
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
     * 发射事件（自动附加统一字段：id / session_id / agent_id / turn_id / timestamp）
     *
     * 所有 Agent 事件都带上这些字段，便于 SSE / WebSocket 断线重连后
     * 按 last_event_id 继续接收，也便于前端按 session/turn 分组渲染。
     *
     * @param string $type
     * @param array<string, mixed> $data
     * @return void
     */
    public function emit($type, array $data = [])
    {
        if (!$this->emit) {
            return;
        }
        $event = array_merge($data, [
            'type'       => $type,
            'id'         => $this->nextEventId(),
            'session_id' => $this->sessionId,
            'agent_id'   => $this->agentId,
            'turn_id'    => 'turn_' . $this->iterations,
            'timestamp'  => microtime(true),
        ]);
        call_user_func($this->emit, $event);
    }

    /**
     * 生成自增事件 ID
     *
     * @return string
     */
    protected function nextEventId()
    {
        $this->eventCounter++;
        return 'evt_' . $this->eventCounter . '_' . dechex(time());
    }

    /* ---------- 运行时标识 ---------- */

    /**
     * @param string $workdir
     * @return $this
     */
    public function setWorkdir($workdir)
    {
        $this->workdir = (string) $workdir;
        return $this;
    }

    /** @return string */
    public function getWorkdir()
    {
        return $this->workdir;
    }

    /**
     * @param string $sessionId
     * @return $this
     */
    public function setSessionId($sessionId)
    {
        $this->sessionId = (string) $sessionId;
        return $this;
    }

    /** @return string */
    public function getSessionId()
    {
        return $this->sessionId;
    }

    /**
     * @param string $agentId
     * @return $this
     */
    public function setAgentId($agentId)
    {
        $this->agentId = (string) $agentId;
        return $this;
    }

    /** @return string */
    public function getAgentId()
    {
        return $this->agentId;
    }

    /* ---------- 待授权状态 ---------- */

    /**
     * @param string|null $requestId
     * @param array<string, mixed>|null $call
     * @return $this
     */
    public function setPendingPermission($requestId, $call = null)
    {
        $this->pendingPermissionId = $requestId ? (string) $requestId : null;
        $this->pendingPermissionCall = is_array($call) ? $call : null;
        return $this;
    }

    /** @return string|null */
    public function getPendingPermissionId()
    {
        return $this->pendingPermissionId;
    }

    /** @return array<string, mixed>|null */
    public function getPendingPermissionCall()
    {
        return $this->pendingPermissionCall;
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