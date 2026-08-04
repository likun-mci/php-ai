<?php
namespace Ai\Cli;

use Ai\Response\AIResponse;

/**
 * Claude Code CLI 响应对象
 *
 * 在 AIResponse 基础上补充 CLI 调用特有的信息：
 * 会话 ID（--resume 续接用）、CLI 实测费用（result 事件的 total_cost_usd）、
 * 轮数、耗时、退出码与最终命令。
 */
class ClaudeCodeResponse extends AIResponse
{
    /** @var string|null 会话 ID，可传给下一轮 --resume */
    protected $sessionId;

    /** @var float CLI 实测费用（USD，取自 result 事件 total_cost_usd） */
    protected $costUsd;

    /** @var int 本轮执行轮数 */
    protected $numTurns;

    /** @var int 本轮执行耗时（毫秒） */
    protected $durationMs;

    /** @var string 实际执行的命令 */
    protected $command;

    /** @var int 进程退出码（-1 表示未知） */
    protected $exitCode;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->sessionId = $data['session_id'] ?? null;
        $this->costUsd   = (float) ($data['cost_usd'] ?? 0);
        $this->numTurns  = (int) ($data['num_turns'] ?? 0);
        $this->durationMs = (int) ($data['duration_ms'] ?? 0);
        $this->command   = (string) ($data['command'] ?? '');
        $this->exitCode  = (int) ($data['exit_code'] ?? -1);
    }

    /**
     * 获取会话 ID（供下一轮 --resume 续接）
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * 获取 CLI 实测费用（USD）
     */
    public function getCostUsd(): float
    {
        return $this->costUsd;
    }

    /**
     * 获取本轮执行轮数
     */
    public function getNumTurns(): int
    {
        return $this->numTurns;
    }

    /**
     * 获取本轮执行耗时（毫秒）
     */
    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    /**
     * 获取实际执行的命令
     */
    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * 获取进程退出码
     */
    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    /**
     * 费用：优先返回 CLI 实测 total_cost_usd（无需价格表），否则回退按价格表估算
     */
    public function cost(array $pricing = []): float
    {
        if ($this->costUsd > 0) {
            return $this->costUsd;
        }
        return parent::cost($pricing);
    }

    /**
     * 转为数组
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'session_id'  => $this->sessionId,
            'cost_usd'    => $this->costUsd,
            'num_turns'   => $this->numTurns,
            'duration_ms' => $this->durationMs,
            'exit_code'   => $this->exitCode,
            'command'     => $this->command,
        ]);
    }
}
