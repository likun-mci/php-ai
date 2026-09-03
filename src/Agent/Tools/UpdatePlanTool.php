<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Planning\Plan;
use Ai\Agent\Planning\PlanManager;
use Ai\Agent\Planning\PlanStep;
use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;

/**
 * update_plan——让模型自己管计划
 *
 * 在这个工具出现之前，计划只能由编排层在循环外创建：`AgentOrchestrator` 用关键词
 * 判断任务复杂，生成一张步骤表，然后循环里只把它**只读地**注入 system。模型看得见
 * 计划，改不了计划——干到第三步发现「这个项目其实已经有 OAuth 基础类了」，也只能
 * 继续照着那张过时的表往下走。
 *
 * 计划应当是**状态**而不是脚本：它随执行推进被模型自己改写。所以改计划得是一次
 * 普通的工具调用，跟读文件、跑测试同一个层级。
 *
 * 语义是**整表覆盖**（每次给出完整的最新一版），不是增量补丁。增量补丁要求模型
 * 准确记住上一版的步骤 ID，记错一个就错位；整表覆盖没有这个失败模式。
 * 历史版本由 `PlanManager` 快照保留，覆盖不会丢。
 *
 * 用法：
 *   update_plan(goal: "修复登录 Bug 并跑通测试", items: [
 *     {"id": "1", "action": "定位登录入口",   "status": "completed"},
 *     {"id": "2", "action": "修复空指针",     "status": "in_progress"},
 *     {"id": "3", "action": "跑 composer test", "status": "pending"}
 *   ])
 */
class UpdatePlanTool implements AgentToolInterface
{
    /** @var PlanManager */
    protected $plans;

    /** @var string 当前计划 ID，空串表示还没建 */
    protected $planId = '';

    /**
     * @var callable|null 计划创建/更新后的回调 function(Plan $plan): void
     *
     * 装配方用它把计划 ID 绑回 AgentRuntime，好让下一轮的 system 注入带上计划。
     * 工具不直接依赖 Runtime——ToolContext 里本来就没有它，硬拿会把工具层
     * 和运行时层焊死。
     */
    protected $onChange = null;

    /** @var int 步骤数上限，防止模型把整个任务铺成上百条 */
    protected $maxItems = 40;

    /**
     * @param PlanManager $plans
     * @param array<string, mixed> $options planId / onChange / maxItems
     */
    public function __construct(PlanManager $plans, array $options = [])
    {
        $this->plans = $plans;
        if (isset($options['planId'])) {
            $this->planId = (string) $options['planId'];
        }
        if (isset($options['onChange']) && is_callable($options['onChange'])) {
            $this->onChange = $options['onChange'];
        }
        if (isset($options['maxItems'])) {
            $this->maxItems = max(1, (int) $options['maxItems']);
        }
    }

    public function name()
    {
        return 'update_plan';
    }

    public function description()
    {
        return '创建或更新当前任务的执行计划（待办清单）。'
            . '适合多步骤任务：先写下打算怎么做，每完成一步就把它标成 completed，'
            . '发现原计划不对就直接给出修改后的新版本。'
            . '简单任务不必调用——一两步能做完的事写计划只是额外开销。'
            . '每次调用都要给出**完整的**步骤表（整表覆盖，不是只发变化的部分）。'
            . '返回更新后的计划摘要与进度。';
    }

    public function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'goal' => [
                    'type'        => 'string',
                    'description' => '任务的总目标，一句话。首次调用必填，之后可省略表示不变。',
                ],
                'items' => [
                    'type'        => 'array',
                    'description' => '完整的步骤表，按执行顺序排列',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'action' => [
                                'type'        => 'string',
                                'description' => '这一步要做什么，一句话',
                            ],
                            'status' => [
                                'type'        => 'string',
                                'enum'        => ['pending', 'in_progress', 'completed', 'failed', 'skipped'],
                                'description' => '这一步的状态，省略视为 pending。'
                                    . '同一时刻最多只应有一步是 in_progress。',
                            ],
                            'id' => [
                                'type'        => 'string',
                                'description' => '步骤标识，省略则按顺序自动编号',
                            ],
                        ],
                        'required' => ['action'],
                    ],
                ],
            ],
            'required' => ['items'],
        ];
    }

    public function execute(array $input, ToolContext $context)
    {
        $items = isset($input['items']) && is_array($input['items']) ? $input['items'] : [];
        if (!$items) {
            return ToolResult::error('参数 items 不能为空——至少给出一个步骤');
        }
        if (count($items) > $this->maxItems) {
            return ToolResult::error(
                '步骤数 ' . count($items) . ' 超过上限 ' . $this->maxItems
                . '，请合并成更粗的步骤，细节留到执行时再展开'
            );
        }

        $goal = isset($input['goal']) ? trim((string) $input['goal']) : '';
        $steps = $this->normalize($items);
        if (!$steps) {
            return ToolResult::error('items 里没有一条带 action 的有效步骤');
        }

        $plan = $this->planId !== '' ? $this->plans->getPlan($this->planId) : null;

        if ($plan === null) {
            // 首次调用：目标缺省时退回第一步的动作，总比留空强——
            // 计划摘要会注入 system，没有目标的计划读起来像一堆无主的步骤
            $plan = $this->plans->createPlan($goal !== '' ? $goal : (string) $steps[0]->getAction());
            $this->planId = $plan->getId();
            $this->plans->rewrite($this->planId, $steps, '模型建立初始计划');
            $plan = $this->plans->getPlan($this->planId);
            $this->plans->start($this->planId);
        } else {
            $this->plans->rewrite($this->planId, $steps, '模型更新计划');
            $plan = $this->plans->getPlan($this->planId);
        }

        if ($plan === null) {
            return ToolResult::error('计划写入失败');
        }

        if ($this->onChange !== null) {
            call_user_func($this->onChange, $plan);
        }

        $progress = $plan->progress();
        $context->emit('plan_updated', [
            'plan_id'  => $plan->getId(),
            'version'  => $plan->getVersion(),
            'steps'    => count($plan->getSteps()),
            'progress' => $progress,
        ]);

        return ToolResult::success($plan->toSummary(), [
            'plan_id'  => $plan->getId(),
            'version'  => $plan->getVersion(),
            'progress' => $progress,
        ]);
    }

    /**
     * 当前计划 ID
     *
     * @return string
     */
    public function getPlanId()
    {
        return $this->planId;
    }

    /**
     * 绑定到一个已存在的计划
     *
     * @param string $planId
     * @return $this
     */
    public function setPlanId($planId)
    {
        $this->planId = (string) $planId;
        return $this;
    }

    /**
     * 把模型给的 items 转成 PlanStep
     *
     * @param array<int, mixed> $items
     * @return PlanStep[]
     */
    protected function normalize(array $items)
    {
        $steps = [];
        $index = 0;

        foreach ($items as $item) {
            // 模型偶尔会直接给字符串数组而不是对象数组，接住比报错有用
            if (is_string($item)) {
                $item = ['action' => $item];
            }
            if (!is_array($item)) {
                continue;
            }

            // action 是本工具的字段名，但模型很容易写成 description / content / task /
            // step / title——都是同一个意思，全部认下来。为一个同义词让模型重试一轮不值当
            $action = '';
            foreach (['action', 'description', 'content', 'task', 'step', 'title'] as $key) {
                if (isset($item[$key]) && is_string($item[$key]) && trim($item[$key]) !== '') {
                    $action = trim($item[$key]);
                    break;
                }
            }
            if ($action === '') {
                continue;
            }

            $index++;
            $id = isset($item['id']) && (is_string($item['id']) || is_int($item['id']))
                && (string) $item['id'] !== ''
                ? $item['id']
                : $index;

            $steps[] = new PlanStep($id, $action, [
                'status' => $this->normalizeStatus(isset($item['status']) ? $item['status'] : ''),
            ]);
        }

        return $steps;
    }

    /**
     * 状态词归一
     *
     * 规范里写的是 in_progress，`PlanStep` 内部用的是 running——两边都得认，
     * 否则模型照着工具描述填 in_progress，落进来变成非法状态。
     *
     * @param mixed $status
     * @return string
     */
    protected function normalizeStatus($status)
    {
        $status = strtolower(trim((string) $status));

        $map = [
            'in_progress' => PlanStep::STATUS_RUNNING,
            'inprogress'  => PlanStep::STATUS_RUNNING,
            'in-progress' => PlanStep::STATUS_RUNNING,
            'active'      => PlanStep::STATUS_RUNNING,
            'doing'       => PlanStep::STATUS_RUNNING,
            'running'     => PlanStep::STATUS_RUNNING,
            'done'        => PlanStep::STATUS_COMPLETED,
            'complete'    => PlanStep::STATUS_COMPLETED,
            'completed'   => PlanStep::STATUS_COMPLETED,
            'failed'      => PlanStep::STATUS_FAILED,
            'error'       => PlanStep::STATUS_FAILED,
            'skipped'     => PlanStep::STATUS_SKIPPED,
            'skip'        => PlanStep::STATUS_SKIPPED,
            'blocked'     => PlanStep::STATUS_SKIPPED,
            'todo'        => PlanStep::STATUS_PENDING,
            'pending'     => PlanStep::STATUS_PENDING,
        ];

        return isset($map[$status]) ? $map[$status] : PlanStep::STATUS_PENDING;
    }
}
