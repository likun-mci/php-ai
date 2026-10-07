<?php
namespace Ai\Agent;

use Ai\AI;
use Ai\Agent\Budget\BudgetManager;
use Ai\Agent\Context\ContextManager;
use Ai\Agent\Hooks\AgentHooks;
use Ai\Agent\Permission\PermissionManager;
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
    /** @var \Ai\Agent\Media\MediaResolver|null 请求边界的媒体解析器 */
    protected $mediaResolver = null;

    /** @var array<string, bool> 当前模型支持的输入模态 */
    protected $mediaSupport = ['image' => true, 'pdf' => true];

    /** @var \Ai\Agent\Media\MediaManager|null 媒体门面，供工具落库用 */
    protected $mediaManager = null;

    /** @var \Ai\Agent\Capability\ModalityRouter|null 模态路由 */
    protected $modalityRouter = null;

    /** @var array<int, array<string, mixed>> */
    protected $messages = [];

    /** @var array<int, array<string, mixed>> 运行中累积、待注入的用户消息（见 queueUserMessage） */
    protected $userQueue = [];

    /** @var callable|null 用户消息来源：每轮迭代回调一次，取跨进程送来的新消息 */
    protected $userMessageSource = null;

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

    /** @var string 调用方声明的用户标识（原始值，空表示未声明） */
    protected $userId = '';

    /** @var string 本次运行私有存储根目录（空表示身份不明，工具应拒绝写盘） */
    protected $storageDir = '';

    /** @var string */
    protected $agentId = '';

    /** @var int 事件计数器 */
    protected $eventCounter = 0;

    /** @var int 事件序列号（自增，每次事件+1） */
    protected $eventSequence = 0;

    /** @var string|null 当前任务 ID */
    protected $taskId = null;

    /** @var string|null 父任务 ID */
    protected $parentTaskId = null;

    /** @var string 当前工具调用 ID */
    protected $toolCallId = '';

    /** @var string 当前消息 ID */
    protected $messageId = '';

    /** @var string|null 待授权的请求 ID */
    protected $pendingPermissionId = null;

    /** @var array<string, mixed>|null 待授权的工具调用 */
    protected $pendingPermissionCall = null;

    /** @var AgentHooks|null */
    protected $hooks = null;

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

    /** @var CheckpointManager|null */
    protected $checkpointManager = null;

    /** @var PlanManager|null */
    protected $planManager = null;

    /** @var \Ai\Agent\Loop\CancellationToken|null 取消令牌 */
    protected $cancellation = null;

    /** @var \Ai\Agent\Tool\ToolDiscovery|null 工具发现（渐进披露） */
    protected $toolDiscovery = null;

    /** @var string 当前执行计划 ID */
    protected $planId = '';

    /** @var ReflectionManager|null */
    protected $reflection = null;

    /** @var string 当前任务目标（供 Planning / Reflection 判断是否达成） */
    protected $goal = '';

    /** @var string 当前 checkpoint 关联 ID */
    protected $checkpointId = '';

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
     * 挂上媒体解析器
     *
     * 只在**请求边界**用：Conversation 里存的是 `media://<id>`，
     * 发请求前才由它换成实际字节。挂空则消息里的媒体块会被明确降级
     * （告诉模型「有图但我看不到」），而不是静默消失。
     *
     * @param \Ai\Agent\Media\MediaResolver|null $resolver
     * @return $this
     */
    public function setMediaResolver($resolver)
    {
        $this->mediaResolver = $resolver instanceof \Ai\Agent\Media\MediaResolver ? $resolver : null;
        return $this;
    }

    /**
     * @return \Ai\Agent\Media\MediaResolver|null
     */
    public function getMediaResolver()
    {
        return $this->mediaResolver;
    }

    /**
     * 当前模型支持哪些输入模态
     *
     * 形如 `['image' => true, 'pdf' => false]`。P5 的 CapabilityResolver
     * 会算出准确值填进来；在那之前默认乐观（与 `CustomModel` 的既有默认一致），
     * 也就是「发出去，让平台自己说不行」——这比谎称看不到更诚实，
     * 错误也看得见。
     *
     * @param array<string, bool> $support
     * @return $this
     */
    public function setMediaSupport(array $support)
    {
        $this->mediaSupport = [
            'image' => !empty($support['image']),
            'pdf'   => !empty($support['pdf']),
        ];
        return $this;
    }

    /**
     * @return array<string, bool>
     */
    public function getMediaSupport()
    {
        return $this->mediaSupport;
    }

    /**
     * @param \Ai\Agent\Media\MediaManager|null $mm
     * @return $this
     */
    public function setMediaManager($mm)
    {
        $this->mediaManager = $mm instanceof \Ai\Agent\Media\MediaManager ? $mm : null;
        return $this;
    }

    /**
     * @return \Ai\Agent\Media\MediaManager|null
     */
    public function getMediaManager()
    {
        return $this->mediaManager;
    }

    /**
     * @param \Ai\Agent\Capability\ModalityRouter|null $router
     * @return $this
     */
    public function setModalityRouter($router)
    {
        $this->modalityRouter = $router instanceof \Ai\Agent\Capability\ModalityRouter ? $router : null;
        return $this;
    }

    /**
     * @return \Ai\Agent\Capability\ModalityRouter|null
     */
    public function getModalityRouter()
    {
        return $this->modalityRouter;
    }

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
     * @param AgentHooks|null $hooks
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

    /* ---------- 验证管理 ---------- */

    /**
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

    /* ---------- 执行计划 ---------- */

    /**
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
     * 当前执行计划，未设置计划管理器或计划 ID 时返回 null
     *
     * @return \Ai\Agent\Planning\Plan|null
     */
    public function getPlan()
    {
        if ($this->planManager === null || $this->planId === '') {
            return null;
        }
        return $this->planManager->getPlan($this->planId);
    }

    /* ---------- 自我反思 ---------- */

    /**
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
     * 设置当前任务目标
     *
     * 反思用它判断"目标是否达成"，计划用它作为 goal。为空时反思会退回到
     * 从首条用户消息推断。
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

    /* ---------- 工作区管理 ---------- */

    /**
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

    /* ---------- 技能管理 ---------- */

    /**
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

    /* ---------- 指令管理 ---------- */

    /**
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

    /* ---------- MCP 管理 ---------- */

    /**
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

    /* ---------- 记忆管理 ---------- */

    /**
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

    /* ---------- 检查点管理 ---------- */

    /**
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
     * @param string $id
     * @return $this
     */
    public function setCheckpointId($id)
    {
        $this->checkpointId = (string) $id;
        return $this;
    }

    /** @return string */
    public function getCheckpointId()
    {
        return $this->checkpointId;
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

    /**
     * 追加一条纯文本 user 消息
     *
     * 反思结论、下一步建议这类"框架自己说的话"通过它回填，
     * 与工具结果的回填路径区分开。
     *
     * @param string $text
     * @return $this
     */
    public function appendUser($text)
    {
        $text = (string) $text;
        if (trim($text) === '') {
            return $this;
        }
        $this->messages[] = ['role' => 'user', 'content' => $text];
        return $this;
    }

    /* ---------- 运行途中追加的用户消息 ---------- */

    /**
     * 排队一条用户消息，下一轮迭代开始时注入上下文
     *
     * 与 `appendUser()` 的区别是「时机」：appendUser 立刻落到消息尾部，
     * 排队的这条要等到本轮工具跑完、下一次请求模型之前才注入 —— 也就是
     * 用户「边跑边说」的话，模型在下一次决策时才看到。
     *
     * 循环在两种位置排空队列：每轮迭代开头，以及模型给出终稿、正要收尾之前。
     * 后一处保证用户在收尾瞬间发出的话不会石沉大海（见 LoopController::injectUserMessages）。
     *
     * @param string $text 用户原话
     * @param array<int, array<string, mixed>> $blocks 附加内容块（agent_media 等）
     * @param array<string, mixed> $meta 随消息带回的附加信息（会并进 user_message 事件）
     * @return $this
     */
    public function queueUserMessage($text, array $blocks = [], array $meta = [])
    {
        $text = (string) $text;
        if (trim($text) === '' && !$blocks) {
            return $this;
        }
        $this->userQueue[] = [
            'text'   => $text,
            'blocks' => array_values($blocks),
            'meta'   => $meta,
            'ts'     => time(),
        ];
        return $this;
    }

    /**
     * 进程内队列里还有没有待注入的用户消息
     *
     * 只看 `queueUserMessage()` 攒下的那些，**不会去问消息来源** ——
     * 来源是「取走即已读」的，为了判断一下而回调它，等于把消息吞掉。
     * 想知道来源里有没有东西，只能去 `drainUserMessages()` 取，取到多少算多少。
     *
     * @return bool
     */
    public function hasQueuedUserMessages()
    {
        return (bool) $this->userQueue;
    }

    /**
     * @return array<int, array<string, mixed>> 队列内容（不清空）
     */
    public function queuedUserMessages()
    {
        return $this->userQueue;
    }

    /**
     * 排空队列（消息来源 + 进程内队列一起取走）
     *
     * 顺序按时间：来源里的（跨进程送来的）在前，进程内队列的在后。
     * **来源每调一次就消耗一批**：回调返回的应当是「自上次之后新到的」那些，
     * 且由回调自己负责标记已读（典型做法是记住文件读到的偏移量）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function drainUserMessages()
    {
        $batch = [];
        if ($this->userMessageSource !== null) {
            $pending = call_user_func($this->userMessageSource);
            if (is_array($pending)) {
                foreach ($pending as $item) {
                    if (is_array($item)) {
                        $batch[] = $item;
                    }
                }
            }
        }
        foreach ($this->userQueue as $item) {
            $batch[] = $item;
        }
        $this->userQueue = [];
        return $batch;
    }

    /**
     * 挂上用户消息来源
     *
     * 进程内队列（queueUserMessage）只有同一个进程看得见；用户在另一个
     * HTTP 请求里发来的消息跨不了进程，得由调用方提供一个「去读盘」的回调：
     * 每次回调返回自上次之后新到的消息（并自行负责标记已读），空数组表示没有。
     *
     * 签名：function (): array<int, array<string, mixed>>
     *
     * @param callable|null $source
     * @return $this
     */
    public function setUserMessageSource($source)
    {
        $this->userMessageSource = $source !== null && is_callable($source) ? $source : null;
        return $this;
    }

    /** @return callable|null */
    public function getUserMessageSource()
    {
        return $this->userMessageSource;
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
        $defs = $this->toolRegistry->defs();
        if ($this->toolDiscovery === null) {
            return $defs;
        }

        // 渐进披露：注册表里放着全部工具（否则激活了也执行不了），
        // 但**给模型看的**只有当前激活的那部分。每轮重新算一次，
        // 模型这一轮用 search_tools 激活的工具，下一轮就能看见
        $active = $this->toolDiscovery->activeTools();
        $visible = [];
        foreach ($defs as $def) {
            $name = isset($def['name']) ? (string) $def['name'] : '';
            if ($name !== '' && isset($active[$name])) {
                $visible[] = $def;
            }
        }
        return $visible;
    }

    /**
     * @param \Ai\Agent\Loop\CancellationToken|null $token
     * @return $this
     */
    public function setCancellation($token)
    {
        $this->cancellation = $token instanceof \Ai\Agent\Loop\CancellationToken ? $token : null;
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
     * 现在该停了吗
     *
     * @return bool
     */
    public function isCancelled()
    {
        return $this->cancellation !== null && $this->cancellation->isCancelled();
    }

    /**
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
        $this->eventSequence++;
        $event = array_merge($data, [
            'type'           => $type,
            'id'             => $this->nextEventId(),
            'sequence'       => $this->eventSequence,
            'session_id'     => $this->sessionId,
            'agent_id'       => $this->agentId,
            'turn_id'        => 'turn_' . $this->iterations,
            'task_id'        => $this->taskId,
            'parent_task_id' => $this->parentTaskId,
            'tool_call_id'   => $this->toolCallId,
            'message_id'     => $this->messageId,
            'timestamp'      => microtime(true),
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
     * 调用方声明的用户标识（原始值）
     *
     * 请求边界之外的东西（工具、子 Agent）需要知道「这是谁的会话」时读它，
     * 而不是去翻 agentHome 的哈希路径反推。
     *
     * @param string $userId
     * @return $this
     */
    public function setUserId($userId)
    {
        $this->userId = (string) $userId;
        return $this;
    }

    /** @return string */
    public function getUserId()
    {
        return $this->userId;
    }

    /**
     * 本次运行私有存储根目录（绝对路径；空 = 身份不明，工具应拒绝写盘）
     *
     * @param string $dir
     * @return $this
     */
    public function setStorageDir($dir)
    {
        $this->storageDir = (string) $dir;
        return $this;
    }

    /** @return string */
    public function getStorageDir()
    {
        return $this->storageDir;
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

    /* ---------- 事件增强字段 ---------- */

    /**
     * @param string|null $taskId
     * @return $this
     */
    public function setTaskId($taskId)
    {
        $this->taskId = $taskId !== null ? (string) $taskId : null;
        return $this;
    }

    /** @return string|null */
    public function getTaskId()
    {
        return $this->taskId;
    }

    /**
     * @param string|null $parentTaskId
     * @return $this
     */
    public function setParentTaskId($parentTaskId)
    {
        $this->parentTaskId = $parentTaskId !== null ? (string) $parentTaskId : null;
        return $this;
    }

    /** @return string|null */
    public function getParentTaskId()
    {
        return $this->parentTaskId;
    }

    /**
     * @param string $toolCallId
     * @return $this
     */
    public function setToolCallId($toolCallId)
    {
        $this->toolCallId = (string) $toolCallId;
        return $this;
    }

    /** @return string */
    public function getToolCallId()
    {
        return $this->toolCallId;
    }

    /**
     * @param string $messageId
     * @return $this
     */
    public function setMessageId($messageId)
    {
        $this->messageId = (string) $messageId;
        return $this;
    }

    /** @return string */
    public function getMessageId()
    {
        return $this->messageId;
    }

    /** @return int */
    public function getEventSequence()
    {
        return $this->eventSequence;
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