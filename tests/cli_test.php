<?php
/**
 * ClaudeCode CLI 包装器的命令行构造测试
 *
 * ClaudeCode.php 有 1810 行、101 个方法，其中绝大多数是一行式的链式 setter，
 * 真正有逻辑的只有两块：**参数渲染**与**可执行文件探测**。这两块又恰恰是
 * 最该被锁住的——参数渲染直接拼进 shell 命令，转义一旦出错就是命令注入。
 *
 * 本测试只覆盖这两块纯逻辑（不真正执行 claude 程序），为后续任何重构提供安全网。
 *
 * 运行：php tests/cli_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Cli\ClaudeCode;

function pad(string $t, int $w): string
{
    $n = $w - mb_strwidth($t, 'UTF-8');
    return $t . ($n > 0 ? str_repeat(' ', $n) : '');
}

$failures = [];
function check(bool $ok, string $name, string $detail = ''): void
{
    global $failures;
    if (!$ok) { $failures[] = $name . ($detail !== '' ? "（{$detail}）" : ''); }
    echo pad($name, 46), $ok ? "✓\n" : "✗ {$detail}\n";
}

$cli = new ClaudeCode();

$renderFlag = new ReflectionMethod($cli, 'renderFlag');
$renderFlag->setAccessible(true);
$renderFlags = new ReflectionMethod($cli, 'renderFlags');
$renderFlags->setAccessible(true);
$normalize = new ReflectionMethod($cli, 'normalizeFlagName');
$normalize->setAccessible(true);

// ===============================================================
// 一、单个参数渲染
// ===============================================================
echo "=== 一、单个参数渲染 ===\n\n";

check($renderFlag->invoke($cli, 'verbose', true) === ' --verbose',
      'true → 只出现开关本身', $renderFlag->invoke($cli, 'verbose', true));
check($renderFlag->invoke($cli, 'verbose', false) === '', 'false → 完全不出现');
check($renderFlag->invoke($cli, 'verbose', null) === '', 'null → 完全不出现');
check($renderFlag->invoke($cli, 'verbose', '') === '', '空串 → 完全不出现');
check($renderFlag->invoke($cli, 'model', 'opus') === " --model 'opus'",
      '标量值 → 带引号', $renderFlag->invoke($cli, 'model', 'opus'));
check($renderFlag->invoke($cli, 'count', 0) === " --count '0'",
      '整数 0 不被当作 false 丢掉', $renderFlag->invoke($cli, 'count', 0));

// 数组三种写法
check($renderFlag->invoke($cli, 'tools', ['Read', 'Write']) === " --tools 'Read,Write'",
      'comma 风格：逗号连接', $renderFlag->invoke($cli, 'tools', ['Read', 'Write']));
check($renderFlag->invoke($cli, 'add-dir', ['/a', '/b']) === " --add-dir '/a' '/b'",
      'variadic 风格：一个开关多个值', $renderFlag->invoke($cli, 'add-dir', ['/a', '/b']));
check($renderFlag->invoke($cli, 'plugin-dir', ['/a', '/b']) === " --plugin-dir '/a' --plugin-dir '/b'",
      'repeat 风格：开关重复出现', $renderFlag->invoke($cli, 'plugin-dir', ['/a', '/b']));
check($renderFlag->invoke($cli, 'unknown-flag', ['x', 'y']) === " --unknown-flag 'x y'",
      '未登记的数组参数默认空格连接', $renderFlag->invoke($cli, 'unknown-flag', ['x', 'y']));
check($renderFlag->invoke($cli, 'tools', []) === '', '空数组 → 完全不出现');
check($renderFlag->invoke($cli, 'tools', ['', 'Read', '']) === " --tools 'Read'",
      '数组内的空值被过滤', $renderFlag->invoke($cli, 'tools', ['', 'Read', '']));

// ===============================================================
// 二、命令注入防护（值全部来自调用方，可能含用户输入）
// ===============================================================
echo "\n=== 二、命令注入防护 ===\n\n";

$payloads = [
    "a'; rm -rf /; echo '"   => '分号 + 单引号闭合',
    'a$(whoami)'             => '命令替换 $()',
    'a`whoami`'              => '反引号',
    'a && rm -rf /'          => '逻辑与',
    "a\nrm -rf /"            => '换行注入',
    'a | tee /tmp/x'         => '管道',
    'a > /etc/passwd'        => '重定向',
];
foreach ($payloads as $payload => $desc) {
    $out = $renderFlag->invoke($cli, 'model', $payload);
    // escapeshellarg 后，整个值必须被单引号包住，且内部不能出现未转义的单引号
    $expected = " --model " . escapeshellarg($payload);
    check($out === $expected, "转义：{$desc}", $out);
}

// 数组元素同样要逐个转义
$arrOut = $renderFlag->invoke($cli, 'add-dir', ['/safe', '/tmp; rm -rf /']);
check($arrOut === ' --add-dir ' . escapeshellarg('/safe') . ' ' . escapeshellarg('/tmp; rm -rf /'),
      '数组元素逐个转义', $arrOut);

// ===============================================================
// 三、参数名归一
// ===============================================================
echo "\n=== 三、参数名归一 ===\n\n";

check($normalize->invoke($cli, 'output_format') === 'output-format',
      '下划线 → 连字符', $normalize->invoke($cli, 'output_format'));
check($normalize->invoke($cli, 'output-format') === 'output-format', '已是连字符则不变');
check($normalize->invoke($cli, '  model  ') === 'model', '首尾空白被裁掉');

// ===============================================================
// 四、整体渲染：setter → 命令行
// ===============================================================
echo "\n=== 四、整体渲染 ===\n\n";

$cli2 = (new ClaudeCode())
    ->setModel('opus')
    ->setAllowedTools(['Read', 'Bash'])
    ->setSkipPermissions(true)
    ->setAddDirs(['/proj', '/lib']);

$out = $renderFlags->invoke($cli2, []);
check(strpos($out, " --model 'opus'") !== false, 'setModel 落到命令行', $out);
// 注意是驼峰 --allowedTools，这是 Claude Code CLI 的真实参数名，
// normalizeFlagName 只把下划线转连字符，不会动驼峰
check(strpos($out, " --allowedTools 'Read Bash'") !== false,
      'setAllowedTools 落到命令行（驼峰参数名）', $out);
check(strpos($out, " --add-dir '/proj' '/lib'") !== false, 'setAddDirs 用 variadic 写法');

// 调用级 options 覆盖实例级配置
$out2 = $renderFlags->invoke($cli2, ['model' => 'haiku']);
check(strpos($out2, " --model 'haiku'") !== false && strpos($out2, "'opus'") === false,
      'options.model 覆盖实例级 model', $out2);

$out3 = $renderFlags->invoke($cli2, ['flags' => ['output_format' => 'json']]);
check(strpos($out3, " --output-format 'json'") !== false,
      'options.flags 支持下划线写法并自动归一', $out3);

// 移除与重置
$cli3 = (new ClaudeCode())->setModel('opus')->setFlag('verbose', true);
check(strpos($renderFlags->invoke($cli3, []), '--verbose') !== false, 'setFlag 生效');
$cli3->removeFlag('verbose');
check(strpos($renderFlags->invoke($cli3, []), '--verbose') === false, 'removeFlag 生效');
$cli3->setFlag('debug', true)->resetFlags();
check(strpos($renderFlags->invoke($cli3, []), '--debug') === false, 'resetFlags 清空自定义参数');

// ===============================================================
// 五、可执行文件探测（不依赖机器上是否真装了 claude）
// ===============================================================
echo "\n=== 五、可执行文件探测 ===\n\n";

$cli4 = new ClaudeCode();
$cli4->setBinary('/usr/local/bin/claude');
check($cli4->getBinary() === '/usr/local/bin/claude', 'setBinary 后不再触发探测');

// 缓存路径可控，且不会写到不可预期的位置
$cachePath = sys_get_temp_dir() . '/ai_cli_cache_' . getmypid() . '.txt';
$cli5 = new ClaudeCode();
$cli5->setBinaryCachePath($cachePath)->setBinaryCacheEnabled(false);
$resolve = new ReflectionMethod($cli5, 'resolveCachePath');
$resolve->setAccessible(true);
check($resolve->invoke($cli5) === $cachePath, 'setBinaryCachePath 生效', $resolve->invoke($cli5));

$write = new ReflectionMethod($cli5, 'writeBinaryCache');
$write->setAccessible(true);
$read = new ReflectionMethod($cli5, 'readBinaryCache');
$read->setAccessible(true);
$cli5->setBinaryCacheEnabled(true);
$write->invoke($cli5, '/fake/claude');
check(is_file($cachePath), '缓存文件被写入');
check($read->invoke($cli5) === '' || $read->invoke($cli5) === '/fake/claude',
      '缓存可读回（不存在的路径会被判为失效）', (string) $read->invoke($cli5));
$cli5->clearBinaryCache();
check(!is_file($cachePath), 'clearBinaryCache 删除缓存文件');
@unlink($cachePath);

echo "\n", str_repeat('=', 60), "\n";
if ($failures) {
    echo count($failures) . " 项未通过：\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    exit(1);
}
echo "全部通过\n";
exit(0);
