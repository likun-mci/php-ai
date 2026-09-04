<?php
/**
 * AstFileAnalyzer 测试（dev.md 第三梯队 3）
 *
 * nikic/php-parser 是**可选**依赖：没装时本测试整体跳过（CI 不装也能过绿），
 * 装了则验证 AST 解析的精度、与 token 版输出同形、以及语法错误时的回退。
 *
 * 运行：php tests/agent_ast_index_test.php
 */

require __DIR__ . '/../autoload.php';
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

use Ai\Code\AstFileAnalyzer;
use Ai\Code\FileAnalyzer;
use Ai\Code\CodeAnalyzer;

$passed = 0;
$failed = 0;
function test($name, $ok)
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "✓ {$name}\n"; }
    else { $failed++; echo "✗ {$name}\n"; }
}
function rrmdir($d)
{
    if (!is_dir($d)) { return; }
    foreach (scandir($d) ?: [] as $i) {
        if ($i === '.' || $i === '..') { continue; }
        $p = "$d/$i"; is_dir($p) && !is_link($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($d);
}

if (!AstFileAnalyzer::isAvailable()) {
    echo "未安装 nikic/php-parser（可选依赖），跳过 AST 用例\n";
    echo "全部通过：0 通过，0 失败\n";
    exit(0);
}
echo "已装 nikic/php-parser，运行 AST 用例\n\n";

$code = <<<'SRC'
<?php
namespace App\Service;

use App\Contract\Repo;
use App\Model\{User as U, Order};

interface Marker {}

trait Loggable
{
    public function log(string $m): void {}
}

abstract class BaseService implements Marker
{
    use Loggable;

    const VERSION = '1.0';
    protected ?Repo $repo = null;
    private static $count = 0;

    abstract protected function boot(): void;

    public function handle(U $user, Order $order): bool
    {
        $this->log('x');
        $helper = new \App\Util\Helper();
        Order::create();
        strlen('abc');
        $fn = function () { $this->inner(); };
        return true;
    }
}

function topLevel(Repo $r) { return $r; }
SRC;

$ast = new AstFileAnalyzer();
$res = $ast->analyzeCode($code, 'Svc.php');
test('AST 解析成功', $res !== null);

// ===== 一、命名空间与 use（含分组 use 与别名）=====
echo "=== 一、命名空间与 use ===\n";
test('命名空间正确', $res->getNamespace() === 'App\Service');
$imports = $res->getImports();
test('普通 use', isset($imports['Repo']) && $imports['Repo'] === 'App\Contract\Repo');
test('分组 use + 别名 U', isset($imports['U']) && $imports['U'] === 'App\Model\User');
test('分组 use 无别名 Order', isset($imports['Order']) && $imports['Order'] === 'App\Model\Order');

// ===== 二、类/接口/trait 识别 =====
echo "\n=== 二、类型识别 ===\n";
$classes = $res->getClasses();
test('识别出 3 个类型（interface/trait/class）', count($classes) === 3);
test('全限定名带命名空间', isset($classes['App\Service\BaseService']));
$svc = $classes['App\Service\BaseService'];
test('kind=class', $svc->getKind() === 'class');
test('abstract 标记', $svc->isAbstract());
test('implements 解析为全限定名', in_array('App\Service\Marker', $svc->getInterfaces(), true));
test('use trait 解析为全限定名', in_array('App\Service\Loggable', $svc->getTraits(), true));
test('interface 的 kind', isset($classes['App\Service\Marker']) && $classes['App\Service\Marker']->getKind() === 'interface');
test('trait 的 kind', isset($classes['App\Service\Loggable']) && $classes['App\Service\Loggable']->getKind() === 'trait');

// ===== 三、成员 =====
echo "\n=== 三、成员 ===\n";
$methods = $svc->getMethods();
test('方法数 2（boot + handle）', count($methods) === 2);
test('常量 VERSION', count($svc->getConstants()) === 1);
$props = $svc->getProperties();
test('属性 2 个', count($props) === 2);
$repoProp = null;
foreach ($props as $p) { if ($p['name'] === 'repo') { $repoProp = $p; } }
test('可空类型属性解析出 ?FQN', $repoProp !== null && strpos($repoProp['type'], 'App\Contract\Repo') !== false);
test('静态属性标记', in_array(true, array_column($props, 'static'), true));

// ===== 四、精度关键：类名解析为全限定名 =====
echo "\n=== 四、FQN 精度 ===\n";
$deps = $svc->getDependencies();
test('参数类型 U 解析成 App\Model\User', in_array('App\Model\User', $deps, true));
test('参数类型 Order 解析成全限定名', in_array('App\Model\Order', $deps, true));
test('依赖里滤掉了标量 bool/void', !in_array('bool', $deps, true) && !in_array('void', $deps, true));

// ===== 五、调用收集（含闭包内部）=====
echo "\n=== 五、调用收集 ===\n";
$handle = null;
foreach ($methods as $m) { if ($m->getName() === 'handle') { $handle = $m; } }
test('取到 handle 方法', $handle !== null);
$calls = $handle ? $handle->getCalls() : [];
test('方法调用 ->log', in_array('->log', $calls, true));
test('静态调用解析成全限定名', in_array('App\Model\Order::create', $calls, true));
test('函数调用 strlen', in_array('strlen', $calls, true));
test('new 记为依赖调用', in_array('App\Util\Helper', $calls, true));
test('闭包内部调用也被收集（token 版易漏）', in_array('->inner', $calls, true));

// ===== 六、顶层函数 =====
echo "\n=== 六、顶层函数 ===\n";
$fns = $res->getFunctions();
test('顶层函数 1 个', count($fns) === 1);

// ===== 七、与 token 版同形（可无缝替换）=====
echo "\n=== 七、输出同形 ===\n";
$tok = (new FileAnalyzer())->analyzeCode($code, 'Svc.php');
test('两者都返回 FileAnalysis', $res instanceof \Ai\Code\FileAnalysis && $tok instanceof \Ai\Code\FileAnalysis);
test('命名空间一致', $res->getNamespace() === $tok->getNamespace());
test('都能取到类列表', is_array($res->getClasses()) && is_array($tok->getClasses()));

// ===== 八、语法错误时返回 null（交由调用方回退）=====
echo "\n=== 八、语法错误回退 ===\n";
test('语法错误返回 null', $ast->analyzeCode('<?php class { bro ken', 'bad.php') === null);

// CodeAnalyzer 端到端：坏文件不应让整个索引丢掉它
$dir = sys_get_temp_dir() . '/php-ai-ast_' . getmypid();
rrmdir($dir); @mkdir($dir, 0777, true);
file_put_contents($dir . '/Good.php', "<?php\nnamespace X;\nclass Good { public function a() {} }\n");
file_put_contents($dir . '/Bad.php', "<?php\nclass Bad { public function b( { }\n");
$ca = new CodeAnalyzer();
$ca->analyzeFile($dir . '/Good.php');
$bad = $ca->analyzeFile($dir . '/Bad.php');
test('AST 可用时正常文件被索引', $ca->analyzeFile($dir . '/Good.php') !== null);
test('语法错误文件回退 token 版后仍有结果或安全返回 null', $bad === null || $bad instanceof \Ai\Code\FileAnalysis);
rrmdir($dir);

echo "\n" . str_repeat('=', 50) . "\n";
if ($failed === 0) { echo "全部通过：{$passed} 通过，0 失败\n"; exit(0); }
echo "有失败：{$passed} 通过，{$failed} 失败\n";
exit(1);
