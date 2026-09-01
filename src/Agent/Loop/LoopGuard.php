<?php
namespace Ai\Agent\Loop;

/**
 * 循环守卫——防止 Agent 死循环
 *
 * 核心机制：对每次工具调用生成「指纹」（工具名 + 规范化参数），
 * 检测连续重复的调用。当同一工具 + 相同参数连续出现 3 次时，
 * 判定为 no_progress，触发停止。
 *
 * 典型场景：
 * ```text
 * read_file(a.php)  ← 第 1 次
 * read_file(a.php)  ← 第 2 次（重复）
 * read_file(a.php)  ← 第 3 次（触发 no_progress）
 * ```
 *
 * 模型重复调用通常意味着它在原地打转，给一个内部提示让它换思路。
 */
class LoopGuard
{
    /** @var array<int, string> 工具调用指纹历史（最近 N 条） */
    protected $history = [];

    /** @var int 连续重复触发阈值 */
    protected $maxRepeat = 3;

    /** @var int 保留历史长度 */
    protected $maxHistory = 100;

    /** @var int 连续重复计数 */
    protected $repeatCount = 0;

    /** @var string|null 上一次的工具名 */
    protected $lastName = null;

    /** @var string|null 上一次的指纹 */
    protected $lastFingerprint = null;

    /**
     * @param int $maxRepeat 连续重复多少次触发 no_progress（默认 3）
     */
    public function __construct($maxRepeat = 3)
    {
        $this->maxRepeat = max(1, (int) $maxRepeat);
    }

    /**
     * 检查一次工具调用，返回是否正常
     *
     * @param string $name 工具名
     * @param mixed $input 工具参数
     * @return array{ok: bool, reason: string} ok=false 表示应停止
     */
    public function check($name, $input)
    {
        $fingerprint = $this->fingerprint((string) $name, $input);

        // 记录历史
        $this->history[] = $fingerprint;
        if (count($this->history) > $this->maxHistory) {
            array_shift($this->history);
        }

        // 连续重复计数：与上次相同则 +1，否则重新从 1 计（首次调用也算 1 次）
        if ($fingerprint === $this->lastFingerprint) {
            $this->repeatCount++;
        } else {
            $this->repeatCount = 1;
        }
        $this->lastName = (string) $name;
        $this->lastFingerprint = $fingerprint;

        // 连续出现 maxRepeat 次相同调用 → 判定无进展
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
     * 生成工具调用指纹
     *
     * @param string $name 工具名
     * @param mixed $input 参数
     * @return string
     */
    public function fingerprint($name, $input)
    {
        return sha1($name . ':' . json_encode($input, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 获取最近一次重复的提示信息（供模型参考）
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
        return $this;
    }

    /**
     * 获取历史记录条数
     *
     * @return int
     */
    public function count()
    {
        return count($this->history);
    }
}