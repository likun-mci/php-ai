<?php
namespace Ai\Agent\Mcp;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;

/**
 * McpManager——MCP 服务器管理器
 *
 * 管理多个 MCP 服务器，将 MCP 工具注册到 Agent 的工具注册表。
 * 每个 MCP 服务器通过 stdio 运行一个子进程，通过 JSON-RPC 2.0 通信。
 *
 * 用法：
 * ```php
 * $mcp = new McpManager();
 * $mcp->addServer('filesystem', 'npx', ['-y', '@modelcontextprotocol/server-fs', '/tmp']);
 * $mcp->initialize();
 * $tools = $mcp->getToolAdapters();  // AgentToolInterface[]
 * // 注册到 ToolRegistry
 * foreach ($tools as $tool) {
 *     $registry->register($tool);
 * }
 * ```
 */
class McpManager
{
    /** @var array<string, array{command: string, args: string[], options: array<string, mixed>}> */
    protected $serverConfigs = [];

    /** @var array<string, McpClient> */
    protected $servers = [];

    /** @var bool */
    protected $initialized = false;

    /**
     * 添加一个 MCP 服务器配置
     *
     * @param string $name 服务器标识名
     * @param string $command 可执行文件
     * @param string[] $args 参数
     * @param array<string, mixed> $options 可选：timeout, label
     * @return $this
     */
    public function addServer($name, $command, array $args = [], array $options = [])
    {
        $this->serverConfigs[(string) $name] = [
            'command' => (string) $command,
            'args'    => $args,
            'options' => $options,
        ];
        return $this;
    }

    /**
     * 从配置数组批量添加服务器
     *
     * 支持的格式：
     * ```php
     * $mcp->addServers([
     *     'filesystem' => [
     *         'command' => 'npx',
     *         'args'    => ['-y', '@modelcontextprotocol/server-fs', '/tmp'],
     *     ],
     * ]);
     * ```
     *
     * @param array<string, array{command: string, args?: string[], options?: array<string, mixed>}> $servers
     * @return $this
     */
    public function addServers(array $servers)
    {
        foreach ($servers as $name => $config) {
            $command = isset($config['command']) ? (string) $config['command'] : '';
            if ($command === '') {
                continue;
            }
            $args = isset($config['args']) && is_array($config['args']) ? $config['args'] : [];
            $options = isset($config['options']) && is_array($config['options']) ? $config['options'] : [];
            $this->addServer((string) $name, $command, $args, $options);
        }
        return $this;
    }

    /**
     * 初始化所有 MCP 服务器
     *
     * @return $this
     * @throws \RuntimeException
     */
    public function initialize()
    {
        if ($this->initialized) {
            return $this;
        }

        $errors = [];
        foreach ($this->serverConfigs as $name => $config) {
            try {
                $client = new McpClient(
                    $config['command'],
                    $config['args'],
                    $config['options']
                );
                $client->initialize();
                $this->servers[(string) $name] = $client;
            } catch (\Throwable $e) {
                $errors[] = "{$name}: " . $e->getMessage();
            }
        }

        $this->initialized = true;

        if ($errors && !$this->servers) {
            throw new \RuntimeException('所有 MCP 服务器启动失败：' . implode('; ', $errors));
        }

        return $this;
    }

    /**
     * 关闭所有 MCP 服务器
     *
     * @return $this
     */
    public function shutdown()
    {
        foreach ($this->servers as $name => $client) {
            try {
                $client->shutdown();
            } catch (\Throwable $e) {
                // 忽略关闭错误
            }
        }
        $this->servers = [];
        $this->initialized = false;
        return $this;
    }

    /**
     * 获取所有 MCP 工具适配器（AgentToolInterface）
     *
     * 每个工具适配器封装了 MCP 工具调用，通过 McpClient 转发到 MCP 服务器。
     *
     * @return array<string, AgentToolInterface>
     */
    public function getToolAdapters()
    {
        $this->ensureInitialized();

        $adapters = [];
        foreach ($this->servers as $serverName => $client) {
            try {
                $tools = $client->listTools();
                foreach ($tools as $tool) {
                    $toolName = isset($tool['name']) ? (string) $tool['name'] : '';
                    if ($toolName === '') {
                        continue;
                    }
                    $fullName = $serverName . '__' . $toolName;
                    $toolDesc = isset($tool['description']) ? (string) $tool['description'] : '';
                    $toolSchema = isset($tool['inputSchema']) && is_array($tool['inputSchema'])
                        ? $tool['inputSchema']
                        : ['type' => 'object', 'properties' => new \stdClass()];
                    $adapters[$fullName] = new McpToolAdapter(
                        $fullName,
                        $toolDesc,
                        $toolSchema,
                        $client,
                        $toolName
                    );
                }
            } catch (\Throwable $e) {
                // 单个服务器失败不影响其他
                continue;
            }
        }
        return $adapters;
    }

    /**
     * 获取 McpClient 实例
     *
     * @param string $serverName
     * @return McpClient|null
     */
    public function getServer($serverName)
    {
        return isset($this->servers[(string) $serverName]) ? $this->servers[(string) $serverName] : null;
    }

    /**
     * 全部运行中的服务器
     *
     * @return array<string, McpClient>
     */
    public function getServers()
    {
        return $this->servers;
    }

    /**
     * @return void
     */
    protected function ensureInitialized()
    {
        if (!$this->initialized) {
            $this->initialize();
        }
    }

    public function __destruct()
    {
        $this->shutdown();
    }
}