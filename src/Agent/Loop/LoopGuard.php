<?php
namespace Ai\Agent\Loop;

/**
 * 循环守卫——防止 Agent 死循环
 *
 * 从「重复调用检测」升级为「进展检测」。不止检测连续重复的工具调用，
 * 还检测以下无进展模式：
 *
 *   1. 连续重复调用（相同工具 + 相同参数）→ 连续 N 次触发
 *   2. 相同错误反复出现（不同工具但返回相同错误指纹）
 *   3. 工具结果无变化（同一文件反复读取/编辑但内容不变）
 *
 * 检测到无进展时，通过 getHint() 生成提示交给模型，让模型换思路。
 */
class LoopGuard
{
    /** @var array<int, string> 工具调用指纹历史 */
    protected $history = [];

    /** @var int 连续重复触发阈值 */
    protected $maxRepeat = 3;

    /** @var int 保留历史长度 */
    protected $maxHistory = 100;

    /** @var int 连续重复计数 */
    protected $repeatCount = 0;

    /** @var string|null 上一次的工具名 */
    protected $lastName = null;

    /** @var string|null 上一次的输入指纹 */
    protected $lastFingerprint = null;

    /** @var string|null 上一次的工具结果指纹 */
    protected $lastResultFingerprint = null;

    /** @var int 相同结果连续出现次数 */
    protected $resultRepeatCount = 0;

    /** @var array<string, int> 错误计数 [error_fingerprint => count] */
    protected $errorCounts = [];

    /**
     * @param int $maxRepeat 连续重复多少次触发 no_progress（默认 3）
     */
    public function __construct($maxRepeat = 3)
    {
        $this->maxRepeat = max(1, (int) $maxRepeat);
    }

    /**
     * 检查一次工具调用（在工具执行前调用）
     *
     * @param string $name 工具名
     * @param mixed $input 工具参数
     * @return array{ok: bool, reason: string} ok=false 表示应停止
     */
    public function check($name, $input)
    {
        $fingerprint = $this->fingerprint((string) $name, $input);

        $this->history[] = $fingerprint;
        if (count($this->history) > $this->maxHistory) {
            array_shift($this->history);
        }

        if ($fingerprint === $this->lastFingerprint) {
            $this->repeatCount++;
        } else {
            $this->repeatCount = 1;
        }
        $this->lastName = (string) $name;
        $this->lastFingerprint = $fingerprint;

        if ($this->repeatCount >= $this->maxRepeat) {
            return [
                'ok'     => false,
                'reason' => 'no_progress',
            ];
        }

        return [
            'ok'     => true,
            'reason' => '',
        ];
    }

    /**
     * 检查工具结果（在工具执行后调用），检测结果层面的无进展
     *
     * @param string $name 工具名
     * @param mixed $result 工具结果（字符串或数组）
     * @return array{ok: bool, reason: string, hint: string}
     */
    public function checkResult($name, $result)
    {
        // 对结果做指纹
        $resultFingerprint = $this->fingerprint((string) $name, $result);
        $sameCount = 0;

        if ($resultFingerprint === $this->lastResultFingerprint) {
            $this->resultRepeatCount++;
            $sameCount = $this->resultRepeatCount;
        } else {
            $this->resultRepeatCount = 1;
        }
        $this->lastResultFingerprint = $resultFingerprint;

        // 连续相同结果 3 次 → 无进展
        if ($sameCount >= $this->maxRepeat) {
            return [
                'ok'     => false,
                'reason' => 'no_progress',
                'hint'   => 'The tool result has not changed for ' . $this->maxRepeat
                    . ' consecutive calls. The current approach is not making progress. '
                    . 'Try a different approach or finish the task.',
            ];
        }

        return [
            'ok'     => true,
            'reason' => '',
            'hint'   => '',
        ];
    }

    /**
     * 记录错误（跨工具的错误模式检测）
     *
     * @param string $errorFingerprint 错误指纹
     * @return array{ok: bool, reason: string, hint: string} ok=false 时建议停止
     */
    public function recordError($errorFingerprint)
    {
        if (!isset($this->errorCounts[$errorFingerprint])) {
            $this->errorCounts[$errorFingerprint] = 0;
        }
        $this->errorCounts[$errorFingerprint]++;

        // 同一错误出现 5 次以上 → 判定无进展
        if ($this->errorCounts[$errorFingerprint] >= 5) {
            return [
                'ok'     => false,
                'reason' => 'no_progress',
                'hint'   => 'The same error has occurred ' . $this->errorCounts[$errorFingerprint]
                    . ' times. The current approach is not fixing the underlying issue. '
                    . 'Try a fundamentally different approach.',
            ];
        }

        return [
            'ok'     => true,
            'reason' => '',
            'hint'   => '',
        ];
    }

    /**
     * 生成指纹
     *
     * @param string $name
     * @param mixed $value
     * @return string
     */
    public function fingerprint($name, $value)
    {
        $serialized = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        return sha1($name . ':' . $serialized);
    }

    /**
     * 获取最近一次重复的提示信息
     *
     * @return string
     */
    public function getHint()
    {
        return 'The previous tool call produced no new progress. '
             . 'Do not repeat the same call unless the input has changed. '
             . 'Choose another approach or finish the task.';
    }

    /**
     * 重置守卫状态
     *
     * @return $this
     */
    public function reset()
    {
        $this->history = [];
        $this->repeatCount = 0;
        $this->lastName = null;
        $this->lastFingerprint = null;
        $this->lastResultFingerprint = null;
        $this->resultRepeatCount = 0;
        $this->errorCounts = [];
        return $this;
    }

    /**
     * @return int
     */
    public function count()
    {
        return count($this->history);
    }
}