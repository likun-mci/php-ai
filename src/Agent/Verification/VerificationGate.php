<?php
namespace Ai\Agent\Verification;

/**
 * VerificationGate——验证闸门
 *
 * 验证不该只是「跑一下某个验证器」，而应该是一道**闸门**：过了才算这一步做完，
 * 没过就把失败信息交给反思，重新规划再来一遍。
 *
 * ```text
 * Execution → Verification → PASS → 下一步
 *                         → FAIL → Reflection → Replan → 继续
 * ```
 *
 * ```php
 * $gate = new VerificationGate($verificationManager, VerificationPolicy::bugFix());
 * $outcome = $gate->check(['file_path' => 'src/Auth.php']);
 *
 * if ($outcome['passed']) {
 *     // 进入下一步
 * } else {
 *     echo $outcome['prompt'];   // 直接可以回填给模型的失败说明
 * }
 * ```
 *
 * 与直接调 `VerificationManager::verify()` 的区别：闸门按策略**有序**执行、
 * 区分必过与非必过步骤、汇总成一个能直接回填给模型的结论。
 */
class VerificationGate
{
    /** @var VerificationManager */
    protected $manager;

    /** @var VerificationPolicy */
    protected $policy;

    /** @var callable|null 事件回调 */
    protected $emit = null;

    /** @var array<int, array<string, mixed>> 历次闸门结果 */
    protected $history = [];

    /**
     * @param VerificationManager $manager
     * @param VerificationPolicy|null $policy 不给则用 basic()
     */
    public function __construct(VerificationManager $manager, $policy = null)
    {
        $this->manager = $manager;
        $this->policy = $policy instanceof VerificationPolicy ? $policy : VerificationPolicy::basic();
    }

    /**
     * 过闸门
     *
     * @param array<string, mixed> $context 验证上下文（file_path / tool_name / workdir 等）
     * @return array<string, mixed> passed / results / failed / skipped / prompt
     */
    public function check(array $context = [])
    {
        $this->event('verification_started', [
            'policy' => $this->policy->getName(),
            'steps'  => $this->policy->steps(),
        ]);

        $results = [];
        $failed = [];
        $skipped = [];
        $passed = true;

        foreach ($this->policy->steps() as $step) {
            $verifier = $this->manager->getVerifier($step);
            if ($verifier === null) {
                // 策略里写了但没挂对应验证器：记下来跳过，不让整条链卡住
                $skipped[] = $step;
                continue;
            }

            $result = $verifier->verify($context);
            $results[] = $result;

            if ($result->isPassed()) {
                continue;
            }

            $required = $this->policy->isRequired($step);
            $failed[] = [
                'step'     => $step,
                'required' => $required,
                'error'    => $result->getError(),
                'errors'   => $result->getErrors(),
            ];

            if ($required) {
                $passed = false;
                if ($this->policy->isFailFast()) {
                    break;
                }
            }
        }

        $outcome = [
            'passed'  => $passed,
            'policy'  => $this->policy->getName(),
            'results' => $results,
            'failed'  => $failed,
            'skipped' => $skipped,
            'prompt'  => $passed ? '' : $this->buildPrompt($failed),
        ];

        $this->history[] = $outcome;
        $this->event($passed ? 'verification_passed' : 'verification_failed', [
            'policy'  => $this->policy->getName(),
            'failed'  => count($failed),
            'skipped' => count($skipped),
        ]);

        return $outcome;
    }

    /**
     * 闸门是否放行（只要结论不要细节时用这个）
     *
     * @param array<string, mixed> $context
     * @return bool
     */
    public function passes(array $context = [])
    {
        $outcome = $this->check($context);
        return $outcome['passed'];
    }

    /**
     * 换一套策略
     *
     * @param VerificationPolicy $policy
     * @return $this
     */
    public function setPolicy(VerificationPolicy $policy)
    {
        $this->policy = $policy;
        return $this;
    }

    /**
     * 按任务描述自动选策略
     *
     * @param string $task
     * @return $this
     */
    public function policyForTask($task)
    {
        $this->policy = VerificationPolicy::forType(VerificationPolicy::detectType($task));
        return $this;
    }

    /** @return VerificationPolicy */
    public function getPolicy()
    {
        return $this->policy;
    }

    /**
     * 历次闸门结果
     *
     * @return array<int, array<string, mixed>>
     */
    public function history()
    {
        return $this->history;
    }

    /**
     * 最近一次结果
     *
     * @return array<string, mixed>|null
     */
    public function lastOutcome()
    {
        return $this->history ? $this->history[count($this->history) - 1] : null;
    }

    /**
     * @param callable|null $emit
     * @return $this
     */
    public function onEvent($emit)
    {
        $this->emit = $emit;
        return $this;
    }

    /**
     * 把失败信息拼成回填给模型的文本
     *
     * 带上文件与行号——只说「测试失败了」，模型只能重新猜一遍。
     *
     * @param array<int, array<string, mixed>> $failed
     * @return string
     */
    protected function buildPrompt(array $failed)
    {
        if (!$failed) {
            return '';
        }

        $lines = ['验证未通过，需要修复后重试：'];
        foreach ($failed as $item) {
            $mark = $item['required'] ? '必过' : '可选';
            $lines[] = sprintf("\n[%s·%s] %s", $item['step'], $mark, $item['error']);
            foreach (array_slice($item['errors'], 0, 10) as $error) {
                $lines[] = sprintf(
                    '  - %s:%d %s',
                    isset($error['file']) ? $error['file'] : '',
                    isset($error['line']) ? (int) $error['line'] : 0,
                    isset($error['message']) ? $error['message'] : ''
                );
            }
        }
        return implode("\n", $lines);
    }

    /**
     * @param string $type
     * @param array<string, mixed> $data
     * @return void
     */
    protected function event($type, array $data = [])
    {
        if ($this->emit !== null) {
            call_user_func($this->emit, array_merge(['type' => $type], $data));
        }
    }
}
