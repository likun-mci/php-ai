#!/usr/bin/env php
<?php
/**
 * 旧 <sid>.json 会话 → JSONL 迁移脚本（用户主动运行，见 dev.md 第七节）
 *
 * 本库默认**不自动迁移**旧会话：JsonlSessionStore 能原样读取旧 <sid>.json，
 * 下一次保存自然产生新格式，原文件保留。只有当你想一次性转换存量会话时才用它。
 *
 * 用法：
 *   php bin/agent-migrate-sessions.php <sessions-dir> [--delete] [--dry-run]
 *
 *   <sessions-dir>  存放 <sid>.json 的目录
 *   --delete        迁移成功后删除原 .json（默认保留）
 *   --dry-run       只报告将要做什么，不写盘
 *
 * 退出码：0 全部成功；1 参数错误；2 有文件迁移失败。
 */

// 定位 autoload：支持「装进 vendor」与「仓库内直接跑」两种布局
$autoloads = [
    __DIR__ . '/../autoload.php',            // 仓库内
    __DIR__ . '/../../../autoload.php',      // 装进别人 vendor/likun-mci/php-ai/bin
    __DIR__ . '/../vendor/autoload.php',     // composer 自动加载
];
$loaded = false;
foreach ($autoloads as $a) {
    if (is_file($a)) { require $a; $loaded = true; break; }
}
if (!$loaded) {
    fwrite(STDERR, "找不到 autoload.php\n");
    exit(1);
}

use Ai\Agent\Session\JsonlSessionStore;
use Ai\Agent\Session\AgentSession;

$args = array_slice($argv, 1);
$dir = '';
$delete = false;
$dryRun = false;
foreach ($args as $arg) {
    if ($arg === '--delete') { $delete = true; }
    elseif ($arg === '--dry-run') { $dryRun = true; }
    elseif (strpos($arg, '--') === 0) {
        fwrite(STDERR, "未知选项：{$arg}\n");
        exit(1);
    } else {
        $dir = $arg;
    }
}

if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "用法：php bin/agent-migrate-sessions.php <sessions-dir> [--delete] [--dry-run]\n");
    exit(1);
}
$dir = rtrim(str_replace('\\', '/', $dir), '/');

$store = new JsonlSessionStore($dir);
$files = glob($dir . '/*.json');
if ($files === false) {
    $files = [];
}
// 排除已经是 state.json 的
$files = array_filter($files, function ($f) {
    return substr($f, -11) !== '.state.json';
});

$ok = 0;
$skip = 0;
$fail = 0;
echo "扫描目录：{$dir}\n";
echo '找到 ' . count($files) . " 个 .json 文件\n";
if ($dryRun) { echo "(dry-run：不写盘)\n"; }
echo str_repeat('-', 50) . "\n";

foreach ($files as $file) {
    $name = basename($file, '.json');
    $raw = @file_get_contents($file);
    if ($raw === false) {
        echo "✗ 读取失败：{$file}\n";
        $fail++;
        continue;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        echo "✗ 非法 JSON，跳过：{$file}\n";
        $fail++;
        continue;
    }

    // 会话 id：优先用文件里记录的 id，回退到文件名
    $id = isset($data['id']) && is_string($data['id']) && $data['id'] !== '' ? $data['id'] : $name;

    // 已存在同名 jsonl 则跳过（避免覆盖新格式）——按 safeName 命名比对
    if (is_file($dir . '/' . \Ai\Helpers\Path::safeName($id) . '.jsonl')) {
        echo "· 已有 jsonl，跳过：{$name}\n";
        $skip++;
        continue;
    }

    if ($dryRun) {
        echo "→ 将迁移：{$name}（" . (isset($data['messages']) && is_array($data['messages']) ? count($data['messages']) : 0) . " 条消息）\n";
        $ok++;
        continue;
    }

    $session = new AgentSession($id, $data);
    $store->save($session);

    // 校验迁移结果
    $reloaded = $store->load($id);
    if ($reloaded === null) {
        echo "✗ 迁移后无法回读：{$name}\n";
        $fail++;
        continue;
    }
    echo "✓ 已迁移：{$name}\n";
    $ok++;

    if ($delete) {
        if (@unlink($file)) {
            echo "  已删除原文件\n";
        } else {
            echo "  ⚠️ 原文件删除失败：{$file}\n";
        }
    }
}

echo str_repeat('-', 50) . "\n";
echo "完成：成功 {$ok}，跳过 {$skip}，失败 {$fail}\n";
if (!$delete && !$dryRun && $ok > 0) {
    echo "原 .json 文件已保留。确认无误后可加 --delete 再跑一次清理。\n";
}
exit($fail > 0 ? 2 : 0);
