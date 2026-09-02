<?php
namespace Ai\Agent\Reflection;

use Ai\Helpers\Text;

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

        // 判据先跑：轮数上限这道闸曾经排在这之前，导致业务侧注入的自定义策略
        // 在轮数超限后根本不会被调用——排查时只会怀疑自己的策略写错了
        $result = $this->strategy !== null
            ? call_user_func($this->strategy, $messages, $goal, $context)
            : $this->defaultReflect($messages, $goal, $context);

        if (!$result instanceof ReflectionResult) {
            return ReflectionResult::completed('反思策略未返回 ReflectionResult，按完成处理');
        }

        // 轮数上限是最后一道闸：判据说还要继续，但反思已经做了这么多轮，
        // 再逼下去只是空转
        if ($result->shouldContinue() && $this->roundOf($context) >= $this->maxRounds) {
            return ReflectionResult::completed('已达到最大反思轮数限制', $result->getMetadata());
        }

        return $result;
    }

    /**
     * 取本次是第几轮反思
     *
     * `reflection_round` 是反思次数，`iteration` 是 Agent 循环的迭代号——两者语义不同。
     * 旧版把后者当成前者，于是「跑到第 10 轮迭代之后，反思一律判完成」，
     * 哪怕工具还在报错。没有 `reflection_round` 时才退回迭代号（兼容旧调用方）。
     *
     * @param array<string, mixed> $context
     * @return int
     */
    protected function roundOf(array $context)
    {
        if (isset($context['reflection_round'])) {
            return (int) $context['reflection_round'];
        }
        return isset($context['iteration']) ? (int) $context['iteration'] : 0;
    }

    /**
     * 默认反思策略——基于规则的简单分析
     *
     * 判据顺序：
     * 1. 最后一条 assistant 消息带完成标记 → 完成
     * 2. **最后一批**工具结果里还有报错 → 继续修（同一报错连撞两批则停手）
     * 3. 首轮且一个工具都没调过 → 继续
     * 4. 其余 → 完成
     *
     * 第 4 条曾经是「前两轮无条件判未达成」，于是任何两轮内收工的任务都被多逼一轮，
     * 回填的那句「任务执行中，尚未达到目标」不带任何任务信息，模型只会困惑地反问，
     * 而那句反问就成了调用方拿到的最终答案——真正的结论被顶掉。
     * 模型停止调用工具本来就是最可靠的完成信号，不该按轮次硬逼。
     *
     * @param array<int, array<string, mixed>> $messages
     * @param string $goal
     * @param array<string, mixed> $context
     * @return ReflectionResult
     */
    protected function defaultReflect(array $messages, $goal, array $context = [])
    {
        $lastAssistant = null;
        $toolCallCount = 0;
        $batches       = [];     // 工具结果批次，从后往前，只需要最近两批
        $pendingTool   = null;   // 正在收集的一批 role=tool 消息

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $msg     = $messages[$i];
            $role    = isset($msg['role']) ? $msg['role'] : '';
            $content = isset($msg['content']) ? $msg['content'] : null;

            // OpenAI 风格：一个工具结果一条 role=tool 消息，连续几条算同一批
            if ($role === 'tool') {
                if ($pendingTool === null) {
                    $pendingTool = [];
                }
                $text = $this->blockText($content);
                if ($this->looksLikeError($text)) {
                    $pendingTool[] = Text::cutChars($text, 200);
                }
                continue;
            }
            if ($pendingTool !== null) {
                $batches[]   = $pendingTool;
                $pendingTool = null;
            }

            if ($role === 'assistant') {
                if ($lastAssistant === null) {
                    $lastAssistant = $msg;
                }
                if (is_array($content)) {
                    foreach ($content as $block) {
                        if (is_array($block) && isset($block['type']) && $block['type'] === 'tool_use') {
                            $toolCallCount++;
                        }
                    }
                }
                continue;
            }

            // 库自己回填工具结果走的是 role=user + 一组 tool_result 块
            // （见 AgentContext::appendToolResults）。旧版只按 role=tool 的字符串去找，
            // 写入方和读取方对不上，那个分支一次也进不去——于是「工具明确报了错、
            // 模型却当作做完了」这一类恰好完全漏掉
            if ($role === 'user' && is_array($content) && count($batches) < 2) {
                $errors = $this->errorsInBatch($content);
                if ($errors !== null) {
                    $batches[] = $errors;
                }
            }
        }
        if ($pendingTool !== null) {
            $batches[] = $pendingTool;
        }

        // 1. 最后一条 assistant 消息明确说完成
        if ($lastAssistant !== null) {
            $lastText = $this->extractText($lastAssistant);
            if ($this->containsCompletionMarkers($lastText)) {
                return ReflectionResult::completed('Agent 已确认目标完成');
            }
        }

        // 2. 只看最后一批工具结果——早先的报错可能已经在后续轮次里修掉了，
        //    拿旧报错反复逼模型会一直空转到轮数上限
        $lastErrors = isset($batches[0]) ? $batches[0] : [];
        $prevErrors = isset($batches[1]) ? $batches[1] : [];
        if ($lastErrors) {
            // 同一个错误连着撞两批，说明这条路走不通，继续重试没有进展
            if ($prevErrors && $this->sameErrors($lastErrors, $prevErrors)) {
                return ReflectionResult::completed(
                    '同一错误反复出现，继续重试没有进展',
                    ['errors' => $lastErrors, 'stalled' => true]
                );
            }
            return ReflectionResult::continuing(
                '工具执行出错，需要继续修复',
                '分析错误并修复',
                ['errors' => $lastErrors]
            );
        }

        // 3. 一个工具都没调过：首轮、或压根还没有 assistant 回合，说明还没开始干活
        if ($toolCallCount === 0 && (!empty($context['isFirstRound']) || $lastAssistant === null)) {
            return ReflectionResult::continuing(
                'Agent 尚未开始执行工具，需要继续',
                '开始执行具体操作'
            );
        }

        // 4. 模型不再调工具，最后一批结果也没报错——这就是完成
        return ReflectionResult::completed('工具执行未报错，Agent 已停止调用工具');
    }

    /**
     * 取一条消息里最后一批工具结果的报错
     *
     * @param array<int, mixed> $content 消息的 content 块数组
     * @return string[]|null 该消息不含 tool_result 块时返回 null（不算一批）；
     *                       含 tool_result 但都成功时返回空数组
     */
    protected function errorsInBatch(array $content)
    {
        $isBatch = false;
        $errors  = [];

        foreach ($content as $block) {
            if (!is_array($block) || !isset($block['type']) || $block['type'] !== 'tool_result') {
                continue;
            }
            $isBatch = true;

            $text = $this->blockText(isset($block['content']) ? $block['content'] : '');

            // 库自己回填的结果一定带 is_error（由 ToolResult::isSuccess() 得出），以它为准。
            // 关键词扫描只留给调用方手工拼的、没有这个标志的消息——
            // 对库产出的结果做关键词扫描会误伤：读一个正文里出现 "error" 的文件就算报错了
            if (array_key_exists('is_error', $block)) {
                $isError = !empty($block['is_error']);
            } else {
                $isError = $this->looksLikeError($text);
            }

            if ($isError) {
                $errors[] = Text::cutChars($text, 200);
            }
        }

        return $isBatch ? $errors : null;
    }

    /**
     * 把 content 取成字符串（数组则序列化）
     *
     * @param mixed $content
     * @return string
     */
    protected function blockText($content)
    {
        if (is_string($content)) {
            return $content;
        }
        $encoded = json_encode($content, JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '';
    }

    /**
     * 文本看起来像不像报错（仅用于没有 is_error 标志的消息）
     *
     * @param string $text
     * @return bool
     */
    protected function looksLikeError($text)
    {
        if (!is_string($text) || $text === '') {
            return false;
        }
        return stripos($text, 'error') !== false
            || stripos($text, 'failed') !== false
            || stripos($text, 'exception') !== false;
    }

    /**
     * 两批报错是不是同一批
     *
     * @param string[] $a
     * @param string[] $b
     * @return bool
     */
    protected function sameErrors(array $a, array $b)
    {
        sort($a);
        sort($b);
        return $a === $b;
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