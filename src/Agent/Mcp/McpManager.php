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
    /** @var array<string, array<string, mixed>> 服务器名 => 配置（stdio 的 command/args/options 或远程的 transport/url） */
    protected $serverConfigs = [];

    /** @var array<string, McpClient> */
    protected $servers = [];

    /** @var bool */
    protected $initialized = false;

    /** @var string 最近一次连接 / 发现失败的原因 */
    protected $lastError = '';

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
     * 按配置注册一个服务器（支持多种传输协议）
     *
     * `addServer()` 只能配 stdio 子进程；这个方法接受完整配置，
     * 远程 HTTP / SSE / WebSocket 服务器用它。
     *
     * ```php
     * $mcp->registerServer('fs', ['command' => 'npx', 'args' => ['-y', 'server-fs', '/tmp']]);
     * $mcp->registerServer('remote', ['transport' => 'http', 'url' => 'https://mcp.example.com/rpc']);
     * $mcp->registerServer('live', ['transport' => 'websocket', 'url' => 'wss://mcp.example.com/ws']);
     * ```
     *
     * @param string $name
     * @param array<string, mixed> $config transport / command / args / url / headers / timeout
     * @return $this
     */
    public function registerServer($name, array $config)
    {
        $this->serverConfigs[(string) $name] = $config;
        return $this;
    }

    /**
     * 连接指定服务器
     *
     * 已连接时直接返回 true，不会重连。
     *
     * @param string $name
     * @return bool 连接并握手成功返回 true
     */
    public function connect($name)
    {
        $name = (string) $name;
        if (isset($this->servers[$name])) {
            return true;
        }
        if (!isset($this->serverConfigs[$name])) {
            return false;
        }

        try {
            $client = $this->makeClient($this->serverConfigs[$name]);
            $client->initialize();
            $this->servers[$name] = $client;
            return true;
        } catch (\Throwable $e) {
            $this->lastError = $name . ': ' . $e->getMessage();
            return false;
        }
    }

    /**
     * 断开指定服务器
     *
     * @param string $name
     * @return $this
     */
    public function disconnect($name)
    {
        $name = (string) $name;
        if (isset($this->servers[$name])) {
            try {
                $this->servers[$name]->shutdown();
            } catch (\Throwable $e) {
                // 忽略关闭错误
            }
            unset($this->servers[$name]);
        }
        return $this;
    }

    /**
     * 指定服务器是否已连接
     *
     * @param string $name
     * @return bool
     */
    public function isConnected($name)
    {
        $name = (string) $name;
        return isset($this->servers[$name]) && $this->servers[$name]->isInitialized();
    }

    /**
     * 动态发现某个服务器的工具列表
     *
     * 未连接时先连；连不上返回空数组，不抛异常——一个 MCP 服务器不可用
     * 不该让整个 Agent 停下来。
     *
     * @param string $name
     * @return array<int, array{name: string, description?: string, inputSchema?: array<string, mixed>}>
     */
    public function discoverTools($name)
    {
        $name = (string) $name;
        if (!isset($this->servers[$name]) && !$this->connect($name)) {
            return [];
        }
        try {
            return $this->servers[$name]->listTools();
        } catch (\Throwable $e) {
            $this->lastError = $name . ': ' . $e->getMessage();
            return [];
        }
    }

    /**
     * 已登记的服务器名（不论是否已连接）
     *
     * @return string[]
     */
    public function serverNames()
    {
        return array_keys($this->serverConfigs);
    }

    /**
     * 每个服务器的连接状态
     *
     * @return array<string, array{connected: bool, transport: string}>
     */
    public function status()
    {
        $status = [];
        foreach ($this->serverConfigs as $name => $config) {
            $connected = isset($this->servers[$name]);
            $status[(string) $name] = [
                'connected' => $connected,
                'transport' => $connected
                    ? $this->servers[$name]->getTransportName()
                    : (isset($config['transport']) && is_string($config['transport'])
                        ? $config['transport']
                        : 'stdio'),
            ];
        }
        return $status;
    }

    /**
     * 最近一次连接 / 发现失败的原因
     *
     * @return string
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * 按配置造客户端
     *
     * 兼容 `addServer()` 写进来的 `['command' =>, 'args' =>, 'options' =>]` 形态。
     *
     * @param array<string, mixed> $config
     * @return McpClient
     */
    protected function makeClient(array $config)
    {
        if (isset($config['options']) && is_array($config['options'])) {
            $flat = $config['options'];
            $flat['command'] = isset($config['command']) ? $config['command'] : '';
            $flat['args'] = isset($config['args']) && is_array($config['args']) ? $config['args'] : [];
            if (isset($config['transport'])) {
                $flat['transport'] = $config['transport'];
            }
            if (isset($config['url'])) {
                $flat['url'] = $config['url'];
            }
            return McpClient::fromConfig($flat);
        }
        return McpClient::fromConfig($config);
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
            if (!is_array($config)) {
                continue;
            }
            // 远程传输不需要 command，按完整配置登记
            if (isset($config['transport']) || isset($config['url'])) {
                $this->registerServer((string) $name, $config);
                continue;
            }
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
                $client = $this->makeClient($config);
                $client->initialize();
                $this->servers[(string) $name] = $client;
            } catch (\Throwable $e) {
                $errors[] = "{$name}: " . $e->getMessage();
                $this->lastError = "{$name}: " . $e->getMessage();
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