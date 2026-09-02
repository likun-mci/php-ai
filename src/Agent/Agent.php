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