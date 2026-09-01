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

    /**
     * @param AI $ai
     */
    public function __construct(AI $ai)
    {
        $this->ai = $ai;
        $this->runtime = new AgentRuntime($ai);
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
     * @param array<string, array{description?: string, input_schema?: array<mixed>, handler?: callable}> $tools
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
        $this->runtime->onEvent($emit);
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