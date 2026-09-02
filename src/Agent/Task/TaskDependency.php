<?php
namespace Ai\Agent\Task;

/**
 * TaskDependency——一条任务依赖边
 *
 * 「D 必须等 C 完成」。父子关系（`parentTaskId`）表达的是从属，依赖表达的是**顺序**——
 * 两个兄弟任务可以同属一个父任务，却一个必须等另一个跑完。这两件事必须分开表达，
 * 否则「B 与 C 并行、D 等 C」这种结构根本写不出来。
 *
 * ```php
 * $dep = new TaskDependency('task_d', 'task_c');           // D 依赖 C
 * $dep = new TaskDependency('task_d', 'task_c', TaskDependency::TYPE_SOFT);
 * ```
 */
class TaskDependency
{
    /** 硬依赖：被依赖任务失败 → 本任务不再执行 */
    const TYPE_HARD = 'hard';

    /** 软依赖：只约束顺序，被依赖任务失败也照跑 */
    const TYPE_SOFT = 'soft';

    /** @var string 本任务 ID */
    protected $taskId = '';

    /** @var string 被依赖的任务 ID */
    protected $dependsOn = '';

    /** @var string hard|soft */
    protected $type = self::TYPE_HARD;

    /** @var string 依赖说明 */
    protected $reason = '';

    /**
     * @param string $taskId
     * @param string $dependsOn
     * @param string $type
     * @param string $reason
     */
    public function __construct($taskId, $dependsOn, $type = self::TYPE_HARD, $reason = '')
    {
        $this->taskId = (string) $taskId;
        $this->dependsOn = (string) $dependsOn;
        $this->type = $type === self::TYPE_SOFT ? self::TYPE_SOFT : self::TYPE_HARD;
        $this->reason = (string) $reason;
    }

    /** @return string */
    public function getTaskId()
    {
        return $this->taskId;
    }

    /** @return string */
    public function getDependsOn()
    {
        return $this->dependsOn;
    }

    /** @return string */
    public function getType()
    {
        return $this->type;
    }

    /** @return bool */
    public function isHard()
    {
        return $this->type === self::TYPE_HARD;
    }

    /** @return string */
    public function getReason()
    {
        return $this->reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'task_id'    => $this->taskId,
            'depends_on' => $this->dependsOn,
            'type'       => $this->type,
            'reason'     => $this->reason,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data)
    {
        return new self(
            isset($data['task_id']) ? $data['task_id'] : '',
            isset($data['depends_on']) ? $data['depends_on'] : '',
            isset($data['type']) ? $data['type'] : self::TYPE_HARD,
            isset($data['reason']) ? $data['reason'] : ''
        );
    }
}
