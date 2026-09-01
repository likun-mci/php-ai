<?php
/**
 * MCP Runtime 测试
 *
 * 覆盖：
 *   1. McpClient 启动/初始化/工具列表/调用
 *   2. McpManager 管理多服务器
 *   3. McpToolAdapter 包装为 AgentToolInterface
 *   4. 集成到 AgentRuntime / Agent
 *
 * 运行：php tests/agent_mcp_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\Mcp\McpClient;
use Ai\Agent\Mcp\McpManager;
use Ai\Agent\Mcp\McpToolAdapter;

$passed = 0;
$failed = 0;

function test($name, $ok)
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "✓ {$name}\n";
    } else {
        $failed++;
        echo "✗ {$name}\n";
    }
}

function assert_eq($name, $expected, $actual)
{
    test($name, $expected === $actual);
}

// 创建一个模拟 MCP 服务器的 PHP 脚本
$mockServerScript = sys_get_temp_dir() . '/mcp_mock_server_' . uniqid() . '.php';
file_put_contents($mockServerScript, '<?php
/**
 * 模拟 MCP 服务器——用于测试
 * 支持：initialize, tools/list, tools/call
 */
$stdin = fopen("php://stdin", "r");
stream_set_blocking($stdin, false);
$buffer = "";

function sendResponse($id, $result) {
    $msg = json_encode(["jsonrpc" => "2.0", "id" => $id, "result" => $result], JSON_UNESCAPED_UNICODE);
    echo "Content-Length: " . strlen($msg) . "\r\n\r\n" . $msg . "\r\n";
    fflush(STDOUT);
}

function sendError($id, $code, $message) {
    $msg = json_encode(["jsonrpc" => "2.0", "id" => $id, "error" => ["code" => $code, "message" => $message]], JSON_UNESCAPED_UNICODE);
    echo "Content-Length: " . strlen($msg) . "\r\n\r\n" . $msg . "\r\n";
    fflush(STDOUT);
}

while (true) {
    $chunk = @fread($stdin, 65536);
    if ($chunk === false || $chunk === "") {
        usleep(10000);
        continue;
    }
    $buffer .= $chunk;

    while (preg_match("/Content-Length: (\d+)\r\n\r\n(.*)/s", $buffer, $m)) {
        $len = (int)$m[1];
        $bodyStart = $m[2];
        if (strlen($bodyStart) < $len) {
            break;
        }
        $json = substr($bodyStart, 0, $len);
        $buffer = substr($bodyStart, $len);
        $req = json_decode($json, true);
        if (!$req) {
            continue;
        }

        $method = isset($req["method"]) ? $req["method"] : "";
        $id = isset($req["id"]) ? $req["id"] : null;

        if ($method === "initialize") {
            sendResponse($id, [
                "protocolVersion" => "2024-11-05",
                "serverInfo" => ["name" => "mock-server", "version" => "1.0.0"],
                "capabilities" => ["tools" => new \stdClass()],
            ]);
        } elseif ($method === "notifications/initialized") {
            // 忽略通知
        } elseif ($method === "tools/list") {
            sendResponse($id, [
                "tools" => [
                    [
                        "name" => "read_file",
                        "description" => "读取文件内容",
                        "inputSchema" => [
                            "type" => "object",
                            "properties" => ["path" => ["type" => "string"]],
                            "required" => ["path"],
                        ],
                    ],
                    [
                        "name" => "write_file",
                        "description" => "写入文件",
                        "inputSchema" => [
                            "type" => "object",
                            "properties" => [
                                "path" => ["type" => "string"],
                                "content" => ["type" => "string"],
                            ],
                            "required" => ["path", "content"],
                        ],
                    ],
                ],
            ]);
        } elseif ($method === "tools/call") {
            $params = isset($req["params"]) ? $req["params"] : [];
            $toolName = isset($params["name"]) ? $params["name"] : "";
            $args = isset($params["arguments"]) ? $params["arguments"] : new \stdClass();
            $args = (array)$args;

            if ($toolName === "read_file") {
                $path = isset($args["path"]) ? $args["path"] : "unknown";
                sendResponse($id, [
                    "content" => [["type" => "text", "text" => "文件内容: " . $path]],
                    "isError" => false,
                ]);
            } elseif ($toolName === "write_file") {
                sendResponse($id, [
                    "content" => [["type" => "text", "text" => "写入成功"]],
                    "isError" => false,
                ]);
            } else {
                sendError($id, -32601, "未知工具: " . $toolName);
            }
        } elseif ($method === "shutdown") {
            sendResponse($id, []);
            exit(0);
        }
    }
}
');

// ===== 1. McpClient 基础功能 =====

echo "=== 一、McpClient 基础功能 ===\n";

$client = new McpClient('php', [$mockServerScript], ['timeout' => 5, 'label' => 'mock']);
test('新建时未运行', !$client->isRunning());
test('新建时未初始化', !$client->isInitialized());
assert_eq('标签', 'mock', $client->getLabel());

// 初始化
try {
    $client->start();
    test('start 后正在运行', $client->isRunning());

    $initResult = $client->initialize();
    test('初始化成功', $client->isInitialized());
    test('初始化返回 serverInfo', is_array($initResult) && isset($initResult['serverInfo']));
    test('初始化返回 serverInfo.name', $initResult['serverInfo']['name'] === 'mock-server');

    // 工具列表
    $tools = $client->listTools();
    test('listTools 返回数组', is_array($tools));
    assert_eq('工具数量', 2, count($tools));
    assert_eq('第一个工具名', 'read_file', $tools[0]['name']);
    assert_eq('第一个工具描述', '读取文件内容', $tools[0]['description']);
    assert_eq('第二个工具名', 'write_file', $tools[1]['name']);

    // 工具调用
    $result = $client->callTool('read_file', ['path' => '/tmp/test.txt']);
    test('callTool 返回数组', is_array($result));
    test('callTool 包含 content', isset($result['content']));
    test('callTool 内容正确', strpos($result['content'], '文件内容: /tmp/test.txt') !== false);
    test('callTool 无错误', empty($result['is_error']));

    // 错误工具名
    $badResult = $client->callTool('nonexistent', []);
    test('调用不存在工具返回错误', !empty($badResult['is_error']));

    // 关闭
    $client->shutdown();
    test('shutdown 后未运行', !$client->isRunning());
    test('shutdown 后未初始化', !$client->isInitialized());

} catch (\Throwable $e) {
    test('MCP 测试异常: ' . $e->getMessage(), false);
    $client->shutdown();
}

// ===== 2. McpManager =====

echo "\n=== 二、McpManager ===\n";

$manager = new McpManager();
$manager->addServer('mock', 'php', [$mockServerScript], ['timeout' => 5]);

try {
    $manager->initialize();
    test('manager 初始化后已初始化', $manager->getServer('mock') !== null);
    test('mock 服务器已初始化', $manager->getServer('mock')->isInitialized());

    // 工具适配器
    $adapters = $manager->getToolAdapters();
    test('getToolAdapters 返回数组', is_array($adapters));
    assert_eq('适配器数量', 2, count($adapters));

    // 检查适配器名称
    $names = array_keys($adapters);
    test('适配器名含 mock__read_file', in_array('mock__read_file', $names, true));
    test('适配器名含 mock__write_file', in_array('mock__write_file', $names, true));

    // 检查适配器接口
    $adapter = $adapters['mock__read_file'];
    test('适配器是 AgentToolInterface', $adapter instanceof \Ai\Agent\Tool\AgentToolInterface);
    assert_eq('适配器名称', 'mock__read_file', $adapter->name());
    assert_eq('适配器描述', '读取文件内容', $adapter->description());
    assert_eq('适配器 schema 类型', 'object', $adapter->schema()['type']);

    // 执行适配器
    $toolContext = new \Ai\Agent\Tool\ToolContext([]);
    $result = $adapter->execute(['path' => '/tmp/test.txt'], $toolContext);
    test('适配器执行返回 ToolResult', $result instanceof \Ai\Agent\Tool\ToolResult);
    test('适配器执行成功', $result->isSuccess());
    test('适配器执行结果包含文件路径', strpos((string) $result, '文件内容: /tmp/test.txt') !== false);

    // 关闭
    $manager->shutdown();
    test('manager shutdown 后服务器为空', count($manager->getServers()) === 0);

} catch (\Throwable $e) {
    test('Manager 测试异常: ' . $e->getMessage(), false);
    $manager->shutdown();
}

// ===== 3. McpManager addServers 批量配置 =====

echo "\n=== 三、addServers 批量配置 ===\n";

$manager2 = new McpManager();
$manager2->addServers([
    'mock' => [
        'command' => 'php',
        'args'    => [$mockServerScript],
        'options' => ['timeout' => 5],
    ],
    'invalid' => [
        'command' => '/nonexistent_binary_xyz',
    ],
]);

try {
    $manager2->initialize();
    // mock 应该成功，invalid 应该失败但不影响整体
    test('批量添加后 mock 服务器存在', $manager2->getServer('mock') !== null);
    test('invalid 服务器不存在', $manager2->getServer('invalid') === null);
    $manager2->shutdown();
} catch (\Throwable $e) {
    test('批量添加异常: ' . $e->getMessage(), false);
    $manager2->shutdown();
}

// ===== 4. McpToolAdapter 独立测试 =====

echo "\n=== 四、McpToolAdapter 独立测试 ===\n";

// 重新创建一个 client 用于适配器测试
$client2 = new McpClient('php', [$mockServerScript], ['timeout' => 5]);
$client2->initialize();

$adapter = new McpToolAdapter('mock__read_file', '读取文件', [
    'type' => 'object',
    'properties' => ['path' => ['type' => 'string']],
], $client2, 'read_file');

test('适配器 name()', $adapter->name() === 'mock__read_file');
test('适配器 description()', $adapter->description() === '读取文件');
test('适配器 schema() 包含 type', $adapter->schema()['type'] === 'object');

$toolContext = new \Ai\Agent\Tool\ToolContext([]);
$result = $adapter->execute(['path' => '/tmp/x.txt'], $toolContext);
test('适配器 execute 成功', $result->isSuccess());

$client2->shutdown();

// ===== 5. 集成到 AgentRuntime / Agent =====

echo "\n=== 五、集成到 AgentRuntime / Agent ===\n";

$ai = new AI();
$ai->setConfig([
    'model'      => 'deepseek-anthropic',
    'api_key'    => 'sk-test',
    'max_tokens' => 1024,
]);

$manager3 = new McpManager();
$manager3->addServer('mock', 'php', [$mockServerScript], ['timeout' => 5]);

$agent = new Agent($ai);
$agent->setMcpManager($manager3);
$runtime = $agent->getRuntime();
test('AgentRuntime 已设置 MCP 管理器', $runtime->getMcpManager() !== null);
assert_eq('getMcpManager 返回同一实例', true, $runtime->getMcpManager() === $manager3);

// 清理
$manager3->shutdown();

// 清理临时文件
@unlink($mockServerScript);

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);