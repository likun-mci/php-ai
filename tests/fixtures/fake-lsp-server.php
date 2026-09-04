#!/usr/bin/env php
<?php
/**
 * 假语言服务器——只为测试 LspClient / LspTool 用
 *
 * 说标准 LSP：Content-Length 分帧 + JSON-RPC 2.0 over stdio。
 * 会在响应前先发一条 window/logMessage 通知，用来验证客户端能跳过穿插的通知。
 */

function sendMsg(array $payload)
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    echo 'Content-Length: ' . strlen($json) . "\r\n\r\n" . $json;
    flush();
}

$buffer = '';
while (!feof(STDIN)) {
    $chunk = fread(STDIN, 8192);
    if ($chunk === false || $chunk === '') {
        usleep(10000);
        continue;
    }
    $buffer .= $chunk;

    while (preg_match('/Content-Length: (\d+)\r?\n\r?\n/', $buffer, $m, PREG_OFFSET_CAPTURE)) {
        $len = (int) $m[1][0];
        $start = $m[0][1] + strlen($m[0][0]);
        if (strlen($buffer) < $start + $len) {
            break;   // 还没收够，回外层继续读
        }
        $body = substr($buffer, $start, $len);
        $buffer = substr($buffer, $start + $len);
        $req = json_decode($body, true);
        if (!is_array($req) || !isset($req['method'])) {
            continue;
        }
        $method = $req['method'];
        $id = isset($req['id']) ? $req['id'] : null;

        if ($method === 'exit') {
            exit(0);
        }
        if ($id === null) {
            continue;   // 通知（initialized / didOpen）不回响应
        }

        // 先插一条通知，客户端必须跳过它继续等自己的响应
        sendMsg(['jsonrpc' => '2.0', 'method' => 'window/logMessage',
                 'params' => ['type' => 3, 'message' => 'fake server working']]);

        $result = null;
        switch ($method) {
            case 'initialize':
                $result = ['capabilities' => [
                    'definitionProvider' => true,
                    'referencesProvider' => true,
                    'hoverProvider'      => true,
                    'documentSymbolProvider' => true,
                ]];
                break;
            case 'textDocument/definition':
                // 单个 Location 形态
                $result = [
                    'uri'   => 'file:///tmp/lsp-proj/src/Target.php',
                    'range' => ['start' => ['line' => 9, 'character' => 4],
                                'end'   => ['line' => 9, 'character' => 12]],
                ];
                break;
            case 'textDocument/references':
                // Location[] 形态
                $result = [
                    ['uri' => 'file:///tmp/lsp-proj/src/A.php',
                     'range' => ['start' => ['line' => 2, 'character' => 0], 'end' => ['line' => 2, 'character' => 5]]],
                    ['uri' => 'file:///tmp/lsp-proj/src/B.php',
                     'range' => ['start' => ['line' => 41, 'character' => 8], 'end' => ['line' => 41, 'character' => 13]]],
                ];
                break;
            case 'textDocument/hover':
                $result = ['contents' => ['kind' => 'markdown', 'value' => "function login(string \$user): bool"]];
                break;
            case 'textDocument/documentSymbol':
                $result = [
                    ['name' => 'UserService', 'kind' => 5,
                     'range' => ['start' => ['line' => 4, 'character' => 0], 'end' => ['line' => 40, 'character' => 1]]],
                    ['name' => 'login', 'kind' => 6,
                     'location' => ['uri' => 'file:///tmp/lsp-proj/src/A.php',
                                    'range' => ['start' => ['line' => 11, 'character' => 4], 'end' => ['line' => 20, 'character' => 5]]]],
                ];
                break;
        }
        sendMsg(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }
}
