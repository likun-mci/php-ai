<?php
namespace Ai\Agent\Loop;

use Ai\Agent\AgentContext;
use Ai\Agent\AgentResult;
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
                        // 无进展：给模型一个内部提示，停止当前循环
                        $context->emit('error', ['message' => '模型重复调用同一工具，已停止']);
                        return AgentResult::stopped(StopReason::NO_PROGRESS, $text, [
                            'iterations' => $iter + 1,
                            'hint'       => $this->guard->getHint(),
                        ]);
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