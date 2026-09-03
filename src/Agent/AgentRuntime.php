<?php
namespace Ai\Agent;

use Ai\AI;
use Ai\Agent\Budget\BudgetManager;
use Ai\Agent\Context\ContextManager;
use Ai\Agent\Hooks\AgentHooks;
use Ai\Agent\Interaction\UserInteractionManager;
use Ai\Agent\Loop\LoopController;
use Ai\Agent\Loop\StopReason;
use Ai\Agent\Permission\PermissionManager;
use Ai\Agent\Session\SessionManager;
use Ai\Agent\SubAgent\SubAgentManager;
use Ai\Agent\Task\TaskManager;
use Ai\Agent\Task\TaskStatus;
use Ai\Agent\Tool\ToolRegistry;
use Ai\Agent\Verification\VerificationManager;
use Ai\Agent\Workspace\WorkspaceManager;
use Ai\Agent\Skill\SkillManager;
use Ai\Agent\Instruction\InstructionManager;
use Ai\Agent\Mcp\McpManager;
use Ai\Agent\Memory\MemoryManager;
use Ai\Agent\Planning\PlanManager;
use Ai\Agent\Reflection\ReflectionManager;
use Ai\Agent\Checkpoint\CheckpointManager;

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

    /** @var AgentHooks|null */
    protected $hooks = null;

    /** @var TaskManager|null */
    protected $taskManager = null;

    /** @var string|null */
    protected $taskId = null;

    /** @var UserInteractionManager|null */
    protected $interaction = null;

    /** @var VerificationManager|null */
    protected $verification = null;

    /** @var WorkspaceManager|null */
    protected $workspace = null;

    /** @var SkillManager|null */
    protected $skillManager = null;

    /** @var InstructionManager|null */
    protected $instruction = null;

    /** @var McpManager|null */
    protected $mcpManager = null;

    /** @var MemoryManager|null */
    protected $memoryManager = null;

    /** @var \Ai\Agent\Loop\CancellationToken|null 取消令牌 */
    protected $cancellation = null;

    /** @var \Ai\Agent\Tool\ToolDiscovery|null 工具发现（渐进披露） */
    protected $toolDiscovery = null;

    /** @var PlanManager|null */
    protected $planManager = null;

    /** @var string 当前执行计划 ID */
    protected $planId = '';

    /** @var ReflectionManager|null */
    protected $reflection = null;

    /** @var string 当前任务目标 */
    protected $goal = '';

    /** @var \Ai\Agent\Checkpoint\Checkpoint|null 最近一次 recover() 加载的检查点 */
    protected $lastCheckpoint = null;

    /** @var \Ai\Agent\Approval\ApprovalWorkflow|null 人工审批工作流 */
    protected $approvalWorkflow = null;

    /** @var CheckpointManager|null */
    protected $checkpointManager = null;

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
     * 当前系统提示词
     *
     * @return string
     */
    public function getSystem()
    {
        return $this->system;
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
        // 工具集变了要同步给子 Agent 管理器——它按父工具集求交决定子 Agent 能用什么，
        // 顺序上先设管理器后设工具是常见写法，这里补上就不必要求调用方记顺序
        if ($this->subAgentManager !== null) {
            $this->subAgentManager->setParentTools($this->toolRegistry->all());
        }
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
     * 当前权限管理器
     *
     * @return \Ai\Agent\Permission\PermissionManager|null
     */
    public function getPermission()
    {
        return $this->permission;
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
     * 当前 AI 实例
     *
     * 编排层需要它来创建子 Agent 运行时；业务代码一般用不到。
     *
     * @return AI
     */
    public function getAI()
    {
        return $this->ai;
    }

    /**
     * 当前工作目录
     *
     * @return string
     */
    public function getWorkdir()
    {
        return $this->workdir;
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
        // 自动创建 WorkspaceManager（如果尚未设置，且 workdir 非空）
        if ($this->workdir !== '' && $this->workspace === null) {
            $this->workspace = new WorkspaceManager($this->workdir);
        }
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
        // 装配顺序不该由调用方记：先设管理器后设预算是常见写法，这里补上同步
        if ($this->subAgentManager !== null) {
            $this->subAgentManager->setParentBudget($bm);
        }
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
        // 把父 Agent 的工具、技能、MCP 交给它——子 Agent 的能力从这里面收窄而来，
        // 不给的话 resolveTools() 就没有可求交的基准，权限约束会形同虚设
        $sam->setParentTools($this->toolRegistry->all());
        $sam->setParentSkills($this->skillManager);
        $sam->setParentMcp($this->mcpManager);
        // 预算与取消同样往下传：子 Agent 花的是父 Agent 的钱和时间，
        // 叫停父 Agent 也该把它派出去的活儿一起停掉
        $sam->setParentBudget($this->budget);
        $sam->setParentCancellation($this->cancellation);
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
     * 注册 ask_user 工具（如果用户交互管理器存在）
     *
     * @return void
     */
    protected function registerInteraction()
    {
        $uim = $this->interaction;
        if ($uim === null) {
            return;
        }
        // 只在 ask_user 尚未注册时追加
        if ($this->toolRegistry->has('ask_user')) {
            return;
        }
        $schema = $uim->getToolSchema();
        $this->toolRegistry->register('ask_user', [
            'description'  => $schema['description'],
            'input_schema' => $schema['input_schema'],
            'handler'      => $uim->getHandler(),
        ]);
    }

    /**
     * 注册 use_skill 工具（如果技能管理器存在）
     *
     * @return void
     */
    protected function registerSkillTool()
    {
        $sm = $this->skillManager;
        if ($sm === null || !$sm->isEnabled() || $sm->count() === 0) {
            return;
        }
        if ($this->toolRegistry->has('use_skill')) {
            return;
        }
        $schema = $sm->getUseSkillToolSchema();
        $this->toolRegistry->register('use_skill', [
            'description'  => $schema['description'],
            'input_schema' => $schema['input_schema'],
            'handler'      => $sm->getUseSkillHandler(),
        ]);
    }

    /**
     * 注册 MCP 工具（如果 MCP 管理器存在）
     *
     * @return void
     */
    protected function registerMcpTools()
    {
        $mm = $this->mcpManager;
        if ($mm === null) {
            return;
        }
        try {
            $adapters = $mm->getToolAdapters();
            foreach ($adapters as $name => $adapter) {
                if (!$this->toolRegistry->has($name)) {
                    $this->toolRegistry->register($adapter);
                }
            }
        } catch (\Throwable $e) {
            // 单个 MCP 服务器失败不影响 Agent 启动
        }
    }

    /**
     * 设置工具执行超时秒数（0 不限制）
     *
     * 超过此期限（含重试等待）仍未返回的工具将被标记为超时。
     * 超时工具的结果不再重试，直接返回给模型。
     *
     * @param int $seconds
     * @return $this
     */
    public function setToolTimeout($seconds)
    {
        $this->loop->setToolTimeout($seconds);
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
        $this->loop->setFallbackModels($models);
        return $this;
    }

    /**
     * 设置任务管理器
     *
     * @param TaskManager|null $tm
     * @return $this
     */
    public function setTaskManager($tm)
    {
        $this->taskManager = $tm;
        return $this;
    }

    /**
     * @return TaskManager|null
     */
    public function getTaskManager()
    {
        return $this->taskManager;
    }

    /**
     * 设置任务 ID（关联当前执行的任务）
     *
     * @param string|null $taskId
     * @return $this
     */
    public function setTaskId($taskId)
    {
        $this->taskId = $taskId ? (string) $taskId : null;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getTaskId()
    {
        return $this->taskId;
    }

    /**
     * 设置用户交互管理器
     *
     * @param UserInteractionManager|null $uim
     * @return $this
     */
    public function setUserInteractionManager($uim)
    {
        $this->interaction = $uim;
        return $this;
    }

    /**
     * @return UserInteractionManager|null
     */
    public function getUserInteractionManager()
    {
        return $this->interaction;
    }

    /**
     * 子 Agent 管理器
     *
     * @return SubAgentManager|null
     */
    public function getSubAgentManager()
    {
        return $this->subAgentManager;
    }

    /**
     * 设置人工审批工作流
     *
     * @param \Ai\Agent\Approval\ApprovalWorkflow|null $workflow
     * @return $this
     */
    public function setApprovalWorkflow($workflow)
    {
        $this->approvalWorkflow = $workflow;
        return $this;
    }

    /**
     * @return \Ai\Agent\Approval\ApprovalWorkflow|null
     */
    public function getApprovalWorkflow()
    {
        return $this->approvalWorkflow;
    }

    /**
     * 设置执行计划管理器
     *
     * 设置后，当前计划的摘要会在每轮迭代注入系统提示词，让模型知道
     * 整体步骤走到哪一步了。
     *
     * @param PlanManager|null $pm
     * @return $this
     */
    public function setPlanManager($pm)
    {
        $this->planManager = $pm;
        return $this;
    }

    /**
     * @return PlanManager|null
     */
    public function getPlanManager()
    {
        return $this->planManager;
    }

    /**
     * 指定当前执行的计划 ID
     *
     * @param string $planId
     * @return $this
     */
    public function setPlanId($planId)
    {
        $this->planId = (string) $planId;
        return $this;
    }

    /**
     * @return string
     */
    public function getPlanId()
    {
        return $this->planId;
    }

    /**
     * 设置反思管理器
     *
     * 设置后，模型在没有工具调用、准备结束时会先反思一次目标是否真的达成，
     * 未达成则把下一步建议回填给模型继续迭代。未设置时循环行为不变。
     *
     * @param ReflectionManager|null $rm
     * @return $this
     */
    public function setReflectionManager($rm)
    {
        $this->reflection = $rm;
        return $this;
    }

    /**
     * @return ReflectionManager|null
     */
    public function getReflectionManager()
    {
        return $this->reflection;
    }

    /**
     * 设置当前任务目标（供 Planning / Reflection 使用）
     *
     * @param string $goal
     * @return $this
     */
    public function setGoal($goal)
    {
        $this->goal = (string) $goal;
        return $this;
    }

    /**
     * @return string
     */
    public function getGoal()
    {
        return $this->goal;
    }

    /**
     * 设置验证管理器
     *
     * @param VerificationManager|null $vm
     * @return $this
     */
    public function setVerificationManager($vm)
    {
        $this->verification = $vm;
        return $this;
    }

    /**
     * @return VerificationManager|null
     */
    public function getVerificationManager()
    {
        return $this->verification;
    }

    /**
     * 设置工作区管理器
     *
     * @param WorkspaceManager|null $wm
     * @return $this
     */
    public function setWorkspaceManager($wm)
    {
        $this->workspace = $wm;
        return $this;
    }

    /**
     * @return WorkspaceManager|null
     */
    public function getWorkspaceManager()
    {
        return $this->workspace;
    }

    /**
     * 设置技能管理器
     *
     * @param SkillManager|null $sm
     * @return $this
     */
    public function setSkillManager($sm)
    {
        $this->skillManager = $sm;
        return $this;
    }

    /**
     * @return SkillManager|null
     */
    public function getSkillManager()
    {
        return $this->skillManager;
    }

    /**
     * 设置指令管理器
     *
     * @param InstructionManager|null $im
     * @return $this
     */
    public function setInstructionManager($im)
    {
        $this->instruction = $im;
        return $this;
    }

    /**
     * @return InstructionManager|null
     */
    public function getInstructionManager()
    {
        return $this->instruction;
    }

    /**
     * 设置 MCP 管理器
     *
     * @param McpManager|null $mm
     * @return $this
     */
    public function setMcpManager($mm)
    {
        $this->mcpManager = $mm;
        return $this;
    }

    /**
     * @return McpManager|null
     */
    public function getMcpManager()
    {
        return $this->mcpManager;
    }

    /**
     * 设置记忆管理器
     *
     * @param MemoryManager|null $mm
     * @return $this
     */
    public function setMemoryManager($mm)
    {
        $this->memoryManager = $mm;
        return $this;
    }

    /**
     * @return MemoryManager|null
     */
    public function getMemoryManager()
    {
        return $this->memoryManager;
    }

    /**
     * 设置检查点管理器
     *
     * @param CheckpointManager|null $cm
     * @return $this
     */
    public function setCheckpointManager($cm)
    {
        $this->checkpointManager = $cm;
        return $this;
    }

    /**
     * @return CheckpointManager|null
     */
    public function getCheckpointManager()
    {
        return $this->checkpointManager;
    }

    /**
     * 回答用户问题并恢复 Agent 执行
     *
     * @param string $questionId
     * @param string $answer
     * @param array<int, array<string, mixed>> $messages 当前上下文消息
     * @return AgentResult
     */
    public function answerUser($questionId, $answer, array $messages)
    {
        if (!$this->interaction) {
            return AgentResult::stopped(StopReason::MODEL_ERROR, '', ['error' => '未配置用户交互管理器']);
        }
        $result = $this->interaction->answer($questionId, $answer);
        if ($result === null || !$result->isAnswered()) {
            return AgentResult::stopped(StopReason::MODEL_ERROR, '', ['error' => '问题不存在或已回答']);
        }
        // 构造工具结果并追加到消息，然后继续循环
        // 使用通用的 tool_use_id，模型会将其与之前的 ask_user 调用关联
        $toolResult = [
            'type'        => 'tool_result',
            'tool_use_id' => 'answer_' . $questionId,
            'content'     => 'User answered: ' . $answer,
            'is_error'    => false,
        ];
        $messages[] = ['role' => 'user', 'content' => [$toolResult]];
        return $this->loop->run($this->buildContext($messages));
    }

    /**
     * 设置钩子容器
     *
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

    /**
     * 注册 before_tool 钩子（快捷方式）
     *
     * @param callable $cb
     * @return $this
     */
    public function onBeforeTool($cb)
    {
        if ($this->hooks === null) {
            $this->hooks = new AgentHooks();
        }
        $this->hooks->onBeforeTool($cb);
        return $this;
    }

    /**
     * 注册 after_tool 钩子（快捷方式）
     *
     * @param callable $cb
     * @return $this
     */
    public function onAfterTool($cb)
    {
        if ($this->hooks === null) {
            $this->hooks = new AgentHooks();
        }
        $this->hooks->onAfterTool($cb);
        return $this;
    }

    /**
     * 注册 before_model 钩子（快捷方式）
     *
     * @param callable $cb
     * @return $this
     */
    public function onBeforeModel($cb)
    {
        if ($this->hooks === null) {
            $this->hooks = new AgentHooks();
        }
        $this->hooks->onBeforeModel($cb);
        return $this;
    }

    /**
     * 注册 after_model 钩子（快捷方式）
     *
     * @param callable $cb
     * @return $this
     */
    public function onAfterModel($cb)
    {
        if ($this->hooks === null) {
            $this->hooks = new AgentHooks();
        }
        $this->hooks->onAfterModel($cb);
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
    /**
     * 设置取消令牌
     *
     * 循环会在三个安全点检查它：开新一轮之前、模型回话后工具开跑前、
     * 一批工具结果回填之后。命中就存检查点并以 `user_cancel` 收尾——
     * 取消不是放弃，之后还能从检查点接着跑。
     *
     * @param \Ai\Agent\Loop\CancellationToken|null $token
     * @return $this
     */
    public function setCancellation($token)
    {
        $this->cancellation = $token instanceof \Ai\Agent\Loop\CancellationToken ? $token : null;
        if ($this->subAgentManager !== null) {
            $this->subAgentManager->setParentCancellation($this->cancellation);
        }
        return $this;
    }

    /**
     * @return \Ai\Agent\Loop\CancellationToken|null
     */
    public function getCancellation()
    {
        return $this->cancellation;
    }

    /**
     * 取消当前运行
     *
     * 没挂令牌时自动建一个——调用方要停，不该因为「你没先注册令牌」而停不下来。
     *
     * @param string $reason
     * @return $this
     */
    public function cancel($reason = '调用方要求取消')
    {
        $token = $this->cancellation;
        if ($token === null) {
            $token = new \Ai\Agent\Loop\CancellationToken();
            $this->setCancellation($token);
        }
        $token->cancel($reason);
        return $this;
    }

    /**
     * 设置工具发现（渐进披露）
     *
     * 注册表里仍然放全部工具（激活了才执行得了），但每轮**给模型看的**
     * 只有当前激活的那部分。
     *
     * @param \Ai\Agent\Tool\ToolDiscovery|null $discovery
     * @return $this
     */
    public function setToolDiscovery($discovery)
    {
        $this->toolDiscovery = $discovery instanceof \Ai\Agent\Tool\ToolDiscovery ? $discovery : null;
        return $this;
    }

    /**
     * @return \Ai\Agent\Tool\ToolDiscovery|null
     */
    public function getToolDiscovery()
    {
        return $this->toolDiscovery;
    }

    /**
     * 把本次运行的度量填进结果
     *
     * `AgentResult` 的契约字段（cost / duration_ms / files_changed …）一直存在，
     * 但从来没人填——调用方得自己去 BudgetManager 和 WorkspaceManager 里各取一遍，
     * 于是 `toContract()` 返回的成本永远是 0。生产环境要按次核算成本、
     * 要知道这次跑了多久、动了哪些文件，这些都该由运行时填好。
     *
     * 只补**没有的**字段：循环里已经算出来的（如 usage）不覆盖。
     *
     * @param AgentResult $result
     * @param float $startedAt
     * @param AgentContext $context
     * @return void
     */
    protected function attachRunMetrics($result, $startedAt, AgentContext $context)
    {
        if (!$result instanceof AgentResult) {
            return;
        }

        $extra = $result->getExtra();
        $details = [];

        if (!isset($extra['duration_ms'])) {
            $details['duration_ms'] = round((microtime(true) - $startedAt) * 1000, 1);
        }

        if ($this->budget !== null) {
            $summary = $this->budget->summary();
            if (!isset($extra['cost'])) {
                $details['cost'] = $summary['cost'];
            }
            if (!isset($extra['tool_calls_count'])) {
                $details['tool_calls_count'] = $summary['tool_calls'];
            }
            $details['budget'] = isset($extra['budget']) ? $extra['budget'] : $summary;
        }

        // 动过哪些文件：代码任务的核心产出。工作区管理器已经在跟踪，
        // 只是从来没往结果里放
        if ($this->workspace !== null && !isset($extra['files_changed'])) {
            try {
                $this->workspace->refresh();
                $modified = $this->workspace->getModified();
                if (is_array($modified) && $modified) {
                    $details['files_changed'] = array_values($modified);
                }
            } catch (\Throwable $e) {
                // 工作区不是 git 仓库、或 git 不可用——拿不到就不填，
                // 不能让「补充度量」这一步把一次成功的运行搞失败
            }
        }

        if (!isset($extra['plan']) && $this->planManager !== null && $this->planId !== '') {
            $plan = $this->planManager->getPlan($this->planId);
            if ($plan !== null) {
                $details['plan'] = [
                    'id'       => $plan->getId(),
                    'goal'     => $plan->getGoal(),
                    'version'  => $plan->getVersion(),
                    'progress' => $plan->progress(),
                    'pending'  => count($plan->getPendingSteps()),
                ];
            }
        }

        if ($details) {
            $result->withDetails($details);
        }
    }

    /**
     * 组装本次运行的 AgentContext
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
        $context->setHooks($this->hooks);
        $context->setVerificationManager($this->verification);
        $context->setWorkspaceManager($this->workspace);
        $context->setSkillManager($this->skillManager);
        $context->setInstructionManager($this->instruction);
        $context->setMcpManager($this->mcpManager);
        $context->setMemoryManager($this->memoryManager);
        $context->setPlanManager($this->planManager);
        $context->setPlanId($this->planId);
        $context->setReflectionManager($this->reflection);
        $context->setGoal($this->goal);
        $context->setCheckpointManager($this->checkpointManager);
        $context->setCancellation($this->cancellation);
        $context->setToolDiscovery($this->toolDiscovery);
        if ($this->taskId !== null) {
            $context->setCheckpointId($this->taskId);
        } elseif ($this->sessionId !== null) {
            $context->setCheckpointId($this->sessionId);
        }
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
        $this->registerInteraction();
        $this->registerSkillTool();
        $this->registerMcpTools();
        $context = $this->buildContext($messages);

        // 注入任务 ID 到上下文（事件会带上 task_id 和 parent_task_id）
        if ($this->taskId !== null) {
            $context->setTaskId($this->taskId);
        }
        if ($this->taskManager !== null && $this->taskId !== null) {
            $task = $this->taskManager->get($this->taskId);
            if ($task !== null && $task->getParentTaskId() !== null) {
                $context->setParentTaskId($task->getParentTaskId());
            }
        }

        // 任务事件：task_start
        if ($this->taskManager && $this->taskId) {
            $this->emitTaskEvent('task_start', [
                'task_id' => $this->taskId,
                'goal'    => $this->getTaskGoal(),
            ]);
            // task_start 钩子
            if ($this->hooks && $this->hooks->hasTaskStart() && $this->taskId !== null) {
                $this->hooks->triggerTaskStart($this->taskId, $this->getTaskGoal());
            }
        }

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

        // agent_start 钩子
        if ($this->hooks && $this->hooks->hasAgentStart()) {
            $this->hooks->triggerAgentStart();
        }

        $runStartedAt = microtime(true);

        try {
            $result = $this->loop->run($context);
            $this->attachRunMetrics($result, $runStartedAt, $context);
            // agent_stop 钩子
            if ($this->hooks && $this->hooks->hasAgentStop()) {
                $this->hooks->triggerAgentStop($result->getStopReason());
            }
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

            // 任务事件：task_complete / task_failed
            if ($this->taskManager && $this->taskId) {
                $this->updateTaskFromResult($result, $context);
                if ($result->isDone()) {
                    $this->emitTaskEvent('task_complete', [
                        'task_id'  => $this->taskId,
                        'result'   => $result->getText(),
                        'iterations' => $result->getIterations(),
                    ]);
                } elseif ($result->isError()) {
                    $this->emitTaskEvent('task_failed', [
                        'task_id' => $this->taskId,
                        'reason'  => $result->getStopReason(),
                        'error'   => $result->getText(),
                    ]);
                }
            }

            return $result;
        } catch (\Throwable $e) {
            // 崩溃时保存检查点（最后一次 checkpoint 已由 LoopController 每轮保存）
            // 供 recover() 恢复用
            $this->saveCrashCheckpoint($context);

            if ($this->sessionManager && $this->sessionId) {
                $this->sessionManager->interrupt($this->sessionId);
            }
            // 任务事件：task_failed（异常）
            if ($this->taskManager && $this->taskId) {
                $this->emitTaskEvent('task_failed', [
                    'task_id' => $this->taskId,
                    'reason'  => 'exception',
                    'error'   => $e->getMessage(),
                ]);
            }
            throw $e;
        } finally {
            $this->ai->setStream($streamWasOn);
        }
    }

    /**
     * 崩溃时保存检查点
     *
     * @param AgentContext $context
     * @return void
     */
    protected function saveCrashCheckpoint(AgentContext $context)
    {
        if ($this->checkpointManager === null || !$this->checkpointManager->isEnabled()) {
            return;
        }
        $cpId = $this->taskId !== null ? $this->taskId : $this->sessionId;
        if ($cpId === null) {
            return;
        }
        $extra = ['crash_recovery' => true, 'error' => 'crash before save'];
        if ($this->goal !== '') {
            $extra['goal'] = $this->goal;
        }
        $plan = $context->getPlan();
        if ($plan !== null) {
            $extra['plan'] = $plan->toArray();
        }

        $this->checkpointManager->save(
            $cpId,
            $context->getIteration(),
            $context->getMessages(),
            $extra
        );
    }

    /**
     * 从崩溃中恢复——加载最新检查点，恢复消息上下文
     *
     * 恢复流程：
     *   1. 从 CheckpointManager 加载最新检查点
     *   2. 恢复消息历史
     *   3. 恢复迭代计数
     *   4. 恢复任务目标与执行计划（检查点里存了才有）
     *   5. 返回恢复后的消息数组，供 run() 继续执行
     *
     * 工作区不在这里恢复：检查点存的是"崩溃时工作区长什么样"，
     * 磁盘上的文件此刻可能已经不同了，照着旧快照改回去是危险操作。
     * 需要时从 `getLastCheckpoint()->getExtra()['workspace']` 自己取来比对。
     *
     * @param string $taskId 任务 ID 与会话 ID
     * @return array<int, array<string, mixed>>|null 恢复后的消息，无法恢复时返回 null
     */
    public function recover($taskId)
    {
        if ($this->checkpointManager === null || !$this->checkpointManager->isEnabled()) {
            return null;
        }

        $latest = $this->checkpointManager->loadLatest((string) $taskId);
        if ($latest === null) {
            return null;
        }

        // 设置迭代计数到 LoopController
        $this->loop->setMaxIter(max($this->loop->getMaxIter(), $latest->getIteration() + 25));

        // 设置任务 ID
        $this->taskId = (string) $taskId;
        $this->lastCheckpoint = $latest;

        $extra = $latest->getExtra();
        if (isset($extra['goal']) && is_string($extra['goal']) && $extra['goal'] !== '') {
            $this->goal = $extra['goal'];
        }
        if (isset($extra['plan']) && is_array($extra['plan'])) {
            $this->restorePlanFrom($extra['plan']);
        }

        return $latest->getMessages();
    }

    /**
     * 最近一次 recover() 加载的检查点
     *
     * @return \Ai\Agent\Checkpoint\Checkpoint|null
     */
    public function getLastCheckpoint()
    {
        return $this->lastCheckpoint;
    }

    /**
     * 从检查点快照还原执行计划
     *
     * 没有 PlanManager 时临时建一个纯内存的——恢复出来的计划总得有地方放。
     *
     * @param array<string, mixed> $snapshot Plan::toArray() 的结果
     * @return void
     */
    protected function restorePlanFrom(array $snapshot)
    {
        $plan = \Ai\Agent\Planning\Plan::fromArray($snapshot);
        if ($plan->getId() === '') {
            return;
        }
        if ($this->planManager === null) {
            $this->planManager = new PlanManager();
        }
        $this->planManager->save($plan);
        $this->planManager->adopt($plan);
        $this->planId = $plan->getId();
    }

    /**
     * 根据 AgentResult 更新任务状态
     *
     * @param AgentResult $result
     * @param AgentContext $context
     * @return void
     */
    protected function updateTaskFromResult(AgentResult $result, AgentContext $context)
    {
        if ($this->taskManager === null || $this->taskId === null) {
            return;
        }
        $task = $this->taskManager->get($this->taskId);
        if ($task === null) {
            return;
        }
        if ($result->isDone()) {
            $this->taskManager->complete($this->taskId);
            // task_complete 钩子
            if ($this->hooks && $this->hooks->hasTaskComplete()) {
                $this->hooks->triggerTaskComplete($this->taskId, $result->getText());
            }
        } elseif ($result->getStopReason() === StopReason::PERMISSION_DENIED) {
            $task->setStatus(TaskStatus::WAITING_PERMISSION);
        } elseif ($result->isError()) {
            $this->taskManager->fail($this->taskId);
            // task_failed 钩子
            if ($this->hooks && $this->hooks->hasTaskFailed()) {
                $this->hooks->triggerTaskFailed($this->taskId, $result->getStopReason());
            }
        }
    }

    /**
     * 获取任务目标
     *
     * @return string
     */
    protected function getTaskGoal()
    {
        if ($this->taskManager === null || $this->taskId === null) {
            return '';
        }
        $task = $this->taskManager->get($this->taskId);
        return $task ? $task->getGoal() : '';
    }

    /**
     * 发出任务事件
     *
     * @param string $type
     * @param array<string, mixed> $data
     * @return void
     */
    protected function emitTaskEvent($type, array $data)
    {
        if ($this->emit === null) {
            return;
        }
        $event = array_merge($data, [
            'type'           => $type,
            'session_id'     => (string) $this->sessionId,
            'agent_id'       => $this->agentId,
            'task_id'        => $this->taskId,
            'timestamp'      => microtime(true),
        ]);
        call_user_func($this->emit, $event);
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