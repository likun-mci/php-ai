<?php
/**
 * AI 标准库 - 模型列表功能示例
 */

require_once __DIR__ . '/autoload.php';

use Ai\AI;
use Ai\Exceptions\AIException;

echo "========================================\n";
echo "AI SDK - 模型列表功能演示\n";
echo "========================================\n\n";

// ===========================================
// 示例 1: OpenAI 模型列表
// ===========================================
echo "=== 示例 1: OpenAI 模型列表 ===\n";

try {
    $ai = AI::create([
        'api_key' => 'sk-xxxxxxxxxxxxx', // 替换为你的 API Key
        'model' => 'gpt-4o', // 设置任意 OpenAI 模型
    ]);
    
    $models = $ai->listModels();
    
    if ($models !== null) {
        echo "OpenAI 可用模型:\n";
        foreach ($models as $modelId => $modelName) {
            echo "  - {$modelId}: {$modelName}\n";
        }
        echo "\n共 " . count($models) . " 个模型\n";
    } else {
        echo "无法获取模型列表\n";
    }
    
} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n";
}
echo "\n";

// ===========================================
// 示例 2: Claude 模型列表
// ===========================================
echo "=== 示例 2: Claude 模型列表 ===\n";

try {
    $ai = AI::create([
        'api_key' => 'sk-ant-xxxxxxxxxxxxx', // 替换为你的 API Key
        'model' => 'claude-3-opus',
    ]);
    
    $models = $ai->listModels();
    
    if ($models !== null) {
        echo "Claude 可用模型（预定义列表）:\n";
        foreach ($models as $modelId => $modelName) {
            echo "  - {$modelId}: {$modelName}\n";
        }
    } else {
        echo "该平台不支持模型列表\n";
    }
    
} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n";
}
echo "\n";

// ===========================================
// 示例 3: Gemini 模型列表
// ===========================================
echo "=== 示例 3: Gemini 模型列表 ===\n";

try {
    $ai = AI::create([
        'api_key' => 'AIzaxxxxxxxxxxxxx', // 替换为你的 API Key
        'model' => 'gemini-pro',
    ]);
    
    $models = $ai->listModels();
    
    if ($models !== null) {
        echo "Gemini 可用模型:\n";
        foreach ($models as $modelId => $modelName) {
            echo "  - {$modelId}: {$modelName}\n";
        }
    } else {
        echo "无法获取模型列表\n";
    }
    
} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n";
}
echo "\n";

// ===========================================
// 示例 4: 动态选择模型
// ===========================================
echo "=== 示例 4: 动态选择模型 ===\n";

try {
    $ai = AI::create([
        'api_key' => 'sk-xxxxxxxxxxxxx',
        'model' => 'gpt-4o',
    ]);
    
    // 获取模型列表
    $models = $ai->listModels();
    
    if ($models !== null && count($models) > 0) {
        echo "可用模型:\n";
        $modelList = array_keys($models);
        
        foreach ($modelList as $index => $modelId) {
            echo ($index + 1) . ". {$modelId}\n";
        }
        
        // 模拟用户选择（实际应用中可以从用户输入获取）
        $selectedIndex = 0; // 选择第一个模型
        $selectedModel = $modelList[$selectedIndex];
        
        echo "\n选择模型: {$selectedModel}\n";
        
        // 切换到选择的模型
        $ai->setModel($selectedModel);
        
        // 使用选择的模型进行对话
        $response = $ai->chat([
            'messages' => [
                ['role' => 'user', 'content' => '你好，请介绍一下你自己']
            ],
            'max_tokens' => 100,
        ]);
        
        echo "模型回复: " . $response->getContent() . "\n";
    }
    
} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n";
}
echo "\n";

// ===========================================
// 示例 5: 比较不同平台的模型
// ===========================================
echo "=== 示例 5: 比较不同平台的模型 ===\n";

$platforms = [
    'OpenAI' => [
        'api_key' => 'sk-xxxxxxxxxxxxx',
        'model' => 'gpt-4o',
    ],
    'Claude' => [
        'api_key' => 'sk-ant-xxxxxxxxxxxxx',
        'model' => 'claude-3-opus',
    ],
    'Gemini' => [
        'api_key' => 'AIzaxxxxxxxxxxxxx',
        'model' => 'gemini-pro',
    ],
];

foreach ($platforms as $platform => $config) {
    try {
        echo "{$platform}:\n";
        
        $ai = AI::create($config);
        $models = $ai->listModels();
        
        if ($models !== null) {
            echo "  共 " . count($models) . " 个模型\n";
            
            // 显示前 5 个模型
            $count = 0;
            foreach ($models as $modelId => $modelName) {
                if ($count++ >= 5) break;
                echo "  - {$modelId}\n";
            }
            
            if (count($models) > 5) {
                echo "  ... 还有 " . (count($models) - 5) . " 个模型\n";
            }
        } else {
            echo "  不支持列举模型\n";
        }
        
        echo "\n";
        
    } catch (AIException $e) {
        echo "  错误: " . $e->getMessage() . "\n\n";
    }
}

// ===========================================
// 示例 6: 模型筛选与搜索
// ===========================================
echo "=== 示例 6: 模型筛选与搜索 ===\n";

try {
    $ai = AI::create([
        'api_key' => 'sk-xxxxxxxxxxxxx',
        'model' => 'gpt-4o',
    ]);
    
    $models = $ai->listModels();
    
    if ($models !== null) {
        // 筛选包含 "gpt-4" 的模型
        $keyword = 'gpt-4';
        echo "搜索关键词: {$keyword}\n";
        echo "匹配的模型:\n";
        
        $filtered = array_filter($models, function($modelName, $modelId) use ($keyword) {
            return stripos($modelId, $keyword) !== false || stripos($modelName, $keyword) !== false;
        }, ARRAY_FILTER_USE_BOTH);
        
        foreach ($filtered as $modelId => $modelName) {
            echo "  - {$modelId}: {$modelName}\n";
        }
        
        echo "\n共找到 " . count($filtered) . " 个匹配的模型\n";
    }
    
} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n";
}
echo "\n";

// ===========================================
// 示例 7: 缓存模型列表
// ===========================================
echo "=== 示例 7: 缓存模型列表 ===\n";

try {
    $cacheFile = '/tmp/ai_models_cache.json';
    $cacheExpiry = 3600; // 1 小时
    
    $ai = AI::create([
        'api_key' => 'sk-xxxxxxxxxxxxx',
        'model' => 'gpt-4o',
    ]);
    
    // 检查缓存
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheExpiry) {
        echo "从缓存读取模型列表\n";
        $models = json_decode(file_get_contents($cacheFile), true);
    } else {
        echo "从 API 获取模型列表\n";
        $models = $ai->listModels();
        
        if ($models !== null) {
            // 保存到缓存
            file_put_contents($cacheFile, json_encode($models, JSON_PRETTY_PRINT));
            echo "已缓存到文件: {$cacheFile}\n";
        }
    }
    
    if ($models !== null) {
        echo "模型数量: " . count($models) . "\n";
    }
    
} catch (AIException $e) {
    echo "错误: " . $e->getMessage() . "\n";
}
echo "\n";

// ===========================================
// 示例 8: 错误处理
// ===========================================
echo "=== 示例 8: 错误处理 ===\n";

try {
    // 使用无效的 API Key
    $ai = AI::create([
        'api_key' => 'invalid-key',
        'model' => 'gpt-4o',
    ]);
    
    $models = $ai->listModels();
    
    if ($models === null) {
        echo "无法获取模型列表（可能是网络错误或 API Key 无效）\n";
    }
    
} catch (AIException $e) {
    echo "捕获异常: " . $e->getMessage() . "\n";
}
echo "\n";

// ===========================================
// 示例 9: Web 应用集成示例
// ===========================================
echo "=== 示例 9: Web 应用集成示例 ===\n";

function getModelsForUI($platform, $apiKey) {
    try {
        $modelMap = [
            'openai' => 'gpt-4o',
            'claude' => 'claude-3-opus',
            'gemini' => 'gemini-pro',
        ];
        
        if (!isset($modelMap[$platform])) {
            return ['error' => 'Unknown platform'];
        }
        
        $ai = AI::create([
            'api_key' => $apiKey,
            'model' => $modelMap[$platform],
        ]);
        
        $models = $ai->listModels();
        
        if ($models === null) {
            return ['error' => 'Platform does not support listing models'];
        }
        
        return [
            'success' => true,
            'platform' => $platform,
            'models' => $models,
            'count' => count($models),
        ];
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// 模拟 API 调用
$result = getModelsForUI('openai', 'sk-xxxxxxxxxxxxx');

echo "API 响应示例:\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo "\n\n";

// 前端 HTML 示例
echo "前端 HTML 示例代码:\n";
echo <<<'HTML'
<select id="model-selector">
    <option value="">-- 选择模型 --</option>
</select>

<script>
// 加载模型列表
fetch('/api/models?platform=openai')
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const selector = document.getElementById('model-selector');
            Object.entries(data.models).forEach(([id, name]) => {
                const option = document.createElement('option');
                option.value = id;
                option.textContent = name;
                selector.appendChild(option);
            });
        }
    });
</script>
HTML;
echo "\n\n";

echo "========================================\n";
echo "所有示例执行完成！\n";
echo "========================================\n";
