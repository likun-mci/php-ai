<?php
namespace Ai\Agent\Context;

use Ai\Agent\Media\MediaReference;

/**
 * 消息块的构造与识别
 *
 * Conversation 里一条消息的 `content` 要么是字符串，要么是**块数组**。
 * 块的类型目前有：
 *
 * ```text
 * text          文本
 * agent_media   媒体引用（内部类型，发出前必被翻译掉）
 * tool_use      模型发起的工具调用
 * tool_result   工具执行结果
 * ```
 *
 * 把这些散落各处的字面量收拢到一个类里，是为了以后加 audio / video / file
 * 时不用再满仓库 grep `'type' => 'text'`。
 *
 * 注：不用类型化属性，保持 PHP 7.1 兼容（库的版本下限）。
 */
class MessagePart
{
    const TEXT        = 'text';
    const AGENT_MEDIA = MediaReference::BLOCK_TYPE;
    const TOOL_USE    = 'tool_use';
    const TOOL_RESULT = 'tool_result';

    /**
     * @param string $text
     * @return array<string, mixed>
     */
    public static function text($text)
    {
        return ['type' => self::TEXT, 'text' => (string) $text];
    }

    /**
     * @param mixed $block
     * @param string $type
     * @return bool
     */
    public static function is($block, $type)
    {
        return is_array($block) && isset($block['type']) && $block['type'] === $type;
    }

    /**
     * @param mixed $block
     * @return bool
     */
    public static function isText($block)
    {
        return self::is($block, self::TEXT);
    }

    /**
     * @param mixed $block
     * @return bool
     */
    public static function isMedia($block)
    {
        return self::is($block, self::AGENT_MEDIA);
    }

    /**
     * 一条消息的 content 里有没有媒体块
     *
     * @param mixed $content
     * @return bool
     */
    public static function contentHasMedia($content)
    {
        if (!is_array($content)) {
            return false;
        }
        foreach ($content as $block) {
            if (self::isMedia($block)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 一批消息里有没有媒体块
     *
     * @param array<int, array<string, mixed>> $messages
     * @return bool
     */
    public static function messagesHaveMedia(array $messages)
    {
        foreach ($messages as $msg) {
            if (is_array($msg) && isset($msg['content']) && self::contentHasMedia($msg['content'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * 取出一批消息里的全部媒体块
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    public static function mediaBlocksIn(array $messages)
    {
        $out = [];
        foreach ($messages as $msg) {
            if (!is_array($msg) || !isset($msg['content']) || !is_array($msg['content'])) {
                continue;
            }
            foreach ($msg['content'] as $block) {
                if (self::isMedia($block)) {
                    $out[] = $block;
                }
            }
        }
        return $out;
    }

    /**
     * 把字符串正文与媒体块拼成一条消息的 content
     *
     * 没有媒体时返回**字符串**而不是单元素块数组——绝大多数协议对纯字符串
     * content 的处理路径最短，也最不容易在第三方兼容端点上出意外。
     *
     * @param string $text
     * @param array<int, array<string, mixed>> $mediaBlocks
     * @return string|array<int, array<string, mixed>>
     */
    public static function compose($text, array $mediaBlocks)
    {
        $text = (string) $text;
        if ($mediaBlocks === []) {
            return $text;
        }
        $content = [];
        if ($text !== '') {
            $content[] = self::text($text);
        }
        foreach ($mediaBlocks as $block) {
            if (self::isMedia($block)) {
                $content[] = $block;
            }
        }
        return $content;
    }

    /**
     * 把媒体块追加到已有 content 上（不覆盖原有内容）
     *
     * 这是坑③的修复原则：数组 content 里可能有 tool_use / tool_result，
     * 整体覆盖会破坏 Anthropic 的配对结构，必须**追加**。
     *
     * @param mixed $content 原有 content（字符串或块数组）
     * @param array<int, array<string, mixed>> $mediaBlocks
     * @return string|array<int, array<string, mixed>>
     */
    public static function append($content, array $mediaBlocks)
    {
        if ($mediaBlocks === []) {
            return $content;
        }
        if (is_string($content)) {
            return self::compose($content, $mediaBlocks);
        }
        if (!is_array($content)) {
            return self::compose('', $mediaBlocks);
        }
        foreach ($mediaBlocks as $block) {
            if (self::isMedia($block)) {
                $content[] = $block;
            }
        }
        return $content;
    }

    /**
     * 取 content 里的纯文本部分（媒体块渲染成占位说明，供日志/摘要用）
     *
     * ⚠️ 这个结果**不能**发给模型当作「模型看过图了」——它只用于日志、
     * 上下文压缩摘要这类给人或给摘要器看的场景。
     *
     * @param mixed $content
     * @return string
     */
    public static function toText($content)
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }
        $parts = [];
        foreach ($content as $block) {
            if (self::isText($block)) {
                $parts[] = isset($block['text']) ? (string) $block['text'] : '';
            } elseif (self::isMedia($block)) {
                $name = isset($block['name']) ? (string) $block['name'] : '附件';
                $parts[] = '[' . (isset($block['media']) ? (string) $block['media'] : 'media') . ': ' . $name . ']';
            }
        }
        return implode('', $parts);
    }
}
