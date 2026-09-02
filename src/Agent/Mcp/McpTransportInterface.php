<?php
namespace Ai\Agent\Mcp;

/**
 * McpTransportInterface——MCP 传输层接口
 *
 * MCP 是 JSON-RPC 2.0 协议，传输方式可以换：本地工具走 stdio 子进程，
 * 远程服务走 HTTP，需要服务端推送时走 SSE，双向实时走 WebSocket。
 * `McpClient` 只管协议，具体怎么把字节送出去由实现类负责。
 *
 * ```php
 * $transport = new McpHttpTransport('https://mcp.example.com/rpc');
 * $client = McpClient::fromConfig(['transport' => $transport]);
 * ```
 */
interface McpTransportInterface
{
    /**
     * 传输方式名称：stdio / http / sse / websocket
     *
     * @return string
     */
    public function name();

    /**
     * 建立连接（启动进程 / 打开连接）
     *
     * @return void
     * @throws \RuntimeException 连接失败
     */
    public function open();

    /**
     * 关闭连接
     *
     * @return void
     */
    public function close();

    /**
     * 连接是否可用
     *
     * @return bool
     */
    public function isOpen();

    /**
     * 发送一个 JSON-RPC 请求并等待响应
     *
     * @param array<string, mixed> $payload 完整的 JSON-RPC 请求（含 id）
     * @param int $timeout 超时秒数
     * @return array<string, mixed>|null 解码后的响应；超时或无响应返回 null
     * @throws \RuntimeException 传输层错误
     */
    public function request(array $payload, $timeout);

    /**
     * 发送一个 JSON-RPC 通知（不等响应）
     *
     * @param array<string, mixed> $payload
     * @return void
     */
    public function notify(array $payload);
}
