<?php
namespace Ai\Agent\Registry;

use Ai\Agent\Tool\ToolDefinition;
use Ai\Agent\Tool\ToolParameter;

/**
 * 入参校验与收敛
 *
 * 模型给的参数不可信：可能缺必填、类型不对（JSON 里数字常被写成字符串），
 * 也可能夹带 Tool 根本没声明的字段。这一层把它整理成 Controller 能安全接收的形状。
 *
 * 三条规则：
 *
 * 1. **未声明的参数一律丢弃**，不透传。让模型能往 Controller 塞任意字段，
 *    等于把入参白名单交给模型决定——那是权限之外的另一个洞。
 * 2. **必填缺失直接失败**，不用默认值蒙混过去。
 * 3. **只做安全的类型收敛**："123"→123、"true"→true 这类 JSON 传输损耗要修，
 *    但 "abc"→0 这种会把错误变成静默错误的转换一律拒绝。
 *
 * ```php
 * $r = ArgumentValidator::validate($toolDefinition, ['id' => '12', 'x' => 1]);
 * $r['ok'];        // true
 * $r['arguments']; // ['id' => 12]   —— x 被丢弃，'12' 被收敛成 int
 * ```
 */
class ArgumentValidator
{
    /**
     * @param ToolDefinition $tool
     * @param array<string, mixed> $input 模型给的原始参数
     * @return array{ok: bool, arguments: array<string, mixed>, errors: string[], dropped: string[]}
     */
    public static function validate(ToolDefinition $tool, array $input)
    {
        $out     = [];
        $errors  = [];
        $known   = [];

        foreach ($tool->getParameters() as $param) {
            $name    = $param->getName();
            $known[] = $name;

            if (!array_key_exists($name, $input)) {
                if ($param->isRequired()) {
                    $errors[] = '缺少必填参数: ' . $name;
                } elseif ($param->hasDefault()) {
                    // 有默认值就不必传给 Controller，让 PHP 自己用默认值
                    continue;
                }
                continue;
            }

            $value = $input[$name];
            $coerced = self::coerce($value, $param);
            if ($coerced['ok'] === false) {
                $errors[] = '参数 ' . $name . ' 类型不符: ' . $coerced['error'];
                continue;
            }
            $value = $coerced['value'];

            $enum = $param->getEnum();
            if ($enum !== [] && !self::inEnum($value, $enum)) {
                $errors[] = '参数 ' . $name . ' 取值不在允许范围: '
                    . implode(' / ', array_map('strval', $enum));
                continue;
            }

            $out[$name] = $value;
        }

        $dropped = [];
        foreach (array_keys($input) as $k) {
            if (!in_array((string) $k, $known, true)) {
                $dropped[] = (string) $k;
            }
        }

        return [
            'ok'        => $errors === [],
            'arguments' => $out,
            'errors'    => $errors,
            'dropped'   => $dropped,
        ];
    }

    /**
     * 按参数声明的类型做安全收敛
     *
     * @param mixed $value
     * @param ToolParameter $param
     * @return array{ok: bool, value: mixed, error: string}
     */
    protected static function coerce($value, ToolParameter $param)
    {
        $types = $param->getTypes();

        // 没声明类型 → 不限制
        if ($types === []) {
            return ['ok' => true, 'value' => $value, 'error' => ''];
        }

        if ($value === null) {
            if (in_array('null', $types, true) || $param->isNullable()) {
                return ['ok' => true, 'value' => null, 'error' => ''];
            }
            return ['ok' => false, 'value' => null, 'error' => '不接受 null'];
        }

        // 已经是某个声明类型就原样放行
        foreach ($types as $t) {
            if (self::matches($value, $t)) {
                return ['ok' => true, 'value' => $value, 'error' => ''];
            }
        }

        // 再试收敛（按声明顺序，第一个成功的算数）
        foreach ($types as $t) {
            $c = self::tryCast($value, $t);
            if ($c['ok']) {
                return $c;
            }
        }

        return [
            'ok'    => false,
            'value' => $value,
            'error' => '期望 ' . implode('|', $types) . '，实际 ' . gettype($value),
        ];
    }

    /**
     * @param mixed $value
     * @param string $type JSON Schema 类型名
     * @return bool
     */
    protected static function matches($value, $type)
    {
        switch ($type) {
            case 'integer':
                return is_int($value);
            case 'number':
                return is_int($value) || is_float($value);
            case 'string':
                return is_string($value);
            case 'boolean':
                return is_bool($value);
            case 'array':
                return is_array($value);
            case 'object':
                return is_array($value) || is_object($value);
            case 'null':
                return $value === null;
            default:
                return false;
        }
    }

    /**
     * 只做无歧义的转换
     *
     * JSON 传输里数字变字符串（"12"）、布尔变字符串（"true"）很常见，修掉它们是对的；
     * 但 "abc" → 0 会把「模型填错了」变成「Controller 收到 0」，那种要拒。
     *
     * @param mixed $value
     * @param string $type
     * @return array{ok: bool, value: mixed, error: string}
     */
    protected static function tryCast($value, $type)
    {
        $fail = ['ok' => false, 'value' => $value, 'error' => ''];

        switch ($type) {
            case 'integer':
                if (is_bool($value)) {
                    return $fail;
                }
                if (is_float($value) && floor($value) === $value) {
                    return ['ok' => true, 'value' => (int) $value, 'error' => ''];
                }
                if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
                    return ['ok' => true, 'value' => (int) trim($value), 'error' => ''];
                }
                return $fail;

            case 'number':
                if (is_bool($value)) {
                    return $fail;
                }
                if (is_string($value) && is_numeric(trim($value))) {
                    return ['ok' => true, 'value' => (float) trim($value), 'error' => ''];
                }
                return $fail;

            case 'string':
                if (is_int($value) || is_float($value)) {
                    return ['ok' => true, 'value' => (string) $value, 'error' => ''];
                }
                return $fail;

            case 'boolean':
                if (is_string($value)) {
                    $v = strtolower(trim($value));
                    if ($v === 'true' || $v === '1') {
                        return ['ok' => true, 'value' => true, 'error' => ''];
                    }
                    if ($v === 'false' || $v === '0') {
                        return ['ok' => true, 'value' => false, 'error' => ''];
                    }
                }
                if ($value === 1 || $value === 0) {
                    return ['ok' => true, 'value' => $value === 1, 'error' => ''];
                }
                return $fail;

            case 'array':
                // JSON 字符串形式的数组（模型偶尔会这么给）
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        return ['ok' => true, 'value' => $decoded, 'error' => ''];
                    }
                }
                return $fail;

            case 'object':
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        return ['ok' => true, 'value' => $decoded, 'error' => ''];
                    }
                }
                return $fail;

            default:
                return $fail;
        }
    }

    /**
     * 枚举比较用宽松相等：模型可能把 1 写成 "1"
     *
     * @param mixed $value
     * @param array<int, mixed> $enum
     * @return bool
     */
    protected static function inEnum($value, array $enum)
    {
        foreach ($enum as $e) {
            if ($e === $value) {
                return true;
            }
            if ((is_string($e) || is_int($e) || is_float($e))
                && (is_string($value) || is_int($value) || is_float($value))
                && (string) $e === (string) $value
            ) {
                return true;
            }
        }
        return false;
    }
}
