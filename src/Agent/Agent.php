<?php
namespace Ai\Agent;

use Ai\AI;

/**
 * Agentic 多轮循环（可复用）
 *
 * 给模型挂上一组工具，循环执行：
 *   模型决策(tool_use) → 我们执行工具 → 把 tool_result 回填 → 继续，直到 end_turn 或达上限。
 *
 * **平台无关**：工具定义、模型发起的调用、结果回填全部走库的统一格式，
 * 协议层负责翻译成各平台的实际结构（OpenAI 系的 tool_calls / role:'tool'，
 * Anthropic 系的 tool_use / tool_result 块），因此同一段 Agent 代码
 * 可以直接跑在 40 个协议上，换平台只改 protocol 配置。
 *
 * 工具格式（兼容旧版与新版）：
 * ```php
 * // 旧版（闭包）
 * $tools = [
 *     'read_file' => [
 *        'description'  => '...',
 *        'input_schema' => ['type'=>'object',...],
 *        'handler'      => function(array $input): string { ... },
 *     ],
 * ];
 *
 * // 新版（对象）
 * $tools = [
 *     new ReadFileTool(),
 * ];
 * ```
 *
 * 事件回调 onEvent(array $event)：
 *   - ['type'=>'agent_text','text'=>...]      模型自然语言
 *   - ['type'=>'tool_call','name'=>...,'input'=>...]
 *   - ['type'=>'tool_error','name'=>...,'message'=>...]  工具抛错（已回填给模型）
 *   - ['type'=>'done']                        正常结束
 *   - ['type'=>'error','message'=>...]
 *   （工具内部的细粒度事件——如 diff/todo——由各 handler 自行通过闭包发出）
 *
 * 内部实现：
 *  v2.0 起内部委托给 {@see AgentRuntime} 执行，但 public API 与事件结构
 *  完全向后兼容，已有代码无需修改。
 */
class Agent
{
    /** @var AI */
    protected $ai;

    /** @var AgentRuntime */
    protected $runtime;

    /** @var string */
    protected $lastText = '';

    /**
     * @param AI $ai
     */
    public function __construct(AI $ai)
    {
        $this->ai = $ai;
        $this->runtime = new AgentRuntime($ai);
    }

    /**
     * @param mixed $system
     * @return $this
     */
    public function setSystem($system)
    {
        $this->runtime->setSystem($system);
        return $this;
    }

    /**
     * @param array<string, array{description?: string, input_schema?: array<mixed>, handler?: callable}> $tools
     * @return $this
     */
    public function setTools(array $tools)
    {
        $this->runtime->setTools($tools);
        return $this;
    }

    /**
     * @return $this
     */
    public function onEvent(callable $emit)
    {
        $this->runtime->onEvent($emit);
        return $this;
    }

    /**
     * @param mixed $n
     * @return $this
     */
    public function setMaxIter($n)
    {
        $this->runtime->setMaxIter($n);
        return $this;
    }

    /**
     * 是否以流式跑这个循环
     *
     * 开启后每一轮的正文都会实时经由 AI 的流式回调吐出去，工具调用照常工作
     * （库会把各平台分片下发的 tool_calls 重组回来）。适合聊天类界面：
     * 用户能一边看到模型说话，一边看到它去调工具。
     *
     * 默认关闭，与旧版本行为一致。开启前请先在 AI 实例上 setStreamCallback()
     * 注册回调，否则分片会直接 echo 到输出。
     *
     * @param bool $stream
     * @return $this
     */
    public function setStream($stream = true)
    {
        $this->runtime->setStream($stream);
        return $this;
    }

    /**
     * @return string
     */
    public function lastText()
    {
        return $this->lastText;
    }

    /**
     * 运行循环
     *
     * @param array<mixed> $messages 初始消息（通常 [['role'=>'user','content'=>...]]）
     * @return void
     */
    public function run(array $messages)
    {
        // 委托给 AgentRuntime 执行
        $result = $this->runtime->run($messages);

        // 保持 lastText 兼容
        $this->lastText = $result->getText();
    }

    /**
     * 获取内部的 AgentRuntime 实例（用于高级扩展）
     *
     * @return AgentRuntime
     */
    public function getRuntime()
    {
        return $this->runtime;
    }
}