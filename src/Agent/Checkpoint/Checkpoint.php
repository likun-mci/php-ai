<?php
namespace Ai\Agent\Checkpoint;

/**
 * Checkpoint——检查点值对象
 *
 * 保存 Agent 运行时某一时刻的完整状态快照，用于崩溃恢复。
 * 每个 checkpoint 对应一轮迭代结束时的状态。
 *
 * Checkpoint 与 Session 的区别：
 *   - Session 是用户可见的会话概念（一个对话 = 一个 session）
 *   - Checkpoint 是崩溃恢复的内部机制（每轮一个快照）
 *
 * 用法：
 * ```php
 * $cp = new Checkpoint('task_1', [
 *     'iteration' => 5,
 *     'messages'  => [['role' => 'user', 'content' => '...']],
 * ]);
 * echo $cp->getIteration(); // 5
 * ```
 */
class Checkpoint
{
    /** @var string */
    protected $id;

    /** @var int */
    protected $iteration = 0;

    /** @var array<int, array<string, mixed>> */
    protected $messages = [];

    /** @var float */
    protected $createdAt;

    /** @var array<string, mixed> 额外状态 */
    protected $extra = [];

    /**
     * @param string $id 关联的任务 ID 或会话 ID
     * @param array<string, mixed> $data
     */
    public function __construct($id, array $data = [])
    {
        $this->id = (string) $id;
        $this->iteration = isset($data['iteration']) ? (int) $data['iteration'] : 0;
        $this->messages = isset($data['messages']) && is_array($data['messages']) ? $data['messages'] : [];
        $this->extra = isset($data['extra']) && is_array($data['extra']) ? $data['extra'] : [];
        $this->createdAt = isset($data['created_at']) ? (float) $data['created_at'] : microtime(true);
    }

    /** @return string */
    public function getId()
    {
        return $this->id;
    }

    /** @return int */
    public function getIteration()
    {
        return $this->iteration;
    }

    /** @return array<int, array<string, mixed>> */
    public function getMessages()
    {
        return $this->messages;
    }

    /** @return float */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /** @return array<string, mixed> */
    public function getExtra()
    {
        return $this->extra;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'id'        => $this->id,
            'iteration' => $this->iteration,
            'messages'  => $this->messages,
            'extra'     => $this->extra,
            'created_at' => $this->createdAt,
        ];
    }
}