<?php
namespace Ai\Agent\Tool;

/**
 * 工具注册表
 *
 * 管理全部可用工具的注册、查找与格式转换。
 * 同时兼容两种注册方式：
 *   1. AgentToolInterface 对象（新格式）
 *   2. 旧格式数组 ['description'=>..., 'input_schema'=>..., 'handler'=>Closure]
 *
 * 旧格式工具会被自动包装为 AgentToolInterface 匿名实现，两种写法可混用。
 *
 * 用法：
 * ```php
 * $registry = new ToolRegistry();
 *
 * // 新格式
 * $registry->register(new ReadFileTool());
 *
 * // 旧格式（自动包装）
 * $registry->register('get_weather', [
 *     'description'  => '查询天气',
 *     'input_schema' => [...],
 *     'handler'      => function(array $in) { return '晴'; },
 * ]);
 * ```
 */
class ToolRegistry
{
    /** @var array<string, AgentToolInterface> 已注册的工具 */
    protected $tools = [];

    /** 注册一个工具
     *
     * 支持两种调用方式：
     *   1. register($toolObject)  — 传入 AgentToolInterface 实例，用其 name() 方法取名
     *   2. register($name, $def)  — 传入工具名 + 旧格式定义数组
     *
     * @param mixed $nameOrTool AgentToolInterface 实例，或工具名
     * @param array<string, mixed>|AgentToolInterface|null $def 旧格式定义或工具实例（可选）
     * @return $this
     */
    public function register($nameOrTool, $def = null)
    {
        if ($nameOrTool instanceof AgentToolInterface) {
            $this->tools[$nameOrTool->name()] = $nameOrTool;
            return $this;
        }

        $name = (string) $nameOrTool;
        if ($name === '') {
            return $this;
        }

        // 直接传了 AgentToolInterface 实例作为第二个参数
        if ($def instanceof AgentToolInterface) {
            $this->tools[$name] = $def;
            return $this;
        }

        // 旧格式数组
        if (is_array($def)) {
            $this->tools[$name] = $this->wrapArray($name, $def);
            return $this;
        }

        return $this;
    }

    /** 批量注册（兼容旧 Agent::setTools() 的传参格式）
     * @param array<string, mixed> $tools 工具名 => 定义数组 或 AgentToolInterface
     * @return $this
     */
    public function registerAll(array $tools)
    {
        foreach ($tools as $name => $def) {
            if ($def instanceof AgentToolInterface) {
                $this->tools[$def->name()] = $def;
            } else {
                $this->tools[$name] = $this->wrapArray((string) $name, $def);
            }
        }
        return $this;
    }

    /** 获取指定工具
     * @param string $name
     * @return AgentToolInterface|null
     */
    public function get($name)
    {
        return isset($this->tools[(string) $name]) ? $this->tools[(string) $name] : null;
    }

    /** 全部已注册工具
     * @return array<string, AgentToolInterface>
     */
    public function all()
    {
        return $this->tools;
    }

    /** 是否有指定工具
     * @param string $name
     * @return bool
     */
    public function has($name)
    {
        return isset($this->tools[(string) $name]);
    }

    /** 获取给 AI 模型使用的工具定义数组（去掉 handler，保留元数据）
     * @return array<int, array<string, mixed>>
     */
    public function defs()
    {
        $defs = [];
        foreach ($this->tools as $name => $tool) {
            $defs[] = [
                'name'         => $name,
                'description'  => $tool->description(),
                'input_schema' => $tool->schema(),
            ];
        }
        return $defs;
    }

    /** 清除所有工具
     * @return $this
     */
    public function clear()
    {
        $this->tools = [];
        return $this;
    }

    /**
     * 将旧格式数组包装为 AgentToolInterface
     *
     * @param string $name 工具名
     * @param array<string, mixed> $def 定义数组（description, input_schema, handler）
     * @return AgentToolInterface
     */
    protected function wrapArray($name, array $def)
    {
        $nameVal = $name;
        $descVal = isset($def['description']) ? (string) $def['description'] : '';
        $schemaVal = isset($def['input_schema']) && is_array($def['input_schema'])
            ? $def['input_schema']
            : ['type' => 'object', 'properties' => new \stdClass()];
        $handlerVal = isset($def['handler']) && is_callable($def['handler']) ? $def['handler'] : null;

        // 匿名类实现 AgentToolInterface
        return new class($nameVal, $descVal, $schemaVal, $handlerVal) implements AgentToolInterface {
            /** @var string */
            protected $name;
            /** @var string */
            protected $desc;
            /** @var array<string, mixed> */
            protected $schema;
            /** @var callable|null */
            protected $handler;

            /**
             * @param string $name
             * @param string $desc
             * @param array<string, mixed> $schema
             * @param callable|null $handler
             */
            public function __construct($name, $desc, $schema, $handler)
            {
                $this->name    = $name;
                $this->desc    = $desc;
                $this->schema  = $schema;
                $this->handler = $handler;
            }

            public function name()
            {
                return $this->name;
            }

            public function description()
            {
                return $this->desc;
            }

            public function schema()
            {
                return $this->schema;
            }

            public function execute(array $input, ToolContext $context)
            {
                if ($this->handler === null) {
                    return ToolResult::error('未知工具 ' . $this->name);
                }
                $out = call_user_func($this->handler, $input);
                // 返回 ToolResult 或 字符串
                if ($out instanceof ToolResult) {
                    return $out;
                }
                return ToolResult::success((string) $out);
            }
        };
    }
}