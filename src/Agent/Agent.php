<?php
namespace Ai\Agent;

use Ai\AI;

/**
 * Agentic 多轮循环（可复用）
 *
 * 给模型挂上一组工具，循环执行：
 *   模型决策(tool_use) → 我们执行工具 → 把 tool_result 回填 → 继续，直到 end_turn 或达上限。
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
 *   - ['type'=>'done']                        正常结束
 *   - ['type'=>'error','message'=>...]
 *   （工具内部的细粒度事件——如 diff/todo——由各 handler 自行通过闭包发出）
 */
class Agent
{
    /** @var AI */
    protected $ai;
    protected $tools = [];
    protected $system = '';
    protected $emit = null;
    protected $maxIter = 25;
    protected $lastText = '';

    public function __construct(AI $ai)
    {
        $this->ai = $ai;
    }

    public function setSystem($system) { $this->system = (string) $system; return $this; }
    public function setTools(array $tools) { $this->tools = $tools; return $this; }
    public function onEvent(callable $emit) { $this->emit = $emit; return $this; }
    public function setMaxIter($n) { $this->maxIter = max(1, (int) $n); return $this; }
    public function lastText() { return $this->lastText; }

    /** Anthropic tools 定义（去掉 handler） */
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
     * @param array $messages 初始消息（通常 [['role'=>'user','content'=>...]]）
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
            } catch (\Exception $e) {
                $this->fire(['type' => 'error', 'message' => $e->getMessage()]);
                return;
            }

            $raw = $resp->getRaw();
            $content = isset($raw['content']) && is_array($raw['content']) ? $raw['content'] : [];
            $stop = isset($raw['stop_reason']) ? $raw['stop_reason'] : '';

            // 记录 assistant 回合（含 tool_use 块）以维持多轮上下文
            $messages[] = ['role' => 'assistant', 'content' => $content];

            // 文本块
            foreach ($content as $b) {
                if (($b['type'] ?? '') === 'text' && trim($b['text'] ?? '') !== '') {
                    $this->lastText = $b['text'];
                    $this->fire(['type' => 'agent_text', 'text' => $b['text']]);
                }
            }

            // 工具调用块
            $toolUses = [];
            foreach ($content as $b) {
                if (($b['type'] ?? '') === 'tool_use') $toolUses[] = $b;
            }

            if ($stop !== 'tool_use' || !$toolUses) {
                $this->fire(['type' => 'done']);
                return;
            }

            $results = [];
            foreach ($toolUses as $tu) {
                $name  = $tu['name'] ?? '';
                $input = isset($tu['input']) && is_array($tu['input']) ? $tu['input'] : [];
                $id    = $tu['id'] ?? '';

                $this->fire(['type' => 'tool_call', 'name' => $name, 'input' => $input]);

                $out = '';
                if (isset($this->tools[$name]['handler']) && is_callable($this->tools[$name]['handler'])) {
                    try {
                        $out = (string) call_user_func($this->tools[$name]['handler'], $input);
                    } catch (\Exception $e) {
                        $out = 'ERROR: ' . $e->getMessage();
                    }
                } else {
                    $out = 'ERROR: 未知工具 ' . $name;
                }

                $results[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $id,
                    'content'     => $out,
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $results];
        }

        $this->fire(['type' => 'error', 'message' => '已达最大迭代步数（' . $this->maxIter . '）']);
    }

    protected function fire(array $event)
    {
        if ($this->emit) call_user_func($this->emit, $event);
    }
}
