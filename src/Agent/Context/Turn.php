<?php
namespace Ai\Agent\Context;

/**
 * Agent Turn——一次完整交互回合
 *
 * 把消息按「回合」分组，而不是按「消息条数」：
 *
 * ```text
 * Turn
 * ├── user_message     用户输入
 * ├── assistant_message 模型回复（可能含 tool_use 块）
 * └── tool_results[]   工具结果回填（user 消息，含 tool_result 块）
 * ```
 *
 * 压缩时必须以 Turn 为单位，保证 tool_use 与对应的 tool_result 永远配对完整，
 * 不会出现「只裁掉 tool_use、留下 tool_result」产生的非法上下文。
 */
class Turn
{
    /** @var array<int, array<string, mixed>> */
    protected $messages = [];

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    public function __construct(array $messages = [])
    {
        $this->messages = array_values($messages);
    }

    /**
     * @param array<string, mixed> $message
     * @return $this
     */
    public function addMessage(array $message)
    {
        $this->messages[] = $message;
        return $this;
    }

    /** @return array<int, array<string, mixed>> */
    public function messages()
    {
        return $this->messages;
    }

    /** @return int */
    public function count()
    {
        return count($this->messages);
    }

    /**
     * 该 Turn 是否含有 tool_use 块（模型在这一轮调用了工具）
     *
     * @return bool
     */
    public function hasToolUse()
    {
        foreach ($this->messages as $m) {
            if (self::isAssistantWithToolUse($m)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 该 Turn 是否含有 tool_result 块（工具结果已回填）
     *
     * @return bool
     */
    public function hasToolResult()
    {
        foreach ($this->messages as $m) {
            if (self::isToolResultMessage($m)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 判断一条消息是否是「用户输入」（非 tool_result 的 user 消息）
     *
     * tool_result 也以 user 消息承载，但它们不是新的用户输入。
     *
     * @param array<string, mixed> $message
     * @return bool
     */
    public static function isUserInput(array $message)
    {
        if (($message['role'] ?? '') !== 'user') {
            return false;
        }
        $content = $message['content'] ?? '';
        if (!is_array($content)) {
            return true;  // 纯文本 user 消息
        }
        // 数组内容：不含 tool_result 块 → 是用户输入
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'tool_result') {
                return false;
            }
        }
        return true;
    }

    /**
     * 判断一条消息是否是 tool_result 回填消息
     *
     * @param array<string, mixed> $message
     * @return bool
     */
    public static function isToolResultMessage(array $message)
    {
        if (($message['role'] ?? '') !== 'user') {
            return false;
        }
        $content = $message['content'] ?? '';
        if (!is_array($content)) {
            return false;
        }
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'tool_result') {
                return true;
            }
        }
        return false;
    }

    /**
     * 判断一条消息是否是带 tool_use 块的 assistant 消息
     *
     * @param array<string, mixed> $message
     * @return bool
     */
    public static function isAssistantWithToolUse(array $message)
    {
        if (($message['role'] ?? '') !== 'assistant') {
            return false;
        }
        $content = $message['content'] ?? '';
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
}