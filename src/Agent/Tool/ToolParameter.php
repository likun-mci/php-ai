<?php
namespace Ai\Agent\Tool;

/**
 * Agent Tool 参数（值对象）
 *
 * 描述一个业务能力的单个入参：名字、类型、说明、是否必填、默认值、枚举取值。
 * 它的唯一产出是 `toSchema()` —— 一段 JSON Schema 片段，拼进 ToolDefinition
 * 的 `properties` 里给模型看。
 *
 * 类型用**数组**保存（`['string', 'null']`），因为 PHP 的 `?string` 与 PHP 8
 * 联合类型都会映射成多个 JSON Schema 类型，规范里的写法就是 `"type": ["string","null"]`。
 *
 * 用法：
 * ```php
 * $p = new ToolParameter([
 *     'name'        => 'id',
 *     'types'       => ['integer'],
 *     'description' => '文章 ID',
 *     'required'    => true,
 * ]);
 * $p->toSchema();   // ['type' => 'integer', 'description' => '文章 ID']
 * ```
 *
 * 注：不用类型化属性，保持 PHP 7.1 兼容（库的版本下限）。
 */
class ToolParameter
{
    /** @var string 参数名 */
    protected $name = '';

    /** @var string[] JSON Schema 类型（integer/number/string/boolean/array/object/null） */
    protected $types = [];

    /** @var string 参数说明（来自 @param 或 Attribute） */
    protected $description = '';

    /** @var bool 是否必填 */
    protected $required = false;

    /** @var mixed 默认值（null 表示没有默认值，用 hasDefault 区分） */
    protected $default = null;

    /** @var bool 是否存在默认值 */
    protected $hasDefault = false;

    /** @var array<int, mixed> 枚举取值（空数组表示不限制） */
    protected $enum = [];

    /** @var string[] 数组元素类型（仅 types 含 array 时有意义） */
    protected $items = [];

    /** @var int 声明顺序（决定 SQLite 里的 sort_order 与输出顺序） */
    protected $sortOrder = 0;

    /** @var array<string, mixed> 手写覆盖：非空时直接作为最终 schema */
    protected $schema = [];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->name        = isset($data['name']) ? (string) $data['name'] : '';
        $this->description = isset($data['description']) ? (string) $data['description'] : '';
        $this->required    = isset($data['required']) ? (bool) $data['required'] : false;
        $this->sortOrder   = isset($data['sort_order']) ? (int) $data['sort_order'] : 0;

        $types = isset($data['types']) ? $data['types'] : (isset($data['type']) ? $data['type'] : []);
        $this->types = self::normalizeTypes($types);

        if (isset($data['items'])) {
            $this->items = self::normalizeTypes($data['items']);
        }

        if (isset($data['enum']) && is_array($data['enum'])) {
            $this->enum = array_values($data['enum']);
        }

        if (array_key_exists('default', $data)) {
            $this->default    = $data['default'];
            $this->hasDefault = true;
        }
        // 显式声明「没有默认值」时可以传 has_default=false 覆盖上面的判断
        if (array_key_exists('has_default', $data)) {
            $this->hasDefault = (bool) $data['has_default'];
        }

        if (isset($data['schema']) && is_array($data['schema'])) {
            $this->schema = $data['schema'];
        }
    }

    /**
     * 把各种写法的类型统一成字符串数组
     *
     * 接受 'string'、'string|null'、['string','null']，输出去重后的数组。
     *
     * @param mixed $types
     * @return string[]
     */
    public static function normalizeTypes($types)
    {
        if (is_string($types)) {
            $types = explode('|', $types);
        }
        if (!is_array($types)) {
            return [];
        }
        $out = [];
        foreach ($types as $t) {
            if (!is_string($t) && !is_numeric($t)) {
                continue;
            }
            $t = trim((string) $t);
            if ($t === '') {
                continue;
            }
            if (!in_array($t, $out, true)) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** @return string */
    public function getName()
    {
        return $this->name;
    }

    /** @return string[] */
    public function getTypes()
    {
        return $this->types;
    }

    /** @return string */
    public function getDescription()
    {
        return $this->description;
    }

    /** @return bool */
    public function isRequired()
    {
        return $this->required;
    }

    /** @return bool */
    public function hasDefault()
    {
        return $this->hasDefault;
    }

    /** @return mixed */
    public function getDefault()
    {
        return $this->default;
    }

    /** @return array<int, mixed> */
    public function getEnum()
    {
        return $this->enum;
    }

    /** @return int */
    public function getSortOrder()
    {
        return $this->sortOrder;
    }

    /**
     * @param int $order
     * @return $this
     */
    public function setSortOrder($order)
    {
        $this->sortOrder = (int) $order;
        return $this;
    }

    /** 是否允许 null（可空参数）
     * @return bool
     */
    public function isNullable()
    {
        return in_array('null', $this->types, true);
    }

    /**
     * 生成 JSON Schema 片段
     *
     * 单类型输出 `"type": "string"`，多类型输出 `"type": ["string","null"]`
     * —— 与规范 §5 的示例保持一致。
     *
     * @return array<string, mixed>
     */
    public function toSchema()
    {
        if ($this->schema !== []) {
            return $this->schema;
        }

        $schema = [];
        if (count($this->types) === 1) {
            $schema['type'] = $this->types[0];
        } elseif (count($this->types) > 1) {
            $schema['type'] = $this->types;
        }

        if ($this->description !== '') {
            $schema['description'] = $this->description;
        }

        if ($this->enum !== []) {
            $schema['enum'] = $this->enum;
        }

        if ($this->items !== [] && in_array('array', $this->types, true)) {
            $schema['items'] = count($this->items) === 1
                ? ['type' => $this->items[0]]
                : ['type' => $this->items];
        }

        if ($this->hasDefault && $this->default !== null) {
            $schema['default'] = $this->default;
        }

        return $schema;
    }

    /** 转为可持久化的数组
     * @return array<string, mixed>
     */
    public function toArray()
    {
        $out = [
            'name'        => $this->name,
            'types'       => $this->types,
            'description' => $this->description,
            'required'    => $this->required,
            'enum'        => $this->enum,
            'items'       => $this->items,
            'sort_order'  => $this->sortOrder,
            'has_default' => $this->hasDefault,
        ];
        if ($this->hasDefault) {
            $out['default'] = $this->default;
        }
        if ($this->schema !== []) {
            $out['schema'] = $this->schema;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data)
    {
        return new self($data);
    }
}
