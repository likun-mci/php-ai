<?php
/**
 * Claude Code CLI 程序调用示例
 *
 * 直接调用本机安装的 claude 可执行程序（Claude Code CLI），
 * 与 HTTP API（Ai\Protocol\Claude）不同，适合让 AI 直接操作工作区文件。
 */

require_once __DIR__ . '/autoload.php';

use Ai\Cli\ClaudeCode;
use Ai\Cli\ClaudeCodeSession;
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
        ->setTools(['Read', 'Grep', 'Glob'])               // 限定可用工具集
        ->setModel('claude-sonnet-5')                      // 指定模型
        ->setFallbackModel(['haiku'])                      // 过载时降级
        ->setEffort('high')                                // 提高推理投入
        ->setMaxBudgetUsd(0.5)                             // 花费上限，超出即终止
        ->setFlag('max-turns', 3)                          // 未提供专用方法的参数走 setFlag
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

// ===========================================
// 示例 5: 结构化输出（--json-schema）
// ===========================================
echo "=== 示例 5: 结构化输出 ===\n";

try {
    $cli = ClaudeCode::create(['workdir' => __DIR__]);
    $cli->setJsonSchema([
        'type'       => 'object',
        'properties' => [
            'files' => ['type' => 'array', 'items' => ['type' => 'string']],
            'count' => ['type' => 'integer'],
        ],
        'required'   => ['files', 'count'],
    ]);

    $response = $cli->chat('列出当前目录下的 PHP 文件名与数量，按 schema 输出。');
    $data = $response->getStructured();      // 直接拿数组，解析失败返回 null
    echo "文件数: " . ($data['count'] ?? '解析失败') . "\n";
    print_r($data['files'] ?? []);
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 6: 常驻双工会话（对齐官方 IDE 插件的工作方式）
// ===========================================
echo "\n=== 示例 6: 常驻双工会话 ===\n";

try {
    $session = ClaudeCodeSession::create([
        'workdir'      => __DIR__,
        'turn_timeout' => 300,
    ]);

    // 工具权限实时回调 PHP 决策，等价于 IDE 里弹出的"是否允许执行"
    $session->onPermission(function (array $req) {
        echo "  [权限询问] {$req['tool_name']}\n";
        if ($req['tool_name'] === 'Bash') {
            return '本环境禁止执行 shell 命令';        // 字符串 = 拒绝并给模型说明理由
        }
        return true;                                  // true = 放行；也可返回
                                                      // ['behavior'=>'allow','updatedInput'=>[...]] 改写入参
    });

    // 第一轮：带细分事件的流式回调
    $r1 = $session->send('看一下当前目录都有哪些文件', function ($event, $data) {
        if ($event === 'init')        echo "  [init] 可用工具 " . count($data['tools']) . " 个\n";
        if ($event === 'tool_use')    echo "  [工具] {$data['name']}\n";
        if ($event === 'tool_result') echo "  [结果] " . ($data['is_error'] ? '失败' : '成功') . "\n";
        if ($event === 'text')        echo "  [文本] " . trim($data) . "\n";
    });
    echo "第一轮: " . mb_substr(trim($r1->getContent()), 0, 80) . "\n";

    // 第二轮：同一进程，上下文常驻，无需 --resume 重放历史
    $r2 = $session->send('刚才第一个文件大概是做什么的？一句话说明。');
    echo "第二轮: " . mb_substr(trim($r2->getContent()), 0, 80) . "\n";
    echo "同一会话: " . ($r1->getSessionId() === $r2->getSessionId() ? '是' : '否') . "\n";
    echo "累计费用: $" . ($r1->getCostUsd() + $r2->getCostUsd()) . "\n";

    echo "退出码: " . $session->close() . "\n";
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 7: 会话中断 + 运行时热切换
// ===========================================
echo "\n=== 示例 7: 中断与运行时控制 ===\n";

try {
    $session = ClaudeCodeSession::create(['workdir' => __DIR__, 'turn_timeout' => 300]);

    // 硬性禁用 Bash：从工具集里摘掉，模型根本看不到
    // （只靠 onPermission 拦不住 CLI 判定为安全的沙箱只读命令）
    $session->setDisallowedTools(['Bash']);

    $interrupted = false;
    $res = $session->send('逐个读取当前目录所有文件并详细总结', function ($event, $data) use ($session, &$interrupted) {
        if ($event === 'tool_use' && !$interrupted) {
            $interrupted = true;
            echo "  [中断] 相当于 IDE 里的停止按钮\n";
            $session->interrupt();
        }
    });
    echo "结束类型: " . $res->getSubtype() . "\n";       // error_during_execution
    echo "进程仍存活: " . ($session->isRunning() ? '是' : '否') . "\n";

    // 运行时热切换，无需重启进程
    $session->setPermissionMode('plan');       // 切成只规划不改动
    $session->switchThinkingTokens(31999);     // IDE 插件用的思考预算

    $session->close();
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 8: 信息查询（不发起对话，不消耗模型额度）
// ===========================================
echo "\n=== 示例 8: 信息查询 ===\n";

try {
    $cli = ClaudeCode::create();

    // 子命令类查询（毫秒级）
    echo "CLI 版本: " . $cli->getVersion() . "\n";
    $auth = $cli->getAuthStatus();
    echo "登录状态: " . ($auth['loggedIn'] ? '已登录' : '未登录')
        . " | 方式: {$auth['authMethod']} | 订阅: {$auth['subscriptionType']}\n";

    // 模型列表：返回值可直接传给 setModel()，与 AI::listModels() 约定一致
    echo "可用模型: " . implode(', ', $cli->listModels()) . "\n";
    foreach ($cli->listModels(true) as $model) {
        printf("  %-20s → %-28s %s\n", $model['value'], $model['resolvedModel'], $model['displayName']);
    }

    // 额度与限流
    echo "额度用量:\n";
    foreach ($cli->getRateLimits() as $limit) {
        printf("  %-14s 已用 %5.1f%%  %s  %s 后重置\n",
            $limit['key'], $limit['percent'], $limit['severity'],
            gmdate('H:i:s', $limit['resets_in']));
    }

    $usage = $cli->getUsage();
    echo "订阅类型: " . $usage['subscription_type'] . "\n";
    echo "本周请求数: " . ($usage['behaviors']['week']['request_count'] ?? '-') . "\n";
    echo "MCP 服务器: " . count($cli->getMcpServers()) . " 个\n";

    // 生效设置（user / project / local 合并后的结果）
    $settings = $cli->getSettings();
    echo "生效设置键: " . implode(', ', array_keys($settings['effective'] ?? [])) . "\n";
} catch (ConfigException $e) {
    echo "未找到 claude：" . $e->getMessage() . "\n";
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 9: 会话内查询本次累计花费
// ===========================================
echo "\n=== 示例 9: 会话花费统计 ===\n";

try {
    $session = ClaudeCodeSession::create(['workdir' => __DIR__, 'turn_timeout' => 300]);

    $session->send('用一句话说明什么是 PSR-4。');
    $session->send('再举一个目录映射的例子。');

    // 会话类的同名方法会复用已运行的进程，拿到的是本会话真实累计值
    $usage = $session->getUsage();
    echo "本会话累计花费: $" . $usage['session']['total_cost_usd'] . "\n";
    echo "代码改动行数: +" . $usage['session']['total_lines_added']
        . " / -" . $usage['session']['total_lines_removed'] . "\n";
    echo "--- 花费报告 ---\n" . $session->getSessionCost() . "\n";

    $session->close();
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 10: 处理过程中继续提需求（非阻塞事件泵）
// ===========================================
echo "\n=== 示例 10: 轮内插入新需求 ===\n";

try {
    $session = ClaudeCodeSession::create(['workdir' => __DIR__, 'turn_timeout' => 300]);

    // 协程环境（Swoole / Workerman）务必设置，否则内部等待会钉住整个 worker：
    // $session->setSleeper(function ($sec) { \Swoole\Coroutine::sleep($sec); });

    $onEvent = function ($event, $data) {
        if ($event === 'posted')    { echo "  [已排队] {$data['id']}\n"; }
        if ($event === 'delivered') { echo "  [已送达] {$data['id']}\n"; }
        if ($event === 'tool_use')  { echo "  [工具] {$data['name']}\n"; }
    };

    $session->post('逐个读取当前目录的 php 文件，总结每个文件的职责');   // 立即返回

    $injected = false;
    while ($active = $session->tick($onEvent)) {
        // 这里可以做任何事：收 WebSocket 帧、查客户端是否还连着、把增量落库……
        if (!$injected && $session->getAvailableTools()) {
            $injected = true;
            // 轮内插入：CLI 会在当前这次工具调用执行完后并入本轮，不打断正在跑的工具
            $session->post('改主意了，只看 src 目录，其余跳过');
        }
        usleep(20000);
    }

    $res = $session->takeResult();   // 取走即清空；结构与 send() 的返回完全一致
    if ($res) {
        echo "本轮消息数: " . $res->getNumTurns() . "（多条用户消息仍只有一个 result）\n";
        echo "回复: " . mb_substr($res->getContent(), 0, 100) . "...\n";
    }

    $session->close();
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}
