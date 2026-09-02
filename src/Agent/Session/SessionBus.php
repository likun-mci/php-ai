<?php
namespace Ai\Agent\Session;

use Ai\Agent\Team\AgentMessage;

/**
 * SessionBus——跨 Session 消息总线
 *
 * `AgentCommunication` 解决的是一个团队内部、同一个进程里的消息。跨 Session 是另一回事：
 * 后台 Agent 在另一个进程（甚至另一次 PHP 请求）里跑完，得让主 Session 知道。
 * 那条消息必须落盘才传得过去——内存里的队列，另一个进程根本看不见。
 *
 * ```php
 * // 后台 Agent 那边
 * $bus = new SessionBus('/var/data/session-bus');
 * $bus->send('session_main', AgentMessage::status('background', '安全扫描完成，发现 3 个问题'));
 *
 * // 主 Session 这边
 * $bus = new SessionBus('/var/data/session-bus');
 * foreach ($bus->receive('session_main') as $message) {   // 收完即删
 *     echo $message->toPrompt();
 * }
 * ```
 *
 * 不传目录时退化成纯内存模式，只在同进程内可用——适合测试与单进程场景，
 * 但**后台任务通知必须配目录**，否则消息发出去没人收得到。
 */
class SessionBus
{
    /** @var string 消息落盘目录，空则纯内存 */
    protected $baseDir = '';

    /** @var array<string, AgentMessage[]> session_id => 未读消息（内存模式用） */
    protected $inboxes = [];

    /** @var array<string, callable[]> session_id => 订阅回调 */
    protected $subscribers = [];

    /** @var int 单个 Session 最多堆积多少条未读，超出丢最早的 */
    protected $maxPending = 200;

    /**
     * @param string $baseDir 落盘目录，空字符串则纯内存
     * @param array<string, mixed> $options maxPending
     */
    public function __construct($baseDir = '', array $options = [])
    {
        $this->baseDir = rtrim(str_replace('\\', '/', (string) $baseDir), '/');
        if ($this->baseDir !== '' && !is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0777, true);
        }
        if (isset($options['maxPending'])) {
            $this->maxPending = max(1, (int) $options['maxPending']);
        }
    }

    /**
     * 向某个 Session 投递消息
     *
     * @param string $sessionId 目标 Session
     * @param AgentMessage $message
     * @return bool
     */
    public function send($sessionId, AgentMessage $message)
    {
        $sessionId = (string) $sessionId;
        if ($sessionId === '') {
            return false;
        }

        if ($this->baseDir === '') {
            if (!isset($this->inboxes[$sessionId])) {
                $this->inboxes[$sessionId] = [];
            }
            $this->inboxes[$sessionId][] = $message;
            $this->trimMemory($sessionId);
            $this->notify($sessionId, $message);
            return true;
        }

        $file = $this->inboxFile($sessionId);
        if ($file === '') {
            return false;
        }

        $pending = $this->readInbox($sessionId);
        $pending[] = $message->toArray();
        if (count($pending) > $this->maxPending) {
            $pending = array_slice($pending, -$this->maxPending);
        }

        $json = json_encode($pending, JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents($file, $json, LOCK_EX) === false) {
            return false;
        }

        $this->notify($sessionId, $message);
        return true;
    }

    /**
     * 收取某个 Session 的消息（收完即清空）
     *
     * @param string $sessionId
     * @return AgentMessage[]
     */
    public function receive($sessionId)
    {
        $sessionId = (string) $sessionId;

        if ($this->baseDir === '') {
            $messages = isset($this->inboxes[$sessionId]) ? $this->inboxes[$sessionId] : [];
            $this->inboxes[$sessionId] = [];
            return $messages;
        }

        $pending = $this->readInbox($sessionId);
        if (!$pending) {
            return [];
        }

        // 先清空再返回：中途崩了宁可丢消息，也不要下次重复投递同一条
        $file = $this->inboxFile($sessionId);
        if ($file !== '') {
            @file_put_contents($file, '[]', LOCK_EX);
        }

        $messages = [];
        foreach ($pending as $data) {
            if (is_array($data)) {
                $messages[] = $this->messageFromArray($data);
            }
        }
        return $messages;
    }

    /**
     * 查看未读但不取走
     *
     * @param string $sessionId
     * @return AgentMessage[]
     */
    public function peek($sessionId)
    {
        $sessionId = (string) $sessionId;

        if ($this->baseDir === '') {
            return isset($this->inboxes[$sessionId]) ? $this->inboxes[$sessionId] : [];
        }

        $messages = [];
        foreach ($this->readInbox($sessionId) as $data) {
            if (is_array($data)) {
                $messages[] = $this->messageFromArray($data);
            }
        }
        return $messages;
    }

    /**
     * 未读条数
     *
     * @param string $sessionId
     * @return int
     */
    public function pendingCount($sessionId)
    {
        return count($this->peek($sessionId));
    }

    /**
     * 有没有未读
     *
     * @param string $sessionId
     * @return bool
     */
    public function hasPending($sessionId)
    {
        return $this->pendingCount($sessionId) > 0;
    }

    /**
     * 订阅某个 Session 的消息
     *
     * 回调只在**本进程内** `send()` 时触发——跨进程投递的消息，另一端得靠
     * `receive()` 主动取。PHP 没有常驻进程间的推送通道，这一点无法回避。
     *
     * @param string $sessionId
     * @param callable $callback function(AgentMessage $message, string $sessionId): void
     * @return $this
     */
    public function subscribe($sessionId, callable $callback)
    {
        $sessionId = (string) $sessionId;
        if (!isset($this->subscribers[$sessionId])) {
            $this->subscribers[$sessionId] = [];
        }
        $this->subscribers[$sessionId][] = $callback;
        return $this;
    }

    /**
     * 取消某个 Session 的全部订阅
     *
     * @param string $sessionId
     * @return $this
     */
    public function unsubscribe($sessionId)
    {
        unset($this->subscribers[(string) $sessionId]);
        return $this;
    }

    /**
     * 收取消息并拼成可注入的提示词
     *
     * @param string $sessionId
     * @param bool $consume 是否同时清空
     * @return string 没有未读时返回空串
     */
    public function toPrompt($sessionId, $consume = true)
    {
        $messages = $consume ? $this->receive($sessionId) : $this->peek($sessionId);
        if (!$messages) {
            return '';
        }
        $parts = [];
        foreach ($messages as $message) {
            $parts[] = $message->toPrompt();
        }
        return "<session-messages>\n" . implode("\n\n", $parts) . "\n</session-messages>";
    }

    /**
     * 清空某个 Session 的未读
     *
     * @param string $sessionId
     * @return $this
     */
    public function clear($sessionId)
    {
        $sessionId = (string) $sessionId;
        if ($this->baseDir === '') {
            $this->inboxes[$sessionId] = [];
            return $this;
        }
        $file = $this->inboxFile($sessionId);
        if ($file !== '' && is_file($file)) {
            @unlink($file);
        }
        return $this;
    }

    /**
     * 有未读消息的 Session
     *
     * @return string[]
     */
    public function sessions()
    {
        if ($this->baseDir === '') {
            return array_keys(array_filter($this->inboxes, function ($messages) {
                return $messages !== [];
            }));
        }

        $files = glob($this->baseDir . '/*.inbox.json');
        $sessions = [];
        foreach ($files === false ? [] : $files as $file) {
            $name = basename($file, '.inbox.json');
            if ($name !== '' && $this->pendingCount($name) > 0) {
                $sessions[] = $name;
            }
        }
        return $sessions;
    }

    /**
     * @return string
     */
    public function getBaseDir()
    {
        return $this->baseDir;
    }

    /**
     * 触发订阅回调
     *
     * @param string $sessionId
     * @param AgentMessage $message
     * @return void
     */
    protected function notify($sessionId, AgentMessage $message)
    {
        if (!isset($this->subscribers[$sessionId])) {
            return;
        }
        foreach ($this->subscribers[$sessionId] as $callback) {
            try {
                call_user_func($callback, $message, $sessionId);
            } catch (\Throwable $e) {
                // 订阅方抛异常不该影响投递本身
            }
        }
    }

    /**
     * 读收件箱原始数据
     *
     * @param string $sessionId
     * @return array<int, array<string, mixed>>
     */
    protected function readInbox($sessionId)
    {
        $file = $this->inboxFile($sessionId);
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
     * @return string
     */
    protected function inboxFile($sessionId)
    {
        if ($this->baseDir === '') {
            return '';
        }
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $sessionId);
        return $safe === '' ? '' : $this->baseDir . '/' . $safe . '.inbox.json';
    }

    /**
     * 内存模式下裁剪超量消息
     *
     * @param string $sessionId
     * @return void
     */
    protected function trimMemory($sessionId)
    {
        if (count($this->inboxes[$sessionId]) > $this->maxPending) {
            $this->inboxes[$sessionId] = array_slice($this->inboxes[$sessionId], -$this->maxPending);
        }
    }

    /**
     * 从数组还原消息
     *
     * @param array<string, mixed> $data
     * @return AgentMessage
     */
    protected function messageFromArray(array $data)
    {
        return new AgentMessage(
            isset($data['from']) ? $data['from'] : '',
            isset($data['to']) ? $data['to'] : '',
            isset($data['type']) ? $data['type'] : AgentMessage::TYPE_STATUS,
            isset($data['content']) ? $data['content'] : '',
            isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : []
        );
    }
}
