<?php
namespace Ai\Agent\Indexer;

use Ai\Agent\Tool\ToolParameter;

/**
 * Reflection → JSON Schema
 *
 * 把 `ReflectionMethod` 的参数列表变成 `ToolParameter[]`：类型以 **PHP 类型声明**
 * 为准（最可靠），描述以 **PHPDoc `@param`** 为准（Reflection 拿不到文字说明），
 * 两边都没有的类型再退回 PHPDoc 的类型标注。
 *
 * ```php
 * $params = (new ReflectionSchemaBuilder())->build($method, $docParams);
 * ```
 *
 * PHP 8/8.1 才有的 Reflection API（`ReflectionUnionType`、枚举）一律探测后使用，
 * 在 7.1 上自动走「只有 ReflectionNamedType」那条路。
 */
class ReflectionSchemaBuilder
{
    /**
     * @param \ReflectionMethod $method
     * @param array<string, array<string, mixed>> $docParams PhpDocParser 解析出的 params
     * @return ToolParameter[]
     */
    public function build(\ReflectionMethod $method, array $docParams = [])
    {
        $out   = [];
        $order = 0;

        foreach ($method->getParameters() as $rp) {
            $name = $rp->getName();
            $doc  = isset($docParams[$name]) ? $docParams[$name] : [];

            $types = $this->typesOf($rp);
            $enum  = $this->enumOf($rp);
            $items = isset($doc['items']) && is_array($doc['items']) ? $doc['items'] : [];

            // 没有 PHP 类型声明时用 PHPDoc 的类型
            if ($types === [] && isset($doc['types']) && is_array($doc['types'])) {
                $types = $doc['types'];
            }
            // PHP 类型声明给不出枚举取值，用 PHPDoc 的字面量联合补上
            if ($enum === [] && isset($doc['enum']) && is_array($doc['enum'])) {
                $enum = $doc['enum'];
            }

            // 枚举类被 mapType 归成了 object，但模型要填的是 backed value，
            // 类型得换成那个 value 的标量类型，否则 schema 自相矛盾
            if ($enum !== []) {
                $types = $this->enumBaseTypes($types, $enum);
            }

            $data = [
                'name'        => $name,
                'types'       => $types,
                'description' => isset($doc['description']) ? (string) $doc['description'] : '',
                'required'    => $this->isRequired($rp),
                'enum'        => $enum,
                'items'       => $items,
                'sort_order'  => $order,
            ];

            if ($rp->isDefaultValueAvailable()) {
                $default = null;
                try {
                    $default = $rp->getDefaultValue();
                } catch (\ReflectionException $e) {
                    // 常量表达式默认值在个别版本上取不到，忽略即可（只影响 schema 里的 default）
                    $default = null;
                }
                $data['default'] = $default;
            }

            $out[] = new ToolParameter($data);
            $order++;
        }

        return $out;
    }

    /**
     * 必填判定：没有默认值、不是可变参数、不是可选参数
     *
     * @param \ReflectionParameter $rp
     * @return bool
     */
    protected function isRequired(\ReflectionParameter $rp)
    {
        if ($rp->isVariadic()) {
            return false;
        }
        if ($rp->isDefaultValueAvailable()) {
            return false;
        }
        return !$rp->isOptional();
    }

    /**
     * 取参数的 JSON Schema 类型
     *
     * 处理三种情况：无类型声明、单一类型（含可空）、PHP 8 联合类型。
     *
     * @param \ReflectionParameter $rp
     * @return string[]
     */
    protected function typesOf(\ReflectionParameter $rp)
    {
        if (!$rp->hasType()) {
            return [];
        }
        $type = $rp->getType();
        if ($type === null) {
            return [];
        }
        return $this->typesOfReflectionType($type);
    }

    /**
     * @param \ReflectionType $type
     * @return string[]
     */
    protected function typesOfReflectionType(\ReflectionType $type)
    {
        $types = [];

        // PHP 8 联合类型：ReflectionUnionType::getTypes()
        if (class_exists('ReflectionUnionType') && $type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $sub) {
                foreach ($this->typesOfReflectionType($sub) as $t) {
                    if (!in_array($t, $types, true)) {
                        $types[] = $t;
                    }
                }
            }
            return $types;
        }

        // PHP 8.1 交集类型：无法映射成 JSON Schema 的具体类型，当 object
        if (class_exists('ReflectionIntersectionType') && $type instanceof \ReflectionIntersectionType) {
            return ['object'];
        }

        // ReflectionNamedType（7.1+）。getName() 是 7.1 就有的
        $name = method_exists($type, 'getName') ? $type->getName() : (string) $type;
        $mapped = PhpDocParser::mapType((string) $name);
        if ($mapped !== '') {
            $types[] = $mapped;
        }
        if ($type->allowsNull() && !in_array('null', $types, true)) {
            $types[] = 'null';
        }
        return $types;
    }

    /**
     * PHP 8.1 backed enum 参数 → 枚举取值
     *
     * 只支持 backed enum（有 `value` 的那种）：纯 enum 没有可序列化的标量值，
     * 塞进 JSON Schema 也没法让模型填。
     *
     * @param \ReflectionParameter $rp
     * @return array<int, mixed>
     */
    protected function enumOf(\ReflectionParameter $rp)
    {
        if (!function_exists('enum_exists') || !$rp->hasType()) {
            return [];
        }
        $type = $rp->getType();
        if ($type === null || !method_exists($type, 'getName')) {
            return [];
        }
        $name = (string) $type->getName();
        if ($name === '' || !enum_exists($name)) {
            return [];
        }

        $values = [];
        /** @var callable $cases */
        $cases = [$name, 'cases'];
        if (!is_callable($cases)) {
            return [];
        }
        $all = call_user_func($cases);
        if (!is_array($all)) {
            return [];
        }
        foreach ($all as $case) {
            if (is_object($case) && property_exists($case, 'value')) {
                $values[] = $case->value;
            }
        }
        return $values;
    }

    /**
     * 把 object 类型换成枚举取值的标量类型（保留 null）
     *
     * @param string[] $types
     * @param array<int, mixed> $enum
     * @return string[]
     */
    protected function enumBaseTypes(array $types, array $enum)
    {
        $first = reset($enum);
        $base  = is_int($first) ? 'integer' : 'string';

        $out = [];
        foreach ($types as $t) {
            $out[] = ($t === 'object') ? $base : $t;
        }
        if ($out === []) {
            $out[] = $base;
        }
        return array_values(array_unique($out));
    }
}
