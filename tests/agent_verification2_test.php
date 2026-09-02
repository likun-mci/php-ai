<?php
/**
 * Verification Framework 2.0 测试
 *
 * 覆盖：
 *   1. VerificationResult 增强（verifierName / errors / toArray）
 *   2. VerifierInterface + BaseVerifier 通用能力（supports / setEnabled）
 *   3. PhpSyntaxVerifier 语法检查（通过 / 失败 / 跳过）
 *   4. SecurityVerifier 危险函数与硬编码凭据扫描
 *   5. UnitTestVerifier 测试命令执行与失败解析
 *   6. GitDiffVerifier 改动规模与受保护路径
 *   7. VerificationManager 整合验证器（与命令式规则共存）
 *
 * 运行：php tests/agent_verification2_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Verification\GitDiffVerifier;
use Ai\Agent\Verification\PhpSyntaxVerifier;
use Ai\Agent\Verification\SecurityVerifier;
use Ai\Agent\Verification\UnitTestVerifier;
use Ai\Agent\Verification\VerificationManager;
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

// ===== 四、SecurityVerifier =====

echo "\n=== 四、SecurityVerifier ===\n";

$sec = new SecurityVerifier();
assert_eq('安全验证器名称', 'security', $sec->name());

$safeFile = $tmpDir . '/safe.php';
file_put_contents($safeFile, "<?php\n// 这里提到 eval 只是注释\n\$s = 'exec';\nfunction run() { return 1; }\n");
$r = $sec->verify(['tool_name' => 'write_file', 'file_path' => $safeFile]);
test('注释与字符串里的危险词不误报', $r->isPassed());

$dangerFile = $tmpDir . '/danger.php';
file_put_contents($dangerFile, "<?php\n\$cmd = 'ls';\nexec(\$cmd);\neval('1+1');\n");
$r = $sec->verify(['tool_name' => 'write_file', 'file_path' => $dangerFile]);
test('危险函数被拦下', !$r->isPassed());
assert_eq('拦下两处危险调用', 2, count($r->getErrors()));
test('第一处定位到第 3 行', $r->getErrors()[0]['line'] === 3);
test('错误信息含函数名', strpos($r->getErrors()[0]['message'], 'exec') !== false);

$sec->allow(['exec', 'eval']);
test('allow 放行后通过', $sec->verify(['file_path' => $dangerFile])->isPassed());

$methodFile = $tmpDir . '/method.php';
file_put_contents($methodFile, "<?php\n\$db->exec('SELECT 1');\nFoo::system('x');\n");
$sec2 = new SecurityVerifier();
test('方法调用与静态调用不误报', $sec2->verify(['file_path' => $methodFile])->isPassed());

$secretFile = $tmpDir . '/secret.php';
file_put_contents($secretFile, "<?php\n\$config = ['api_key' => 'sk-live-abcdefghijklmn'];\n");
$r = $sec2->verify(['file_path' => $secretFile]);
test('硬编码凭据被拦下', !$r->isPassed());
test('凭据错误信息可读', strpos($r->getError(), '凭据') !== false);

$placeholderFile = $tmpDir . '/placeholder.php';
file_put_contents($placeholderFile, "<?php\n\$config = ['api_key' => 'your-api-key-here'];\n");
test('占位符不算硬编码凭据', $sec2->verify(['file_path' => $placeholderFile])->isPassed());

// ===== 五、UnitTestVerifier =====

echo "\n=== 五、UnitTestVerifier ===\n";

$ut = new UnitTestVerifier(['command' => 'exit 0']);
assert_eq('测试验证器名称', 'unit_test', $ut->name());
test('测试命令成功即通过', $ut->verify(['tool_name' => 'edit_file', 'file_path' => $goodFile])->isPassed());

$utFail = new UnitTestVerifier(['command' => 'echo "1) FooTest::testBar" && exit 1']);
$r = $utFail->verify(['tool_name' => 'edit_file', 'file_path' => $goodFile]);
test('测试命令失败即不通过', !$r->isPassed());
test('解析出失败用例名', count($r->getErrors()) > 0
    && strpos($r->getErrors()[0]['message'], 'FooTest::testBar') !== false);

$txtFile = $tmpDir . '/notes.txt';
file_put_contents($txtFile, 'hello');
test('非 PHP 文件改动不跑测试', $utFail->verify(['file_path' => $txtFile])->isPassed());

$utDir = new UnitTestVerifier(['command' => 'exit 1', 'workdir' => $tmpDir . '/nope']);
test('执行目录不存在时跳过', $utDir->verify(['file_path' => $goodFile])->isPassed());

// ===== 六、GitDiffVerifier =====

echo "\n=== 六、GitDiffVerifier ===\n";

$gd = new GitDiffVerifier(['workdir' => $tmpDir]);
assert_eq('差异验证器名称', 'git_diff', $gd->name());
test('非 git 仓库直接通过', $gd->verify(['tool_name' => 'edit_file'])->isPassed());

$repo = $tmpDir . '/repo';
@mkdir($repo, 0777, true);
exec('cd ' . escapeshellarg($repo)
    . ' && git init -q && git config user.email t@t && git config user.name t'
    . ' && echo base > a.txt && git add -A && git commit -q -m init 2>&1', $ignored, $initCode);

if ($initCode === 0) {
    $gdRepo = new GitDiffVerifier(['workdir' => $repo]);
    test('干净仓库通过', $gdRepo->verify(['tool_name' => 'edit_file'])->isPassed());

    file_put_contents($repo . '/a.txt', "base\nchanged\nmore\n");
    $r = $gdRepo->verify(['tool_name' => 'edit_file']);
    test('有改动仍通过（未设上限）', $r->isPassed());
    test('输出含改动统计', strpos($r->getOutput(), '文件改动') !== false);

    $gdLimit = new GitDiffVerifier(['workdir' => $repo, 'maxLines' => 1]);
    $r = $gdLimit->verify(['tool_name' => 'edit_file']);
    test('超出行数上限不通过', !$r->isPassed());
    test('错误信息说明超限', strpos($r->getError(), '超过上限') !== false);
    test('错误列表带文件名', count($r->getErrors()) > 0
        && strpos($r->getErrors()[0]['file'], 'a.txt') !== false);

    $gdProtect = new GitDiffVerifier(['workdir' => $repo, 'protectPaths' => ['a.txt']]);
    $r = $gdProtect->verify(['tool_name' => 'edit_file']);
    test('改动受保护路径不通过', !$r->isPassed());
    test('错误信息点名受保护路径', strpos($r->getError(), 'a.txt') !== false);

    exec('rm -rf ' . escapeshellarg($repo));
} else {
    echo "  （跳过 git 仓库相关用例：git init 失败）\n";
}

// ===== 七、VerificationManager 整合验证器 =====

echo "\n=== 七、VerificationManager 整合验证器 ===\n";

$vm = new VerificationManager();
test('未挂载时无验证', !$vm->hasVerification('write_file'));

$vm->addVerifier(new PhpSyntaxVerifier());
test('挂载后 hasVerification 为真', $vm->hasVerification('write_file'));
test('hasRule 仍只看命令式规则', !$vm->hasRule('write_file'));
assert_eq('verifiers 返回一个', 1, count($vm->verifiers()));
test('getVerifier 按名取回', $vm->getVerifier('php_syntax') instanceof PhpSyntaxVerifier);
assert_eq('verifiersFor 匹配工具', 1, count($vm->verifiersFor('write_file')));
assert_eq('verifiersFor 不匹配无关工具', 0, count($vm->verifiersFor('bash')));

$results = $vm->verify('write_file', ['file_path' => $badFile]);
assert_eq('验证器结果并入返回', 1, count($results));
test('验证器捕获语法错误', !$results[0]->isPassed());
assert_eq('结果带验证器名', 'php_syntax', $results[0]->getVerifierName());

// 命令式规则与验证器共存
$vm->addRule('write_file', 'exit 0');
$results = $vm->verify('write_file', ['file_path' => $badFile]);
assert_eq('规则 + 验证器各出一条结果', 2, count($results));
test('第一条来自命令式规则', $results[0]->getVerifierName() === '');

// 重复挂载同名验证器只保留一个
$vm->addVerifier(new PhpSyntaxVerifier());
assert_eq('同名验证器不重复', 1, count($vm->verifiers()));

$vm->removeVerifier('php_syntax');
assert_eq('removeVerifier 生效', 0, count($vm->verifiers()));

$vm->setEnabled(false);
assert_eq('停用后 verify 返回空', 0, count($vm->verify('write_file', ['file_path' => $badFile])));

// ===== 清理 =====

@unlink($goodFile);
@unlink($badFile);
@unlink($safeFile);
@unlink($dangerFile);
@unlink($methodFile);
@unlink($secretFile);
@unlink($placeholderFile);
@unlink($txtFile);
@rmdir($tmpDir);

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);
