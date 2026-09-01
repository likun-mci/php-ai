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
 * 用法：
 * ```php
 * $sam = new SubAgentManager($ai);
 * $sam->register('code-reviewer', [
 *     'description' => '审查代码质量',
 *     'prompt'      => '你是代码审查专家...',
 *     'tools'       => [new ReadFileTool($pathSafety)],
 * ]);
 *
 * // 获取 spawn_agent 工具定义（主 Agent 注册用）
 * $tools['spawn_agent'] = $sam->getToolDef();
 *
 * // 获取 spawn_agent 的工具定义给 AI 模型
 * $toolDefs[] = $sam->getToolSchema();
 * ```
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
     * 注册子 Agent
     *
     * @param string $name 标识名
     * @param array<string, mixed> $config description / prompt / system / tools / max_iter
     * @return $this
     */
    public function register($name, array $config = [])
    {
        $this->agents[(string) $name] = new SubAgentDefinition((string) $name, $config);
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
                . '执行完毕后返回结果摘要。当前可用的子 Agent：' . implode(', ', $agentNames),
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

            $def = $self->get($agentName);
            if ($def === null) {
                return 'ERROR: 子 Agent "' . $agentName . '" 不存在';
            }
            if ($task === '') {
                return 'ERROR: 任务描述不能为空';
            }

            // 创建子 Agent 的独立运行时
            // 核心原则：子 Agent 权限 ⊆ 父 Agent 权限
            // 用父权限管理器创建子运行时的权限，子 Agent 不能超越父权限
            $subRuntime = new AgentRuntime($self->ai);
            $subRuntime->setSystem($def->getSystemPrompt());
            $subRuntime->setMaxIter($def->getMaxIter());
            $subRuntime->setWorkdir($self->workdir);

            // 权限继承：子 Agent 不能超越父 Agent 的权限范围
            $parentPm = $self->parentPermission;
            if ($parentPm) {
                $subRuntime->setPermission($parentPm);
            } else {
                // 父权限未设置时走 manual 模式——子 Agent 同样受权限系统约束
                $subRuntime->setPermissionMode('manual');
            }

            $tools = $def->getTools();
            if ($tools) {
                $subRuntime->setTools($tools);
            }

            // 运行子 Agent
            $subResult = $subRuntime->run([
                ['role' => 'user', 'content' => $task],
            ]);

            if ($subResult->isDone()) {
                return json_encode([
                    'status'  => 'completed',
                    'summary' => $subResult->getText(),
                    'iterations' => $subResult->getIterations(),
                ], JSON_UNESCAPED_UNICODE);
            }

            return json_encode([
                'status'  => 'stopped',
                'reason'  => $subResult->getStopReason(),
                'summary' => $subResult->getText(),
                'iterations' => $subResult->getIterations(),
            ], JSON_UNESCAPED_UNICODE);
        };
    }
}