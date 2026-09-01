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
}