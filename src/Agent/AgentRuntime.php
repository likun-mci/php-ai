<?php
namespace Ai\Agent;

use Ai\AI;
use Ai\Agent\Budget\BudgetManager;
use Ai\Agent\Context\ContextManager;
use Ai\Agent\Loop\LoopController;
use Ai\Agent\Loop\StopReason;
use Ai\Agent\Permission\PermissionManager;
use Ai\Agent\Session\SessionManager;
use Ai\Agent\SubAgent\SubAgentManager;
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

    /** @var ContextManager|null */
    protected $contextManager = null;

    /** @var string */
    protected $workdir = '';

    /** @var string */
    protected $agentId = '';

    /** @var SubAgentManager|null */
    protected $subAgentManager = null;

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
     * 设置上下文管理器
     *
     * @param ContextManager $cm
     * @return $this
     */
    public function setContextManager($cm)
    {
        $this->contextManager = $cm;
        return $this;
    }

    /**
     * 设置工作目录
     *
     * @param string $workdir
     * @return $this
     */
    public function setWorkdir($workdir)
    {
        $this->workdir = (string) $workdir;
        return $this;
    }

    /**
     * 启用并行工具执行
     *
     * @param bool $parallel
     * @return $this
     */
    public function setParallelTools($parallel = true)
    {
        $this->loop->setParallelTools($parallel);
        return $this;
    }

    /**
     * 注入并行运行器（Swoole/Workerman 协程环境用）
     *
     * @param callable|null $runner
     * @return $this
     */
    public function setParallelRunner($runner)
    {
        $this->loop->setParallelRunner($runner);
        return $this;
    }

    /**
     * 设置 Agent ID
     *
     * @param string $agentId
     * @return $this
     */
    public function setAgentId($agentId)
    {
        $this->agentId = (string) $agentId;
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
     * 设置子 Agent 管理器
     *
     * @param SubAgentManager $sam
     * @return $this
     */
    public function setSubAgentManager($sam)
    {
        $this->subAgentManager = $sam;
        if ($this->permission) {
            $sam->setParentPermission($this->permission);
        }
        if ($this->workdir) {
            $sam->setWorkdir($this->workdir);
        }
        return $this;
    }

    /**
     * 注册 spawn_agent 工具（如果子 Agent 存在）
     *
     * @return void
     */
    protected function registerSpawnAgent()
    {
        $sam = $this->subAgentManager;
        if ($sam === null) {
            return;
        }
        $agents = $sam->all();
        if (!$agents) {
            return;
        }
        // 只在 spawn_agent 尚未注册时追加
        if ($this->toolRegistry->has('spawn_agent')) {
            return;
        }
        $this->toolRegistry->register('spawn_agent', [
            'description'  => $sam->getToolSchema()['description'],
            'input_schema' => $sam->getToolSchema()['input_schema'],
            'handler'      => $sam->getHandler(),
        ]);
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
     * 批准权限请求并恢复 Agent 执行
     *
     * @param string $requestId
     * @param array<int, array<string, mixed>> $messages 当前上下文消息
     * @return AgentResult
     */
    public function approve($requestId, array $messages)
    {
        if (!$this->permission) {
            return AgentResult::stopped(StopReason::MODEL_ERROR, '', ['error' => '未配置权限管理器']);
        }
        if (!$this->permission->approve($requestId)) {
            return AgentResult::stopped(StopReason::MODEL_ERROR, '', ['error' => '权限请求不存在或已处理']);
        }
        $req = $this->permission->getRequest($requestId);
        if ($req === null) {
            return AgentResult::stopped(StopReason::MODEL_ERROR, '', ['error' => '权限请求不存在']);
        }
        // 构造工具结果并追加到消息，然后继续循环
        $toolResult = [
            'type'        => 'tool_result',
            'tool_use_id' => 'resume_' . $requestId,
            'content'     => 'Permission approved by user.',
            'is_error'    => false,
        ];
        $messages[] = ['role' => 'user', 'content' => [$toolResult]];
        return $this->loop->run($this->buildContext($messages));
    }

    /**
     * 拒绝权限请求并恢复 Agent 执行
     *
     * @param string $requestId
     * @param string $reason
     * @param array<int, array<string, mixed>> $messages
     * @return AgentResult
     */
    public function deny($requestId, $reason, array $messages)
    {
        if (!$this->permission) {
            return AgentResult::stopped(StopReason::MODEL_ERROR, '', ['error' => '未配置权限管理器']);
        }
        $this->permission->deny($requestId, $reason);
        // 追加拒绝结果
        $toolResult = [
            'type'        => 'tool_result',
            'tool_use_id' => 'deny_' . $requestId,
            'content'     => 'ERROR: Permission denied — ' . $reason,
            'is_error'    => true,
        ];
        $messages[] = ['role' => 'user', 'content' => [$toolResult]];
        return $this->loop->run($this->buildContext($messages));
    }

    /**
     * 构造 AgentContext（run/approve/deny 共用）
     *
     * @param array<int, array<string, mixed>> $messages
     * @return AgentContext
     */
    protected function buildContext(array $messages)
    {
        $context = new AgentContext($this->ai, $this->toolRegistry, $this->emit);
        $context->setMessages($messages);
        $context->setSystem($this->system);
        $context->setWorkdir($this->workdir);
        $context->setSessionId((string) $this->sessionId);
        $context->setAgentId($this->agentId);
        if ($this->permission) {
            $context->setPermission($this->permission);
        }
        if ($this->budget) {
            $context->setBudget($this->budget);
        }
        $cm = $this->contextManager;
        if ($cm === null) {
            $cm = new ContextManager($context->getMessages());
        } else {
            $cm->setMessages($context->getMessages());
        }
        $context->setContextManager($cm);
        return $context;
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
        $this->registerSpawnAgent();
        $context = $this->buildContext($messages);

        // 恢复会话状态（如果存在）
        $resumedStopReason = null;
        if ($this->sessionManager && $this->sessionId) {
            $session = $this->sessionManager->getStore()->load($this->sessionId);
            if ($session) {
                $context->setMessages($session->getMessages() ?: $messages);
                if ($session->getIteration() > 0) {
                    $context->setIteration($session->getIteration());
                }
                $resumedStopReason = $session->getStopReason();
                // 标记为 running
                $session->resume();
                $this->sessionManager->getStore()->save($session);
            }
        }

        // 临时切换流式开关
        $streamWasOn = $this->ai->isStreaming();
        $this->ai->setStream($this->stream);

        try {
            $result = $this->loop->run($context);
            // 自动保存会话（完整状态）
            if ($this->sessionManager && $this->sessionId) {
                $store = $this->sessionManager->getStore();
                $session = $store->load($this->sessionId);
                if ($session === null) {
                    $session = new \Ai\Agent\Session\AgentSession($this->sessionId, [
                        'messages' => $context->getMessages(),
                        'system'   => $this->system,
                        'model'    => $this->ai->model() ? $this->ai->model()->getName() : '',
                        'workdir'  => $this->workdir,
                    ]);
                }
                $session->setMessages($context->getMessages());
                $session->setIteration($context->getIteration());
                $session->setStopReason($result->getStopReason());
                if ($this->budget) {
                    $session->setBudgetState($this->budget->summary());
                }
                if ($result->isDone()) {
                    $session->complete();
                } elseif ($result->getStopReason() === \Ai\Agent\Loop\StopReason::PERMISSION_DENIED) {
                    $session->pause();
                    $permId = $result->getExtra()['request_id'] ?? '';
                    if ($permId) {
                        $session->setPendingPermissionId($permId);
                    }
                }
                $store->save($session);
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