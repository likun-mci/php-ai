<?php
/**
 * AI 标准库流式输出使用示例
 *
 * 两种模式：
 *   1. 只调 setStream(true)             —— 库按固定 SSE 报文直接写输出缓冲区（PHP-FPM / CLI）
 *   2. 再调 setStreamCallback($fn)      —— 分片交给回调，库不再有任何输出
 *                                          Swoole / Workerman 等常驻内存框架必须用这种方式
 *
 * 回调事件结构（已屏蔽平台差异）：
 *   [ 'type' => 'stream_chunk', 'content' => 增量文本|null, 'raw' => 平台原始分片 ]
 *   [ 'type' => 'stream_end',   'data' => [ 'content' =>…, 'model' =>…, 'usage' =>… ] ]
 */

require_once __DIR__ . '/autoload.php';

use Ai\AI;
use Ai\Exceptions\AIException;

// ===========================================
// 示例 1: OpenAI 流式输出
// ===========================================
echo "=== 示例 1: OpenAI 流式输出 ===\n";

try {
    $ai = AI::create([
        'api_key' => 'sk-xxxxxxxxxxxxx', // 替换为你的 API Key
        'model' => 'gpt-4o',
    ]);
    
    // 开启流式 + 注册分片回调（回调收到的 content 已由协议层归一化，跨平台通用）
    $ai->setStream(true)->setStreamCallback(function($event) {
        if ($event['type'] === 'stream_chunk' && $event['content'] !== null) {
            echo $event['content'];
            flush(); // 立即输出到浏览器
        }
    });
    
    $response = $ai->chat([
        'messages' => [
            ['role' => 'user', 'content' => '用100字介绍人工智能']
        ],
        'temperature' => 0.7,
    ]);
    
    // 流式输出完成后，仍然可以使用完整的 response
    echo "\n\n--- 完整响应 ---\n";
    echo "完整内容: " . $response->getContent() . "\n";
    echo "消耗 Tokens: " . $response->tokens() . "\n";
    echo "费用: $" . $response->cost() . "\n\n";
    
} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 2: Claude 流式输出
// ===========================================
echo "=== 示例 2: Claude 流式输出 ===\n";

try {
    $ai = AI::create([
        'api_key' => 'sk-ant-xxxxxxxxxxxxx', // 替换为你的 API Key
        'model' => 'claude-3-opus',
    ]);
    
    // 同一套回调代码即可，无需关心 Claude 的 content_block_delta 格式差异
    $ai->setStream(true)->setStreamCallback(function($event) {
        if ($event['type'] === 'stream_chunk' && $event['content'] !== null) {
            echo $event['content'];
            flush();
        }
    });
    
    $response = $ai->chat([
        'messages' => [
            ['role' => 'user', 'content' => '简单介绍量子计算']
        ],
    ]);
    
    echo "\n\n消耗 Tokens: " . $response->tokens() . "\n\n";
    
} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 3: 流式输出 + 自定义处理
// ===========================================
echo "=== 示例 3: 流式输出 + 自定义处理 ===\n";

try {
    $ai = AI::create([
        'api_key' => 'sk-xxxxxxxxxxxxx',
        'model' => 'gpt-4o',
    ]);
    
    $wordCount = 0;
    $charCount = 0;
    
    // 流式回调中进行实时统计
    $ai->setStream(true)->setStreamCallback(function($event) use (&$wordCount, &$charCount) {
        if ($event['type'] === 'stream_chunk' && $event['content'] !== null) {
            $content = $event['content'];
            echo $content;
            flush();
            
            // 统计字符数
            $charCount += mb_strlen($content);
            
            // 统计单词数
            if (preg_match('/\s/', $content)) {
                $wordCount++;
            }
        }
    });
    
    $response = $ai->chat([
        'messages' => [
            ['role' => 'user', 'content' => '写一段关于编程的话']
        ],
    ]);
    
    echo "\n\n--- 统计信息 ---\n";
    echo "实时统计字符数: $charCount\n";
    echo "实时统计单词数: $wordCount\n";
    echo "最终内容长度: " . mb_strlen($response->getContent()) . "\n\n";
    
} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 4: 流式输出到文件
// ===========================================
echo "=== 示例 4: 流式输出到文件 ===\n";

try {
    $ai = AI::create([
        'api_key' => 'sk-xxxxxxxxxxxxx',
        'model' => 'gpt-4o',
    ]);
    
    $file = fopen('/tmp/ai_output.txt', 'w');
    
    $ai->setStream(true)->setStreamCallback(function($event) use ($file) {
        if ($event['type'] === 'stream_chunk' && $event['content'] !== null) {
            $content = $event['content'];
            
            // 同时输出到屏幕和文件
            echo $content;
            fwrite($file, $content);
            flush();
        }
    });
    
    $response = $ai->chat([
        'messages' => [
            ['role' => 'user', 'content' => '写一篇短文']
        ],
    ]);
    
    fclose($file);
    
    echo "\n\n已保存到文件: /tmp/ai_output.txt\n\n";
    
} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 5: 关闭流式输出
// ===========================================
echo "=== 示例 5: 关闭流式输出 ===\n";

try {
    $ai = AI::create([
        'api_key' => 'sk-xxxxxxxxxxxxx',
        'model' => 'gpt-4o',
    ]);
    
    // 先启用流式输出
    $ai->setStream(true)->setStreamCallback(function($event) {
        if ($event['type'] === 'stream_chunk') echo ".";
    });
    
    echo "第一次请求（流式）: ";
    $response1 = $ai->chat([
        'messages' => [['role' => 'user', 'content' => '你好']]
    ]);
    echo "\n内容: " . $response1->getContent() . "\n\n";
    
    // 关闭流式输出
    $ai->setStream(false)->setStreamCallback(null);
    
    echo "第二次请求（非流式）:\n";
    $response2 = $ai->chat([
        'messages' => [['role' => 'user', 'content' => '再见']]
    ]);
    echo "内容: " . $response2->getContent() . "\n\n";
    
} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 6: Web 应用中的 SSE 流式输出
// ===========================================
echo "=== 示例 6: Web 应用中的 SSE 流式输出 ===\n";

// 模拟 SSE 响应（实际使用时在 HTTP 响应中）
function simulateSSE() {
    // 设置 SSE 响应头
    // header('Content-Type: text/event-stream');
    // header('Cache-Control: no-cache');
    // header('Connection: keep-alive');
    
    try {
        $ai = AI::create([
            'api_key' => 'sk-xxxxxxxxxxxxx',
            'model' => 'gpt-4o',
        ]);
        
        $ai->setStream(true)->setStreamCallback(function($event) {
            // 发送 SSE 格式数据到客户端
            if ($event['type'] === 'stream_chunk' && $event['content'] !== null) {
                $content = $event['content'];
                
                // SSE 格式
                echo "data: " . json_encode(['content' => $content]) . "\n\n";
                
                // 立即刷新输出缓冲区
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        });
        
        $response = $ai->chat([
            'messages' => [
                ['role' => 'user', 'content' => '介绍一下 PHP']
            ],
        ]);
        
        // 发送完成标记
        echo "data: " . json_encode(['done' => true, 'tokens' => $response->tokens()]) . "\n\n";
        flush();
        
    } catch (AIException $e) {
        echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
        flush();
    }
}

echo "（在实际 Web 应用中，客户端可以使用 EventSource 接收流式数据）\n";
echo "前端示例代码:\n";
echo <<<'JS'
const eventSource = new EventSource('/api/ai/chat');

eventSource.onmessage = function(e) {
    const data = JSON.parse(e.data);
    
    if (data.content) {
        // 实时显示内容
        document.getElementById('output').innerHTML += data.content;
    }
    
    if (data.done) {
        console.log('完成，消耗 tokens:', data.tokens);
        eventSource.close();
    }
    
    if (data.error) {
        console.error('错误:', data.error);
        eventSource.close();
    }
};
JS;
echo "\n\n";

// ===========================================
// 示例 7: 流式输出 + 多轮对话
// ===========================================
echo "=== 示例 7: 流式输出 + 多轮对话 ===\n";

try {
    $ai = AI::create([
        'api_key' => 'sk-xxxxxxxxxxxxx',
        'model' => 'gpt-4o',
    ]);
    
    $messages = [
        ['role' => 'system', 'content' => '你是一个有帮助的助手'],
    ];
    
    $ai->setStream(true)->setStreamCallback(function($event) {
        if ($event['type'] === 'stream_chunk' && $event['content'] !== null) {
            echo $event['content'];
            flush();
        }
    });
    
    // 第一轮
    echo "用户: 我想学习 PHP\n助手: ";
    $messages[] = ['role' => 'user', 'content' => '我想学习 PHP'];
    $response1 = $ai->chat(['messages' => $messages]);
    echo "\n\n";
    
    // 保存助手回复
    $messages[] = ['role' => 'assistant', 'content' => $response1->getContent()];
    
    // 第二轮
    echo "用户: 有什么好的资源？\n助手: ";
    $messages[] = ['role' => 'user', 'content' => '有什么好的资源？'];
    $response2 = $ai->chat(['messages' => $messages]);
    echo "\n\n";
    
    echo "对话完成\n";
    echo "第一轮消耗: " . $response1->tokens() . " tokens\n";
    echo "第二轮消耗: " . $response2->tokens() . " tokens\n\n";
    
} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

echo "所有流式输出示例执行完成！\n";
