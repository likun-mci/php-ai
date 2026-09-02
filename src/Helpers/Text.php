<?php
namespace Ai\Helpers;

/**
 * Text——文本截断助手
 *
 * 截断看着简单，但两种常见写法都是错的，而且都要到线上才暴露：
 *
 * ```php
 * substr($s, 0, 1024);       // 按字节切 → 可能把一个汉字劈成两半，产生非法 UTF-8
 * mb_substr($s, 0, 1024);    // 按字符切 → 中文实际放行 3072 字节，字节上限形同虚设
 * ```
 *
 * 非法 UTF-8 的后果不是显示乱码那么轻：`json_encode()` 直接返回 `false`，
 * 于是**下一次模型请求根本发不出去**，整个 Agent 运行中断——而这段坏字节
 * 是库自己截出来的。
 *
 * 正确的原语是 `mb_strcut()`：按字节切，但绝不切开一个字符。
 *
 * ```php
 * Text::cutBytes($output, 1024);        // 限字节，不劈字符
 * Text::cutChars($summary, 200);        // 限字符（给人看的摘要用这个）
 * Text::ellipsis($text, 200);           // 限字符并补省略号
 * ```
 */
class Text
{
    /**
     * 按**字节**截断，且不切开多字节字符
     *
     * 用于所有「防止内容过大」的场景：工具输出、命令回显、diff 正文。
     * 这些内容会原样回填进模型请求，字节数才是真正要控制的量。
     *
     * @param string $text
     * @param int $maxBytes 上限字节数，<= 0 时原样返回
     * @return string
     */
    public static function cutBytes($text, $maxBytes)
    {
        $text = (string) $text;
        $maxBytes = (int) $maxBytes;

        if ($maxBytes <= 0 || strlen($text) <= $maxBytes) {
            return $text;
        }
        if (function_exists('mb_strcut')) {
            return mb_strcut($text, 0, $maxBytes, 'UTF-8');
        }
        // 没有 mbstring 时手工回退：切完往回退到一个完整字符的边界
        return self::trimBrokenTail(substr($text, 0, $maxBytes));
    }

    /**
     * 按**字符**截断
     *
     * 用于给人看的摘要、日志行——那里关心的是「显示多长」而不是字节数。
     *
     * @param string $text
     * @param int $maxChars
     * @return string
     */
    public static function cutChars($text, $maxChars)
    {
        $text = (string) $text;
        $maxChars = (int) $maxChars;

        if ($maxChars <= 0) {
            return $text;
        }
        if (function_exists('mb_substr') && function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8') <= $maxChars
                ? $text
                : mb_substr($text, 0, $maxChars, 'UTF-8');
        }
        return strlen($text) <= $maxChars ? $text : self::trimBrokenTail(substr($text, 0, $maxChars));
    }

    /**
     * 按字符截断并补省略号
     *
     * @param string $text
     * @param int $maxChars
     * @param string $suffix
     * @return string 未超长时不加后缀
     */
    public static function ellipsis($text, $maxChars, $suffix = '…')
    {
        $text = (string) $text;
        $cut = self::cutChars($text, $maxChars);
        return $cut === $text ? $text : $cut . $suffix;
    }

    /**
     * 字符数（mbstring 不可用时退回字节数）
     *
     * @param string $text
     * @return int
     */
    public static function length($text)
    {
        $text = (string) $text;
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    /**
     * 内容是不是合法 UTF-8
     *
     * @param string $text
     * @return bool
     */
    public static function isValidUtf8($text)
    {
        if (function_exists('mb_check_encoding')) {
            return mb_check_encoding((string) $text, 'UTF-8');
        }
        return (bool) preg_match('//u', (string) $text);
    }

    /**
     * 去掉末尾残缺的多字节序列
     *
     * 最多回退 3 字节——UTF-8 单个字符最长 4 字节，所以残缺尾巴不会超过 3 字节。
     *
     * @param string $text
     * @return string
     */
    protected static function trimBrokenTail($text)
    {
        $text = (string) $text;
        for ($i = 0; $i < 4 && $text !== ''; $i++) {
            if (self::isValidUtf8($text)) {
                return $text;
            }
            $text = substr($text, 0, -1);
        }
        return $text;
    }
}
