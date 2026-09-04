<?php
namespace Ai\Agent\Tools;

/**
 * 后台命令进程注册表——`bash(run_in_background)` 与 `bash_output` 共用
 *
 * 长时间任务（构建、测试、装依赖）不该把 Agent 主循环阻塞在一次工具调用里。
 * 后台启动后立刻返回 id，模型继续干别的，之后用 `bash_output` 取增量输出。
 *
 * 用静态注册表是因为两个工具是各自独立的对象，但必须看到同一批进程；
 * 生命周期跟随当前 PHP 进程（Agent 一次运行），进程退出即释放。
 *
 * 读取策略：每次 poll() 用非阻塞 fread 把管道数据吸进缓冲区，避免管道写满导致
 * 子进程卡死；read() 默认**消费**缓冲区（只返回上次之后的新输出），与
 * 「轮询长任务进度」的用法契合。
 */
class BackgroundShells
{
    /**
     * @var array<string, array<string, mixed>> id => 进程记录
     */
    protected static $shells = [];

    /** @var int 自增序号，用于生成可读 id */
    protected static $seq = 0;

    /**
     * 后台启动一条命令，返回句柄 id；启动失败返回 ''
     *
     * @param string $command
     * @param string|null $cwd
     * @param int $maxBytes 每个进程缓冲区上限（超出丢弃最旧的）
     * @return string
     */
    public static function start($command, $cwd = null, $maxBytes = 100000)
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = @proc_open((string) $command, $descriptors, $pipes, $cwd, null);
        if (!is_resource($proc)) {
            return '';
        }
        @fclose($pipes[0]);
        @stream_set_blocking($pipes[1], false);
        @stream_set_blocking($pipes[2], false);

        self::$seq++;
        $id = 'bg_' . self::$seq . '_' . substr(bin2hex(pack('N', mt_rand())), 0, 6);
        self::$shells[$id] = [
            'proc'     => $proc,
            'pipes'    => $pipes,
            'command'  => (string) $command,
            'out'      => '',
            'err'      => '',
            'started'  => microtime(true),
            'exit'     => null,
            'maxBytes' => max(1024, (int) $maxBytes),
        ];
        return $id;
    }

    /**
     * 是否存在该 id
     *
     * @param string $id
     * @return bool
     */
    public static function has($id)
    {
        return isset(self::$shells[(string) $id]);
    }

    /**
     * 把管道里已有数据吸进缓冲区，并更新退出状态（非阻塞）
     *
     * @param string $id
     * @return void
     */
    public static function poll($id)
    {
        $id = (string) $id;
        if (!isset(self::$shells[$id])) {
            return;
        }
        $s = &self::$shells[$id];
        if (!is_resource($s['proc'])) {
            return;
        }
        for ($i = 0; $i < 64; $i++) {
            $got = false;
            foreach ([1, 2] as $fd) {
                if (!isset($s['pipes'][$fd]) || !is_resource($s['pipes'][$fd])) {
                    continue;
                }
                $data = @fread($s['pipes'][$fd], 8192);
                if (is_string($data) && $data !== '') {
                    $key = $fd === 1 ? 'out' : 'err';
                    $s[$key] .= $data;
                    $cap = (int) $s['maxBytes'];
                    if (strlen($s[$key]) > $cap) {
                        // 保留尾部（长任务里新输出更有价值）
                        $s[$key] = substr($s[$key], -$cap);
                    }
                    $got = true;
                }
            }
            if (!$got) {
                break;
            }
        }
        $status = @proc_get_status($s['proc']);
        if (is_array($status) && !$status['running'] && $s['exit'] === null) {
            $s['exit'] = (int) $status['exitcode'];
        }
    }

    /**
     * 读取增量输出与状态；id 不存在返回 null
     *
     * @param string $id
     * @param bool $consume 是否消费缓冲区（true=只返回新输出）
     * @return array{command: string, running: bool, exit_code: int|null, output: string, stderr: string, duration: float}|null
     */
    public static function read($id, $consume = true)
    {
        $id = (string) $id;
        if (!isset(self::$shells[$id])) {
            return null;
        }
        self::poll($id);
        $s = &self::$shells[$id];
        $out = $s['out'];
        $err = $s['err'];
        if ($consume) {
            $s['out'] = '';
            $s['err'] = '';
        }
        return [
            'command'   => $s['command'],
            'running'   => $s['exit'] === null,
            'exit_code' => $s['exit'],
            'output'    => $out,
            'stderr'    => $err,
            'duration'  => round(microtime(true) - $s['started'], 2),
        ];
    }

    /**
     * 结束一个后台进程并释放；返回是否处理了该 id
     *
     * @param string $id
     * @return bool
     */
    public static function kill($id)
    {
        $id = (string) $id;
        if (!isset(self::$shells[$id])) {
            return false;
        }
        $s = self::$shells[$id];
        if (is_resource($s['proc'])) {
            @proc_terminate($s['proc'], 9);
        }
        foreach ([1, 2] as $fd) {
            if (isset($s['pipes'][$fd]) && is_resource($s['pipes'][$fd])) {
                @fclose($s['pipes'][$fd]);
            }
        }
        if (is_resource($s['proc'])) {
            @proc_close($s['proc']);
        }
        unset(self::$shells[$id]);
        return true;
    }

    /**
     * 列出全部后台进程摘要
     *
     * @return array<int, array{id: string, command: string, running: bool, exit_code: int|null, duration: float}>
     */
    public static function all()
    {
        $out = [];
        foreach (array_keys(self::$shells) as $id) {
            self::poll($id);
            $s = self::$shells[$id];
            $out[] = [
                'id'        => $id,
                'command'   => $s['command'],
                'running'   => $s['exit'] === null,
                'exit_code' => $s['exit'],
                'duration'  => round(microtime(true) - $s['started'], 2),
            ];
        }
        return $out;
    }

    /**
     * 清空全部（测试用；会杀掉仍在跑的进程）
     *
     * @return void
     */
    public static function reset()
    {
        foreach (array_keys(self::$shells) as $id) {
            self::kill($id);
        }
        self::$shells = [];
    }
}
