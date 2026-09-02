<?php
/**
 * Phase 4.2 测试——Skill 2.0 / MCP 传输协议 / Browser Agent
 *
 * 覆盖：
 *   1. SKILL.md frontmatter 块标量（knowledge: |）与 files 通配符
 *   2. Skill discover（不预读正文）/ loadByName / forFile / activateForFile
 *   3. 技能知识块注入
 *   4. MCP 传输抽象：stdio / http / websocket 的构造与选择
 *   5. McpHttpTransport 的 JSON 与 SSE 响应解析（注入 sender，不联网）
 *   6. McpManager registerServer / connect / disconnect / isConnected /
 *      discoverTools / status
 *   7. BrowserTool 参数校验与无浏览器环境下的降级
 *
 * 不联网、不需要 Key。运行：php tests/agent_phase42_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Mcp\McpClient;
use Ai\Agent\Mcp\McpHttpTransport;
use Ai\Agent\Mcp\McpManager;
use Ai\Agent\Mcp\McpStdioTransport;
use Ai\Agent\Mcp\McpTransportInterface;
use Ai\Agent\Mcp\McpWebSocketTransport;
use Ai\Agent\Skill\SkillManager;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tools\BrowserSession;
use Ai\Agent\Tools\BrowserTool;

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
    if ($expected !== $actual) {
        echo "    期望: " . var_export($expected, true) . "\n";
        echo "    实际: " . var_export($actual, true) . "\n";
    }
}

$tmpDir = sys_get_temp_dir() . '/php_ai_p42_' . getmypid();
$skillsDir = $tmpDir . '/skills';
@mkdir($skillsDir . '/wordpress', 0777, true);
@mkdir($skillsDir . '/deploy', 0777, true);

file_put_contents($skillsDir . '/wordpress/SKILL.md', <<<'MD'
---
name: wordpress
description: WordPress 插件开发
allowed-tools:
  - edit_file
  - bash
files:
  - wp-content
  - "*.wp.php"
knowledge: |
  WordPress 通过 hooks（action / filter）扩展。
  插件入口在 wp-content/plugins/。
---
# WordPress 插件开发

完整正文：先注册 hook，再实现回调。
MD
);

file_put_contents($skillsDir . '/deploy/SKILL.md', <<<'MD'
---
name: deploy
description: 部署到生产环境
---
# 部署流程

1. 构建
2. 上传
MD
);

// ===== 一、frontmatter 块标量与通配符 =====

echo "\n=== 一、frontmatter 解析 ===\n";

$parsed = SkillManager::parseFrontmatter(file_get_contents($skillsDir . '/wordpress/SKILL.md'));
$meta = $parsed['meta'];

assert_eq('标量键', 'wordpress', $meta['name']);
assert_eq('列表键', ['edit_file', 'bash'], $meta['allowed-tools']);
assert_eq('通配符列表', ['wp-content', '*.wp.php'], $meta['files']);
test('块标量保留多行', isset($meta['knowledge']) && strpos($meta['knowledge'], "\n") !== false);
test('块标量内容正确', strpos($meta['knowledge'], 'hooks') !== false);
test('块标量不吞正文', strpos($parsed['content'], '# WordPress 插件开发') === 0);

// ===== 二、discover / loadByName / forFile =====

echo "\n=== 二、Skill 发现与按需加载 ===\n";

$sm = new SkillManager();
$found = $sm->discover($skillsDir);
sort($found);
assert_eq('发现两个技能', ['deploy', 'wordpress'], $found);

$wp = $sm->get('wordpress');
assert_eq('描述已读', 'WordPress 插件开发', $wp->getDescription());
test('discover 不预读正文', !$wp->isLoaded());
test('knowledge 已读', strpos($wp->getKnowledge(), 'hooks') !== false);
assert_eq('files 通配符已读', ['wp-content', '*.wp.php'], $wp->getFilePatterns());

$content = $sm->loadByName('wordpress');
test('loadByName 读到正文', strpos($content, '完整正文') !== false);
test('loadByName 之后标为已加载', $wp->isLoaded());
test('loadByName 不激活技能', !$wp->isActive());
assert_eq('loadByName 不存在的技能返回空', '', $sm->loadByName('nope'));

$matched = $sm->forFile('/var/www/wp-content/plugins/foo/foo.php');
assert_eq('路径片段匹配', ['wordpress'], array_keys($matched));
assert_eq('通配符匹配', ['wordpress'], array_keys($sm->forFile('/tmp/theme.wp.php')));
assert_eq('无 files 的技能永远不匹配', [], array_keys($sm->forFile('/var/www/deploy.sh')));
assert_eq('不匹配的路径返回空', [], array_keys($sm->forFile('/var/www/src/Auth.php')));

$activated = $sm->activateForFile('/var/www/wp-content/plugins/foo.php');
assert_eq('按文件激活技能', ['wordpress'], $activated);
test('激活后技能生效', $sm->get('wordpress')->isActive());
assert_eq('重复激活不再返回', [], $sm->activateForFile('/var/www/wp-content/x.php'));
test('激活后合并了 allowed-tools', in_array('edit_file', $sm->getAllowedTools(), true));

// ===== 三、技能知识注入 =====

echo "\n=== 三、技能知识注入 ===\n";

$knowledge = $sm->knowledgeForPrompt();
test('知识块含标签', strpos($knowledge, '<skill-knowledge>') === 0);
test('知识块含技能名', strpos($knowledge, '## wordpress') !== false);
test('知识块不含完整正文', strpos($knowledge, '完整正文') === false);
test('没有 knowledge 的技能不占位', strpos($knowledge, '## deploy') === false);

$smEmpty = new SkillManager();
assert_eq('无技能时知识块为空', '', $smEmpty->knowledgeForPrompt());
$sm->setEnabled(false);
assert_eq('停用后不注入知识', '', $sm->knowledgeForPrompt());
$sm->setEnabled(true);

// loadFromDir 仍然预读正文（向后兼容）
$sm2 = new SkillManager();
$sm2->loadFromDir($skillsDir);
test('loadFromDir 预读正文', $sm2->get('wordpress')->isLoaded());
test('loadFromDir 也读 knowledge', $sm2->get('wordpress')->getKnowledge() !== '');

// ===== 四、MCP 传输抽象 =====

echo "\n=== 四、MCP 传输抽象 ===\n";

$stdio = new McpStdioTransport('php', ['-v']);
test('stdio 实现传输接口', $stdio instanceof McpTransportInterface);
assert_eq('stdio 名称', 'stdio', $stdio->name());
test('未 open 时未连接', !$stdio->isOpen());

$http = new McpHttpTransport('https://example.com/mcp');
assert_eq('http 名称', 'http', $http->name());
assert_eq('http 端点', 'https://example.com/mcp', $http->getUrl());
test('http open 前未连接', !$http->isOpen());
$http->open();
test('http open 后标记为连接', $http->isOpen());
$http->close();
test('http close 后断开', !$http->isOpen());

$ws = new McpWebSocketTransport('wss://example.com/mcp');
assert_eq('websocket 名称', 'websocket', $ws->name());
test('websocket 未连接', !$ws->isOpen());

$client = McpClient::fromConfig(['transport' => 'http', 'url' => 'https://example.com/mcp']);
assert_eq('fromConfig 选中 http', 'http', $client->getTransportName());
$clientWs = McpClient::fromConfig(['transport' => 'websocket', 'url' => 'wss://example.com/mcp']);
assert_eq('fromConfig 选中 websocket', 'websocket', $clientWs->getTransportName());
$clientStdio = McpClient::fromConfig(['command' => 'php', 'args' => ['-v']]);
assert_eq('fromConfig 默认 stdio', 'stdio', $clientStdio->getTransportName());
$clientCustom = McpClient::fromConfig(['transport' => $http]);
test('fromConfig 接受传输对象', $clientCustom->getTransport() === $http);

// 老构造方式仍然可用
$legacy = new McpClient('php', ['-v'], ['timeout' => 5]);
assert_eq('旧构造仍是 stdio', 'stdio', $legacy->getTransportName());
test('旧构造未启动时 isRunning 为假', !$legacy->isRunning());

// ===== 五、HTTP 传输的响应解析 =====

echo "\n=== 五、HTTP 传输响应解析 ===\n";

$captured = [];
$jsonTransport = new McpHttpTransport('https://example.com/mcp', [
    'sender' => function ($url, $body, $headers, $timeout) use (&$captured) {
        $captured[] = ['url' => $url, 'body' => $body, 'headers' => $headers];
        $payload = json_decode($body, true);
        return [
            'body' => json_encode([
                'jsonrpc' => '2.0',
                'id'      => $payload['id'],
                'result'  => ['tools' => [['name' => 'echo', 'description' => '回显']]],
            ]),
            'headers' => ['Content-Type: application/json', 'Mcp-Session-Id: sess-123'],
        ];
    },
]);

$response = $jsonTransport->request(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list'], 5);
test('JSON 响应被解析', is_array($response) && isset($response['result']['tools']));
assert_eq('响应 id 透传', 7, $response['id']);
assert_eq('会话 ID 被记住', 'sess-123', $jsonTransport->getSessionId());
test('请求带 Content-Type', in_array('Content-Type: application/json', $captured[0]['headers'], true));
test('请求声明接受 SSE', (bool) preg_grep('#text/event-stream#', $captured[0]['headers']));

// 第二次请求应带上会话 ID
$jsonTransport->request(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/list'], 5);
test('后续请求带会话 ID', in_array('Mcp-Session-Id: sess-123', $captured[1]['headers'], true));

$sseTransport = new McpHttpTransport('https://example.com/mcp', [
    'sender' => function ($url, $body, $headers, $timeout) {
        $payload = json_decode($body, true);
        $lines = [
            'event: message',
            'data: ' . json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/progress']),
            '',
            'data: ' . json_encode(['jsonrpc' => '2.0', 'id' => $payload['id'], 'result' => ['ok' => true]]),
            '',
        ];
        return ['body' => implode("\n", $lines), 'headers' => ['Content-Type: text/event-stream']];
    },
]);
$sseResponse = $sseTransport->request(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping'], 5);
test('SSE 响应被解析', is_array($sseResponse) && !empty($sseResponse['result']['ok']));
assert_eq('SSE 跳过了通知取到响应', 3, $sseResponse['id']);

// ===== 六、McpManager 连接管理 =====

echo "\n=== 六、McpManager 连接管理 ===\n";

$mockServer = $tmpDir . '/mock_mcp.php';
file_put_contents($mockServer, <<<'PHPMOCK'
<?php
// 极简 MCP 服务器：读 Content-Length 分帧的 JSON-RPC，回固定响应
$buffer = '';
while (!feof(STDIN)) {
    $chunk = fread(STDIN, 8192);
    if ($chunk === false || $chunk === '') { usleep(10000); continue; }
    $buffer .= $chunk;
    while (preg_match('/Content-Length: (\d+)\r?\n\r?\n/', $buffer, $m, PREG_OFFSET_CAPTURE)) {
        $len = (int) $m[1][0];
        $start = $m[0][1] + strlen($m[0][0]);
        if (strlen($buffer) < $start + $len) { break 2; }
        $req = json_decode(substr($buffer, $start, $len), true);
        $buffer = substr($buffer, $start + $len);
        if (!isset($req['id'])) { continue; }
        $method = isset($req['method']) ? $req['method'] : '';
        if ($method === 'initialize') {
            $result = ['protocolVersion' => '2024-11-05', 'serverInfo' => ['name' => 'mock', 'version' => '1.0']];
        } elseif ($method === 'tools/list') {
            $result = ['tools' => [['name' => 'echo', 'description' => '回显', 'inputSchema' => ['type' => 'object']]]];
        } else {
            $result = ['content' => [['type' => 'text', 'text' => 'ok']]];
        }
        $json = json_encode(['jsonrpc' => '2.0', 'id' => $req['id'], 'result' => $result]);
        echo 'Content-Length: ' . strlen($json) . "\r\n\r\n" . $json;
        flush();
    }
}
PHPMOCK
);

$manager = new McpManager();
$manager->registerServer('mock', ['command' => 'php', 'args' => [$mockServer], 'timeout' => 5]);
$manager->registerServer('remote', ['transport' => 'http', 'url' => 'https://example.com/mcp']);

assert_eq('登记了两个服务器', ['mock', 'remote'], $manager->serverNames());
test('未连接时 isConnected 为假', !$manager->isConnected('mock'));

$status = $manager->status();
assert_eq('状态里 stdio 默认传输', 'stdio', $status['mock']['transport']);
assert_eq('状态里远程传输', 'http', $status['remote']['transport']);
test('状态里未连接', $status['mock']['connected'] === false);

test('connect 成功', $manager->connect('mock'));
test('连接后 isConnected 为真', $manager->isConnected('mock'));
test('重复 connect 直接返回真', $manager->connect('mock'));
assert_eq('未登记的服务器连不上', false, $manager->connect('ghost'));

$tools = $manager->discoverTools('mock');
assert_eq('发现 1 个工具', 1, count($tools));
assert_eq('工具名正确', 'echo', $tools[0]['name']);
assert_eq('未登记服务器发现工具返回空', [], $manager->discoverTools('ghost'));

$status = $manager->status();
test('连接后状态更新', $status['mock']['connected'] === true);

$manager->disconnect('mock');
test('disconnect 后断开', !$manager->isConnected('mock'));
$manager->disconnect('ghost');   // 不存在的服务器不该炸
test('disconnect 不存在的服务器安全', true);

// addServers 兼容远程配置
$manager2 = new McpManager();
$manager2->addServers([
    'local'  => ['command' => 'php', 'args' => [$mockServer]],
    'remote' => ['transport' => 'http', 'url' => 'https://example.com/mcp'],
    'broken' => ['args' => []],   // 没有 command 也没有 transport，跳过
]);
assert_eq('addServers 登记两个（跳过无效项）', ['local', 'remote'], $manager2->serverNames());

// ===== 七、BrowserTool =====

echo "\n=== 七、BrowserTool ===\n";

$browser = new BrowserTool();
assert_eq('工具名', 'browser', $browser->name());
test('描述非空', $browser->description() !== '');
$schema = $browser->schema();
assert_eq('必填参数', ['action'], $schema['required']);
test('action 枚举含 open', in_array('open', $schema['properties']['action']['enum'], true));
test('action 枚举含 screenshot', in_array('screenshot', $schema['properties']['action']['enum'], true));

$ctx = new ToolContext(['workdir' => $tmpDir]);

$result = $browser->execute([], $ctx);
test('缺 action 报错', !$result->isSuccess());
test('缺 action 的错误信息可读', strpos($result->getError(), 'action') !== false);

$result = $browser->execute(['action' => 'close'], $ctx);
test('close 不需要浏览器也能成功', $result->isSuccess());

if (BrowserSession::isAvailable()) {
    echo "  （检测到 Chrome，跳过降级用例）\n";
    $result = $browser->execute(['action' => 'open'], $ctx);
    test('open 缺 url 报错', !$result->isSuccess());
    $result = $browser->execute(['action' => 'open', 'url' => 'file:///etc/passwd'], $ctx);
    test('拒绝非 http(s) 地址', !$result->isSuccess());
    $browser->close();
} else {
    $result = $browser->execute(['action' => 'open', 'url' => 'https://example.com'], $ctx);
    test('无浏览器时返回错误而非崩溃', !$result->isSuccess());
    test('错误信息说明缺少浏览器', strpos($result->getError(), 'Chrome') !== false);
    assert_eq('isAvailable 与 detectBinary 一致', '', BrowserSession::detectBinary());
}

$session = new BrowserSession(['binary' => '/nonexistent/chrome']);
test('不存在的浏览器启动失败', !$session->launch());
test('未启动时 isRunning 为假', !$session->isRunning());
$session->close();   // 不该炸
test('未启动时 close 安全', true);

// ===== 清理 =====

exec('rm -rf ' . escapeshellarg($tmpDir));

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);
