<?php
namespace Ai\Code;

/**
 * TokenScanner——PHP token 流的低层读取工具
 *
 * `token_get_all()` 的输出在不同 PHP 版本上形态不同，这个类把差异收在一处：
 * PHP 8 把 `Foo\Bar` 这样的限定名合成一个 `T_NAME_QUALIFIED` token，PHP 7 则拆成
 * `T_STRING`、`T_NS_SEPARATOR`、`T_STRING` 三个。本库下限是 7.1，两种都要能读，
 * 所以读名字一律走 `readName()`，不要在调用方自己拼 token。
 *
 * ```php
 * $scanner = new TokenScanner(file_get_contents('src/Auth.php'));
 * foreach ($scanner->tokens() as $i => $token) {
 *     if ($scanner->isType($i, T_CLASS)) {
 *         echo $scanner->readName($scanner->skipWhitespace($i + 1));   // 类名
 *     }
 * }
 * ```
 */
class TokenScanner
{
    /** @var array<int, array{0: int, 1: string, 2: int}|string> */
    protected $tokens = [];

    /** @var int */
    protected $count = 0;

    /**
     * @param string $code PHP 源码
     */
    public function __construct($code)
    {
        $tokens = @token_get_all((string) $code);
        $this->tokens = is_array($tokens) ? $tokens : [];
        $this->count = count($this->tokens);
    }

    /**
     * @return array<int, array{0: int, 1: string, 2: int}|string>
     */
    public function tokens()
    {
        return $this->tokens;
    }

    /**
     * @return int
     */
    public function count()
    {
        return $this->count;
    }

    /**
     * 取指定下标的 token，越界返回 null
     *
     * @param int $i
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    public function at($i)
    {
        return isset($this->tokens[$i]) ? $this->tokens[$i] : null;
    }

    /**
     * 判断指定位置是不是某个 token 类型
     *
     * @param int $i
     * @param int $type
     * @return bool
     */
    public function isType($i, $type)
    {
        return isset($this->tokens[$i])
            && is_array($this->tokens[$i])
            && $this->tokens[$i][0] === $type;
    }

    /**
     * 判断指定位置是不是某个字面符号（`{`、`(`、`;` 等）
     *
     * @param int $i
     * @param string $char
     * @return bool
     */
    public function isChar($i, $char)
    {
        return isset($this->tokens[$i])
            && is_string($this->tokens[$i])
            && $this->tokens[$i] === $char;
    }

    /**
     * token 的行号，取不到返回 0
     *
     * @param int $i
     * @return int
     */
    public function lineAt($i)
    {
        if (isset($this->tokens[$i]) && is_array($this->tokens[$i])) {
            return (int) $this->tokens[$i][2];
        }
        return 0;
    }

    /**
     * 跳过空白与注释，返回下一个有意义 token 的下标
     *
     * @param int $i 起始下标
     * @return int 越界时返回 count()
     */
    public function skipWhitespace($i)
    {
        for (; $i < $this->count; $i++) {
            $token = $this->tokens[$i];
            if (!is_array($token)) {
                return $i;
            }
            if ($token[0] !== T_WHITESPACE && $token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                return $i;
            }
        }
        return $this->count;
    }

    /**
     * 从指定位置读一个（可能带命名空间的）名字
     *
     * PHP 8 的合成 token 与 PHP 7 的 T_STRING + T_NS_SEPARATOR 序列都能读。
     * 读不到名字返回空串，`$end` 回填最后一个消费掉的 token 下标。
     *
     * 完全限定名的前导 `\` 会保留——它是"这个名字不走 import 表"的唯一信号，
     * 去掉就没法把 `\Countable` 和当前命名空间下的 `Countable` 区分开了。
     *
     * @param int $i
     * @param int|null $end 输出参数：名字结束位置
     * @return string 如 `Foo\Bar` 或 `\Foo\Bar`
     */
    public function readName($i, &$end = null)
    {
        $end = $i;
        if (!isset($this->tokens[$i])) {
            return '';
        }

        $token = $this->tokens[$i];
        if (is_array($token) && in_array($token[0], self::qualifiedNameTypes(), true)) {
            $end = $i;
            return $token[1];
        }

        // PHP 7 形态：T_STRING / T_NS_SEPARATOR 交替
        $name = '';
        for (; $i < $this->count; $i++) {
            $token = $this->tokens[$i];
            if (!is_array($token)) {
                break;
            }
            if ($token[0] === T_STRING || $token[0] === T_NS_SEPARATOR) {
                $name .= $token[1];
                $end = $i;
                continue;
            }
            break;
        }
        return $name;
    }

    /**
     * 找到与指定位置的 `{` 配对的 `}`
     *
     * @param int $open `{` 所在下标
     * @return int 配对的 `}` 下标，找不到返回 count() - 1
     */
    public function matchBrace($open)
    {
        $depth = 0;
        for ($i = $open; $i < $this->count; $i++) {
            if ($this->isChar($i, '{')) {
                $depth++;
            } elseif ($this->isChar($i, '}')) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            } elseif (is_array($this->tokens[$i])
                && in_array($this->tokens[$i][0], self::braceOpeningTypes(), true)) {
                // 字符串插值里的 `{$x}` 等结构也会带 `{`，由上面的计数统一处理
                $depth++;
            }
        }
        return $this->count - 1;
    }

    /**
     * 找到下一个 `{` 或 `;`（区分有函数体与抽象/接口声明）
     *
     * @param int $i
     * @return int 找不到返回 count()
     */
    public function findBodyStart($i)
    {
        for (; $i < $this->count; $i++) {
            if ($this->isChar($i, '{') || $this->isChar($i, ';')) {
                return $i;
            }
        }
        return $this->count;
    }

    /**
     * PHP 8 合成限定名的 token 类型（7.x 上这些常量不存在）
     *
     * @return int[]
     */
    public static function qualifiedNameTypes()
    {
        $types = [];
        foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $const) {
            if (defined($const)) {
                $types[] = constant($const);
            }
        }
        return $types;
    }

    /**
     * 会带上 `{` 的复合 token 类型（字符串插值）
     *
     * @return int[]
     */
    protected static function braceOpeningTypes()
    {
        $types = [T_CURLY_OPEN];
        if (defined('T_DOLLAR_OPEN_CURLY_BRACES')) {
            $types[] = constant('T_DOLLAR_OPEN_CURLY_BRACES');
        }
        return $types;
    }
}
