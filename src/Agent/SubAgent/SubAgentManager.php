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

            $def = $self->get($agentName);
            if ($def === null) {
                return 'ERROR: 子 Agent "' . $agentName . '" 不存在';
            }
            if ($task === '') {
                return 'ERROR: 任务描述不能为空';
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
        $subRuntime = new AgentRuntime($this->ai);
        $subRuntime->setSystem($def->getSystemPrompt());
        $subRuntime->setMaxIter($def->getMaxIter());
        $subRuntime->setWorkdir($this->workdir);

        // 权限继承：子 Agent 不能超越父 Agent 的权限范围
        $parentPm = $this->parentPermission;
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

        $start = microtime(true);
        $messages = [['role' => 'user', 'content' => $task]];
        $subResult = $subRuntime->run($messages);

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
        return $runId;
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
        return json_encode($run, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 获取一次子 Agent 运行的完整 transcript
     *
     * @param string $runId
     * @return array<string, mixed>|null
     */
    public function getTranscript($runId)
    {
        return isset($this->runs[$runId]) ? $this->runs[$runId] : null;
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