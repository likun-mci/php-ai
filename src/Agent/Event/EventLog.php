<?php
namespace Ai\Agent\Event;

/**
 * EventLog——事件日志与回放
 *
 * 前端断线重连时，需要「从我收到的最后一条之后继续发」，而不是把 Agent 重跑一遍。
 * 这个类按 sequence 存事件，支持从任意位置续发。
 *
 * ```php
 * $log = new EventLog('/var/data/events');
 * $agent->onEvent(function ($event) use ($log) { $log->append($event); });
 *
 * // 客户端带着 Last-Event-ID 重连
 * $missed = $log->since($lastEventId);     // 它没收到的那些
 * foreach ($missed as $event) { echo "data: " . json_encode($event) . "\n\n"; }
 * ```
 *
 * **只重发事件，不重新执行 Agent。** 重跑会产生新的副作用（再改一遍文件、再跑一遍命令），
 * 而客户端要的只是补上漏掉的那几条消息。
 *
 * 事件按 session 分文件存放；不传目录则只在内存里，进程结束即丢——
 * 断线重连的场景必须配目录，否则重连时进程可能已经换了。
 */
class EventLog
{
    /** @var string 落盘目录，空则纯内存 */
    protected $baseDir = '';

    /** @var array<string, array<int, array<string, mixed>>> session => 事件列表 */
    protected $events = [];

    /** @var int 每个 session 最多保留多少条 */
    protected $maxEvents = 1000;

    /** @var string 当前默认 session */
    protected $sessionId = 'default';

    /**
     * @param string $baseDir
     * @param array<string, mixed> $options maxEvents / sessionId
     */
    public function __construct($baseDir = '', array $options = [])
    {
        $this->baseDir = rtrim(str_replace('\\', '/', (string) $baseDir), '/');
        if ($this->baseDir !== '' && !is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0777, true);
        }
        if (isset($options['maxEvents'])) {
            $this->maxEvents = max(1, (int) $options['maxEvents']);
        }
        if (isset($options['sessionId'])) {
            $this->sessionId = (string) $options['sessionId'];
        }
    }

    /**
     * 记录一条事件
     *
     * 事件里的 `session_id` 优先；没有就用默认 session。
     *
     * @param array<string, mixed> $event
     * @return bool
     */
    public function append(array $event)
    {
        $sessionId = isset($event['session_id']) && $event['session_id'] !== ''
            ? (string) $event['session_id']
            : $this->sessionId;

        $events = $this->load($sessionId);

        // 没有 sequence 的事件补一个，否则回放时无从定位
        if (!isset($event['sequence'])) {
            $last = $events ? $events[count($events) - 1] : null;
            $event['sequence'] = $last !== null && isset($last['sequence'])
                ? (int) $last['sequence'] + 1
                : 1;
        }
        if (!isset($event['timestamp'])) {
            $event['timestamp'] = time();
        }

        $events[] = $event;
        if (count($events) > $this->maxEvents) {
            $events = array_slice($events, -$this->maxEvents);
        }
        return $this->store($sessionId, $events);
    }

    /**
     * 取某个 session 的全部事件
     *
     * @param string $sessionId
     * @return array<int, array<string, mixed>>
     */
    public function all($sessionId = '')
    {
        return $this->load($sessionId === '' ? $this->sessionId : (string) $sessionId);
    }

    /**
     * 取 sequence 之后的事件——断线重连补发用
     *
     * @param int $afterSequence 客户端收到的最后一条的 sequence
     * @param string $sessionId
     * @return array<int, array<string, mixed>>
     */
    public function since($afterSequence, $sessionId = '')
    {
        $afterSequence = (int) $afterSequence;
        $events = $this->all($sessionId);

        return array_values(array_filter($events, function ($event) use ($afterSequence) {
            return isset($event['sequence']) && (int) $event['sequence'] > $afterSequence;
        }));
    }

    /**
     * 按事件 ID 定位后续事件
     *
     * SSE 的 `Last-Event-ID` 传的是事件 ID 而不是 sequence，这个方法做转换。
     * ID 找不到时返回全部——宁可重发也不要漏发，客户端自己去重比丢消息强。
     *
     * @param string $lastEventId
     * @param string $sessionId
     * @return array<int, array<string, mixed>>
     */
    public function sinceId($lastEventId, $sessionId = '')
    {
        $lastEventId = (string) $lastEventId;
        if ($lastEventId === '') {
            return $this->all($sessionId);
        }

        $events = $this->all($sessionId);
        foreach ($events as $index => $event) {
            if (isset($event['id']) && (string) $event['id'] === $lastEventId) {
                return array_values(array_slice($events, $index + 1));
            }
        }
        return $events;
    }

    /**
     * 按类型过滤
     *
     * @param string $type
     * @param string $sessionId
     * @return array<int, array<string, mixed>>
     */
    public function ofType($type, $sessionId = '')
    {
        $type = (string) $type;
        return array_values(array_filter($this->all($sessionId), function ($event) use ($type) {
            return isset($event['type']) && $event['type'] === $type;
        }));
    }

    /**
     * 某个任务的事件——`events(task_id)`
     *
     * @param string $taskId
     * @param string $sessionId
     * @return array<int, array<string, mixed>>
     */
    public function ofTask($taskId, $sessionId = '')
    {
        $taskId = (string) $taskId;
        return array_values(array_filter($this->all($sessionId), function ($event) use ($taskId) {
            return isset($event['task_id']) && (string) $event['task_id'] === $taskId;
        }));
    }

    /**
     * 最新的 sequence
     *
     * @param string $sessionId
     * @return int 没有事件返回 0
     */
    public function lastSequence($sessionId = '')
    {
        $events = $this->all($sessionId);
        if (!$events) {
            return 0;
        }
        $last = $events[count($events) - 1];
        return isset($last['sequence']) ? (int) $last['sequence'] : 0;
    }

    /**
     * 事件条数
     *
     * @param string $sessionId
     * @return int
     */
    public function count($sessionId = '')
    {
        return count($this->all($sessionId));
    }

    /**
     * 生成 SSE 帧文本——直接 echo 给客户端
     *
     * @param array<int, array<string, mixed>> $events
     * @return string
     */
    public static function toSse(array $events)
    {
        $out = '';
        foreach ($events as $event) {
            $json = json_encode($event, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                continue;
            }
            if (isset($event['id'])) {
                $out .= 'id: ' . $event['id'] . "\n";
            }
            if (isset($event['type'])) {
                $out .= 'event: ' . $event['type'] . "\n";
            }
            $out .= 'data: ' . $json . "\n\n";
        }
        return $out;
    }

    /**
     * 清空某个 session 的事件
     *
     * @param string $sessionId
     * @return $this
     */
    public function clear($sessionId = '')
    {
        $sessionId = $sessionId === '' ? $this->sessionId : (string) $sessionId;
        $this->events[$sessionId] = [];
        $file = $this->fileFor($sessionId);
        if ($file !== '' && is_file($file)) {
            @unlink($file);
        }
        return $this;
    }

    /**
     * @param string $sessionId
     * @return $this
     */
    public function setSessionId($sessionId)
    {
        $this->sessionId = (string) $sessionId;
        return $this;
    }

    /**
     * 挂到 Agent 上的事件回调
     *
     * ```php
     * $agent->onEvent($log->recorder());
     * ```
     *
     * @return callable
     */
    public function recorder()
    {
        $self = $this;
        return function (array $event) use ($self) {
            $self->append($event);
        };
    }

    /**
     * @param string $sessionId
     * @return array<int, array<string, mixed>>
     */
    protected function load($sessionId)
    {
        $sessionId = (string) $sessionId;

        if ($this->baseDir === '') {
            return isset($this->events[$sessionId]) ? $this->events[$sessionId] : [];
        }

        $file = $this->fileFor($sessionId);
        if ($file === '' || !is_file($file)) {
            return [];
        }
        $json = @file_get_contents($file);
        if ($json === false || trim($json) === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * @param string $sessionId
     * @param array<int, array<string, mixed>> $events
     * @return bool
     */
    protected function store($sessionId, array $events)
    {
        if ($this->baseDir === '') {
            $this->events[$sessionId] = $events;
            return true;
        }
        $file = $this->fileFor($sessionId);
        if ($file === '') {
            return false;
        }
        $json = json_encode($events, JSON_UNESCAPED_UNICODE);
        return $json !== false && @file_put_contents($file, $json, LOCK_EX) !== false;
    }

    /**
     * @param string $sessionId
     * @return string
     */
    protected function fileFor($sessionId)
    {
        if ($this->baseDir === '') {
            return '';
        }
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $sessionId);
        return $safe === '' ? '' : $this->baseDir . '/' . $safe . '.events.json';
    }
}
