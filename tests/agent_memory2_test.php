<?php
/**
 * Memory 2.0 测试——MemoryRetriever 检索、排序、压缩、过期
 *
 * 覆盖：
 *   1. 条目拆分（跳过空行与标题行）
 *   2. 相关性检索与排序
 *   3. 关键词搜索
 *   4. 注入提示词（相关 / 空查询回退）
 *   5. 自定义打分器
 *   6. 压缩与过期清理
 *   7. MemoryManager 上的快捷方法
 *
 * 运行：php tests/agent_memory2_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Memory\MemoryManager;
use Ai\Agent\Memory\MemoryRetriever;

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

$dir = sys_get_temp_dir() . '/php_ai_memory2_' . getmypid();
@mkdir($dir, 0777, true);

$mm = new MemoryManager($dir);
$mm->write('project', "# 项目记忆\n\n登录走 JWT，密钥放在 config/jwt.php\n数据库是 MySQL 8，字符集 utf8mb4\n");
$mm->write('user', "用户偏好 PHP，不用 Node\n");
$mm->write('task', "正在修 Auth.php 的 401 问题\n");

$retriever = new MemoryRetriever($mm);

// ===== 一、条目拆分 =====

echo "\n=== 一、条目拆分 ===\n";

$entries = $retriever->entries(['project']);
assert_eq('跳过空行与标题行后剩 2 条', 2, count($entries));
assert_eq('条目带作用域', 'project', $entries[0]['scope']);
test('条目带行号', $entries[0]['line'] > 0);
test('条目文本已 trim', $entries[0]['text'] === trim($entries[0]['text']));

$all = $retriever->entries();
assert_eq('全作用域共 4 条', 4, count($all));

// ===== 二、相关性检索 =====

echo "\n=== 二、相关性检索 ===\n";

$hits = $retriever->retrieve('登录接口报 401 怎么排查');
test('检索到结果', count($hits) > 0);
test('最相关的是登录相关记忆',
    strpos($hits[0]['text'], '登录') !== false || strpos($hits[0]['text'], '401') !== false);
test('结果带分数', $hits[0]['score'] > 0);

$dbHits = $retriever->retrieve('数据库字符集');
test('换个查询命中数据库记忆', count($dbHits) > 0 && strpos($dbHits[0]['text'], 'MySQL') !== false);

$none = $retriever->retrieve('完全无关的量子色动力学');
assert_eq('无关查询返回空', 0, count($none));

assert_eq('空查询返回空', 0, count($retriever->retrieve('')));

$scoped = $retriever->retrieve('登录', ['user']);
assert_eq('限定作用域后不跨域命中', 0, count($scoped));

$limited = $retriever->retrieve('PHP 登录 数据库 Auth', [], 1);
assert_eq('limit 生效', 1, count($limited));

// 英文查询
$mm->remember('project', 'Deploy script lives in bin/deploy.sh');
$enHits = $retriever->retrieve('deploy script');
test('英文查询可命中', count($enHits) > 0 && strpos($enHits[0]['text'], 'deploy.sh') !== false);

// ===== 三、关键词搜索 =====

echo "\n=== 三、关键词搜索 ===\n";

$found = $retriever->search('JWT');
assert_eq('关键词搜索命中 1 条', 1, count($found));
test('大小写不敏感', count($retriever->search('jwt')) === 1);
assert_eq('限定作用域搜索', 0, count($retriever->search('JWT', 'user')));
assert_eq('空关键词返回空', 0, count($retriever->search('')));

// ===== 四、注入提示词 =====

echo "\n=== 四、注入提示词 ===\n";

$prompt = $retriever->forPrompt('登录 401');
test('相关记忆块含标签', strpos($prompt, '<memory-relevant') === 0);
test('相关记忆块含作用域前缀', strpos($prompt, '[project]') !== false);
test('相关记忆块不含无关条目', strpos($prompt, 'Node') === false);

$fallback = $retriever->forPrompt('');
test('空查询回退到全量记忆', strpos($fallback, '<memory>') === 0);

$mm->setEnabled(false);
assert_eq('停用后不注入', '', $retriever->forPrompt('登录'));
$mm->setEnabled(true);

// ===== 五、自定义打分器 =====

echo "\n=== 五、自定义打分器 ===\n";

$custom = new MemoryRetriever($mm);
$custom->setScorer(function ($query, $text) {
    return strpos($text, 'MySQL') !== false ? 100.0 : 0.0;
});
$hits = $custom->retrieve('随便什么查询');
assert_eq('自定义打分器只留一条', 1, count($hits));
test('自定义打分器决定排序', strpos($hits[0]['text'], 'MySQL') !== false);

$custom->setTopK(3);
assert_eq('setTopK 生效', 3, $custom->getTopK());
$custom->setMinScore(200.0);
assert_eq('提高阈值后无结果', 0, count($custom->retrieve('随便什么查询')));
assert_eq('getMinScore 返回设置值', 200.0, $custom->getMinScore());

// ===== 六、压缩与过期 =====

echo "\n=== 六、压缩与过期 ===\n";

$mm->write('session', "第 1 条\n第 2 条\n第 3 条\n第 4 条\n第 5 条\n");
$removed = $retriever->compress('session', 2);
assert_eq('压缩删除 3 条', 3, $removed);
$left = $retriever->entries(['session']);
assert_eq('压缩后剩 2 条', 2, count($left));
test('保留的是最近的条目', $left[0]['text'] === '第 4 条' && $left[1]['text'] === '第 5 条');
assert_eq('条目数不足时不压缩', 0, $retriever->compress('session', 10));

$old = date('Y-m-d', time() - 40 * 86400);
$recent = date('Y-m-d', time() - 2 * 86400);
$mm->write('agent', "[{$old}] 很久以前试过方案 A\n[{$recent}] 昨天试过方案 B\n没有日期的一条\n");
$expired = $retriever->expire('agent', 30);
assert_eq('过期清理删除 1 条', 1, $expired);
$kept = $retriever->entries(['agent']);
assert_eq('过期后剩 2 条', 2, count($kept));
test('无日期条目被保留', strpos($kept[1]['text'], '没有日期') !== false);
assert_eq('没有过期条目时返回 0', 0, $retriever->expire('agent', 30));

// ===== 七、MemoryManager 快捷方法 =====

echo "\n=== 七、MemoryManager 快捷方法 ===\n";

test('retriever() 惰性创建', $mm->retriever() instanceof MemoryRetriever);
test('retriever() 返回同一实例', $mm->retriever() === $mm->retriever());
test('retrieve() 直达检索', count($mm->retrieve('登录 401')) > 0);
test('forPromptRelevant 只注入相关记忆',
    strpos($mm->forPromptRelevant('登录 401'), '<memory-relevant') === 0);
test('forPromptRelevant 空查询回退全量',
    strpos($mm->forPromptRelevant(''), '<memory>') === 0);

$custom2 = new MemoryRetriever($mm, ['topK' => 1]);
$mm->setRetriever($custom2);
test('setRetriever 替换生效', $mm->retriever() === $custom2);
assert_eq('替换后的 topK 生效', 1, count($mm->retrieve('登录 数据库 PHP')));

// ===== 清理 =====

foreach (MemoryManager::validScopes() as $scope) {
    @unlink($dir . '/' . $scope . '.md');
}
@rmdir($dir);

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);
