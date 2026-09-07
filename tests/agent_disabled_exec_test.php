<?php
/**
 * 禁用进程函数的生产环境测试
 *
 * 真实事故：生产机在 php.ini 的 disable_functions 里禁掉了 exec / proc_open，
 * Agent 一启动就
 *   Fatal error: Call to undefined function Ai\Agent\Workspace\exec()
 * ——被禁的函数是从函数表里消失，不是抛异常，@ 抑制符和 try/catch 都拦不住，
 * 唯一的办法是调用前先问 function_exists()。
 *
 * 这个测试真的拉起一个 `php -d disable_functions=...` 的子进程，在里面跑一遍
 * 依赖外部命令的那些类，断言它们「降级」而不是「崩溃」。纯静态检查做不到这点。
 *
 * 运行：php tests/agent_disabled_exec_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Helpers\Shell;

$passed = 0;
$failed = 0;
function test($name, $ok, $extra = '')
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "✓ {$name}\n"; }
    else { $failed++; echo "✗ {$name}" . ($extra !== '' ? "\n    {$extra}" : '') . "\n"; }
}

// ===== 一、Shell 的能力探测 =====
echo "=== 一、Shell 能力探测 ===\n";
test('hasFunction 认得存在的函数', Shell::hasFunction('strlen'));
test('hasFunction 认得不存在的函数', !Shell::hasFunction('php_ai_no_such_function'));
test('hasFunction 空名返回 false', !Shell::hasFunction(''));
test('hasFunction 大小写无关', Shell::hasFunction('STRLEN'));
test('disabledMessage 说清了原因', strpos(Shell::disabledMessage('exec'), 'disable_functions') !== false);

$hasProc = Shell::canProcOpen();
if ($hasProc) {
    $r = Shell::run('echo hello');
    test('run 拿得到 stdout 与退出码', $r['code'] === 0 && trim($r['out']) === 'hello', json_encode($r));
    $r2 = Shell::run('exit 3');
    test('run 如实返回非 0 退出码', $r2['code'] === 3);
    $r3 = Shell::run('pwd', sys_get_temp_dir());
    test('run 的 cwd 生效', $r3['code'] === 0 && trim($r3['out']) !== '');
    test('run 的 cwd 不存在时不执行', Shell::run('echo x', '/no/such/dir/php-ai')['code'] === -1);
} else {
    echo "  （本机已禁用 proc_open，跳过 run 的正向用例）\n";
}

// ===== 二、子进程里禁掉全部进程函数 =====
echo "\n=== 二、disable_functions 下不崩 ===\n";

$disabled = 'exec,shell_exec,proc_open,popen,passthru,system,proc_close,'
    . 'proc_get_status,proc_terminate,pcntl_fork';

$child = <<<'PHP'
<?php
require %s;

use Ai\Agent\Tools\BackgroundShells;
use Ai\Agent\Tools\BashTool;
use Ai\Agent\Tools\BrowserSession;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Mcp\McpStdioTransport;
use Ai\Agent\Verification\GitDiffVerifier;
use Ai\Agent\Verification\PhpSyntaxVerifier;
use Ai\Agent\Verification\UnitTestVerifier;
use Ai\Agent\Verification\VerificationManager;
use Ai\Agent\Workspace\WorkspaceManager;
use Ai\Agent\Workspace\WorkspaceSnapshot;
use Ai\Helpers\Shell;

$dir = %s;
$out = [];

$out['can_exec'] = Shell::canExec();
$out['can_proc'] = Shell::canProcOpen();
$out['can_run']  = Shell::canRunCommand();
$out['run']      = Shell::run('echo hi');
$out['capture']  = Shell::capture('echo hi');
$out['bin']      = Shell::hasBinary('git');

// 事故现场：Agent 每轮都要刷一次 git 上下文
$ws = new WorkspaceManager($dir);
$ws->refresh();
$out['ws_git']     = $ws->isGitRepo();
$out['ws_context'] = $ws->toContextString();

$snap = WorkspaceSnapshot::capture($dir);
$out['snap_git']     = $snap->isGitRepo();
$out['snap_branch']  = $snap->getBranch();
$out['snap_summary'] = $snap->toSummary();

// 验证：跑不了命令应当「跳过」，不是判失败——判失败会让 Agent 白白重试
$vm = new VerificationManager(['write_file' => 'php -l {file}']);
$vm->addVerifier(new PhpSyntaxVerifier());
$vm->addVerifier(new UnitTestVerifier(['command' => 'phpunit']));
$vm->addVerifier(new GitDiffVerifier(['workdir' => $dir]));
$results = $vm->verify('write_file', ['file_path' => $dir . '/autoload.php']);
$out['verify_count']  = count($results);
$out['verify_passed'] = true;
foreach ($results as $r) {
    if (!$r->isPassed()) { $out['verify_passed'] = false; }
}

// bash 工具：报错走 ToolResult，不是 Fatal
$ctx = new ToolContext(['workdir' => $dir]);
$bash = new BashTool();
$res = $bash->execute(['command' => 'echo hi'], $ctx);
$out['bash_success'] = $res->isSuccess();
$out['bash_error']   = $res->getContent();
$bg = $bash->execute(['command' => 'sleep 1', 'run_in_background' => true], $ctx);
$out['bash_bg_success'] = $bg->isSuccess();
$out['bg_start'] = BackgroundShells::start('echo hi');

// MCP stdio：抛 RuntimeException，不是 Fatal
try {
    (new McpStdioTransport('some-mcp-server'))->open();
    $out['mcp'] = 'no_exception';
} catch (\RuntimeException $e) {
    $out['mcp'] = 'runtime_exception';
}

$out['browser_available'] = BrowserSession::isAvailable();
$b = new BrowserSession();
$out['browser_launch'] = $b->launch();
$b->close();

echo json_encode($out, JSON_UNESCAPED_UNICODE);
PHP;

$childCode = sprintf($child, var_export(dirname(__DIR__) . '/autoload.php', true), var_export(dirname(__DIR__), true));
$childFile = sys_get_temp_dir() . '/php-ai-disabled-exec-' . getmypid() . '.php';
file_put_contents($childFile, $childCode);

$binary = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
$cmd = escapeshellarg($binary)
    . ' -d ' . escapeshellarg('disable_functions=' . $disabled)
    . ' ' . escapeshellarg($childFile) . ' 2>&1';

if (!$hasProc) {
    echo "  （本机没有 proc_open，跑不了子进程，跳过这一节）\n";
} else {
    $res = Shell::capture($cmd, ['timeout' => 60]);
    $raw = trim($res['out'] . $res['err']);
    @unlink($childFile);

    test('子进程没有 Call to undefined function', strpos($raw, 'undefined function') === false, $raw);
    test('子进程没有 Fatal error', strpos($raw, 'Fatal error') === false, $raw);

    $json = json_decode($raw, true);
    test('子进程正常跑完并输出结果', is_array($json), $raw);

    if (is_array($json)) {
        test('探测到 exec 被禁', $json['can_exec'] === false);
        test('探测到 proc_open 被禁', $json['can_proc'] === false);
        test('canRunCommand 为 false', $json['can_run'] === false);
        test('Shell::run 返回 code -1', $json['run']['code'] === -1 && $json['run']['out'] === '');
        test('Shell::capture 给出禁用说明', strpos($json['capture']['err'], 'disable_functions') !== false);
        test('hasBinary 一律 false（探测不了）', $json['bin'] === false);

        test('WorkspaceManager 退化成非 git 仓库', $json['ws_git'] === false);
        test('WorkspaceManager 仍给出 cwd 上下文', strpos($json['ws_context'], 'cwd:') !== false, $json['ws_context']);
        // 快照靠 .git 目录判仓库（不依赖命令），跑不了 git 时只是分支/提交取不到
        test('WorkspaceSnapshot 仍认得 git 仓库', $json['snap_git'] === true);
        test('WorkspaceSnapshot 的分支退化成空串', $json['snap_branch'] === '');
        test('WorkspaceSnapshot 仍给出摘要', is_string($json['snap_summary']) && $json['snap_summary'] !== '');

        test('验证器全部跑到', $json['verify_count'] >= 3, (string) $json['verify_count']);
        test('跑不了命令时验证记为跳过而非失败', $json['verify_passed'] === true);

        test('bash 工具返回失败而不是崩溃', $json['bash_success'] === false);
        test('bash 报错说明了原因', strpos($json['bash_error'], 'disable_functions') !== false, $json['bash_error']);
        test('bash 后台模式同样安全失败', $json['bash_bg_success'] === false);
        test('BackgroundShells::start 返回空串', $json['bg_start'] === '');

        test('MCP stdio 抛 RuntimeException', $json['mcp'] === 'runtime_exception');
        test('BrowserSession 报告不可用', $json['browser_available'] === false);
        test('BrowserSession::launch 返回 false', $json['browser_launch'] === false);
    }
}

echo "\n通过 {$passed}，失败 {$failed}\n";
exit($failed === 0 ? 0 : 1);
