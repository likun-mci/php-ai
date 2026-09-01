<?php
namespace Ai\Agent\Mcp;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;

/**
 * McpToolAdapter——将 MCP 工具包装为 AgentToolInterface
 *
 * 每个适配器封装一个 MCP 工具，通过 McpClient 转发到 MCP 服务器。
 * 工具名格式：{serverName}__{toolName}，避免不同服务器间的工具名冲突。
 *
 * @internal
 */
class McpToolAdapter implements AgentToolInterface
{
    /** @var string */
    protected $name;

    /** @var string */
    protected $description;

    /** @var array<string, mixed> */
    protected $schema;

    /** @var McpClient */
    protected $client;

    /** @var string MCP 服务器上的原始工具名 */
    protected $originalName;

    /**
     * @param string $name 完整工具名（含服务器前缀）
     * @param string $description
     * @param array<string, mixed> $schema
     * @param McpClient $client
     * @param string $originalName MCP 服务器上的原始工具名
     */
    public function __construct($name, $description, array $schema, McpClient $client, $originalName)
    {
        $this->name = (string) $name;
        $this->description = (string) $description;
        $this->schema = $schema;
        $this->client = $client;
        $this->originalName = (string) $originalName;
    }

    public function name()
    {
        return $this->name;
    }

    public function description()
    {
        return $this->description;
    }

    public function schema()
    {
        return $this->schema;
    }

    public function execute(array $input, ToolContext $context)
    {
        try {
            $result = $this->client->callTool($this->originalName, $input);
            if (!empty($result['is_error'])) {
                return ToolResult::error($result['content']);
            }
            return ToolResult::success($result['content']);
        } catch (\Throwable $e) {
            return ToolResult::error('MCP 工具执行失败：' . $e->getMessage());
        }
    }
}