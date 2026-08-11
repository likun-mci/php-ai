<?php
/**
 * Agent 工具调用示例：同一段代码跑在任意平台上
 *
 * 工具定义、模型发起的调用、结果回填全部走库的统一格式，协议层负责翻译成
 * 各平台的实际结构（OpenAI 系的 tool_calls / role:'tool'，Anthropic 系的
 * tool_use / tool_result 块）。换平台只改 protocol 与 api_key，业务代码不动。
 *
 * 运行：php examples_agent.php
 */

require_once __DIR__ . '/autoload.php';

use Ai\AI;
use Ai\Agent\Agent;

// ============================================================
// 1. 定义工具：一份定义，所有平台通用
// ============================================================

$tools = [
    'get_weather' => [
        'description'  => '查询指定城市的实时天气。需要知道天气时调用。',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'city' => ['type' => 'string', 'description' => '城市名，如「北京」'],
            ],
            'required'   => ['city'],
        ],
        'handler' => function (array $input): string {
            // 真实项目里这里去调气象接口；返回值会作为 tool_result 回填给模型
            $city = $input['city'] ?? '';
            return json_encode(
                ['city' => $city, 'weather' => '晴', 'temperature' => 25, 'humidity' => '40%'],
                JSON_UNESCAPED_UNICODE
            );
        },
    ],

    'search_order' => [
        'description'  => '按订单号查询订单状态',
        'input_schema' => [
            'type'       => 'object',
            'properties' => ['order_no' => ['type' => 'string', 'description' => '订单号']],
            'required'   => ['order_no'],
        ],
        'handler' => function (array $input): string {
            return '订单 ' . ($input['order_no'] ?? '') . ' 状态：已发货，预计明天送达';
        },
    ],
];

// ============================================================
// 2. 业务函数：与平台无关
// ============================================================

/**
 * 跑一轮 Agent 对话
 *
 * @param string $protocol 协议标识（qwen / zhipu / openai / claude / doubao ……）
 * @param string $model    模型名
 * @param string $apiKey   该平台的密钥
 * @param string $question 用户问题
 * @param array  $tools    工具集
 */
function runAgent(string $protocol, string $model, string $apiKey, string $question, array $tools): string
{
    $ai = AI::create([
        'protocol'   => $protocol,
        'model'      => $model,
        'api_key'    => $apiKey,
        'max_tokens' => 2048,
    ]);

    $agent = (new Agent($ai))
        ->setSystem('你是一个严谨的助理。需要外部信息时调用工具，不要凭空编造。')
        ->setTools($tools)
        ->setMaxIter(10)
        ->onEvent(function (array $event) {
            switch ($event['type']) {
                case 'tool_call':
                    echo "  → 调用工具 {$event['name']}("
                       . json_encode($event['input'], JSON_UNESCAPED_UNICODE) . ")\n";
                    break;
                case 'tool_error':
                    echo "  ! 工具出错 {$event['name']}：{$event['message']}\n";
                    break;
                case 'agent_text':
                    echo "  模型：" . mb_substr($event['text'], 0, 80) . "\n";
                    break;
                case 'error':
                    echo "  × 出错：{$event['message']}\n";
                    break;
            }
        });

    $agent->run([['role' => 'user', 'content' => $question]]);
    return $agent->lastText();
}

// ============================================================
// 3. 换平台只改这一行配置
// ============================================================

$platforms = [
    // 协议           模型                    环境变量名
    ['qwen',      'qwen-plus',            'QWEN_API_KEY'],
    ['zhipu',     'glm-4.6',              'ZHIPU_API_KEY'],
    ['doubao',    'doubao-seed-1-6',      'DOUBAO_API_KEY'],
    ['moonshot',  'kimi-latest',          'MOONSHOT_API_KEY'],
    ['deepseek',  'deepseek-chat',        'DEEPSEEK_API_KEY'],
    ['openai',    'gpt-4o',               'OPENAI_API_KEY'],
    ['claude',    'claude-opus-5',        'ANTHROPIC_API_KEY'],
];

$question = '北京今天天气怎么样？另外帮我查下订单 A20260811 到哪了。';

$ran = false;
foreach ($platforms as [$protocol, $model, $envName]) {
    $key = getenv($envName);
    if (!$key) {
        continue;                       // 未配置密钥的平台跳过
    }
    $ran = true;
    echo "\n=== {$protocol}（{$model}）===\n";
    try {
        $answer = runAgent($protocol, $model, $key, $question, $tools);
        echo "  最终答案：{$answer}\n";
    } catch (\Ai\Exceptions\AIException $e) {
        echo "  × {$e->getMessage()}\n";
    }
}

if (!$ran) {
    echo <<<TXT
未检测到任何平台密钥，跳过实际调用。

设置任一环境变量后重跑即可，例如：
    export QWEN_API_KEY=sk-xxx     && php examples_agent.php
    export ZHIPU_API_KEY=xxx       && php examples_agent.php
    export OPENAI_API_KEY=sk-xxx   && php examples_agent.php

要点：上面 runAgent() 里的代码对所有平台完全一致——
工具定义、tool_call 事件、结果回填都由库统一处理，
业务层不需要知道 OpenAI 用 tool_calls、Anthropic 用 tool_use 块。

TXT;
}

// ============================================================
// 4. 不用 Agent，自己控制循环时的写法
// ============================================================
//
// $ai = AI::create(['protocol' => 'qwen', 'model' => 'qwen-plus', 'api_key' => 'sk-xxx']);
// $messages = [['role' => 'user', 'content' => '北京天气']];
//
// $resp = $ai->chat(['messages' => $messages, 'tools' => $toolDefs]);
//
// if ($resp->hasToolCalls()) {                 // 各平台一致
//     $messages[] = $resp->toAssistantMessage();   // 把模型这一轮接回上下文
//     $results = [];
//     foreach ($resp->getToolCalls() as $call) {   // ['id'=>.., 'name'=>.., 'input'=>[..]]
//         $results[] = [
//             'type'        => 'tool_result',
//             'tool_use_id' => $call['id'],
//             'content'     => myHandler($call['name'], $call['input']),
//         ];
//     }
//     $messages[] = ['role' => 'user', 'content' => $results];
//     $resp = $ai->chat(['messages' => $messages, 'tools' => $toolDefs]);
// }
//
// echo $resp->getContent();
