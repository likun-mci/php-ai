<?php
/**
 * Code Intelligence 测试——代码结构分析与项目索引
 *
 * 覆盖：
 *   1. TokenScanner 名字读取（PHP 7 / 8 两种 token 形态）
 *   2. FileAnalyzer 解析命名空间、import、类、方法、属性、常量、调用
 *   3. ClassAnalyzer 继承链、子类、接口实现、含继承的方法表
 *   4. FunctionAnalyzer 拉平、按名查找、调用方
 *   5. CallGraph 调用关系与影响面
 *   6. DependencyGraph 依赖、被依赖、环检测、分层
 *   7. CodeAnalyzer 目录扫描与综合查询
 *   8. RepositoryIndexer / ProjectIndex 项目索引
 *
 * 运行：php tests/agent_code_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Code\ProjectIndex;
use Ai\Agent\Code\RepositoryIndexer;
use Ai\Code\CallGraph;
use Ai\Code\ClassAnalysis;
use Ai\Code\ClassAnalyzer;
use Ai\Code\CodeAnalyzer;
use Ai\Code\DependencyGraph;
use Ai\Code\FileAnalyzer;
use Ai\Code\FunctionAnalyzer;
use Ai\Code\TokenScanner;

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

$tmpDir = sys_get_temp_dir() . '/php_ai_code_' . getmypid();
@mkdir($tmpDir . '/src/Service', 0777, true);
@mkdir($tmpDir . '/app/Http/Controllers', 0777, true);

// ===== 一、TokenScanner =====

echo "\n=== 一、TokenScanner ===\n";

$scanner = new TokenScanner("<?php\nnamespace Foo\\Bar;\nclass Baz {}\n");
test('token 流非空', $scanner->count() > 0);

$nsIdx = 0;
for ($i = 0; $i < $scanner->count(); $i++) {
    if ($scanner->isType($i, T_NAMESPACE)) {
        $nsIdx = $i;
        break;
    }
}
$nameStart = $scanner->skipWhitespace($nsIdx + 1);
assert_eq('读出限定名（两种 token 形态皆可）', 'Foo\\Bar', $scanner->readName($nameStart));

$scanner2 = new TokenScanner("<?php\nclass A implements \\Countable {}\n");
$absIdx = 0;
for ($i = 0; $i < $scanner2->count(); $i++) {
    if ($scanner2->isType($i, T_IMPLEMENTS)) {
        $absIdx = $scanner2->skipWhitespace($i + 1);
        break;
    }
}
assert_eq('完全限定名保留前导反斜杠', '\\Countable', $scanner2->readName($absIdx));

$scanner3 = new TokenScanner("<?php\nfunction f() { if (true) { echo 1; } }\n");
$open = 0;
for ($i = 0; $i < $scanner3->count(); $i++) {
    if ($scanner3->isChar($i, '{')) {
        $open = $i;
        break;
    }
}
$close = $scanner3->matchBrace($open);
test('大括号配对跳过了嵌套块', $close > $open && $scanner3->isChar($close, '}'));

// ===== 二、FileAnalyzer =====

echo "\n=== 二、FileAnalyzer ===\n";

$code = <<<'PHP'
<?php
namespace App\Service;

use App\Model\User;
use App\Contract\AuthInterface as Contract;
use App\Support\{Hash, Token as Tok};

interface Base {}

trait Loggable
{
    public function log($msg) { error_log($msg); }
}

abstract class BaseAuth implements Base
{
    const VERSION = '1.0';
    protected $store;
    public function boot() {}
}

final class Auth extends BaseAuth implements Contract
{
    use Loggable;

    const TABLE = 'users';

    private $cache = [];
    protected static $instance = null;

    public function __construct(User $user, ?Hash $hash = null, ...$rest)
    {
        $this->store = new Tok();
        $this->log('booted');
    }

    public function login($name, $password = ''): bool
    {
        $user = User::findByName($name);
        return $this->verify($user, $password);
    }

    protected function verify($user, $password): bool
    {
        return hash_equals((string) $user, $password);
    }
}

function helper(array $x): void
{
    strlen('x');
}
PHP;

$fa = new FileAnalyzer();
$analysis = $fa->analyzeCode($code, 'Auth.php');

assert_eq('命名空间', 'App\\Service', $analysis->getNamespace());
assert_eq('import 数量（含成组与别名）', 4, count($analysis->getImports()));
$imports = $analysis->getImports();
assert_eq('别名 import', 'App\\Contract\\AuthInterface', $imports['Contract']);
assert_eq('成组 import', 'App\\Support\\Hash', $imports['Hash']);
assert_eq('成组 import 带别名', 'App\\Support\\Token', $imports['Tok']);
assert_eq('解析出 4 个类型', 4, count($analysis->getClasses()));
assert_eq('全局函数 1 个', 1, count($analysis->getFunctions()));

$auth = $analysis->getClass('Auth');
test('按短名取到类', $auth instanceof ClassAnalysis);
assert_eq('完整类名', 'App\\Service\\Auth', $auth->getName());
assert_eq('短类名', 'Auth', $auth->getShortName());
assert_eq('父类已解析成完整名', 'App\\Service\\BaseAuth', $auth->getParent());
assert_eq('接口按 import 解析', ['App\\Contract\\AuthInterface'], $auth->getInterfaces());
assert_eq('trait 已记录', ['App\\Service\\Loggable'], $auth->getTraits());
test('final 标记', $auth->isFinal());
test('非抽象', !$auth->isAbstract());
assert_eq('方法数', 3, count($auth->getMethods()));
assert_eq('类常量', 'TABLE', $auth->getConstants()[0]['name']);
assert_eq('属性数', 2, count($auth->getProperties()));

$props = $auth->getProperties();
assert_eq('私有属性可见性', 'private', $props[0]['visibility']);
test('静态属性被识别', $props[1]['static'] === true && $props[1]['name'] === 'instance');

$login = $auth->getMethod('login');
assert_eq('方法参数数', 2, $login->countParams());
assert_eq('必填参数数', 1, $login->countRequiredParams());
assert_eq('返回类型', 'bool', $login->getReturnType());
assert_eq('方法完整名', 'App\\Service\\Auth::login', $login->getFullName());
test('签名可读', strpos($login->getSignature(), 'public function login($name, $password = ...): bool') === 0);
test('方法体调用被记录', in_array('App\\Model\\User::findByName', $login->getCalls(), true));
test('方法调用记为 ->名字', in_array('->verify', $login->getCalls(), true));

$ctor = $auth->getMethod('__construct');
$params = $ctor->getParams();
assert_eq('构造器参数类型（import 解析前的原名）', 'User', $params[0]['type']);
test('可空类型带 ?', $params[1]['type'] === '?Hash');
test('可变参数被识别', $params[2]['variadic'] === true);

$verify = $auth->getMethod('verify');
assert_eq('protected 可见性', 'protected', $verify->getVisibility());

$baseAuth = $analysis->getClass('BaseAuth');
test('抽象类被识别', $baseAuth->isAbstract());
$base = $analysis->getClass('Base');
assert_eq('接口 kind', ClassAnalysis::KIND_INTERFACE, $base->getKind());
$loggable = $analysis->getClass('Loggable');
assert_eq('trait kind', ClassAnalysis::KIND_TRAIT, $loggable->getKind());

$deps = $auth->getDependencies();
test('依赖含父类', in_array('App\\Service\\BaseAuth', $deps, true));
test('依赖含参数类型', in_array('App\\Model\\User', $deps, true));
test('依赖含 new 的类', in_array('App\\Support\\Token', $deps, true));
test('依赖不含内置类型', !in_array('bool', $deps, true) && !in_array('array', $deps, true));

$fn = $analysis->getFunctions()['helper'];
assert_eq('全局函数无所属类', '', $fn->getClass());
test('全局函数调用被记录', in_array('strlen', $fn->getCalls(), true));

// 空文件与坏文件不炸
test('空源码不炸', $fa->analyzeCode('')->getClasses() === []);
assert_eq('不存在的文件返回 null', null, $fa->analyze($tmpDir . '/nope.php'));

// ===== 三、ClassAnalyzer =====

echo "\n=== 三、ClassAnalyzer ===\n";

$ca = new ClassAnalyzer();
$index = $analysis->getClasses();

assert_eq('继承链', ['App\\Service\\BaseAuth'], $ca->ancestors('App\\Service\\Auth', $index));
assert_eq('子类', ['App\\Service\\Auth'], $ca->subclassesOf('App\\Service\\BaseAuth', $index));
assert_eq('后代类', ['App\\Service\\Auth'], $ca->descendantsOf('App\\Service\\BaseAuth', $index));
assert_eq('接口实现者', ['App\\Service\\BaseAuth'], $ca->implementorsOf('App\\Service\\Base', $index));
assert_eq('trait 使用者', ['App\\Service\\Auth'], $ca->usersOfTrait('App\\Service\\Loggable', $index));

$all = $ca->allMethods('App\\Service\\Auth', $index);
test('含继承的方法表含父类方法', isset($all['boot']));
test('含继承的方法表含 trait 方法', isset($all['log']));
test('含继承的方法表含自身方法', isset($all['login']));

test('isSubclassOf 认父类', $ca->isSubclassOf('App\\Service\\Auth', 'App\\Service\\BaseAuth', $index));
test('isSubclassOf 认沿链的接口', $ca->isSubclassOf('App\\Service\\Auth', 'App\\Service\\Base', $index));
test('isSubclassOf 不认无关类', !$ca->isSubclassOf('App\\Service\\Auth', 'App\\Other', $index));
assert_eq('不在索引里的类无继承链', [], $ca->ancestors('Nope', $index));

// ===== 四、FunctionAnalyzer =====

echo "\n=== 四、FunctionAnalyzer ===\n";

$fnA = new FunctionAnalyzer();
$functions = $fnA->flatten($analysis);
test('拉平后含方法与全局函数', isset($functions['App\\Service\\Auth::login'], $functions['helper']));

$found = $fnA->findByName($functions, 'login');
assert_eq('按短名找到 1 个', 1, count($found));
assert_eq('按完整名直接命中', 1, count($fnA->findByName($functions, 'App\\Service\\Auth::login')));
assert_eq('找不到返回空', 0, count($fnA->findByName($functions, 'nonexistent')));

$callers = $fnA->callersOf($functions, 'verify');
test('找出调用方', in_array('App\\Service\\Auth::login', $callers, true));

test('复杂度是正数', $fnA->roughComplexity($functions['App\\Service\\Auth::login']) > 0);
assert_eq('阈值高时没有长函数', 0, count($fnA->longFunctions($functions, 500)));

// ===== 五、CallGraph =====

echo "\n=== 五、CallGraph ===\n";

$graph = new CallGraph();
$graph->addFile($analysis);

test('图里有节点', count($graph->nodes()) >= 4);
test('图里有边', $graph->countEdges() > 0);
test('callees 拿到调用目标', in_array('->verify', $graph->callees('App\\Service\\Auth::login'), true));
test('callers 按短名匹配到方法调用', in_array('App\\Service\\Auth::login', $graph->callers('verify'), true));
test('callers 匹配静态调用', in_array('App\\Service\\Auth::login', $graph->callers('App\\Model\\User::findByName'), true));
test('impactOf 含直接调用方', in_array('App\\Service\\Auth::login', $graph->impactOf('verify'), true));
assert_eq('无关目标没有调用方', [], $graph->callers('nonexistent_function'));
test('未被引用的函数里有 helper', in_array('helper', $graph->unreferenced(), true));
assert_eq('fileOf 记录文件', 'Auth.php', $graph->fileOf('App\\Service\\Auth::login'));

$graph->clear();
assert_eq('clear 清空', 0, count($graph->nodes()));

// ===== 六、DependencyGraph =====

echo "\n=== 六、DependencyGraph ===\n";

$dg = new DependencyGraph();
$dg->addFile($analysis);

test('依赖含父类', in_array('App\\Service\\BaseAuth', $dg->dependenciesOf('App\\Service\\Auth'), true));
test('反向依赖', in_array('App\\Service\\Auth', $dg->dependentsOf('App\\Service\\BaseAuth'), true));
test('传递依赖含祖先的接口',
    in_array('App\\Service\\Base', $dg->transitiveDependencies('App\\Service\\Auth'), true));
test('传递影响面', in_array('App\\Service\\Auth', $dg->transitiveDependents('App\\Service\\Base'), true));
assert_eq('无环', 0, count($dg->detectCycles()));
test('分层有结果', count($dg->layers()) > 0);
test('mostDepended 有结果', count($dg->mostDepended(3)) > 0);

// 造一个环
$cyclic = $fa->analyzeCode('<?php
namespace C;
class A { public function f(B $b) {} }
class B { public function g(A $a) {} }
');
$dg2 = new DependencyGraph();
$dg2->addFile($cyclic);
test('检测到循环依赖', count($dg2->detectCycles()) > 0);

// ===== 七、CodeAnalyzer =====

echo "\n=== 七、CodeAnalyzer ===\n";

file_put_contents($tmpDir . '/src/Service/Auth.php', $code);
file_put_contents($tmpDir . '/src/Service/Admin.php', '<?php
namespace App\Service;
class Admin extends Auth
{
    public function promote()
    {
        return $this->login("root");
    }
}
');

$analyzer = new CodeAnalyzer();
$scanned = $analyzer->scan($tmpDir . '/src');
assert_eq('扫描到 2 个文件', 2, $scanned);

$stats = $analyzer->stats();
assert_eq('索引里 5 个类型', 5, $stats['classes']);
test('统计含方法数', $stats['methods'] > 0);

test('按完整名取类', $analyzer->analyzeClass('App\\Service\\Auth') !== null);
test('按短名取类', $analyzer->analyzeClass('Admin') !== null);
assert_eq('取不存在的类返回 null', null, $analyzer->analyzeClass('Nope'));

test('findCallers 跨文件', in_array('App\\Service\\Admin::promote', $analyzer->findCallers('login'), true));
test('findDependencies', in_array('App\\Service\\Auth', $analyzer->findDependencies('App\\Service\\Admin'), true));
test('findDependents', in_array('App\\Service\\Admin', $analyzer->findDependents('App\\Service\\Auth'), true));
test('传递依赖更长',
    count($analyzer->findDependencies('App\\Service\\Admin', true))
    > count($analyzer->findDependencies('App\\Service\\Admin')));

$related = $analyzer->findRelatedFiles($tmpDir . '/src/Service/Admin.php');
test('相关文件含被依赖的文件', in_array($tmpDir . '/src/Service/Auth.php', $related, true));

$symbols = $analyzer->findSymbol('login');
test('符号定位到方法', count($symbols) > 0 && $symbols[0]['type'] === 'method');
test('符号带行号', $symbols[0]['line'] > 0);

$explain = $analyzer->explain('App\\Service\\Auth');
test('explain 含类名', strpos($explain, 'App\\Service\\Auth') !== false);
test('explain 含被依赖信息', strpos($explain, '被依赖') !== false);
test('explain 含子类', strpos($explain, '子类') !== false);
assert_eq('explain 不存在的类返回空', '', $analyzer->explain('Nope'));

$analyzer->clear();
assert_eq('clear 清空索引', 0, count($analyzer->index()));

// ===== 八、RepositoryIndexer =====

echo "\n=== 八、RepositoryIndexer ===\n";

file_put_contents($tmpDir . '/composer.json', json_encode([
    'require'  => ['php' => '>=7.1', 'laravel/framework' => '^10.0'],
    'autoload' => ['psr-4' => ['App\\' => 'src/']],
]));
file_put_contents($tmpDir . '/app/Http/Controllers/AuthController.php', '<?php class AuthController {}');
@mkdir($tmpDir . '/config', 0777, true);
file_put_contents($tmpDir . '/config/database.php', '<?php return [];');
@mkdir($tmpDir . '/public', 0777, true);
file_put_contents($tmpDir . '/public/index.php', '<?php // entry');

$indexer = new RepositoryIndexer(['indexFile' => 'test.index.json']);
$idx = $indexer->index($tmpDir);

assert_eq('识别语言', 'PHP', $idx->getLanguage());
assert_eq('从 composer 依赖识别框架', 'Laravel', $idx->getFramework());
assert_eq('识别入口', 'public/index.php', $idx->getEntry());
test('识别 PSR-4 命名空间', $idx->getNamespaces() === ['App\\' => 'src/']);
test('依赖已记录', isset($idx->getDependencies()['laravel/framework']));
test('控制器归类', in_array('app/Http/Controllers/AuthController.php', $idx->getFiles('controllers'), true));
test('配置归类', in_array('config/database.php', $idx->getFiles('configs'), true));
test('入口归类', in_array('public/index.php', $idx->getFiles('entries'), true));
test('数据库配置被识别', isset($idx->getDatabase()['config']));
test('索引文件已落盘', is_file($tmpDir . '/test.index.json'));

$summary = $idx->toSummary();
test('摘要含框架', strpos($summary, 'Laravel') !== false);
test('摘要含 project 标签', strpos($summary, '<project>') === 0);

$loaded = $indexer->loadIndex($tmpDir);
test('索引可读回', $loaded instanceof ProjectIndex);
assert_eq('读回的框架一致', 'Laravel', $loaded->getFramework());
assert_eq('读回的文件数一致', $idx->countFiles(), $loaded->countFiles());

test('刚建的索引不过期', !$indexer->isIndexStale($tmpDir));
$indexer->setTtl(0);
test('ttl 为 0 时不按时间过期', !$indexer->isIndexStale($tmpDir));

$stale = new RepositoryIndexer(['indexFile' => 'test.index.json', 'ttl' => 1]);
$old = ProjectIndex::fromJson(file_get_contents($tmpDir . '/test.index.json'));
$old->setIndexedAt(time() - 100);
$stale->saveIndex($old);
test('超过 ttl 判为过期', $stale->isIndexStale($tmpDir));

$refreshed = $stale->refreshIndex($tmpDir);
test('重建后不再过期', !$stale->isIndexStale($tmpDir));
assert_eq('重建后框架仍正确', 'Laravel', $refreshed->getFramework());

test('ensureIndex 复用未过期索引', $indexer->ensureIndex($tmpDir) instanceof ProjectIndex);
assert_eq('无索引目录返回空索引根', '', $indexer->index('')->getRoot());
assert_eq('不存在的目录 loadIndex 返回 null', null, $indexer->loadIndex($tmpDir . '/nope'));

test('deleteIndex 生效', $indexer->deleteIndex($tmpDir) && !is_file($tmpDir . '/test.index.json'));

// 分类兜底
assert_eq('无法归类返回空串', '', $indexer->categorize('src/Random/Thing.php'));
assert_eq('测试文件归类', 'tests', $indexer->categorize('tests/FooTest.php'));
assert_eq('模型归类', 'models', $indexer->categorize('app/Models/User.php'));
assert_eq('服务归类', 'services', $indexer->categorize('app/Services/Mailer.php'));
assert_eq('路由归类', 'routes', $indexer->categorize('routes/web.php'));
assert_eq('视图归类', 'views', $indexer->categorize('resources/views/home.blade.php'));

// ===== 清理 =====

exec('rm -rf ' . escapeshellarg($tmpDir));

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);
