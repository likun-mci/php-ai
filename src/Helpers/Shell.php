<?php
namespace Ai\Helpers;

/**
 * 系统命令探测与安全执行——供工具做「渐进增强」用
 *
 * 设计目的：探测到外部二进制（ripgrep `rg`、`git`）就用它换取性能/精度，
 * 探测不到就回退纯 PHP 实现。这些二进制**只是可选增强**，绝不进
 * composer.json 依赖，也绝不因缺失而报错（见 dev.md 渐进增强原则）。
 *
 * PHP 7.1：proc_open 用字符串命令（数组形式是 7.4+），因此调用方须自行
 * escapeshellarg 组装；本类不拼接不可信输入。
 */
class Shell
{
    /** @var array<string, bool> 二进制存在性缓存 */
    protected static $binCache = [];

    /**
     * 探测某个二进制是否可用（结果缓存）
     *
     * @param string $name 二进制名，如 'rg' / 'git'
     * @return bool
     */
    public static function hasBinary($name)
    {
        $name = (string) $name;
        if ($name === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            return false;
        }
        if (isset(self::$binCache[$name])) {
            return self::$binCache[$name];
        }
        $probe = Path::isWindows()
            ? 'where ' . escapeshellarg($name)
            : 'command -v ' . escapeshellarg($name);
        $res = self::capture($probe, ['timeout' => 5]);
        $ok = $res['code'] === 0 && trim($res['out']) !== '';
        return self::$binCache[$name] = $ok;
    }

    /**
     * 测试期重置探测缓存
     *
     * @return void
     */
    public static function resetCache()
    {
        self::$binCache = [];
    }

    /**
     * 安全执行一条命令，捕获 stdout/stderr/退出码，带超时（非阻塞轮询）
     *
     * 命令字符串由调用方用 escapeshellarg 组装；本类不做拼接。
     *
     * @param string $command 完整命令行（调用方已转义）
     * @param array<string, mixed> $opts cwd / timeout（秒）/ stdin / maxBytes
     * @return array{code: int, out: string, err: string}
     */
    public static function capture($command, array $opts = [])
    {
        $command = (string) $command;
        $cwd     = isset($opts['cwd']) && (string) $opts['cwd'] !== '' ? (string) $opts['cwd'] : null;
        $timeout = isset($opts['timeout']) ? max(1, (int) $opts['timeout']) : 30;
        $stdin   = isset($opts['stdin']) ? (string) $opts['stdin'] : '';
        $maxBytes = isset($opts['maxBytes']) ? max(1024, (int) $opts['maxBytes']) : 5000000;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes, $cwd, null);
        if (!is_resource($process)) {
            return ['code' => -1, 'out' => '', 'err' => 'proc_open 失败'];
        }

        if ($stdin !== '') {
            @fwrite($pipes[0], $stdin);
        }
        @fclose($pipes[0]);

        @stream_set_blocking($pipes[1], false);
        @stream_set_blocking($pipes[2], false);

        $out = '';
        $err = '';
        $start = microtime(true);
        $done = false;
        $code = -1;

        while (!$done) {
            if ((microtime(true) - $start) > $timeout) {
                @proc_terminate($process, 9);
                $err .= "\n[command timed out after {$timeout}s]";
                break;
            }
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $sel = @stream_select($read, $write, $except, 0, 200000);
            if ($sel === false) {
                break;
            }
            $hasData = false;
            foreach ($read as $r) {
                $data = @fread($r, 8192);
                if ($data !== false && $data !== '') {
                    $hasData = true;
                    if ($r === $pipes[1]) {
                        if (strlen($out) < $maxBytes) {
                            $out .= $data;
                        }
                    } else {
                        $err .= $data;
                    }
                }
            }
            if (!$hasData) {
                $status = @proc_get_status($process);
                if ($status !== false && !$status['running']) {
                    $done = true;
                }
            }
        }

        $rest = @stream_get_contents($pipes[1]);
        if (is_string($rest) && $rest !== '' && strlen($out) < $maxBytes) {
            $out .= $rest;
        }
        $restErr = @stream_get_contents($pipes[2]);
        if (is_string($restErr) && $restErr !== '') {
            $err .= $restErr;
        }
        @fclose($pipes[1]);
        @fclose($pipes[2]);

        $status = @proc_get_status($process);
        if (is_array($status) && $status['exitcode'] >= 0) {
            $code = (int) $status['exitcode'];
        }
        @proc_close($process);

        if (strlen($out) > $maxBytes) {
            $out = substr($out, 0, $maxBytes);
        }
        return ['code' => $code, 'out' => $out, 'err' => $err];
    }
}
