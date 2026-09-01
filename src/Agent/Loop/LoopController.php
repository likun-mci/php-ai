<?php
namespace Ai\Agent\Loop;

use Ai\Agent\AgentContext;
use Ai\Agent\AgentResult;
use Ai\Agent\Budget\BudgetManager;
use Ai\Agent\Permission\PermissionResult;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolExecutor;
use Ai\Agent\Tool\ToolRegistry;

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

    /** @var LoopGuard|null 循环守卫 */
    protected $guard = null;

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

        // 构造工具执行器与上下文
        $executor     = new ToolExecutor($registry);
        $toolContext  = new ToolContext('', $emit ? function ($event) use ($emit) {
            $emit($event);
        } : null);

        // 重置守卫
        if ($this->guard) {
            $this->guard->reset();
        }

        for ($iter = 0; $iter < $this->maxIter; $iter++) {
            $context->setIteration($iter + 1);
            $context->emit('thinking', ['iter' => $iter + 1]);

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

            // 调用 AI 模型
            try {
                $resp = $ai->chat([
                    'system'   => $context->getSystem(),
                    'messages' => $context->getMessages(),
                    'tools'    => $toolDefs,
                ]);
            } catch (\Throwable $e) {
                $context->emit('error', ['message' => $e->getMessage()]);
                return AgentResult::stopped(StopReason::MODEL_ERROR, '', [
                    'error' => $e->getMessage(),
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
            $results = [];
            foreach ($toolCalls as $call) {
                $name  = isset($call['name']) ? (string) $call['name'] : '';
                $input = isset($call['input']) && is_array($call['input']) ? $call['input'] : [];

                $context->emit('tool_call', ['name' => $name, 'input' => $input]);

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
                            $results[] = [
                                'type'        => 'tool_result',
                                'tool_use_id' => isset($call['id']) ? (string) $call['id'] : '',
                                'content'     => 'ERROR: Permission denied — ' . $permResult->getReason(),
                                'is_error'    => true,
                            ];
                            continue;
                        }
                        if ($permResult->needsAsk()) {
                            $context->emit('tool_permission', ['name' => $name, 'input' => $input, 'prompt' => $permResult->getReason()]);
                        }
                    }
                }

                // 执行工具
                $result = $executor->execute($call, $toolContext);

                $isError = !$result->isSuccess();
                $out     = (string) $result;

                if ($isError) {
                    $context->emit('tool_error', ['name' => $name, 'message' => $out]);
                }

                $results[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => isset($call['id']) ? (string) $call['id'] : '',
                    'content'     => $out,
                    'is_error'    => $isError,
                ];
            }

            // 回填工具结果
            $context->appendToolResults($results);
        }

        // 已达最大迭代次数
        $context->emit('error', ['message' => '已达最大迭代步数（' . $this->maxIter . '）']);
        return AgentResult::stopped(StopReason::MAX_ITER, $context->getLastText(), [
            'iterations' => $this->maxIter,
        ]);
    }
}