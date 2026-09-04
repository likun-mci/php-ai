<?php
namespace Ai\Agent\Indexer;

/**
 * PHPDoc 解析器 —— 从注释块里抽出 Agent Tool 标注
 *
 * 认得两类标签：
 *   1. `@agent-*` 系列（php-ai 定义的标准，规范 §4 / §31.4）
 *   2. 标准的 `@param` / `@return`（补充 Reflection 拿不到的**描述**与更精确的类型）
 *
 * 纯字符串处理，零依赖，不需要 nikic/php-parser。
 *
 * ```php
 * $parsed = (new PhpDocParser())->parse($docComment);
 * $parsed['tool'];          // 'article.update'
 * $parsed['params']['id'];  // ['types' => ['integer'], 'description' => '文章 ID']
 * ```
 *
 * 没有 `@agent-tool` 时 `tool` 为空字符串——调用方据此判断「这个方法不是 Tool」。
 */
class PhpDocParser
{
    /** @var string[] 支持的 @agent-* 标签（值直接进 result 的同名键） */
    protected static $simpleTags = [
        'agent-tool'        => 'tool',
        'agent-description' => 'description',
        'agent-controller'  => 'controller',
        'agent-risk'        => 'risk',
        'agent-confirm'     => 'confirm',
        'agent-permission'  => 'permission',
        'agent-keywords'    => 'keywords',
        'agent-enabled'     => 'enabled',
        'agent-version'     => 'version',
    ];

    /**
     * 解析一个 docblock
     *
     * @param string|false|null $doc ReflectionMethod::getDocComment() 的原始返回
     * @return array<string, mixed> tool/description/summary/controller/risk/confirm/
     *                              permission/keywords/enabled/version/params/return
     */
    public function parse($doc)
    {
        // 分开用局部变量而不是一个大数组：静态分析对「同一个数组里既有 string
        // 又有 array 还有 bool」的下标写入无从收敛，拆开之后每个变量类型明确
        /** @var string */
        $tool = '';
        /** @var string */
        $description = '';
        /** @var string */
        $controller = '';
        /** @var string */
        $risk = '';
        /** @var string */
        $version = '';
        /** @var bool|null */
        $confirm = null;
        /** @var bool|null */
        $enabled = null;
        /** @var string[] */
        $permission = [];
        /** @var string[] */
        $keywords = [];
        /** @var array<string, array<string, mixed>> */
        $params = [];
        /** @var array{types: string[], description: string} */
        $return = ['types' => [], 'description' => ''];

        if (!is_string($doc) || $doc === '') {
            return $this->assemble('', '', '', '', '', '', null, null, [], [], [], $return);
        }

        $lines = $this->stripDocBlock($doc);

        // 摘要：第一段非空、非 @ 开头的文字（多行摘要拼成一行）
        $summaryLines = [];
        foreach ($lines as $line) {
            if ($line === '') {
                if ($summaryLines !== []) {
                    break;
                }
                continue;
            }
            if (strpos($line, '@') === 0) {
                break;
            }
            $summaryLines[] = $line;
        }
        $summary = implode(' ', $summaryLines);

        // 标签：@name 后面跟到下一个 @ 之前的全部内容（支持多行值）
        foreach ($this->collectTags($lines) as $tag) {
            $name  = $tag['name'];
            $value = $tag['value'];

            if ($name === 'param') {
                $p = $this->parseParamTag($value);
                if ($p !== null) {
                    $params[$p['name']] = [
                        'types'       => $p['types'],
                        'description' => $p['description'],
                        'enum'        => $p['enum'],
                        'items'       => $p['items'],
                    ];
                }
                continue;
            }

            if ($name === 'return') {
                $r = $this->parseTypeAndRest($value);
                $return = ['types' => $r['types'], 'description' => $r['rest']];
                continue;
            }

            if (!isset(self::$simpleTags[$name])) {
                continue;
            }

            switch (self::$simpleTags[$name]) {
                case 'tool':
                    // 同名标签重复出现时保留第一个，避免后面的空值把有效值冲掉
                    if ($tool === '') {
                        $tool = trim($value);
                    }
                    break;
                case 'description':
                    if ($description === '') {
                        $description = trim($value);
                    }
                    break;
                case 'controller':
                    if ($controller === '') {
                        $controller = trim($value);
                    }
                    break;
                case 'version':
                    if ($version === '') {
                        $version = trim($value);
                    }
                    break;
                case 'risk':
                    $risk = strtolower(trim($value));
                    break;
                case 'confirm':
                    $confirm = $this->toBool($value);
                    break;
                case 'enabled':
                    $enabled = $this->toBool($value);
                    break;
                case 'permission':
                    // 可以出现多次，也可以逗号分隔
                    foreach ($this->splitList($value) as $v) {
                        if (!in_array($v, $permission, true)) {
                            $permission[] = $v;
                        }
                    }
                    break;
                case 'keywords':
                    foreach ($this->splitList($value) as $v) {
                        if (!in_array($v, $keywords, true)) {
                            $keywords[] = $v;
                        }
                    }
                    break;
            }
        }

        // 没写 @agent-description 就退回 docblock 摘要
        if ($description === '') {
            $description = $summary;
        }

        return $this->assemble(
            $tool,
            $description,
            $summary,
            $controller,
            $risk,
            $version,
            $confirm,
            $enabled,
            $permission,
            $keywords,
            $params,
            $return
        );
    }

    /**
     * 把各字段拼成对外的解析结果
     *
     * @param string $tool
     * @param string $description
     * @param string $summary
     * @param string $controller
     * @param string $risk
     * @param string $version
     * @param bool|null $confirm
     * @param bool|null $enabled
     * @param string[] $permission
     * @param string[] $keywords
     * @param array<string, array<string, mixed>> $params
     * @param array{types: string[], description: string} $return
     * @return array<string, mixed>
     */
    protected function assemble(
        $tool,
        $description,
        $summary,
        $controller,
        $risk,
        $version,
        $confirm,
        $enabled,
        array $permission,
        array $keywords,
        array $params,
        array $return
    ) {
        return [
            'tool'        => $tool,
            'description' => $description,
            'summary'     => $summary,
            'controller'  => $controller,
            'risk'        => $risk,
            'confirm'     => $confirm,
            'permission'  => $permission,
            'keywords'    => $keywords,
            'enabled'     => $enabled,
            'version'     => $version,
            'params'      => $params,
            'return'      => $return,
        ];
    }

    /**
     * 去掉 `/**`、`*\/` 与每行开头的 `*`，返回逐行数组
     *
     * @param string $doc
     * @return string[]
     */
    protected function stripDocBlock($doc)
    {
        $doc = preg_replace('#^\s*/\*\*?#', '', $doc);
        if ($doc === null) {
            return [];
        }
        $doc = preg_replace('#\*/\s*$#', '', $doc);
        if ($doc === null) {
            return [];
        }
        $raw = preg_split('/\r\n|\r|\n/', $doc);
        if ($raw === false) {
            return [];
        }
        $out = [];
        foreach ($raw as $line) {
            $line = preg_replace('/^\s*\*\s?/', '', $line);
            if ($line === null) {
                $line = '';
            }
            $out[] = rtrim($line);
        }
        return $out;
    }

    /**
     * 把行数组归并成标签列表，支持标签值跨行
     *
     * @param string[] $lines
     * @return array<int, array{name: string, value: string}>
     */
    protected function collectTags(array $lines)
    {
        $tags    = [];
        $current = null;

        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if (preg_match('/^@([A-Za-z0-9_\-]+)\s*(.*)$/', $trimmed, $m) === 1) {
                if ($current !== null) {
                    $tags[] = $current;
                }
                $current = ['name' => strtolower($m[1]), 'value' => trim($m[2])];
                continue;
            }
            if ($current === null) {
                continue;
            }
            if ($trimmed === '') {
                // 空行结束当前标签的续行
                $tags[]  = $current;
                $current = null;
                continue;
            }
            $current['value'] = trim($current['value'] . ' ' . $trimmed);
        }
        if ($current !== null) {
            $tags[] = $current;
        }
        return $tags;
    }

    /**
     * 解析 `@param int $id 文章 ID`
     *
     * 也容忍缺类型（`@param $id 说明`）和缺说明（`@param int $id`）。
     *
     * @param string $value
     * @return array{name: string, types: string[], description: string, enum: array<int, mixed>, items: string[]}|null
     */
    protected function parseParamTag($value)
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $types = [];
        $enum  = [];
        $items = [];

        // 先看第一段是不是类型（不以 $ 开头即视为类型）
        if (strpos($value, '$') !== 0) {
            $parsed = $this->parseTypeAndRest($value);
            $types  = $parsed['types'];
            $enum   = $parsed['enum'];
            $items  = $parsed['items'];
            $value  = $parsed['rest'];
        }

        if (preg_match('/^\$?([A-Za-z_][A-Za-z0-9_]*)\s*(.*)$/s', $value, $m) !== 1) {
            return null;
        }

        return [
            'name'        => $m[1],
            'types'       => $types,
            'description' => trim($m[2]),
            'enum'        => $enum,
            'items'       => $items,
        ];
    }

    /**
     * 从 `int|null 说明文字` 里切出类型与剩余部分
     *
     * 顺带识别两种写法：
     *   - 字面量联合 `'draft'|'published'` → enum
     *   - 数组 `int[]` / `array<int, string>` → array + items
     *
     * @param string $value
     * @return array{types: string[], rest: string, enum: array<int, mixed>, items: string[]}
     */
    protected function parseTypeAndRest($value)
    {
        $value = ltrim($value);
        if ($value === '') {
            return ['types' => [], 'rest' => '', 'enum' => [], 'items' => []];
        }

        // 类型段：允许字母/数字/反斜杠/竖线/问号/方括号/尖括号/逗号/引号，遇空格且括号配平即结束
        $len   = strlen($value);
        $depth = 0;
        $end   = $len;
        for ($i = 0; $i < $len; $i++) {
            $ch = $value[$i];
            if ($ch === '<' || $ch === '{') {
                $depth++;
                continue;
            }
            if ($ch === '>' || $ch === '}') {
                $depth--;
                continue;
            }
            if ($ch === ' ' && $depth <= 0) {
                $end = $i;
                break;
            }
        }
        $typeStr = substr($value, 0, $end);
        $rest    = trim(substr($value, $end));

        return $this->parseTypeString($typeStr) + ['rest' => $rest];
    }

    /**
     * 把类型字符串映射成 JSON Schema 类型
     *
     * @param string $typeStr
     * @return array{types: string[], enum: array<int, mixed>, items: string[]}
     */
    public function parseTypeString($typeStr)
    {
        $typeStr = trim($typeStr);
        $types   = [];
        $enum    = [];
        $items   = [];

        if ($typeStr === '') {
            return ['types' => [], 'enum' => [], 'items' => []];
        }

        // array<K, V> 里的逗号不是联合分隔符，先把尖括号内容摘出来
        $generic = '';
        if (preg_match('/^([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*<(.+)>$/', $typeStr, $m) === 1) {
            $typeStr = $m[1];
            $generic = $m[2];
        }

        $parts = explode('|', $typeStr);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            // 'draft' / "draft" —— 字面量，归为 enum
            if (preg_match('/^([\'"])(.*)\1$/', $part, $m) === 1) {
                $enum[] = $m[2];
                if (!in_array('string', $types, true)) {
                    $types[] = 'string';
                }
                continue;
            }

            // 可空写法 ?int
            if (strpos($part, '?') === 0) {
                $part = substr($part, 1);
                if (!in_array('null', $types, true)) {
                    $types[] = 'null';
                }
            }

            // int[] / string[]
            if (substr($part, -2) === '[]') {
                $items[] = self::mapType(substr($part, 0, -2));
                $part    = 'array';
            }

            $mapped = self::mapType($part);
            if ($mapped !== '' && !in_array($mapped, $types, true)) {
                $types[] = $mapped;
            }
        }

        // array<int, string> 的 V 就是元素类型
        if ($generic !== '' && in_array('array', $types, true)) {
            $gp = explode(',', $generic);
            $v  = trim((string) array_pop($gp));
            $mapped = self::mapType($v);
            if ($mapped !== '' && !in_array($mapped, $items, true)) {
                $items[] = $mapped;
            }
        }

        // 只有 null 没有别的类型时没意义，丢弃
        if ($types === ['null']) {
            $types = [];
        }

        return ['types' => $types, 'enum' => $enum, 'items' => array_values(array_unique($items))];
    }

    /**
     * PHP 类型名 → JSON Schema 类型名
     *
     * 认不出的类名一律当 object；`mixed` 返回空串（不写 type，表示不限制）。
     *
     * @param string $type
     * @return string
     */
    public static function mapType($type)
    {
        $type = ltrim(trim($type), '\\');
        switch (strtolower($type)) {
            case 'int':
            case 'integer':
                return 'integer';
            case 'float':
            case 'double':
                return 'number';
            case 'string':
                return 'string';
            case 'bool':
            case 'boolean':
            case 'true':
            case 'false':
                return 'boolean';
            case 'array':
            case 'iterable':
            case 'list':
                return 'array';
            case 'null':
                return 'null';
            case 'object':
            case 'stdclass':
                return 'object';
            case 'mixed':
            case '':
            case 'void':
            case 'self':
            case 'static':
            case 'callable':
            case 'resource':
                return '';
            default:
                // 具名类当作 object
                return 'object';
        }
    }

    /**
     * @param string $value
     * @return bool
     */
    protected function toBool($value)
    {
        $v = strtolower(trim($value));
        return $v === 'true' || $v === '1' || $v === 'yes' || $v === 'on';
    }

    /**
     * @param string $value
     * @return string[]
     */
    protected function splitList($value)
    {
        $parts = preg_split('/[,\s]+/', trim($value));
        if ($parts === false) {
            return [];
        }
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }
}
