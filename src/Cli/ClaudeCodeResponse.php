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

    /** @var string result 事件子类型：success / error_max_turns / error_during_execution ... */
    protected $subtype;

    /** @var string 终止原因：end_turn / max_tokens / tool_use ... */
    protected $stopReason;

    /** @var string 本轮思考内容（若模型产生了 thinking 块） */
    protected $thinking;

    /** @var array 本次会话可用工具名列表（取自 system/init 事件） */
    protected $tools;

    /** @var array 本轮的工具调用记录，每项 ['id','name','input'] */
    protected $toolUses;

    /** @var array 被权限拒绝的工具调用记录 */
    protected $permissionDenials;

    /** @var array system/init 事件原文（cwd、mcp_servers、slash_commands 等） */
    protected $init;

    /** @var array|null --json-schema 结构化输出解析结果 */
    protected $structured;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->sessionId = $data['session_id'] ?? null;
        $this->costUsd   = (float) ($data['cost_usd'] ?? 0);
        $this->numTurns  = (int) ($data['num_turns'] ?? 0);
        $this->durationMs = (int) ($data['duration_ms'] ?? 0);
        $this->command   = (string) ($data['command'] ?? '');
        $this->exitCode  = (int) ($data['exit_code'] ?? -1);
        $this->subtype   = (string) ($data['subtype'] ?? '');
        $this->stopReason = (string) ($data['stop_reason'] ?? '');
        $this->thinking  = (string) ($data['thinking'] ?? '');
        $this->tools     = (array) ($data['tools'] ?? []);
        $this->toolUses  = (array) ($data['tool_uses'] ?? []);
        $this->permissionDenials = (array) ($data['permission_denials'] ?? []);
        $this->init      = (array) ($data['init'] ?? []);
        $this->structured = isset($data['structured']) && is_array($data['structured'])
            ? $data['structured'] : null;
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
     * 获取 result 事件子类型（success / error_max_turns / error_during_execution ...）
     */
    public function getSubtype(): string
    {
        return $this->subtype;
    }

    /**
     * 获取终止原因（end_turn / max_tokens / tool_use ...）
     */
    public function getStopReason(): string
    {
        return $this->stopReason;
    }

    /**
     * 获取本轮思考内容（未开启 thinking 时为空串）
     */
    public function getThinking(): string
    {
        return $this->thinking;
    }

    /**
     * 获取本次会话可用的工具名列表
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * 获取本轮的工具调用记录，每项 ['id','name','input']
     */
    public function getToolUses(): array
    {
        return $this->toolUses;
    }

    /**
     * 获取被权限拒绝的工具调用记录
     */
    public function getPermissionDenials(): array
    {
        return $this->permissionDenials;
    }

    /**
     * 获取 system/init 事件原文（cwd、mcp_servers、slash_commands、permissionMode 等）
     */
    public function getInit(): array
    {
        return $this->init;
    }

    /**
     * 获取结构化输出（配合 setJsonSchema() 使用），无法解析为 JSON 时返回 null
     */
    public function getStructured(): ?array
    {
        return $this->structured;
    }

    /**
     * 是否发生了权限拒绝（工具被拦截）
     */
    public function hasPermissionDenials(): bool
    {
        return !empty($this->permissionDenials);
    }

    /**
     * 费用：优先返回 CLI 实测 total_cost_usd（无需价格表），否则回退按价格表估算
     */
    public function cost(array $pricing = [], int $perTokens = 1000): float
    {
        if ($this->costUsd > 0) {
            return $this->costUsd;
        }
        return parent::cost($pricing, $perTokens);
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
            'subtype'     => $this->subtype,
            'stop_reason' => $this->stopReason,
            'thinking'    => $this->thinking,
            'tools'       => $this->tools,
            'tool_uses'   => $this->toolUses,
            'permission_denials' => $this->permissionDenials,
            'structured'  => $this->structured,
        ]);
    }
}
