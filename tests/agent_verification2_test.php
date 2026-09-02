<?php
/**
 * Verification Framework 2.0 测试
 *
 * 覆盖：
 *   1. VerificationResult 增强（verifierName / errors / toArray）
 *   2. VerifierInterface + BaseVerifier 通用能力（supports / setEnabled）
 *   3. PhpSyntaxVerifier 语法检查（通过 / 失败 / 跳过）
 *
 * 运行：php tests/agent_verification2_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Verification\PhpSyntaxVerifier;
use Ai\Agent\Verification\VerificationResult;
use Ai\Agent\Verification\VerifierInterface;

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

$tmpDir = sys_get_temp_dir() . '/php_ai_verify2_' . getmypid();
@mkdir($tmpDir, 0777, true);

// ===== 一、VerificationResult 增强 =====

echo "\n=== 一、VerificationResult 增强 ===\n";

$ok = VerificationResult::passed('php -l a.php', 'No syntax errors', 'php_syntax');
test('passed 结果通过', $ok->isPassed());
assert_eq('passed 保留命令', 'php -l a.php', $ok->getCommand());
assert_eq('passed 保留输出', 'No syntax errors', $ok->getOutput());
assert_eq('passed 记录验证器名', 'php_syntax', $ok->getVerifierName());
assert_eq('passed 无结构化错误', [], $ok->getErrors());

$bad = VerificationResult::failed('php -l a.php', 'Parse error', 'php_syntax');
test('failed 结果未通过', !$bad->isPassed());
assert_eq('failed 错误进 error 而非 output', 'Parse error', $bad->getError());
assert_eq('failed output 为空', '', $bad->getOutput());
assert_eq('failed 记录验证器名', 'php_syntax', $bad->getVerifierName());

$bad->addError('unexpected token', 'src/A.php', 12);
assert_eq('addError 追加一条', 1, count($bad->getErrors()));
assert_eq('addError 记录行号', 12, $bad->getErrors()[0]['line']);
assert_eq('addError 记录文件', 'src/A.php', $bad->getErrors()[0]['file']);

$bad->setErrors([['message' => 'm1', 'file' => 'b.php', 'line' => '7'], ['message' => 'm2']]);
assert_eq('setErrors 覆盖旧错误', 2, count($bad->getErrors()));
assert_eq('setErrors 行号转 int', 7, $bad->getErrors()[0]['line']);
assert_eq('setErrors 缺省字段兜底', 0, $bad->getErrors()[1]['line']);

$arr = $bad->toArray();
assert_eq('toArray 含 passed', false, $arr['passed']);
assert_eq('toArray 含 verifier', 'php_syntax', $arr['verifier']);
test('toArray 含 errors', isset($arr['errors']) && count($arr['errors']) === 2);

// 向后兼容：旧的两参调用方式
$legacy = VerificationResult::passed('ls', 'out');
assert_eq('旧调用方式验证器名为空', '', $legacy->getVerifierName());

// ===== 二、BaseVerifier 通用能力 =====

echo "\n=== 二、BaseVerifier 通用能力 ===\n";

$verifier = new PhpSyntaxVerifier();
test('实现 VerifierInterface', $verifier instanceof VerifierInterface);
assert_eq('验证器名称', 'php_syntax', $verifier->name());
test('supportedTools 含 write_file', in_array('write_file', $verifier->supportedTools(), true));
test('supports(write_file) 为真', $verifier->supports('write_file'));
test('supports(bash) 为假', !$verifier->supports('bash'));
test('默认启用', $verifier->isEnabled());

$disabled = new PhpSyntaxVerifier(['enabled' => false]);
test('构造参数可禁用', !$disabled->isEnabled());
test('禁用后 verify 直接通过', $disabled->verify(['file_path' => __FILE__])->isPassed());
$disabled->setEnabled(true);
test('setEnabled 可重新启用', $disabled->isEnabled());

// ===== 三、PhpSyntaxVerifier 语法检查 =====

echo "\n=== 三、PhpSyntaxVerifier 语法检查 ===\n";

$goodFile = $tmpDir . '/good.php';
file_put_contents($goodFile, "<?php\nfunction good() { return 1; }\n");
$r = $verifier->verify(['tool_name' => 'write_file', 'file_path' => $goodFile]);
test('合法 PHP 文件通过', $r->isPassed());
assert_eq('通过时带验证器名', 'php_syntax', $r->getVerifierName());

$badFile = $tmpDir . '/bad.php';
file_put_contents($badFile, "<?php\nfunction bad( { return 1; }\n");
$r = $verifier->verify(['tool_name' => 'write_file', 'file_path' => $badFile]);
test('语法错误文件不通过', !$r->isPassed());
test('失败时带命令', strpos($r->getCommand(), 'php -l') === 0);
test('失败时解析出结构化错误', count($r->getErrors()) > 0);
$first = $r->getErrors()[0];
assert_eq('结构化错误定位到第 2 行', 2, $first['line']);
test('结构化错误含文件名', strpos($first['file'], 'bad.php') !== false);
test('结构化错误含描述', $first['message'] !== '');

// 跳过场景
test('非 PHP 文件跳过', $verifier->verify(['file_path' => $tmpDir . '/x.txt'])->isPassed());
test('无 file_path 跳过', $verifier->verify(['tool_name' => 'write_file'])->isPassed());
test('文件不存在跳过', $verifier->verify(['file_path' => $tmpDir . '/nope.php'])->isPassed());

// ===== 清理 =====

@unlink($goodFile);
@unlink($badFile);
@rmdir($tmpDir);

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);
