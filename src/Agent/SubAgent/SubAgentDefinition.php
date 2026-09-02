<?php
namespace Ai\Agent\SubAgent;

/**
 * 子 Agent 定义
 *
 * 注册一个可由主 Agent 通过 spawn_agent 工具调用、或由编排层自动委派的子 Agent。
 * 每个子 Agent 有独立的系统提示词、工具、模型与上下文。
 *
 * 完整配置（与 Claude Code 的 Subagent 配置模型对齐）：
 *
 * ```php
 * $sam->register('reviewer', [
 *     'description'     => '代码审查与安全审查',
 *     'prompt'          => '你是代码审查者……',
 *     'tools'           => ['read_file' => …, 'grep' => …],
 *     'disallowedTools' => ['write_file', 'edit_file'],   // 只做减法
 *     'model'           => 'claude-sonnet-5',             // 该角色单独用的模型
 *     'permissionMode'  => 'auto',
 *     'maxTurns'        => 15,
 *     'skills'          => ['php-development'],
 *     'mcpServers'      => ['fs'],
 *     'hooks'           => $hooks,
 *     'memory'          => '/var/data/memory/reviewer',
 *     'background'      => false,
 *     'isolation'       => 'worktree',
 * ]);
 * ```
 *
 * **权限只减不增**：`tools` 与 `disallowedTools` 都只能在父 Agent 已有的范围内收窄。
 * 子 Agent 配置里写了父 Agent 没有的工具，那个工具也不会凭空出现——
 * 见 `SubAgentManager::resolveTools()`。
 *
 * ## 跨平台：每个角色可以挂在不同平台的接口上
 *
 * `model` 旁边可以直接写连接信息，让 coder 走 DeepSeek、reviewer 走 Kimi、
 * planner 走 OpenAI——三家各自的 Key 与地址：
 *
 * ```php
 * $sam->register('coder',    ['model' => 'deepseek-chat',   'api_key' => $dsKey]);
 * $sam->register('reviewer', ['model' => 'moonshot-v1-32k', 'api_key' => $kimiKey]);
 * $sam->register('planner',  ['model' => 'gpt-4o',          'api_key' => $oaKey]);
 * ```
 *
 * 可写的连接键见 `$connectionKeys`：`api_key` / `base_url` / `endpoint` /
 * `endpoint_models` / `protocol` / `platform` / `headers` / `organization` /
 * `project_id` / `extra_body`，也可以整块塞进 `connection`。
 *
 * **只要写了其中任何一个，父 Agent 的连接信息就整份不再继承**——这是有意的：
 * 继承一半（比如带上父 Agent 的 `base_url`）会把 Kimi 的模型名发到 DeepSeek 的地址上。
 * 反过来，一个连接键都不写时行为与旧版一致：完全沿用父 Agent 的连接，
 * 只换模型名——OpenRouter / 自建网关那种「一把 Key 打所有模型」的用法不受影响。
 *
 * 已经有现成的 `AI` 实例时，直接给 `ai`，优先级最高：
 *
 * ```php
 * $sam->register('reviewer', ['ai' => $kimiAi, 'description' => '代码审查']);
 * ```
 */
class SubAgentDefinition
{
    /** @var string 名称（主 Agent 用此标识调用） */
    protected $name;

    /** @var string 描述（告诉主 Agent 这个子 Agent 做什么，也是自动委派的匹配依据） */
    protected $description;

    /** @var string 系统提示词 */
    protected $systemPrompt;

    /** @var array<string, mixed> 工具定义 */
    protected $tools = [];

    /** @var string[] 禁用的工具名——只做减法，不能借此获得父 Agent 没有的工具 */
    protected $disallowedTools = [];

    /** @var string 该子 Agent 使用的模型，空则沿用父 Agent 的模型 */
    protected $model = '';

    /**
     * 可写在子 Agent 配置里的连接键
     *
     * 「连接」= 决定这次请求打到哪个平台、哪个地址、用哪把钥匙的信息。
     * 与 temperature 那类生成参数分开：生成参数照常从父 Agent 继承，
     * 连接信息一旦被覆盖就整份改用子 Agent 自己的。
     *
     * @var string[]
     */
    public static $connectionKeys = [
        'api_key', 'base_url', 'endpoint', 'endpoint_models',
        'protocol', 'platform', 'headers', 'organization', 'project_id', 'extra_body',
    ];

    /** @var array<string, mixed> 该子 Agent 独有的连接配置，空则沿用父 Agent */
    protected $connection = [];

    /** @var \Ai\AI|null 直接指定的 AI 实例，优先级高于 model / connection */
    protected $ai = null;

    /** @var string 权限模式，空则继承父 Agent */
    protected $permissionMode = '';

    /** @var int 最大迭代次数 */
    protected $maxIter = 25;

    /** @var string[] 该子 Agent 可用的技能名 */
    protected $skills = [];

    /** @var string[] 该子 Agent 可用的 MCP 服务器名 */
    protected $mcpServers = [];

    /** @var \Ai\Agent\Hooks\AgentHooks|null 该子 Agent 独立的钩子 */
    protected $hooks = null;

    /** @var string 该子 Agent 的记忆目录，空则不挂记忆 */
    protected $memoryDir = '';

    /** @var bool 是否后台运行（主 Agent 不等待） */
    protected $background = false;

    /** @var string 隔离模式：''（无）| 'worktree' */
    protected $isolation = '';

    /** @var array<string, mixed> 额外配置 */
    protected $extra = [];

    /**
     * @param string $name
     * @param array<string, mixed> $config
     */
    public function __construct($name, array $config = [])
    {
        $this->name = (string) $name;
        $this->description = isset($config['description']) ? (string) $config['description'] : '';
        $this->systemPrompt = isset($config['prompt']) ? (string) $config['prompt'] : '';
        if (isset($config['system'])) {
            $this->systemPrompt = (string) $config['system'];
        }
        $this->tools = isset($config['tools']) && is_array($config['tools']) ? $config['tools'] : [];

        // max_iter 与 maxTurns 是同一个东西，两种写法都收
        $this->maxIter = isset($config['max_iter']) ? (int) $config['max_iter'] : 25;
        if (isset($config['maxTurns'])) {
            $this->maxIter = (int) $config['maxTurns'];
        }
        $this->maxIter = max(1, $this->maxIter);

        foreach (['disallowedTools', 'skills', 'mcpServers'] as $key) {
            if (isset($config[$key]) && is_array($config[$key])) {
                $this->$key = array_values(array_map('strval', $config[$key]));
            }
        }
        foreach (['model', 'permissionMode', 'isolation'] as $key) {
            if (isset($config[$key])) {
                $this->$key = (string) $config[$key];
            }
        }
        if (isset($config['memory'])) {
            $this->memoryDir = (string) $config['memory'];
        }
        if (isset($config['hooks'])) {
            $this->hooks = $config['hooks'];
        }
        $this->background = !empty($config['background']);
        $this->extra = isset($config['extra']) && is_array($config['extra']) ? $config['extra'] : [];

        // 连接信息：平铺写（'api_key' => ...）与整块写（'connection' => [...]）都收，
        // 后者优先——整块写通常是从平台配置表里取出来的一份完整连接
        foreach (self::$connectionKeys as $key) {
            if (array_key_exists($key, $config)) {
                $this->connection[$key] = $config[$key];
            }
        }
        if (isset($config['connection']) && is_array($config['connection'])) {
            $this->connection = array_merge($this->connection, $config['connection']);
        }
        if (isset($config['ai']) && $config['ai'] instanceof \Ai\AI) {
            $this->ai = $config['ai'];
        }
    }

    /**
     * 该子 Agent 独有的连接配置
     *
     * 空数组表示「沿用父 Agent 的连接」。非空时父 Agent 的连接信息整份不继承，
     * 详见类文档。
     *
     * @return array<string, mixed>
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * 有没有指定自己的连接
     *
     * @return bool
     */
    public function hasConnection()
    {
        return $this->connection !== [];
    }

    /**
     * 直接指定的 AI 实例，没有则返回 null
     *
     * @return \Ai\AI|null
     */
    public function getAi()
    {
        return $this->ai;
    }

    /** @return bool */
    public function isBackground() { return $this->background; }

    /** @return string */
    public function getName() { return $this->name; }
    /** @return string */
    public function getDescription() { return $this->description; }
    /** @return string */
    public function getSystemPrompt() { return $this->systemPrompt; }
    /** @return array<string, mixed> */
    public function getTools() { return $this->tools; }
    /** @return int */
    public function getMaxIter() { return $this->maxIter; }
    /** @return array<string, mixed> */
    public function getExtra() { return $this->extra; }

    /**
     * 禁用的工具名
     *
     * @return string[]
     */
    public function getDisallowedTools()
    {
        return $this->disallowedTools;
    }

    /**
     * 指定工具是否被该子 Agent 禁用
     *
     * @param string $toolName
     * @return bool
     */
    public function isToolDisallowed($toolName)
    {
        return in_array((string) $toolName, $this->disallowedTools, true);
    }

    /**
     * 该子 Agent 使用的模型，空则沿用父 Agent
     *
     * @return string
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * 权限模式，空则继承父 Agent
     *
     * @return string
     */
    public function getPermissionMode()
    {
        return $this->permissionMode;
    }

    /**
     * @return string[]
     */
    public function getSkills()
    {
        return $this->skills;
    }

    /**
     * @return string[]
     */
    public function getMcpServers()
    {
        return $this->mcpServers;
    }

    /**
     * @return \Ai\Agent\Hooks\AgentHooks|null
     */
    public function getHooks()
    {
        return $this->hooks;
    }

    /**
     * 记忆目录，空则该子 Agent 不挂记忆
     *
     * @return string
     */
    public function getMemoryDir()
    {
        return $this->memoryDir;
    }

    /**
     * 隔离模式：'' 或 'worktree'
     *
     * @return string
     */
    public function getIsolation()
    {
        return $this->isolation;
    }

    /**
     * 是否要求 git worktree 隔离
     *
     * @return bool
     */
    public function isWorktreeIsolated()
    {
        return $this->isolation === 'worktree';
    }

    /**
     * 导出配置（用于日志与后台任务传参）
     *
     * `tools` 与 `hooks` 是对象，不放进来——这个结构要能 JSON 序列化。
     * 连接信息只列键名，**不含 api_key 的值**——transcript 会落盘。
     *
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'name'            => $this->name,
            'description'     => $this->description,
            'prompt'          => $this->systemPrompt,
            'toolNames'       => array_keys($this->tools),
            // 只列键名：这个结构会进 transcript 与后台任务参数，api_key 不能跟着落盘
            'connectionKeys'  => array_keys($this->connection),
            'ownAi'           => $this->ai !== null,
            'disallowedTools' => $this->disallowedTools,
            'model'           => $this->model,
            'permissionMode'  => $this->permissionMode,
            'maxTurns'        => $this->maxIter,
            'skills'          => $this->skills,
            'mcpServers'      => $this->mcpServers,
            'memory'          => $this->memoryDir,
            'background'      => $this->background,
            'isolation'       => $this->isolation,
        ];
    }
}
