<?php
namespace Ai\Agent;

use Ai\AI;

/**
 * Agentic 多轮循环（可复用）
 *
 * 给模型挂上一组工具，循环执行：
 *   模型决策(tool_use) → 我们执行工具 → 把 tool_result 回填 → 继续，直到 end_turn 或达上限。
 *
 * **平台无关**：工具定义、模型发起的调用、结果回填全部走库的统一格式，
 * 协议层负责翻译成各平台的实际结构（OpenAI 系的 tool_calls / role:'tool'，
 * Anthropic 系的 tool_use / tool_result 块），因此同一段 Agent 代码
 * 可以直接跑在 40 个协议上，换平台只改 protocol 配置。
 *
 * 工具格式（兼容旧版与新版）：
 * ```php
 * // 旧版（闭包）
 * $tools = [
 *     'read_file' => [
 *        'description'  => '...',
 *        'input_schema' => ['type'=>'object',...],
 *        'handler'      => function(array $input): string { ... },
 *     ],
 * ];
 *
 * // 新版（对象）
 * $tools = [
 *     new ReadFileTool(),
 * ];
 * ```
 *
 * 事件回调 onEvent(array $event)：
 *   - ['type'=>'agent_text','text'=>...]      模型自然语言
 *   - ['type'=>'tool_call','name'=>...,'input'=>...]
 *   - ['type'=>'tool_error','name'=>...,'message'=>...]  工具抛错（已回填给模型）
 *   - ['type'=>'done']                        正常结束
 *   - ['type'=>'error','message'=>...]
 *   （工具内部的细粒度事件——如 diff/todo——由各 handler 自行通过闭包发出）
 *
 * 内部实现：
 *  v2.0 起内部委托给 {@see AgentRuntime} 执行，但 public API 与事件结构
 *  完全向后兼容，已有代码无需修改。
 */
class Agent
{
    /** @var AI */
    protected $ai;

    /** @var AgentRuntime */
    protected $runtime;

    /** @var string */
    protected $lastText = '';

    /** @var array<int, array<string, mixed>> 最近一次运行的消息历史（供完成判据检查错误） */
    protected $lastMessages = [];

    /** @var \Ai\Agent\Orchestrator\AgentOrchestrator|null 编排器（惰性创建） */
    protected $orchestrator = null;

    /** @var callable|null 事件回调，编排器创建时一并接上 */
    protected $eventCallback = null;

    /** @var \Ai\Agent\Session\SessionBus|null 跨 Session 消息总线 */
    protected $sessionBus = null;

    /** @var \Ai\Agent\Memory\MemoryConsolidator|null 记忆整理器 */
    protected $consolidator = null;

    /** @var \Ai\Agent\Orchestrator\AgentScheduler|null 任务调度器 */
    protected $scheduler = null;

    /** @var \Ai\Agent\Orchestrator\ArtifactManager|null 产物管理器 */
    protected $artifacts = null;

    /** @var \Ai\Agent\Event\EventLog|null 事件日志 */
    protected $eventLog = null;

    /** @var \Ai\Agent\Orchestrator\ModelRouter|null 模型路由器 */
    protected $modelRouter = null;

    /** @var \Ai\Agent\Tool\ToolGroup|null 工具分组 */
    protected $toolGroups = null;

    /** @var \Ai\Agent\Tool\ToolDiscovery|null 工具发现 */
    protected $toolDiscovery = null;

    /** @var \Ai\Agent\Permission\PermissionPolicy|null 分层权限策略 */
    protected $permissionPolicy = null;

    /**
     * @param AI $ai
     */
    public function __construct(AI $ai)
    {
        $this->ai = $ai;
        $this->runtime = new AgentRuntime($ai);
    }

    /**
     * 创建一个 Agent（链式配置入口）
     *
     * ```php
     * $agent = Agent::create($ai)
     *     ->workdir('/var/www/project')
     *     ->codeAgent();
     *
     * $result = $agent->task('把登录系统改造成支持 Google OAuth，并完成测试');
     * ```
     *
     * 使用者不需要理解 LoopController / ContextManager / PermissionManager /
     * SubAgentManager——这些在 `codeAgent()` 里已经装配好了。
     *
     * @param AI $ai
     * @return self
     */
    public static function create(AI $ai)
    {
        return new self($ai);
    }

    /**
     * 设置工作目录（`setWorkdir()` 的链式别名）
     *
     * @param string $dir
     * @return $this
     */
    public function workdir($dir)
    {
        return $this->setWorkdir($dir);
    }

    /**
     * 追加工具（保留已注册的）
     *
     * `setTools()` 是覆盖，这个是追加——装配好 codeAgent 之后再加自己的工具时用它。
     *
     * @param array<string, mixed> $tools
     * @return $this
     */
    public function tools(array $tools)
    {
        $merged = array_merge($this->runtime->getToolRegistry()->all(), $tools);
        return $this->setTools($merged);
    }

    /**
     * 从目录装载技能（`discoverSkills()` 的链式别名）
     *
     * @param string|string[] $dirs
     * @return $this
     */
    public function skills($dirs)
    {
        $this->discoverSkills($dirs);
        return $this;
    }

    /**
     * 注册一批子 Agent
     *
     * ```php
     * $agent->agents([
     *     'dba' => ['description' => '数据库结构与查询优化', 'tools' => ['read_file', 'bash']],
     * ]);
     * ```
     *
     * 数组里的 `tools` 可以直接写工具名——会从父 Agent 的工具集里挑，
     * 挑不到的不会凭空出现。
     *
     * 每个角色可以挂在不同平台上——`model` 旁边写上该平台的 Key 即可，
     * 或者用 `platforms()` 登记一次、按模型名自动匹配：
     *
     * ```php
     * $agent->agents([
     *     'planner'  => ['description' => '任务规划', 'model' => 'gpt-4o',          'api_key' => $oaKey],
     *     'coder'    => ['description' => '写代码',   'model' => 'deepseek-chat',   'api_key' => $dsKey],
     *     'reviewer' => ['description' => '审代码',   'model' => 'moonshot-v1-32k', 'api_key' => $kimiKey],
     * ]);
     * ```
     *
     * @param array<string, array<string, mixed>> $agents 名称 => 配置
     * @return $this
     */
    public function agents(array $agents)
    {
        $sam = $this->runtime->getSubAgentManager();
        if ($sam === null) {
            $sam = new \Ai\Agent\SubAgent\SubAgentManager($this->ai);
            $this->setSubAgentManager($sam);
        }

        $parentTools = $this->runtime->getToolRegistry()->all();
        foreach ($agents as $name => $config) {
            if (!is_array($config)) {
                continue;
            }
            // tools 写成名字数组时按名字从父工具集里取
            if (isset($config['tools']) && is_array($config['tools'])) {
                $resolved = [];
                foreach ($config['tools'] as $key => $value) {
                    $toolName = is_string($value) ? $value : (string) $key;
                    if (isset($parentTools[$toolName])) {
                        $resolved[$toolName] = $parentTools[$toolName];
                    } elseif (!is_string($value)) {
                        $resolved[(string) $key] = $value;
                    }
                }
                $config['tools'] = $resolved;
            }
            $sam->register((string) $name, $config);
        }
        $this->syncModelRouter();
        return $this;
    }

    /**
     * 登记各平台的连接配置（跨平台编排）
     *
     * 子 Agent 只写模型名时，库按模型名推断平台，再从这里取 Key 与地址——
     * 不必在每个角色里重复贴 Key，ModelRouter 动态选出来的模型也能自动配对。
     *
     * ```php
     * $agent->platforms([
     *     'deepseek' => ['api_key' => $dsKey],
     *     'moonshot' => ['api_key' => $kimiKey],
     *     'openai'   => ['api_key' => $oaKey],
     * ])->agents([
     *     'coder'    => ['description' => '写代码', 'model' => 'deepseek-chat'],
     *     'reviewer' => ['description' => '审代码', 'model' => 'moonshot-v1-32k'],
     *     'planner'  => ['description' => '规划',   'model' => 'gpt-4o'],
     * ]);
     * ```
     *
     * 键是平台名（同 `AI::platformOfModel()`）或具体模型名，模型名精确匹配优先。
     * 值的可用键见 `SubAgentDefinition::$connectionKeys`。
     *
     * @param array<string, array<string, mixed>> $configs 平台名 => 连接配置
     * @return $this
     */
    public function platforms(array $configs)
    {
        $sam = $this->runtime->getSubAgentManager();
        if ($sam === null) {
            $sam = new \Ai\Agent\SubAgent\SubAgentManager($this->ai);
            $this->setSubAgentManager($sam);
        }
        $sam->setPlatformConfigs($configs);
        return $this;
    }

    /**
     * 把模型路由器接到子 Agent 管理器上
     *
     * 两边任一先出现都要能接上：先 `modelRouter()` 后 `agents()`，或反过来。
     *
     * @return void
     */
    protected function syncModelRouter()
    {
        if ($this->modelRouter === null) {
            return;
        }
        $sam = $this->runtime->getSubAgentManager();
        if ($sam !== null) {
            $sam->setModelRouter($this->modelRouter);
        }
    }

    /**
     * @param mixed $system
     * @return $this
     */
    public function setSystem($system)
    {
        $this->runtime->setSystem($system);
        return $this;
    }

    /**
     * @param array<string, mixed> $tools 工具名 => 数组定义或 AgentToolInterface 实例
     * @return $this
     */
    public function setTools(array $tools)
    {
        $this->runtime->setTools($tools);
        return $this;
    }

    /**
     * @return $this
     */
    public function onEvent(callable $emit)
    {
        $this->eventCallback = $emit;
        $this->runtime->onEvent($emit);
        if ($this->orchestrator !== null) {
            $this->orchestrator->onEvent($emit);
        }
        return $this;
    }

    /**
     * @param mixed $n
     * @return $this
     */
    public function setMaxIter($n)
    {
        $this->runtime->setMaxIter($n);
        return $this;
    }

    /**
     * 是否以流式跑这个循环
     *
     * 开启后每一轮的正文都会实时经由 AI 的流式回调吐出去，工具调用照常工作
     * （库会把各平台分片下发的 tool_calls 重组回来）。适合聊天类界面：
     * 用户能一边看到模型说话，一边看到它去调工具。
     *
     * 默认关闭，与旧版本行为一致。开启前请先在 AI 实例上 setStreamCallback()
     * 注册回调，否则分片会直接 echo 到输出。
     *
     * @param bool $stream
     * @return $this
     */
    public function setStream($stream = true)
    {
        $this->runtime->setStream($stream);
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
        $this->runtime->setWorkdir($workdir);
        return $this;
    }

    /**
     * 设置工作区目录（自动创建 WorkspaceManager 跟踪 git 状态）
     *
     * 等价于 setWorkdir()，但语义更明确：工作区状态会在每轮迭代中
     * 注入系统提示词，让模型知道当前分支、修改文件等。
     *
     * @param string $workdir
     * @return $this
     */
    public function setWorkspaceDir($workdir)
    {
        $this->runtime->setWorkdir((string) $workdir);
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
        $this->runtime->setParallelTools($parallel);
        return $this;
    }

    /**
     * 设置权限模式
     *
     * @param string $mode manual|auto|plan|accept_edits|dont_ask|bypass
     * @return $this
     */
    public function setPermissionMode($mode)
    {
        $this->runtime->setPermissionMode($mode);
        return $this;
    }

    /**
     * 设置会话 ID（启用会话持久化时需要）
     *
     * @param string $id
     * @return $this
     */
    public function setSessionId($id)
    {
        $this->runtime->setSessionId($id);
        return $this;
    }

    /**
     * 设置会话管理器（启用持久化）
     *
     * @param \Ai\Agent\Session\SessionManager $sm
     * @return $this
     */
    public function setSessionManager($sm)
    {
        $this->runtime->setSessionManager($sm);
        return $this;
    }

    /**
     * 设置预算上限
     *
     * @param float $maxBudget 美元
     * @param array<string, mixed> $pricing 价格表
     * @return $this
     */
    public function setMaxBudget($maxBudget, array $pricing = [])
    {
        $this->runtime->setMaxBudget($maxBudget, $pricing);
        return $this;
    }

    /**
     * 设置降级模型
     *
     * @param string[] $models
     * @return $this
     */
    public function setFallbackModels(array $models)
    {
        $this->runtime->setFallbackModels($models);
        return $this;
    }

    /**
     * 设置上下文管理器（自动压缩超长上下文）
     *
     * @param \Ai\Agent\Context\ContextManager $cm
     * @return $this
     */
    public function setContextManager($cm)
    {
        $this->runtime->setContextManager($cm);
        return $this;
    }

    /**
     * 设置工具执行超时秒数（0 不限制）
     *
     * 超过此期限（含重试等待）仍未返回的工具将被标记为超时，
     * 超时结果不再重试，直接返回给模型。
     *
     * @param int $seconds
     * @return $this
     */
    public function setToolTimeout($seconds)
    {
        $this->runtime->setToolTimeout($seconds);
        return $this;
    }

    /**
     * 注册 before_tool 钩子
     *
     * 在工具执行前调用。返回 ToolResult 则短路执行（不执行实际工具）。
     * 签名：function (string $name, array $input, ToolContext $ctx): ?ToolResult
     *
     * @param callable $cb
     * @return $this
     */
    public function onBeforeTool($cb)
    {
        $this->runtime->onBeforeTool($cb);
        return $this;
    }

    /**
     * 注册 after_tool 钩子
     *
     * 在工具执行后调用，可修改/包装结果。
     * 签名：function (string $name, ToolResult $result): ToolResult
     *
     * @param callable $cb
     * @return $this
     */
    public function onAfterTool($cb)
    {
        $this->runtime->onAfterTool($cb);
        return $this;
    }

    /**
     * 注册 before_model 钩子
     *
     * 在模型调用前调用，可修改请求参数。
     * 签名：function (array $messages, array $tools): array
     *
     * @param callable $cb
     * @return $this
     */
    public function onBeforeModel($cb)
    {
        $this->runtime->onBeforeModel($cb);
        return $this;
    }

    /**
     * 注册 after_model 钩子
     *
     * 在模型调用后调用，可修改/记录响应。
     * 签名：function ($response): $response
     *
     * @param callable $cb
     * @return $this
     */
    public function onAfterModel($cb)
    {
        $this->runtime->onAfterModel($cb);
        return $this;
    }

    /**
     * 注册 tool_error 钩子
     *
     * @param callable $cb
     * @return $this
     */
    public function onToolError($cb)
    {
        $hooks = $this->runtime->getHooks();
        if ($hooks === null) {
            $hooks = new \Ai\Agent\Hooks\AgentHooks();
            $this->runtime->setHooks($hooks);
        }
        $hooks->onToolError($cb);
        return $this;
    }

    /**
     * 注册 after_tool_batch 钩子
     *
     * @param callable $cb
     * @return $this
     */
    public function onAfterToolBatch($cb)
    {
        $hooks = $this->runtime->getHooks();
        if ($hooks === null) {
            $hooks = new \Ai\Agent\Hooks\AgentHooks();
            $this->runtime->setHooks($hooks);
        }
        $hooks->onAfterToolBatch($cb);
        return $this;
    }

    /**
     * 注册 permission_request 钩子
     *
     * @param callable $cb
     * @return $this
     */
    public function onPermissionRequest($cb)
    {
        $hooks = $this->runtime->getHooks();
        if ($hooks === null) {
            $hooks = new \Ai\Agent\Hooks\AgentHooks();
            $this->runtime->setHooks($hooks);
        }
        $hooks->onPermissionRequest($cb);
        return $this;
    }

    /**
     * 注册 task_start 钩子
     *
     * @param callable $cb
     * @return $this
     */
    public function onTaskStart($cb)
    {
        $hooks = $this->runtime->getHooks();
        if ($hooks === null) {
            $hooks = new \Ai\Agent\Hooks\AgentHooks();
            $this->runtime->setHooks($hooks);
        }
        $hooks->onTaskStart($cb);
        return $this;
    }

    /**
     * 注册 task_complete 钩子
     *
     * @param callable $cb
     * @return $this
     */
    public function onTaskComplete($cb)
    {
        $hooks = $this->runtime->getHooks();
        if ($hooks === null) {
            $hooks = new \Ai\Agent\Hooks\AgentHooks();
            $this->runtime->setHooks($hooks);
        }
        $hooks->onTaskComplete($cb);
        return $this;
    }

    /**
     * 注册 subagent_start 钩子
     *
     * @param callable $cb
     * @return $this
     */
    public function onSubagentStart($cb)
    {
        $hooks = $this->runtime->getHooks();
        if ($hooks === null) {
            $hooks = new \Ai\Agent\Hooks\AgentHooks();
            $this->runtime->setHooks($hooks);
        }
        $hooks->onSubagentStart($cb);
        return $this;
    }

    /**
     * 注册 subagent_stop 钩子
     *
     * @param callable $cb
     * @return $this
     */
    public function onSubagentStop($cb)
    {
        $hooks = $this->runtime->getHooks();
        if ($hooks === null) {
            $hooks = new \Ai\Agent\Hooks\AgentHooks();
            $this->runtime->setHooks($hooks);
        }
        $hooks->onSubagentStop($cb);
        return $this;
    }

    /**
     * 注册 before_compact 钩子
     *
     * @param callable $cb
     * @return $this
     */
    public function onBeforeCompact($cb)
    {
        $hooks = $this->runtime->getHooks();
        if ($hooks === null) {
            $hooks = new \Ai\Agent\Hooks\AgentHooks();
            $this->runtime->setHooks($hooks);
        }
        $hooks->onBeforeCompact($cb);
        return $this;
    }

    /**
     * 注册 after_compact 钩子
     *
     * @param callable $cb
     * @return $this
     */
    public function onAfterCompact($cb)
    {
        $hooks = $this->runtime->getHooks();
        if ($hooks === null) {
            $hooks = new \Ai\Agent\Hooks\AgentHooks();
            $this->runtime->setHooks($hooks);
        }
        $hooks->onAfterCompact($cb);
        return $this;
    }

    /**
     * 设置 Agent 标识（事件里带 agent_id 字段，便于前端区分）
     *
     * @param string $agentId
     * @return $this
     */
    public function setAgentId($agentId)
    {
        $this->runtime->setAgentId($agentId);
        return $this;
    }

    /**
     * 设置用户交互管理器（启用 ask_user 工具）
     *
     * @param \Ai\Agent\Interaction\UserInteractionManager $uim
     * @return $this
     */
    public function setUserInteractionManager($uim)
    {
        $this->runtime->setUserInteractionManager($uim);
        return $this;
    }

    /**
     * 设置验证策略
     *
     * 工具执行后自动运行验证命令（如 `php -l {file}`），
     * 验证失败时把错误信息回填给模型，让模型自行修复。
     *
     * 用法：
     * ```php
     * $agent->setVerification([
     *     'edit_file'  => ['php -l {file}'],
     *     'write_file' => ['php -l {file}'],
     *     'test'       => ['vendor/bin/phpunit'],
     * ]);
     * ```
     *
     * @param array<string, string|string[]> $rules 工具名 => 命令或命令数组
     * @return $this
     */
    public function setVerification(array $rules)
    {
        $vm = new \Ai\Agent\Verification\VerificationManager($rules);
        $this->runtime->setVerificationManager($vm);
        return $this;
    }

    /**
     * 挂载一个验证器
     *
     * 与 `setVerification()` 的命令式规则可以共存：命令式适合"跑一条命令看退出码"，
     * 验证器适合需要解析输出、定位到文件行号的场景。未设置过验证管理器时自动创建一个。
     *
     * ```php
     * $agent->addVerifier(new \Ai\Agent\Verification\PhpSyntaxVerifier());
     * $agent->addVerifier(new \Ai\Agent\Verification\SecurityVerifier());
     * ```
     *
     * @param \Ai\Agent\Verification\VerifierInterface $verifier
     * @return $this
     */
    public function addVerifier($verifier)
    {
        $vm = $this->runtime->getVerificationManager();
        if ($vm === null) {
            $vm = new \Ai\Agent\Verification\VerificationManager();
            $this->runtime->setVerificationManager($vm);
        }
        $vm->addVerifier($verifier);
        return $this;
    }

    /**
     * 一次性挂载全部内置验证器
     *
     * PHP 语法检查 + 安全扫描默认开启；单元测试与 git 差异检查需要显式给出
     * 命令与目录，因此只在传了对应选项时挂载。
     *
     * ```php
     * $agent->useDefaultVerifiers([
     *     'test'    => 'composer test',        // 挂 UnitTestVerifier
     *     'workdir' => '/var/www/project',     // 挂 GitDiffVerifier
     *     'maxFiles' => 10,
     * ]);
     * ```
     *
     * @param array<string, mixed> $options test / workdir / maxFiles / maxLines / protectPaths
     * @return $this
     */
    public function useDefaultVerifiers(array $options = [])
    {
        $this->addVerifier(new \Ai\Agent\Verification\PhpSyntaxVerifier());
        $this->addVerifier(new \Ai\Agent\Verification\SecurityVerifier());

        if (isset($options['test']) && (string) $options['test'] !== '') {
            $this->addVerifier(new \Ai\Agent\Verification\UnitTestVerifier([
                'command' => (string) $options['test'],
                'workdir' => isset($options['workdir']) ? (string) $options['workdir'] : '',
            ]));
        }

        if (isset($options['workdir']) && (string) $options['workdir'] !== '') {
            $this->addVerifier(new \Ai\Agent\Verification\GitDiffVerifier([
                'workdir'      => (string) $options['workdir'],
                'maxFiles'     => isset($options['maxFiles']) ? (int) $options['maxFiles'] : 0,
                'maxLines'     => isset($options['maxLines']) ? (int) $options['maxLines'] : 0,
                'protectPaths' => isset($options['protectPaths']) && is_array($options['protectPaths'])
                    ? $options['protectPaths']
                    : [],
            ]));
        }

        return $this;
    }

    /**
     * 任务调度器（惰性创建）
     *
     * 控制「下一个跑哪个」与「同时最多跑几个」。
     *
     * @param array<string, mixed> $limits max_tasks / max_subagents / max_concurrent …
     * @return \Ai\Agent\Orchestrator\AgentScheduler
     */
    public function scheduler(array $limits = [])
    {
        if ($this->scheduler === null) {
            $this->scheduler = new \Ai\Agent\Orchestrator\AgentScheduler($limits);
        }
        return $this->scheduler;
    }

    /**
     * 产物管理器（惰性创建）
     *
     * 测试报告、补丁、日志不该全文塞进上下文——存在外面，上下文里只留引用。
     *
     * @param string $baseDir 存储目录，空则纯内存
     * @return \Ai\Agent\Orchestrator\ArtifactManager
     */
    public function artifacts($baseDir = '')
    {
        if ($this->artifacts === null) {
            $this->artifacts = new \Ai\Agent\Orchestrator\ArtifactManager((string) $baseDir);
        }
        return $this->artifacts;
    }

    /**
     * 事件日志（惰性创建）
     *
     * 断线重连时从指定 sequence 续发，**只重发事件，不重跑 Agent**。
     * 创建后会自动接上事件回调。
     *
     * @param string $baseDir 落盘目录，空则纯内存
     * @return \Ai\Agent\Event\EventLog
     */
    public function eventLog($baseDir = '')
    {
        if ($this->eventLog !== null) {
            return $this->eventLog;
        }

        $log = new \Ai\Agent\Event\EventLog((string) $baseDir);
        $this->eventLog = $log;

        $recorder = $log->recorder();
        $previous = $this->eventCallback;
        $this->onEvent(function (array $event) use ($recorder, $previous) {
            $recorder($event);
            if ($previous !== null) {
                call_user_func($previous, $event);
            }
        });
        return $log;
    }

    /**
     * 模型路由器（惰性创建）
     *
     * 按角色与任务复杂度选模型：explorer 用便宜的，coder / reviewer 用强的。
     * 创建后会自动接到子 Agent 管理器上——**定义里没写 `model` 的子 Agent** 由它选，
     * 写死了 `model` 的以定义为准。
     *
     * 选出来的模型跨平台时，凭据从 `platforms()` 登记的表里按模型名自动匹配：
     *
     * ```php
     * $agent->platforms([
     *     'deepseek' => ['api_key' => $dsKey],
     *     'moonshot' => ['api_key' => $kimiKey],
     * ])->modelRouter([
     *     'cheap'    => 'deepseek-chat',
     *     'standard' => 'moonshot-v1-32k',
     *     'premium'  => 'gpt-4o',
     * ]);
     * ```
     *
     * @param array<string, string> $tiers cheap / standard / premium => 模型名
     * @return \Ai\Agent\Orchestrator\ModelRouter
     */
    public function modelRouter(array $tiers = [])
    {
        if ($this->modelRouter === null) {
            $this->modelRouter = new \Ai\Agent\Orchestrator\ModelRouter($tiers);
        }
        $router = $this->modelRouter;
        $this->syncModelRouter();
        return $router;
    }

    /**
     * 工具分组（惰性创建）
     *
     * @return \Ai\Agent\Tool\ToolGroup
     */
    public function toolGroups()
    {
        if ($this->toolGroups === null) {
            $this->toolGroups = new \Ai\Agent\Tool\ToolGroup();
        }
        return $this->toolGroups;
    }

    /**
     * 启用工具按需发现
     *
     * 工具很多时，初始只给常用的 + 一个 `search_tools`，模型需要别的能力时
     * 自己搜出来再启用——避免几十个工具定义占满每一轮请求。
     *
     * @param string[] $alwaysAvailable 一开始就给的工具名
     * @return \Ai\Agent\Tool\ToolDiscovery
     */
    public function useToolDiscovery(array $alwaysAvailable = [])
    {
        $discovery = new \Ai\Agent\Tool\ToolDiscovery($this->runtime->getToolRegistry(), [
            'alwaysAvailable' => $alwaysAvailable,
            'groups'          => $this->toolGroups,
        ]);
        $this->toolDiscovery = $discovery;
        $this->setTools($discovery->initialTools());
        return $discovery;
    }

    /**
     * 分层权限策略（惰性创建）
     *
     * 最终权限 = Global AND Agent AND Skill AND Task，DENY 优先。
     *
     * @return \Ai\Agent\Permission\PermissionPolicy
     */
    public function permissionPolicy()
    {
        if ($this->permissionPolicy === null) {
            $this->permissionPolicy = new \Ai\Agent\Permission\PermissionPolicy();
        }
        return $this->permissionPolicy;
    }

    /**
     * 把分层权限策略应用到权限管理器
     *
     * @return $this
     */
    public function applyPermissionPolicy()
    {
        $pm = $this->runtime->getPermission();
        if ($pm === null) {
            $pm = new \Ai\Agent\Permission\PermissionManager();
            $this->runtime->setPermission($pm);
        }
        $this->permissionPolicy()->applyTo($pm);
        return $this;
    }

    /**
     * 恢复一个任务
     *
     * 后台任务查句柄，崩溃任务从检查点恢复消息继续跑。
     *
     * @param string $taskId
     * @return array<string, mixed>|null 后台句柄，或 ['messages' => …] 恢复出的消息
     */
    public function resume($taskId)
    {
        $taskId = (string) $taskId;

        $handle = $this->orchestrator()->dispatcher()->status($taskId);
        if ($handle !== null) {
            return $handle;
        }

        $messages = $this->runtime->recover($taskId);
        return $messages === null ? null : ['task_id' => $taskId, 'messages' => $messages];
    }

    /**
     * 编排器（惰性创建）
     *
     * 拿到它就能自己看决策、换策略选择器：
     *
     * ```php
     * $agent->orchestrator()->selector()->setAutoDelegate(false);
     * $decision = $agent->orchestrator()->decide('重构认证系统');
     * ```
     *
     * @return \Ai\Agent\Orchestrator\AgentOrchestrator
     */
    public function orchestrator()
    {
        if ($this->orchestrator === null) {
            $this->orchestrator = new \Ai\Agent\Orchestrator\AgentOrchestrator($this->runtime, [
                'subAgents'   => $this->runtime->getSubAgentManager(),
                'planManager' => $this->runtime->getPlanManager(),
            ]);
            if ($this->eventCallback !== null) {
                $this->orchestrator->onEvent($this->eventCallback);
            }
        }
        return $this->orchestrator;
    }

    /**
     * 交给编排层处理一个任务——由 Agent 自己决定怎么干
     *
     * 与 `run()` 的区别：`run()` 直接进循环，`task()` 会先判断该直接干、
     * 该先拆计划、该派子 Agent 还是该并行铺开。
     *
     * ```php
     * $result = $agent->task('重构整个用户认证系统');
     * echo $agent->orchestrator()->lastDecision()->toSummary();   // 看它为什么这么选
     * ```
     *
     * @param string $task
     * @param array<string, mixed> $context
     * @return AgentResult
     */
    public function task($task, array $context = [])
    {
        return $this->orchestrator()->handle((string) $task, $context);
    }

    /**
     * 后台派发一个任务，立即返回句柄
     *
     * 真异步程度取决于环境：注入了 runner（协程 / 队列）→ 真异步；
     * `pcntl_fork` 可用 → fork 子进程；都不行 → 同步跑完再返回。
     * 返回的 `mode` 字段如实标明走的是哪一档，不会假装异步。
     *
     * ```php
     * $handle = $agent->dispatch('扫描整个项目的安全问题');
     * $handle['task_id'];   // 'task_1_ab12cd34'
     * $handle['mode'];      // 'runner' | 'fork' | 'sync'
     * ```
     *
     * @param string $task
     * @return array<string, mixed>
     */
    public function dispatch($task)
    {
        $orchestrator = $this->orchestrator();
        $decision = \Ai\Agent\Orchestrator\StrategyDecision::background('调用方显式要求后台执行');
        $result = $orchestrator->execute((string) $task, $decision);

        $meta = $result->getExtra();
        return isset($meta['handle']) && is_array($meta['handle'])
            ? $meta['handle']
            : ['task_id' => '', 'status' => 'failed', 'mode' => 'sync', 'background' => false];
    }

    /**
     * 查询后台任务
     *
     * @param string $taskId
     * @return array<string, mixed>|null
     */
    public function taskStatus($taskId)
    {
        return $this->orchestrator()->dispatcher()->status((string) $taskId);
    }

    /**
     * 一键装配成代码 Agent
     *
     * 把「让 Agent 干活于一个 PHP 项目」需要的东西一次配齐：内置工具、
     * 六个专职子 Agent、默认验证器、执行计划、反思。之后一句 `task()` 就能开工。
     *
     * ```php
     * $agent = (new Agent($ai))->setWorkdir('/var/www/project')->codeAgent();
     * $result = $agent->task('修复登录 Bug 并运行测试');
     * ```
     *
     * @param array<string, mixed> $options test（测试命令）/ agents（只装哪几个子 Agent）/
     *                                      permissionMode / maxFiles / maxLines
     * @return $this
     */
    public function codeAgent(array $options = [])
    {
        $workdir = $this->runtime->getWorkdir();
        if ($workdir === '') {
            $workdir = getcwd() === false ? '.' : (string) getcwd();
            $this->setWorkdir($workdir);
        }

        // 内置工具（读写改 + 搜索 + Bash）+ 代码结构索引
        $tools = \Ai\Agent\Tools\ClaudeCodeTools::all(['workdir' => $workdir]);
        if (empty($options['noIndex'])) {
            $tools['code_index'] = new \Ai\Agent\Tools\CodeIndexTool($workdir);
        }
        $this->setTools($tools);

        if (isset($options['permissionMode'])) {
            $this->setPermissionMode((string) $options['permissionMode']);
        }

        // 六个专职子 Agent，各自工具集已收窄
        $sam = new \Ai\Agent\SubAgent\SubAgentManager($this->ai);
        $sam->setWorkdir($workdir);
        $sam->setParentTools($tools);
        \Ai\Agent\SubAgent\BuiltinAgents::register(
            $sam,
            isset($options['agents']) && is_array($options['agents']) ? $options['agents'] : []
        );
        $this->setSubAgentManager($sam);

        // 验证器：语法 + 安全常开，测试命令给了才挂
        $this->useDefaultVerifiers([
            'test'     => isset($options['test']) ? (string) $options['test'] : '',
            'workdir'  => $workdir,
            'maxFiles' => isset($options['maxFiles']) ? (int) $options['maxFiles'] : 0,
            'maxLines' => isset($options['maxLines']) ? (int) $options['maxLines'] : 0,
        ]);

        // 计划与反思：让它能拆步骤、能在"以为做完了"的时候再检查一遍
        if ($this->runtime->getPlanManager() === null) {
            $this->setPlanManager(new \Ai\Agent\Planning\PlanManager());
        }
        if ($this->runtime->getReflectionManager() === null) {
            $this->enableReflection();
        }

        // 项目指令（CLAUDE.md / AGENTS.md）
        $this->loadInstructions($workdir);

        return $this;
    }

    /**
     * 最近一次运行的消息历史
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLastMessages()
    {
        return $this->lastMessages;
    }

    /**
     * 记录消息历史（run / ask 内部调用）
     *
     * @param array<int, array<string, mixed>> $messages
     * @return $this
     */
    protected function rememberMessages(array $messages)
    {
        $this->lastMessages = $messages;
        return $this;
    }

    /**
     * 启用验证闸门
     *
     * 闸门按任务类型选验证链：修 Bug 一套、加功能一套、重构一套、安全改动一套。
     * 过了才算这一步做完，没过就把失败信息交回给模型。
     *
     * ```php
     * $agent->useVerificationGate(\Ai\Agent\Verification\VerificationPolicy::bugFix());
     * $outcome = $agent->checkCompletion($result, '修复登录 401');
     * if (!$outcome['completed']) {
     *     $agent->ask($outcome['prompt']);   // 带着未达成的原因继续
     * }
     * ```
     *
     * @param \Ai\Agent\Verification\VerificationPolicy|null $policy 不给则按任务描述自动选
     * @return \Ai\Agent\Verification\VerificationGate
     */
    public function useVerificationGate($policy = null)
    {
        $vm = $this->runtime->getVerificationManager();
        if ($vm === null) {
            $vm = new \Ai\Agent\Verification\VerificationManager();
            $this->runtime->setVerificationManager($vm);
        }
        $gate = new \Ai\Agent\Verification\VerificationGate($vm, $policy);
        if ($this->eventCallback !== null) {
            $gate->onEvent($this->eventCallback);
        }
        $this->orchestrator()->setVerificationGate($gate);
        return $gate;
    }

    /**
     * 设置完成判据
     *
     * 不设置时用宽松判据。**不能因为模型说「完成了」就算完成**——
     * 判据把完成变成一组可检查的条件。
     *
     * @param \Ai\Agent\Orchestrator\CompletionCriteria|string[] $criteria 判据对象或判据名数组
     * @return $this
     */
    public function setCompletionCriteria($criteria)
    {
        if (is_array($criteria)) {
            $criteria = new \Ai\Agent\Orchestrator\CompletionCriteria($criteria);
        }
        $this->orchestrator()->setCriteria($criteria);
        return $this;
    }

    /**
     * 检查任务是否真的达成完成条件
     *
     * @param AgentResult $result
     * @param string $task 任务描述（用于自动选验证策略）
     * @param array<string, mixed> $context
     * @return array<string, mixed> completed / unmet / reasons / prompt / verification
     */
    public function checkCompletion($result, $task = '', array $context = [])
    {
        if (!isset($context['messages'])) {
            $context['messages'] = $this->lastMessages;
        }
        return $this->orchestrator()->checkCompletion($result, (string) $task, $context);
    }

    /**
     * 跨 Session 消息总线
     *
     * 后台 Agent 在另一个进程里跑完，得让主 Session 知道——那条消息必须落盘，
     * 内存里的队列另一个进程看不见。
     *
     * @param string $baseDir 落盘目录，空则纯内存（只在同进程内可用）
     * @return \Ai\Agent\Session\SessionBus
     */
    public function sessionBus($baseDir = '')
    {
        if ($this->sessionBus === null) {
            $this->sessionBus = new \Ai\Agent\Session\SessionBus((string) $baseDir);
        }
        return $this->sessionBus;
    }

    /**
     * 记忆整理器
     *
     * 不要让所有工具结果自动进记忆——先提候选，整理时去重筛选再写入。
     *
     * @return \Ai\Agent\Memory\MemoryConsolidator|null 未设置记忆管理器时返回 null
     */
    public function memoryConsolidator()
    {
        $mm = $this->runtime->getMemoryManager();
        if ($mm === null) {
            return null;
        }
        if ($this->consolidator === null) {
            $this->consolidator = new \Ai\Agent\Memory\MemoryConsolidator($mm);
        }
        return $this->consolidator;
    }

    /**
     * 组建一个多角色 Agent 团队
     *
     * 团队成员共享 Agent 当前的工具与工作目录，各自持有独立上下文。
     *
     * ```php
     * $team = $agent->team([
     *     \Ai\Agent\Team\AgentRole::developer(),
     *     \Ai\Agent\Team\AgentRole::tester(),
     * ]);
     * $team->pipeline('给 Auth 模块补测试', ['developer', 'tester']);
     * ```
     *
     * @param array<int, \Ai\Agent\Team\AgentRole|string> $roles
     * @param array<string, mixed> $options system / workdir / permission
     * @return \Ai\Agent\Team\AgentTeam
     */
    public function team(array $roles = [], array $options = [])
    {
        if (!isset($options['tools'])) {
            $options['tools'] = $this->runtime->getToolRegistry()->all();
        }
        if (!isset($options['workdir'])) {
            $options['workdir'] = $this->runtime->getWorkdir();
        }
        $team = new \Ai\Agent\Team\AgentTeam($this->ai, $options);
        foreach ($roles as $role) {
            $team->addMember($role);
        }
        return $team;
    }

    /**
     * 设置人工审批工作流
     *
     * 设置后可用 `submitForApproval()` 提交改动等待人工批准。
     *
     * @param \Ai\Agent\Approval\ApprovalWorkflow $workflow
     * @return $this
     */
    public function setApprovalWorkflow($workflow)
    {
        $this->runtime->setApprovalWorkflow($workflow);
        return $this;
    }

    /**
     * 启用人工审批（自动创建 ApprovalWorkflow）
     *
     * @param string $baseDir 审批请求落盘目录，空则只放内存
     * @param array<string, mixed> $options ttl / notifier / autoApprove
     * @return \Ai\Agent\Approval\ApprovalWorkflow
     */
    public function enableApproval($baseDir = '', array $options = [])
    {
        $workflow = new \Ai\Agent\Approval\ApprovalWorkflow((string) $baseDir, $options);
        $this->runtime->setApprovalWorkflow($workflow);
        return $workflow;
    }

    /**
     * 提交一份改动等待人工审批
     *
     * @param string $diff
     * @param array<string, mixed> $context summary / files
     * @return \Ai\Agent\Approval\ApprovalRequest|null 未启用审批时返回 null
     */
    public function submitForApproval($diff, array $context = [])
    {
        $workflow = $this->runtime->getApprovalWorkflow();
        return $workflow === null ? null : $workflow->submitForReview($diff, $context);
    }

    /**
     * 设置执行计划管理器
     *
     * @param \Ai\Agent\Planning\PlanManager $pm
     * @return $this
     */
    public function setPlanManager($pm)
    {
        $this->runtime->setPlanManager($pm);
        return $this;
    }

    /**
     * 设置计划存储目录（自动创建 PlanManager）
     *
     * 传空字符串则只放内存，进程结束即丢失。
     *
     * @param string $baseDir
     * @return $this
     */
    public function setPlanDir($baseDir)
    {
        $pm = new \Ai\Agent\Planning\PlanManager((string) $baseDir);
        $this->runtime->setPlanManager($pm);
        return $this;
    }

    /**
     * 为当前 Agent 创建并启用一个执行计划
     *
     * 计划摘要会在每轮迭代注入系统提示词，模型据此知道整体步骤走到哪一步。
     * 未设置过 PlanManager 时自动创建一个纯内存的。
     *
     * ```php
     * $plan = $agent->plan('给 Auth 模块补测试', [
     *     '阅读 src/Auth.php',
     *     '写 tests/AuthTest.php',
     *     '跑测试并修复',
     * ]);
     * ```
     *
     * @param string $goal
     * @param string[] $steps 预定义步骤，留空则生成只有目标、没有步骤的空计划
     * @return \Ai\Agent\Planning\Plan
     */
    public function plan($goal, array $steps = [])
    {
        $pm = $this->runtime->getPlanManager();
        if ($pm === null) {
            $pm = new \Ai\Agent\Planning\PlanManager();
            $this->runtime->setPlanManager($pm);
        }
        $plan = $pm->createPlan((string) $goal, $steps ? ['steps' => $steps] : []);
        $pm->start($plan->getId());
        $this->runtime->setPlanId($plan->getId());
        $this->runtime->setGoal((string) $goal);
        return $plan;
    }

    /**
     * 当前执行计划，未创建时返回 null
     *
     * @return \Ai\Agent\Planning\Plan|null
     */
    public function getPlan()
    {
        $pm = $this->runtime->getPlanManager();
        $planId = $this->runtime->getPlanId();
        if ($pm === null || $planId === '') {
            return null;
        }
        return $pm->getPlan($planId);
    }

    /**
     * 开启自我反思
     *
     * 开启后，模型在没有工具调用、准备结束时会先反思一次目标是否真的达成；
     * 未达成则把下一步建议回填给模型继续迭代，而不是就此收工。
     *
     * ```php
     * $agent->enableReflection(['maxRounds' => 5]);
     * $agent->setGoal('让 composer test 全部通过');
     * ```
     *
     * @param array<string, mixed> $options maxRounds / strategy / enabled
     * @return $this
     */
    public function enableReflection(array $options = [])
    {
        $rm = new \Ai\Agent\Reflection\ReflectionManager($options);
        $this->runtime->setReflectionManager($rm);
        return $this;
    }

    /**
     * 设置反思管理器
     *
     * @param \Ai\Agent\Reflection\ReflectionManager $rm
     * @return $this
     */
    public function setReflectionManager($rm)
    {
        $this->runtime->setReflectionManager($rm);
        return $this;
    }

    /**
     * 设置当前任务目标
     *
     * 反思据此判断"目标是否达成"；开启记忆检索后，也用它检索相关记忆。
     * 不设置时退回用首条用户消息当目标。
     *
     * @param string $goal
     * @return $this
     */
    public function setGoal($goal)
    {
        $this->runtime->setGoal((string) $goal);
        return $this;
    }

    /**
     * 设置技能管理器
     *
     * @param \Ai\Agent\Skill\SkillManager $sm
     * @return $this
     */
    public function setSkillManager($sm)
    {
        $this->runtime->setSkillManager($sm);
        return $this;
    }

    /**
     * 从目录加载技能并启用
     *
     * 快捷方式：创建 SkillManager，从指定目录加载 SKILL.md，
     * 注入到 Runtime。
     *
     * @param string|string[] $dirs 技能目录路径（单个或多个）
     * @return $this
     */
    public function loadSkills($dirs)
    {
        $sm = new \Ai\Agent\Skill\SkillManager();
        $list = is_array($dirs) ? $dirs : [(string) $dirs];
        foreach ($list as $dir) {
            $sm->loadFromDir((string) $dir);
        }
        $this->runtime->setSkillManager($sm);
        return $this;
    }

    /**
     * 发现目录下的技能（不预读正文）
     *
     * 与 `loadSkills()` 的区别：只解析 frontmatter 登记技能，正文等模型
     * `use_skill` 时再读盘。技能多、正文长时用它。
     *
     * @param string|string[] $dirs
     * @return string[] 发现的技能名
     */
    public function discoverSkills($dirs)
    {
        $sm = $this->runtime->getSkillManager();
        if ($sm === null) {
            $sm = new \Ai\Agent\Skill\SkillManager();
            $this->runtime->setSkillManager($sm);
        }
        $found = [];
        foreach (is_array($dirs) ? $dirs : [(string) $dirs] as $dir) {
            foreach ($sm->discover((string) $dir) as $name) {
                $found[] = $name;
            }
        }
        return $found;
    }

    /**
     * 按文件路径自动激活匹配的技能
     *
     * 匹配依据是 SKILL.md frontmatter 里的 `files` 通配符。
     *
     * @param string $path
     * @return string[] 被激活的技能名
     */
    public function activateSkillsForFile($path)
    {
        $sm = $this->runtime->getSkillManager();
        return $sm === null ? [] : $sm->activateForFile((string) $path);
    }

    /**
     * 设置指令管理器
     *
     * @param \Ai\Agent\Instruction\InstructionManager $im
     * @return $this
     */
    public function setInstructionManager($im)
    {
        $this->runtime->setInstructionManager($im);
        return $this;
    }

    /**
     * 从目录加载项目指令
     *
     * 快捷方式：创建 InstructionManager，从指定目录加载 CLAUDE.md / AGENTS.md，
     * 注入到 Runtime。
     *
     * @param string|string[] $dirs 目录路径（单个或多个，优先级递增）
     * @return $this
     */
    public function loadInstructions($dirs)
    {
        $im = new \Ai\Agent\Instruction\InstructionManager();
        $list = is_array($dirs) ? $dirs : [(string) $dirs];
        foreach ($list as $dir) {
            $im->loadFromTree((string) $dir);
        }
        $this->runtime->setInstructionManager($im);
        return $this;
    }

    /**
     * 设置 MCP 管理器
     *
     * @param \Ai\Agent\Mcp\McpManager $mm
     * @return $this
     */
    public function setMcpManager($mm)
    {
        $this->runtime->setMcpManager($mm);
        return $this;
    }

    /**
     * 从配置数组设置 MCP 服务器并启用
     *
     * 快捷方式：
     * ```php
     * $agent->setMcpServers([
     *     'filesystem' => [
     *         'command' => 'npx',
     *         'args'    => ['-y', '@modelcontextprotocol/server-fs', '/tmp'],
     *     ],
     * ]);
     * ```
     *
     * @param array<string, array{command: string, args?: string[], options?: array<string, mixed>}> $servers
     * @return $this
     */
    public function setMcpServers(array $servers)
    {
        $mm = new \Ai\Agent\Mcp\McpManager();
        $mm->addServers($servers);
        $this->runtime->setMcpManager($mm);
        return $this;
    }

    /**
     * 设置记忆管理器
     *
     * @param \Ai\Agent\Memory\MemoryManager $mm
     * @return $this
     */
    public function setMemoryManager($mm)
    {
        $this->runtime->setMemoryManager($mm);
        return $this;
    }

    /**
     * 设置记忆存储目录（自动创建 MemoryManager）
     *
     * 各作用域文件存放于 {baseDir}/{scope}.md
     *
     * @param string $baseDir
     * @return $this
     */
    public function setMemoryDir($baseDir)
    {
        $mm = new \Ai\Agent\Memory\MemoryManager((string) $baseDir);
        $this->runtime->setMemoryManager($mm);
        return $this;
    }

    /**
     * 设置检查点存储目录（自动创建 CheckpointManager）
     *
     * 每轮迭代结束后自动保存检查点，崩溃后可从最新检查点恢复。
     * 检查点按任务 ID 分组，保留最近 maxCheckpoints 个（默认 5）。
     *
     * @param string $baseDir
     * @param array<string, mixed> $options enabled / maxCheckpoints
     * @return $this
     */
    public function setCheckpointDir($baseDir, array $options = [])
    {
        $cm = new \Ai\Agent\Checkpoint\CheckpointManager((string) $baseDir, $options);
        $this->runtime->setCheckpointManager($cm);
        return $this;
    }

    /**
     * 从崩溃中恢复——加载最新检查点，继续执行
     *
     * 用法：
     * ```php
     * $agent->setCheckpointDir('/tmp/checkpoints');
     * $messages = $agent->recoverFromCrash('task_1');
     * if ($messages !== null) {
     *     $result = $agent->run($messages);
     * }
     * ```
     *
     * @param string $taskId 任务 ID
     * @return array<int, array<string, mixed>>|null 恢复后的消息，无可恢复的检查点时返回 null
     */
    public function recoverFromCrash($taskId)
    {
        return $this->runtime->recover((string) $taskId);
    }

    /**
     * 回答用户问题并恢复 Agent 执行
     *
     * @param string $questionId
     * @param string $answer
     * @param array<int, array<string, mixed>> $messages 当前上下文消息
     * @return \Ai\Agent\AgentResult
     */
    public function answerUser($questionId, $answer, array $messages)
    {
        return $this->runtime->answerUser($questionId, $answer, $messages);
    }

    /**
     * 设置子 Agent 管理器（启用 spawn_agent 工具）
     *
     * @param \Ai\Agent\SubAgent\SubAgentManager $sam
     * @return $this
     */
    public function setSubAgentManager($sam)
    {
        $this->runtime->setSubAgentManager($sam);
        $this->syncModelRouter();
        return $this;
    }

    /**
     * 设置任务管理器（启用任务生命周期跟踪）
     *
     * @param \Ai\Agent\Task\TaskManager|null $tm
     * @return $this
     */
    public function setTaskManager($tm)
    {
        $this->runtime->setTaskManager($tm);
        return $this;
    }

    /**
     * 设置任务 ID（关联当前执行的任务）
     *
     * @param string|null $taskId
     * @return $this
     */
    public function setTaskId($taskId)
    {
        $this->runtime->setTaskId($taskId);
        return $this;
    }

    /**
     * 批准权限请求并恢复 Agent 执行
     *
     * @param string $requestId
     * @param array<int, array<string, mixed>> $messages 当前上下文消息（从事件 data 里取）
     * @return AgentResult
     */
    public function approve($requestId, array $messages)
    {
        return $this->runtime->approve($requestId, $messages);
    }

    /**
     * 拒绝权限请求并恢复 Agent 执行
     *
     * @param string $requestId
     * @param string $reason
     * @param array<int, array<string, mixed>> $messages 当前上下文消息
     * @return AgentResult
     */
    public function deny($requestId, $reason, array $messages)
    {
        return $this->runtime->deny($requestId, $reason, $messages);
    }

    /**
     * @return string
     */
    public function lastText()
    {
        return $this->lastText;
    }

    /**
     * 运行循环
     *
     * @param array<mixed> $messages 初始消息（通常 [['role'=>'user','content'=>...]]）
     * @return void
     */
    public function run(array $messages)
    {
        // 委托给 AgentRuntime 执行
        $result = $this->runtime->run($messages);

        // 保持 lastText 兼容
        $this->lastText = $result->getText();
        $this->rememberMessages($messages);
    }

    /**
     * 获取内部的 AgentRuntime 实例（用于高级扩展）
     *
     * @return AgentRuntime
     */
    public function getRuntime()
    {
        return $this->runtime;
    }
}