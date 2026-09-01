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
                    $summarizer = $this->makeSummarizer($ai);
                    $newMessages = $cm->compact($summarizer, $context->getSystem());
                    $context->setMessages($newMessages);
                    $context->emit('context_compact_done', [
                        'messages' => count($newMessages),
                    ]);
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
                    $modelParams = [
                        'system'   => $context->getSystem(),
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

            // 没有工具调用 → 正常结束
            if (!$toolCalls) {
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

                $context->emit('tool_call', ['name' => $name, 'input' => $input]);

                // 设置当前 tool_use_id 到 ToolContext
                $toolCallId = isset($call['id']) ? (string) $call['id'] : '';
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

            // 回填工具结果（只要有结果就回填）
            $context->appendToolResults($results);
        }

        // 已达最大迭代次数
        $context->emit('error', ['message' => '已达最大迭代步数（' . $this->maxIter . '）']);
        return AgentResult::stopped(StopReason::MAX_ITER, $context->getLastText(), [
            'iterations' => $this->maxIter,
        ]);
    }
}