<?php
namespace Ai\Agent\Loop;

use Ai\Agent\AgentContext;
use Ai\Agent\AgentResult;
use Ai\Agent\Budget\BudgetManager;
use Ai\Agent\Permission\PermissionResult;
use Ai\Agent\Tool\ParallelToolExecutor;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolExecutor;
use Ai\Agent\Tool\ToolRegistry;
use Ai\Agent\Tool\ToolResult;

/**
 * 循环控制器——Agent 自循环的核心
 *
 * 职责单一：驱动「模型决策 → 执行工具 → 回填结果 → 继续」的循环。
 * 不关心工具如何注册、消息如何裁剪、权限如何管理——那些由 AgentContext
 * 和外部组件处理。
 *
 * 循环流程：
 * ```text
 * while (true) {
 *     1. 调用 AI 模型
 *     2. 检查是否有工具调用
 *     3. 有 → 执行工具 → 回填 → 继续
 *     4. 无 → 返回最终结果
 *     5. 检查停止条件
 * }
 * ```
 */
class LoopController
{
    /** @var int 最大迭代次数 */
    protected $maxIter = 25;

    /** @var int 起始迭代序号（resume 时从上次暂停处继续） */
    protected $startIter = 0;

    /** @var LoopGuard|null 循环守卫 */
    protected $guard = null;

    /** @var bool 是否启用并行工具执行 */
    protected $parallelTools = false;

    /** @var callable|null 并行运行器 */
    protected $parallelRunner = null;

    /** @var int 工具执行超时秒数（0 不限制），透传给 ToolExecutor 与 ToolContext */
    protected $toolTimeout = 0;

    /** @var string[] 降级模型名，按优先级排列 */
    protected $fallbackModels = [];

    /** @var array<string, mixed>|null 待恢复的权限调用 */
    protected $pendingPermissionCall = null;

    /** @var string|null 待恢复的权限请求 ID */
    protected $pendingPermissionId = null;

    /** @var array<int, array<string, mixed>>|null 暂停时的上下文消息 */
    protected $pendingContextMessages = null;

    /**
     * @param int $maxIter 最大迭代次数
     */
    public function __construct($maxIter = 25)
    {
        $this->setMaxIter($maxIter);
        $this->guard = new LoopGuard();
    }

    /**
     * @param int $n
     * @return $this
     */
    public function setMaxIter($n)
    {
        $this->maxIter = max(1, (int) $n);
        return $this;
    }

    /**
     * @return int
     */
    public function getMaxIter()
    {
        return $this->maxIter;
    }

    /**
     * @return LoopGuard|null
     */
    public function getGuard()
    {
        return $this->guard;
    }

    /**
     * 收集检查点要额外保存的运行时状态
     *
     * 只存消息历史不够：小时级的长任务恢复回来，得知道计划走到第几步、
     * 工作区是什么分支、目标是什么，否则模型只能从消息里重新推断。
     *
     * 存的是快照而非对象引用——检查点要能整体 JSON 序列化落盘。
     *
     * @param AgentContext $context
     * @return array<string, mixed>
     */
    protected function checkpointExtra(AgentContext $context)
    {
        $extra = [];

        $goal = $context->getGoal();
        if ($goal !== '') {
            $extra['goal'] = $goal;
        }

        $plan = $context->getPlan();
        if ($plan !== null) {
            $extra['plan'] = $plan->toArray();
        }

        $wm = $context->getWorkspaceManager();
        if ($wm !== null) {
            $extra['workspace'] = [
                'dir'      => $wm->getWorkdir(),
                'branch'   => $wm->getBranch(),
                'modified' => $wm->getModified(),
            ];
        }

        $mm = $context->getMemoryManager();
        if ($mm !== null && $mm->isEnabled() && $mm->getBaseDir() !== '') {
            $extra['memory_dir'] = $mm->getBaseDir();
        }

        return $extra;
    }

    /**
     * 执行一次反思——判断任务目标是否真的达成
     *
     * 只在模型不再调用工具、准备结束时调用。未设置 ReflectionManager 或
     * 反思被停用时返回 null，循环行为与升级前完全一致。
     *
     * 目标取 `AgentContext::getGoal()`，未显式设置时退回首条用户消息——
     * 那通常就是用户交代的任务。
     *
     * @param AgentContext $context
     * @param int $iter 当前迭代序号（0 起）
     * @return \Ai\Agent\Reflection\ReflectionResult|null
     */
    protected function reflect(AgentContext $context, $iter)
    {
        $rm = $context->getReflectionManager();
        if ($rm === null || !$rm->isEnabled()) {
            return null;
        }

        $goal = $context->getGoal();
        if ($goal === '') {
            $goal = $this->firstUserText($context->getMessages());
        }
        if (trim($goal) === '') {
            return null;
        }

        return $rm->reflect($context->getMessages(), $goal, [
            'iteration'    => (int) $iter,
            'isFirstRound' => (int) $iter === 0,
        ]);
    }

    /**
     * 取首条用户消息的文本内容
     *
     * @param array<int, array<string, mixed>> $messages
     * @return string
     */
    protected function firstUserText(array $messages)
    {
        foreach ($messages as $msg) {
            if (!isset($msg['role']) || $msg['role'] !== 'user') {
                continue;
            }
            $content = isset($msg['content']) ? $msg['content'] : '';
            if (is_string($content)) {
                return $content;
            }
            if (is_array($content)) {
                foreach ($content as $block) {
                    if (isset($block['type'], $block['text']) && $block['type'] === 'text') {
                        return (string) $block['text'];
                    }
                }
            }
        }
        return '';
    }

    /**
     * 检测本次工具调用中是否有 ask_user，返回待回答的问题 ID
     *
     * ask_user 工具的 handler 会返回 JSON：{'error': false, 'questions': [{'id': ..., 'question': ..., 'options': [...], ...}]}
     * 检测到后 Agent 应暂停（WAITING_USER），由业务层 answerUser() 恢复。
     *
     * @param array<int, array<string, mixed>> $calls 被允许执行的工具调用
     * @param array<int, array<string, mixed>> $results 执行结果
     * @return string|null 待回答的问题 ID（首个），没有 ask_user 调用则返回 null
     */
    protected function detectAskUser(array $calls, array $results)
    {
        $askUserAssigned = false;
        foreach ($calls as $call) {
            if ((isset($call['name']) ? (string) $call['name'] : '') === 'ask_user') {
                $askUserAssigned = true;
                break;
            }
        }
        if (!$askUserAssigned) {
            return null;
        }

        // 尝试从结果中提取问题 ID（ask_user handler 返回的 JSON）
        foreach ($results as $r) {
            $content = isset($r['content']) ? (string) $r['content'] : '';
            $decoded = json_decode($content, true);
            if (is_array($decoded) && isset($decoded['questions']) && is_array($decoded['questions'])) {
                foreach ($decoded['questions'] as $q) {
                    if (is_array($q) && isset($q['question_id'])) {
                        return (string) $q['question_id'];
                    }
                    if (is_array($q) && isset($q['id'])) {
                        return (string) $q['id'];
                    }
                }
            }
        }
        return 'ask_user';  // 兜底：没解析出 ID 也用 'ask_user' 占位
    }

    /**
     * 从工具结果列表生成签名（用于结果级进展检测）
     *
     * @param array<int, array{type: string, content: string, is_error: bool}> $results
     * @return string
     */
    protected function signatureOfResults(array $results)
    {
        $parts = [];
        foreach ($results as $r) {
            $content = isset($r['content']) ? (string) $r['content'] : '';
            $isError = !empty($r['is_error']);
            // 截取前 200 字符作为签名（避免大结果导致误判）
            $parts[] = ($isError ? 'ERR:' : 'OK:') . mb_substr($content, 0, 200);
        }
        return implode('||', $parts);
    }

    /**
     * 启用并行工具执行
     *
     * @param bool $parallel
     * @return $this
     */
    public function setParallelTools($parallel = true)
    {
        $this->parallelTools = (bool) $parallel;
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
        $this->parallelRunner = is_callable($runner) ? $runner : null;
        return $this;
    }

    /**
     * 设置工具执行超时秒数（0 不限制）
     *
     * @param int $seconds
     * @return $this
     */
    public function setToolTimeout($seconds)
    {
        $this->toolTimeout = max(0, (int) $seconds);
        return $this;
    }

    /** @return int */
    public function getToolTimeout()
    {
        return $this->toolTimeout;
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
        return $this;
    }

    /** @return string[] */
    public function getFallbackModels()
    {
        return $this->fallbackModels;
    }

    /**
     * 创建一个摘要器闭包，用 AI 自身压缩历史消息
     *
     * @param \Ai\AI $ai
     * @return callable
     */
    protected function makeSummarizer($ai)
    {
        return function (array $messages, $taskHint) use ($ai) {
            $serialized = '';
            foreach ($messages as $i => $m) {
                $role = isset($m['role']) ? (string) $m['role'] : '';
                $content = $m['content'] ?? '';
                if (is_array($content)) {
                    $parts = [];
                    foreach ($content as $block) {
                        if (is_array($block) && isset($block['text'])) {
                            $parts[] = (string) $block['text'];
                        } elseif (is_array($block) && isset($block['type'])) {
                            $parts[] = '[' . $block['type'] . ']';
                        }
                    }
                    $content = implode(' ', $parts);
                }
                $serialized .= $role . ': ' . mb_substr((string) $content, 0, 500) . "\n";
                if ($i > 50) {
                    $serialized .= "... (" . (count($messages) - 50) . " more messages)\n";
                    break;
                }
            }

            $system = '你是一个对话摘要器。将以下 Agent 对话历史压缩成简洁的摘要，'
                . '保留：已完成的任务、关键发现、已修改的文件、当前状态。'
                . '忽略：工具调用细节、错误堆栈、临时输出。'
                . '用中文，控制在 500 字以内。';
            if ($taskHint !== '') {
                $system .= "\n\n任务背景：{$taskHint}";
            }

            try {
                $resp = $ai->chat([
                    'system'   => $system,
                    'messages' => [['role' => 'user', 'content' => '请压缩以下对话历史：\n\n' . $serialized]],
                    'max_tokens' => 1024,
                ]);
                return $resp->getContent();
            } catch (\Throwable $e) {
                return '';
            }
        };
    }

    /**
     * 运行主循环
     *
     * @param AgentContext $context
     * @return AgentResult
     */
    public function run(AgentContext $context)
    {
        $toolDefs = $context->toolDefs();
        $ai       = $context->getAI();
        $emit     = $context->getEmitter();
        $registry = $context->getToolRegistry();

        // 构造工具执行器，应用超时配置
        $executor = new ToolExecutor($registry);
        if ($this->toolTimeout > 0) {
            $executor->setExecutionTimeout($this->toolTimeout);
        }

        // 重置守卫
        if ($this->guard) {
            $this->guard->reset();
        }

        for ($iter = 0; $iter < $this->maxIter; $iter++) {
            $context->setIteration($iter + 1);
            $context->emit('thinking', ['iter' => $iter + 1]);

            // 每轮迭代构造 ToolContext，携带最新 agentId / iteration / sessionId
            $toolContext = new ToolContext([
                'workdir'   => $context->getWorkdir(),
                'sessionId' => $context->getSessionId(),
                'agentId'   => $context->getAgentId(),
                'iteration' => $iter + 1,
                'timeout'   => $this->toolTimeout,
                'emit'      => $emit ? function ($event) use ($emit) { $emit($event); } : null,
            ]);

            // 上下文压缩检查（Phase 4）
            $cm = $context->getContextManager();
            if ($cm) {
                $cm->setMessages($context->getMessages());
                if ($cm->shouldCompact()) {
                    $context->emit('context_compact', [
                        'tokens'    => $cm->tokenCount(),
                        'messages'  => count($cm->messages()),
                    ]);
                    // before_compact 钩子
                    $hooks = $context->getHooks();
                    if ($hooks && $hooks->hasBeforeCompact()) {
                        $hooks->triggerBeforeCompact($cm->tokenCount(), count($cm->messages()));
                    }
                    $summarizer = $this->makeSummarizer($ai);
                    $newMessages = $cm->compact($summarizer, $context->getSystem());
                    $context->setMessages($newMessages);
                    $context->emit('context_compact_done', [
                        'messages' => count($newMessages),
                    ]);
                    // after_compact 钩子
                    if ($hooks && $hooks->hasAfterCompact()) {
                        $hooks->triggerAfterCompact(count($newMessages));
                    }
                }
            }

            // 调用 AI 模型（带降级重试）
            $modelError = null;
            $modelAttempts = $this->fallbackModels;
            $resp = null;
            // 第 1 次用当前模型
            for ($mi = 0; $mi <= count($modelAttempts); $mi++) {
                if ($mi > 0) {
                    $fbModel = $modelAttempts[$mi - 1];
                    // 切换到降级模型
                    $context->emit('model_fallback', [
                        'model' => $fbModel,
                        'error' => $modelError ? $modelError->getMessage() : '',
                    ]);
                    $ai->setModel($fbModel);
                }
                try {
                    // 工作区状态注入（每次迭代刷新）
                    $systemPrompt = $context->getSystem();
                    $wm = $context->getWorkspaceManager();
                    if ($wm) {
                        $wm->refresh();
                        $wsContext = $wm->toContextString();
                        if ($wsContext !== '') {
                            $systemPrompt .= "\n\n<workspace>\n{$wsContext}\n</workspace>";
                        }
                    }
                    // 技能描述注入（默认只给名称和描述，完整内容通过 use_skill 加载）
                    $sm = $context->getSkillManager();
                    if ($sm && $sm->isEnabled() && $sm->count() > 0) {
                        $skillPrompt = $sm->toSystemPrompt();
                        if ($skillPrompt !== '') {
                            $systemPrompt .= "\n\n<skills>\n{$skillPrompt}\n</skills>";
                        }
                        // 技能知识（frontmatter 的 knowledge 字段，几行要点，不是完整正文）
                        $knowledge = $sm->knowledgeForPrompt();
                        if ($knowledge !== '') {
                            $systemPrompt .= "\n\n" . $knowledge;
                        }
                    }
                    // 项目指令注入（CLAUDE.md / AGENTS.md）
                    $im = $context->getInstructionManager();
                    if ($im && $im->isEnabled()) {
                        $instPrompt = $im->toSystemPrompt();
                        if ($instPrompt !== '') {
                            $systemPrompt .= "\n\n" . $instPrompt;
                        }
                    }
                    // 长期记忆注入：有任务目标时只注入相关记忆，否则注入全部
                    $mm = $context->getMemoryManager();
                    if ($mm && $mm->isEnabled()) {
                        $goal = $context->getGoal();
                        $memPrompt = $goal !== ''
                            ? $mm->forPromptRelevant($goal)
                            : $mm->forPrompt();
                        if ($memPrompt !== '') {
                            $systemPrompt .= "\n\n" . $memPrompt;
                        }
                    }
                    // 执行计划注入（当前步骤 + 整体进度）
                    $plan = $context->getPlan();
                    if ($plan !== null) {
                        $systemPrompt .= "\n\n<plan>\n" . $plan->toSummary() . "\n</plan>";
                    }
                    $modelParams = [
                        'system'   => $systemPrompt,
                        'messages' => $context->getMessages(),
                        'tools'    => $toolDefs,
                    ];
                    // before_model 钩子
                    $hooks = $context->getHooks();
                    if ($hooks && $hooks->hasBeforeModel()) {
                        $modelParams = $hooks->triggerBeforeModel($modelParams);
                    }
                    $resp = $ai->chat($modelParams);
                    // after_model 钩子
                    if ($hooks && $hooks->hasAfterModel()) {
                        $resp = $hooks->triggerAfterModel($resp);
                    }
                    break;  // 成功
                } catch (\Throwable $e) {
                    $modelError = $e;
                    // 继续尝试下一个降级模型
                }
            }
            if ($resp === null) {
                $context->emit('error', ['message' => $modelError ? $modelError->getMessage() : '模型调用失败']);
                return AgentResult::stopped(StopReason::MODEL_ERROR, '', [
                    'error' => $modelError ? $modelError->getMessage() : '模型调用失败',
                    'iterations' => $iter,
                ]);
            }

            $text      = $resp->getContent();
            $toolCalls = $resp->getToolCalls();

            // 预算检查（Phase 7）
            $budget = $context->getBudget();
            if ($budget) {
                $budget->record($resp->getUsage());
                if ($budget->exceeded()) {
                    $summary = $budget->summary();
                    $context->emit('error', ['message' => $summary['reason']]);
                    return AgentResult::stopped(StopReason::BUDGET_EXCEEDED, $text, [
                        'iterations' => $iter + 1,
                        'budget'     => $summary,
                    ]);
                }
            }

            // 记录 assistant 回合
            $context->appendAssistant($resp);

            if (trim($text) !== '') {
                $context->setLastText($text);
                $context->emit('agent_text', ['text' => $text]);
            }

            // 没有工具调用 → 反思一次，确认目标真的达成后才结束
            if (!$toolCalls) {
                $reflection = $this->reflect($context, $iter);
                if ($reflection !== null) {
                    $context->emit('reflection', [
                        'success'     => $reflection->isSuccess(),
                        'reason'      => $reflection->getReason(),
                        'next_action' => $reflection->getNextAction(),
                    ]);
                    if ($reflection->shouldContinue()) {
                        // 把反思结论作为用户消息回填，驱动下一轮继续执行
                        $context->appendUser($reflection->toPrompt());
                        continue;
                    }
                }
                $context->emit('done');
                $usage = $resp->getUsage();
                return AgentResult::done($text, [
                    'usage'      => $usage,
                    'iterations' => $iter + 1,
                ]);
            }

            // 执行工具调用
            // 1. 执行权限检查与守卫检测，过滤掉被拒绝的调用
            $allowedCalls = [];
            $deniedResults = [];
            $askPermission = false;
            foreach ($toolCalls as $call) {
                $name  = isset($call['name']) ? (string) $call['name'] : '';
                $input = isset($call['input']) && is_array($call['input']) ? $call['input'] : [];
                $toolCallId = isset($call['id']) ? (string) $call['id'] : '';

                // 设置当前 tool_use_id 到 context（事件里带 tool_call_id）和 ToolContext
                $context->setToolCallId($toolCallId);
                $context->emit('tool_call', ['name' => $name, 'input' => $input, 'tool_call_id' => $toolCallId]);
                $toolContext->setToolCallId($toolCallId);

                // 循环守卫检测
                if ($this->guard) {
                    $guardCheck = $this->guard->check($name, $input);
                    if (!$guardCheck['ok']) {
                        $context->emit('error', ['message' => '模型重复调用同一工具，已停止']);
                        return AgentResult::stopped(StopReason::NO_PROGRESS, $text, [
                            'iterations' => $iter + 1,
                            'hint'       => $this->guard->getHint(),
                        ]);
                    }
                }

                // 权限检查
                $perm = $context->getPermission();
                if ($perm) {
                    $toolObj = $registry->get($name);
                    if ($toolObj) {
                        $permResult = $perm->check($toolObj, $input, $toolContext);
                        if ($permResult->isDenied()) {
                            $context->emit('tool_error', ['name' => $name, 'message' => $permResult->getReason()]);
                            $deniedResults[] = [
                                'type'        => 'tool_result',
                                'tool_use_id' => isset($call['id']) ? (string) $call['id'] : '',
                                'content'     => 'ERROR: Permission denied — ' . $permResult->getReason(),
                                'is_error'    => true,
                            ];
                            continue;
                        }
                        if ($permResult->needsAsk()) {
                            $request = $permResult->getRequest();
                            $requestId = $request ? $request->getId() : '';
                            $context->emit('tool_permission', [
                                'name'       => $name,
                                'input'      => $input,
                                'prompt'     => $permResult->getReason(),
                                'request_id' => $requestId,
                            ]);
                            // 暂停 Agent，等待用户决策
                            // 将已收集到的结果一并返回，让上下文保持完整
                            if ($deniedResults) {
                                $context->appendToolResults($deniedResults);
                            }
                            // 记录当前 pending 的调用到上下文，供 resume 使用
                            $context->setPendingPermission($requestId, $call);
                            $context->emit('error', ['message' => '等待用户授权：' . $name]);
                            return AgentResult::stopped(StopReason::PERMISSION_DENIED, $text, [
                                'iterations'    => $iter + 1,
                                'permission'    => 'pending',
                                'request_id'    => $requestId,
                                'tool_name'     => $name,
                                'tool_input'    => $input,
                                'pending_call'  => $call,
                            ]);
                        }
                    }
                }

                $allowedCalls[] = $call;
            }

            // 如果有被拒绝的调用，先回填它们
            $results = $deniedResults;

            // 2. 执行允许的调用（并行或串行）
            if ($allowedCalls) {
                if ($this->parallelTools && count($allowedCalls) > 1) {
                    $parallelExec = new ParallelToolExecutor($registry);
                    if ($this->parallelRunner) {
                        $parallelExec->setParallelRunner($this->parallelRunner);
                    }
                    // 并行路径也走 after_tool 钩子
                    $parallelResults = $parallelExec->executeAll($allowedCalls, $toolContext);
                    $hooks = $context->getHooks();
                    if ($hooks && $hooks->hasAfterTool()) {
                        foreach ($parallelResults as $i => $pr) {
                            $callName = isset($allowedCalls[$i]['name']) ? (string) $allowedCalls[$i]['name'] : '';
                            $tr = new ToolResult([
                                'success' => empty($pr['is_error']),
                                'content' => $pr['content'] ?? '',
                                'error'   => empty($pr['is_error']) ? '' : ($pr['content'] ?? ''),
                            ]);
                            $tr = $hooks->triggerAfterTool($callName, $tr);
                            $parallelResults[$i]['content'] = (string) $tr;
                            $parallelResults[$i]['is_error'] = !$tr->isSuccess();
                        }
                    }
                    $results = array_merge($results, $parallelResults);
                } else {
                    // 顺序执行（带钩子）
                    foreach ($allowedCalls as $call) {
                        $callName = isset($call['name']) ? (string) $call['name'] : '';
                        $callInput = isset($call['input']) && is_array($call['input']) ? $call['input'] : [];

                        // before_tool 钩子：可短路
                        $hooks = $context->getHooks();
                        if ($hooks && $hooks->hasBeforeTool()) {
                            $hookResult = $hooks->triggerBeforeTool($callName, $callInput, $toolContext);
                            if ($hookResult instanceof ToolResult) {
                                $result = $hookResult;
                                $isError = !$result->isSuccess();
                                $out = (string) $result;
                                if ($isError) {
                                    $context->emit('tool_error', ['name' => $callName, 'message' => $out]);
                                }
                                $results[] = [
                                    'type'        => 'tool_result',
                                    'tool_use_id' => isset($call['id']) ? (string) $call['id'] : '',
                                    'content'     => $out,
                                    'is_error'    => $isError,
                                ];
                                continue;
                            }
                        }

                        $result = $executor->execute($call, $toolContext);

                        // after_tool 钩子
                        if ($hooks && $hooks->hasAfterTool()) {
                            $result = $hooks->triggerAfterTool($callName, $result);
                        }

                        $isError = !$result->isSuccess();
                        $out     = (string) $result;
                        if ($isError) {
                            $context->emit('tool_error', ['name' => $callName, 'message' => $out]);
                        }
                        $results[] = [
                            'type'        => 'tool_result',
                            'tool_use_id' => isset($call['id']) ? (string) $call['id'] : '',
                            'content'     => $out,
                            'is_error'    => $isError,
                        ];
                    }
                }
            }

            // after_tool_batch 钩子（整批工具执行完成后、回填之前）
            $hooks = $context->getHooks();
            if ($hooks && $hooks->hasAfterToolBatch()) {
                $results = $hooks->triggerAfterToolBatch($results);
            }

            // 结果级进展检测（Progress Guard）：工具结果连续相同 → 无进展
            if ($this->guard && $results) {
                $resultSig = $this->signatureOfResults($results);
                $progressCheck = $this->guard->checkResult('_results', $resultSig);
                if (!$progressCheck['ok']) {
                    $context->emit('error', ['message' => '工具结果连续相同，判定无进展']);
                    return AgentResult::stopped(StopReason::NO_PROGRESS, $text, [
                        'iterations' => $iter + 1,
                        'hint'       => $progressCheck['hint'],
                    ]);
                }
            }

            // 检测 ask_user 工具调用 → 暂停等待用户回答（在回填前检测，避免结果进入上下文）
            $askUserId = $this->detectAskUser($allowedCalls, $results);
            if ($askUserId !== null) {
                $context->emit('error', ['message' => '等待用户回答问题']);
                return AgentResult::stopped(StopReason::WAITING_USER, $text, [
                    'iterations'    => $iter + 1,
                    'question_id'   => $askUserId,
                    'tool_name'     => 'ask_user',
                ]);
            }

            // 验证环节（Verification）：工具执行后自动验证，失败信息回填给模型
            $vm = $context->getVerificationManager();
            if ($vm && $vm->isEnabled() && $allowedCalls) {
                foreach ($allowedCalls as $call) {
                    $callName = isset($call['name']) ? (string) $call['name'] : '';
                    $callInput = isset($call['input']) && is_array($call['input']) ? $call['input'] : [];
                    if (!$vm->hasVerification($callName)) {
                        continue;
                    }
                    $verificationResults = $vm->verify($callName, $callInput);
                    foreach ($verificationResults as $vr) {
                        if (!$vr->isPassed()) {
                            $label = $vr->getVerifierName() !== ''
                                ? $vr->getVerifierName()
                                : $vr->getCommand();
                            $context->emit('tool_error', [
                                'name'    => $callName,
                                'message' => "验证失败：{$label} — {$vr->getError()}",
                            ]);
                            $failedBlock = [
                                'type'     => 'tool_result',
                                'tool_use_id' => isset($call['id']) ? (string) $call['id'] : '',
                                'content'  => "VERIFICATION FAILED: {$label}\n{$vr->getError()}",
                                'is_error' => true,
                            ];
                            // 尽量并入该调用已有的 tool_result（同一 tool_use_id），
                            // 避免产生引用不到 tool_use 块的独立结果导致协议拒绝
                            $merged = false;
                            foreach ($results as $i => $r) {
                                if (isset($r['tool_use_id'])
                                    && (string) $r['tool_use_id'] === $failedBlock['tool_use_id']) {
                                    $results[$i]['content'] = (string) $r['content']
                                        . "\n\nVERIFICATION FAILED: {$label}\n{$vr->getError()}";
                                    $results[$i]['is_error'] = true;
                                    $merged = true;
                                    break;
                                }
                            }
                            if (!$merged) {
                                $results[] = $failedBlock;
                            }
                        }
                    }
                }
            }

            // 回填工具结果（只要有结果就回填）
            $context->appendToolResults($results);

            // 检查点保存（每轮迭代后，用于崩溃恢复）
            $cpm = $context->getCheckpointManager();
            if ($cpm && $cpm->isEnabled()) {
                $cpId = $context->getCheckpointId();
                if ($cpId !== '') {
                    $cpm->save($cpId, $iter + 1, $context->getMessages(), $this->checkpointExtra($context));
                }
            }
        }

        // 已达最大迭代次数
        $context->emit('error', ['message' => '已达最大迭代步数（' . $this->maxIter . '）']);
        return AgentResult::stopped(StopReason::MAX_ITER, $context->getLastText(), [
            'iterations' => $this->maxIter,
        ]);
    }
}