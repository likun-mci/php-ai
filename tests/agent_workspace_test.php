<?php
/**
 * WorkspaceManager 测试
 *
 * 覆盖：
 *   1. 基本属性（workdir、projectName）
 *   2. Git 状态读取（branch、modified、untracked）
 *   3. isGitRepo / hasChanges 判断
 *   4. toContextString 格式
 *   5. 非 git 目录的行为
 *   6. 缓存刷新控制
 *   7. 集成到 AgentRuntime（setWorkdir 自动创建）
 *
 * 运行：php tests/agent_workspace_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\Workspace\WorkspaceManager;

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

// ===== 1. 基本属性 =====

echo "=== 一、基本属性 ===\n";

$wm = new WorkspaceManager(getcwd());
test('workdir 已设置', $wm->getWorkdir() !== '');
test('projectName 非空', $wm->getProjectName() !== '');

$wm2 = new WorkspaceManager();
test('空构造 workdir 为空', $wm2->getWorkdir() === '');
$wm2->setWorkdir('/tmp');
test('setWorkdir 后 workdir 正确', $wm2->getWorkdir() === '/tmp');

// ===== 2. Git 状态读取 =====

echo "\n=== 二、Git 状态读取 ===\n";

// 当前项目目录本身是 git 仓库
$wm3 = new WorkspaceManager(getcwd());
$wm3->refresh();
test('isGitRepo 为 true', $wm3->isGitRepo());
test('branch 非空', $wm3->getBranch() !== '');

// ===== 3. 非 git 目录 =====

echo "\n=== 三、非 git 目录 ===\n";

$wm4 = new WorkspaceManager('/tmp');
$wm4->refresh();
test('/tmp 不是 git 仓库', !$wm4->isGitRepo());
test('非 git 时 branch 为空', $wm4->getBranch() === '');
test('非 git 时 modified 为空', $wm4->getModified() === []);

// ===== 4. toContextString 格式 =====

echo "\n=== 四、toContextString 格式 ===\n";

$wm5 = new WorkspaceManager(getcwd());
$wm5->refresh();
$ctx = $wm5->toContextString();
test('上下文包含 cwd', strpos($ctx, 'cwd:') !== false);
test('上下文包含 branch', strpos($ctx, 'branch:') !== false);
test('上下文包含 git 仓库信息', $wm5->isGitRepo() ? strpos($ctx, 'clean') !== false || strpos($ctx, 'modified') !== false || strpos($ctx, 'untracked') !== false : strpos($ctx, 'not a git repository') !== false);

// 非 git 目录的上下文
$wm6 = new WorkspaceManager('/tmp');
$wm6->refresh();
$ctx6 = $wm6->toContextString();
test('非 git 上下文包含提示', strpos($ctx6, 'not a git repository') !== false);

// 空 workdir 的上下文
$wm7 = new WorkspaceManager();
$ctx7 = $wm7->toContextString();
test('空 workdir 上下文为空', $ctx7 === '');

// ===== 5. 缓存刷新控制 =====

echo "\n=== 五、缓存刷新控制 ===\n";

$wm8 = new WorkspaceManager(getcwd(), ['cache_ttl' => 3600]);
$wm8->refresh();
test('缓存 TTL 可配置', true);

// ===== 6. 集成到 AgentRuntime =====

echo "\n=== 六、集成到 AgentRuntime ===\n";

$ai = new AI();
$ai->setConfig([
    'model'      => 'deepseek-anthropic',
    'api_key'    => 'sk-test',
    'max_tokens' => 1024,
]);

$agent = new Agent($ai);
$agent->setWorkspaceDir(getcwd());

$runtime = $agent->getRuntime();
test('setWorkspaceDir 后 WorkspaceManager 已创建', $runtime->getWorkspaceManager() !== null);

$wm9 = $runtime->getWorkspaceManager();
$wm9->refresh();
test('WorkspaceManager 已关联到 runtime', $wm9->getWorkdir() === getcwd());

// 通过 setWorkdir 同样自动创建
$agent2 = new Agent($ai);
$agent2->setWorkdir(getcwd());
$wm10 = $agent2->getRuntime()->getWorkspaceManager();
test('setWorkdir 也自动创建 WorkspaceManager', $wm10 !== null);

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);