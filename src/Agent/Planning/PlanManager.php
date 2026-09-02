<?php
namespace Ai\Agent\Planning;

/**
 * PlanManager——规划管理器
 *
 * 统筹计划的创建、持久化、执行推进与调整。是 Planning Engine 的对外入口：
 *
 * 1. 复杂任务到来时自动生成 Plan（拆解步骤）
 * 2. 计划保存到 Task State，崩溃后可恢复
 * 3. 每个 Step 有独立状态
 * 4. 支持计划修改（PlanReview 发现偏差时调整）
 *
 * 用法：
 * ```php
 * $pm = new PlanManager('/tmp/agent_plans');
 * $plan = $pm->createPlan('修复用户登录问题', ['complexity' => 'high']);
 * $pm->start($plan->getId());
 * $step = $pm->advance($plan->getId());   // 返回待执行步骤
 * // 执行步骤后：
 * $pm->completeStep($plan->getId(), $step->getId(), '已修改 session 逻辑');
 * $pm->modifyPlan($plan->getId(), [...新步骤...], '发现原方案错误');
 * ```
 */
class PlanManager
{
    /** @var array<string, Plan> planId => Plan */
    protected $plans = [];

    /** @var string 持久化目录 */
    protected $baseDir = '';

    /** @var bool 是否开启自动持久化 */
    protected $persist = true;

    /** @var int 步骤 ID 自增计数 */
    protected $stepCounter = 0;

    /** @var array<string, array<int, array<string, mixed>>> planId => 各版本快照 */
    protected $versions = [];

    /**
     * @param string $baseDir 持久化目录，空字符串则不持久化
     * @param array<string, mixed> $options
     */
    public function __construct($baseDir = '', array $options = [])
    {
        $this->baseDir = $baseDir;
        if (isset($options['persist'])) {
            $this->persist = (bool) $options['persist'];
        }
        if ($this->baseDir !== '' && $this->persist && !is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0777, true);
        }
        $this->loadAll();
    }

    /**
     * 生成执行计划。
     *
     * 模型可经由工具调用 PlanManager 生成计划；框架内也提供基于规则的
     * 简单拆解（按步骤字符串列表），供无法调用模型时使用。
     *
     * @param string $goal
     * @param array<string, mixed> $context 上下文（可选提供 steps 预定义步骤）
     * @return Plan
     */
    public function createPlan($goal, array $context = [])
    {
        $steps = [];
        if (isset($context['steps']) && is_array($context['steps'])) {
            foreach ($context['steps'] as $i => $stepDef) {
                if ($stepDef instanceof PlanStep) {
                    $steps[] = $stepDef;
                } elseif (is_string($stepDef)) {
                    $steps[] = new PlanStep($i + 1, $stepDef);
                } elseif (is_array($stepDef)) {
                    $steps[] = PlanStep::fromArray(array_merge([
                        'id' => $i + 1,
                        'action' => '',
                    ], $stepDef));
                }
            }
        }
        $plan = new Plan($goal, $steps);
        if (isset($context['risks']) && is_array($context['risks'])) {
            $plan->setRisks($context['risks']);
        }
        $this->plans[$plan->getId()] = $plan;
        $this->save($plan);
        return $plan;
    }

    /**
     * 获取计划
     *
     * @param string $planId
     * @return Plan|null
     */
    public function getPlan($planId)
    {
        return isset($this->plans[$planId]) ? $this->plans[$planId] : null;
    }

    /**
     * @return Plan[]
     */
    public function allPlans()
    {
        return array_values($this->plans);
    }

    /**
     * 删除计划
     *
     * @param string $planId
     * @return bool
     */
    public function deletePlan($planId)
    {
        if (!isset($this->plans[$planId])) {
            return false;
        }
        unset($this->plans[$planId]);
        if ($this->persist && $this->baseDir !== '') {
            @unlink($this->fileFor($planId));
        }
        return true;
    }

    /**
     * 标记计划开始执行
     *
     * @param string $planId
     * @return Plan|null
     */
    public function start($planId)
    {
        $plan = $this->getPlan($planId);
        if (!$plan) {
            return null;
        }
        $plan->markRunning();
        $this->save($plan);
        return $plan;
    }

    /**
     * 获取当前待执行的步骤
     *
     * @param string $planId
     * @return PlanStep|null
     */
    public function getCurrentStep($planId)
    {
        $plan = $this->getPlan($planId);
        if (!$plan) {
            return null;
        }
        return $plan->getCurrentStep();
    }

    /**
     * 推进到下一个待执行步骤（返回该步骤并标记 running）
     *
     * @param string $planId
     * @return PlanStep|null
     */
    public function advance($planId)
    {
        $plan = $this->getPlan($planId);
        if (!$plan) {
            return null;
        }
        $step = $plan->getCurrentStep();
        if (!$step) {
            // 所有步骤都已终结：检查是否全部完成
            if ($plan->isComplete()) {
                $plan->markCompleted();
                $this->save($plan);
            }
            return null;
        }
        $step->markRunning();
        $this->save($plan);
        return $step;
    }

    /**
     * 标记步骤完成
     *
     * @param string $planId
     * @param int|string $stepId
     * @param string|null $result
     * @return PlanStep|null
     */
    public function completeStep($planId, $stepId, $result = null)
    {
        $plan = $this->getPlan($planId);
        if (!$plan) {
            return null;
        }
        $step = $plan->getStep($stepId);
        if (!$step) {
            return null;
        }
        $step->markCompleted($result);
        $this->save($plan);
        return $step;
    }

    /**
     * 标记步骤失败
     *
     * @param string $planId
     * @param int|string $stepId
     * @param string $error
     * @return PlanStep|null
     */
    public function failStep($planId, $stepId, $error)
    {
        $plan = $this->getPlan($planId);
        if (!$plan) {
            return null;
        }
        $step = $plan->getStep($stepId);
        if (!$step) {
            return null;
        }
        $step->markFailed($error);
        $this->save($plan);
        return $step;
    }

    /**
     * 标记步骤跳过
     *
     * @param string $planId
     * @param int|string $stepId
     * @param string|null $reason
     * @return PlanStep|null
     */
    public function skipStep($planId, $stepId, $reason = null)
    {
        $plan = $this->getPlan($planId);
        if (!$plan) {
            return null;
        }
        $step = $plan->getStep($stepId);
        if (!$step) {
            return null;
        }
        $step->markSkipped($reason);
        $this->save($plan);
        return $step;
    }

    /**
     * 修改计划——PlanReview 发现原方案错误时调整。
     *
     * 支持的操作：
     *   - ['append' => [步骤动作...]]：追加步骤
     *   - ['insert' => ['at' => 步骤id, 'step' => 动作]]：在某步骤后插入
     *   - ['replace' => ['step' => 步骤id, 'action' => 新动作]]：替换步骤内容
     *   - ['remove' => [步骤id...]]：移除步骤
     *
     * @param string $planId
     * @param array<string, mixed> $modifications
     * @param string $reason
     * @return Plan|null
     */
    public function modifyPlan($planId, array $modifications, $reason = '调整计划')
    {
        $plan = $this->getPlan($planId);
        if (!$plan) {
            return null;
        }

        // 改之前先把当前版本快照下来——旧计划不能被直接覆盖
        $this->snapshot($plan);

        $changes = [];
        $steps = $plan->getSteps();

        if (isset($modifications['append']) && is_array($modifications['append'])) {
            foreach ($modifications['append'] as $action) {
                $nextId = $this->nextStepId($plan);
                $newStep = $action instanceof PlanStep
                    ? $action
                    : new PlanStep($nextId, $action);
                $plan->addStep($newStep);
                $changes[] = ['append', (string) $newStep->getAction()];
            }
        }

        if (isset($modifications['insert']) && is_array($modifications['insert'])) {
            $at = isset($modifications['insert']['at']) ? $modifications['insert']['at'] : null;
            $insertStep = isset($modifications['insert']['step']) ? $modifications['insert']['step'] : '';
            if ($insertStep !== '') {
                $nextId = $this->nextStepId($plan);
                $newStep = $insertStep instanceof PlanStep
                    ? $insertStep
                    : new PlanStep($nextId, $insertStep);
                $index = $this->findStepIndex($plan, $at);
                array_splice($steps, $index + 1, 0, [$newStep]);
                $plan->setSteps($steps);
                $changes[] = ['insert', (string) $newStep->getAction()];
            }
        }

        if (isset($modifications['replace']) && is_array($modifications['replace'])) {
            $targetId = isset($modifications['replace']['step']) ? $modifications['replace']['step'] : null;
            $newAction = isset($modifications['replace']['action']) ? $modifications['replace']['action'] : null;
            $target = $plan->getStep($targetId);
            if ($target && $newAction) {
                $old = $target->getAction();
                $target->setAction($newAction);
                if ($target->isPending()) {
                    $target->markPending();
                }
                $changes[] = ['replace', $old . ' → ' . $newAction];
            }
        }

        if (isset($modifications['remove']) && is_array($modifications['remove'])) {
            foreach ($modifications['remove'] as $removeId) {
                $target = $plan->getStep($removeId);
                if ($target && !$target->isRunning() && !$target->isCompleted()) {
                    $steps = array_values(array_filter($steps, function (PlanStep $s) use ($removeId) {
                        return $s->getId() !== $removeId;
                    }));
                    $changes[] = ['remove', (string) $removeId];
                }
            }
            $plan->setSteps($steps);
        }

        $plan->addRevision($reason, $changes);
        $this->save($plan);
        return $plan;
    }

    /**
     * 某个计划的全部历史版本
     *
     * 修改计划前的样子会被快照下来——「原计划是什么、为什么改成现在这样」
     * 是排查 Agent 走偏的关键线索，直接覆盖就查不到了。
     *
     * @param string $planId
     * @return array<int, array<string, mixed>> 版本号 => 计划快照
     */
    public function versions($planId)
    {
        $planId = (string) $planId;
        return isset($this->versions[$planId]) ? $this->versions[$planId] : [];
    }

    /**
     * 取某个历史版本
     *
     * @param string $planId
     * @param int $version
     * @return Plan|null 该版本不存在返回 null
     */
    public function getVersion($planId, $version)
    {
        $snapshots = $this->versions((string) $planId);
        $version = (int) $version;
        if (!isset($snapshots[$version])) {
            // 当前版本不在快照里（只有被改过才快照），单独判一下
            $current = $this->getPlan($planId);
            return $current !== null && $current->getVersion() === $version ? $current : null;
        }
        return Plan::fromArray($snapshots[$version]);
    }

    /**
     * 两个版本之间改了什么
     *
     * @param string $planId
     * @param int $from
     * @param int $to
     * @return array<string, mixed> added / removed / reason
     */
    public function diffVersions($planId, $from, $to)
    {
        $planFrom = $this->getVersion($planId, $from);
        $planTo = $this->getVersion($planId, $to);
        if ($planFrom === null || $planTo === null) {
            return ['added' => [], 'removed' => [], 'reason' => ''];
        }

        $actionsOf = function (Plan $plan) {
            $actions = [];
            foreach ($plan->getSteps() as $step) {
                $actions[] = $step->getAction();
            }
            return $actions;
        };

        $before = $actionsOf($planFrom);
        $after = $actionsOf($planTo);

        $reason = '';
        foreach ($planTo->getRevisions() as $revision) {
            if (isset($revision['version']) && (int) $revision['version'] === (int) $to) {
                $reason = isset($revision['reason']) ? (string) $revision['reason'] : '';
                break;
            }
        }

        return [
            'added'   => array_values(array_diff($after, $before)),
            'removed' => array_values(array_diff($before, $after)),
            'reason'  => $reason,
        ];
    }

    /**
     * 把一个已有的 Plan 对象纳入管理（并持久化）
     *
     * 从检查点或外部反序列化出来的计划用它接管——`createPlan()` 会生成新 ID，
     * 那样恢复回来的计划就跟原来的对不上了。
     *
     * @param Plan $plan
     * @return $this
     */
    public function adopt(Plan $plan)
    {
        $id = $plan->getId();
        if ($id === '') {
            return $this;
        }
        $this->plans[$id] = $plan;
        $this->save($plan);
        return $this;
    }

    /**
     * 快照一个计划的当前版本
     *
     * @param Plan $plan
     * @return void
     */
    protected function snapshot(Plan $plan)
    {
        $planId = $plan->getId();
        if ($planId === '') {
            return;
        }
        if (!isset($this->versions[$planId])) {
            $this->versions[$planId] = [];
        }
        $this->versions[$planId][$plan->getVersion()] = $plan->toArray();
    }

    /**
     * 保存计划（持久化到 JSON 文件）
     *
     * @param Plan $plan
     * @return void
     */
    public function save(Plan $plan)
    {
        if ($this->persist && $this->baseDir !== '') {
            file_put_contents($this->fileFor($plan->getId()), $plan->toJson());
        }
    }

    /**
     * 从持久化目录加载所有计划
     *
     * @return void
     */
    protected function loadAll()
    {
        if (!$this->persist || $this->baseDir === '' || !is_dir($this->baseDir)) {
            return;
        }
        $files = glob($this->baseDir . '/plan_*.json');
        foreach ($files === false ? [] : $files as $file) {
            $json = @file_get_contents($file);
            if ($json === false) {
                continue;
            }
            $plan = Plan::fromJson($json);
            $this->plans[$plan->getId()] = $plan;
        }
    }

    /**
     * @param string $planId
     * @return string
     */
    protected function fileFor($planId)
    {
        return rtrim($this->baseDir, '/') . '/' . $planId . '.json';
    }

    /**
     * 找下一个可用步骤 ID
     *
     * @param Plan $plan
     * @return int
     */
    protected function nextStepId(Plan $plan)
    {
        $max = 0;
        foreach ($plan->getSteps() as $step) {
            $id = $step->getId();
            if (is_numeric($id) && (int) $id > $max) {
                $max = (int) $id;
            }
        }
        $max++;
        return $max;
    }

    /**
     * 查找步骤在列表中的下标
     *
     * @param Plan $plan
     * @param int|string|null $stepId
     * @return int
     */
    protected function findStepIndex(Plan $plan, $stepId)
    {
        $steps = $plan->getSteps();
        if ($stepId === null) {
            return count($steps) - 1;
        }
        foreach ($steps as $i => $step) {
            if ($step->getId() === $stepId) {
                return $i;
            }
        }
        return count($steps) - 1;
    }
}