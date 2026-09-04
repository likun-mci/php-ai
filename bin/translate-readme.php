#!/usr/bin/env php
<?php
/**
 * README 英文初稿生成器（Edge 免费翻译，见 dev.md v2.1 §1.5）
 *
 * 把一个中文 Markdown 文件按段落用 Edge 免费接口翻成英文**初稿**，输出到 stdout。
 * 机翻只作初稿：代码围栏（``` ... ```）原样保留不翻；平台名/模型名/端点 URL
 * 请人工或模型校对后再落地到 README-en.md（不要直接覆盖）。
 *
 * 用法：
 *   php bin/translate-readme.php README.md > README-en.draft.md
 *   php bin/translate-readme.php <file> [to] [from]     # 默认 to=en from=zh-Hans
 *
 * 退出码：0 成功；1 参数/读取错误；2 翻译部分失败（已尽量输出）。
 */

$autoloads = [
    __DIR__ . '/../autoload.php',
    __DIR__ . '/../../../autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];
$loaded = false;
foreach ($autoloads as $a) {
    if (is_file($a)) { require $a; $loaded = true; break; }
}
if (!$loaded) {
    fwrite(STDERR, "找不到 autoload.php\n");
    exit(1);
}

use Ai\Helpers\Translate;

$file = isset($argv[1]) ? $argv[1] : '';
$to   = isset($argv[2]) ? $argv[2] : 'en';
$from = isset($argv[3]) ? $argv[3] : 'zh-Hans';

if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "用法：php bin/translate-readme.php <file> [to=en] [from=zh-Hans]\n");
    exit(1);
}
$content = file_get_contents($file);
if ($content === false) {
    fwrite(STDERR, "读取失败：{$file}\n");
    exit(1);
}
$content = str_replace(["\r\n", "\r"], "\n", $content);

// 按代码围栏切块：围栏内原样保留，围栏外按段落翻译
$lines = explode("\n", $content);
$blocks = [];          // ['code'|'text', string]
$inFence = false;
$buf = [];
$flush = function ($type) use (&$blocks, &$buf) {
    if ($buf) { $blocks[] = [$type, implode("\n", $buf)]; $buf = []; }
};
foreach ($lines as $line) {
    $isFence = preg_match('/^\s*```/', $line) === 1;
    if ($isFence) {
        if (!$inFence) { $flush('text'); $inFence = true; $buf[] = $line; }
        else { $buf[] = $line; $flush('code'); $inFence = false; }
        continue;
    }
    $buf[] = $line;
}
$flush($inFence ? 'code' : 'text');

// 收集需要翻译的段落（text 块里按空行分段，跳过纯空白/纯符号）
$toTranslate = [];   // index => 原文
$plan = [];          // 每个 block 的翻译计划
foreach ($blocks as $bi => $block) {
    if ($block[0] === 'code') {
        $plan[$bi] = ['code', $block[1]];
        continue;
    }
    $paras = preg_split('/\n{2,}/', $block[1]);
    $paraPlan = [];
    foreach ($paras === false ? [] : $paras as $pi => $para) {
        if (trim($para) === '' || preg_match('/^[\s#>\-=|`*_]+$/', $para)) {
            $paraPlan[$pi] = ['keep', $para];   // 空/纯符号行原样
        } else {
            $idx = count($toTranslate);
            $toTranslate[$idx] = $para;
            $paraPlan[$pi] = ['trans', $idx];
        }
    }
    $plan[$bi] = ['text', $paraPlan];
}

fwrite(STDERR, '待翻译段落：' . count($toTranslate) . " 段，调用 Edge 翻译…\n");
$translated = $toTranslate ? Translate::to(array_values($toTranslate), $to, ['from' => $from]) : [];
$ok = is_array($translated) && count($translated) === count($toTranslate);
if (!$ok) {
    fwrite(STDERR, "⚠️ 翻译失败或部分失败，未翻译段落将原样输出\n");
}

// 重组输出
$out = [];
foreach ($blocks as $bi => $block) {
    $p = $plan[$bi];
    if ($p[0] === 'code') {
        $out[] = $p[1];
        continue;
    }
    $paras = [];
    foreach ($p[1] as $pi => $pp) {
        if ($pp[0] === 'keep') {
            $paras[] = $pp[1];
        } else {
            $idx = $pp[1];
            $paras[] = ($ok && isset($translated[$idx])) ? $translated[$idx] : $toTranslate[$idx];
        }
    }
    $out[] = implode("\n\n", $paras);
}

echo implode("\n", $out) . "\n";
fwrite(STDERR, "完成。请人工/模型校对（平台名、模型名、端点 URL 保持原样）后再落地。\n");
exit($ok ? 0 : 2);
