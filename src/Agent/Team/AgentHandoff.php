<?php
namespace Ai\Agent\Team;

/**
 * AgentHandoff——任务交接记录
 *
 * Coder 改到一半发现是数据库结构的问题，把任务转给 DatabaseAgent；后者处理完再转回来。
 * 交接必须留痕：谁交给谁、为什么交、交的时候进展到哪了——否则一个任务在几个角色之间
 * 转了几圈之后，没人说得清它到底经历过什么。
 *
 * ```php
 * $handoff = new AgentHandoff('coder', 'dba', 'task_1', '发现是索引缺失导致的慢查询', [
 *     'context_summary' => '已定位到 UserRepo::findByEmail，全表扫描 12 万行',
 *     'files'           => ['src/Repo/UserRepo.php'],
 * ]);
 * echo $handoff->toPrompt();   // 交给目标 Agent 的说明
 * ```
 */
class AgentHandoff
{
    /** @var string 交接记录 ID */
    protected $id = '';

    /** @var string 交出方 */
    protected $sourceAgent = '';

    /** @var string 接手方 */
    protected $targetAgent = '';

    /** @var string 关联任务 ID */
    protected $taskId = '';

    /** @var string 交接原因 */
    protected $reason = '';

    /** @var string 交接时的进展摘要 */
    protected $contextSummary = '';

    /** @var array<string, mixed> 附加数据 */
    protected $metadata = [];

    /** @var int 交接时间 */
    protected $createdAt = 0;

    /** @var string 交接是否已被接手：pending|accepted|returned */
    protected $status = self::STATUS_PENDING;

    const STATUS_PENDING  = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_RETURNED = 'returned';

    /**
     * @param string $sourceAgent
     * @param string $targetAgent
     * @param string $taskId
     * @param string $reason
     * @param array<string, mixed> $options context_summary / metadata
     */
    public function __construct($sourceAgent, $targetAgent, $taskId, $reason = '', array $options = [])
    {
        $this->sourceAgent = (string) $sourceAgent;
        $this->targetAgent = (string) $targetAgent;
        $this->taskId = (string) $taskId;
        $this->reason = (string) $reason;
        $this->createdAt = time();
        $this->id = 'ho_' . substr(md5(uniqid('', true)), 0, 12);

        if (isset($options['context_summary'])) {
            $this->contextSummary = (string) $options['context_summary'];
        }
        if (isset($options['metadata']) && is_array($options['metadata'])) {
            $this->metadata = $options['metadata'];
        }
        // 除 context_summary / metadata 外的其余键一并收进 metadata，调用方不必先包一层
        foreach ($options as $key => $value) {
            if (!in_array($key, ['context_summary', 'metadata'], true)) {
                $this->metadata[$key] = $value;
            }
        }
        if (isset($options['status'])) {
            $this->status = (string) $options['status'];
        }
    }

    /** @return string */
    public function getId()
    {
        return $this->id;
    }

    /** @return string */
    public function getSourceAgent()
    {
        return $this->sourceAgent;
    }

    /** @return string */
    public function getTargetAgent()
    {
        return $this->targetAgent;
    }

    /** @return string */
    public function getTaskId()
    {
        return $this->taskId;
    }

    /** @return string */
    public function getReason()
    {
        return $this->reason;
    }

    /** @return string */
    public function getContextSummary()
    {
        return $this->contextSummary;
    }

    /**
     * @param string $summary
     * @return $this
     */
    public function setContextSummary($summary)
    {
        $this->contextSummary = (string) $summary;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata()
    {
        return $this->metadata;
    }

    /** @return int */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /** @return string */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * 标记已被接手
     *
     * @return $this
     */
    public function accept()
    {
        $this->status = self::STATUS_ACCEPTED;
        return $this;
    }

    /**
     * 标记已交回给原交出方
     *
     * @return $this
     */
    public function markReturned()
    {
        $this->status = self::STATUS_RETURNED;
        return $this;
    }

    /**
     * 生成交回的反向交接
     *
     * @param string $reason
     * @param string $contextSummary
     * @return self
     */
    public function reverse($reason = '', $contextSummary = '')
    {
        $this->markReturned();
        return new self($this->targetAgent, $this->sourceAgent, $this->taskId, $reason, [
            'context_summary' => $contextSummary,
            'metadata'        => ['returned_from' => $this->id],
        ]);
    }

    /**
     * 交给目标 Agent 的说明文本
     *
     * @return string
     */
    public function toPrompt()
    {
        $lines = [];
        $lines[] = "[交接] {$this->sourceAgent} → {$this->targetAgent}";
        if ($this->reason !== '') {
            $lines[] = '原因：' . $this->reason;
        }
        if ($this->contextSummary !== '') {
            $lines[] = "当前进展：\n" . $this->contextSummary;
        }
        if ($this->taskId !== '') {
            $lines[] = '任务 ID：' . $this->taskId;
        }
        return implode("\n", $lines);
    }

    /**
     * 转成可投递的消息
     *
     * @return AgentMessage
     */
    public function toMessage()
    {
        return AgentMessage::handoff(
            $this->sourceAgent,
            $this->targetAgent,
            $this->toPrompt(),
            array_merge($this->metadata, [
                'handoff_id'      => $this->id,
                'task_id'         => $this->taskId,
                'reason'          => $this->reason,
                'context_summary' => $this->contextSummary,
            ])
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'id'              => $this->id,
            'source_agent'    => $this->sourceAgent,
            'target_agent'    => $this->targetAgent,
            'task_id'         => $this->taskId,
            'reason'          => $this->reason,
            'context_summary' => $this->contextSummary,
            'metadata'        => $this->metadata,
            'status'          => $this->status,
            'created_at'      => $this->createdAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data)
    {
        $handoff = new self(
            isset($data['source_agent']) ? $data['source_agent'] : '',
            isset($data['target_agent']) ? $data['target_agent'] : '',
            isset($data['task_id']) ? $data['task_id'] : '',
            isset($data['reason']) ? $data['reason'] : '',
            [
                'context_summary' => isset($data['context_summary']) ? $data['context_summary'] : '',
                'metadata'        => isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [],
                'status'          => isset($data['status']) ? $data['status'] : self::STATUS_PENDING,
            ]
        );
        if (isset($data['id'])) {
            $handoff->id = (string) $data['id'];
        }
        if (isset($data['created_at'])) {
            $handoff->createdAt = (int) $data['created_at'];
        }
        return $handoff;
    }
}
