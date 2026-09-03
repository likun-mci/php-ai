<?php
namespace Ai\Helpers;

/**
 * 路径工具——统一的路径归一化、HOME 探测、slug/safeName、原子写
 *
 * 本库此前有两套路径归一化：
 *   - src/Agent/Tools/PathSafety.php    沙箱越界校验（depthGuard < 64）
 *   - src/Editor/EditExecutor.php:46-57  同一套逻辑的拷贝
 * 它们管的是沙箱边界，属安全相关代码，本次不动（见 dev.md 第九节）。
 * 新代码一律用本类，不再制造第三份路径归一化实现。
 *
 * 设计约束（见 dev.md）：
 *   - PHP 7.1 兼容：不用 str_starts_with/PHP_OS_FAMILY/typed properties 等
 *   - 环境变量只经 getenv() 读取，不读 $_SERVER
 *   - 由标识符推导路径的散列一律 SHA-256（见 dev.md 2.7）
 *   - 归一化不调用 realpath()（保持逻辑项目身份稳定，见 dev.md 2.5）
 */
class Path
{
    /**
     * 是否 Windows 平台
     *
     * @return bool
     */
    public static function isWindows()
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * 是否绝对路径（同时认 Unix `/` 与 Windows 盘符 `C:\`、UNC `\\`）
     *
     * @param string $path
     * @return bool
     */
    public static function isAbsolute($path)
    {
        $path = (string) $path;
        if ($path === '') {
            return false;
        }
        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }
        // Windows 盘符：C:\ 或 C:/
        if (strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':'
            && ($path[2] === '/' || $path[2] === '\\')) {
            return true;
        }
        return false;
    }

    /**
     * 词法归一化：反斜杠转正斜杠、合并 `.`/`..`、去重复斜杠与尾斜杠
     *
     * 不触碰文件系统（不调用 realpath），因此对不存在的路径也安全，且逻辑
     * 项目身份不随软链接 release 目录漂移。保留前导 `/` 与 Windows 盘符前缀。
     *
     * @param string $path
     * @return string
     */
    public static function normalize($path)
    {
        $path = str_replace('\\', '/', (string) $path);

        $prefix = '';
        // Windows 盘符前缀
        if (strlen($path) >= 2 && ctype_alpha($path[0]) && $path[1] === ':') {
            $prefix = substr($path, 0, 2);
            $path = substr($path, 2);
        }
        if (isset($path[0]) && $path[0] === '/') {
            $prefix .= '/';
            $path = ltrim($path, '/');
        }

        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if ($parts && end($parts) !== '..') {
                    array_pop($parts);
                } elseif ($prefix === '') {
                    // 相对路径可以保留前导 ..
                    $parts[] = '..';
                }
                continue;
            }
            $parts[] = $seg;
        }

        $result = $prefix . implode('/', $parts);
        return $result === '' ? '.' : $result;
    }

    /**
     * 探测用户 HOME 目录，全部候选都不满足时返回 ''
     *
     * 候选顺序（见 dev.md 2.4）：HOME → USERPROFILE → HOMEDRIVE+HOMEPATH →
     * posix_getpwuid(getmyuid())。每个候选须：绝对路径、是目录、可写、不是 `/`。
     *
     * @return string
     */
    public static function home()
    {
        foreach (self::homeCandidates() as $cand) {
            if (self::isUsableHome($cand)) {
                return self::normalize($cand);
            }
        }
        return '';
    }

    /**
     * HOME 候选列表（未经校验）
     *
     * @return string[]
     */
    protected static function homeCandidates()
    {
        $out = [];

        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            $out[] = $home;
        }

        $userProfile = getenv('USERPROFILE');
        if (is_string($userProfile) && $userProfile !== '') {
            $out[] = $userProfile;
        }

        $drive = getenv('HOMEDRIVE');
        $hpath = getenv('HOMEPATH');
        if (is_string($drive) && $drive !== '' && is_string($hpath) && $hpath !== '') {
            $out[] = $drive . $hpath;
        }

        if (function_exists('posix_getpwuid') && function_exists('getmyuid')) {
            $uid = getmyuid();
            if (is_int($uid)) {
                $info = posix_getpwuid($uid);
                if (is_array($info)) {
                    $dir = (string) $info['dir'];
                    if ($dir !== '') {
                        $out[] = $dir;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * 候选 HOME 是否可用：绝对、是目录、可写、不是根 `/`
     *
     * @param string $dir
     * @return bool
     */
    protected static function isUsableHome($dir)
    {
        $dir = (string) $dir;
        if ($dir === '' || !self::isAbsolute($dir)) {
            return false;
        }
        $norm = self::normalize($dir);
        if ($norm === '/' || $norm === '') {
            return false;
        }
        return is_dir($dir) && is_writable($dir);
    }

    /**
     * 从 $start 逐级向上，返回所有祖先目录（含自身），最多 $maxDepth 层
     *
     * @param string $start
     * @param int $maxDepth
     * @return string[]
     */
    public static function walkUp($start, $maxDepth = 64)
    {
        $dir = self::normalize($start);
        $out = [];
        $depth = 0;
        while (true) {
            $out[] = $dir;
            if ($depth++ >= $maxDepth) {
                break;
            }
            $parent = self::normalize($dir . '/..');
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return $out;
    }

    /**
     * 从 $start 向上寻找含有 $needle（相对名）的目录，找到即返回该目录，否则 ''
     *
     * @param string $start 起点目录
     * @param string $needle 目标子项名（如 '.agent'）
     * @param int $maxDepth 最多向上层数
     * @param string $stopAt 到达此目录（含）后停止，不再向上（如 HOME）
     * @return string
     */
    public static function findUp($start, $needle, $maxDepth = 10, $stopAt = '')
    {
        $needle = (string) $needle;
        $stopNorm = $stopAt === '' ? '' : self::normalize($stopAt);
        $dir = self::normalize($start);
        $depth = 0;
        while (true) {
            if (is_dir($dir . '/' . $needle) || is_file($dir . '/' . $needle)) {
                return $dir;
            }
            if ($stopNorm !== '' && $dir === $stopNorm) {
                break;
            }
            if ($depth++ >= $maxDepth) {
                break;
            }
            $parent = self::normalize($dir . '/..');
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return '';
    }

    /**
     * 把绝对路径转成可作目录名的 slug，附 SHA-256 短散列后缀防碰撞
     *
     * 例：/root/workspace/php-ai → -root-workspace-php-ai-29c1cb80cd75
     * 不调用 realpath（见 dev.md 2.5）。散列用 SHA-256（见 dev.md 2.7）。
     *
     * @param string $path
     * @param int $hashLen 后缀十六进制长度
     * @return string
     */
    public static function slug($path, $hashLen = 12)
    {
        $norm = self::normalize($path);
        $hash = substr(hash('sha256', $norm), 0, $hashLen);
        // 盘符里的冒号也要清掉
        $body = preg_replace('#[/:\\\\]+#', '-', $norm);
        if (!is_string($body)) {
            $body = '';
        }
        $body = preg_replace('/[^A-Za-z0-9\-_.]/', '-', $body);
        if (!is_string($body)) {
            $body = '';
        }
        $body = trim($body, '-');
        return ($body === '' ? 'root' : $body) . '-' . $hash;
    }

    /**
     * 把任意标识符清洗成安全文件名，附原 id 的 SHA-256 短散列后缀
     *
     * 清洗必须无损：仅清洗会把 a/b、a.b、a b 落到同一名字，碰撞即串号，
     * `..` 漏过即目录穿越。加原 id 散列后缀后，两个不同 id 须「清洗后相同
     * 且散列前缀相同」才碰撞（见 dev.md 4.3）。散列非安全边界。
     *
     * @param string $id
     * @param int $hashLen 后缀十六进制长度
     * @return string
     */
    public static function safeName($id, $hashLen = 12)
    {
        $id = (string) $id;
        $clean = preg_replace('/[^A-Za-z0-9\-_]/', '_', $id);
        if (!is_string($clean)) {
            $clean = '';
        }
        // 避免全清洗后为空
        if ($clean === '' || trim($clean, '_') === '') {
            $clean = 'id';
        }
        $hash = substr(hash('sha256', $id), 0, $hashLen);
        return $clean . '-' . $hash;
    }

    /**
     * 目录是否存在且可写；不存在则检查其最近的已存在父级是否可写
     *
     * 不创建任何目录（只读零副作用，见 dev.md 第十一节）。
     *
     * @param string $dir
     * @return bool
     */
    public static function isWritableDir($dir)
    {
        $dir = self::normalize($dir);
        if ($dir === '') {
            return false;
        }
        $depth = 0;
        while ($dir !== '' && !is_dir($dir) && $depth++ < 64) {
            $parent = self::normalize($dir . '/..');
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return is_dir($dir) && is_writable($dir);
    }

    /**
     * 确保目录存在（递归创建），返回是否成功
     *
     * 仅在真正写入路径上调用（见 dev.md 第十一节：读操作不得创建目录）。
     *
     * @param string $dir
     * @param int $mode 权限位（默认 0700，见 dev.md 第十八节）
     * @return bool
     */
    public static function ensureDir($dir, $mode = 0700)
    {
        $dir = (string) $dir;
        if ($dir === '') {
            return false;
        }
        if (is_dir($dir)) {
            return true;
        }
        if (!@mkdir($dir, $mode, true) && !is_dir($dir)) {
            // 并发下可能已被别的进程建好，那不算失败
            $err = error_get_last();
            \Ai\Helpers\Log::error('目录创建失败', [
                'dir' => $dir,
                'reason' => isset($err['message']) ? $err['message'] : '未知原因',
            ]);
            return false;
        }
        return true;
    }

    /**
     * 原子写：先写同目录临时文件，rename 覆盖目标
     *
     * rename 在同一文件系统内原子，读者不会看到半个 JSON（见 dev.md 第十九节）。
     * 尽量沿用原文件权限，避免 rename 后权限退回 umask 默认值。
     *
     * @param string $file 目标文件
     * @param string $contents 内容
     * @param int $dirMode 需要创建父目录时的权限位
     * @return bool
     */
    public static function atomicWrite($file, $contents, $dirMode = 0700)
    {
        $file = (string) $file;
        $contents = (string) $contents;
        $dir = dirname($file);
        if (!is_dir($dir) && !self::ensureDir($dir, $dirMode)) {
            return false;
        }

        $tmp = $file . '.tmp.' . getmypid() . '.' . mt_rand(1000, 9999);
        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            $err = error_get_last();
            \Ai\Helpers\Log::error('原子写临时文件失败', [
                'tmp' => $tmp,
                'reason' => isset($err['message']) ? $err['message'] : '未知原因',
            ]);
            return false;
        }
        // 沿用原文件权限
        if (is_file($file)) {
            $perms = @fileperms($file);
            if ($perms !== false) {
                @chmod($tmp, $perms & 0777);
            }
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            $err = error_get_last();
            \Ai\Helpers\Log::error('原子写 rename 失败', [
                'file' => $file,
                'reason' => isset($err['message']) ? $err['message'] : '未知原因',
            ]);
            return false;
        }
        return true;
    }
}
