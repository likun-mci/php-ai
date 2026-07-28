<?php
/**
 * Ai 库 PSR-4 自动加载
 *
 * 命名空间 Ai\ 映射到当前目录下的 src/
 *
 * 使用方式（在项目入口引入一次即可）：
 *     require_once __DIR__ . '/autoload.php';
 */

spl_autoload_register(function (string $class): void {
    // PSR-4 前缀映射：命名空间前缀 => 源码根目录
    static $map = null;
    if ($map === null) {
        $map = [
            'Ai\\'                       => __DIR__ . DIRECTORY_SEPARATOR . 'src',
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
