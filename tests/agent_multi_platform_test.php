<?php
/**
 * 跨平台子 Agent 测试——一个 Agent 里让不同角色跑在不同平台的接口上
 *
 * 覆盖：
 *   1. SubAgentDefinition 解析连接键 / connection / ai
 *   2. aiFor()：无要求时复用父实例，有要求时派生独立实例
 *   3. 派生实例不污染父 Agent（模型、连接、端点都不变）
 *   4. 换平台时父 Agent 的 base_url / api_key 不泄露过去
 *   5. 平台配置表按平台名与模型名匹配
 *   6. ModelRouter 接入：定义里没写 model 的角色由它选
 *   7. 端到端 runSync：三个角色打到三个平台的地址、各带各的 Key
 *   8. toArray() 不落盘 api_key
 *
 * 不联网、不需要 Key。运行：php tests/agent_multi_platform_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\Orchestrator\ModelRouter;
use Ai\Agent\SubAgent\SubAgentDefinition;
use Ai\Agent\SubAgent\SubAgentManager;

/** 记录每次请求的 URL 与请求头，用来断言「打到哪、带了谁的 Key」 */
class RecordingTransportMP implements \Ai\Contracts\TransportInterface
{
    public $calls = [];

    public function post(string $url, array $data, array $headers = []): array
    {
        $this->calls[] = ['url' => $url, 'data' => $data, 'headers' => $headers];
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => '完成'], 'finish_reason' => 'stop']]];
    }
    public function get(string $url, array $params = [], array $headers = []): array { return []; }
    public function setTimeout(int $t): \Ai\Contracts\TransportInterface { return $this; }
    public function setProxy(string $p): \Ai\Contracts\TransportInterface { return $this; }
    public function setStreamCallback(?callable $cb): \Ai\Contracts\TransportInterface { return $this; }
}

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

/** 找出所有请求头里的鉴权值，协议不同头名也不同，统一挑出来看 */
function authOf(array $headers)
{
    foreach ($headers as $key => $value) {
        $line = is_int($key) ? (string) $value : $key . ': ' . $value;
        if (stripos($line, 'authorization') !== false || stripos($line, 'api-key') !== false) {
            return $line;
        }
    }
    return '';
}

function makeParent(RecordingTransportMP $tr = null)
{
    $tr = $tr ?: new RecordingTransportMP();
    $ai = AI::create(['model' => 'deepseek-chat', 'api_key' => 'ds-key', 'temperature' => 0.3]);
    $ai->setTransport($tr);
    return [$ai, $tr];
}

// ===== 一、SubAgentDefinition 解析连接信息 =====

$def = new SubAgentDefinition('reviewer', [
    'description' => '审代码',
    'model'       => 'moonshot-v1-32k',
    'api_key'     => 'kimi-key',
    'base_url'    => 'https://gw.example.com',
]);
assert_eq('平铺连接键被收下', 'kimi-key', $def->getConnection()['api_key']);
assert_eq('base_url 一并收下', 'https://gw.example.com', $def->getConnection()['base_url']);
test('hasConnection 为真', $def->hasConnection());

$defBlock = new SubAgentDefinition('r2', ['connection' => ['api_key' => 'blk', 'protocol' => 'moonshot']]);
assert_eq('整块 connection 生效', 'blk', $defBlock->getConnection()['api_key']);
assert_eq('整块里的 protocol 生效', 'moonshot', $defBlock->getConnection()['protocol']);

$defPlain = new SubAgentDefinition('plain', ['description' => '没写连接']);
test('没写连接时 hasConnection 为假', !$defPlain->hasConnection());
assert_eq('没写连接时 getAi 为 null', null, $defPlain->getAi());

list($ownAi) = makeParent();
$defOwn = new SubAgentDefinition('own', ['ai' => $ownAi]);
test('直接给 AI 实例被收下', $defOwn->getAi() === $ownAi);

// toArray 不能把 Key 带进 transcript
$arr = $def->toArray();
test('toArray 只列连接键名', $arr['connectionKeys'] === ['api_key', 'base_url']);
test('toArray 不含 api_key 的值', strpos(json_encode($arr), 'kimi-key') === false);

// ===== 二、aiFor：什么时候复用父实例，什么时候派生 =====

list($parent, $tr) = makeParent();
$sam = new SubAgentManager($parent);

$sam->register('plain', ['description' => '沿用父 Agent']);
test('无模型无连接 → 复用父实例', $sam->aiFor($sam->get('plain')) === $parent);

$sam->register('kimi', ['description' => '审代码', 'model' => 'moonshot-v1-32k', 'api_key' => 'kimi-key']);
$kimiAi = $sam->aiFor($sam->get('kimi'));
test('有连接 → 派生独立实例', $kimiAi !== $parent);
test('同一角色重复取回同一实例', $sam->aiFor($sam->get('kimi')) === $kimiAi);

$sam->register('own', ['ai' => $ownAi]);
test('给了 AI 实例就用它', $sam->aiFor($sam->get('own')) === $ownAi);

// ===== 三、派生实例配置正确，且父 Agent 毫发无损 =====

assert_eq('子实例模型是 kimi', 'moonshot-v1-32k', $kimiAi->model()->getName());
assert_eq('子实例平台是 moonshot', 'moonshot', $kimiAi->getPlatform());
assert_eq('子实例用自己的 Key', 'kimi-key', $kimiAi->getConfig()['api_key']);
test('子实例端点指向 moonshot', strpos($kimiAi->resolveEndpoint(), 'moonshot') !== false);

assert_eq('父实例模型没被改', 'deepseek-chat', $parent->model()->getName());
assert_eq('父实例 Key 没被改', 'ds-key', $parent->getConfig()['api_key']);
test('父实例端点仍指向 deepseek', strpos($parent->resolveEndpoint(), 'deepseek') !== false);

// 生成参数继承，连接信息不继承
assert_eq('生成参数照常继承', 0.3, $kimiAi->getConfig()['temperature']);

// ===== 四、换平台时父 Agent 的连接信息不泄露 =====

$gwAi = AI::create([
    'model'    => 'deepseek-chat',
    'api_key'  => 'ds-key',
    'base_url' => 'https://ds-gateway.internal',
]);
$gwAi->setTransport($tr);
$samGw = new SubAgentManager($gwAi);
$samGw->register('kimi', ['model' => 'moonshot-v1-32k', 'api_key' => 'kimi-key']);
$kimi2 = $samGw->aiFor($samGw->get('kimi'));
test('父 base_url 没被继承', !isset($kimi2->getConfig()['base_url']));
test('端点没落到父网关上', strpos($kimi2->resolveEndpoint(), 'ds-gateway') === false);
assert_eq('端点走 moonshot 官方地址', 'https://api.moonshot.cn/v1/chat/completions', $kimi2->resolveEndpoint());

// 反过来：一个连接键都不写时完全沿用父连接（网关「一把 Key 打所有模型」不受影响）
$samGw->register('gwmodel', ['model' => 'moonshot-v1-32k']);
$gwSub = $samGw->aiFor($samGw->get('gwmodel'));
assert_eq('没写连接 → 沿用父网关地址', 'https://ds-gateway.internal/v1/chat/completions', $gwSub->resolveEndpoint());
assert_eq('没写连接 → 沿用父 Key', 'ds-key', $gwSub->getConfig()['api_key']);

// ===== 五、平台配置表 =====

list($parent2) = makeParent($tr);
$samTable = new SubAgentManager($parent2);
$samTable->setPlatformConfigs([
    'moonshot' => ['api_key' => 'kimi-key'],
    'openai'   => ['api_key' => 'oa-key'],
]);
$samTable->register('reviewer', ['description' => '审代码', 'model' => 'moonshot-v1-32k']);
$samTable->register('planner',  ['description' => '规划',   'model' => 'gpt-4o']);

assert_eq('按平台名匹配到 kimi Key', 'kimi-key', $samTable->aiFor($samTable->get('reviewer'))->getConfig()['api_key']);
assert_eq('按平台名匹配到 openai Key', 'oa-key', $samTable->aiFor($samTable->get('planner'))->getConfig()['api_key']);
test('planner 端点指向 openai', strpos($samTable->aiFor($samTable->get('planner'))->resolveEndpoint(), 'openai') !== false);

// 模型名精确匹配优先于平台名
$samTable->setPlatformConfig('gpt-4o', ['api_key' => 'oa-special', 'base_url' => 'https://oa-gw.internal']);
$planner = $samTable->aiFor($samTable->get('planner'));
assert_eq('模型名精确匹配优先', 'oa-special', $planner->getConfig()['api_key']);
assert_eq('精确匹配的 base_url 生效', 'https://oa-gw.internal/v1/chat/completions', $planner->resolveEndpoint());

// 定义里写死的连接优先于平台表
$samTable->register('reviewer', ['model' => 'moonshot-v1-32k', 'api_key' => 'inline-key']);
assert_eq('定义里的连接优先于平台表', 'inline-key', $samTable->aiFor($samTable->get('reviewer'))->getConfig()['api_key']);

// ===== 六、ModelRouter 接入 =====

list($parent3) = makeParent($tr);
$samRoute = new SubAgentManager($parent3);
$samRoute->setPlatformConfigs([
    'moonshot' => ['api_key' => 'kimi-key'],
    'openai'   => ['api_key' => 'oa-key'],
]);
$samRoute->setModelRouter(new ModelRouter([
    'cheap'    => 'deepseek-chat',
    'standard' => 'moonshot-v1-32k',
    'premium'  => 'gpt-4o',
]));
$samRoute->register('explorer', ['description' => '找代码']);              // 无 model → 路由 cheap
$samRoute->register('reviewer', ['description' => '审代码']);              // 无 model → 路由 premium
$samRoute->register('fixed',    ['description' => '固定', 'model' => 'qwen-max']);

assert_eq('explorer 路由到 cheap 档', 'deepseek-chat', $samRoute->resolveModel($samRoute->get('explorer')));
assert_eq('reviewer 路由到 premium 档', 'gpt-4o', $samRoute->resolveModel($samRoute->get('reviewer')));
assert_eq('写死 model 的不被路由覆盖', 'qwen-max', $samRoute->resolveModel($samRoute->get('fixed')));

$routedAi = $samRoute->aiFor($samRoute->get('reviewer'), $samRoute->resolveModel($samRoute->get('reviewer')));
assert_eq('路由出的模型自动配上该平台的 Key', 'oa-key', $routedAi->getConfig()['api_key']);

// routeContext：预算见底时降档
$samRoute->setRouteContext(['budget_left' => 0.01]);
assert_eq('预算见底降到 cheap 档', 'deepseek-chat', $samRoute->resolveModel($samRoute->get('reviewer')));
$samRoute->setRouteContext(function ($def, $task) {
    return ['priority' => 'critical'];
});
assert_eq('闭包上下文生效（critical → premium）', 'gpt-4o', $samRoute->resolveModel($samRoute->get('reviewer')));

// ===== 七、端到端：三个角色打到三个平台 =====

$tr2 = new RecordingTransportMP();
list($parent4) = makeParent($tr2);
$agent = Agent::create($parent4)
    ->platforms([
        'moonshot' => ['api_key' => 'kimi-key'],
        'openai'   => ['api_key' => 'oa-key'],
    ])
    ->agents([
        'coder'    => ['description' => '写代码', 'model' => 'deepseek-chat'],
        'reviewer' => ['description' => '审代码', 'model' => 'moonshot-v1-32k'],
        'planner'  => ['description' => '规划',   'model' => 'gpt-4o'],
    ]);

$sam4 = $agent->getRuntime()->getSubAgentManager();
$sam4->runSync('coder', '写个函数');
$sam4->runSync('reviewer', '审一下');
$sam4->runSync('planner', '拆任务');

assert_eq('三次子 Agent 调用', 3, count($tr2->calls));
test('coder 打到 deepseek', strpos($tr2->calls[0]['url'], 'deepseek') !== false);
test('reviewer 打到 moonshot', strpos($tr2->calls[1]['url'], 'moonshot') !== false);
test('planner 打到 openai', strpos($tr2->calls[2]['url'], 'openai') !== false);
assert_eq('coder 用 deepseek 模型', 'deepseek-chat', $tr2->calls[0]['data']['model']);
assert_eq('reviewer 用 kimi 模型', 'moonshot-v1-32k', $tr2->calls[1]['data']['model']);
assert_eq('planner 用 gpt-4o', 'gpt-4o', $tr2->calls[2]['data']['model']);
test('coder 带父 Agent 的 Key', strpos(authOf($tr2->calls[0]['headers']), 'ds-key') !== false);
test('reviewer 带 kimi 的 Key', strpos(authOf($tr2->calls[1]['headers']), 'kimi-key') !== false);
test('planner 带 openai 的 Key', strpos(authOf($tr2->calls[2]['headers']), 'oa-key') !== false);

// 跑完之后父 Agent 还是原样
assert_eq('跑完父 Agent 模型不变', 'deepseek-chat', $parent4->model()->getName());
assert_eq('跑完父 Agent Key 不变', 'ds-key', $parent4->getConfig()['api_key']);

// ===== 八、AI::replaceConfig 本身 =====

$ai = AI::create(['model' => 'deepseek-chat', 'api_key' => 'a', 'base_url' => 'https://old.example.com']);
$ai->replaceConfig(['model' => 'gpt-4o', 'api_key' => 'b']);
assert_eq('replaceConfig 丢弃旧 base_url', 'https://api.openai.com/v1/chat/completions', $ai->resolveEndpoint());
assert_eq('replaceConfig 换掉 Key', 'b', $ai->getConfig()['api_key']);
test('replaceConfig 不留旧键', !isset($ai->getConfig()['base_url']));

$ai2 = AI::create(['model' => 'deepseek-chat', 'api_key' => 'a']);
$ai2->replaceConfig(['api_key' => 'c']);
assert_eq('不给 model 时沿用当前模型', 'deepseek-chat', $ai2->model()->getName());
assert_eq('不给 model 时换得掉 Key', 'c', $ai2->getConfig()['api_key']);

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);
