<?php
/**
 * Agent Tool 标准 + Tool Registry 测试（Phase 1-4）
 *
 * 覆盖 dev.md 第 2-5 节：值对象、PHPDoc 解析、Reflection Schema、ClassLocator、
 * Memory / SQLite Registry、FTS5 中文搜索与降级、增量索引、Discovery 权限过滤。
 *
 * 全程使用临时目录与临时 sqlite，结束递归删除，绝不污染仓库。
 *
 * 运行：php tests/agent_tool_registry_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Discovery\RegistryToolBridge;
use Ai\Agent\Discovery\ToolSearcher;
use Ai\Agent\Indexer\ClassLocator;
use Ai\Agent\Indexer\PhpDocParser;
use Ai\Agent\Indexer\ReflectionSchemaBuilder;
use Ai\Agent\Indexer\ToolIndexer;
use Ai\Agent\Registry\CallableControllerGateway;
use Ai\Agent\Registry\MemoryToolRegistry;
use Ai\Agent\Registry\SearchText;
use Ai\Agent\Registry\SqliteToolRegistry;
use Ai\Agent\Registry\ToolRegistryInterface;
use Ai\Agent\Registry\ToolSearchContext;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolDefinition;
use Ai\Agent\Tool\ToolParameter;

$passed = 0;
$failed = 0;

function test($name, $ok)
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "✓ {$name}\n"; }
    else { $failed++; echo "✗ {$name}\n"; }
}
function assert_eq($name, $expected, $actual)
{
    if ($expected !== $actual) {
        echo "  期望: " . var_export($expected, true) . "\n  实际: " . var_export($actual, true) . "\n";
    }
    test($name, $expected === $actual);
}
function rrmdir($dir)
{
    if (!is_dir($dir)) { return; }
    $items = scandir($dir);
    if ($items === false) { return; }
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') { continue; }
        $p = $dir . '/' . $it;
        if (is_dir($p) && !is_link($p)) { rrmdir($p); } else { @unlink($p); }
    }
    @rmdir($dir);
}

/**
 * 在**子进程**里跑一次扫描
 *
 * PHP 没法在一个进程里卸载并重新载入类：同进程中改了源码再扫，Reflection 读到的
 * 还是先载入的那份。真实用法（php-ai index）每次都是新进程，因此涉及「改文件后
 * 重扫」的用例必须照样另起进程，否则测的是 PHP 的类缓存而不是索引器。
 *
 * @param string $dir
 * @param string $db
 * @return array<string, mixed>
 */
function scan_subprocess($dir, $db)
{
    $code = 'require ' . var_export(__DIR__ . '/../autoload.php', true) . ';'
        . '$r = new Ai\\Agent\\Registry\\SqliteToolRegistry(' . var_export($db, true) . ');'
        . '$res = (new Ai\\Agent\\Indexer\\ToolIndexer($r))->scan([' . var_export($dir, true) . ']);'
        . 'echo json_encode($res->toArray());';
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
    $decoded = json_decode((string) $out, true);
    return is_array($decoded) ? $decoded : ['error' => (string) $out];
}

$fixtures = __DIR__ . '/fixtures/agent_app';
$tmpBase  = sys_get_temp_dir() . '/php-ai-registry-test_' . getmypid();
rrmdir($tmpBase);
@mkdir($tmpBase, 0700, true);

// ===================================================================
echo "\n=== 1. PhpDocParser ===\n";
// ===================================================================

$parser = new PhpDocParser();
$doc = "/**\n"
    . " * 修改文章\n"
    . " *\n"
    . " * @agent-tool article.update\n"
    . " * @agent-description 修改指定文章的标题、\n"
    . " *   摘要和正文\n"
    . " * @agent-controller article/update\n"
    . " * @agent-risk medium\n"
    . " * @agent-confirm false\n"
    . " * @agent-permission article/update\n"
    . " * @agent-keywords 文章,编辑\n"
    . " * @agent-version 1.2\n"
    . " *\n"
    . " * @param int \$id 文章 ID\n"
    . " * @param string|null \$title 文章标题\n"
    . " * @param 'draft'|'published' \$status 状态\n"
    . " * @param int[] \$tags 标签 ID\n"
    . " * @param \$untyped 没有类型的参数\n"
    . " * @return array 修改后的文章\n"
    . " */";
$p = $parser->parse($doc);

assert_eq('@agent-tool 解析', 'article.update', $p['tool']);
assert_eq('docblock 摘要', '修改文章', $p['summary']);
assert_eq('多行 @agent-description 拼接', '修改指定文章的标题、 摘要和正文', $p['description']);
assert_eq('@agent-controller', 'article/update', $p['controller']);
assert_eq('@agent-risk', 'medium', $p['risk']);
assert_eq('@agent-confirm false', false, $p['confirm']);
assert_eq('@agent-permission', ['article/update'], $p['permission']);
assert_eq('@agent-keywords 逗号分隔', ['文章', '编辑'], $p['keywords']);
assert_eq('@agent-version', '1.2', $p['version']);
assert_eq('@param int 映射 integer', ['integer'], $p['params']['id']['types']);
assert_eq('@param 描述', '文章 ID', $p['params']['id']['description']);
assert_eq('@param string|null', ['string', 'null'], $p['params']['title']['types']);
assert_eq('@param 字面量联合 → enum', ['draft', 'published'], $p['params']['status']['enum']);
assert_eq('@param int[] → array', ['array'], $p['params']['tags']['types']);
assert_eq('@param int[] → items', ['integer'], $p['params']['tags']['items']);
assert_eq('@param 无类型仍能拿到名字与描述', '没有类型的参数', $p['params']['untyped']['description']);
assert_eq('@return 描述', '修改后的文章', $p['return']['description']);

$empty = $parser->parse(false);
assert_eq('无 docblock 返回空 tool', '', $empty['tool']);

$noTag = $parser->parse("/**\n * 只是普通注释\n */");
assert_eq('没有 @agent-tool 时 tool 为空', '', $noTag['tool']);
assert_eq('没有 @agent-description 时用摘要兜底', '只是普通注释', $noTag['description']);

assert_eq('mapType float → number', 'number', PhpDocParser::mapType('float'));
assert_eq('mapType 具名类 → object', 'object', PhpDocParser::mapType('App\\Model\\Article'));
assert_eq('mapType mixed → 空（不限制）', '', PhpDocParser::mapType('mixed'));

// ===================================================================
echo "\n=== 2. ToolParameter / ToolDefinition ===\n";
// ===================================================================

$param = new ToolParameter([
    'name' => 'id', 'types' => ['integer'], 'description' => '文章 ID', 'required' => true,
]);
assert_eq('单类型 schema', ['type' => 'integer', 'description' => '文章 ID'], $param->toSchema());

$nullable = new ToolParameter(['name' => 't', 'types' => ['string', 'null'], 'default' => null]);
assert_eq('多类型 schema 用数组', ['string', 'null'], $nullable->toSchema()['type']);
test('default 为 null 时不写进 schema', !array_key_exists('default', $nullable->toSchema()));
test('isNullable', $nullable->isNullable());

$withDefault = new ToolParameter(['name' => 'page', 'types' => ['integer'], 'default' => 1]);
assert_eq('非 null 默认值写进 schema', 1, $withDefault->toSchema()['default']);

$roundTrip = ToolParameter::fromArray($param->toArray());
assert_eq('ToolParameter 往返一致', $param->toArray(), $roundTrip->toArray());

$def = new ToolDefinition([
    'name'            => 'article.update',
    'description'     => '修改指定文章',
    'controller_path' => 'article/update',
    'risk'            => 'medium',
    'parameters'      => [
        new ToolParameter(['name' => 'id', 'types' => ['integer'], 'description' => '文章 ID', 'required' => true]),
        new ToolParameter(['name' => 'title', 'types' => ['string', 'null'], 'description' => '标题']),
    ],
]);
$schema = $def->schema();
assert_eq('schema type', 'object', $schema['type']);
assert_eq('schema required', ['id'], $schema['required']);
assert_eq('schema properties 顺序与声明一致', ['id', 'title'], array_keys($schema['properties']));

$noParams = new ToolDefinition(['name' => 'x.y', 'description' => 'z']);
test('无参数时 properties 是对象（json 编码成 {}）', $noParams->schema()['properties'] instanceof stdClass);
test('无参数时不输出 required', !isset($noParams->schema()['required']));
assert_eq(
    '无参数 schema 的 JSON 形状',
    '{"type":"object","properties":{}}',
    (string) json_encode($noParams->schema())
);

assert_eq('非法风险等级归一化为 low', 'low', ToolDefinition::normalizeRisk('nonsense'));
assert_eq('风险权重 critical > high', true, ToolDefinition::riskWeight('critical') > ToolDefinition::riskWeight('high'));
assert_eq('toModelTool 结构', ['name', 'description', 'input_schema'], array_keys($def->toModelTool()));
assert_eq('summary 结构', ['name', 'description', 'risk', 'controller'], array_keys($def->summary()));

$defRound = ToolDefinition::fromArray($def->toArray());
assert_eq('ToolDefinition 往返：name', $def->getName(), $defRound->getName());
assert_eq('ToolDefinition 往返：参数个数', 2, count($defRound->getParameters()));
assert_eq('ToolDefinition 往返：schema 一致', json_encode($def->schema()), json_encode($defRound->schema()));

// ===================================================================
echo "\n=== 3. ReflectionSchemaBuilder ===\n";
// ===================================================================

require_once $fixtures . '/ArticleService.php';
$builder = new ReflectionSchemaBuilder();
$rm      = new ReflectionMethod('AgentAppFixture\\ArticleService', 'create');
$docp    = $parser->parse($rm->getDocComment());
$params  = $builder->build($rm, $docp['params']);

assert_eq('参数个数', 3, count($params));
assert_eq('第一个参数名', 'title', $params[0]->getName());
assert_eq('无默认值 → required', true, $params[0]->isRequired());
assert_eq('有默认值 → 非 required', false, $params[2]->isRequired());
assert_eq('PHPDoc 类型补上（无 PHP 类型声明时）', ['string'], $params[0]->getTypes());
assert_eq('PHPDoc 描述被采用', '文章标题', $params[0]->getDescription());
assert_eq('可空参数类型', ['string', 'null'], $params[2]->getTypes());

$rmList  = new ReflectionMethod('AgentAppFixture\\ArticleService', 'listArticles');
$docList = $parser->parse($rmList->getDocComment());
$pList   = $builder->build($rmList, $docList['params']);
assert_eq('默认值被记录', 1, $pList[0]->getDefault());
assert_eq('字面量联合 → enum 进入参数', ['draft', 'published'], $pList[2]->getEnum());
assert_eq('enum 参数类型是 string 而不是 object', ['string'], $pList[2]->getTypes());

// ===================================================================
echo "\n=== 4. ClassLocator ===\n";
// ===================================================================

$srcArticle = (string) file_get_contents($fixtures . '/ArticleService.php');
$srcNoTool  = (string) file_get_contents($fixtures . '/NoToolService.php');

test('有标注的文件被识别', ClassLocator::looksLikeTool($srcArticle));
test('无标注的文件被排除', !ClassLocator::looksLikeTool($srcNoTool));
assert_eq('提取全限定类名', ['AgentAppFixture\\ArticleService'], ClassLocator::classesIn($srcArticle));
assert_eq(
    '一个文件里多个类都能提取',
    ['AgentAppFixture\\AbstractBase'],
    ClassLocator::classesIn((string) file_get_contents($fixtures . '/AbstractBase.php'))
);
assert_eq(
    '匿名类与 ::class 不会被误当成类声明',
    ['Demo\\Real'],
    ClassLocator::classesIn("<?php\nnamespace Demo;\n\$a = Foo::class;\n\$b = new class {};\nclass Real {}\n")
);

// ===================================================================
echo "\n=== 5. SearchText 中文切词 ===\n";
// ===================================================================

assert_eq(
    'ASCII 按分隔符切开并小写',
    'article update',
    SearchText::normalize('Article.Update')
);
test('中文切成单字 + 二元组', strpos(SearchText::normalize('修改文章'), '文章') !== false);
test('中文单字也在', strpos(SearchText::normalize('修改文章'), ' 文 ') !== false);
test('MATCH 查询用 OR 拼接', strpos(SearchText::toMatchQuery('文章 修改'), ' OR ') !== false);
assert_eq('空查询没有 token', '', SearchText::toMatchQuery('   '));
test('打分：命中名字权重更高', SearchText::score('article', 'article.update', '别的描述')
    > SearchText::score('article', 'order.refund', 'article 出现在描述里'));
assert_eq('完全不命中得 0 分', 0.0, SearchText::score('完全无关的词', 'article.update', '修改文章'));

// ===================================================================
echo "\n=== 6. Registry 接口契约（Memory 与 SQLite 跑同一组断言）===\n";
// ===================================================================

$dbFile = $tmpBase . '/registry.sqlite';
/** @var array<string, ToolRegistryInterface> $registries */
$registries = [
    'Memory' => new MemoryToolRegistry(),
    'SQLite' => new SqliteToolRegistry($dbFile),
];

foreach ($registries as $label => $registry) {
    $t1 = new ToolDefinition([
        'name' => 'article.update', 'description' => '修改指定文章的标题和正文',
        'controller_path' => 'article/update', 'risk' => 'medium',
        'keywords' => ['文章', '编辑'], 'permissions' => ['article/update'],
        'class_name' => 'App\\ArticleService', 'method_name' => 'update',
        'source_file' => '/app/ArticleService.php', 'source_line' => 42, 'hash' => 'h1',
        'returns' => '修改后的文章',
        'parameters' => [
            new ToolParameter(['name' => 'id', 'types' => ['integer'], 'description' => '文章 ID', 'required' => true]),
            new ToolParameter(['name' => 'title', 'types' => ['string', 'null'], 'description' => '标题']),
        ],
    ]);
    $t2 = new ToolDefinition([
        'name' => 'order.refund', 'description' => '对订单发起退款',
        'controller_path' => 'order/refund', 'risk' => 'high', 'keywords' => ['订单', '退款'],
    ]);
    $t3 = new ToolDefinition([
        'name' => 'user.disabled', 'description' => '已下线的能力',
        'controller_path' => 'user/x', 'enabled' => false,
    ]);

    $registry->register($t1);
    $registry->register($t2);
    $registry->register($t3);

    assert_eq("[{$label}] count 默认不含禁用", 2, $registry->count());
    assert_eq("[{$label}] count 含禁用", 3, $registry->count(true));

    $got = $registry->get('article.update');
    test("[{$label}] get 返回定义", $got !== null);
    assert_eq("[{$label}] 描述往返", '修改指定文章的标题和正文', $got->getDescription());
    assert_eq("[{$label}] controller 往返", 'article/update', $got->getControllerPath());
    assert_eq("[{$label}] risk 往返", 'medium', $got->getRisk());
    assert_eq("[{$label}] keywords 往返", ['文章', '编辑'], $got->getKeywords());
    assert_eq("[{$label}] permissions 往返", ['article/update'], $got->getPermissions());
    assert_eq("[{$label}] source_line 往返", 42, $got->getSourceLine());
    assert_eq("[{$label}] returns 往返", '修改后的文章', $got->getReturns());
    assert_eq("[{$label}] 参数个数往返", 2, count($got->getParameters()));
    assert_eq("[{$label}] 参数必填往返", true, $got->getParameter('id')->isRequired());
    assert_eq("[{$label}] 参数类型往返", ['string', 'null'], $got->getParameter('title')->getTypes());
    assert_eq("[{$label}] schema 往返一致", json_encode($t1->schema()), json_encode($got->schema()));

    assert_eq("[{$label}] get 不存在返回 null", null, $registry->get('nope.nope'));

    $hits = $registry->search('文章 修改');
    test("[{$label}] 中文搜索命中 article.update", $hits !== [] && $hits[0]->getName() === 'article.update');
    $hits2 = $registry->search('退款');
    test("[{$label}] 中文搜索命中 order.refund", $hits2 !== [] && $hits2[0]->getName() === 'order.refund');
    $hits3 = $registry->search('article');
    test("[{$label}] 英文（工具名）搜索命中", $hits3 !== [] && $hits3[0]->getName() === 'article.update');
    assert_eq("[{$label}] 搜索不返回禁用的 Tool", 0, count($registry->search('已下线')));
    assert_eq("[{$label}] 空查询返回全部启用的", 2, count($registry->search('')));
    assert_eq("[{$label}] limit 生效", 1, count($registry->search('', new ToolSearchContext(['limit' => 1]))));

    // 权限过滤（Discovery 优化，不是安全边界）
    assert_eq(
        "[{$label}] permissions=null 不过滤",
        2,
        count($registry->search('', new ToolSearchContext()))
    );
    assert_eq(
        "[{$label}] permissions=[] 全挡",
        0,
        count($registry->search('', new ToolSearchContext(['permissions' => []])))
    );
    assert_eq(
        "[{$label}] 精确匹配放行",
        1,
        count($registry->search('', new ToolSearchContext(['permissions' => ['article/update']])))
    );
    assert_eq(
        "[{$label}] 前缀通配放行",
        1,
        count($registry->search('', new ToolSearchContext(['permissions' => ['article/*']])))
    );
    assert_eq(
        "[{$label}] 大小写与首斜杠不敏感",
        1,
        count($registry->search('', new ToolSearchContext(['permissions' => ['/Article/Update']])))
    );

    // 覆盖注册
    $t1b = ToolDefinition::fromArray(array_merge($t1->toArray(), ['description' => '改了描述']));
    $registry->register($t1b);
    assert_eq("[{$label}] 同名覆盖不新增", 2, $registry->count());
    assert_eq("[{$label}] 覆盖后描述更新", '改了描述', $registry->get('article.update')->getDescription());
    $reHits = $registry->search('改了描述');
    test("[{$label}] 覆盖后搜索索引同步", $reHits !== [] && $reHits[0]->getName() === 'article.update');

    // 文件 hash（增量扫描基础）
    $registry->setFileHash('/app/ArticleService.php', 'h1', 1);
    assert_eq("[{$label}] getFileHash", 'h1', $registry->getFileHash('/app/ArticleService.php'));
    assert_eq("[{$label}] getFileHash 未知文件", null, $registry->getFileHash('/app/None.php'));
    test("[{$label}] fileHashes 列表", isset($registry->fileHashes()['/app/ArticleService.php']));
    assert_eq("[{$label}] removeFile 删掉该文件的 Tool", 1, $registry->removeFile('/app/ArticleService.php'));
    assert_eq("[{$label}] removeFile 后 Tool 消失", null, $registry->get('article.update'));

    $registry->remove('order.refund');
    assert_eq("[{$label}] remove", null, $registry->get('order.refund'));

    $registry->clear();
    assert_eq("[{$label}] clear", 0, $registry->count(true));
    assert_eq("[{$label}] clear 同时清空文件 hash", [], $registry->fileHashes());
}

$sqliteReg = new SqliteToolRegistry($dbFile);
test('SQLite 检测到 FTS5', $sqliteReg->hasFts());
test('SQLite 文件已创建', is_file($dbFile));

// 目录不存在时自动创建
$nested = $tmpBase . '/deep/nested/dir/reg.sqlite';
new SqliteToolRegistry($nested);
test('Registry 目录不存在时自动创建', is_file($nested));

// FTS5 不可用时的降级路径：直接删掉 FTS 表模拟
$degraded = new SqliteToolRegistry($tmpBase . '/degraded.sqlite');
$degraded->register(new ToolDefinition([
    'name' => 'article.search', 'description' => '搜索文章内容', 'controller_path' => 'article/search',
]));
$refl = new ReflectionProperty('Ai\\Agent\\Registry\\SqliteToolRegistry', 'ftsAvailable');
$refl->setAccessible(true);
$refl->setValue($degraded, false);
$degradedHits = $degraded->search('文章');
test('FTS5 不可用时 LIKE 降级仍能搜到', $degradedHits !== [] && $degradedHits[0]->getName() === 'article.search');

// ===================================================================
echo "\n=== 7. ToolIndexer 扫描与增量 ===\n";
// ===================================================================

$scanDb   = $tmpBase . '/scan.sqlite';
$scanReg  = new SqliteToolRegistry($scanDb);
$indexer  = new ToolIndexer($scanReg);
$result   = $indexer->scan([$fixtures]);

assert_eq('首次扫描新增 8 个 Tool', 8, $result->toolsAdded);
assert_eq('首次扫描无更新', 0, $result->toolsUpdated);
test('缺 @agent-controller 记为 error', $result->hasErrors());
test('抽象类里的标注不入库', $scanReg->get('abstract.never') === null);
test('trait 里的标注不入库', $scanReg->get('trait.never') === null);
test('没有 @agent-tool 的 public 方法不入库', $scanReg->get('article.helper') === null);
test('无标注文件不会被 include（探针常量未定义）', !defined('AGENT_FIXTURE_NO_TOOL_LOADED'));
assert_eq('Registry 总数', 8, $scanReg->count(true));

$upd = $scanReg->get('article.update');
assert_eq('索引出的 controller', 'article/update', $upd->getControllerPath());
assert_eq('索引出的 risk', 'medium', $upd->getRisk());
assert_eq('索引出的 class', 'AgentAppFixture\\ArticleService', $upd->getClassName());
assert_eq('索引出的 method', 'update', $upd->getMethodName());
test('索引出的 source_line > 0', $upd->getSourceLine() > 0);
test('索引出的 hash 非空', $upd->getHash() !== '');
assert_eq('索引出的参数个数', 4, count($upd->getParameters()));
assert_eq('索引出的必填参数', ['id'], $upd->schema()['required']);
assert_eq('@agent-confirm false 被记录为显式声明', true, $upd->isConfirmDeclared());

$result2 = $indexer->scan([$fixtures]);
assert_eq('二次扫描全部跳过（增量命中）', 0, $result2->filesParsed);
assert_eq('二次扫描无 Tool 变动', 0, $result2->toolsChanged());
test('二次扫描 filesSkipped 覆盖全部文件', $result2->filesSkipped === $result2->filesScanned);

$result3 = $indexer->scan([$fixtures], ['force' => true]);
test('force 强制重扫全部文件', $result3->filesParsed > 0);
assert_eq('force 重扫不产生新增', 0, $result3->toolsAdded);

// 复制一份 fixtures 到临时目录，测「改文件」「删文件」
$work = $tmpBase . '/work';
@mkdir($work, 0700, true);
copy($fixtures . '/ArticleService.php', $work . '/ArticleService.php');
copy($fixtures . '/OrderService.php', $work . '/OrderService.php');
// 避免与 fixtures 的类名冲突：换个命名空间
foreach (['ArticleService', 'OrderService'] as $f) {
    $c = (string) file_get_contents($work . '/' . $f . '.php');
    $c = str_replace('namespace AgentAppFixture;', 'namespace AgentAppWork;', $c);
    file_put_contents($work . '/' . $f . '.php', $c);
}

$workDb  = $tmpBase . '/work.sqlite';
$r1      = scan_subprocess($work, $workDb);
$workReg = new SqliteToolRegistry($workDb);
assert_eq('临时副本首扫 Tool 数', 8, $r1['tools_added']);

// 改一个文件：只该重扫它
$c = (string) file_get_contents($work . '/ArticleService.php');
$c = str_replace('分页列出文章，可按状态过滤', '分页列出文章（已改）', $c);
file_put_contents($work . '/ArticleService.php', $c);
$r2 = scan_subprocess($work, $workDb);
assert_eq('改一个文件只解析一个', 1, $r2['files_parsed']);
assert_eq('改动后是更新而非新增', 0, $r2['tools_added']);
assert_eq(
    'article.list 描述已更新',
    '分页列出文章（已改）',
    (new SqliteToolRegistry($workDb))->get('article.list')->getDescription()
);

// 删掉方法的标注：该 Tool 应被移除
$c = (string) file_get_contents($work . '/ArticleService.php');
$c = str_replace('@agent-tool article.delete', '(已移除标注) article.delete', $c);
file_put_contents($work . '/ArticleService.php', $c);
$r3 = scan_subprocess($work, $workDb);
assert_eq('标注被删 → Tool 被移除', 1, $r3['tools_removed']);
assert_eq('被移除的 Tool 不再存在', null, (new SqliteToolRegistry($workDb))->get('article.delete'));

// 删掉整个文件：其名下 Tool 全部清除
@unlink($work . '/OrderService.php');
$r4 = scan_subprocess($work, $workDb);
assert_eq('文件删除 → 其名下 Tool 全清', 3, $r4['tools_removed']);
assert_eq('order.refund 已清除', null, (new SqliteToolRegistry($workDb))->get('order.refund'));

// 整个文件的标注被拿掉：文件还在，但不再产出 Tool
$c = (string) file_get_contents($work . '/ArticleService.php');
$c = str_replace(['@agent-tool', 'AgentTool'], ['@removed-tool', 'RemovedTool'], $c);
file_put_contents($work . '/ArticleService.php', $c);
scan_subprocess($work, $workDb);
assert_eq('全部标注移除 → 剩余 Tool 被清空', 0, (new SqliteToolRegistry($workDb))->count(true));

// check 模式：不写库
$checkReg = new SqliteToolRegistry($tmpBase . '/check.sqlite');
$checkIx  = new ToolIndexer($checkReg);
$chk1 = $checkIx->check([$fixtures]);
test('check 首次报告 stale', $chk1->isStale());
assert_eq('check 不写库', 0, $checkReg->count(true));
$checkIx->scan([$fixtures]);
$chk2 = $checkIx->check([$fixtures]);
test('scan 之后 check 报告最新', !$chk2->isStale());

// Tool 名重复
$dupDir = $tmpBase . '/dup';
@mkdir($dupDir, 0700, true);
foreach (['A', 'B'] as $n) {
    file_put_contents($dupDir . '/Dup' . $n . '.php', "<?php\nnamespace DupNs;\n"
        . "class Dup{$n}\n{\n"
        . "    /**\n     * @agent-tool dup.same\n     * @agent-description 重名\n"
        . "     * @agent-controller dup/same\n     */\n"
        . "    public function run()\n    {\n        return [];\n    }\n}\n");
}
$dupReg = new SqliteToolRegistry($tmpBase . '/dup.sqlite');
$dupRes = (new ToolIndexer($dupReg))->scan([$dupDir]);
test('重名 Tool 被记为 error', $dupRes->hasErrors());
$dupErr = false;
foreach ($dupRes->errors as $e) {
    if (strpos($e, 'Tool 名重复') !== false) { $dupErr = true; }
}
test('重名错误信息可识别', $dupErr);
assert_eq('重名时只保留一个', 1, $dupReg->count(true));

// scanClass：模块安装 / 插件注册场景
$clsReg = new MemoryToolRegistry();
$clsRes = (new ToolIndexer($clsReg))->scanClass('AgentAppFixture\\ArticleService');
assert_eq('scanClass 索引出 5 个 Tool', 5, $clsRes->toolsAdded);
test('scanClass 后可 get', $clsReg->get('article.read') !== null);

// 路径不存在
$badReg = new MemoryToolRegistry();
$badRes = (new ToolIndexer($badReg))->scan([$tmpBase . '/not-exists']);
test('不存在的路径记 error 而不是崩溃', $badRes->hasErrors());

// ===================================================================
echo "\n=== 8. ToolSearcher / Discovery ===\n";
// ===================================================================

$dReg = new SqliteToolRegistry($tmpBase . '/disc.sqlite');
(new ToolIndexer($dReg))->scan([$fixtures]);

$searcher = new ToolSearcher($dReg);
$sum = $searcher->summaries('修改文章');
test('summaries 返回摘要而不是完整定义', $sum !== [] && array_keys($sum[0]) === ['name', 'description', 'risk', 'controller']);
test('summaries 首个命中是 article.update', $sum[0]['name'] === 'article.update');
test('searcher get 拿到完整定义', $searcher->get('article.update') !== null);

$limited = new ToolSearchContext(['permissions' => ['article/read']]);
$searcher->setContext($limited);
assert_eq('上下文过滤后只剩 1 个', 1, count($searcher->search('')));
assert_eq('过滤掉的 Tool get 返回 null', null, $searcher->get('article.update'));
assert_eq('放行的 Tool 仍能 get', 'article.read', $searcher->get('article.read')->getName());

// 网关参与候选过滤
$denyAll = new CallableControllerGateway(
    function ($path, array $args, array $ctx) { return 'never'; },
    function ($path, array $ctx) { return false; }
);
$gwSearcher = new ToolSearcher($dReg, $denyAll);
$gwLeft = $gwSearcher->search('');
// 网关只能对「有 Controller 入口」的 Tool 表态；order.orphan 没有入口，网关无从判断，
// 于是留在候选里——它会在 Executor 那一步被明确拒绝（没有入口就没有权限边界）
assert_eq('网关 can()=false 时有入口的 Tool 全被滤掉', 1, count($gwLeft));
assert_eq('留下的只有没声明 Controller 入口的那个', 'order.orphan', $gwLeft[0]->getName());
assert_eq('有入口的 Tool 被网关滤掉后 get 也拿不到', null, $gwSearcher->get('article.update'));

// ===================================================================
echo "\n=== 9. RegistryToolBridge 三工具 ===\n";
// ===================================================================

$gateway = new CallableControllerGateway(function ($path, array $args, array $ctx) {
    return ['path' => $path, 'args' => $args];
});
$bridge = new RegistryToolBridge($dReg, $gateway);
$tools  = $bridge->tools();
$tctx   = new ToolContext([]);

assert_eq('三个工具', ['search_app_tools', 'get_app_tool', 'call_app_tool'], array_keys($tools));
foreach ($tools as $name => $tool) {
    assert_eq("[{$name}] name() 与键一致", $name, $tool->name());
    test("[{$name}] description 非空", $tool->description() !== '');
    $s = $tool->schema();
    assert_eq("[{$name}] schema 是 object", 'object', $s['type']);
    test("[{$name}] schema 有 properties", isset($s['properties']) && $s['properties'] !== []);
}

$sr = $tools['search_app_tools']->execute(['query' => '文章 修改'], $tctx);
test('search_app_tools 成功', $sr->isSuccess());
$rows = json_decode((string) $sr->getContent(), true);
test('search_app_tools 返回 JSON 数组', is_array($rows) && isset($rows[0]['name']));
test('search_app_tools 返回摘要不含 parameters', !isset($rows[0]['parameters']));

$srEmpty = $tools['search_app_tools']->execute(['query' => 'zzzqqqwww'], $tctx);
test('搜不到时给出可操作的提示', $srEmpty->isSuccess()
    && strpos((string) $srEmpty->getContent(), '没有找到') !== false);

$gr = $tools['get_app_tool']->execute(['name' => 'article.update'], $tctx);
test('get_app_tool 成功', $gr->isSuccess());
$payload = json_decode((string) $gr->getContent(), true);
test('get_app_tool 返回完整 schema', isset($payload['parameters']['properties']['id']));
assert_eq('get_app_tool 返回 risk', 'medium', $payload['risk']);

$gr2 = $tools['get_app_tool']->execute(['name' => 'not.exists'], $tctx);
test('get_app_tool 对不存在的 Tool 返回失败', !$gr2->isSuccess());

// ===================================================================
echo "\n=== 清理 ===\n";
rrmdir($tmpBase);
test('临时目录已清理', !is_dir($tmpBase));

echo "\n========================================\n";
echo "通过: {$passed}  失败: {$failed}\n";
echo "========================================\n";
exit($failed > 0 ? 1 : 0);
