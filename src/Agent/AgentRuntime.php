<?php
namespace Ai\Agent;

use Ai\AI;
use Ai\Agent\Budget\BudgetManager;
use Ai\Agent\Loop\LoopController;
use Ai\Agent\Permission\PermissionManager;
use Ai\Agent\Session\SessionManager;
use Ai\Agent\Tool\ToolRegistry;

/**
 * Agent Runtime——执行引擎
 *
 * 组装所有组件（ToolRegistry、LoopController、PermissionManager、AgentContext），
 * 驱动 Agent 自循环。对外不直接暴露，由 Agent 类代理调用。
 *
 * 职责：
 *  - 管理工具注册表
 *  - 管理循环控制器
 *  - 管理权限系统
 *  - 组装 AgentContext
 *  - 调度 LoopController::run()
 *
 * 设计原则：运行时与业务层解耦，后续 Phase 可叠加
 * ContextManager、BudgetManager 等组件，
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

    /** @var PermissionManager|null */
    protected $permission = null;

    /** @var SessionManager|null */
    protected $sessionManager = null;

    /** @var string|null */
    protected $sessionId = null;

    /** @var BudgetManager|null */
    protected $budget = null;

    /** @var string[] */
    protected $fallbackModels = [];

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
     * 设置权限模式（快捷方式）
     *
     * @param string $mode manual|auto|plan|accept_edits|dont_ask|bypass
     * @return $this
     */
    public function setPermissionMode($mode)
    {
        if ($this->permission === null) {
            $this->permission = new PermissionManager($mode);
        } else {
            $this->permission->setMode($mode);
        }
        return $this;
    }

    /**
     * 设置权限管理器
     *
     * @param PermissionManager $pm
     * @return $this
     */
    public function setPermission($pm)
    {
        $this->permission = $pm;
        return $this;
    }

    /**
     * 设置会话管理器（启用持久化）
     *
     * @param SessionManager $sm
     * @return $this
     */
    public function setSessionManager($sm)
    {
        $this->sessionManager = $sm;
        return $this;
    }

    /**
     * 设置会话 ID
     *
     * @param string $id
     * @return $this
     */
    public function setSessionId($id)
    {
        $this->sessionId = (string) $id;
        return $this;
    }

    /**
     * 设置预算管理器
     *
     * @param BudgetManager $bm
     * @return $this
     */
    public function setBudget($bm)
    {
        $this->budget = $bm;
        return $this;
    }

    /**
     * 设置预算上限（快捷方式，价格按每百万 token 计）
     *
     * @param float $maxBudget 美元
     * @param array<string, mixed> $pricing ['prompt'=>, 'completion'=>, 'cached'=>]
     * @return $this
     */
    public function setMaxBudget($maxBudget, array $pricing = [])
    {
        $this->budget = new BudgetManager([
            'maxBudget'  => $maxBudget,
            'pricing'    => $pricing,
            'perMillion' => true,
        ]);
        return $this;
    }

    /**
     * 设置 Token 上限
     *
     * @param int $maxTokens
     * @return $this
     */
    public function setMaxTokens($maxTokens)
    {
        $this->budget = new BudgetManager([
            'maxTokens' => $maxTokens,
        ]);
        return $this;
    }

    /**
     * 设置降级模型（主模型服务级错误时自动切换）
     *
     * @param string[] $models 降级模型名，按优先级排列
     * @return $this
     */
    public function setFallbackModels(array $models)
    {
        $this->fallbackModels = $models;
        return $this;
    }

    /**
     * @return PermissionManager|null
     */
    public function getPermission()
    {
        return $this->permission;
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
        if ($this->permission) {
            $context->setPermission($this->permission);
        }
        if ($this->budget) {
            $context->setBudget($this->budget);
        }

        // 临时切换流式开关
        $streamWasOn = $this->ai->isStreaming();
        $this->ai->setStream($this->stream);

        try {
            $result = $this->loop->run($context);
            // 自动保存会话
            if ($this->sessionManager && $this->sessionId) {
                $this->sessionManager->save(
                    $this->sessionId,
                    $context->getMessages(),
                    $this->system,
                    ['result_text' => $result->getText(), 'stop_reason' => $result->getStopReason()]
                );
                if ($result->isDone()) {
                    $this->sessionManager->complete($this->sessionId);
                }
            }
            return $result;
        } catch (\Throwable $e) {
            if ($this->sessionManager && $this->sessionId) {
                $this->sessionManager->interrupt($this->sessionId);
            }
            throw $e;
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