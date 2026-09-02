<?php
namespace Ai\Agent\Orchestrator;

/**
 * BackgroundDispatcher——后台任务派发
 *
 * PHP 没有内置事件循环，「后台执行」在不同部署环境里能做到的程度差别很大。
 * 这个类把三档路径收在一处，并且**明确告诉调用方走的是哪一档**——
 * 悄悄退化成同步执行、却让调用方以为是异步的，比直接说做不到危险得多。
 *
 * | 档位 | 条件 | 行为 |
 * |---|---|---|
 * | `runner` | 注入了 runner（Swoole / Workerman 协程、队列 Worker） | 真异步，立即返回 |
 * | `fork` | `pcntl_fork` 可用 | fork 子进程执行，父进程立即返回 |
 * | `sync` | 都不可用 | 同步跑完再返回，但仍返回 task_id，状态机一致 |
 *
 * ```php
 * $dispatcher = new BackgroundDispatcher();
 * $dispatcher->setRunner(function (array $payload) { … });   // 可选
 *
 * $handle = $dispatcher->dispatch('task_1', function () use ($agent) {
 *     return $agent->run([['role' => 'user', 'content' => '扫描整个项目']]);
 * });
 * $handle['mode'];     // 'runner' | 'fork' | 'sync'
 * $handle['status'];   // 'running' | 'completed' | 'failed'
 * ```
 *
 * fork 档的注意点：子进程里改的内存父进程看不到，所以结果必须落盘才能取回。
 * 传了 `resultDir` 时会把结果写成 JSON；没传就只能靠 runner 或 sync 档拿结果。
 */
class BackgroundDispatcher
{
    const MODE_RUNNER = 'runner';
    const MODE_FORK   = 'fork';
    const MODE_SYNC   = 'sync';

    /** @var callable|null 异步运行器 function(array $payload): mixed */
    protected $runner = null;

    /** @var bool 是否允许 fork */
    protected $allowFork = true;

    /** @var string 结果落盘目录，空则不落盘 */
    protected $resultDir = '';

    /** @var array<string, array<string, mixed>> task_id => 句柄 */
    protected $handles = [];

    /** @var callable|null 任务终结时的回调 function(array $handle): void */
    protected $onComplete = null;

    /**
     * @param array<string, mixed> $options runner / allowFork / resultDir
     */
    public function __construct(array $options = [])
    {
        if (isset($options['runner'])) {
            $this->setRunner($options['runner']);
        }
        if (isset($options['allowFork'])) {
            $this->allowFork = (bool) $options['allowFork'];
        }
        if (isset($options['resultDir'])) {
            $this->setResultDir((string) $options['resultDir']);
        }
    }

    /**
     * 注入异步运行器
     *
     * @param callable|null $runner function(array $payload): mixed
     * @return $this
     */
    public function setRunner($runner)
    {
        $this->runner = is_callable($runner) ? $runner : null;
        return $this;
    }

    /**
     * @param bool $allow
     * @return $this
     */
    public function setAllowFork($allow)
    {
        $this->allowFork = (bool) $allow;
        return $this;
    }

    /**
     * @param string $dir
     * @return $this
     */
    public function setResultDir($dir)
    {
        $this->resultDir = rtrim(str_replace('\\', '/', (string) $dir), '/');
        if ($this->resultDir !== '' && !is_dir($this->resultDir)) {
            @mkdir($this->resultDir, 0777, true);
        }
        return $this;
    }

    /**
     * 任务终结（完成或失败）时的回调
     *
     * 后台任务跑完之后主 Agent 得知道——这是跨 Session 消息投递的挂载点。
     * fork 档的回调在父进程调 `status()` 发现结果时触发，不是子进程里触发：
     * 子进程的内存父进程看不到，在那边回调等于没回调。
     *
     * @param callable|null $callback function(array $handle): void
     * @return $this
     */
    public function onComplete($callback)
    {
        $this->onComplete = is_callable($callback) ? $callback : null;
        return $this;
    }

    /**
     * 当前环境实际能用的档位
     *
     * @return string runner|fork|sync
     */
    public function mode()
    {
        if ($this->runner !== null) {
            return self::MODE_RUNNER;
        }
        if ($this->allowFork && $this->canFork()) {
            return self::MODE_FORK;
        }
        return self::MODE_SYNC;
    }

    /**
     * 本机是否支持 fork
     *
     * @return bool
     */
    public function canFork()
    {
        if (!function_exists('pcntl_fork')) {
            return false;
        }
        // 有些环境装了扩展但在 php.ini 里禁用了函数
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('pcntl_fork', $disabled, true);
    }

    /**
     * 派发一个后台任务
     *
     * @param string $taskId
     * @param callable $work function(): mixed 实际要执行的活
     * @param array<string, mixed> $payload 交给 runner 的描述信息
     * @return array<string, mixed> 句柄：task_id / status / mode / background
     */
    public function dispatch($taskId, callable $work, array $payload = [])
    {
        $taskId = (string) $taskId;
        $mode = $this->mode();

        $handle = [
            'task_id'    => $taskId,
            'status'     => 'running',
            'mode'       => $mode,
            'background' => $mode !== self::MODE_SYNC,
            'started_at' => time(),
        ];

        switch ($mode) {
            case self::MODE_RUNNER:
                $handle = array_merge($handle, $this->dispatchViaRunner($taskId, $work, $payload));
                break;
            case self::MODE_FORK:
                $handle = array_merge($handle, $this->dispatchViaFork($taskId, $work));
                break;
            default:
                $handle = array_merge($handle, $this->dispatchSync($taskId, $work));
        }

        $this->handles[$taskId] = $handle;
        if ($handle['status'] !== 'running') {
            $this->notifyComplete($handle);
        }
        return $handle;
    }

    /**
     * 查询任务句柄
     *
     * fork 档会顺带从磁盘捡结果——子进程写完之后父进程才看得到。
     *
     * @param string $taskId
     * @return array<string, mixed>|null
     */
    public function status($taskId)
    {
        $taskId = (string) $taskId;
        if (!isset($this->handles[$taskId])) {
            return null;
        }

        $handle = $this->handles[$taskId];
        if ($handle['status'] === 'running' && $handle['mode'] === self::MODE_FORK) {
            $result = $this->readResult($taskId);
            if ($result !== null) {
                $handle['status'] = isset($result['status']) ? (string) $result['status'] : 'completed';
                $handle['result'] = isset($result['result']) ? $result['result'] : null;
                $handle['finished_at'] = isset($result['finished_at']) ? (int) $result['finished_at'] : time();
                $this->handles[$taskId] = $handle;
                $this->notifyComplete($handle);
            }
        }
        return $handle;
    }

    /**
     * 取结果，未完成返回 null
     *
     * @param string $taskId
     * @return mixed
     */
    public function result($taskId)
    {
        $handle = $this->status($taskId);
        if ($handle === null || $handle['status'] === 'running') {
            return null;
        }
        return isset($handle['result']) ? $handle['result'] : null;
    }

    /**
     * 全部句柄
     *
     * @return array<string, array<string, mixed>>
     */
    public function handles()
    {
        return $this->handles;
    }

    /**
     * 触发终结回调
     *
     * @param array<string, mixed> $handle
     * @return void
     */
    protected function notifyComplete(array $handle)
    {
        if ($this->onComplete === null) {
            return;
        }
        try {
            call_user_func($this->onComplete, $handle);
        } catch (\Throwable $e) {
            // 通知失败不该影响任务本身的结果
        }
    }

    /**
     * 通过 runner 派发
     *
     * @param string $taskId
     * @param callable $work
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function dispatchViaRunner($taskId, callable $work, array $payload)
    {
        $payload['task_id'] = $taskId;
        $payload['work'] = $work;

        $runner = $this->runner;
        if ($runner === null) {
            return $this->dispatchSync($taskId, $work);
        }

        try {
            $returned = call_user_func($runner, $payload);
            // runner 同步返回了结果说明它其实是同步跑的，如实标记
            if ($returned !== null) {
                return ['status' => 'completed', 'result' => $returned, 'finished_at' => time()];
            }
            return ['status' => 'running'];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => time()];
        }
    }

    /**
     * fork 子进程执行
     *
     * @param string $taskId
     * @param callable $work
     * @return array<string, mixed>
     */
    protected function dispatchViaFork($taskId, callable $work)
    {
        $pid = @pcntl_fork();

        if ($pid === -1) {
            // fork 失败就退回同步，别把任务丢了
            return array_merge(['mode' => self::MODE_SYNC, 'background' => false], $this->dispatchSync($taskId, $work));
        }

        if ($pid === 0) {
            // 子进程：跑完写结果就退出，绝不能往下走回父进程的流程
            $status = 'completed';
            $result = null;
            try {
                $result = call_user_func($work);
            } catch (\Throwable $e) {
                $status = 'failed';
                $result = $e->getMessage();
            }
            $this->writeResult($taskId, $status, $result);
            exit(0);
        }

        return ['status' => 'running', 'pid' => $pid];
    }

    /**
     * 同步执行（降级路径）
     *
     * @param string $taskId
     * @param callable $work
     * @return array<string, mixed>
     */
    protected function dispatchSync($taskId, callable $work)
    {
        try {
            $result = call_user_func($work);
            $this->writeResult($taskId, 'completed', $result);
            return ['status' => 'completed', 'result' => $result, 'finished_at' => time()];
        } catch (\Throwable $e) {
            $this->writeResult($taskId, 'failed', $e->getMessage());
            return ['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => time()];
        }
    }

    /**
     * 结果落盘
     *
     * @param string $taskId
     * @param string $status
     * @param mixed $result
     * @return void
     */
    protected function writeResult($taskId, $status, $result)
    {
        $file = $this->resultFile($taskId);
        if ($file === '') {
            return;
        }
        // AgentResult 之类的对象没法直接 JSON 化，转成可读结构
        if (is_object($result)) {
            $result = method_exists($result, 'toArray')
                ? $result->toArray()
                : (method_exists($result, 'getText') ? (string) $result->getText() : get_class($result));
        }
        $json = json_encode([
            'task_id'     => (string) $taskId,
            'status'      => (string) $status,
            'result'      => $result,
            'finished_at' => time(),
        ], JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            @file_put_contents($file, $json, LOCK_EX);
        }
    }

    /**
     * 读结果
     *
     * @param string $taskId
     * @return array<string, mixed>|null
     */
    protected function readResult($taskId)
    {
        $file = $this->resultFile($taskId);
        if ($file === '' || !is_file($file)) {
            return null;
        }
        $json = @file_get_contents($file);
        if ($json === false || trim($json) === '') {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param string $taskId
     * @return string
     */
    protected function resultFile($taskId)
    {
        if ($this->resultDir === '') {
            return '';
        }
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $taskId);
        return $safe === '' ? '' : $this->resultDir . '/' . $safe . '.json';
    }
}
