<?php
/**
 * InstructionManager 测试
 *
 * 覆盖：
 *   1. 从文件加载 CLAUDE.md / AGENTS.md
 *   2. 多级指令合并（优先级）
 *   3. toSystemPrompt 格式
 *   4. 启用/停用
 *   5. 集成到 AgentRuntime / Agent
 *
 * 运行：php tests/agent_instruction_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\Instruction\InstructionManager;

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

// ===== 1. 从文件加载 =====

echo "=== 一、从文件加载 CLAUDE.md / AGENTS.md ===\n";

$tmpRoot = sys_get_temp_dir() . '/inst_test_' . uniqid();
mkdir($tmpRoot, 0777, true);

// 创建项目级 CLAUDE.md
file_put_contents($tmpRoot . '/CLAUDE.md', "PHP 项目必须使用 PHP 7.1 语法\n禁止使用类型化属性");

// 创建 .claude/CLAUDE.md
mkdir($tmpRoot . '/.claude', 0777, true);
file_put_contents($tmpRoot . '/.claude/CLAUDE.md', "全局提示：claude 语法");

// 创建 .ai/AGENTS.md
mkdir($tmpRoot . '/.ai', 0777, true);
file_put_contents($tmpRoot . '/.ai/AGENTS.md', "AI 配置文件规范");

$im = new InstructionManager();
$im->loadFromDir($tmpRoot);
test('loadFromDir 加载了文件', count($im->getInstructions()) > 0);

// 只加载 CLAUDE.md（不加载 .claude/CLAUDE.md，因为没在根目录下）
$found = false;
foreach ($im->getInstructions() as $inst) {
    if (strpos($inst['path'], 'CLAUDE.md') !== false) {
        $found = true;
    }
}
test('CLAUDE.md 被加载', $found);

// ===== 2. loadFromTree 多级加载 =====

echo "\n=== 二、loadFromTree 多级加载 ===\n";

$im2 = new InstructionManager();
$im2->loadFromTree($tmpRoot);
test('loadFromTree 加载了指令', count($im2->getInstructions()) > 0);

// 应该加载了 .claude/CLAUDE.md 和根目录 CLAUDE.md 和 .ai/AGENTS.md
$paths = [];
foreach ($im2->getInstructions() as $inst) {
    $paths[] = $inst['path'];
}
$pathStr = implode('|', $paths);
test('包含 .claude/CLAUDE.md', strpos($pathStr, '.claude/CLAUDE.md') !== false);
test('包含根 CLAUDE.md', strpos($pathStr, '/CLAUDE.md') !== false);
test('包含 .ai/AGENTS.md', strpos($pathStr, '.ai/AGENTS.md') !== false);

// 不存在的目录
$im3 = new InstructionManager();
$im3->loadFromDir('/nonexistent_dir_xyz');
test('不存在的目录不报错', count($im3->getInstructions()) === 0);

// 空目录
$emptyDir = sys_get_temp_dir() . '/inst_empty_' . uniqid();
mkdir($emptyDir, 0777, true);
$im4 = new InstructionManager();
$im4->loadFromDir($emptyDir);
test('空目录不报错', count($im4->getInstructions()) === 0);
@rmdir($emptyDir);

// ===== 3. toSystemPrompt 格式 =====

echo "\n=== 三、toSystemPrompt 格式 ===\n";

$prompt = $im2->toSystemPrompt();
test('提示词包含 <instructions> 标签', strpos($prompt, '<instructions>') !== false);
test('提示词以 </instructions> 结尾', strpos($prompt, '</instructions>') !== false);
test('提示词包含 PHP 规范', strpos($prompt, 'PHP 7.1') !== false);
test('提示词包含 .claude 内容', strpos($prompt, 'claude 语法') !== false);

// 空指令
$im5 = new InstructionManager();
test('空指令 toSystemPrompt 为空', $im5->toSystemPrompt() === '');

// 停用
$im6 = new InstructionManager();
$im6->loadFromDir($tmpRoot);
$im6->setEnabled(false);
test('停用后 toSystemPrompt 为空', $im6->toSystemPrompt() === '');

// ===== 4. 清空 =====

echo "\n=== 四、清空 ===\n";

$im7 = new InstructionManager();
$im7->loadFromDir($tmpRoot);
test('清空前有指令', count($im7->getInstructions()) > 0);
$im7->clear();
test('清空后无指令', count($im7->getInstructions()) === 0);
test('清空后 toSystemPrompt 为空', $im7->toSystemPrompt() === '');

// ===== 5. 自定义文件名 =====

echo "\n=== 五、自定义文件名 ===\n";

$im8 = new InstructionManager();
$im8->setFilenames(['CUSTOM.md']);
file_put_contents($tmpRoot . '/CUSTOM.md', '自定义规则');
$im8->loadFromDir($tmpRoot);
test('自定义文件名被加载', count($im8->getInstructions()) > 0);
$prompt8 = $im8->toSystemPrompt();
test('自定义文件内容正确', strpos($prompt8, '自定义规则') !== false);

// ===== 6. 集成到 AgentRuntime / Agent =====

echo "\n=== 六、集成到 AgentRuntime / Agent ===\n";

$ai = new AI();
$ai->setConfig([
    'model'      => 'deepseek-anthropic',
    'api_key'    => 'sk-test',
    'max_tokens' => 1024,
]);

$agent = new Agent($ai);
$agent->setInstructionManager($im2);
$runtime = $agent->getRuntime();
test('AgentRuntime 已设置指令管理器', $runtime->getInstructionManager() !== null);
assert_eq('getInstructionManager 返回同一实例', true, $runtime->getInstructionManager() === $im2);

// loadInstructions 快捷方式
$agent2 = new Agent($ai);
$agent2->loadInstructions($tmpRoot);
$im9 = $agent2->getRuntime()->getInstructionManager();
test('loadInstructions 创建了 InstructionManager', $im9 !== null);
test('loadInstructions 加载了指令', $im9->toSystemPrompt() !== '');

// 清理临时目录
@unlink($tmpRoot . '/CLAUDE.md');
@unlink($tmpRoot . '/.claude/CLAUDE.md');
@unlink($tmpRoot . '/.ai/AGENTS.md');
@unlink($tmpRoot . '/CUSTOM.md');
@rmdir($tmpRoot . '/.claude');
@rmdir($tmpRoot . '/.ai');
@rmdir($tmpRoot);

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);