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
