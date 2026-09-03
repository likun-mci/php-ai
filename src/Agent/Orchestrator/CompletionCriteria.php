<?php
namespace Ai\Agent\Orchestrator;

/**
 * CompletionCriteria——任务完成判据
 *
 * **不能因为模型说「完成了」就算完成。** 模型判断自己是否达成目标是出了名的乐观：
 * 测试还红着、计划还剩三步没做、上一次工具调用还在报错，它照样会说「已完成」。
 *
 * 判据把「完成」变成一组可检查的条件，全部满足才算数：
 *
 * ```php
 * $criteria = new CompletionCriteria(['verification_passed', 'no_pending_steps', 'no_pending_errors']);
 *
 * $outcome = $criteria->evaluate([
 *     'verification_passed' => true,
 *     'plan'                => $plan,
 *     'messages'            => $messages,
 * ]);
 *
 * $outcome['completed'];    // false
 * $outcome['unmet'];        // ['no_pending_steps']
 * $outcome['prompt'];       // '计划里还有 2 个步骤未完成：…'
 * ```
 *
 * 内置四条判据，也可以注册自定义判据（`addCriterion()`）——不同项目对「做完了」的
 * 定义不一样，硬编码一套是不够的。
 */
class CompletionCriteria
{
    /** 验证闸门已通过 */
    const VERIFICATION_PASSED = 'verification_passed';

    /** 计划里没有未完成的步骤 */
    const NO_PENDING_STEPS = 'no_pending_steps';

    /** 最近的工具结果里没有报错 */
    const NO_PENDING_ERRORS = 'no_pending_errors';

    /** 模型自己声明完成（弱判据，单独使用没有意义） */
    const MODEL_CLAIMS_DONE = 'model_claims_done';

    /** @var string[] 要检查的判据 */
    protected $required = [];

    /** @var array<string, callable> 自定义判据 */
    protected $custom = [];

    /**
     * @param string[] $required 不传则用默认三条（不含 model_claims_done）
     */
    public function __construct(array $required = [])
    {
        $this->required = $required ? array_values(array_map('strval', $required)) : [
            self::VERIFICATION_PASSED,
            self::NO_PENDING_STEPS,
            self::NO_PENDING_ERRORS,
        ];
    }

    /**
     * 宽松判据：只要没有明显的未完成信号就算完成
     *
     * 「宽松」指的是不要求验证通过——没挂验证器的轻量任务，
     * 要求 `verification_passed` 会永远达不成。
     *
     * 但**计划里还有没做完的步骤**不属于「宽松可以忽略」的范畴：那是模型自己
     * 写下的待办，还剩着就说明活没干完。这条判据在没有计划时自动满足
     * （见 `checkPlan()`），所以放进宽松档不会误伤无计划的任务——
     * 原先把它排除在外，等于模型用 `update_plan` 写的计划从来没人核对过：
     * 留着三个 pending 步骤直接收工，判据照样说「完成」。
     *
     * @return self
     */
    public static function lenient()
    {
        return new self([self::NO_PENDING_ERRORS, self::NO_PENDING_STEPS]);
    }

    /**
     * 严格判据：验证 + 计划 + 无错误 + 模型确认
     *
     * @return self
     */
    public static function strict()
    {
        return new self([
            self::VERIFICATION_PASSED,
            self::NO_PENDING_STEPS,
            self::NO_PENDING_ERRORS,
            self::MODEL_CLAIMS_DONE,
        ]);
    }

    /**
     * 注册自定义判据
     *
     * @param string $name
     * @param callable $checker function(array $context): bool|array{met: bool, reason: string}
     * @return $this
     */
    public function addCriterion($name, callable $checker)
    {
        $name = (string) $name;
        if ($name === '') {
            return $this;
        }
        $this->custom[$name] = $checker;
        if (!in_array($name, $this->required, true)) {
            $this->required[] = $name;
        }
        return $this;
    }

    /**
     * 去掉一条判据
     *
     * @param string $name
     * @return $this
     */
    public function remove($name)
    {
        $name = (string) $name;
        $this->required = array_values(array_filter($this->required, function ($item) use ($name) {
            return $item !== $name;
        }));
        unset($this->custom[$name]);
        return $this;
    }

    /**
     * 评估是否达成完成条件
     *
     * @param array<string, mixed> $context verification_passed / plan / messages / errors
     * @return array<string, mixed> completed / met / unmet / reasons / prompt
     */
    public function evaluate(array $context = [])
    {
        $met = [];
        $unmet = [];
        $reasons = [];

        foreach ($this->required as $name) {
            $check = $this->checkOne($name, $context);
            if ($check['met']) {
                $met[] = $name;
                continue;
            }
            $unmet[] = $name;
            if ($check['reason'] !== '') {
                $reasons[$name] = $check['reason'];
            }
        }

        return [
            'completed' => $unmet === [],
            'met'       => $met,
            'unmet'     => $unmet,
            'reasons'   => $reasons,
            'prompt'    => $unmet ? $this->buildPrompt($reasons, $unmet) : '',
        ];
    }

    /**
     * 是否达成（只要结论）
     *
     * @param array<string, mixed> $context
     * @return bool
     */
    public function isMet(array $context = [])
    {
        $outcome = $this->evaluate($context);
        return $outcome['completed'];
    }

    /**
     * 全部判据名
     *
     * @return string[]
     */
    public function required()
    {
        return $this->required;
    }

    /**
     * 检查单条判据
     *
     * @param string $name
     * @param array<string, mixed> $context
     * @return array{met: bool, reason: string}
     */
    protected function checkOne($name, array $context)
    {
        if (isset($this->custom[$name])) {
            $result = call_user_func($this->custom[$name], $context);
            if (is_array($result)) {
                return [
                    'met'    => !empty($result['met']),
                    'reason' => isset($result['reason']) ? (string) $result['reason'] : '',
                ];
            }
            return ['met' => (bool) $result, 'reason' => $result ? '' : $name . ' 未满足'];
        }

        switch ($name) {
            case self::VERIFICATION_PASSED:
                // 没跑过验证 = 判据未满足；说"没验证所以算通过"就等于没有这道闸门
                if (!array_key_exists('verification_passed', $context)) {
                    return ['met' => false, 'reason' => '尚未执行验证'];
                }
                return empty($context['verification_passed'])
                    ? ['met' => false, 'reason' => '验证未通过']
                    : ['met' => true, 'reason' => ''];

            case self::NO_PENDING_STEPS:
                return $this->checkPlan($context);

            case self::NO_PENDING_ERRORS:
                return $this->checkErrors($context);

            case self::MODEL_CLAIMS_DONE:
                return !empty($context['model_claims_done'])
                    ? ['met' => true, 'reason' => '']
                    : ['met' => false, 'reason' => '模型尚未声明完成'];
        }

        return ['met' => false, 'reason' => '未知判据：' . $name];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{met: bool, reason: string}
     */
    protected function checkPlan(array $context)
    {
        if (!isset($context['plan']) || !is_object($context['plan'])) {
            // 没有计划就没有"未完成的步骤"，这条判据自动满足
            return ['met' => true, 'reason' => ''];
        }

        $plan = $context['plan'];
        if (!method_exists($plan, 'getPendingSteps')) {
            return ['met' => true, 'reason' => ''];
        }

        $pending = $plan->getPendingSteps();
        if (!$pending) {
            return ['met' => true, 'reason' => ''];
        }

        $names = [];
        foreach (array_slice($pending, 0, 5) as $step) {
            $names[] = is_object($step) && method_exists($step, 'getAction') ? (string) $step->getAction() : '';
        }
        return [
            'met'    => false,
            'reason' => sprintf('计划里还有 %d 个步骤未完成：%s', count($pending), implode('、', $names)),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{met: bool, reason: string}
     */
    protected function checkErrors(array $context)
    {
        if (!empty($context['errors']) && is_array($context['errors'])) {
            return [
                'met'    => false,
                'reason' => sprintf('还有 %d 条未处理的错误', count($context['errors'])),
            ];
        }

        // 从消息历史里看最后一批工具结果有没有报错
        if (isset($context['messages']) && is_array($context['messages'])) {
            $errorText = $this->lastToolError($context['messages']);
            if ($errorText !== '') {
                return ['met' => false, 'reason' => '最近一次工具执行报错：' . $errorText];
            }
        }
        return ['met' => true, 'reason' => ''];
    }

    /**
     * 找最后一批工具结果里的错误
     *
     * @param array<int, array<string, mixed>> $messages
     * @return string 没有错误返回空串
     */
    protected function lastToolError(array $messages)
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];
            if (!isset($message['content']) || !is_array($message['content'])) {
                continue;
            }
            foreach ($message['content'] as $block) {
                if (!is_array($block) || !isset($block['type']) || $block['type'] !== 'tool_result') {
                    continue;
                }
                if (!empty($block['is_error'])) {
                    $text = isset($block['content']) ? (string) $block['content'] : '';
                    return function_exists('mb_substr')
                        ? mb_substr($text, 0, 200, 'UTF-8')
                        : substr($text, 0, 200);
                }
            }
            // 只看最后一批工具结果，更早的错误可能已经被修掉了
            return '';
        }
        return '';
    }

    /**
     * 拼出「为什么还不算完成」的说明
     *
     * @param array<string, string> $reasons
     * @param string[] $unmet
     * @return string
     */
    protected function buildPrompt(array $reasons, array $unmet)
    {
        $lines = ['任务尚未达成完成条件：'];
        foreach ($unmet as $name) {
            $lines[] = '- ' . (isset($reasons[$name]) ? $reasons[$name] : $name);
        }
        $lines[] = '请继续处理上述问题。';
        return implode("\n", $lines);
    }
}
