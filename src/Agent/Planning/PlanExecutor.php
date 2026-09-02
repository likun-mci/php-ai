<?php
namespace Ai\Agent\Planning;

/**
 * PlanExecutor——执行引擎
 *
 * 按顺序或依赖图执行 Plan 中的步骤。支持：
 * - 串行执行（按步骤顺序）
 * - 依赖图执行（步骤间有依赖关系时自动等待前驱完成）
 * - 执行回调（每步完成时通知）
 * - 断点续执行（从 pending 状态继续）
 */
class PlanExecutor
{
    const MODE_SEQUENTIAL = 'sequential';
    const MODE_DEPENDENCY = 'dependency';

    /** @var PlanManager */
    protected $planManager;

    /** @var string sequential|dependency */
    protected $mode = self::MODE_DEPENDENCY;

    /** @var callable|null 每步执行前的回调 function(PlanStep, Plan): void */
    protected $beforeStep = null;

    /** @var callable|null 每步执行后的回调 function(PlanStep, Plan): void */
    protected $afterStep = null;

    /** @var int 最大重试次数 */
    protected $maxRetries = 3;

    /**
     * @param PlanManager $planManager
     * @param array<string, mixed> $options
     */
    public function __construct(PlanManager $planManager, array $options = [])
    {
        $this->planManager = $planManager;
        if (isset($options['mode'])) {
            $this->mode = $options['mode'];
        }
        if (isset($options['beforeStep'])) {
            $this->beforeStep = $options['beforeStep'];
        }
        if (isset($options['afterStep'])) {
            $this->afterStep = $options['afterStep'];
        }
        if (isset($options['maxRetries'])) {
            $this->maxRetries = $options['maxRetries'];
        }
    }

    /**
     * 执行一个步骤（由调用方提供实际执行逻辑）
     *
     * 调用方负责：
     * 1. 获取步骤
     * 2. 执行步骤（如调用工具、模型等）
     * 3. 调用此方法标记完成/失败
     *
     * @param string $planId
     * @param callable $executor function(PlanStep, Plan): string 返回执行结果
     * @return array{success: bool, step: PlanStep|null, error: string|null}
     */
    public function executeStep($planId, callable $executor)
    {
        $plan = $this->planManager->getPlan($planId);
        if (!$plan) {
            return ['success' => false, 'step' => null, 'error' => 'Plan not found: ' . $planId];
        }

        $step = $plan->getCurrentStep();
        if (!$step) {
            return ['success' => false, 'step' => null, 'error' => 'No pending step available'];
        }

        // 标记为 running
        $this->planManager->advance($planId);

        // beforeStep 回调
        if ($this->beforeStep) {
            call_user_func($this->beforeStep, $step, $plan);
        }

        $lastError = null;
        $attempts = 0;

        // 重试循环：每次重新获取 plan 以获取最新状态
        do {
            try {
                $result = call_user_func($executor, $step, $plan);
                $this->planManager->completeStep($planId, $step->getId(), $result);
                $lastError = null;
                break;
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                $attempts++;
            }
        } while ($attempts <= $this->maxRetries);

        if ($lastError !== null) {
            $this->planManager->failStep($planId, $step->getId(), $lastError);
        }

        // afterStep 回调
        if ($this->afterStep) {
            call_user_func($this->afterStep, $step, $plan);
        }

        return [
            'success' => $lastError === null,
            'step' => $step,
            'error' => $lastError,
        ];
    }

    /**
     * 跳过步骤
     *
     * @param string $planId
     * @param int|string $stepId
     * @param string $reason
     * @return array{success: bool, step: PlanStep|null, error: string|null}
     */
    public function skipStep($planId, $stepId, $reason = '')
    {
        $step = $this->planManager->skipStep($planId, $stepId, $reason);
        return [
            'success' => true,
            'step' => $step,
            'error' => null,
        ];
    }

    /**
     * 执行计划所有剩余步骤（阻塞式，每步依次执行）
     *
     * 注意：此方法在当前进程中依次执行所有步骤，适用于简单场景。
     * 复杂场景应使用 executeStep() 手动控制步骤推进。
     *
     * @param string $planId
     * @param callable $executor function(PlanStep, Plan): string
     * @return array{success: bool, completed: int, failed: int, skipped: int, error: string|null}
     */
    public function executeAll($planId, callable $executor)
    {
        $plan = $this->planManager->getPlan($planId);
        if (!$plan) {
            return ['success' => false, 'completed' => 0, 'failed' => 0, 'skipped' => 0, 'error' => 'Plan not found'];
        }

        $this->planManager->start($planId);
        $completed = 0;
        $failed = 0;
        $skipped = 0;

        while (true) {
            // 重新获取 plan（状态可能已更新）
            $plan = $this->planManager->getPlan($planId);
            if (!$plan) {
                break;
            }

            $step = $plan->getCurrentStep();
            if (!$step) {
                break;
            }

            $result = $this->executeStep($planId, $executor);
            if ($result['success']) {
                $completed++;
            } else {
                $failed++;
            }
        }

        $plan = $this->planManager->getPlan($planId);
        if ($plan && $plan->getStatus() === Plan::STATUS_RUNNING) {
            if ($failed > 0) {
                $plan->markFailed();
            } else {
                $plan->markCompleted();
            }
            $this->planManager->save($plan);
        }

        return [
            'success' => $failed === 0,
            'completed' => $completed,
            'failed' => $failed,
            'skipped' => $skipped,
            'error' => $failed > 0 ? sprintf('%d step(s) failed', $failed) : null,
        ];
    }

    /**
     * 获取计划中所有待执行步骤
     *
     * @param string $planId
     * @return PlanStep[]
     */
    public function getPendingSteps($planId)
    {
        $plan = $this->planManager->getPlan($planId);
        if (!$plan) {
            return [];
        }
        return $plan->getPendingSteps();
    }

    /**
     * @return string
     */
    public function getMode()
    {
        return $this->mode;
    }

    /**
     * @param string $mode
     * @return $this
     */
    public function setMode($mode)
    {
        $this->mode = $mode;
        return $this;
    }

    /**
     * @param callable|null $beforeStep
     * @return $this
     */
    public function setBeforeStep($beforeStep)
    {
        $this->beforeStep = $beforeStep;
        return $this;
    }

    /**
     * @param callable|null $afterStep
     * @return $this
     */
    public function setAfterStep($afterStep)
    {
        $this->afterStep = $afterStep;
        return $this;
    }
}