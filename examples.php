<?php
/**
 * AI 标准库使用示例
 */

require_once __DIR__ . '/autoload.php';

use Ai\AI;
use Ai\Helpers\AIFile;
use Ai\Exceptions\AIException;

// ===========================================
// 示例 1: OpenAI GPT-4 基本对话
// ===========================================
echo "=== 示例 1: OpenAI GPT-4 基本对话 ===\n";

try {
    $ai = AI::create([
        'api_key' => 'sk-xxxxxxxxxxxxx', // 替换为你的 API Key
        'model' => 'gpt-4o',
    ]);
    
    $response = $ai->chat([
        'messages' => [
            ['role' => 'system', 'content' => '你是一个有帮助的助手'],
            ['role' => 'user', 'content' => '用一句话介绍人工智能']
        ],
        'temperature' => 0.7,
        'max_tokens' => 100,
    ]);
    
    echo "回复: " . $response->getContent() . "\n";
    echo "消耗 Tokens: " . $response->tokens() . "\n";
    echo "费用: $" . $response->cost() . "\n\n";
    
} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 2: Claude 3 对话
// ===========================================
echo "=== 示例 2: Claude 3 对话 ===\n";

try {
    $ai = AI::create([
        'api_key' => 'sk-ant-xxxxxxxxxxxxx', // 替换为你的 API Key
        'model' => 'claude-3-opus',
    ]);
    
    $response = $ai->chat([
        'messages' => [
            ['role' => 'user', 'content' => '什么是量子计算？']
        ],
        'max_tokens' => 500,
        'temperature' => 0.8,
    ]);
    
    echo "回复: " . $response->getContent() . "\n";
    echo "消耗 Tokens: " . $response->tokens() . "\n\n";
    
} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 3: Gemini 对话
// ===========================================
echo "=== 示例 3: Gemini 对话 ===\n";

try {
    $ai = AI::create([
        'api_key' => 'AIzaxxxxxxxxxxxxx', // 替换为你的 API Key
        'model' => 'gemini-pro',
    ]);
    
    $response = $ai->chat([
        'messages' => [
            ['role' => 'user', 'content' => '介绍一下深度学习']
        ],
    ]);
    
    echo "回复: " . $response->getContent() . "\n\n";
    
} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 4: 使用回调机制
// ===========================================
echo "=== 示例 4: 使用回调机制 ===\n";

try {
    $ai = AI::create([
        'api_key' => 'sk-xxxxxxxxxxxxx',
        'model' => 'gpt-4o',
    ]);
    
    // 请求前回调
    $ai->onBefore(function(&$payload) {
        echo "准备发送请求...\n";
        echo "消息数量: " . count($payload['messages']) . "\n";
    });
    
    // 响应后回调
    $ai->onResponse(function($response) {
        echo "收到响应，消耗 tokens: " . $response->tokens() . "\n";
        echo "费用: $" . $response->cost() . "\n";
    });
    
    $response = $ai->chat([
        'messages' => [
            ['role' => 'user', 'content' => 'Hello, AI!']
        ]
    ]);
    
    echo "回复: " . $response->getContent() . "\n\n";
    
} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 5: 多模态 - 图片识别
// ===========================================
echo "=== 示例 5: 多模态 - 图片识别 ===\n";

try {
    $ai = AI::create([
        'api_key' => 'sk-xxxxxxxxxxxxx',
        'model' => 'gpt-4o', // 需要支持视觉的模型
    ]);
    
    // 从本地文件加载图片
    $image = AIFile::fromPath('/path/to/image.jpg');
    
    // 或从 URL 加载
    // $image = AIFile::fromUrl('https://example.com/image.jpg');
    
    $response = $ai
        ->setAttachments([$image])
        ->chat([
            'messages' => [
                [
                    'role' => 'user',
                    'content' => '这张图片里有什么？'
                ]
            ]
        ]);
    
    echo "回复: " . $response->getContent() . "\n\n";
    
} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 6: 动态切换模型
// ===========================================
echo "=== 示例 6: 动态切换模型 ===\n";

try {
    $ai = AI::create([
        'api_key' => 'sk-xxxxxxxxxxxxx',
    ]);
    
    // 使用 GPT-4
    echo "使用 GPT-4:\n";
    $ai->setModel('gpt-4o');
    $response1 = $ai->chat([
        'messages' => [['role' => 'user', 'content' => '你好']]
    ]);
    echo "回复: " . $response1->getContent() . "\n";
    
    // 切换到 GPT-4.1
    echo "\n切换到 GPT-4.1:\n";
    $ai->setModel('gpt-4.1');
    $response2 = $ai->chat([
        'messages' => [['role' => 'user', 'content' => '你好']]
    ]);
    echo "回复: " . $response2->getContent() . "\n\n";
    
} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 7: 错误处理
// ===========================================
echo "=== 示例 7: 错误处理 ===\n";

use Ai\Exceptions\ConfigException;
use Ai\Exceptions\RequestException;

try {
    $ai = AI::create([
        'api_key' => 'invalid-key',
        'model' => 'gpt-4o',
    ]);
    
    $response = $ai->chat([
        'messages' => [['role' => 'user', 'content' => 'Hello']]
    ]);
    
} catch (ConfigException $e) {
    echo "配置错误: " . $e->getMessage() . "\n";
} catch (RequestException $e) {
    echo "请求失败: " . $e->getMessage() . "\n";
} catch (AIException $e) {
    echo "AI 错误: " . $e->getMessage() . "\n";
    echo "平台: " . $e->getPlatform() . "\n";
    echo "错误代码: " . $e->getErrorCode() . "\n";
}
echo "\n";

// ===========================================
// 示例 8: 自定义超时
// ===========================================
echo "=== 示例 8: 自定义超时 ===\n";

try {
    $ai = AI::create([
        'api_key' => 'sk-xxxxxxxxxxxxx',
        'model' => 'gpt-4o',
    ]);
    
    // 设置 60 秒超时
    $ai->setTimeout(60);
    
    $response = $ai->chat([
        'messages' => [
            [
                'role' => 'user',
                'content' => '写一篇关于人工智能的详细文章，至少1000字'
            ]
        ],
        'max_tokens' => 2000,
    ]);
    
    echo "文章长度: " . strlen($response->getContent()) . " 字符\n";
    echo "消耗 Tokens: " . $response->tokens() . "\n\n";
    
} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 9: 多轮对话
// ===========================================
echo "=== 示例 9: 多轮对话 ===\n";

try {
    $ai = AI::create([
        'api_key' => 'sk-xxxxxxxxxxxxx',
        'model' => 'gpt-4o',
    ]);
    
    $messages = [
        ['role' => 'system', 'content' => '你是一个有帮助的助手'],
    ];
    
    // 第一轮
    $messages[] = ['role' => 'user', 'content' => '我想学习 PHP'];
    $response1 = $ai->chat(['messages' => $messages]);
    echo "第一轮回复: " . $response1->getContent() . "\n\n";
    
    // 添加助手的回复
    $messages[] = ['role' => 'assistant', 'content' => $response1->getContent()];
    
    // 第二轮
    $messages[] = ['role' => 'user', 'content' => '有什么好的学习资源推荐？'];
    $response2 = $ai->chat(['messages' => $messages]);
    echo "第二轮回复: " . $response2->getContent() . "\n\n";
    
} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// ===========================================
// 示例 10: 完整的应用场景
// ===========================================
echo "=== 示例 10: 完整的应用场景 ===\n";

class AIAssistant
{
    protected $ai;
    protected $messages = [];
    
    public function __construct($apiKey, $model = 'gpt-4o')
    {
        $this->ai = AI::create([
            'api_key' => $apiKey,
            'model' => $model,
        ]);
        
        // 添加系统提示
        $this->messages[] = [
            'role' => 'system',
            'content' => '你是一个专业的编程助手，擅长回答技术问题'
        ];
        
        // 监控 token 使用
        $this->ai->onResponse(function($response) {
            $this->logUsage($response);
        });
    }
    
    public function ask($question)
    {
        $this->messages[] = ['role' => 'user', 'content' => $question];
        
        try {
            $response = $this->ai->chat(['messages' => $this->messages]);
            
            // 保存助手的回复
            $this->messages[] = [
                'role' => 'assistant',
                'content' => $response->getContent()
            ];
            
            return $response->getContent();
            
        } catch (AIException $e) {
            error_log('AI request failed: ' . $e->getMessage());
            return '抱歉，服务暂时不可用，请稍后再试。';
        }
    }
    
    protected function logUsage($response)
    {
        echo "[日志] 消耗 tokens: " . $response->tokens() . ", 费用: $" . $response->cost() . "\n";
    }
    
    public function reset()
    {
        $this->messages = [
            [
                'role' => 'system',
                'content' => '你是一个专业的编程助手，擅长回答技术问题'
            ]
        ];
    }
}

try {
    $assistant = new AIAssistant('sk-xxxxxxxxxxxxx');
    
    echo "问题 1: PHP 和 Python 有什么区别？\n";
    $answer1 = $assistant->ask('PHP 和 Python 有什么区别？');
    echo "回答: " . $answer1 . "\n\n";
    
    echo "问题 2: 各自适合什么场景？\n";
    $answer2 = $assistant->ask('各自适合什么场景？');
    echo "回答: " . $answer2 . "\n\n";
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
}

echo "所有示例执行完成！\n";
echo "\n---\n";
echo "更多示例：\n";
echo "  php examples_openrouter.php   # OpenRouter 聚合中转使用示例\n";
