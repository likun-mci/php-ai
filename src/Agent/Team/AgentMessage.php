<?php
namespace Ai\Agent\Team;

/**
 * AgentMessage——Agent 之间的结构化消息
 *
 * 多角色协作时，"Tester 发现了 bug"要能被 Developer 准确接住。纯文本传递会丢
 * 类型与来源，收到的一方分不清这是任务分派还是结果汇报，`type` 字段就是为此存在的。
 *
 * ```php
 * $msg = AgentMessage::bug('tester', 'developer', 'AuthTest::testLogin 失败：期望 true 实际 false', [
 *     'file' => 'tests/AuthTest.php',
 *     'line' => 42,
 * ]);
 * echo $msg->toPrompt();   // 注入给接收方的文本
 * ```
 */
class AgentMessage
{
    const TYPE_TASK   = 'task';
    const TYPE_BUG    = 'bug';
    const TYPE_REVIEW = 'review';
    const TYPE_STATUS = 'status';
    const TYPE_RESULT = 'result';

    /** 请求：需要对方回应 */
    const TYPE_REQUEST = 'request';

    /** 回应：对某条 request 的答复 */
    const TYPE_RESPONSE = 'response';

    /** 错误：执行出错的通报 */
    const TYPE_ERROR = 'error';

    /** 交接：把任务转交给另一个 Agent */
    const TYPE_HANDOFF = 'handoff';

    /** @var string[] 全部合法类型 */
    protected static $validTypes = [
        'task', 'bug', 'review', 'status', 'result',
        'request', 'response', 'error', 'handoff',
    ];

    /** @var string 发送者角色 */
    protected $from = '';

    /** @var string 接收者角色，空串表示广播 */
    protected $to = '';

    /** @var string 消息类型 */
    protected $type = self::TYPE_STATUS;

    /** @var string 消息内容 */
    protected $content = '';

    /** @var array<string, mixed> 附加数据 */
    protected $metadata = [];

    /** @var int 发送时间戳 */
    protected $createdAt = 0;

    /** @var string 消息 ID */
    protected $id = '';

    /**
     * @param string $from
     * @param string $to 空串表示广播
     * @param string $type
     * @param string $content
     * @param array<string, mixed> $metadata
     */
    public function __construct($from, $to, $type, $content, array $metadata = [])
    {
        $this->from = (string) $from;
        $this->to = (string) $to;
        $this->type = self::isValidType($type) ? (string) $type : self::TYPE_STATUS;
        $this->content = (string) $content;
        $this->metadata = $metadata;
        $this->createdAt = time();
        $this->id = 'msg_' . substr(md5(uniqid('', true)), 0, 12);
    }

    /**
     * 任务分派
     *
     * @param string $from
     * @param string $to
     * @param string $content
     * @param array<string, mixed> $metadata
     * @return self
     */
    public static function task($from, $to, $content, array $metadata = [])
    {
        return new self($from, $to, self::TYPE_TASK, $content, $metadata);
    }

    /**
     * 缺陷反馈
     *
     * @param string $from
     * @param string $to
     * @param string $content
     * @param array<string, mixed> $metadata
     * @return self
     */
    public static function bug($from, $to, $content, array $metadata = [])
    {
        return new self($from, $to, self::TYPE_BUG, $content, $metadata);
    }

    /**
     * 审查意见
     *
     * @param string $from
     * @param string $to
     * @param string $content
     * @param array<string, mixed> $metadata
     * @return self
     */
    public static function review($from, $to, $content, array $metadata = [])
    {
        return new self($from, $to, self::TYPE_REVIEW, $content, $metadata);
    }

    /**
     * 状态同步（默认广播）
     *
     * @param string $from
     * @param string $content
     * @param array<string, mixed> $metadata
     * @return self
     */
    public static function status($from, $content, array $metadata = [])
    {
        return new self($from, '', self::TYPE_STATUS, $content, $metadata);
    }

    /**
     * 执行结果
     *
     * @param string $from
     * @param string $to
     * @param string $content
     * @param array<string, mixed> $metadata
     * @return self
     */
    public static function result($from, $to, $content, array $metadata = [])
    {
        return new self($from, $to, self::TYPE_RESULT, $content, $metadata);
    }

    /**
     * 请求——需要对方回应
     *
     * @param string $from
     * @param string $to
     * @param string $content
     * @param array<string, mixed> $metadata
     * @return self
     */
    public static function request($from, $to, $content, array $metadata = [])
    {
        return new self($from, $to, self::TYPE_REQUEST, $content, $metadata);
    }

    /**
     * 回应某条请求
     *
     * 回应会自动带上 `reply_to`，收到的一方才能把答复对上是哪个问题——
     * 一个角色同时问了三件事时，没有这个字段就分不清了。
     *
     * @param AgentMessage $request 被回应的请求
     * @param string $content
     * @param array<string, mixed> $metadata
     * @return self
     */
    public static function respondTo(AgentMessage $request, $content, array $metadata = [])
    {
        $metadata['reply_to'] = $request->getId();
        return new self($request->getTo(), $request->getFrom(), self::TYPE_RESPONSE, $content, $metadata);
    }

    /**
     * 错误通报
     *
     * @param string $from
     * @param string $to 空串表示广播
     * @param string $content
     * @param array<string, mixed> $metadata
     * @return self
     */
    public static function error($from, $to, $content, array $metadata = [])
    {
        return new self($from, $to, self::TYPE_ERROR, $content, $metadata);
    }

    /**
     * 交接通知
     *
     * @param string $from
     * @param string $to
     * @param string $content
     * @param array<string, mixed> $metadata
     * @return self
     */
    public static function handoff($from, $to, $content, array $metadata = [])
    {
        return new self($from, $to, self::TYPE_HANDOFF, $content, $metadata);
    }

    /**
     * 这条消息在回应哪条请求
     *
     * @return string 不是回应时返回空串
     */
    public function getReplyTo()
    {
        return isset($this->metadata['reply_to']) ? (string) $this->metadata['reply_to'] : '';
    }

    /** @return string */
    public function getId()
    {
        return $this->id;
    }

    /** @return string */
    public function getFrom()
    {
        return $this->from;
    }

    /** @return string */
    public function getTo()
    {
        return $this->to;
    }

    /** @return string */
    public function getType()
    {
        return $this->type;
    }

    /** @return string */
    public function getContent()
    {
        return $this->content;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata()
    {
        return $this->metadata;
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function meta($key, $default = null)
    {
        return isset($this->metadata[$key]) ? $this->metadata[$key] : $default;
    }

    /** @return int */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /**
     * 是否广播消息
     *
     * @return bool
     */
    public function isBroadcast()
    {
        return $this->to === '';
    }

    /**
     * 转成注入给接收方的文本
     *
     * @return string
     */
    public function toPrompt()
    {
        $header = '[' . strtoupper($this->type) . ' 来自 ' . ($this->from !== '' ? $this->from : '未知') . ']';
        $text = $header . "\n" . $this->content;
        if ($this->metadata) {
            $json = json_encode($this->metadata, JSON_UNESCAPED_UNICODE);
            if ($json !== false && $json !== '[]' && $json !== '{}') {
                $text .= "\n附加信息：" . $json;
            }
        }
        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'id'         => $this->id,
            'from'       => $this->from,
            'to'         => $this->to,
            'type'       => $this->type,
            'content'    => $this->content,
            'metadata'   => $this->metadata,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * @param string $type
     * @return bool
     */
    public static function isValidType($type)
    {
        return in_array((string) $type, self::$validTypes, true);
    }

    /**
     * @return string[]
     */
    public static function validTypes()
    {
        return self::$validTypes;
    }
}
