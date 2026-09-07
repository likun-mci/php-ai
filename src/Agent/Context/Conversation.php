<?php
namespace Ai\Agent\Context;

/**
 * 对话消息拼接规则
 *
 * 只负责一件事：把用户新说的话，**合法地**接到已有上下文后面。
 *
 * 「合法」不是风格问题，是接口硬约束。Anthropic Messages API 要求
 * assistant 发出的每个 `tool_use` 块，都必须在紧随其后的 user 消息里
 * 有一个 `tool_use_id` 完全对得上的 `tool_result` 块——少一个、对不上，
 * 整个请求 400，不是降级，是发不出去。OpenAI 系同理：`tool_calls` 之后
 * 必须跟上对应 `tool_call_id` 的 `role:'tool'` 消息。
 *
 * 这个约束在正常轮次里由循环自己维护（调完工具就回填结果）。会破的是
 * **中途停下**的那些时刻：等授权时停、达到迭代上限时停、被取消时停——
 * 此时上下文的最后一条是带 `tool_use` 的 assistant 消息，结果还没回填。
 * 用户这时接着说话，如果直接 `$messages[] = ['role'=>'user', ...]`，
 * 下一次请求必然 400。
 *
 * 所以这里的做法与 Claude 一致：先给每个悬空的 `tool_use` 补一条
 * `is_error` 的 `tool_result` 说明「用户没批准，它没执行」，再把用户
 * 这句话作为 **同一条 user 消息里的 text 块**接在后面。一条消息同时
 * 完成「交代工具结局」和「给出新指示」，既满足配对约束，又不会产生
 * 两条相邻的 user 消息（Anthropic 不接受同角色连发）。
 *
 * 统一格式与 OpenAI 原生格式（`assistant.tool_calls` / `role:'tool'`）
 * 两种写法都认——库本来就不强制业务层迁移已有代码。
 */
class Conversation
{
    /**
     * 补给悬空 tool_use 的结果文案
     *
     * 要说清三件事，模型才不会接着往下猜：调用没被批准、**没有执行**
     * （文件没改、命令没跑）、别重试而是看后面那句话。
     */
    const TOOL_NOT_APPROVED = 'ERROR: The user did not approve this tool call, so it was NOT executed — nothing was changed. Do not retry it. Read the user message that follows and act on that instead.';

    /**
     * 找出还没有回填结果的 tool_use
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<string, string> tool_use_id => 工具名，按出现顺序
     */
    public static function danglingToolUses(array $messages)
    {
        $pending = [];
        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $role    = isset($msg['role']) ? (string) $msg['role'] : '';
            $content = isset($msg['content']) ? $msg['content'] : '';

            if ($role === 'assistant') {
                // 统一格式：content 里的 tool_use 块
                if (is_array($content)) {
                    foreach ($content as $block) {
                        if (!is_array($block) || (isset($block['type']) ? $block['type'] : '') !== 'tool_use') {
                            continue;
                        }
                        $id = isset($block['id']) ? (string) $block['id'] : '';
                        if ($id !== '') {
                            $pending[$id] = isset($block['name']) ? (string) $block['name'] : '';
                        }
                    }
                }
                // OpenAI 原生写法：assistant.tool_calls
                if (isset($msg['tool_calls']) && is_array($msg['tool_calls'])) {
                    foreach ($msg['tool_calls'] as $call) {
                        if (!is_array($call)) {
                            continue;
                        }
                        $id = isset($call['id']) ? (string) $call['id'] : '';
                        if ($id !== '') {
                            $pending[$id] = isset($call['function']['name']) ? (string) $call['function']['name'] : '';
                        }
                    }
                }
                continue;
            }

            // OpenAI 原生写法的结果消息
            if ($role === 'tool') {
                $id = isset($msg['tool_call_id']) ? (string) $msg['tool_call_id'] : '';
                if ($id !== '') {
                    unset($pending[$id]);
                }
                continue;
            }

            // 统一格式：user 消息里的 tool_result 块
            if (is_array($content)) {
                foreach ($content as $block) {
                    if (!is_array($block) || (isset($block['type']) ? $block['type'] : '') !== 'tool_result') {
                        continue;
                    }
                    $id = isset($block['tool_use_id']) ? (string) $block['tool_use_id'] : '';
                    if ($id !== '') {
                        unset($pending[$id]);
                    }
                }
            }
        }

        return $pending;
    }

    /**
     * 把用户新说的一句话接到上下文后面
     *
     * 三种情形：
     *   1. 有悬空 tool_use → 补 tool_result + text 块，合成一条 user 消息
     *   2. 末条已是 user 消息 → 并进去，不产生相邻的两条 user
     *   3. 其余 → 新起一条普通 user 消息
     *
     * @param array<int, array<string, mixed>> $messages
     * @param string $text
     * @return array<int, array<string, mixed>>
     */
    public static function appendUserText(array $messages, $text)
    {
        return self::appendUserParts($messages, $text, []);
    }

    /**
     * 接一句用户的话 + 若干媒体块
     *
     * 与 `appendUserText()` 共用同一套悬空 `tool_use` 处理——这一点很重要：
     * 带附件的消息同样可能出现在「上一轮停在等授权」之后，如果因为它带了媒体
     * 就绕过补 `tool_result` 那段，下一次请求照样 400。所以媒体只是多挂几个块，
     * 拼接规则一个字都不改。
     *
     * @param array<int, array<string, mixed>> $messages
     * @param string $text
     * @param array<int, array<string, mixed>> $mediaBlocks agent_media 块
     * @return array<int, array<string, mixed>>
     */
    public static function appendUserParts(array $messages, $text, array $mediaBlocks)
    {
        $messages = array_values($messages);
        $text     = (string) $text;

        $media = [];
        foreach ($mediaBlocks as $block) {
            if (MessagePart::isMedia($block) && isset($block['ref']) && $block['ref'] !== '') {
                $media[] = $block;
            }
        }

        $dangling = self::danglingToolUses($messages);
        if ($dangling) {
            $blocks = [];
            foreach ($dangling as $id => $name) {
                $blocks[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => (string) $id,
                    'content'     => self::TOOL_NOT_APPROVED,
                    'is_error'    => true,
                ];
            }
            if (trim($text) !== '') {
                $blocks[] = ['type' => 'text', 'text' => $text];
            }
            foreach ($media as $block) {
                $blocks[] = $block;
            }
            $messages[] = ['role' => 'user', 'content' => $blocks];
            return $messages;
        }

        if (trim($text) === '' && $media === []) {
            return $messages;
        }

        $last = count($messages) - 1;
        if ($last >= 0 && is_array($messages[$last]) && (isset($messages[$last]['role']) ? $messages[$last]['role'] : '') === 'user') {
            if (trim($text) !== '') {
                $messages[$last] = self::mergeUserText($messages[$last], $text);
            }
            if ($media !== []) {
                $messages[$last]['content'] = MessagePart::append(
                    isset($messages[$last]['content']) ? $messages[$last]['content'] : '',
                    $media
                );
            }
            return $messages;
        }

        $messages[] = ['role' => 'user', 'content' => MessagePart::compose($text, $media)];
        return $messages;
    }

    /**
     * 把一批消息接到上下文后面
     *
     * user 消息走 appendUserText 的规则（补结果、合并相邻），其余角色原样追加。
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $incoming
     * @return array<int, array<string, mixed>>
     */
    public static function append(array $messages, array $incoming)
    {
        foreach ($incoming as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $role    = isset($msg['role']) ? (string) $msg['role'] : 'user';
            $content = isset($msg['content']) ? $msg['content'] : '';

            // 纯文本 user 消息才需要拼接规则；带块结构的原样进（调用方自己拼好了）
            if ($role === 'user' && is_string($content)) {
                $messages = self::appendUserText($messages, $content);
                continue;
            }
            $messages = array_values($messages);
            $messages[] = $msg;
        }
        return $messages;
    }

    /**
     * 把输入归一成消息列表
     *
     * 接受：字符串、单条消息（带 role 键的数组）、消息列表。
     *
     * @param string|array<mixed> $input
     * @return array<int, array<string, mixed>>
     */
    public static function normalize($input)
    {
        if (is_string($input)) {
            return trim($input) === '' ? [] : [['role' => 'user', 'content' => $input]];
        }
        if (!is_array($input)) {
            return [];
        }
        if (isset($input['role'])) {
            return [$input];
        }
        $out = [];
        foreach ($input as $msg) {
            if (is_array($msg) && isset($msg['role'])) {
                $out[] = self::sanitizeMedia($msg);
            }
        }
        return $out;
    }

    /**
     * 并入一条已有 user 消息
     *
     * @param array<string, mixed> $msg
     * @param string $text
     * @return array<string, mixed>
     */
    /**
     * 剔除残缺的 agent_media 块
     *
     * 没有 `ref` 的媒体块解析不出任何东西，留着只会在翻译层变成一句
     * 「文件已不在存储中」的噪音。调用方手工拼消息时拼错了，就在入口挡掉。
     *
     * @param array<string, mixed> $msg
     * @return array<string, mixed>
     */
    protected static function sanitizeMedia(array $msg)
    {
        if (!isset($msg['content']) || !is_array($msg['content'])) {
            return $msg;
        }
        $clean   = [];
        $changed = false;
        foreach ($msg['content'] as $block) {
            if (MessagePart::isMedia($block)
                && (!isset($block['ref']) || (string) $block['ref'] === '')
            ) {
                $changed = true;
                continue;
            }
            $clean[] = $block;
        }
        if ($changed) {
            $msg['content'] = $clean;
        }
        return $msg;
    }

    /**
     * 把一段文字并进已有的 user 消息
     *
     * @param array<string, mixed> $msg
     * @param string $text
     * @return array<string, mixed>
     */
    protected static function mergeUserText(array $msg, $text)
    {
        $content = isset($msg['content']) ? $msg['content'] : '';
        if (is_array($content)) {
            $content[]      = ['type' => 'text', 'text' => (string) $text];
            $msg['content'] = $content;
            return $msg;
        }
        $content = (string) $content;
        $msg['content'] = trim($content) === '' ? (string) $text : $content . "\n\n" . (string) $text;
        return $msg;
    }
}
