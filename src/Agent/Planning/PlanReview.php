<?php
namespace Ai\Agent\Planning;

/**
 * PlanReview——计划审查
 *
 * 在计划执行过程中检查是否产生偏差，发现原方案错误时生成修改建议。
 * 配合 PlanManager::modifyPlan() 实现计划的动态调整。
 *
 * 审查维度：
 * - 目标完成度检查：所有步骤完成但目标未达成 → 需要追加/调整步骤
 * - 步骤失败分析：某一步失败 → 建议重试、替换方案或跳过
 * - 阻塞检测：步骤间依赖形成环或前驱失败 → 建议调整依赖
 * - 归纳总结：执行结果与期望不符 → 建议修改步骤
 */
class PlanReview
{
    const VERDICT_OK      = 'ok';
    const VERDICT_AFFECTED = 'affected';
    const VERDICT_FAILED  = 'failed';

    /** @var PlanManager */
    protected $planManager;

    /** @var callable|null 生成步骤建议的策略 function(PlanStep, Plan): array */
    protected $strategy = null;

    /**
     * @param PlanManager $planManager
     * @param array<string, mixed> $options
     */
    public function __construct(PlanManager $planManager, array $options = [])
    {
        $this->planManager = $planManager;
        if (isset($options['strategy'])) {
            $this->strategy = $options['strategy'];
        }
    }

    /**
     * 审查计划状态，返回是否正常、需要修改的建议。
     *
     * @param string $planId
     * @return array{
     *   status: string,
     *   progress: int,
     *   issues: string[],
     *   suggestions: string[],
     *   recommendations: string[]
     * }
     */
    public function review($planId)
    {
        $plan = $this->planManager->getPlan($planId);
        if (!$plan) {
            return [
                'status' => 'not_found',
                'progress' => 0,
                'issues' => ['计划不存在: ' . $planId],
                'suggestions' => [],
                'recommendations' => [],
            ];
        }

        $issues = [];
        $suggestions = [];
        $recommendations = [];

        $steps = $plan->getSteps();
        $failedSteps = [];
        $pendingSteps = $plan->getPendingSteps();
        $completedSteps = $plan->getCompletedSteps();

        // 1. 检查失败步骤
        foreach ($steps as $step) {
            if ($step->isFailed()) {
                $failedSteps[] = $step;
                $issues[] = sprintf(
                    '步骤 #%s「%s」失败: %s',
                    $step->getId(),
                    $step->getAction(),
                    $step->getError()
                );
            }
        }

        // 2. 目标已完成但整体计划未标记完成
        if ($plan->isComplete() && $plan->getStatus() !== Plan::STATUS_COMPLETED) {
            $plan->markCompleted();
            $this->planManager->save($plan);
        }

        // 3. 计划未完成（仍有待执行步骤）但没有任何进行中的动作
        if ($pendingSteps && $plan->getStatus() !== Plan::STATUS_RUNNING) {
            $plan->markRunning();
            $this->planManager->save($plan);
        }

        // 4. 失败步骤建议
        if ($failedSteps) {
            $suggestions[] = [
                'type' => 'retry_or_replace',
                'message' => '存在失败步骤，建议重试、调整方案或跳过：' .
                    implode(', ', array_map(function ($s) {
                        return '#' . $s->getId() . ' ' . $s->getAction();
                    }, $failedSteps)),
                'step_ids' => array_map(function ($s) {
                    return $s->getId();
                }, $failedSteps),
            ];
            $recommendations[] = 'retry';
        }

        // 5. 依赖环检测
        $cycle = $this->detectDependencyCycle($plan);
        if ($cycle) {
            $issues[] = '依赖关系形成环: ' . implode(' → ', $cycle);
            $suggestions[] = [
                'type' => 'fix_dependency',
                'message' => '请调整依赖关系，消除环',
                'cycle' => $cycle,
            ];
            $recommendations[] = 'fix_dependencies';
        }

        // 6. 策略回调：附加建议
        if ($this->strategy) {
            $extra = call_user_func($this->strategy, $plan, $this->planManager);
            if (is_array($extra)) {
                if (isset($extra['issues'])) {
                    $issues = array_merge($issues, $extra['issues']);
                }
                if (isset($extra['suggestions'])) {
                    $suggestions = array_merge($suggestions, $extra['suggestions']);
                }
                if (isset($extra['recommendations'])) {
                    $recommendations = array_merge($recommendations, $extra['recommendations']);
                }
            }
        }

        $status = $issues ? self::VERDICT_AFFECTED : self::VERDICT_OK;

        return [
            'status' => $status,
            'progress' => $plan->progress(),
            'issues' => $issues,
            'suggestions' => $suggestions,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * 执行审查并自动应用建议（若有）
     *
     * @param string $planId
     * @param array<string, mixed> $modifications
     * @param string|null $reason
     * @return array<string, mixed> 审查结果 + 修改后的计划
     */
    public function reviewAndAdjust($planId, array $modifications = [], $reason = null)
    {
        $result = $this->review($planId);

        if ($modifications) {
            $reason = $reason ?: '自动调整计划';
            $this->planManager->modifyPlan($planId, $modifications, $reason);
            $result['modified'] = true;
            $result['plan'] = $this->planManager->getPlan($planId);
        } else {
            $result['modified'] = false;
        }

        return $result;
    }

    /**
     * 检测依赖环
     *
     * @param Plan $plan
     * @return array<int, int|string> 环上的步骤 ID 序列，无环返回空数组
     */
    public function detectDependencyCycle(Plan $plan)
    {
        $deps = $plan->getDependencies();
        $visited = [];
        $stack = [];

        foreach ($deps as $stepId => $dependencies) {
            if (!$this->hasVisited($visited, $stepId)) {
                $cycle = $this->dfs($stepId, $deps, $visited, $stack);
                if ($cycle) {
                    return $cycle;
                }
            }
        }
        return [];
    }

    /**
     * DFS 检测环
     *
     * @param int|string $node
     * @param array<int|string, array<int, int|string>> $graph
     * @param array<int, int|string> $visited
     * @param array<int, int|string> $stack
     * @return array<int, int|string>
     */
    protected function dfs($node, array $graph, array &$visited, array &$stack)
    {
        $visited[] = $node;
        $stack[] = $node;

        $neighbors = isset($graph[$node]) ? $graph[$node] : [];
        foreach ($neighbors as $neighbor) {
            $start = array_search($neighbor, $stack, true);
            if ($start !== false) {
                // 找到环
                return array_slice($stack, (int) $start);
            }
            if (!$this->hasVisited($visited, $neighbor)) {
                $cycle = $this->dfs($neighbor, $graph, $visited, $stack);
                if ($cycle) {
                    return $cycle;
                }
            }
        }

        array_pop($stack);
        return [];
    }

    /**
     * @param array<int, int|string> $visited
     * @param int|string $node
     * @return bool
     */
    protected function hasVisited(array $visited, $node)
    {
        return in_array($node, $visited, true);
    }
}