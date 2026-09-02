<?php
/**
 * 跨平台子 Agent 示例：DeepSeek 写代码、Kimi 审代码、GPT 做规划
 *
 * 一个 Agent 里的三个角色分别挂在三个平台的接口上，各用各的 Key 与地址。
 * 主 Agent 通过 spawn_agent 工具派活，或由编排层自动委派。
 *
 * 运行前先设好环境变量：
 *   export DEEPSEEK_API_KEY=sk-...
 *   export MOONSHOT_API_KEY=sk-...
 *   export OPENAI_API_KEY=sk-...
 *
 * 运行：php examples_multi_platform.php
 */

require_once __DIR__ . '/autoload.php';

use Ai\AI;
use Ai\Agent\Agent;

$deepseekKey = getenv('DEEPSEEK_API_KEY') ?: '';
$moonshotKey = getenv('MOONSHOT_API_KEY') ?: '';
$openaiKey   = getenv('OPENAI_API_KEY') ?: '';

if ($deepseekKey === '' || $moonshotKey === '' || $openaiKey === '') {
    echo "请先设置 DEEPSEEK_API_KEY / MOONSHOT_API_KEY / OPENAI_API_KEY\n";
    echo "（下面的代码不改也能读——它展示的是配置写法，不依赖具体平台）\n\n";
}

// ============================================================
// 1. 写法一：连接信息直接写在角色定义里
// ============================================================

// 父 Agent 自己也要有一个模型——它负责派活，不必是最贵的那个
$ai = AI::create(['model' => 'deepseek-chat', 'api_key' => $deepseekKey]);

$agent = Agent::create($ai)->agents([
    'planner' => [
        'description' => '把需求拆成可执行的步骤，只做规划不写代码',
        'prompt'      => '你是任务规划者。把需求拆成有序的步骤，每步一句话。',
        'model'       => 'gpt-4o',
        'api_key'     => $openaiKey,
    ],
    'coder' => [
        'description' => '按给定方案写 PHP 代码',
        'prompt'      => '你是 PHP 工程师。只输出代码，不解释。',
        'model'       => 'deepseek-chat',
        'api_key'     => $deepseekKey,
    ],
    'reviewer' => [
        'description' => '审查代码的正确性与安全问题',
        'prompt'      => '你是代码审查者。指出问题，没问题就说「无问题」。',
        'model'       => 'moonshot-v1-32k',
        'api_key'     => $moonshotKey,
    ],
]);

// ============================================================
// 2. 写法二：平台配置表——角色多了不必逐个贴 Key
//
// 库按模型名推断平台（deepseek-chat → deepseek，moonshot-v1-32k → moonshot），
// 再从表里取对应的连接配置
// ============================================================

$agent2 = Agent::create(AI::create(['model' => 'deepseek-chat', 'api_key' => $deepseekKey]))
    ->platforms([
        'deepseek' => ['api_key' => $deepseekKey],
        'moonshot' => ['api_key' => $moonshotKey],
        'openai'   => ['api_key' => $openaiKey],
    ])
    ->agents([
        'planner'  => ['description' => '任务规划', 'model' => 'gpt-4o'],
        'coder'    => ['description' => '写代码',   'model' => 'deepseek-chat'],
        'reviewer' => ['description' => '代码审查', 'model' => 'moonshot-v1-32k'],
    ]);

// 走第三方网关时把地址也放进表里，模型名不变
// $agent2->platforms(['openai' => ['api_key' => $gwKey, 'base_url' => 'https://gw.example.com']]);

// ============================================================
// 3. 写法三：交给 ModelRouter 按角色/复杂度选，凭据自动配对
// ============================================================

$agent3 = Agent::create(AI::create(['model' => 'deepseek-chat', 'api_key' => $deepseekKey]))
    ->platforms([
        'deepseek' => ['api_key' => $deepseekKey],
        'moonshot' => ['api_key' => $moonshotKey],
        'openai'   => ['api_key' => $openaiKey],
    ]);

$agent3->modelRouter([
    'cheap'    => 'deepseek-chat',      // explorer 之类的杂活
    'standard' => 'moonshot-v1-32k',
    'premium'  => 'gpt-4o',             // coder / reviewer
]);

// 这些角色都没写 model，跑的时候由路由器按角色挑
$agent3->agents([
    'explorer' => ['description' => '在代码库里找东西'],
    'coder'    => ['description' => '写代码'],
    'reviewer' => ['description' => '代码审查'],
]);

$sam3 = $agent3->getRuntime()->getSubAgentManager();
echo "路由结果：\n";
foreach (['explorer', 'coder', 'reviewer'] as $role) {
    echo "  {$role}\t=> " . $sam3->resolveModel($sam3->get($role)) . "\n";
}

// 预算见底时统一降档，先把任务跑完比跑得漂亮重要
$sam3->setRouteContext(['budget_left' => 0.05]);
echo "预算见底后 reviewer => " . $sam3->resolveModel($sam3->get('reviewer')) . "\n\n";

// ============================================================
// 4. 实际跑一轮：串起规划 → 写码 → 审查
// ============================================================

if ($deepseekKey === '' || $moonshotKey === '' || $openaiKey === '') {
    echo "缺少 Key，跳过实际调用。\n";
    return;
}

$sam = $agent->getRuntime()->getSubAgentManager();

$planId = $sam->runSync('planner', '实现一个把驼峰命名转成下划线的 PHP 函数');
$plan   = $sam->getResult($planId)['summary'];
echo "【规划 · GPT】\n{$plan}\n\n";

$codeId = $sam->runSync('coder', "按此方案实现：\n{$plan}");
$code   = $sam->getResult($codeId)['summary'];
echo "【实现 · DeepSeek】\n{$code}\n\n";

$reviewId = $sam->runSync('reviewer', "审查这段实现：\n{$code}");
echo "【审查 · Kimi】\n" . $sam->getResult($reviewId)['summary'] . "\n";

// 三个角色各自打到各自平台的地址，父 Agent 的模型与 Key 全程没被改动：
echo "\n父 Agent 仍是：" . $ai->model()->getName() . " @ " . $ai->getPlatform() . "\n";
