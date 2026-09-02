<?php
namespace Ai\Agent\SubAgent;

use Ai\Agent\AgentRuntime;
use Ai\Agent\Permission\PermissionManager;
use Ai\AI;

/**
 * 子 Agent 管理器
 *
 * 管理子 Agent 注册表，并提供一个 spawn_agent 工具定义，
 * 让主 Agent 可以在运行时派生子 Agent 执行独立任务。
 *
 * 每个子 Agent 运行在独立的 AgentRuntime 中，拥有隔离的上下文，
 * 不会导致主 Agent 的上下文膨胀。
 *
 * 完整 transcript（P0-5）：
 *   每次 spawn 的完整消息历史、迭代次数、停止原因、最终结果都被记录，
 *   可通过 getTranscript() / recentRuns() 查询，与主 transcript 分离。
 *
 * 后台模式（P0-4）：
 *   spawn_agent 工具支持 background 参数。为 true 时：
 *   - 已注入 background runner（Swoole / Workerman / 队列 Worker）→ 非阻塞执行，
 *     工具立即返回 task_id，主 Agent 继续；结果完成后通过 getResult() 获取
 *   - 未注入 runner → 降级为同步执行（仍记录完整 transcript）
 *
 * 用法：
 * ```php
 * $sam = new SubAgentManager($ai);
 * $sam->register('code-reviewer', [
 *     'description' => '审查代码质量',
 *     'prompt'      => '你是代码审查专家...',
 *     'tools'       => [new ReadFileTool($pathSafety)],
 * ]);
 *
 * // 注入后台运行器（可选，协程/队列环境用）
 * $sam->setBackgroundRunner(function ($task) { /* 异步执行，返回结果数组 *\/ });
 *
 * // 获取 spawn_agent 工具定义（主 Agent 注册用）
 * $tools['spawn_agent'] = $sam->getToolDef();
 * ```
 *
 * 多平台（P0-6）：
 *   每个子 Agent 可以挂在不同平台的接口上——coder 用 DeepSeek、reviewer 用 Kimi、
 *   planner 用 OpenAI，三家各自的 Key 与地址。写法有三种，优先级从高到低：
 *
 *   1. `'ai' => $someAiInstance` —— 直接给一个配好的 AI 实例
 *   2. `'model' => 'moonshot-v1-32k', 'api_key' => $kimiKey` —— 连接信息写在定义里
 *   3. `setPlatformConfig('moonshot', ['api_key' => $kimiKey])` —— 平台配置表，
 *      按模型名自动匹配；ModelRouter 选出来的模型也走这条，不必逐个角色重复写 Key
 *
 *   子 Agent 拿到的是**独立的 AI 实例**（从父实例 clone 而来，transport 与流式回调
 *   跟着走），父 Agent 的模型与连接不会被改动。
 */
class SubAgentManager
{
    /** @var AI */
    protected $ai;

    /** @var array<string, SubAgentDefinition> */
    protected $agents = [];

    /** @var PermissionManager|null 父权限（子 Agent 继承，且不允许超越父权限） */
    protected $parentPermission = null;

    /** @var string */
    protected $workdir = '';

    /** @var callable|null 后台运行器 */
    protected $backgroundRunner = null;

    /** @var array<string, array<string, mixed>> 已完成的子 Agent 运行记录（transcript） */
    protected $runs = [];

    /** @var int 运行自增序号 */
    protected $runCounter = 0;

    /** @var array<string, mixed> 父 Agent 的工具集——子 Agent 的工具只能是它的子集 */
    protected $parentTools = [];

    /** @var \Ai\Agent\Skill\SkillManager|null 父 Agent 的技能管理器 */
    protected $parentSkills = null;

    /** @var \Ai\Agent\Mcp\McpManager|null 父 Agent 的 MCP 管理器 */
    protected $parentMcp = null;

    /** @var string transcript 落盘目录，空则只留在内存 */
    protected $transcriptDir = '';

    /** @var array<string, array<string, mixed>> 平台 / 模型名 => 连接配置 */
    protected $platformConfigs = [];

    /** @var \Ai\Agent\Orchestrator\ModelRouter|null 模型路由器，定义里没写 model 时问它 */
    protected $modelRouter = null;

    /** @var callable|array<string, mixed>|null 路由上下文补充（预算、优先级等） */
    protected $routeContext = null;

    /** @var array<string, AI> 派生出的子 AI 实例缓存，键为 '角色名|模型名' */
    protected $aiCache = [];

    /**
     * @param AI $ai 共享的 AI 实例（子 Agent 复用同一个 AI 配置）
     */
    public function __construct(AI $ai)
    {
        $this->ai = $ai;
    }

    /**
     * 设置父权限管理器（子 Agent 继承，且不允许超越）
     *
     * @param PermissionManager|null $pm
     * @return $this
     */
    public function setParentPermission($pm)
    {
        $this->parentPermission = $pm;
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
     * 登记一个平台的连接配置
     *
     * 子 Agent 只写 `'model' => 'moonshot-v1-32k'` 时，库按模型名推断出平台是
     * `moonshot`，再从这张表里取该平台的 Key 与地址。这样跨平台编排不必在每个
     * 角色里重复贴 Key，ModelRouter 动态选出来的模型也能自动配上正确的凭据。
     *
     * ```php
     * $sam->setPlatformConfig('deepseek', ['api_key' => $dsKey]);
     * $sam->setPlatformConfig('moonshot', ['api_key' => $kimiKey]);
     * $sam->setPlatformConfig('openai',   ['api_key' => $oaKey]);
     *
     * $sam->register('coder',    ['description' => '写代码', 'model' => 'deepseek-chat']);
     * $sam->register('reviewer', ['description' => '审代码', 'model' => 'moonshot-v1-32k']);
     * ```
     *
     * 键可以是平台名（`deepseek` / `moonshot` / `openai` ……，取值同
     * `AI::platformOfModel()`），也可以是**具体模型名**——模型名精确匹配优先，
     * 用于同一平台下不同模型要走不同网关的情况。
     *
     * @param string $platform 平台名或模型名
     * @param array<string, mixed> $config 连接配置，键见 SubAgentDefinition::$connectionKeys
     * @return $this
     */
    public function setPlatformConfig($platform, array $config)
    {
        $this->platformConfigs[(string) $platform] = $config;
        $this->aiCache = [];   // 连接变了，之前派生的实例作废
        return $this;
    }

    /**
     * 批量登记平台连接配置
     *
     * @param array<string, array<string, mixed>> $configs 平台名 => 连接配置
     * @return $this
     */
    public function setPlatformConfigs(array $configs)
    {
        foreach ($configs as $platform => $config) {
            if (is_array($config)) {
                $this->setPlatformConfig($platform, $config);
            }
        }
        return $this;
    }

    /**
     * 已登记的平台连接配置
     *
     * @return array<string, array<string, mixed>>
     */
    public function getPlatformConfigs()
    {
        return $this->platformConfigs;
    }

    /**
     * 注入模型路由器
     *
     * 子 Agent 定义里没写 `model` 时，由它按角色 / 任务复杂度 / 预算选一个。
     * 定义里写死了 `model` 的以定义为准——显式配置不该被启发式覆盖。
     *
     * @param \Ai\Agent\Orchestrator\ModelRouter|null $router
     * @return $this
     */
    public function setModelRouter($router)
    {
        $this->modelRouter = $router;
        $this->aiCache = [];
        return $this;
    }

    /**
     * @return \Ai\Agent\Orchestrator\ModelRouter|null
     */
    public function getModelRouter()
    {
        return $this->modelRouter;
    }

    /**
     * 补充路由上下文（预算余量、优先级等）
     *
     * ModelRouter 会看 `budget_left` / `priority`，但这些只有调用方知道。
     * 传数组是固定值，传闭包则每次路由前调用一次取实时值：
     *
     * ```php
     * $sam->setRouteContext(function ($def, $task) use ($budget) {
     *     return ['budget_left' => $budget->remainingRatio()];
     * });
     * ```
     *
     * @param callable|array<string, mixed>|null $context
     * @return $this
     */
    public function setRouteContext($context)
    {
        $this->routeContext = $context;
        return $this;
    }

    /**
     * 定下这次运行用哪个模型
     *
     * 定义里写死的优先，否则问路由器；都没有则返回空串（沿用父 Agent 的模型）。
     *
     * @param SubAgentDefinition $def
     * @param string $task
     * @return string
     */
    public function resolveModel(SubAgentDefinition $def, $task = '')
    {
        $model = $def->getModel();
        if ($model !== '' || $this->modelRouter === null) {
            return $model;
        }

        $context = ['agent' => $def->getName(), 'task' => (string) $task];
        if ($this->routeContext !== null) {
            $extra = is_callable($this->routeContext)
                ? call_user_func($this->routeContext, $def, (string) $task)
                : $this->routeContext;
            if (is_array($extra)) {
                $context = array_merge($context, $extra);
            }
        }

        $routed = $this->modelRouter->route($context);
        return is_string($routed) ? $routed : '';
    }

    /**
     * 取该子 Agent 该用的 AI 实例
     *
     * 没有任何模型 / 连接要求时直接返回父实例（与旧版行为一致）；有要求时派生一个
     * 独立实例并缓存——**不改动父实例**，所以子 Agent 跑完父 Agent 的模型不会被换掉，
     * 并行执行时也不会互相踩。
     *
     * @param SubAgentDefinition $def
     * @param string $model 本次运行的模型（路由器选的），空则用定义里的
     * @return AI
     */
    public function aiFor(SubAgentDefinition $def, $model = '')
    {
        $own = $def->getAi();
        if ($own !== null) {
            return $own;
        }

        $model = (string) $model !== '' ? (string) $model : $def->getModel();
        if ($model === '' && !$def->hasConnection()) {
            return $this->ai;
        }

        $key = $def->getName() . '|' . $model;
        if (!isset($this->aiCache[$key])) {
            $this->aiCache[$key] = $this->deriveAi($def, $model);
        }
        return $this->aiCache[$key];
    }

    /**
     * 派生一个子 AI 实例
     *
     * 连接信息（打到哪个平台、哪个地址、用哪把钥匙）与生成参数（temperature 之类）
     * 分开处理：生成参数照常从父 Agent 继承；连接信息**只要子 Agent 自己给了，
     * 父 Agent 的就整份丢弃**。半继承是这里最危险的做法——留下父 Agent 的
     * `base_url`，Kimi 的模型名就会被发到 DeepSeek 的地址上，报回来的是
     * 「模型不存在」，没人会往连接信息上想。
     *
     * @param SubAgentDefinition $def
     * @param string $model
     * @return AI
     */
    protected function deriveAi(SubAgentDefinition $def, $model)
    {
        $conn = $def->getConnection();

        // 定义里没写连接，就按模型名去平台配置表里找
        if ($conn === [] && $model !== '') {
            $conn = $this->platformConfigFor($model);
        }

        $config = $conn === []
            ? $this->ai->getConfig()
            : array_merge($this->stripConnection($this->ai->getConfig()), $conn);

        // 模型名一律显式写死：父 Agent 可能是 setModel() 直接设的，
        // 那样 config['model'] 里躺着的还是构造时那个旧名字
        $name = $model;
        if ($name === '') {
            $current = $this->ai->model();
            $name = $current !== null ? $current->getName() : '';
        }
        if ($name !== '') {
            $config['model'] = $name;
        } else {
            unset($config['model']);
        }

        // clone 而非 new AI：父 Agent 注入的 transport、流式回调要跟着走
        $sub = clone $this->ai;
        $sub->replaceConfig($config);
        return $sub;
    }

    /**
     * 按模型名找平台连接配置
     *
     * 先按模型名精确匹配（同平台不同模型走不同网关的情况），再按推断出的平台名。
     *
     * @param string $model
     * @return array<string, mixed>
     */
    protected function platformConfigFor($model)
    {
        if ($this->platformConfigs === []) {
            return [];
        }
        $name = (string) $model;
        if (isset($this->platformConfigs[$name])) {
            return $this->platformConfigs[$name];
        }
        $platform = $this->ai->platformOfModel($name);
        return isset($this->platformConfigs[$platform]) ? $this->platformConfigs[$platform] : [];
    }

    /**
     * 去掉配置里的连接信息，只留生成参数
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function stripConnection(array $config)
    {
        foreach (SubAgentDefinition::$connectionKeys as $key) {
            unset($config[$key]);
        }
        return $config;
    }

    /**
     * 设置 transcript 落盘目录
     *
     * 不设置时 transcript 只在内存里，进程结束即丢——后台任务与崩溃恢复
     * 都需要它能被另一个进程读到，所以长任务场景必须配。
     *
     * @param string $dir
     * @return $this
     */
    public function setTranscriptDir($dir)
    {
        $this->transcriptDir = rtrim(str_replace('\\', '/', (string) $dir), '/');
        if ($this->transcriptDir !== '' && !is_dir($this->transcriptDir)) {
            @mkdir($this->transcriptDir, 0777, true);
        }
        return $this;
    }

    /**
     * @return string
     */
    public function getTranscriptDir()
    {
        return $this->transcriptDir;
    }

    /**
     * 续跑一个子 Agent 任务
     *
     * 拿回上一次的 transcript，把新指令接在后面继续跑。子 Agent 跑到一半
     * 被截断（达到迭代上限、权限被拒）时用它接着干，而不是从头再来一遍。
     *
     * @param string $runId 上一次的运行 ID
     * @param string $task 追加的指令，空则用原任务
     * @return string 新的运行 ID；原记录不存在返回空串
     */
    public function resume($runId, $task = '')
    {
        $record = $this->getTranscript($runId);
        if ($record === null) {
            return '';
        }

        $agentName = isset($record['agent']) ? (string) $record['agent'] : '';
        $def = $this->get($agentName);
        if ($def === null) {
            return '';
        }

        $previous = isset($record['summary']) ? (string) $record['summary'] : '';
        $original = isset($record['task']) ? (string) $record['task'] : '';
        $followUp = trim((string) $task) !== '' ? (string) $task : $original;

        $prompt = $original !== '' ? "原任务：{$original}\n" : '';
        if ($previous !== '') {
            $prompt .= "上一轮的结论：\n{$previous}\n\n";
        }
        $prompt .= "继续执行：{$followUp}";

        $newRunId = $this->runSync($agentName, $prompt);
        if (isset($this->runs[$newRunId])) {
            $this->runs[$newRunId]['resumed_from'] = (string) $runId;
            $this->persistRun($newRunId);
        }
        return $newRunId;
    }

    /**
     * transcript 落盘
     *
     * @param string $runId
     * @return void
     */
    protected function persistRun($runId)
    {
        if ($this->transcriptDir === '' || !isset($this->runs[$runId])) {
            return;
        }
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $runId);
        if ($safe === '') {
            return;
        }
        $json = json_encode($this->runs[$runId], JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            @file_put_contents($this->transcriptDir . '/' . $safe . '.json', $json, LOCK_EX);
        }
    }

    /**
     * 从磁盘读回一条 transcript
     *
     * @param string $runId
     * @return array<string, mixed>|null
     */
    public function loadTranscript($runId)
    {
        if ($this->transcriptDir === '') {
            return null;
        }
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $runId);
        $file = $this->transcriptDir . '/' . $safe . '.json';
        if ($safe === '' || !is_file($file)) {
            return null;
        }
        $json = @file_get_contents($file);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * 设置父 Agent 的工具集
     *
     * 子 Agent 的工具只能从这里面挑。子 Agent 配置里写了父 Agent 没有的工具，
     * 那个工具不会凭空出现——权限只减不增。
     *
     * @param array<string, mixed> $tools
     * @return $this
     */
    public function setParentTools(array $tools)
    {
        $this->parentTools = $tools;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParentTools()
    {
        return $this->parentTools;
    }

    /**
     * 设置父 Agent 的技能管理器（子 Agent 按 skills 配置挑子集）
     *
     * @param \Ai\Agent\Skill\SkillManager|null $sm
     * @return $this
     */
    public function setParentSkills($sm)
    {
        $this->parentSkills = $sm;
        return $this;
    }

    /**
     * 设置父 Agent 的 MCP 管理器
     *
     * @param \Ai\Agent\Mcp\McpManager|null $mm
     * @return $this
     */
    public function setParentMcp($mm)
    {
        $this->parentMcp = $mm;
        return $this;
    }

    /**
     * 解析子 Agent 实际可用的工具
     *
     * 三步收窄，每一步都只会让工具变少：
     *   1. 起点是子 Agent 自己声明的 tools；没声明就用父 Agent 的全集
     *   2. 与父 Agent 的工具集求交——父没有的一律去掉
     *   3. 去掉 disallowedTools
     *
     * @param SubAgentDefinition $def
     * @return array<string, mixed>
     */
    public function resolveTools(SubAgentDefinition $def)
    {
        $declared = $def->getTools();
        $tools = $declared ? $declared : $this->parentTools;

        // 与父工具集求交：父 Agent 没有的工具，子 Agent 声明了也拿不到
        if ($this->parentTools) {
            $intersected = [];
            foreach ($tools as $name => $tool) {
                $key = (string) $name;
                if (isset($this->parentTools[$key])) {
                    // 统一用父 Agent 那一份实例，避免子 Agent 传进来一个同名但行为不同的工具
                    $intersected[$key] = $this->parentTools[$key];
                }
            }
            $tools = $intersected;
        }

        foreach ($def->getDisallowedTools() as $disallowed) {
            unset($tools[$disallowed]);
        }
        return $tools;
    }

    /**
     * 按定义组装一个子 Agent 运行时
     *
     * 权限、工具、模型、平台、技能、MCP、钩子、记忆都在这里落位。
     * 核心不变式：**子 Agent 的能力永远是父 Agent 的子集**。
     *
     * @param SubAgentDefinition $def
     * @param string $workdir 覆盖工作目录（worktree 隔离时用）
     * @param string $model 本次运行的模型（路由器选的），空则用定义里的
     * @return AgentRuntime
     */
    public function buildRuntime(SubAgentDefinition $def, $workdir = '', $model = '')
    {
        // 模型 / 平台由 aiFor() 落位：需要换平台的子 Agent 拿到独立 AI 实例，
        // 其余的仍复用父实例
        $runtime = new AgentRuntime($this->aiFor($def, $model));
        $runtime->setSystem($def->getSystemPrompt());
        $runtime->setMaxIter($def->getMaxIter());
        $runtime->setWorkdir($workdir !== '' ? (string) $workdir : $this->workdir);

        // 权限继承：子 Agent 不能超越父 Agent 的权限范围
        if ($this->parentPermission) {
            $runtime->setPermission($this->parentPermission);
            // permissionMode 只能往严格的方向调；宽松方向的请求忽略
            $mode = $def->getPermissionMode();
            if ($mode !== '' && $this->isStricterMode($mode, $this->parentPermission->getMode())) {
                $runtime->setPermissionMode($mode);
            }
        } else {
            // 父权限未设置时走 manual——子 Agent 同样受权限系统约束
            $runtime->setPermissionMode($def->getPermissionMode() !== '' ? $def->getPermissionMode() : 'manual');
        }

        $tools = $this->resolveTools($def);
        if ($tools) {
            $runtime->setTools($tools);
        }

        // 技能：从父 Agent 的技能里挑子集
        $skills = $def->getSkills();
        if ($skills && $this->parentSkills !== null) {
            $sm = new \Ai\Agent\Skill\SkillManager();
            foreach ($skills as $skillName) {
                $skill = $this->parentSkills->get($skillName);
                if ($skill !== null) {
                    $sm->register($skill->getName(), [
                        'description'  => $skill->getDescription(),
                        'content'      => $skill->getContent(),
                        'allowedTools' => $skill->getAllowedTools(),
                        'path'         => $skill->getPath(),
                    ]);
                }
            }
            $runtime->setSkillManager($sm);
        }

        // MCP：父 Agent 已登记的服务器里挑子集
        if ($def->getMcpServers() && $this->parentMcp !== null) {
            $runtime->setMcpManager($this->parentMcp);
        }

        if ($def->getHooks() !== null) {
            $runtime->setHooks($def->getHooks());
        }

        $memoryDir = $def->getMemoryDir();
        if ($memoryDir !== '') {
            $runtime->setMemoryManager(new \Ai\Agent\Memory\MemoryManager($memoryDir));
        }

        return $runtime;
    }

    /**
     * 直接执行一段逻辑（保留给子类覆盖）
     *
     * 旧版靠「在共享的 AI 实例上临时 setModel 再切回来」实现子 Agent 换模型，
     * 那样只换得了模型名，api_key / base_url 还是父 Agent 的，跨平台必然 401。
     * 现在模型与平台都由 `aiFor()` 通过独立 AI 实例解决，这里不再需要切换，
     * 方法本身保留——已有子类可能覆盖了它。
     *
     * @param SubAgentDefinition $def
     * @param callable $callback
     * @return mixed $callback 的返回值
     */
    protected function withModel(SubAgentDefinition $def, callable $callback)
    {
        return call_user_func($callback);
    }

    /**
     * 判断一个权限模式是不是比另一个更严格
     *
     * 子 Agent 只能把权限收紧，不能放宽——`bypass` 的父 Agent 下面可以挂 `manual`
     * 的子 Agent，反过来不行。
     *
     * @param string $mode
     * @param string $parentMode
     * @return bool
     */
    protected function isStricterMode($mode, $parentMode)
    {
        // 由严到松
        $order = ['manual', 'plan', 'accept_edits', 'auto', 'dont_ask', 'bypass'];
        $a = array_search((string) $mode, $order, true);
        $b = array_search((string) $parentMode, $order, true);
        if ($a === false || $b === false) {
            return false;
        }
        return $a <= $b;
    }

    /**
     * 注入后台运行器（Swoole / Workerman / 队列 Worker 环境用）
     *
     * 签名：function (array $task): array
     *   $task = ['name'=>..., 'task'=>..., 'system'=>..., 'tools'=>..., 'max_iter'=>...]
     * 返回执行结果数组（status / summary / iterations / messages）
     *
     * @param callable|null $runner
     * @return $this
     */
    public function setBackgroundRunner($runner)
    {
        $this->backgroundRunner = is_callable($runner) ? $runner : null;
        return $this;
    }

    /**
     * 注册子 Agent
     *
     * @param string $name 标识名
     * @param array<string, mixed> $config description / prompt / system / tools / max_iter / background
     * @return $this
     */
    public function register($name, array $config = [])
    {
        $name = (string) $name;
        $this->agents[$name] = new SubAgentDefinition($name, $config);
        // 同名重注册时旧的派生实例作废，否则改了 api_key 也还在用旧连接
        foreach (array_keys($this->aiCache) as $key) {
            if (strpos($key, $name . '|') === 0) {
                unset($this->aiCache[$key]);
            }
        }
        return $this;
    }

    /**
     * 获取已注册的子 Agent 定义
     *
     * @param string $name
     * @return SubAgentDefinition|null
     */
    public function get($name)
    {
        return isset($this->agents[(string) $name]) ? $this->agents[(string) $name] : null;
    }

    /**
     * 全部已注册的子 Agent
     *
     * @return array<string, SubAgentDefinition>
     */
    public function all()
    {
        return $this->agents;
    }

    /**
     * 获取 spawn_agent 工具的描述（用于注入主 Agent 的 system prompt）
     *
     * @return string
     */
    public function toolDescription()
    {
        if (!$this->agents) {
            return '';
        }
        $desc = "你可以派生子 Agent 来并行执行独立任务。已注册的子 Agent：\n";
        foreach ($this->agents as $name => $def) {
            $desc .= "  - {$name}：" . $def->getDescription() . "\n";
        }
        return $desc;
    }

    /**
     * 获取 spawn_agent 工具的 schema（给 AI 模型注册用）
     *
     * @return array<string, mixed>
     */
    public function getToolSchema()
    {
        $agentNames = [];
        foreach ($this->agents as $name => $def) {
            $agentNames[] = $name;
        }

        return [
            'name'        => 'spawn_agent',
            'description' => '派生子 Agent 执行一个独立任务。子 Agent 有独立的上下文和工具，'
                . '执行完毕后返回结果摘要（含完整 transcript 的记录）。'
                . '设置 background=true 时后台执行，立即返回 task_id。'
                . '当前可用的子 Agent：' . implode(', ', $agentNames),
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'agent' => [
                        'type'        => 'string',
                        'description' => '要派发的子 Agent 名称',
                        'enum'        => $agentNames,
                    ],
                    'task' => [
                        'type'        => 'string',
                        'description' => '子 Agent 要执行的任务描述',
                    ],
                    'background' => [
                        'type'        => 'boolean',
                        'description' => '是否后台执行（true 时不阻塞主 Agent，返回 task_id）',
                        'default'     => false,
                    ],
                    'isolation' => [
                        'type'        => 'string',
                        'description' => '隔离模式："worktree" 时在独立 git worktree 中执行，完成后返回 diff',
                        'enum'        => ['worktree'],
                    ],
                ],
                'required' => ['agent', 'task'],
            ],
        ];
    }

    /**
     * 获取 spawn_agent 的 handler 闭包（注册到主 Agent 的工具）
     *
     * @return callable
     */
    public function getHandler()
    {
        $self = $this;
        return function (array $input) use ($self) {
            $agentName = isset($input['agent']) ? (string) $input['agent'] : '';
            $task = isset($input['task']) ? (string) $input['task'] : '';
            $background = !empty($input['background']);
            $isolation = isset($input['isolation']) ? (string) $input['isolation'] : '';

            $def = $self->get($agentName);
            if ($def === null) {
                return 'ERROR: 子 Agent "' . $agentName . '" 不存在';
            }
            if ($task === '') {
                return 'ERROR: 任务描述不能为空';
            }

            // 定义里声明的 background / isolation 是默认值，工具入参可以额外开启，但不能关闭——
            // 一个被配置成必须隔离的子 Agent，不该因为模型没传参就直接改到父工作区
            if ($def->isBackground()) {
                $background = true;
            }
            if ($def->isWorktreeIsolated()) {
                $isolation = 'worktree';
            }

            // Worktree 隔离模式
            if ($isolation === 'worktree') {
                $runId = $self->runSyncWithWorktree($agentName, $task);
                return $self->formatRunResult($runId);
            }

            // 后台模式且注入了 runner → 非阻塞执行
            if ($background && $self->backgroundRunner !== null) {
                $taskId = $self->registerPendingRun($agentName, $task);
                $payload = [
                    'name'     => $agentName,
                    'task'     => $task,
                    'system'   => $def->getSystemPrompt(),
                    'tools'    => $def->getTools(),
                    'max_iter' => $def->getMaxIter(),
                    'task_id'  => $taskId,
                ];
                try {
                    $runnerResult = call_user_func($self->backgroundRunner, $payload);
                    if (is_array($runnerResult)) {
                        $self->storeRunResult($taskId, $runnerResult);
                    }
                } catch (\Throwable $e) {
                    $self->storeRunResult($taskId, [
                        'status'  => 'failed',
                        'reason'  => 'runner_error',
                        'summary' => $e->getMessage(),
                    ]);
                }
                return json_encode([
                    'status'  => 'background',
                    'task_id' => $taskId,
                    'agent'   => $agentName,
                    'message' => '子 Agent 已在后台启动，可通过 getResult() 查询结果',
                ], JSON_UNESCAPED_UNICODE);
            }

            // 同步执行（记录完整 transcript）
            $runId = $self->runSync($agentName, $task);
            return $self->formatRunResult($runId);
        };
    }

    /**
     * 同步运行一个子 Agent，并记录完整 transcript
     *
     * @param string $agentName
     * @param string $task
     * @return string 运行记录 ID
     */
    public function runSync($agentName, $task)
    {
        $def = $this->get($agentName);
        if ($def === null) {
            $this->runCounter++;
            $runId = 'sub_' . $this->runCounter . '_' . dechex(time());
            $this->runs[$runId] = [
                'task_id'  => $runId,
                'agent'    => $agentName,
                'task'     => $task,
                'status'   => 'failed',
                'reason'   => 'unknown_agent',
                'summary'  => '子 Agent "' . $agentName . '" 不存在',
                'messages' => [],
                'iterations' => 0,
                'created_at' => time(),
                'updated_at' => time(),
            ];
            return $runId;
        }

        // 创建子 Agent 的独立运行时
        // 核心原则：子 Agent 权限 ⊆ 父 Agent 权限
        $model = $this->resolveModel($def, $task);
        $subRuntime = $this->buildRuntime($def, '', $model);

        $start = microtime(true);
        $messages = [['role' => 'user', 'content' => $task]];
        $subResult = $this->withModel($def, function () use ($subRuntime, $messages) {
            return $subRuntime->run($messages);
        });

        $this->runCounter++;
        $runId = 'sub_' . $this->runCounter . '_' . dechex(time());
        $this->runs[$runId] = [
            'task_id'  => $runId,
            'agent'    => $agentName,
            'task'     => $task,
            'status'   => $subResult->isDone() ? 'completed' : 'stopped',
            'reason'   => $subResult->getStopReason(),
            'summary'  => $subResult->getText(),
            'messages' => $subResult->getToolCalls() ?: [],
            'iterations' => $subResult->getIterations(),
            'duration_ms' => round((microtime(true) - $start) * 1000, 1),
            'created_at' => time(),
            'updated_at' => time(),
        ];
        $this->persistRun($runId);
        return $runId;
    }

    /**
     * 在独立 git worktree 中同步运行子 Agent
     *
     * 流程：
     *   1. git worktree add 创建隔离的工作目录
     *   2. 子 Agent 在 worktree 中执行任务
     *   3. git diff 捕获改动
     *   4. git worktree remove 清理
     *
     * @param string $agentName
     * @param string $task
     * @return string 运行记录 ID
     */
    protected function runSyncWithWorktree($agentName, $task)
    {
        $def = $this->get($agentName);
        if ($def === null) {
            $this->runCounter++;
            $runId = 'sub_' . $this->runCounter . '_' . dechex(time());
            $this->runs[$runId] = [
                'task_id'  => $runId,
                'agent'    => $agentName,
                'task'     => $task,
                'status'   => 'failed',
                'reason'   => 'unknown_agent',
                'summary'  => '子 Agent "' . $agentName . '" 不存在',
                'messages' => [],
                'iterations' => 0,
                'created_at' => time(),
                'updated_at' => time(),
            ];
            return $runId;
        }

        $workdir = $this->workdir;
        if ($workdir === '' || !is_dir($workdir . '/.git')) {
            $this->runCounter++;
            $runId = 'sub_' . $this->runCounter . '_' . dechex(time());
            $this->runs[$runId] = [
                'task_id'  => $runId,
                'agent'    => $agentName,
                'task'     => $task,
                'status'   => 'failed',
                'reason'   => 'no_git_repo',
                'summary'  => '当前目录不是 git 仓库，无法创建 worktree',
                'messages' => [],
                'iterations' => 0,
                'created_at' => time(),
                'updated_at' => time(),
            ];
            return $runId;
        }

        // 创建 worktree
        $worktreeDir = $workdir . '/.claude/worktrees/sub_' . dechex(time()) . '_' . mt_rand(1000, 9999);
        $this->runCommand('git worktree add ' . escapeshellarg($worktreeDir) . ' HEAD 2>/dev/null', $workdir);

        if (!is_dir($worktreeDir)) {
            $this->runCounter++;
            $runId = 'sub_' . $this->runCounter . '_' . dechex(time());
            $this->runs[$runId] = [
                'task_id'  => $runId,
                'agent'    => $agentName,
                'task'     => $task,
                'status'   => 'failed',
                'reason'   => 'worktree_create_failed',
                'summary'  => '无法创建 git worktree',
                'messages' => [],
                'iterations' => 0,
                'created_at' => time(),
                'updated_at' => time(),
            ];
            return $runId;
        }

        // 运行子 Agent（工作目录指向 worktree，父工作区不受影响）
        $model = $this->resolveModel($def, $task);
        $subRuntime = $this->buildRuntime($def, $worktreeDir, $model);

        $start = microtime(true);
        $messages = [['role' => 'user', 'content' => $task]];
        $subResult = $this->withModel($def, function () use ($subRuntime, $messages) {
            return $subRuntime->run($messages);
        });

        // 捕获 diff
        $diff = $this->runCommand('git diff HEAD -- :/ 2>/dev/null', $worktreeDir);
        $diffStat = $this->runCommand('git diff --stat HEAD -- :/ 2>/dev/null', $worktreeDir);

        // 清理 worktree
        $this->runCommand('git worktree remove --force ' . escapeshellarg($worktreeDir) . ' 2>/dev/null', $workdir);

        $this->runCounter++;
        $runId = 'sub_' . $this->runCounter . '_' . dechex(time());
        $this->runs[$runId] = [
            'task_id'    => $runId,
            'agent'      => $agentName,
            'task'       => $task,
            'status'     => $subResult->isDone() ? 'completed' : 'stopped',
            'reason'     => $subResult->getStopReason(),
            'summary'    => $subResult->getText(),
            'messages'   => $subResult->getToolCalls() ?: [],
            'iterations' => $subResult->getIterations(),
            'duration_ms' => round((microtime(true) - $start) * 1000, 1),
            'diff'       => $diff !== '' ? $diff : '',
            'diff_stat'  => $diffStat !== '' ? $diffStat : '',
            'created_at' => time(),
            'updated_at' => time(),
        ];
        $this->persistRun($runId);
        return $runId;
    }

    /**
     * 把某次 worktree 运行的改动应用到父工作区
     *
     * 子 Agent 在隔离的 worktree 里改完，diff 拿回来了，接下来要么合入要么丢弃。
     * 这个方法用 `git apply` 打补丁——不是 merge 分支，因为 worktree 里的改动
     * 通常没提交，没有 commit 可合。
     *
     * @param string $runId worktree 运行记录 ID
     * @param bool $check true 时只试打不真打（`git apply --check`）
     * @return array{applied: bool, reason: string} 应用结果
     */
    public function mergeWorktreeRun($runId, $check = false)
    {
        $record = $this->getTranscript($runId);
        if ($record === null) {
            return ['applied' => false, 'reason' => 'unknown_run'];
        }
        $diff = isset($record['diff']) ? (string) $record['diff'] : '';
        if (trim($diff) === '') {
            return ['applied' => false, 'reason' => 'empty_diff'];
        }
        if ($this->workdir === '' || !is_dir($this->workdir . '/.git')) {
            return ['applied' => false, 'reason' => 'no_git_repo'];
        }

        $patch = tempnam(sys_get_temp_dir(), 'php_ai_patch_');
        if ($patch === false || @file_put_contents($patch, $diff) === false) {
            return ['applied' => false, 'reason' => 'patch_write_failed'];
        }

        $flag = $check ? ' --check' : '';
        $output = [];
        $code = -1;
        @exec(
            'cd ' . escapeshellarg($this->workdir) . ' && git apply' . $flag . ' '
            . escapeshellarg($patch) . ' 2>&1',
            $output,
            $code
        );
        @unlink($patch);

        if ($code !== 0) {
            return ['applied' => false, 'reason' => 'apply_failed: ' . trim(implode(' ', $output))];
        }

        if (!$check && isset($this->runs[$runId])) {
            $this->runs[$runId]['merged'] = true;
            $this->runs[$runId]['updated_at'] = time();
            $this->persistRun($runId);
        }
        return ['applied' => true, 'reason' => $check ? 'check_passed' : 'applied'];
    }

    /**
     * 丢弃某次 worktree 运行的改动
     *
     * worktree 早在运行结束时就已经删掉了，这里只是把记录标成 discarded——
     * 明确留痕「这份 diff 被人看过并否决了」，比让它悬在记录里强。
     *
     * @param string $runId
     * @param string $reason
     * @return bool
     */
    public function discardWorktreeRun($runId, $reason = '')
    {
        if (!isset($this->runs[$runId])) {
            $loaded = $this->getTranscript($runId);
            if ($loaded === null) {
                return false;
            }
        }
        $this->runs[$runId]['discarded'] = true;
        $this->runs[$runId]['discard_reason'] = (string) $reason;
        $this->runs[$runId]['updated_at'] = time();
        $this->persistRun($runId);
        return true;
    }

    /**
     * 在指定目录下执行 shell 命令并返回 stdout
     *
     * @param string $command
     * @param string $cwd
     * @return string
     */
    protected function runCommand($command, $cwd)
    {
        $cwd = (string) $cwd;
        if ($cwd === '' || !is_dir($cwd)) {
            return '';
        }
        $output = [];
        $code = -1;
        $cmd = 'cd ' . escapeshellarg($cwd) . ' && ' . $command;
        exec($cmd, $output, $code);
        return implode("\n", $output);
    }

    /**
     * 注册一个后台待运行记录
     *
     * @param string $agentName
     * @param string $task
     * @return string 运行记录 ID
     */
    protected function registerPendingRun($agentName, $task)
    {
        $this->runCounter++;
        $runId = 'sub_' . $this->runCounter . '_' . dechex(time());
        $this->runs[$runId] = [
            'task_id'  => $runId,
            'agent'    => $agentName,
            'task'     => $task,
            'status'   => 'pending',
            'summary'  => '',
            'messages' => [],
            'iterations' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ];
        return $runId;
    }

    /**
     * 存储后台运行的执行结果
     *
     * @param string $runId
     * @param array<string, mixed> $result
     * @return void
     */
    public function storeRunResult($runId, array $result)
    {
        if (!isset($this->runs[$runId])) {
            return;
        }
        $this->runs[$runId]['status'] = isset($result['status']) ? (string) $result['status'] : 'stopped';
        $this->runs[$runId]['reason'] = isset($result['reason']) ? (string) $result['reason'] : '';
        $this->runs[$runId]['summary'] = isset($result['summary']) ? (string) $result['summary'] : '';
        if (isset($result['iterations'])) {
            $this->runs[$runId]['iterations'] = (int) $result['iterations'];
        }
        if (isset($result['messages']) && is_array($result['messages'])) {
            $this->runs[$runId]['messages'] = $result['messages'];
        }
        $this->runs[$runId]['updated_at'] = time();
    }

    /**
     * 格式化运行结果为工具返回的 JSON 字符串
     *
     * @param string $runId
     * @return string
     */
    protected function formatRunResult($runId)
    {
        $run = isset($this->runs[$runId]) ? $this->runs[$runId] : [
            'status'  => 'failed',
            'summary' => '运行记录不存在',
        ];
        $run['transcript_id'] = $runId;
        unset($run['messages']);  // 完整 transcript 不塞给模型，用 transcript_id 引用
        $encoded = json_encode($run, JSON_UNESCAPED_UNICODE);
        return $encoded !== false ? $encoded : '{"status":"failed","summary":"编码失败"}';
    }

    /**
     * 获取一次子 Agent 运行的完整 transcript
     *
     * @param string $runId
     * @return array<string, mixed>|null
     */
    public function getTranscript($runId)
    {
        if (isset($this->runs[$runId])) {
            return $this->runs[$runId];
        }
        // 内存里没有就找磁盘——后台任务的 transcript 是另一个进程写的
        $loaded = $this->loadTranscript($runId);
        if ($loaded !== null) {
            $this->runs[(string) $runId] = $loaded;
        }
        return $loaded;
    }

    /**
     * 获取一次子 Agent 运行的结果摘要
     *
     * @param string $runId
     * @return array<string, mixed>|null
     */
    public function getResult($runId)
    {
        $run = $this->getTranscript($runId);
        if ($run === null) {
            return null;
        }
        $out = $run;
        unset($out['messages']);
        return $out;
    }

    /**
     * 最近的子 Agent 运行记录
     *
     * @param int $limit
     * @return array<string, array<string, mixed>>
     */
    public function recentRuns($limit = 10)
    {
        $recent = array_slice($this->runs, -$limit, $limit, true);
        return $recent;
    }
}