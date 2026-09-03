<?php
/**
 * v1.65 Memory 测试
 *
 * 覆盖 dev.md 第十~十七节 / 第二十四~二十五节：
 *   remember 内容散列 id + 日期前缀 + 去重幂等；forget(id / pattern)；
 *   expire 真能删；forInjection 长期常驻 + id 剥离；RememberTool / ForgetTool；
 *   记忆写工具的权限把关；maxInject 统一；autoConsolidate 默认关。
 *
 * 运行：php tests/agent_memory3_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\Memory as MemoryClass;
use Ai\Agent\Memory\MemoryManager;
use Ai\Agent\Tools\RememberTool;
use Ai\Agent\Tools\ForgetTool;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Permission\PermissionManager;

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
        echo "  期望: " . var_export($expected, true) . "  实际: " . var_export($actual, true) . "\n";
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

$base = sys_get_temp_dir() . '/php-ai-memory3_' . getmypid();
rrmdir($base);
$dir = $base . '/mem';

// ===== 一、remember：id + 日期 + 去重 =====
echo "=== 一、remember id / 日期 / 去重 ===\n";
$mm = new MemoryManager($dir);
test('remember 返回 true', $mm->remember('project', '项目使用 CodeIgniter 3'));
$e = $mm->retriever()->entries(['project'])[0];
assert_eq('内容散列 id 与 dev.md 14.3 示例一致', '9bda3a', $e['id']);
assert_eq('date 为今天', date('Y-m-d'), $e['date']);
assert_eq('text 已剥离前缀', '项目使用 CodeIgniter 3', $e['text']);

$mm->remember('project', '项目使用 CodeIgniter 3');   // 重复内容
$mm->remember('project', '项目使用 CodeIgniter 3');
assert_eq('重复 remember 幂等，仍 1 条', 1, count($mm->retriever()->entries(['project'])));

$mm->remember('project', '数据库用 MySQL 8');
assert_eq('不同内容各占一条', 2, count($mm->retriever()->entries(['project'])));

// date=false 只关日期，id 仍写
$mm->remember('agent', '一条无日期记忆', ['date' => false]);
$ae = $mm->retriever()->entries(['agent'])[0];
assert_eq('date=false → date 为空', '', $ae['date']);
test('date=false → id 仍写（forget 才有得删）', $ae['id'] !== '');

// ===== 二、forget by id / pattern =====
echo "\n=== 二、forget by id / pattern ===\n";
$fm = new MemoryManager($base . '/fm');
$fm->remember('task', '登录走 JWT');
$fm->remember('task', '密码用 bcrypt');
$fm->remember('task', '登录失败要记录日志');
$entries = $fm->retriever()->entries(['task']);
$firstId = $entries[0]['id'];

assert_eq('forgetById 精确删 1 条', 1, $fm->forgetById('task', $firstId));
assert_eq('删后剩 2 条', 2, count($fm->retriever()->entries(['task'])));
assert_eq('删不存在的 id 返回 0', 0, $fm->forgetById('task', 'ffffff'));

// findByPattern
$cands = $fm->findByPattern('task', '登录');
assert_eq('pattern「登录」命中 1 条（另一条已删）', 1, count($cands));

// forget 不为不存在记忆建文件
$fresh = new MemoryManager($base . '/fresh');
$fresh->forget('user');
test('forget 不存在 scope 不建文件', !is_file($base . '/fresh/user.md'));
assert_eq('forgetById 对空 scope 返回 0', 0, $fresh->forgetById('user', 'abc123'));
test('forgetById 空 scope 不建文件', !is_file($base . '/fresh/user.md'));

// ===== 三、expire 真能删（默认日期前缀让它复活） =====
echo "\n=== 三、expire ===\n";
$em = new MemoryManager($base . '/em');
$old = date('Y-m-d', time() - 40 * 86400);
$em->getMemory('agent')->append('- [' . $old . '] (#abc123) 很久以前试过方案 A');
$em->remember('agent', '最近的结论');   // 今天
$em->remember('agent', '另一条近的');
$removed = $em->retriever()->expire('agent', 30);
assert_eq('expire 删掉 1 条过期', 1, $removed);
$kept = $em->retriever()->entries(['agent']);
assert_eq('剩 2 条近的', 2, count($kept));
test('近条目仍带 id 前缀（未被序列化成裸文本）', $kept[0]['id'] !== '');

// compress 保留前缀
$cm = new MemoryManager($base . '/cm');
$cm->remember('session', '第一');
$cm->remember('session', '第二');
$cm->remember('session', '第三');
$cm->retriever()->compress('session', 2);
$left = $cm->retriever()->entries(['session']);
assert_eq('compress 后剩 2 条', 2, count($left));
test('compress 保留 id 前缀', $left[0]['id'] !== '' && $left[1]['id'] !== '');

// ===== 四、forInjection：长期常驻 + id 剥离 =====
echo "\n=== 四、forInjection ===\n";
$im = new MemoryManager($base . '/im');
$im->remember('project', '项目常驻的知识');
$im->remember('task', '当前任务的临时便签 xyz');

// 零命中查询：长期 project 仍常驻，不失忆
$inj = $im->forInjection('完全无关的量子色动力学');
test('零命中时长期记忆仍常驻', strpos($inj, '项目常驻的知识') !== false);
test('默认不输出 (#id)', strpos($inj, '(#') === false);

$injId = $im->forInjection('', true);
test('withId=true 时带 (#id)', strpos($injId, '(#') !== false);

// 相关性查询命中短期
$injRel = $im->forInjection('临时便签 xyz');
test('相关查询命中短期 task', strpos($injRel, 'xyz') !== false);

// 停用后不注入
$im->setEnabled(false);
assert_eq('停用后 forInjection 为空', '', $im->forInjection('项目'));
$im->setEnabled(true);

// ===== 五、RememberTool / ForgetTool =====
echo "\n=== 五、RememberTool / ForgetTool ===\n";
$tm = new MemoryManager($base . '/tm');
$rt = new RememberTool($tm);
$ft = new ForgetTool($tm);
$ctx = new ToolContext([]);

assert_eq('RememberTool 名', 'remember', $rt->name());
assert_eq('ForgetTool 名', 'forget', $ft->name());

$r = $rt->execute(['scope' => 'project', 'content' => 'API 层必须过 Service'], $ctx);
test('remember 执行成功', $r->isSuccess());
test('落盘可读', strpos($tm->read('project'), 'API 层必须过 Service') !== false);

$rBad = $rt->execute(['scope' => 'nope', 'content' => 'x'], $ctx);
test('无效 scope 报错', !$rBad->isSuccess());
$rEmpty = $rt->execute(['scope' => 'project', 'content' => '   '], $ctx);
test('空内容报错', !$rEmpty->isSuccess());

// forget by id
$pid = $tm->retriever()->entries(['project'])[0]['id'];
$fById = $ft->execute(['scope' => 'project', 'memory_id' => $pid], $ctx);
test('forget by id 成功', $fById->isSuccess());
assert_eq('project 已空', 0, count($tm->retriever()->entries(['project'])));

// forget pattern：多条命中不批量删，返回候选
$tm->remember('task', '登录接口 A');
$tm->remember('task', '登录接口 B');
$tm->remember('task', '与登录无关的事');
$fMulti = $ft->execute(['scope' => 'task', 'pattern' => '登录接口'], $ctx);
test('pattern 命中多条不报错', $fMulti->isSuccess());
test('pattern 命中多条返回候选未删', strpos($fMulti->getContent(), '命中') !== false);
assert_eq('多条命中后一条没删', 3, count($tm->retriever()->entries(['task'])));

// pattern 命中唯一则删
$fOne = $ft->execute(['scope' => 'task', 'pattern' => '无关的事'], $ctx);
test('pattern 唯一命中删除成功', $fOne->isSuccess());
assert_eq('删后剩 2 条', 2, count($tm->retriever()->entries(['task'])));

// ===== 六、记忆写工具的权限把关 =====
echo "\n=== 六、权限把关 ===\n";
$permManual = new PermissionManager(PermissionManager::MODE_MANUAL);
$resManual = $permManual->check($rt, ['scope' => 'project', 'content' => 'x'], $ctx);
test('manual 模式 remember 需要询问', $resManual->needsAsk() || $resManual->isDenied());

$permDontAsk = new PermissionManager(PermissionManager::MODE_DONT_ASK);
test('dont_ask 放行 remember', $permDontAsk->check($rt, [], $ctx)->isAllowed());
$permBypass = new PermissionManager(PermissionManager::MODE_BYPASS);
test('bypass 放行 forget', $permBypass->check($ft, [], $ctx)->isAllowed());

// ===== 七、maxInject 统一 & autoConsolidate 默认关 =====
echo "\n=== 七、maxInject / autoConsolidate ===\n";
$reflect = new \ReflectionClass(MemoryClass::class);
$ctor = $reflect->getConstructor();
$params = $ctor->getParameters();
assert_eq('Memory maxInject 默认 10000', 10000, $params[1]->getDefaultValue());

$ai = new AI();
$agent = Agent::create($ai);
test('autoConsolidate 返回自身链式', $agent->autoConsolidate(true) === $agent);
$agent->autoConsolidate(false);
test('autoConsolidate(false) 不报错', true);

// ===== 八、工具注册：有 memory + 有工具的 Agent 才注册 =====
echo "\n=== 八、remember/forget 工具注册 ===\n";
$fakeHome = $base . '/home';
@mkdir($fakeHome, 0700, true);
$origHome = getenv('HOME');
putenv('HOME=' . $fakeHome);

// 有工具 + 有记忆意图（userId）→ chat 前 registerMemoryTools 生效
$a = Agent::create($ai)->setWorkdir($base . '/proj')->setUserId('u1');
$a->tools(['dummy' => ['description' => 'd', 'handler' => function () { return 'ok'; }]]);
// 手动触发（chat 会调，但无 key 不便跑完整循环）——直接调用受保护逻辑的等价路径：
$rm = new \ReflectionMethod(Agent::class, 'ensurePersistence');
$rm->setAccessible(true);
$rm->invoke($a);
$rmt = new \ReflectionMethod(Agent::class, 'registerMemoryTools');
$rmt->setAccessible(true);
$rmt->invoke($a);
$reg = $a->getRuntime()->getToolRegistry();
test('有 memory+工具 → 注册 remember', $reg->has('remember'));
test('有 memory+工具 → 注册 forget', $reg->has('forget'));

// 无 memory 的裸 agent 不注册
$bare = Agent::create($ai);
$bare->tools(['dummy' => ['description' => 'd', 'handler' => function () { return 'ok'; }]]);
$rmt->invoke($bare);
test('无 memory 不注册 remember', !$bare->getRuntime()->getToolRegistry()->has('remember'));

if ($origHome === false) { putenv('HOME'); } else { putenv('HOME=' . $origHome); }

// ===== 收尾 =====
echo "\n" . str_repeat('=', 60) . "\n";
rrmdir($base);
if ($failed === 0) {
    echo "全部通过：{$passed} 通过，0 失败\n";
    exit(0);
}
echo "有失败：{$passed} 通过，{$failed} 失败\n";
exit(1);
