<?php
namespace Ai\Agent\Tools;

use Ai\Agent\SubAgent\SubAgentManager;
use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;

/**
 * delegate——让模型自己派子 Agent
 *
 * 委派的全部意义是**隔离上下文**：子 Agent 跑二十轮工具、翻三十个文件，主上下文
 * 只多一段总结。原先这件事只能由编排层在循环外做——`StrategySelector` 拿关键词
 * 给子 Agent 的 description 打分，在模型看到任务之前就决定派谁。任务描述里出现
 * 几个碰巧的词就会被派走，真正该委派的探索反而识别不出来。
 *
 * 该不该委派必须看任务内容才能判断，所以它归模型。委派是**能力**，不是流程：
 * 简单问题、单文件改动、一次 grep 能解决的事都不该走这里。
 *
 * 完整的执行过程留在 `SubAgentManager` 里，按返回的 task_id 可查 transcript——
 * 主上下文拿到的只是摘要。
 *
 * 用法：
 *   delegate(agent: "explorer", task: "找出支付模块的入口类和调用链")
 */
class DelegateTool implements AgentToolInterface
{
    /** @var SubAgentManager */
    protected $subAgents;

    /** @var int 同一次 Agent 运行里最多委派几次 */
    protected $maxDelegations = 8;

    /** @var int 已委派次数 */
    protected $count = 0;

    /**
     * @param SubAgentManager $subAgents
     * @param array<string, mixed> $options maxDelegations
     */
    public function __construct(SubAgentManager $subAgents, array $options = [])
    {
        $this->subAgents = $subAgents;
        if (isset($options['maxDelegations'])) {
            $this->maxDelegations = max(1, (int) $options['maxDelegations']);
        }
    }

    public function name()
    {
        return 'delegate';
    }

    public function description()
    {
        $desc = '把一段可以独立完成的子任务交给专职子 Agent，它在**独立的上下文**里执行，'
            . '只把结论返回给你。适合需要翻很多文件才能得出一个结论的探索性工作——'
            . '这样翻阅过程不会占满你的上下文。'
            . '不适合：简单问题、单文件的小改动、一次搜索就能解决的事，'
            . '以及需要你持续看到中间细节的工作。'
            . '子 Agent 看不到你的对话历史，任务描述必须自带全部必要背景。';

        $available = $this->agentList();
        return $available === ''
            ? $desc
            : $desc . "\n\n可用的子 Agent：\n" . $available;
    }

    public function schema()
    {
        $names = array_keys($this->subAgents->all());

        $agent = [
            'type'        => 'string',
            'description' => '子 Agent 名字，必须是「可用的子 Agent」里列出的其中一个',
        ];
        // 名字列进 enum，模型就不会凭印象编一个不存在的名字
        if ($names) {
            $agent['enum'] = array_values($names);
        }

        return [
            'type'       => 'object',
            'properties' => [
                'agent' => $agent,
                'task'  => [
                    'type'        => 'string',
                    'description' => '交给它做什么。要自带背景（哪个目录、什么前提、'
                        . '要得到什么结论），它看不到你这边的上下文。',
                ],
            ],
            'required' => ['agent', 'task'],
        ];
    }

    public function execute(array $input, ToolContext $context)
    {
        $agent = isset($input['agent']) ? trim((string) $input['agent']) : '';
        $task  = isset($input['task']) ? trim((string) $input['task']) : '';

        if ($agent === '') {
            return ToolResult::error('参数 agent 不能为空。可用：' . $this->agentNames());
        }
        if ($task === '') {
            return ToolResult::error('参数 task 不能为空——要写清楚让子 Agent 做什么');
        }
        // 名字写错不抛异常：把可用名字回给模型，让它换一个再试。
        // 一次拼写错误不该终止整个任务
        if ($this->subAgents->get($agent) === null) {
            return ToolResult::error(
                '子 Agent "' . $agent . '" 不存在。可用：' . $this->agentNames()
            );
        }
        if ($this->count >= $this->maxDelegations) {
            return ToolResult::error(
                '本次运行的委派次数已达上限 ' . $this->maxDelegations
                . '，请自己继续处理剩下的部分'
            );
        }

        $this->count++;
        $context->emit('subagent_started', ['agent' => $agent, 'task' => $task]);

        $runId  = $this->subAgents->runSync($agent, $task);
        $record = $this->subAgents->getResult($runId);
        $record = is_array($record) ? $record : [];

        $status  = isset($record['status']) ? (string) $record['status'] : 'stopped';
        $summary = isset($record['summary']) ? (string) $record['summary'] : '';

        $context->emit('subagent_completed', [
            'agent'   => $agent,
            'task_id' => $runId,
            'status'  => $status,
        ]);

        $meta = [
            'agent'       => $agent,
            'task_id'     => $runId,
            'status'      => $status,
            'iterations'  => isset($record['iterations']) ? (int) $record['iterations'] : 0,
            'duration_ms' => isset($record['duration_ms']) ? $record['duration_ms'] : null,
        ];

        if ($status !== 'completed') {
            // 没跑完也要把已有的产出交回去——半截结论往往仍然有用，
            // 而且模型需要知道「是没做完」而不是「结论就这些」
            $reason = isset($record['reason']) ? (string) $record['reason'] : 'stopped';
            return ToolResult::error(
                '子 Agent "' . $agent . '" 未正常完成（' . $reason . '）。'
                . ($summary !== '' ? "已有产出：\n" . $summary : '没有产出。'),
                $meta
            );
        }

        return ToolResult::success(
            $summary !== '' ? $summary : '子 Agent 完成，但没有返回文本结论。',
            $meta
        );
    }

    /**
     * 已委派次数
     *
     * @return int
     */
    public function getCount()
    {
        return $this->count;
    }

    /**
     * 重置计数（一次新的 Agent 运行开始时调用）
     *
     * @return $this
     */
    public function reset()
    {
        $this->count = 0;
        return $this;
    }

    /**
     * 可用子 Agent 的「名字 —— 职责」清单
     *
     * 不列出来模型只能猜名字。工具描述的质量直接决定它调得准不准。
     *
     * @return string
     */
    protected function agentList()
    {
        $lines = [];
        foreach ($this->subAgents->all() as $name => $def) {
            $desc = trim((string) $def->getDescription());
            $lines[] = '- ' . $name . ($desc !== '' ? '：' . $desc : '');
        }
        return implode("\n", $lines);
    }

    /**
     * @return string 逗号分隔的可用名字
     */
    protected function agentNames()
    {
        $names = array_keys($this->subAgents->all());
        return $names ? implode('、', $names) : '（当前没有注册任何子 Agent）';
    }
}
