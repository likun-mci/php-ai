<?php
namespace Ai\Agent;

/**
 * Agent 执行结果
 *
 * 封装 Agent 运行完毕后的结果：最终文本、停止原因、工具调用次数、
 * token 用量等。比直接从 Agent 实例取属性更结构化。
 *
 * 用法：
 * ```php
 * $result = $agent->run($messages);
 *
 * echo $result->getText();           // 模型最终回答
 * echo $result->getStopReason();     // end_turn / max_iter / ...
 * print_r($result->getUsage());      // token 用量
 * ```
 */
class AgentResult
{
    /** @var string 最终文本 */
    protected $text = '';

    /** @var string 停止原因 */
    protected $stopReason = '';

    /** @var array<int, array{id: string, name: string, input: array<string, mixed>}> 工具调用序列 */
    protected $toolCalls = [];

    /** @var array<string, mixed> token 用量 */
    protected $usage = [];

    /** @var int 迭代次数 */
    protected $iterations = 0;

    /** @var array<string, mixed> 额外数据 */
    protected $extra = [];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->text        = isset($data['text']) ? (string) $data['text'] : '';
        $this->stopReason  = isset($data['stop_reason']) ? (string) $data['stop_reason'] : '';
        $this->toolCalls   = isset($data['tool_calls']) && is_array($data['tool_calls']) ? $data['tool_calls'] : [];
        $this->usage       = isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : [];
        $this->iterations  = isset($data['iterations']) ? (int) $data['iterations'] : 0;
        $this->extra       = isset($data['extra']) && is_array($data['extra']) ? $data['extra'] : [];
    }

    /** 创建正常结束结果
     * @param string $text 最终文本
     * @param array<string, mixed> $extra
     * @return self
     */
    public static function done($text = '', array $extra = [])
    {
        $data = [
            'text'       => $text,
            'stop_reason' => 'end_turn',
        ];
        // 标准字段提到顶层，其余留在 extra 供 getExtra() 读取
        foreach (['iterations', 'usage', 'tool_calls'] as $k) {
            if (array_key_exists($k, $extra)) {
                $data[$k] = $extra[$k];
                unset($extra[$k]);
            }
        }
        $data['extra'] = $extra;
        return new self($data);
    }

    /** 创建停止结果
     * @param string $reason 停止原因
     * @param string $text 已产生的文本
     * @param array<string, mixed> $extra
     * @return self
     */
    public static function stopped($reason, $text = '', array $extra = [])
    {
        $data = [
            'text'       => $text,
            'stop_reason' => $reason,
        ];
        foreach (['iterations', 'usage', 'tool_calls'] as $k) {
            if (array_key_exists($k, $extra)) {
                $data[$k] = $extra[$k];
                unset($extra[$k]);
            }
        }
        $data['extra'] = $extra;
        return new self($data);
    }

    /** 获取最终文本
     * @return string
     */
    public function getText()
    {
        return $this->text;
    }

    /** 获取停止原因
     * @return string
     */
    public function getStopReason()
    {
        return $this->stopReason;
    }

    /** 获取工具调用序列
     * @return array<int, array{id: string, name: string, input: array<string, mixed>}>
     */
    public function getToolCalls()
    {
        return $this->toolCalls;
    }

    /** 获取 token 用量
     * @return array<string, mixed>
     */
    public function getUsage()
    {
        return $this->usage;
    }

    /** 获取迭代次数
     * @return int
     */
    public function getIterations()
    {
        return $this->iterations;
    }

    /** 是否正常结束（end_turn）
     * @return bool
     */
    public function isDone()
    {
        return $this->stopReason === 'end_turn';
    }

    /** 是否因错误/异常停止
     * @return bool
     */
    public function isError()
    {
        $errorReasons = ['max_iter', 'no_progress', 'tool_error', 'model_error', 'permission_denied', 'budget_exceeded', 'timeout'];
        return in_array($this->stopReason, $errorReasons, true);
    }

    /** 获取额外数据
     * @return array<string, mixed>
     */
    public function getExtra()
    {
        return $this->extra;
    }

    /** 转为数组
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'text'        => $this->text,
            'stop_reason' => $this->stopReason,
            'tool_calls'  => $this->toolCalls,
            'usage'       => $this->usage,
            'iterations'  => $this->iterations,
        ];
    }

    /* ---------- 结构化结果契约（Phase 5.3） ---------- */

    /**
     * 完成状态
     *
     * 与 `isDone()` 的区别：这里返回的是可直接放进 API 响应的字符串。
     *
     * @return string completed / stopped / failed
     */
    public function getStatus()
    {
        if ($this->isDone()) {
            return 'completed';
        }
        return $this->isError() ? 'failed' : 'stopped';
    }

    /**
     * 一句话摘要
     *
     * @return string
     */
    public function getSummary()
    {
        if (isset($this->extra['summary'])) {
            return (string) $this->extra['summary'];
        }
        $text = trim($this->text);
        if ($text === '') {
            return '';
        }
        $lines = preg_split('/\r?\n/', $text);
        return $lines === false ? $text : trim($lines[0]);
    }

    /**
     * 这次执行改了哪些文件
     *
     * 由 `WorkspaceSnapshot::diff()` 或调用方填入——AgentResult 自己不去扫工作区，
     * 那是 Workspace 的职责。
     *
     * @return string[]
     */
    public function getFilesChanged()
    {
        return isset($this->extra['files_changed']) && is_array($this->extra['files_changed'])
            ? array_values(array_map('strval', $this->extra['files_changed']))
            : [];
    }

    /**
     * 测试结果
     *
     * @return array<string, mixed> 形如 ['passed' => true, 'failed' => 0]
     */
    public function getTests()
    {
        return isset($this->extra['tests']) && is_array($this->extra['tests']) ? $this->extra['tests'] : [];
    }

    /**
     * 验证结果
     *
     * @return array<string, mixed>
     */
    public function getVerification()
    {
        return isset($this->extra['verification']) && is_array($this->extra['verification'])
            ? $this->extra['verification']
            : [];
    }

    /**
     * 产物引用列表
     *
     * @return string[]
     */
    public function getArtifacts()
    {
        return isset($this->extra['artifacts']) && is_array($this->extra['artifacts'])
            ? array_values(array_map('strval', $this->extra['artifacts']))
            : [];
    }

    /**
     * 子任务结果
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSubtasks()
    {
        return isset($this->extra['subtasks']) && is_array($this->extra['subtasks'])
            ? $this->extra['subtasks']
            : [];
    }

    /**
     * 估算成本
     *
     * @return float 未知返回 0.0
     */
    public function getCost()
    {
        if (isset($this->extra['cost'])) {
            return (float) $this->extra['cost'];
        }
        return isset($this->usage['cost']) ? (float) $this->usage['cost'] : 0.0;
    }

    /**
     * 执行耗时（毫秒）
     *
     * @return float
     */
    public function getDuration()
    {
        return isset($this->extra['duration_ms']) ? (float) $this->extra['duration_ms'] : 0.0;
    }

    /**
     * 补充结构化字段
     *
     * ```php
     * $result->withDetails([
     *     'files_changed' => ['src/Auth.php'],
     *     'tests'         => ['passed' => true],
     *     'duration_ms'   => 12500.4,
     * ]);
     * ```
     *
     * @param array<string, mixed> $details
     * @return $this
     */
    public function withDetails(array $details)
    {
        foreach ($details as $key => $value) {
            $this->extra[(string) $key] = $value;
        }
        return $this;
    }

    /**
     * 完整的结构化结果——可直接 JSON 返回给调用方
     *
     * @return array<string, mixed>
     */
    public function toContract()
    {
        return [
            'status'        => $this->getStatus(),
            'summary'       => $this->getSummary(),
            'text'          => $this->text,
            'stop_reason'   => $this->stopReason,
            'files_changed' => $this->getFilesChanged(),
            'tests'         => $this->getTests(),
            'verification'  => $this->getVerification(),
            'artifacts'     => $this->getArtifacts(),
            'subtasks'      => $this->getSubtasks(),
            'usage'         => $this->usage,
            'cost'          => $this->getCost(),
            'iterations'    => $this->iterations,
            'duration_ms'   => $this->getDuration(),
        ];
    }
}