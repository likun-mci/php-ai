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
 * 工具格式：
 *   $tools = [
 *     'read_file' => [
 *        'description'  => '...',
 *        'input_schema' => ['type'=>'object','properties'=>[...],'required'=>[...]],
 *        'handler'      => function(array $input): string { ... return 工具结果文本; },
 *     ],
 *     ...
 *   ]
 *
 * 事件回调 onEvent(array $event)：
 *   - ['type'=>'agent_text','text'=>...]      模型自然语言
 *   - ['type'=>'tool_call','name'=>...,'input'=>...]
 *   - ['type'=>'tool_error','name'=>...,'message'=>...]  工具抛错（已回填给模型）
 *   - ['type'=>'done']                        正常结束
 *   - ['type'=>'error','message'=>...]
 *   （工具内部的细粒度事件——如 diff/todo——由各 handler 自行通过闭包发出）
 */
class Agent
{
    /** @var AI */
    protected $ai;
    /**
     * @var array<string, array<string, mixed>>
     */
    protected $tools = [];
    /**
     * @var string
     */
    protected $system = '';
    /**
     * @var callable|null
     */
    protected $emit = null;
    /**
     * @var int
     */
    protected $maxIter = 25;
    /**
     * @var string
     */
    protected $lastText = '';

    public function __construct(AI $ai)
    {
        $this->ai = $ai;
    }

    /**
     * @param mixed $system
     * @return $this
     */
    public function setSystem($system) { $this->system = (string) $system; return $this; }
    /**
     * @param array<string, array{description?: string, input_schema?: array<mixed>, handler?: callable}> $tools
     * @return $this
     */
    public function setTools(array $tools) { $this->tools = $tools; return $this; }
    /**
     * @return $this
     */
    public function onEvent(callable $emit) { $this->emit = $emit; return $this; }
    /**
     * @param mixed $n
     * @return $this
     */
    public function setMaxIter($n) { $this->maxIter = max(1, (int) $n); return $this; }
    /**
     * @return string
     */
    public function lastText() { return $this->lastText; }

    /** Anthropic tools 定义（去掉 handler）
     * @return array<int, array<string, mixed>>
     */
    protected function toolDefs()
    {
        $defs = [];
        foreach ($this->tools as $name => $t) {
            $defs[] = [
                'name'         => $name,
                'description'  => isset($t['description']) ? $t['description'] : '',
                'input_schema' => isset($t['input_schema']) ? $t['input_schema'] : ['type' => 'object', 'properties' => new \stdClass()],
            ];
        }
        return $defs;
    }

    /**
     * 运行循环
     * @param array<mixed> $messages 初始消息（通常 [['role'=>'user','content'=>...]]）
     * @return void
     */
    public function run(array $messages)
    {
        $toolDefs = $this->toolDefs();

        for ($iter = 0; $iter < $this->maxIter; $iter++) {
            $this->fire(['type' => 'thinking', 'iter' => $iter + 1]);
            try {
                $resp = $this->ai->setStream(false)->chat([
                    'system'   => $this->system,
                    'messages' => $messages,
                    'tools'    => $toolDefs,
                ]);
            } catch (\Throwable $e) {
                // 捕获 \Throwable：循环中途崩掉不如报成 error 事件，让调用方能收尾
                $this->fire(['type' => 'error', 'message' => $e->getMessage()]);
                return;
            }

            // 全部走归一后的接口，不再直接翻平台原始结构
            $text      = $resp->getContent();
            $toolCalls = $resp->getToolCalls();

            // 记录 assistant 回合（含 tool_use 块）以维持多轮上下文
            $messages[] = $resp->toAssistantMessage();

            if (trim($text) !== '') {
                $this->lastText = $text;
                $this->fire(['type' => 'agent_text', 'text' => $text]);
            }

            if (!$toolCalls) {
                $this->fire(['type' => 'done']);
                return;
            }

            $results = [];
            foreach ($toolCalls as $call) {
                $name  = $call['name'] ?? '';
                $input = isset($call['input']) && is_array($call['input']) ? $call['input'] : [];
                $id    = $call['id'] ?? '';

                $this->fire(['type' => 'tool_call', 'name' => $name, 'input' => $input]);

                $isError = false;
                if (isset($this->tools[$name]['handler']) && is_callable($this->tools[$name]['handler'])) {
                    try {
                        // 捕获 \Throwable 而非 \Exception：handler 里的 TypeError 等
                        // Error 类异常不该穿透整个 Agent 循环，应作为工具失败回填给模型
                        $out = (string) call_user_func($this->tools[$name]['handler'], $input);
                    } catch (\Throwable $e) {
                        $out = 'ERROR: ' . $e->getMessage();
                        $isError = true;
                    }
                } else {
                    $out = 'ERROR: 未知工具 ' . $name;
                    $isError = true;
                }

                if ($isError) {
                    $this->fire(['type' => 'tool_error', 'name' => $name, 'message' => $out]);
                }

                $results[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $id,
                    'content'     => $out,
                    'is_error'    => $isError,
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $results];
        }

        $this->fire(['type' => 'error', 'message' => '已达最大迭代步数（' . $this->maxIter . '）']);
    }

    /**
     * @param array<mixed> $event
     * @return void
     */
    protected function fire(array $event)
    {
        if ($this->emit) call_user_func($this->emit, $event);
    }
}
