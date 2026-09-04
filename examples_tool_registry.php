<?php
/**
 * Agent Tool 标准 + SQLite Tool Registry —— 完整可运行示例
 *
 * 直接跑：
 *     php examples_tool_registry.php
 *
 * 它会在系统临时目录里造一个「样本应用」（带 @agent-tool 标注的 Service）、
 * 一个临时 SQLite Registry，完整走一遍：
 *
 *     PHPDoc 标注 → 扫描索引 → SQLite + FTS5 → 按需搜索 → 取 Schema
 *       → Tool 调用 → 应用现有 Controller 权限校验 → 业务代码执行 → 结果
 *
 * 跑完自动清理，不写任何东西到仓库里，也不需要 API Key（全程不联网）。
 */

require __DIR__ . '/autoload.php';

use Ai\Agent\Discovery\RegistryToolBridge;
use Ai\Agent\Indexer\ToolIndexer;
use Ai\Agent\Registry\CallableControllerGateway;
use Ai\Agent\Registry\RiskPolicy;
use Ai\Agent\Registry\SqliteToolRegistry;
use Ai\Agent\Registry\ToolSearchContext;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolDefinition;

$tmp = sys_get_temp_dir() . '/php-ai-registry-demo_' . getmypid();
@mkdir($tmp . '/app/Service', 0700, true);

function demo_title($n, $t)
{
    echo "\n" . str_repeat('=', 68) . "\n" . $n . '. ' . $t . "\n" . str_repeat('=', 68) . "\n";
}

// =====================================================================
demo_title(1, '应用侧：给现有 Service 加 PHPDoc 标注');
// =====================================================================

// 真实项目里这就是你已有的 Controller / Service，只是多了几行 @agent-* 注释。
$serviceCode = <<<'PHP'
<?php
namespace DemoApp;

class ProductService
{
    /** @var array<int, array<string, mixed>> */
    protected $products = [
        1 => ['id' => 1, 'name' => '香薰蜡烛', 'price' => 59.0, 'sales' => 320],
        2 => ['id' => 2, 'name' => '陶瓷马克杯', 'price' => 39.0, 'sales' => 810],
    ];

    /**
     * 商品销量统计
     *
     * @agent-tool product.sales.stats
     * @agent-description 按销量倒序列出商品，用于找出卖得最好的商品
     * @agent-controller product/stats
     * @agent-risk low
     * @agent-keywords 商品,销量,统计,排行
     *
     * @param int $limit 返回条数
     * @return array 商品数组，按销量倒序
     */
    public function salesStats($limit = 10)
    {
        $rows = array_values($this->products);
        usort($rows, function ($a, $b) {
            return $b['sales'] - $a['sales'];
        });
        return array_slice($rows, 0, $limit);
    }

    /**
     * 修改商品价格
     *
     * @agent-tool product.update_price
     * @agent-description 修改指定商品的售价，价格必须大于 0
     * @agent-controller product/update_price
     * @agent-risk medium
     * @agent-permission product/update_price
     * @agent-keywords 商品,价格,调价,降价
     *
     * @param int $id 商品 ID
     * @param float $price 新的售价
     * @return array 修改后的商品
     */
    public function updatePrice($id, $price)
    {
        if (!isset($this->products[$id])) {
            throw new \RuntimeException('商品不存在: ' . $id);
        }
        $this->products[$id]['price'] = $price;
        return $this->products[$id];
    }

    /**
     * 下架商品
     *
     * @agent-tool product.delete
     * @agent-description 永久下架并删除商品，不可恢复
     * @agent-controller product/delete
     * @agent-risk high
     * @agent-keywords 商品,删除,下架
     *
     * @param int $id 商品 ID
     * @return array 删除结果
     */
    public function delete($id)
    {
        unset($this->products[$id]);
        return ['deleted' => $id];
    }

    /**
     * 内部辅助方法：没有 @agent-tool，不会被暴露给 Agent
     *
     * @return int
     */
    public function internalCount()
    {
        return count($this->products);
    }
}
PHP;

file_put_contents($tmp . '/app/Service/ProductService.php', $serviceCode);
echo "已生成样本应用: {$tmp}/app/Service/ProductService.php\n";
echo "其中 3 个方法带 @agent-tool 标注，1 个内部方法没有标注。\n";

// =====================================================================
demo_title(2, '发布阶段：扫描并写入 SQLite Registry（等价于 php-ai index）');
// =====================================================================

$registry = new SqliteToolRegistry($tmp . '/.ai/registry.sqlite');
$indexer  = new ToolIndexer($registry);

$result = $indexer->scan([$tmp . '/app']);
echo $result->summary() . "\n";
echo 'FTS5 全文索引: ' . ($registry->hasFts() ? '可用' : '不可用（已降级到 LIKE 搜索）') . "\n\n";

foreach ($registry->all(true) as $tool) {
    printf(
        "  %-24s risk=%-8s controller=%s\n",
        $tool->getName(),
        $tool->getRisk(),
        $tool->getControllerPath()
    );
}
echo "\n注意 internalCount() 没有进 Registry —— 只有显式标注的方法才会被暴露。\n";

// 增量：再扫一次，文件没变就全部跳过
$again = $indexer->scan([$tmp . '/app']);
echo "\n再扫一次（增量）: " . $again->summary() . "\n";

// =====================================================================
demo_title(3, '应用侧：实现 Controller 网关（唯一需要你写的适配层）');
// =====================================================================

$service = new DemoApp\ProductService();

// 你的应用现有的路由表 / 权限系统，这里用最小的形式模拟
$routes = [
    'product/stats'        => [$service, 'salesStats'],
    'product/update_price' => [$service, 'updatePrice'],
    'product/delete'       => [$service, 'delete'],
];
$userPermissions = ['product/stats', 'product/update_price'];   // 这个用户没有删除权限

$gateway = new CallableControllerGateway(
    // dispatch：真正执行。⚠️ 这里必须做权限校验，它是最终安全边界
    function ($path, array $args, array $ctx) use ($routes, $userPermissions) {
        if (!in_array($path, $userPermissions, true)) {
            throw new \RuntimeException('无权访问 ' . $path);
        }
        if (!isset($routes[$path])) {
            throw new \RuntimeException('未注册的 Controller 入口: ' . $path);
        }
        echo "    → 应用权限校验通过，分发到 {$path}\n";
        return call_user_func_array($routes[$path], array_values($args));
    },
    // can：Discovery 阶段的候选过滤（只是优化，不是安全边界）
    function ($path, array $ctx) use ($userPermissions) {
        return in_array($path, $userPermissions, true);
    }
);

echo "网关已就绪。当前用户权限: " . implode(', ', $userPermissions) . "\n";

// =====================================================================
demo_title(4, 'Agent 侧：三个工具接进现有 Agent');
// =====================================================================

$bridge = new RegistryToolBridge($registry, $gateway, [
    'risk_policy' => new RiskPolicy(),   // 默认 high/critical 需要确认
]);
$bridge->setContext(new ToolSearchContext([
    'user_id'     => 7,
    'permissions' => $userPermissions,
]));

$tools = $bridge->tools();
echo "注入给模型的工具: " . implode(', ', array_keys($tools)) . "\n";
echo "\n模型初始只看到这 3 个工具，而不是应用的全部业务能力——\n";
echo "能力再多也不会撑爆 context，模型按需搜、按需加载。\n";
echo "\n接进真实 Agent 只需要一行：\n";
echo "    \$agent->tools(\$bridge->tools());\n";

// =====================================================================
demo_title(5, '运行时：用户说「把销量最高的商品降价 5%」');
// =====================================================================

$ctx = new ToolContext([]);

echo "① 模型搜索能力: search_app_tools(query: \"商品 销量 价格\")\n";
$r = $tools['search_app_tools']->execute(['query' => '商品 销量 价格'], $ctx);
$candidates = json_decode((string) $r->getContent(), true);
foreach ((array) $candidates as $c) {
    printf("    %-24s [%s] %s\n", $c['name'], $c['risk'], $c['description']);
}
echo "  （product.delete 不在候选里：当前用户没有删除权限）\n";

echo "\n② 模型取 Schema: get_app_tool(name: \"product.sales.stats\")\n";
$r = $tools['get_app_tool']->execute(['name' => 'product.sales.stats'], $ctx);
echo '    ' . $r->getContent() . "\n";

echo "\n③ 模型调用查询: call_app_tool(name: \"product.sales.stats\")\n";
$r = $tools['call_app_tool']->execute([
    'name'      => 'product.sales.stats',
    'arguments' => ['limit' => 1],
], $ctx);
echo '    ' . $r->getContent() . "\n";

$top = json_decode((string) $r->getContent(), true);
$topProduct = is_array($top) && isset($top[0]) ? $top[0] : null;

if ($topProduct !== null) {
    $newPrice = round($topProduct['price'] * 0.95, 2);
    echo "\n④ 模型算出新价格并调用修改: {$topProduct['name']} {$topProduct['price']} → {$newPrice}\n";
    $r = $tools['call_app_tool']->execute([
        'name'      => 'product.update_price',
        'arguments' => ['id' => $topProduct['id'], 'price' => $newPrice],
    ], $ctx);
    echo '    ' . $r->getContent() . "\n";
}

// =====================================================================
demo_title(6, '安全边界演示');
// =====================================================================

echo "① 无权限的能力：搜不到，直接点名也调不动\n";
$r = $bridge->invoke(['name' => 'product.delete', 'arguments' => ['id' => 1]]);
echo '    ' . $r->getContent() . "\n";

echo "\n② 未声明的参数被丢弃，不会透传给 Controller\n";
$r = $bridge->invoke([
    'name'      => 'product.update_price',
    'arguments' => ['id' => 1, 'price' => 49.9, 'is_admin' => true, 'sql' => 'DROP TABLE'],
]);
$meta = $r->getMetadata();
echo '    被丢弃的参数: ' . implode(', ', isset($meta['dropped_arguments']) ? $meta['dropped_arguments'] : []) . "\n";

echo "\n③ 参数类型不对时拒绝执行，而不是悄悄转成 0\n";
$r = $bridge->invoke(['name' => 'product.update_price', 'arguments' => ['id' => 1, 'price' => '免费']]);
echo '    ' . $r->getContent() . "\n";

echo "\n④ 高风险操作需要用户确认（风险不是权限，两者独立）\n";
$adminBridge = new RegistryToolBridge($registry, new CallableControllerGateway(
    function ($path, array $args, array $ctx) {
        echo "    → 管理员权限校验通过，执行 {$path}\n";
        return ['deleted' => isset($args['id']) ? $args['id'] : 0];
    }
));
$adminBridge->setContext(new ToolSearchContext(['user_id' => 1]));   // 不过滤，全部可见

$r = $adminBridge->invoke(['name' => 'product.delete', 'arguments' => ['id' => 2]]);
echo '    第一次调用: ' . $r->getContent() . "\n";
$r = $adminBridge->invoke(['name' => 'product.delete', 'arguments' => ['id' => 2], 'confirmed' => true]);
echo '    用户确认后: ' . $r->getContent() . "\n";

echo "\n⑤ 即使 Discovery 放行，最终仍以应用的 dispatch() 校验为准\n";
$optimistic = new RegistryToolBridge($registry, new CallableControllerGateway(
    function ($path, array $args, array $ctx) {
        // 对象级业务规则：光有 product/update_price 权限还不够
        throw new \RuntimeException('Policy 拒绝：该商品属于其他店铺');
    },
    function ($path, array $ctx) {
        return true;   // Discovery 阶段乐观放行
    }
));
$optimistic->setContext(new ToolSearchContext(['user_id' => 7]));
echo '    搜索能搜到: ' . count($optimistic->searcher()->summaries('价格')) . " 个候选\n";
$r = $optimistic->invoke(['name' => 'product.update_price', 'arguments' => ['id' => 1, 'price' => 1.0]]);
echo '    但执行被拒: ' . $r->getContent() . "\n";
echo "\n  这就是本方案最重要的一条：搜得到 ≠ 有权限。\n";
echo "  Registry 只负责让 AI 发现应用有什么能力，永远不决定这些能力能不能执行。\n";

// =====================================================================
demo_title(7, '命令行等价操作');
// =====================================================================

echo <<<TXT
发布流程里通常不写 PHP，直接用 CLI：

    composer install
    php-ai index --path=app/Controller --path=app/Service
    php-ai tools
    php-ai tools:search "商品 价格"
    php-ai tools:show product.update_price
    php-ai index --check        # CI 里校验索引是否最新，过期返回退出码 1

也可以把路径写进 .ai/config.php，之后直接 `php-ai index`：

    <?php
    return ['agent' => ['index' => ['paths' => [
        __DIR__ . '/app/Controller',
        __DIR__ . '/app/Service',
    ]]]];

TXT;

// =====================================================================
// 清理
// =====================================================================

function demo_rrmdir($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') {
            continue;
        }
        $p = $dir . '/' . $it;
        if (is_dir($p) && !is_link($p)) {
            demo_rrmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}
demo_rrmdir($tmp);

echo "\n临时目录已清理: {$tmp}\n";
echo "\n完整规范见 README.md 的「Agent Tool 标准与 Tool Registry」一节。\n";
