<?php
namespace Ai\Agent\Planning;

/**
 * Plan——任务执行计划值对象
 *
 * 承载 Agent 对复杂任务的拆解：目标、步骤列表、整体状态、风险评估与依赖关系。
 * 计划会被持久化到 Task State，崩溃恢复后可以继续执行。
 */
class Plan
{
    const STATUS_PENDING   = 'pending';
    const STATUS_RUNNING   = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';

    /** @var string */
    protected $id = '';

    /** @var string */
    protected $goal;

    /** @var PlanStep[] */
    protected $steps = [];

    /** @var string */
    protected $status = self::STATUS_PENDING;

    /** @var array<int, array<string, mixed>> */
    protected $risks = [];

    /** @var array<int|string, array<int, int|string>> 依赖关系 stepId => [stepId...] */
    protected $dependencies = [];

    /** @var array<int, array<string, mixed>> 计划修订历史 */
    protected $revisions = [];

    /** @var string|null */
    protected $createdAt = null;

    /** @var string|null */
    protected $updatedAt = null;

    /**
     * @param string $goal
     * @param PlanStep[] $steps
     */
    public function __construct($goal, array $steps = [])
    {
        $this->id = self::generateId();
        $this->goal = $goal;
        $this->createdAt = self::now();
        $this->updatedAt = $this->createdAt;
        foreach ($steps as $step) {
            $this->addStep($step);
        }
    }

    /**
     * 生成计划 ID
     *
     * @return string
     */
    public static function generateId()
    {
        $raw = preg_replace('/[^a-zA-Z0-9]/', '', uniqid('', true));
        return 'plan_' . substr((string) $raw, 0, 16);
    }

    /**
     * @return string
     */
    protected static function now()
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param string $id
     * @return $this
     */
    public function setId($id)
    {
        $this->id = (string) $id;
        return $this;
    }

    /**
     * @return string
     */
    public function getGoal()
    {
        return $this->goal;
    }

    /**
     * @return string
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @return PlanStep[]
     */
    public function getSteps()
    {
        return $this->steps;
    }

    /**
     * 设置步骤列表（替换）
     *
     * @param PlanStep[] $steps
     * @return $this
     */
    public function setSteps(array $steps)
    {
        $this->steps = $steps;
        $this->touch();
        return $this;
    }

    /**
     * @return PlanStep[]
     */
    public function getPendingSteps()
    {
        return array_values(array_filter($this->steps, function (PlanStep $step) {
            return $step->isPending();
        }));
    }

    /**
     * @return PlanStep[]
     */
    public function getCompletedSteps()
    {
        return array_values(array_filter($this->steps, function (PlanStep $step) {
            return $step->isCompleted();
        }));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRisks()
    {
        return $this->risks;
    }

    /**
     * @return array<int|string, array<int, int|string>>
     */
    public function getDependencies()
    {
        return $this->dependencies;
    }

    /**
     * 添加步骤
     *
     * @param PlanStep $step
     * @return $this
     */
    public function addStep(PlanStep $step)
    {
        $this->steps[] = $step;
        $deps = $step->getDependencies();
        if ($deps) {
            $this->dependencies[$step->getId()] = $deps;
        }
        $this->touch();
        return $this;
    }

    /**
     * @param int|string $stepId
     * @return PlanStep|null
     */
    public function getStep($stepId)
    {
        foreach ($this->steps as $step) {
            if ($step->getId() === $stepId) {
                return $step;
            }
        }
        return null;
    }

    /**
     * 当前应执行的步骤（第一个 pending 且依赖已满足的）
     *
     * @return PlanStep|null
     */
    public function getCurrentStep()
    {
        $completed = $this->getCompletedStepIds();
        foreach ($this->steps as $step) {
            if ($step->isReady($completed)) {
                return $step;
            }
        }
        return null;
    }

    /**
     * @return array<int, int|string>
     */
    public function getCompletedStepIds()
    {
        return array_map(function (PlanStep $step) {
            return $step->getId();
        }, $this->getCompletedSteps());
    }

    /**
     * 添加风险
     *
     * @param array<string, mixed>|string $risk
     * @return $this
     */
    public function addRisk($risk)
    {
        $this->risks[] = is_array($risk) ? $risk : ['description' => $risk];
        $this->touch();
        return $this;
    }

    /**
     * @param array<int, array<string, mixed>> $risks
     * @return $this
     */
    public function setRisks(array $risks)
    {
        $this->risks = $risks;
        $this->touch();
        return $this;
    }

    /**
     * 标记计划开始执行
     *
     * @return $this
     */
    public function markRunning()
    {
        $this->status = self::STATUS_RUNNING;
        $this->touch();
        return $this;
    }

    /**
     * 标记计划完成
     *
     * @return $this
     */
    public function markCompleted()
    {
        $this->status = self::STATUS_COMPLETED;
        $this->touch();
        return $this;
    }

    /**
     * 标记计划失败
     *
     * @return $this
     */
    public function markFailed()
    {
        $this->status = self::STATUS_FAILED;
        $this->touch();
        return $this;
    }

    /**
     * 记录一次计划修订（PlanReview 调用）
     *
     * @param string $reason
     * @param array<int|string, mixed> $changes 修订内容摘要
     * @return $this
     */
    public function addRevision($reason, array $changes = [])
    {
        $this->revisions[] = [
            'time' => self::now(),
            'reason' => $reason,
            'changes' => $changes,
        ];
        $this->touch();
        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRevisions()
    {
        return $this->revisions;
    }

    /**
     * 整体进度（0-100）
     *
     * @return int
     */
    public function progress()
    {
        if (!$this->steps) {
            return 0;
        }
        $done = count(array_filter($this->steps, function (PlanStep $step) {
            return $step->isCompleted();
        }));
        $failed = count(array_filter($this->steps, function (PlanStep $step) {
            return $step->isFailed();
        }));
        $skipped = count(array_filter($this->steps, function (PlanStep $step) {
            return $step->isSkipped();
        }));
        return (int) round(($done + $failed + $skipped) / count($this->steps) * 100);
    }

    /**
     * @return bool 是否所有步骤都完成
     */
    public function isComplete()
    {
        if (!$this->steps) {
            return true;
        }
        foreach ($this->steps as $step) {
            if (!$step->isTerminal()) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        $steps = [];
        foreach ($this->steps as $step) {
            $steps[] = $step->toArray();
        }
        return [
            'id' => $this->id,
            'goal' => $this->goal,
            'status' => $this->status,
            'steps' => $steps,
            'risks' => $this->risks,
            'dependencies' => $this->dependencies,
            'revisions' => $this->revisions,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data)
    {
        $steps = [];
        if (isset($data['steps']) && is_array($data['steps'])) {
            foreach ($data['steps'] as $stepData) {
                $steps[] = PlanStep::fromArray($stepData);
            }
        }
        $plan = new self(isset($data['goal']) ? $data['goal'] : '', $steps);
        if (isset($data['id'])) {
            $plan->setId($data['id']);
        }
        if (isset($data['status'])) {
            $plan->status = $data['status'];
        }
        if (isset($data['risks'])) {
            $plan->risks = $data['risks'];
        }
        if (isset($data['dependencies']) && is_array($data['dependencies'])) {
            $plan->dependencies = $data['dependencies'];
        }
        if (isset($data['revisions'])) {
            $plan->revisions = $data['revisions'];
        }
        if (isset($data['created_at'])) {
            $plan->createdAt = $data['created_at'];
        }
        if (isset($data['updated_at'])) {
            $plan->updatedAt = $data['updated_at'];
        }
        return $plan;
    }

    /**
     * @return string
     */
    public function toJson()
    {
        $json = json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
        return $json === false ? '{}' : $json;
    }

    /**
     * @param string $json
     * @return self
     */
    public static function fromJson($json)
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $data = [];
        }
        return self::fromArray($data);
    }

    /**
     * 简明摘要用于注入 system prompt
     *
     * @return string
     */
    public function toSummary()
    {
        $lines = [];
        $lines[] = sprintf('Plan #%s — %s (%d%%)', $this->id, $this->goal, $this->progress());
        foreach ($this->steps as $step) {
            $lines[] = sprintf(
                '  [%s] #%s %s',
                strtoupper($step->getStatus()),
                $step->getId(),
                $step->getAction()
            );
        }
        return implode("\n", $lines);
    }

    /**
     * 更新时间戳
     *
     * @return void
     */
    protected function touch()
    {
        $this->updatedAt = self::now();
    }
}