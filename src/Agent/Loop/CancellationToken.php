<?php
namespace Ai\Agent\Loop;

/**
 * CancellationToken——把「停下来」变成一件真的能做到的事
 *
 * `ToolContext` 早就有 `cancel()` / `isCancelled()`，但循环从不查它，也从不把它
 * 传进去——调了等于没调，没有任何路径能停下一个正在跑的 Agent。生产环境里这不是
 * 可选项：HTTP 请求断了、用户按了停止、任务超时，都需要循环在下一个安全点收手，
 * 而不是把剩下的二十轮跑完再说。
 *
 * 检查点选在**安全边界**上：模型调用之前、工具执行之前、一批工具结果回填之后。
 * 不在这些点之外硬中断——PHP 没有安全的线程中断，半途掐掉一个正在写文件的工具，
 * 留下的是半截文件，比多跑一轮糟得多。
 *
 * 三种用法：
 *
 * ```php
 * // 1. 同进程直接取消（信号处理器、超时回调里）
 * $token = new CancellationToken();
 * $agent->setCancellation($token);
 * pcntl_signal(SIGINT, function () use ($token) { $token->cancel('用户中断'); });
 *
 * // 2. Web 场景：浏览器断开就别再烧钱了
 * $token = CancellationToken::whenConnectionAborted();
 *
 * // 3. 跨进程：另一个进程 / 请求写个文件就能叫停后台任务
 * $token = CancellationToken::whenFileExists('/tmp/agent-' . $taskId . '.stop');
 * ```
 *
 * 探针的结果会**记住**：一旦判定取消就不再回头，避免「文件被删了于是任务又活了」
 * 这种半死不活的状态。
 */
class CancellationToken
{
    /** @var bool */
    protected $cancelled = false;

    /** @var string */
    protected $reason = '';

    /** @var callable|null 外部探针 function(): bool */
    protected $probe = null;

    /** @var string 探针判定取消时用的原因 */
    protected $probeReason = '外部信号要求取消';

    /**
     * @param callable|null $probe 返回 true 表示应当取消
     * @param string $probeReason 探针触发时记录的原因
     */
    public function __construct($probe = null, $probeReason = '')
    {
        if (is_callable($probe)) {
            $this->probe = $probe;
        }
        if ((string) $probeReason !== '') {
            $this->probeReason = (string) $probeReason;
        }
    }

    /**
     * 浏览器断开连接时取消
     *
     * 用户关掉页面之后 Agent 还在后台跑模型，账单照付、结果没人看。
     * 注意 PHP 只有在向输出写入时才会更新连接状态，所以这个探针配合
     * SSE / 流式输出才最灵；纯后台任务用 `whenFileExists()` 更可靠。
     *
     * @return self
     */
    public static function whenConnectionAborted()
    {
        return new self(function () {
            return connection_aborted() === 1;
        }, '客户端已断开连接');
    }

    /**
     * 指定文件出现时取消
     *
     * 跨进程叫停最省事的做法：CLI / 另一个 HTTP 请求 `touch` 一下就行，
     * 不需要共享内存、信号或消息队列。
     *
     * @param string $path
     * @return self
     */
    public static function whenFileExists($path)
    {
        $path = (string) $path;
        return new self(function () use ($path) {
            // 别让 stat 缓存把刚创建的文件挡住——同一个请求里 file_exists
            // 会一直返回旧结果，取消信号就迟迟传不进来
            clearstatcache(true, $path);
            return $path !== '' && file_exists($path);
        }, '收到外部取消信号（' . $path . '）');
    }

    /**
     * 到达指定时刻后取消
     *
     * @param float|int $seconds 从现在起多少秒
     * @return self
     */
    public static function afterSeconds($seconds)
    {
        $deadline = microtime(true) + max(0, (float) $seconds);
        return new self(function () use ($deadline) {
            return microtime(true) >= $deadline;
        }, '已到达截止时间');
    }

    /**
     * 主动取消
     *
     * @param string $reason
     * @return $this
     */
    public function cancel($reason = '调用方要求取消')
    {
        if (!$this->cancelled) {
            $this->cancelled = true;
            $this->reason = (string) $reason;
        }
        return $this;
    }

    /**
     * 现在该停了吗
     *
     * @return bool
     */
    public function isCancelled()
    {
        if ($this->cancelled) {
            return true;
        }
        if ($this->probe !== null && call_user_func($this->probe)) {
            // 探针一旦判定取消就固化下来，之后不再问它。
            // 否则文件被删掉、连接状态被刷新，任务会诡异地"复活"
            $this->cancelled = true;
            $this->reason = $this->probeReason;
            return true;
        }
        return false;
    }

    /**
     * @return string 未取消时为空串
     */
    public function getReason()
    {
        return $this->reason;
    }

    /**
     * 复位（同一个 token 复用于下一次运行时）
     *
     * @return $this
     */
    public function reset()
    {
        $this->cancelled = false;
        $this->reason = '';
        return $this;
    }
}
