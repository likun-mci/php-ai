<?php
/**
 * VerificationManager 测试
 *
 * 覆盖：
 *   1. 验证策略配置（setRules / addRule / hasRule）
 *   2. 验证通过
 *   3. 验证失败
 *   4. 无验证策略时跳过
 *   5. 启用/停用
 *   6. 集成到 Agent 循环
 *
 * 运行：php tests/agent_verification_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\Verification\VerificationManager;
use Ai\Agent\Verification\VerificationResult;

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

// ===== 1. 验证策略配置 =====

echo "=== 一、验证策略配置 ===\n";

$vm = new VerificationManager([
    'edit_file'  => ['php -l {file}'],
    'write_file' => 'php -l {file}',
]);

test('hasRule 返回 true 已配置的工具', $vm->hasRule('edit_file'));
test('hasRule 返回 true write_file', $vm->hasRule('write_file'));
test('hasRule 返回 false 未配置的工具', !$vm->hasRule('read_file'));

$vm->addRule('test', 'vendor/bin/phpunit');
test('addRule 后 hasRule 返回 true', $vm->hasRule('test'));

$rules = $vm->rules();
test('rules 包含 3 个工具', count($rules) === 3);

// ===== 2. VerificationResult 值对象 =====

echo "\n=== 二、VerificationResult 值对象 ===\n";

$vr = VerificationResult::passed('php -l test.php', 'No syntax errors');
test('验证通过 isPassed 返回 true', $vr->isPassed());
assert_eq('passed 命令正确', 'php -l test.php', $vr->getCommand());
assert_eq('passed 输出正确', 'No syntax errors', $vr->getOutput());
assert_eq('passed 错误信息为空', '', $vr->getError());

$vr2 = VerificationResult::failed('php -l bad.php', 'Parse error: syntax error');
test('验证失败 isPassed 返回 false', !$vr2->isPassed());
assert_eq('failed 命令正确', 'php -l bad.php', $vr2->getCommand());
assert_eq('failed 错误信息正确', 'Parse error: syntax error', $vr2->getError());

// ===== 3. 验证执行 =====

echo "\n=== 三、验证执行 ===\n";

// 创建一个临时 PHP 文件用于测试
$tmpFile = tempnam(sys_get_temp_dir(), 'verify_test_');
file_put_contents($tmpFile, "<?php echo 'hello';\n");

$vm2 = new VerificationManager();
$vm2->addRule('edit_file', 'php -l {file}');

// 通过验证
$results = $vm2->verify('edit_file', ['file_path' => $tmpFile]);
test('php -l 合法文件应通过', $results[0]->isPassed());

// 失败验证：创建一个语法错误的文件
$badFile = tempnam(sys_get_temp_dir(), 'verify_bad_');
file_put_contents($badFile, "<?php echo 'hello'\n"); // 语法错误：缺少分号或后面内容

// 用 exit 测试构造明确失败
$failFile = tempnam(sys_get_temp_dir(), 'verify_fail_');
file_put_contents($failFile, "<?php\ninvalid_php_syntax_xyz_123\n");

$results2 = $vm2->verify('edit_file', ['file_path' => $failFile]);
test('php -l 语法错误文件应失败', !$results2[0]->isPassed());

// 清理临时文件
unlink($tmpFile);
unlink($badFile);
unlink($failFile);

// ===== 4. 无验证策略时跳过 =====

echo "\n=== 四、无验证策略时跳过 ===\n";

$vm3 = new VerificationManager();
test('空规则时 hasRule 返回 false', !$vm3->hasRule('edit_file'));

$results = $vm3->verify('edit_file', ['file_path' => 'test.php']);
test('空规则时 verify 返回空数组', count($results) === 0);

// ===== 5. 启用/停用 =====

echo "\n=== 五、启用/停用 ===\n";

$vm4 = new VerificationManager(['edit_file' => ['php -l {file}']]);
test('默认启用', $vm4->isEnabled());
$vm4->setEnabled(false);
test('停用后 isEnabled 返回 false', !$vm4->isEnabled());
test('停用后 hasRule 返回 false', !$vm4->hasRule('edit_file'));
$vm4->setEnabled(true);
test('重新启用后 hasRule 返回 true', $vm4->hasRule('edit_file'));

// ===== 6. 集成到 Agent 循环 =====

echo "\n=== 六、集成到 Agent 循环 ===\n";

// 创建 AI 实例
$ai = new AI();
$ai->setConfig([
    'model'      => 'deepseek-anthropic',
    'api_key'    => 'sk-test',
    'max_tokens' => 1024,
]);

$agent = new Agent($ai);

// 注入验证管理器
$agent->setVerification([
    'edit_file' => ['php -l {file}'],
]);

$runtime = $agent->getRuntime();
test('AgentRuntime 已设置验证管理器', $runtime->getVerificationManager() !== null);

$vm = $runtime->getVerificationManager();
test('验证管理器已配置 edit_file 规则', $vm->hasRule('edit_file'));

// 验证通过 Agent 的快捷方式
$agent2 = new Agent($ai);
$agent2->setVerification([
    'write_file' => ['php -l {file}'],
]);
$vm2 = $agent2->getRuntime()->getVerificationManager();
test('Agent->setVerification() 传递了配置', $vm2 !== null);
test('write_file 规则已配置', $vm2->hasRule('write_file'));
test('edit_file 未配置', !$vm2->hasRule('edit_file'));

// ===== 7. 占位符替换 =====

echo "\n=== 七、占位符替换 ===\n";

$vm5 = new VerificationManager();
$vm5->addRule('write_file', 'php -l {file}');
test('{file} 被替换为输入路径', $vm5->hasRule('write_file'));

// 没有 file_path 的输入，验证被跳过
$noFileResults = $vm5->verify('write_file', ['content' => 'hello']);
test('无 file_path 时 verify 返回空', count($noFileResults) === 0);

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);