<?php
namespace Ai\Task;

use Ai\AI;
use Ai\Contracts\CapabilityResponseInterface;
use Ai\Exceptions\UnsupportedCapabilityException;
use Ai\Helpers\Capabilities;
use Ai\Helpers\Log;

/**
 * 异步生成任务
 *
 * 视频生成和多数国产文生图接口都是三段式：提交 → 轮询 → 取结果。
 * 提交后立刻返回本对象，**不阻塞**。
 *
 * ```php
 * // 提交后存库，请求立即结束
 * $task = $ai->video()->generate('日落的海边');
 * $db->save(['task' => json_encode($task->toArray())]);
 *
 * // 稍后（定时任务 / 另一个请求）恢复并查询
 * $task = AsyncTask::fromArray(json_decode($row['task'], true), $ai);
 * if ($task->refresh()->isDone()) {
 *     $task->getResult()->saveTo('/var/www/videos/x.mp4');
 * }
 * ```
 *
 * **为什么默认不阻塞**：视频生成动辄几分钟，PHP-FPM 下阻塞等待会占死一个 worker，
 * 并发一上来整站就不可用。wait() 是给 CLI / 队列 worker 用的，不是给 Web 请求用的。
 */
class AsyncTask
{
    const STATUS_PENDING   = 'pending';
    const STATUS_RUNNING   = 'running';
    const STATUS_SUCCEEDED = 'succeeded';
    const STATUS_FAILED    = 'failed';

    /**
     * 等待超时。**这不是失败**——任务在平台侧仍在跑，
     * 保存 task_id 稍后 refresh() 还能拿到结果
     */
    const STATUS_TIMEOUT = 'timeout';

    /** 轮询间隔上限（秒）。再长会让「刚好完成」和「发现完成」之间拖太久 */
    const MAX_INTERVAL = 30;

    /** @var string */
    protected $id = '';
    /** @var string 能力标识，见 Capabilities */
    protected $capability = '';
    /** @var string */
    protected $status = self::STATUS_PENDING;
    /** @var string 给人看的说明，超时时告知如何继续 */
    protected $message = '';
    /** @var string */
    protected $error = '';
    /** @var array<string, mixed> 最近一次查询的原始响应 */
    protected $raw = [];
    /** @var string 任务查询端点（部分平台与提交端点不同） */
    protected $queryUrl = '';
    /** @var int 已轮询次数 */
    protected $polls = 0;
    /** @var CapabilityResponseInterface|null */
    protected $result = null;
    /** @var AI|null 反序列化恢复后必须重新注入 */
    protected $ai = null;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        string $id,
        string $capability,
        ?AI $ai = null,
        string $queryUrl = '',
        array $raw = []
    ) {
        $this->id         = $id;
        $this->capability = $capability;
        $this->ai         = $ai;
        $this->queryUrl   = $queryUrl;
        $this->raw        = $raw;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCapability(): string
    {
        return $this->capability;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * 任务是否已有最终结论（成功或失败）
     *
     * **超时返回 false**，因为任务还活着。这样 `if ($task->isDone())` 的写法
     * 天然不会把超时误当成完成
     */
    public function isDone(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED || $this->status === self::STATUS_FAILED;
    }

    /**
     * 任务是否还没有结论（含排队中、生成中、等待超时）
     */
    public function isPending(): bool
    {
        return !$this->isDone();
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * 是否是等待超时（而非失败）
     */
    public function isTimeout(): bool
    {
        return $this->status === self::STATUS_TIMEOUT;
    }

    /**
     * 人话说明。超时时会给出「保存 task_id 稍后再查」的具体指引
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    public function getError(): string
    {
        return $this->error;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRaw(): array
    {
        return $this->raw;
    }

    public function getPolls(): int
    {
        return $this->polls;
    }

    /**
     * 生成结果。**未成功完成时返回 null**，配合 getMessage() / getError() 判断原因
     */
    public function getResult(): ?CapabilityResponseInterface
    {
        return $this->result;
    }

    /**
     * 重新注入 AI 实例（反序列化恢复后使用）
     */
    public function setAI(AI $ai): self
    {
        $this->ai = $ai;
        return $this;
    }

    /**
     * 主动查询一次任务状态
     *
     * @throws UnsupportedCapabilityException 未注入 AI 实例，或协议未实现任务查询
     */
    public function refresh(): self
    {
        if ($this->isDone()) {
            return $this;   // 已有结论就不再打扰平台
        }

        $ai = $this->ai;
        if ($ai === null) {
            throw new UnsupportedCapabilityException(
                '任务未关联 AI 实例，无法查询。反序列化恢复时请用 AsyncTask::fromArray($data, $ai)，'
                . '或先调用 setAI($ai)'
            );
        }

        $protocol = $ai->protocolInstance();
        if ($protocol === null) {
            throw new UnsupportedCapabilityException('AI 实例尚未初始化协议，请先 setModel()');
        }
        // 任务查询的解析由协议层提供（各平台状态字段名与取值都不一样）。
        // 鸭子类型探测而非写进接口，是因为只有少数几个平台需要它
        if (!method_exists($protocol, 'parseTaskStatus')) {
            throw new UnsupportedCapabilityException(sprintf(
                '协议 %s 未实现异步任务查询（缺少 parseTaskStatus 方法），无法轮询「%s」任务',
                get_class($protocol),
                Capabilities::label($this->capability)
            ));
        }

        $url = $this->queryUrl;
        if ($url === '') {
            throw new UnsupportedCapabilityException('任务查询地址为空，无法查询任务 ' . $this->id);
        }

        $this->polls++;
        $headers  = $protocol->buildHeaders($ai->getConfig());
        $response = $ai->transport()->get($url, [], $headers);
        $this->raw = $response;

        /** @var array{status?: string, error?: string, result?: CapabilityResponseInterface|null, result_url?: string} $parsed */
        $parsed = $protocol->parseTaskStatus($this->capability, $response, $url);

        $status = isset($parsed['status']) ? (string) $parsed['status'] : self::STATUS_RUNNING;
        $this->status  = $this->normalizeStatus($status);
        $this->error   = isset($parsed['error']) ? (string) $parsed['error'] : '';
        $this->result  = isset($parsed['result']) && $parsed['result'] instanceof CapabilityResponseInterface
            ? $parsed['result']
            : null;

        // 三段式平台：查状态只拿到一个文件 ID，还要再取一次才有真正的下载地址
        // （MiniMax 就是这样：status=Success 时给 file_id，得再调 files/retrieve）。
        // 多一跳而不是把它塞进 parseTaskStatus，是因为协议层拿不到传输层，
        // 让协议自己发请求会把两层职责搅在一起
        if ($this->status === self::STATUS_SUCCEEDED
            && $this->result === null
            && !empty($parsed['result_url'])
            && method_exists($protocol, 'parseTaskResult')
        ) {
            $this->polls++;
            $final = $ai->transport()->get((string) $parsed['result_url'], [], $headers);
            $built = $protocol->parseTaskResult($this->capability, $final);
            if ($built instanceof CapabilityResponseInterface) {
                $this->result = $built;
            }
            $this->raw = array_merge($this->raw, ['_final' => $final]);
        }

        if ($this->status === self::STATUS_SUCCEEDED) {
            $this->message = '任务已完成';
        } elseif ($this->status === self::STATUS_FAILED) {
            $this->message = '任务失败：' . ($this->error !== '' ? $this->error : '平台未提供原因');
        } else {
            $this->message = '任务处理中';
        }

        return $this;
    }

    /**
     * 阻塞等待任务完成
     *
     * ⚠️ **不要在 Web 请求里调用**。视频生成动辄几分钟，会占死一个 PHP-FPM worker。
     * 这个方法是给 CLI 脚本和队列 worker 用的。
     *
     * 超时**不抛异常**：任务在平台侧仍在跑，抛异常会诱导调用方 catch 后当失败处理，
     * 白白丢掉一次已经付费的生成。超时后状态置为 timeout，isDone() 仍为 false，
     * getMessage() 给出继续查询的指引。
     *
     * @param int $timeoutSec  最长等待秒数
     * @param int $intervalSec 首次轮询间隔，之后按 1.5 倍递增并加抖动，上限 30 秒
     */
    public function wait(int $timeoutSec = 300, int $intervalSec = 3): self
    {
        $deadline = time() + max(1, $timeoutSec);
        $interval = max(1, $intervalSec);

        while (true) {
            $this->refresh();
            if ($this->isDone()) {
                return $this;
            }

            $remaining = $deadline - time();
            if ($remaining <= 0) {
                $this->status  = self::STATUS_TIMEOUT;
                $this->message = sprintf(
                    '任务仍在平台侧处理中（已等待约 %d 秒，轮询 %d 次）。这不是失败——'
                    . '请保存 task_id「%s」，稍后用 AsyncTask::fromArray($data, $ai) 恢复后再调用 refresh() 继续查询。',
                    $timeoutSec,
                    $this->polls,
                    $this->id
                );
                Log::warning('异步任务等待超时', ['task_id' => $this->id, 'polls' => $this->polls]);
                return $this;
            }

            // 退避 + 抖动：固定间隔会让多个 worker 在同一时刻齐刷刷查询，把平台限流打满
            $sleep = (int) min($interval, self::MAX_INTERVAL, $remaining);
            $jitter = $sleep > 1 ? random_int(0, (int) ($sleep * 200)) : 0;   // 最多 +20%
            usleep($sleep * 1000000 + $jitter * 1000);

            $interval = (int) ceil($interval * 1.5);
        }
    }

    /**
     * 序列化，用于存库后跨请求恢复
     *
     * 不含 AI 实例（含连接与密钥，既存不进去也不该存），恢复时重新注入
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'capability' => $this->capability,
            'status'     => $this->status,
            'message'    => $this->message,
            'error'      => $this->error,
            'query_url'  => $this->queryUrl,
            'polls'      => $this->polls,
            'raw'        => $this->raw,
        ];
    }

    /**
     * 从 toArray() 的结果恢复
     *
     * 恢复出的任务**不含结果对象**——结果只在 refresh() 拿到成功状态时重建，
     * 避免把一个可能已经过期的媒体 URL 当成有效结果存着
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, ?AI $ai = null): self
    {
        $task = new self(
            isset($data['id']) ? (string) $data['id'] : '',
            isset($data['capability']) ? (string) $data['capability'] : '',
            $ai,
            isset($data['query_url']) ? (string) $data['query_url'] : '',
            isset($data['raw']) && is_array($data['raw']) ? $data['raw'] : []
        );
        $task->status  = isset($data['status']) ? (string) $data['status'] : self::STATUS_PENDING;
        $task->message = isset($data['message']) ? (string) $data['message'] : '';
        $task->error   = isset($data['error']) ? (string) $data['error'] : '';
        $task->polls   = isset($data['polls']) ? (int) $data['polls'] : 0;
        return $task;
    }

    /**
     * 把平台五花八门的状态取值收敛成库内标准值
     *
     * 各平台差异很大：task_status / status / state 三种字段名，
     * SUCCEEDED / success / Success / 2 四种成功写法。协议层负责映射，
     * 这里再兜一次底，避免某个协议漏映射时状态变成一个谁也不认识的字符串
     */
    protected function normalizeStatus(string $status): string
    {
        $s = strtolower(trim($status));

        $succeeded = ['succeeded', 'success', 'successful', 'completed', 'done', 'finished', '2'];
        $failed    = ['failed', 'failure', 'fail', 'error', 'cancelled', 'canceled', 'rejected', '3'];
        $running   = ['running', 'processing', 'in_progress', 'inprogress', 'generating', '1'];
        $pending   = ['pending', 'queued', 'queuing', 'queueing', 'preparing', 'submitted', 'waiting', '0'];

        if (in_array($s, $succeeded, true)) {
            return self::STATUS_SUCCEEDED;
        }
        if (in_array($s, $failed, true)) {
            return self::STATUS_FAILED;
        }
        if (in_array($s, $running, true)) {
            return self::STATUS_RUNNING;
        }
        if (in_array($s, $pending, true)) {
            return self::STATUS_PENDING;
        }

        // 认不出的状态按「还在跑」处理，不能当成失败——
        // 平台新增一个状态值就让用户的任务全变失败，是最糟的降级方式
        Log::warning('未识别的任务状态，按处理中对待', ['status' => $status, 'task_id' => $this->id]);
        return self::STATUS_RUNNING;
    }
}
