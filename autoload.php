<?php
/**
 * Ai 库 PSR-4 自动加载
 *
 * 命名空间 Ai\ 映射到当前目录（__DIR__）
 *
 * 使用方式（在项目入口或 CI hooks 中引入一次即可）：
 *     require_once APPPATH . 'libraries/Ai/autoload.php';
 */

spl_autoload_register(function (string $class): void {
    // PSR-4 前缀映射：命名空间前缀 => 源码根目录
    static $map = null;
    if ($map === null) {
        $map = [
            'Ai\\'                       => __DIR__,
        ];
    }

    foreach ($map as $prefix => $base) {
        $prefixLen = strlen($prefix);
        if (strncmp($class, $prefix, $prefixLen) !== 0) {
            continue;
        }
        $relative = substr($class, $prefixLen);
        $file = $base . DIRECTORY_SEPARATOR
            . str_replace('\\', DIRECTORY_SEPARATOR, $relative)
            . '.php';
        if (is_file($file)) {
            require_once $file;
        }
        return;
    }
});
