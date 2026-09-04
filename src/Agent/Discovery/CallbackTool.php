<?php
namespace Ai\Agent\Discovery;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;

/**
 * 用闭包实现的 AgentToolInterface
 *
 * `RegistryToolBridge` 要产出三个工具对象。用匿名类的话每个都要重复写
 * name/description/schema 三个 getter，用它一句话就够。
 *
 * ```php
 * new CallbackTool('my_tool', '说明', ['type' => 'object'], function (array $in) {
 *     return ToolResult::success('ok');
 * });
 * ```
 *
 * 闭包返回 `ToolResult` 时原样返回，返回字符串时自动包成成功结果。
 */
class CallbackTool implements AgentToolInterface
{
    /** @var string */
    protected $toolName;

    /** @var string */
    protected $toolDescription;

    /** @var array<string, mixed> */
    protected $toolSchema;

    /** @var callable function(array $input, ToolContext $context): ToolResult|string */
    protected $handler;

    /**
     * @param string $name
     * @param string $description
     * @param array<string, mixed> $schema
     * @param callable $handler
     */
    public function __construct($name, $description, array $schema, $handler)
    {
        $this->toolName        = (string) $name;
        $this->toolDescription = (string) $description;
        $this->toolSchema      = $schema;
        $this->handler         = $handler;
    }

    /** @return string */
    public function name()
    {
        return $this->toolName;
    }

    /** @return string */
    public function description()
    {
        return $this->toolDescription;
    }

    /** @return array<string, mixed> */
    public function schema()
    {
        return $this->toolSchema;
    }

    /**
     * @param array<string, mixed> $input
     * @param ToolContext $context
     * @return ToolResult
     */
    public function execute(array $input, ToolContext $context)
    {
        if (!is_callable($this->handler)) {
            return ToolResult::error('工具 ' . $this->toolName . ' 没有可执行的处理器');
        }
        $out = call_user_func($this->handler, $input, $context);
        if ($out instanceof ToolResult) {
            return $out;
        }
        return ToolResult::success(is_string($out) ? $out : (string) json_encode($out, JSON_UNESCAPED_UNICODE));
    }
}
