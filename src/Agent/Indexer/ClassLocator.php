<?php
namespace Ai\Agent\Indexer;

/**
 * 把 PHP 文件变成可反射的类名
 *
 * 扫描目录时**不能**无脑 `require` 每个文件——业务代码里常有顶层副作用
 * （连数据库、注册路由、发请求），一次索引把整个应用跑起来是灾难。
 *
 * 因此流程是：
 *
 * ```text
 * 读文件内容
 *   ↓  内容里既没有 '@agent-tool' 也没有 'AgentTool' → 直接跳过，绝不 include
 *   ↓
 * token_get_all 取 namespace + class 名（纯词法，不执行代码）
 *   ↓
 * class_exists($fqcn, true)  —— 先给应用自己的 autoloader 机会
 *   ↓  仍不存在
 * require_once 该文件 → 再判一次
 * ```
 *
 * 这样只有**确实声明了 Agent Tool** 的文件才会被载入，副作用面收敛到最小。
 */
class ClassLocator
{
    /** @var string[] 文件里出现任一标记才值得深入解析 */
    protected static $markers = ['@agent-tool', 'AgentTool'];

    /**
     * 文件内容是否可能包含 Agent Tool 标注
     *
     * @param string $contents
     * @return bool
     */
    public static function looksLikeTool($contents)
    {
        foreach (self::$markers as $m) {
            if (strpos($contents, $m) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 词法提取文件里声明的全部类名（含命名空间）
     *
     * 不处理 interface / trait —— 它们不能被实例化也不该作为 Tool 载体，
     * 但仍会被 `class_exists` 之外的调用方跳过，这里只收 class。
     *
     * @param string $contents PHP 源码
     * @return string[] 全限定类名
     */
    public static function classesIn($contents)
    {
        $tokens = @token_get_all($contents);
        if (!is_array($tokens)) {
            return [];
        }

        $classes   = [];
        $namespace = '';
        $count     = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            // namespace Foo\Bar;
            if ($token[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $t = $tokens[$j];
                    if (is_string($t)) {
                        if ($t === ';' || $t === '{') {
                            break;
                        }
                        continue;
                    }
                    if ($t[0] === T_WHITESPACE) {
                        continue;
                    }
                    // PHP 8 把 Foo\Bar 合成一个 T_NAME_QUALIFIED；PHP 7 是
                    // T_STRING + T_NS_SEPARATOR 交替，两种都要认
                    if ($t[0] === T_STRING || $t[0] === T_NS_SEPARATOR
                        || (defined('T_NAME_QUALIFIED') && $t[0] === T_NAME_QUALIFIED)
                        || (defined('T_NAME_FULLY_QUALIFIED') && $t[0] === T_NAME_FULLY_QUALIFIED)
                    ) {
                        $namespace .= $t[1];
                        continue;
                    }
                    break;
                }
                $namespace = trim($namespace, '\\');
                continue;
            }

            if ($token[0] !== T_CLASS) {
                continue;
            }

            // 排除 `Foo::class` 与匿名类 `new class`
            $prev = self::prevMeaningful($tokens, $i);
            if ($prev !== null && is_array($prev) && ($prev[0] === T_DOUBLE_COLON || $prev[0] === T_NEW)) {
                continue;
            }

            $name = self::nextString($tokens, $i);
            if ($name === '') {
                continue;
            }
            $classes[] = $namespace === '' ? $name : $namespace . '\\' . $name;
        }

        return $classes;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param int $i
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    protected static function prevMeaningful(array $tokens, $i)
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $t = $tokens[$j];
            if (is_array($t) && ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
                continue;
            }
            return $t;
        }
        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param int $i
     * @return string
     */
    protected static function nextString(array $tokens, $i)
    {
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($t) && $t[0] === T_STRING) {
                return $t[1];
            }
            return '';
        }
        return '';
    }

    /**
     * 确保类已载入，必要时 require 文件
     *
     * @param string $fqcn 全限定类名
     * @param string $file 声明该类的文件
     * @return bool 是否可用
     */
    public static function ensureLoaded($fqcn, $file)
    {
        if (class_exists($fqcn, true)) {
            return true;
        }
        if (!is_file($file) || !is_readable($file)) {
            return false;
        }
        /** @psalm-suppress UnresolvableInclude */
        require_once $file;
        return class_exists($fqcn, false);
    }
}
