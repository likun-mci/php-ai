<?php
namespace Ai\Agent\Context;

/**
 * 上下文管理器
 *
 * 管理 Agent 的对话消息：维护消息列表、估算 token 数、
 * 判断是否需要压缩（超过阈值）、执行压缩（把旧消息压成摘要）。
 *
 * token 估算用「字符数 / 4」的近似值（英文约 4 字符 1 token，中文约 1.5 字符 1 token），
 * 不做精确分词——对压缩触发时机而言，近似值足够。
 *
 * 用法：
 * ```php
 * $cm = new ContextManager($messages, ['threshold' => 0.8, 'maxTokens' => 100000]);
 * if ($cm->shouldCompact()) {
 *     $cm->compact(function ($summaryText) { ... });  // 摘要器闭包
 * }
 * ```
 */
class ContextManager
{
    /** @var array<int, array<string, mixed>> */
    protected $messages = [];

    /** @var int 触发压缩的 token 阈值（默认 100000） */
    protected $maxTokens = 100000;

    /** @var float 触发压缩的比例阈值（0.8 = 超过 80% 时压缩） */
    protected $threshold = 0.8;

    /** @var int 压缩后保留的最近消息条数 */
    protected $keepRecent = 10;

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $options maxTokens / threshold / keepRecent
     */
    public function __construct(array $messages = [], array $options = [])
    {
        $this->messages = array_values($messages);
        if (isset($options['maxTokens'])) {
            $this->maxTokens = max(1000, (int) $options['maxTokens']);
        }
        if (isset($options['threshold'])) {
            $this->threshold = max(0.1, min(0.99, (float) $options['threshold']));
        }
        if (isset($options['keepRecent'])) {
            $this->keepRecent = max(2, (int) $options['keepRecent']);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function messages() { return $this->messages; }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return $this
     */
    public function setMessages(array $messages)
    {
        $this->messages = array_values($messages);
        return $this;
    }

    /** 追加一条消息
     * @param array<string, mixed> $message
     * @return $this
     */
    public function append(array $message)
    {
        $this->messages[] = $message;
        return $this;
    }

    /**
     * 估算全部消息的 token 数
     *
     * @return int
     */
    public function tokenCount()
    {
        $total = 0;
        foreach ($this->messages as $msg) {
            $total += self::estimateTokens(self::serializeMessage($msg));
        }
        return $total;
    }

    /**
     * 是否应该触发压缩（当前 token 数超过 maxTokens × threshold）
     *
     * @return bool
     */
    public function shouldCompact()
    {
        return $this->tokenCount() > (int) floor($this->maxTokens * $this->threshold);
    }

    /**
     * 将消息列表按 Agent Turn 分组
     *
     * 一个 Turn 包含：
     *   1. 用户输入（role=user，不含 tool_result）
     *   2. 模型回复（role=assistant，可能含 tool_use）
     *   3. 工具结果（role=user，含 tool_result）
     *
     * 分组后每个 Turn 内的 tool_use/tool_result 配对完整，不会被拆散。
     *
     * @return Turn[]
     */
    public function turns()
    {
        $turns = [];
        $current = new Turn();

        foreach ($this->messages as $msg) {
            // 遇到新的用户输入（非 tool_result）→ 之前的 Turn 结束，开启新 Turn
            if (Turn::isUserInput($msg) && $current->count() > 0) {
                $turns[] = $current;
                $current = new Turn();
            }
            $current->addMessage($msg);

            // 模型回复不含 tool_use → 当前 Turn 结束
            if (($msg['role'] ?? '') === 'assistant' && !$this->hasToolUse($msg)) {
                $turns[] = $current;
                $current = new Turn();
            }

            // tool_result 消息（user 消息，含 tool_result 块）→ 当前 Turn 结束
            if (Turn::isToolResultMessage($msg)) {
                $turns[] = $current;
                $current = new Turn();
            }
        }

        // 有余留的未结束 Turn
        if ($current->count() > 0) {
            $turns[] = $current;
        }

        return $turns;
    }

    /**
     * 判断一条消息是否含有 tool_use 块
     *
     * @param array<string, mixed> $msg
     * @return bool
     */
    protected function hasToolUse(array $msg)
    {
        if (($msg['role'] ?? '') !== 'assistant') {
            return false;
        }
        $content = $msg['content'] ?? '';
        if (!is_array($content)) {
            return false;
        }
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'tool_use') {
                return true;
            }
        }
        return false;
    }

    /**
     * 执行压缩：以 Turn 为单位，保留最近 keepRecent 条消息（至少完整 1 个 Turn），
     * 把更早的 Turn 通过摘要器压成一条系统消息。
     *
     * @param callable $summarizer function(array $messages, string $taskHint): string
     *                             传入被压缩的旧消息，返回摘要文本
     * @param string $taskHint 任务提示（帮助摘要器保留关键信息）
     * @param string $preserve 原样保留的结构化状态（改过的文件、计划进度、当前分支）。
     *                         摘要会漏，这一段不会——它不经模型转述，直接拼进上下文。
     * @return array<int, array<string, mixed>> 压缩后的消息列表
     */
    public function compact($summarizer, $taskHint = '', $preserve = '')
    {
        $preserve = (string) $preserve;
        $count = count($this->messages);
        if ($count <= $this->keepRecent) {
            return $this->messages;
        }

        // 方案一：按 Turn 切割
        $turnList = $this->turns();
        $turnCount = count($turnList);

        // 从后往前保留 Turn，直到消息数 >= keepRecent 或仅剩 1 个 Turn
        $keepMessages = [];
        $keepTurnMessages = 0;
        for ($i = $turnCount - 1; $i >= 0; $i--) {
            $turnMsgs = $turnList[$i]->messages();
            $keepTurnMessages += count($turnMsgs);
            $keepMessages = array_merge($turnMsgs, $keepMessages);
            // 如果保留的消息数 >= keepRecent，或这是最后一个 Turn，停止
            if ($keepTurnMessages >= $this->keepRecent || $i === 0) {
                break;
            }
        }

        // 计算被压缩的旧消息
        $oldCount = $count - count($keepMessages);
        if ($oldCount <= 0) {
            return $this->messages;
        }
        $old = array_slice($this->messages, 0, $oldCount);

        $summary = call_user_func($summarizer, $old, $taskHint);
        if (!is_string($summary) || $summary === '') {
            // 摘要失败则保守处理：只裁掉纯工具结果（那些可以安全重放的信息）
            $old = array_filter($old, function ($m) {
                $content = $m['content'] ?? '';
                if (is_array($content)) {
                    foreach ($content as $block) {
                        if (is_array($block) && ($block['type'] ?? '') === 'tool_result') {
                            return false;
                        }
                    }
                }
                return true;
            });
            // 摘要失败时状态更不能丢：这条路径下模型对早期历史一无所知，
            // 全靠这段结构化事实撑住
            $rescued = array_merge(array_values($old), $keepMessages);
            if ($preserve !== '') {
                array_unshift($rescued, [
                    'role'    => 'system',
                    'content' => "[Preserved state]\n" . $preserve,
                ]);
            }
            $this->messages = $rescued;
            return $this->messages;
        }

        // 摘要负责叙事，preserve 负责事实。事实原样拼进去，不经模型转述——
        // 长任务压到第三、第四次之后，「改过哪些文件」「计划还剩几步」
        // 一旦被摘要漏掉，模型会重做已经做完的事，或者以为自己做完了
        $content = "[Conversation summary]\n" . $summary;
        if ($preserve !== '') {
            $content .= "\n\n[Preserved state]\n" . $preserve;
        }
        $summaryMsg = ['role' => 'system', 'content' => $content];
        $this->messages = array_merge([$summaryMsg], $keepMessages);
        return $this->messages;
    }

    /* ---------- 工具方法 ---------- */

    /**
     * 估算一段文本的 token 数
     *
     * 中文约 1.5 字符 1 token，其余约 4 字符 1 token。
     *
     * @param string $text
     * @return int
     */
    public static function estimateTokens($text)
    {
        if ($text === '') {
            return 0;
        }
        // 统计非 ASCII（中文等）字符数
        $nonAscii = preg_match_all('/[^\x00-\x7F]/u', $text);
        if ($nonAscii === false) {
            $nonAscii = 0;
        }
        $ascii = strlen($text) - $nonAscii;
        return (int) ceil($ascii / 4 + $nonAscii / 1.5);
    }

    /**
     * 把一条消息序列化为文本（用于 token 估算）
     *
     * @param array<string, mixed> $msg
     * @return string
     */
    protected static function serializeMessage(array $msg)
    {
        $role = isset($msg['role']) ? (string) $msg['role'] : '';
        $content = $msg['content'] ?? '';
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $block) {
                if (is_array($block)) {
                    $parts[] = (string) ($block['text'] ?? $block['content'] ?? json_encode($block, JSON_UNESCAPED_UNICODE));
                } else {
                    $parts[] = (string) $block;
                }
            }
            $content = implode(' ', $parts);
        }
        return $role . ': ' . (string) $content;
    }
}