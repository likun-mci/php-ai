<?php
/**
 * MemoryManager 分作用域记忆测试
 *
 * 覆盖：
 *   1. 作用域验证
 *   2. remember / read / forget
 *   3. 多作用域独立存储
 *   4. forPrompt 合并格式
 *   5. 启用/停用
 *   6. 集成到 AgentRuntime / Agent
 *
 * 运行：php tests/agent_memory_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\Memory\MemoryManager;

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
}

// 临时目录
$memDir = sys_get_temp_dir() . '/mem_test_' . uniqid();

// ===== 1. 作用域验证 =====

echo "=== 一、作用域验证 ===\n";

test('user 是有效作用域', MemoryManager::isValidScope('user'));
test('project 是有效作用域', MemoryManager::isValidScope('project'));
test('session 是有效作用域', MemoryManager::isValidScope('session'));
test('task 是有效作用域', MemoryManager::isValidScope('task'));
test('agent 是有效作用域', MemoryManager::isValidScope('agent'));
test('invalid 不是有效作用域', !MemoryManager::isValidScope('invalid'));
test('空字符串不是有效作用域', !MemoryManager::isValidScope(''));
assert_eq('有效作用域数量', 5, count(MemoryManager::validScopes()));

// ===== 2. 基本读写 =====

echo "\n=== 二、基本读写 ===\n";

$mm = new MemoryManager($memDir);
test('remember 返回 true', $mm->remember('user', '用户喜欢 PHP'));
test('read 返回内容', strpos($mm->read('user'), '用户喜欢 PHP') !== false);

$mm->remember('user', '用户喜欢深色主题');
$content = $mm->read('user');
test('追加后包含两条', strpos($content, '用户喜欢深色主题') !== false);

// 覆盖写入
$mm->write('user', '仅此一条');
assert_eq('覆盖后内容', '仅此一条', $mm->read('user'));

// 清空
$mm->forget('user');
assert_eq('清空后内容为空', '', $mm->read('user'));

// 无效作用域
test('无效作用域 remember 返回 false', !$mm->remember('invalid', 'x'));
test('无效作用域 read 返回空', $mm->read('invalid') === '');
test('无效作用域 forget 返回 false', !$mm->forget('invalid'));

// ===== 3. 多作用域独立存储 =====

echo "\n=== 三、多作用域独立存储 ===\n";

$mm2 = new MemoryManager($memDir);
$mm2->remember('user', '用户喜欢 PHP');
$mm2->remember('project', '项目使用 CodeIgniter 3');
$mm2->remember('session', '正在修登录');
$mm2->remember('task', '正在修改 Auth.php');
$mm2->remember('agent', '上次尝试方案 A 失败');

test('user 已存储', $mm2->read('user') !== '');
test('project 已存储', $mm2->read('project') !== '');
test('session 已存储', $mm2->read('session') !== '');
test('task 已存储', $mm2->read('task') !== '');
test('agent 已存储', $mm2->read('agent') !== '');

// 验证各作用域文件独立
$userFile = $memDir . '/user.md';
$projectFile = $memDir . '/project.md';
test('user.md 存在', is_file($userFile));
test('project.md 存在', is_file($projectFile));

// ===== 4. forPrompt 合并格式 =====

echo "\n=== 四、forPrompt 合并格式 ===\n";

$prompt = $mm2->forPrompt();
test('包含 <memory> 标签', strpos($prompt, '<memory>') !== false);
test('以 </memory> 结尾', strpos($prompt, '</memory>') !== false);
test('包含 user 作用域标题', strpos($prompt, '## user') !== false);
test('包含 project 作用域标题', strpos($prompt, '## project') !== false);
test('包含 session 作用域标题', strpos($prompt, '## session') !== false);
test('包含 task 作用域标题', strpos($prompt, '## task') !== false);
test('包含 agent 作用域标题', strpos($prompt, '## agent') !== false);
test('包含记忆内容', strpos($prompt, '用户喜欢 PHP') !== false);
test('包含 project 内容', strpos($prompt, 'CodeIgniter 3') !== false);

// 清空后 forPrompt 为空
$mm2->clearAll();
test('clearAll 后 forPrompt 为空', $mm2->forPrompt() === '');

// 空记忆管理器
$mm3 = new MemoryManager();
test('无 baseDir 时 forPrompt 为空', $mm3->forPrompt() === '');

// ===== 5. 启用/停用 =====

echo "\n=== 五、启用/停用 ===\n";

$mm4 = new MemoryManager($memDir, ['enabled' => false]);
$mm4->remember('user', '测试');
test('停用时 forPrompt 为空', $mm4->forPrompt() === '');

$mm5 = new MemoryManager($memDir, ['enabled' => true]);
test('启用时默认 enabled', $mm5->isEnabled());
$mm5->setEnabled(false);
test('setEnabled(false) 生效', !$mm5->isEnabled());
$mm5->setEnabled(true);
test('setEnabled(true) 生效', $mm5->isEnabled());

// ===== 6. 集成到 AgentRuntime / Agent =====

echo "\n=== 六、集成到 AgentRuntime / Agent ===\n";

$ai = new AI();
$ai->setConfig([
    'model'      => 'deepseek-anthropic',
    'api_key'    => 'sk-test',
    'max_tokens' => 1024,
]);

$mm6 = new MemoryManager($memDir);
$mm6->remember('user', '用户喜欢 PHP');

$agent = new Agent($ai);
$agent->setMemoryManager($mm6);
$runtime = $agent->getRuntime();
test('AgentRuntime 已设置记忆管理器', $runtime->getMemoryManager() !== null);
assert_eq('getMemoryManager 返回同一实例', true, $runtime->getMemoryManager() === $mm6);

// setMemoryDir 快捷方式
$agent2 = new Agent($ai);
$agent2->setMemoryDir($memDir);
$mm7 = $agent2->getRuntime()->getMemoryManager();
test('setMemoryDir 创建了 MemoryManager', $mm7 !== null);
test('setMemoryDir 设置了 baseDir', $mm7->getBaseDir() === $memDir);

// 清理临时文件
$mm2->clearAll();
foreach (MemoryManager::validScopes() as $scope) {
    $f = $memDir . '/' . $scope . '.md';
    if (is_file($f)) @unlink($f);
}
if (is_dir($memDir)) @rmdir($memDir);

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);