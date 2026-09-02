<?php
namespace Ai\Agent\Reflection;

/**
 * ReflectionManager——反思管理器
 *
 * 让 Agent 不再执行一次就结束。在工具执行、结果回填后，检查目标是否完成：
 * 未完成 → 继续 Loop（分析原因、再次修改、重新测试）
 * 已完成 → 结束 Loop
 *
 * 反思流程：
 * ```
 * Tool Result
 *   ↓
 * Reflection
 *   ↓
 * 检查目标是否完成
 *   ↓
 * 如果未完成 → 继续 Loop
 * 如果已完成 → 结束 Loop
 * ```
 *
 * 用法：
 * ```php
 * $rm = new ReflectionManager();
 * $result = $rm->reflect($messages, '修复用户登录问题');
 * if ($result->shouldContinue()) {
 *     // 继续下一轮迭代
 * }
 * ```
 */
class ReflectionManager
{
    /** @var bool 是否启用反思 */
    protected $enabled = true;

    /** @var int 最大反思轮数（防止无限循环） */
    protected $maxRounds = 10;

    /** @var callable|null 自定义反思策略 function(array $messages, string $goal): ReflectionResult */
    protected $strategy = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        if (isset($options['enabled'])) {
            $this->enabled = (bool) $options['enabled'];
        }
        if (isset($options['maxRounds'])) {
            $this->maxRounds = (int) $options['maxRounds'];
        }
        if (isset($options['strategy'])) {
            $this->strategy = $options['strategy'];
        }
    }

    /**
     * 执行反思。
     *
     * 根据消息历史和任务目标，判断目标是否完成。
     * 默认使用基于规则的分析（检查消息中是否包含完成标记），
     * 也可通过 setStrategy 注入模型驱动的反思策略。
     *
     * @param array<int, array<string, mixed>> $messages 消息历史
     * @param string $goal 任务目标
     * @param array<string, mixed> $context 额外上下文
     * @return ReflectionResult
     */
    public function reflect(array $messages, $goal, array $context = [])
    {
        if (!$this->enabled) {
            return ReflectionResult::completed('反思已禁用');
        }

        // 检查最大轮数
        $round = isset($context['iteration']) ? (int) $context['iteration'] : 0;
        if ($round >= $this->maxRounds) {
            return ReflectionResult::completed('已达到最大反思轮数限制');
        }

        // 使用自定义策略
        if ($this->strategy) {
            return call_user_func($this->strategy, $messages, $goal, $context);
        }

        // 默认规则策略
        return $this->defaultReflect($messages, $goal, $context);
    }

    /**
     * 默认反思策略——基于规则的简单分析
     *
     * 检查：
     * 1. 最后一条消息是否来自 assistant 且包含"完成"、"已解决"等标记
     * 2. 是否有工具执行错误
     * 3. 根据迭代次数判断
     *
     * @param array<int, array<string, mixed>> $messages
     * @param string $goal
     * @param array<string, mixed> $context
     * @return ReflectionResult
     */
    protected function defaultReflect(array $messages, $goal, array $context = [])
    {
        $lastAssistant = null;
        $hasError = false;
        $errorMessages = [];
        $toolCallCount = 0;

        // 从后往前扫描消息
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $msg = $messages[$i];
            $role = isset($msg['role']) ? $msg['role'] : '';

            if ($role === 'assistant' && $lastAssistant === null) {
                $lastAssistant = $msg;
            }

            // 检查 tool_result 错误
            if ($role === 'tool' && isset($msg['content'])) {
                $content = $msg['content'];
                if (is_string($content) && (
                    stripos($content, 'error') !== false
                    || stripos($content, 'failed') !== false
                    || stripos($content, 'exception') !== false
                    || stripos($content, 'Parse error') !== false
                    || stripos($content, 'Fatal error') !== false
                )) {
                    $hasError = true;
                    $errorMessages[] = substr($content, 0, 200);
                }
            }

            // 统计 tool_use
            if ($role === 'assistant' && isset($msg['content'])) {
                $content = $msg['content'];
                if (is_array($content)) {
                    foreach ($content as $block) {
                        if (isset($block['type']) && $block['type'] === 'tool_use') {
                            $toolCallCount++;
                        }
                    }
                }
            }
        }

        // 1. 检查最后一条 assistant 消息是否包含完成标记
        if ($lastAssistant !== null) {
            $lastText = $this->extractText($lastAssistant);
            if ($this->containsCompletionMarkers($lastText)) {
                return ReflectionResult::completed('Agent 已确认目标完成');
            }
        }

        // 2. 检查是否有工具执行错误
        if ($hasError && $toolCallCount > 0) {
            return ReflectionResult::continuing(
                '工具执行出错，需要继续修复',
                '分析错误并修复',
                ['errors' => $errorMessages]
            );
        }

        // 3. 如果当前轮还没有工具调用，可能只是对话阶段
        if ($toolCallCount === 0) {
            // 不是失败，但也不是完成——让模型继续
            if (isset($context['isFirstRound']) && $context['isFirstRound']) {
                return ReflectionResult::continuing(
                    'Agent 尚未开始执行工具，需要继续',
                    '开始执行具体操作'
                );
            }
        }

        // 4. 默认：没有明确的完成标记，也没有错误，继续执行
        $round = isset($context['iteration']) ? (int) $context['iteration'] : 0;
        if ($round < 2) {
            return ReflectionResult::continuing(
                '任务执行中，尚未达到目标',
                '继续执行下一步'
            );
        }

        // 多轮以后且没有明确信号，认为完成
        return ReflectionResult::completed('已执行多轮，未发现问题，默认完成');
    }

    /**
     * 判断是否应继续循环
     *
     * @param ReflectionResult $result
     * @return bool
     */
    public function shouldContinue(ReflectionResult $result)
    {
        return $result->shouldContinue();
    }

    /**
     * 获取下一步建议
     *
     * @param ReflectionResult $result
     * @return string|null
     */
    public function getNextAction(ReflectionResult $result)
    {
        return $result->getNextAction();
    }

    /**
     * @return bool
     */
    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * @param bool $enabled
     * @return $this
     */
    public function setEnabled($enabled)
    {
        $this->enabled = (bool) $enabled;
        return $this;
    }

    /**
     * @return int
     */
    public function getMaxRounds()
    {
        return $this->maxRounds;
    }

    /**
     * @param int $maxRounds
     * @return $this
     */
    public function setMaxRounds($maxRounds)
    {
        $this->maxRounds = $maxRounds;
        return $this;
    }

    /**
     * @param callable|null $strategy
     * @return $this
     */
    public function setStrategy($strategy)
    {
        $this->strategy = $strategy;
        return $this;
    }

    /**
     * 从消息中提取文本内容
     *
     * @param array<string, mixed> $message
     * @return string
     */
    protected function extractText(array $message)
    {
        if (!isset($message['content'])) {
            return '';
        }
        $content = $message['content'];
        if (is_string($content)) {
            return $content;
        }
        if (is_array($content)) {
            $texts = [];
            foreach ($content as $block) {
                if (isset($block['type']) && $block['type'] === 'text') {
                    $texts[] = isset($block['text']) ? $block['text'] : '';
                }
            }
            return implode(' ', $texts);
        }
        return '';
    }

    /**
     * 检查文本是否包含完成标记
     *
     * @param string $text
     * @return bool
     */
    protected function containsCompletionMarkers($text)
    {
        $markers = [
            '任务完成',
            '目标已完成',
            '已完成',
            '已解决',
            '完成所有任务',
            '全部完成',
            'task complete',
            'all done',
            'completed successfully',
            '问题已解决',
            '修复完成',
            'Done',
        ];
        foreach ($markers as $marker) {
            if (mb_stripos($text, $marker) !== false) {
                return true;
            }
        }
        return false;
    }
}