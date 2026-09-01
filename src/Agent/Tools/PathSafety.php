<?php
namespace Ai\Agent\Tools;

/**
 * 路径沙箱——内置工具共用
 *
 * 所有文件类工具（Read/Write/Edit/Glob）都只允许访问 workdir 以内的路径，
 * 这里统一做路径解析与越界防护，避免每个工具各自实现一份（容易漏）。
 *
 * 设计要点：
 *  - 相对路径基于 workdir 解析；绝对路径也必须在 workdir 内
 *  - 用 realpath 校验，防 symlink 逃逸 / `..` 穿越
 *  - 目录可能不存在（写入场景），用已存在的父级做边界校验
 */
class PathSafety
{
    /** @var string */
    protected $rootDir;

    /**
     * @param string $rootDir 沙箱根目录绝对路径
     */
    public function __construct($rootDir)
    {
        $this->rootDir = rtrim(str_replace('\\', '/', (string) $rootDir), '/') . '/';
    }

    /**
     * 沙箱根目录
     * @return string
     */
    public function rootDir()
    {
        return $this->rootDir;
    }

    /**
     * 解析路径为 workdir 内的绝对路径
     *
     * @param string $path 相对 workdir 或绝对路径
     * @return string 规范化的绝对路径
     * @throws \InvalidArgumentException 非法/越界路径
     */
    public function resolve($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        if ($path === '' || strpos($path, "\0") !== false) {
            throw new \InvalidArgumentException('非法的文件路径');
        }

        // 绝对路径必须落在沙箱内，否则拒绝
        $abs = $path;
        if (strpos($path, '/') !== 0) {
            // 相对路径：基于 workdir
            $abs = $this->rootDir . ltrim($path, '/');
        }
        $abs = $this->normalize($abs);

        // 目录可能尚不存在（写入场景），用已存在的父级做边界校验
        $rootReal = realpath($this->rootDir);
        if ($rootReal === false) {
            throw new \InvalidArgumentException('沙箱根目录不存在');
        }
        $rootReal = str_replace('\\', '/', $rootReal) . '/';

        $checkDir = dirname($abs);
        $depthGuard = 0;
        while ($checkDir && !is_dir($checkDir) && $depthGuard++ < 64) {
            $checkDir = dirname($checkDir);
        }
        $dirReal = realpath($checkDir);
        if ($dirReal === false) {
            throw new \InvalidArgumentException('目标路径无效');
        }
        $dirReal = str_replace('\\', '/', $dirReal) . '/';
        if (strpos($dirReal, $rootReal) !== 0) {
            throw new \InvalidArgumentException('目标路径超出沙箱目录范围');
        }
        return $abs;
    }

    /**
     * 规范化路径：合并 `.` / `..`，去除重复斜杠
     *
     * @param string $path
     * @return string
     */
    public function normalize($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        $prefix = '';
        if (strpos($path, '/') === 0) {
            $prefix = '/';
            $path = ltrim($path, '/');
        }
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if ($parts) {
                    array_pop($parts);
                }
                continue;
            }
            $parts[] = $seg;
        }
        return $prefix . implode('/', $parts);
    }
}