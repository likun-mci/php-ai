<?php
/**
 * Claude Code CLI 程序调用示例
 *
 * 直接调用本机安装的 claude 可执行程序（Claude Code CLI），
 * 与 HTTP API（Ai\Protocol\Claude）不同，适合让 AI 直接操作工作区文件。
 */

require_once __DIR__ . '/autoload.php';

use Ai\Cli\ClaudeCode;
use Ai\Exceptions\ConfigException;

// ===========================================
// 示例 1: 基本对话（自动检测 claude 路径）
// ===========================================
echo "=== 示例 1: 基本对话 ===\n";

try {
    $cli = ClaudeCode::create([
        // 不传 binary 时自动检测（含缓存）；也可手动指定：
        // 'binary' => '/usr/local/bin/claude',
        'workdir' => __DIR__,
    ]);

    $response = $cli->chat('用一句话介绍 Claude Code，不要编辑任何文件。');
    echo "回复: " . $response->getContent() . "\n";
    echo "模型: " . $response->getModel() . "\n";
    echo "会话: " . $response->getSessionId() . "\n";
    echo "费用: $" . $response->getCostUsd() . "\n\n";

    // 下一轮自动续接同一会话
    $cli->setSessionId($response->getSessionId());
} catch (ConfigException $e) {
    echo "未找到 claude：" . $e->getMessage() . "\n\n";
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 2: 流式调用（逐事件回调，可直接转发 SSE）
// ===========================================
echo "=== 示例 2: 流式调用 ===\n";

try {
    $cli = ClaudeCode::create([
        'workdir' => __DIR__,
    ]);

    $cli->runStream('请阅读 README.md 并总结前三行。', function ($event, $data) {
        if ($event === 'start') {
            echo "[会话续接: " . ($data['resume'] ? '是' : '否') . "]\n";
        } elseif ($event === 'message') {
            // 原始 stream-json 事件原样透传（assistant / user / result ...）
            // echo json_encode($data) . "\n";
        } elseif ($event === 'stderr') {
            // 排查用：echo "[stderr] " . $data;
        } elseif ($event === 'result') {
            echo "[最终文本] " . $data['content'] . "\n";
            echo "[会话ID] " . $data['session_id'] . "\n";
        } elseif ($event === 'done') {
            echo "[完成]\n";
        }
    });
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 3: 自定义参数（覆盖默认 / 新增参数）
// ===========================================
echo "=== 示例 3: 自定义参数 ===\n";

try {
    $cli = ClaudeCode::create(['workdir' => __DIR__]);

    $cli
        ->setFlag('allowedTools', 'Read Grep Glob')        // 收紧工具权限
        ->setFlag('model', 'claude-sonnet-4-5')            // 指定模型
        ->setFlag('max-turns', 3)                          // 限制轮数
        ->removeFlag('verbose')                            // 去掉默认 verbose
        ->setTimeout(120);                                 // 超时 120 秒

    // 查看最终生效的参数
    print_r($cli->getFlags());

    $response = $cli->chat('当前工作区有哪些文件？只列出文件名。');
    echo "回复: " . $response->getContent() . "\n";
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 4: 自定义执行器（如经 SSH/SFTP 远程执行）
// ===========================================
echo "=== 示例 4: 自定义执行器 ===\n";

try {
    $cli = ClaudeCode::create(['workdir' => __DIR__]);

    $cli->setRunner(function ($cmd, $onChunk) {
        // 演示：把命令交给远程环境执行（此处为本地 echo 模拟）。
        // 真实场景可用 ssh2_exec / proc_open 在宿主机执行：
        //   $stream = ssh2_exec($conn, $cmd); 边读边回调 $onChunk($chunk, 'out')
        $output = "{\"type\":\"assistant\",\"message\":{\"model\":\"claude-sonnet-4-5\",\"content\":[{\"type\":\"text\",\"text\":\"（远程执行示例）\"}]}}\n"
            . "{\"type\":\"result\",\"subtype\":\"success\",\"result\":\"（远程执行示例）\",\"session_id\":\"demo-session-1\",\"usage\":{\"input_tokens\":10,\"output_tokens\":5},\"total_cost_usd\":0.001}\n";
        $onChunk($output, 'out');
        return 0;
    });

    $response = $cli->chat('你好');
    echo "回复: " . $response->getContent() . "\n";
    echo "会话: " . $response->getSessionId() . "\n";
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}
