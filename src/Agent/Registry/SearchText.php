<?php
namespace Ai\Agent\Registry;

/**
 * 检索文本归一化 —— 让 FTS5 能搜中文
 *
 * SQLite 的 `unicode61` 分词器把连续汉字当成**一个** token：「修改文章的标题」
 * 整段是一个词，搜「文章」自然一无所获。中文检索因此必须自己切。
 *
 * 这里的做法是把文本归一化成一串空格分隔的 token 再交给 FTS5：
 *
 * ```text
 * 'article.update 修改文章'
 *   → article update 修 改 文 章 修改 改文 文章
 * ```
 *
 * 汉字切成**单字 + 相邻二元组**：单字保召回，二元组保精度（搜「文章」时
 * `文章` 这个 bigram 命中，比只有单字的 `文`+`章` 排得更靠前）。
 * 索引与查询走同一个函数，两边切法一致才能对上。
 *
 * `MemoryToolRegistry` 的纯 PHP 打分与 SQLite 的 LIKE 降级路径也复用它，
 * 三条路的搜索语义因此保持一致。
 */
class SearchText
{
    /**
     * 归一化成空格分隔的 token 串（写进 FTS 表的就是它）
     *
     * @param string $text
     * @return string
     */
    public static function normalize($text)
    {
        $tokens = self::tokenize($text);
        return implode(' ', $tokens);
    }

    /**
     * 切词
     *
     * ASCII 段：小写，按非字母数字切开（`article.update` → `article` `update`）。
     * CJK 段：单字 + 相邻二元组。
     *
     * @param string $text
     * @return string[]
     */
    public static function tokenize($text)
    {
        $text = (string) $text;
        if ($text === '') {
            return [];
        }

        // 先按「是不是 CJK」把字符串切成段
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return [];
        }

        $tokens  = [];
        $ascii   = '';
        $cjkRun  = [];

        foreach ($chars as $ch) {
            if (self::isCjk($ch)) {
                if ($ascii !== '') {
                    self::pushAscii($tokens, $ascii);
                    $ascii = '';
                }
                $cjkRun[] = $ch;
                continue;
            }
            if ($cjkRun !== []) {
                self::pushCjk($tokens, $cjkRun);
                $cjkRun = [];
            }
            $ascii .= $ch;
        }
        if ($ascii !== '') {
            self::pushAscii($tokens, $ascii);
        }
        if ($cjkRun !== []) {
            self::pushCjk($tokens, $cjkRun);
        }

        return array_values(array_unique($tokens));
    }

    /**
     * 判断单个字符是否属于 CJK（含日文假名、CJK 扩展 A、兼容表意文字）
     *
     * @param string $ch UTF-8 单字符
     * @return bool
     */
    protected static function isCjk($ch)
    {
        // mb_ord 是 PHP 7.2 才有的，这里手工解码 UTF-8 取码点，保持 7.1 兼容
        $cp = self::codePoint($ch);
        if ($cp < 0x2E80) {
            return false;
        }
        return ($cp >= 0x3040 && $cp <= 0x30FF)      // 平假名 / 片假名
            || ($cp >= 0x3400 && $cp <= 0x4DBF)      // CJK 扩展 A
            || ($cp >= 0x4E00 && $cp <= 0x9FFF)      // CJK 基本区
            || ($cp >= 0xF900 && $cp <= 0xFAFF)      // 兼容表意文字
            || ($cp >= 0x20000 && $cp <= 0x2FA1F);   // CJK 扩展 B~F
    }

    /**
     * UTF-8 单字符 → Unicode 码点（不依赖 mb_ord，PHP 7.1 可用）
     *
     * @param string $ch
     * @return int
     */
    public static function codePoint($ch)
    {
        if ($ch === '') {
            return 0;
        }
        $b0 = ord($ch[0]);
        if ($b0 < 0x80) {
            return $b0;
        }
        $len = strlen($ch);
        if ($b0 >= 0xF0 && $len >= 4) {
            return (($b0 & 0x07) << 18) | ((ord($ch[1]) & 0x3F) << 12)
                | ((ord($ch[2]) & 0x3F) << 6) | (ord($ch[3]) & 0x3F);
        }
        if ($b0 >= 0xE0 && $len >= 3) {
            return (($b0 & 0x0F) << 12) | ((ord($ch[1]) & 0x3F) << 6) | (ord($ch[2]) & 0x3F);
        }
        if ($b0 >= 0xC0 && $len >= 2) {
            return (($b0 & 0x1F) << 6) | (ord($ch[1]) & 0x3F);
        }
        return $b0;
    }

    /**
     * @param string[] $tokens
     * @param string $ascii
     * @return void
     */
    protected static function pushAscii(array &$tokens, $ascii)
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', strtolower($ascii));
        if ($parts === false) {
            return;
        }
        foreach ($parts as $p) {
            if ($p !== '') {
                $tokens[] = $p;
            }
        }
    }

    /**
     * @param string[] $tokens
     * @param string[] $run 一段连续的 CJK 字符
     * @return void
     */
    protected static function pushCjk(array &$tokens, array $run)
    {
        $n = count($run);
        for ($i = 0; $i < $n; $i++) {
            $tokens[] = $run[$i];
            if ($i + 1 < $n) {
                $tokens[] = $run[$i] . $run[$i + 1];
            }
        }
    }

    /**
     * 构造 FTS5 的 MATCH 查询串
     *
     * token 之间用 OR（召回优先，让模型看到更多候选，再由排序决定顺序）。
     * 每个 token 用双引号包住，避免 `AND`/`NOT`/`*` 这类 FTS5 关键字被当语法。
     *
     * @param string $query
     * @return string 空串表示没有可用 token
     */
    public static function toMatchQuery($query)
    {
        $tokens = self::tokenize($query);
        if ($tokens === []) {
            return '';
        }
        $parts = [];
        foreach ($tokens as $t) {
            $parts[] = '"' . str_replace('"', '""', $t) . '"';
        }
        return implode(' OR ', $parts);
    }

    /**
     * 纯 PHP 打分（内存 Registry 与 LIKE 降级路径用）
     *
     * 规则：命中一个查询 token 得 1 分；命中的 token 越长权重越高（二元组比单字值钱）；
     * 名字里命中的分数翻倍（工具名比描述更能说明意图）。
     *
     * @param string $query
     * @param string $name 工具名
     * @param string $haystack 描述 + 关键词
     * @return float 0 表示完全没命中
     */
    public static function score($query, $name, $haystack)
    {
        $qTokens = self::tokenize($query);
        if ($qTokens === []) {
            return 0.0;
        }
        $nameText = ' ' . self::normalize($name) . ' ';
        $bodyText = ' ' . self::normalize($haystack) . ' ';

        $score = 0.0;
        foreach ($qTokens as $t) {
            $w = 1.0 + (self::tokenLength($t) - 1) * 0.5;
            if (strpos($nameText, ' ' . $t . ' ') !== false) {
                $score += $w * 2.0;
            } elseif (strpos($bodyText, ' ' . $t . ' ') !== false) {
                $score += $w;
            }
        }
        return $score;
    }

    /**
     * token 的「字符数」（UTF-8 感知，不用 mb_strlen 以免依赖 mbstring 的具体行为）
     *
     * @param string $token
     * @return int
     */
    protected static function tokenLength($token)
    {
        $parts = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY);
        return $parts === false ? strlen($token) : count($parts);
    }
}
