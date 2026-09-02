<?php
namespace Ai\Agent\Orchestrator;

use Ai\Agent\AgentResult;
use Ai\Agent\AgentRuntime;
use Ai\Agent\Loop\StopReason;
use Ai\Agent\Planning\PlanManager;
use Ai\Agent\SubAgent\SubAgentManager;

/**
 * AgentOrchestrator——编排层入口
 *
 * `AgentRuntime` 回答的是「怎么跑一轮 Agent 循环」，这个类回答的是**「这活该怎么干」**：
 * 直接调工具、先拆计划、派给子 Agent、并行铺开、还是丢后台。
 *
 * ```php
 * $orchestrator = new AgentOrchestrator($runtime);
 * $orchestrator->setSubAgents($sam)->setPlanManager($pm);
 *
 * $result = $orchestrator->handle('分析项目中的认证、支付、SEO');
 * // 自动识别成三路并行 → 派 explorer → 汇总
 *
 * print_r($orchestrator->lastDecision()->toArray());
 * // ['strategy' => 'parallel', 'reason' => '识别到 3 个互不相关的子任务', …]
 * ```
 *
 * **决策一定会进事件流**（`strategy_decision` 事件）。Agent 自主选策略之后，
 * 使用者必须能回答「它为什么这么干」——否则出了问题只能靠猜。
 *
 * 不改变既有行为：不挂 SubAgentManager / PlanManager 时，所有策略都会退回
 * `DIRECT`，跑出来跟直接调 `AgentRuntime::run()` 一样。
 */
class AgentOrchestrator
{
    /** @var AgentRuntime */
    protected $runtime;

    /** @var StrategySelector */
    protected $selector;

    /** @var SubAgentManager|null */
    protected $subAgents = null;

    /** @var PlanManager|null */
    protected $planManager = null;

    /** @var BackgroundDispatcher|null */
    protected $dispatcher = null;

    /** @var ParallelAgentExecutor|null */
    protected $parallel = null;

    /** @var ResultAggregator|null */
    protected $aggregator = null;

    /** @var \Ai\Agent\Verification\VerificationGate|null 验证闸门 */
    protected $gate = null;

    /** @var CompletionCriteria|null 完成判据 */
    protected $criteria = null;

    /** @var StrategyDecision|null 最近一次决策 */
    protected $lastDecision = null;

    /** @var array<int, array<string, mixed>> 决策历史 */
    protected $decisions = [];

    /** @var callable|null 事件回调 */
    protected $emit = null;

    /** @var int 后台任务自增序号 */
    protected $taskCounter = 0;

    /**
     * @param AgentRuntime $runtime
     * @param array<string, mixed> $options selector / subAgents / planManager / dispatcher / parallel
     */
    public function __construct(AgentRuntime $runtime, array $options = [])
    {
        $this->runtime = $runtime;

        if (isset($options['subAgents']) && $options['subAgents'] instanceof SubAgentManager) {
            $this->subAgents = $options['subAgents'];
        }
        $this->selector = isset($options['selector']) && $options['selector'] instanceof StrategySelector
            ? $options['selector']
            : new StrategySelector($this->subAgents);

        if (isset($options['planManager']) && $options['planManager'] instanceof PlanManager) {
            $this->planManager = $options['planManager'];
        }
        if (isset($options['dispatcher']) && $options['dispatcher'] instanceof BackgroundDispatcher) {
            $this->dispatcher = $options['dispatcher'];
        }
        if (isset($options['parallel']) && $options['parallel'] instanceof ParallelAgentExecutor) {
            $this->parallel = $options['parallel'];
        }
    }

    /**
     * 处理一个任务——选策略并执行
     *
     * @param string $task 任务描述
     * @param array<string, mixed> $context 额外上下文，透传给策略选择器
     * @return AgentResult
     */
    public function handle($task, array $context = [])
    {
        $task = (string) $task;
        $decision = $this->decide($task, $context);
        return $this->execute($task, $decision, $context);
    }

    /**
     * 只做决策，不执行
     *
     * 决策与执行分开，调用方可以先看决策再决定要不要照做——
     * 自动化程度由使用者掌握，而不是被框架强制。
     *
     * @param string $task
     * @param array<string, mixed> $context
     * @return StrategyDecision
     */
    public function decide($task, array $context = [])
    {
        $context = array_merge($this->buildContext(), $context);
        $decision = $this->selector->select((string) $task, $context);

        $this->lastDecision = $decision;
        $this->decisions[] = $decision->toArray();
        $this->event('strategy_decision', $decision->toArray());

        return $decision;
    }

    /**
     * 按给定决策执行
     *
     * @param string $task
     * @param StrategyDecision $decision
     * @param array<string, mixed> $context
     * @return AgentResult
     */
    public function execute($task, StrategyDecision $decision, array $context = [])
    {
        $task = (string) $task;

        switch ($decision->getStrategy()) {
            case ExecutionStrategy::PLAN:
                return $this->executePlan($task, $decision);
            case ExecutionStrategy::DELEGATE:
                return $this->executeDelegate($task, $decision);
            case ExecutionStrategy::PARALLEL:
                return $this->executeParallel($task, $decision);
            case ExecutionStrategy::BACKGROUND:
                return $this->executeBackground($task, $decision);
            case ExecutionStrategy::ASK_USER:
                return $this->executeAskUser($task, $decision);
            case ExecutionStrategy::VERIFY:
            case ExecutionStrategy::DIRECT:
            default:
                return $this->executeDirect($task, $context);
        }
    }

    /**
     * 跑完之后过闸门与完成判据
     *
     * 「模型说完成了」不等于完成：验证还没过、计划还剩几步、上一次工具还在报错，
     * 这些都得检查。任一判据不满足就把原因交回给模型继续干——这正是
     * Verification → Reflection → Replan 闭环的入口。
     *
     * @param \Ai\Agent\AgentResult $result
     * @param string $task
     * @param array<string, mixed> $context 追加给判据的上下文
     * @return array<string, mixed> completed / unmet / prompt / verification
     */
    public function checkCompletion($result, $task = '', array $context = [])
    {
        $verification = null;
        if ($this->gate !== null) {
            if ($task !== '') {
                $this->gate->policyForTask($task);
            }
            $verification = $this->gate->check(array_merge(
                ['workdir' => $this->runtime->getWorkdir()],
                isset($context['verification_context']) && is_array($context['verification_context'])
                    ? $context['verification_context']
                    : []
            ));
        }

        $criteria = $this->criteria();
        $evalContext = $context;
        if ($verification !== null) {
            $evalContext['verification_passed'] = $verification['passed'];
        }
        if (!isset($evalContext['plan'])) {
            $plan = $this->currentPlan();
            if ($plan !== null) {
                $evalContext['plan'] = $plan;
            }
        }
        if (!isset($evalContext['model_claims_done']) && is_object($result) && method_exists($result, 'isDone')) {
            $evalContext['model_claims_done'] = $result->isDone();
        }

        $outcome = $criteria->evaluate($evalContext);
        $outcome['verification'] = $verification;

        $this->event($outcome['completed'] ? 'task_completed' : 'completion_unmet', [
            'unmet'  => $outcome['unmet'],
            'reasons' => $outcome['reasons'],
        ]);

        return $outcome;
    }

    /**
     * 完成判据（惰性创建）
     *
     * 默认用宽松判据：没挂验证闸门时要求「验证通过」会永远达不成。
     * 挂了闸门之后建议换成默认或严格判据。
     *
     * @return CompletionCriteria
     */
    public function criteria()
    {
        if ($this->criteria === null) {
            $this->criteria = $this->gate !== null
                ? new CompletionCriteria()
                : CompletionCriteria::lenient();
        }
        return $this->criteria;
    }

    /**
     * @param CompletionCriteria|null $criteria
     * @return $this
     */
    public function setCriteria($criteria)
    {
        $this->criteria = $criteria instanceof CompletionCriteria ? $criteria : null;
        return $this;
    }

    /**
     * @param \Ai\Agent\Verification\VerificationGate|null $gate
     * @return $this
     */
    public function setVerificationGate($gate)
    {
        $this->gate = $gate instanceof \Ai\Agent\Verification\VerificationGate ? $gate : null;
        if ($this->gate !== null && $this->emit !== null) {
            $this->gate->onEvent($this->emit);
        }
        return $this;
    }

    /**
     * @return \Ai\Agent\Verification\VerificationGate|null
     */
    public function verificationGate()
    {
        return $this->gate;
    }

    /**
     * 当前执行计划
     *
     * @return \Ai\Agent\Planning\Plan|null
     */
    protected function currentPlan()
    {
        if ($this->planManager === null || $this->runtime->getPlanId() === '') {
            return null;
        }
        return $this->planManager->getPlan($this->runtime->getPlanId());
    }

    /**
     * 直接执行——交给 AgentRuntime 跑循环
     *
     * @param string $task
     * @param array<string, mixed> $context
     * @return AgentResult
     */
    protected function executeDirect($task, array $context = [])
    {
        $messages = isset($context['messages']) && is_array($context['messages'])
            ? $context['messages']
            : [['role' => 'user', 'content' => $task]];

        if ($this->runtime->getGoal() === '') {
            $this->runtime->setGoal($task);
        }
        return $this->runtime->run($messages);
    }

    /**
     * 先规划再执行
     *
     * 没挂 PlanManager 时退回直接执行——没有计划管理器却硬要规划，
     * 只会生成一个没人管的计划对象。
     *
     * @param string $task
     * @param StrategyDecision $decision
     * @return AgentResult
     */
    protected function executePlan($task, StrategyDecision $decision)
    {
        if ($this->planManager === null) {
            return $this->executeDirect($task);
        }

        $steps = $decision->getSubtasks();
        $plan = $this->planManager->createPlan($task, $steps ? ['steps' => $steps] : []);
        $this->planManager->start($plan->getId());

        $this->runtime->setPlanManager($this->planManager);
        $this->runtime->setPlanId($plan->getId());
        $this->runtime->setGoal($task);

        $this->event('plan_created', [
            'plan_id' => $plan->getId(),
            'goal'    => $task,
            'steps'   => count($plan->getSteps()),
        ]);

        return $this->executeDirect($task);
    }

    /**
     * 委派给子 Agent
     *
     * @param string $task
     * @param StrategyDecision $decision
     * @return AgentResult
     */
    protected function executeDelegate($task, StrategyDecision $decision)
    {
        $agent = $decision->getAgent();
        $subAgents = $this->subAgents;
        if ($subAgents === null || $agent === '' || $subAgents->get($agent) === null) {
            // 委派目标不存在就自己干，不能因为决策落空把任务丢了
            return $this->executeDirect($task);
        }

        $this->event('subagent_started', ['agent' => $agent, 'task' => $task]);
        $runId = $subAgents->runSync($agent, $task);
        $record = $subAgents->getResult($runId);
        $record = is_array($record) ? $record : [];

        $status = isset($record['status']) ? (string) $record['status'] : 'stopped';
        $summary = isset($record['summary']) ? (string) $record['summary'] : '';

        $this->event('subagent_completed', [
            'agent'   => $agent,
            'task_id' => $runId,
            'status'  => $status,
        ]);

        $meta = [
            'strategy'   => ExecutionStrategy::DELEGATE,
            'agent'      => $agent,
            'task_id'    => $runId,
            'iterations' => isset($record['iterations']) ? (int) $record['iterations'] : 0,
        ];

        return $status === 'completed'
            ? AgentResult::done($summary, $meta)
            : AgentResult::stopped(StopReason::MAX_ITER, $summary, $meta);
    }

    /**
     * 并行执行多路子任务
     *
     * @param string $task
     * @param StrategyDecision $decision
     * @return AgentResult
     */
    protected function executeParallel($task, StrategyDecision $decision)
    {
        $subtasks = $decision->getSubtasks();
        if ($this->subAgents === null || count($subtasks) < 2) {
            return $this->executeDirect($task);
        }

        $agent = $this->pickParallelAgent($task, $decision, $subtasks);
        if ($agent === '') {
            return $this->executeDirect($task);
        }

        $jobs = [];
        foreach ($subtasks as $subtask) {
            $jobs[] = ['agent' => $agent, 'task' => $subtask];
        }

        $executor = $this->parallelExecutor();
        $results = $executor->run($jobs);

        $aggregated = $this->aggregator()->aggregate($results);

        return AgentResult::done($aggregated['summary'], [
            'strategy'  => ExecutionStrategy::PARALLEL,
            'agent'     => $agent,
            'subtasks'  => count($jobs),
            'mode'      => $executor->mode(),
            'aggregate' => $aggregated,
        ]);
    }

    /**
     * 选一个执行并行子任务的子 Agent
     *
     * 依次尝试：决策指定的 → 整句匹配的 → 单个子任务匹配的 → explorer。
     * 并行铺开的多半是调查类任务，explorer 是合理的兜底；它一个写工具都没有，
     * 兜底兜错了也不会改坏东西。
     *
     * @param string $task
     * @param StrategyDecision $decision
     * @param string[] $subtasks
     * @return string 都没匹配上返回空串
     */
    protected function pickParallelAgent($task, StrategyDecision $decision, array $subtasks)
    {
        $candidates = [$decision->getAgent(), $this->selector->matchAgent($task)];
        if ($subtasks) {
            $candidates[] = $this->selector->matchAgent($subtasks[0]);
        }
        $candidates[] = \Ai\Agent\SubAgent\BuiltinAgents::EXPLORER;

        if ($this->subAgents === null) {
            return '';
        }
        foreach ($candidates as $candidate) {
            $candidate = (string) $candidate;
            if ($candidate !== '' && $this->subAgents->get($candidate) !== null) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * 丢后台执行
     *
     * @param string $task
     * @param StrategyDecision $decision
     * @return AgentResult
     */
    protected function executeBackground($task, StrategyDecision $decision)
    {
        $dispatcher = $this->dispatcher();
        $this->taskCounter++;
        $taskId = 'task_' . $this->taskCounter . '_' . substr(md5(uniqid('', true)), 0, 8);

        $agent = $decision->getAgent();
        $subAgents = $this->subAgents;
        $runtime = $this->runtime;

        $work = function () use ($agent, $subAgents, $runtime, $task) {
            if ($agent !== '' && $subAgents instanceof SubAgentManager && $subAgents->get($agent) !== null) {
                $runId = $subAgents->runSync($agent, $task);
                return $subAgents->getResult($runId);
            }
            return $runtime->run([['role' => 'user', 'content' => $task]]);
        };

        $handle = $dispatcher->dispatch($taskId, $work, ['task' => $task, 'agent' => $agent]);

        $this->event('task_created', [
            'task_id'    => $taskId,
            'background' => !empty($handle['background']),
            'mode'       => $handle['mode'],
        ]);

        $text = json_encode([
            'task_id'    => $taskId,
            'status'     => $handle['status'],
            'background' => !empty($handle['background']),
            'mode'       => $handle['mode'],
        ], JSON_UNESCAPED_UNICODE);

        return AgentResult::stopped(StopReason::MAX_ITER, $text === false ? $taskId : $text, [
            'strategy' => ExecutionStrategy::BACKGROUND,
            'task_id'  => $taskId,
            'handle'   => $handle,
        ]);
    }

    /**
     * 需要用户澄清
     *
     * @param string $task
     * @param StrategyDecision $decision
     * @return AgentResult
     */
    protected function executeAskUser($task, StrategyDecision $decision)
    {
        return AgentResult::stopped(StopReason::WAITING_USER, $decision->getReason(), [
            'strategy' => ExecutionStrategy::ASK_USER,
            'task'     => $task,
        ]);
    }

    /**
     * 最近一次决策
     *
     * @return StrategyDecision|null
     */
    public function lastDecision()
    {
        return $this->lastDecision;
    }

    /**
     * 决策历史
     *
     * @return array<int, array<string, mixed>>
     */
    public function decisions()
    {
        return $this->decisions;
    }

    /** @return StrategySelector */
    public function selector()
    {
        return $this->selector;
    }

    /**
     * @param StrategySelector $selector
     * @return $this
     */
    public function setSelector(StrategySelector $selector)
    {
        $this->selector = $selector;
        return $this;
    }

    /**
     * @param SubAgentManager|null $sam
     * @return $this
     */
    public function setSubAgents($sam)
    {
        $this->subAgents = $sam instanceof SubAgentManager ? $sam : null;
        $this->selector->setSubAgents($this->subAgents);
        if ($this->parallel !== null && $this->subAgents !== null) {
            $this->parallel = null;   // 重建，避免继续用旧的 manager
        }
        return $this;
    }

    /**
     * @param PlanManager|null $pm
     * @return $this
     */
    public function setPlanManager($pm)
    {
        $this->planManager = $pm instanceof PlanManager ? $pm : null;
        return $this;
    }

    /**
     * @param BackgroundDispatcher|null $dispatcher
     * @return $this
     */
    public function setDispatcher($dispatcher)
    {
        $this->dispatcher = $dispatcher instanceof BackgroundDispatcher ? $dispatcher : null;
        return $this;
    }

    /**
     * @param ParallelAgentExecutor|null $executor
     * @return $this
     */
    public function setParallelExecutor($executor)
    {
        $this->parallel = $executor instanceof ParallelAgentExecutor ? $executor : null;
        return $this;
    }

    /**
     * @param ResultAggregator|null $aggregator
     * @return $this
     */
    public function setAggregator($aggregator)
    {
        $this->aggregator = $aggregator instanceof ResultAggregator ? $aggregator : null;
        return $this;
    }

    /**
     * @param callable|null $emit
     * @return $this
     */
    public function onEvent($emit)
    {
        $this->emit = $emit;
        return $this;
    }

    /** @return AgentRuntime */
    public function runtime()
    {
        return $this->runtime;
    }

    /**
     * 后台派发器（惰性创建）
     *
     * @return BackgroundDispatcher
     */
    public function dispatcher()
    {
        if ($this->dispatcher === null) {
            $this->dispatcher = new BackgroundDispatcher();
        }
        return $this->dispatcher;
    }

    /**
     * 并行执行器（惰性创建）
     *
     * @return ParallelAgentExecutor
     */
    public function parallelExecutor()
    {
        if ($this->parallel === null) {
            $this->parallel = new ParallelAgentExecutor(
                $this->subAgents !== null ? $this->subAgents : new SubAgentManager($this->runtime->getAI())
            );
            $this->parallel->onEvent($this->emit);
        }
        return $this->parallel;
    }

    /**
     * 结果聚合器（惰性创建）
     *
     * @return ResultAggregator
     */
    public function aggregator()
    {
        if ($this->aggregator === null) {
            $this->aggregator = new ResultAggregator();
        }
        return $this->aggregator;
    }

    /**
     * 组装给策略选择器的上下文
     *
     * @return array<string, mixed>
     */
    protected function buildContext()
    {
        $context = [];
        if ($this->planManager !== null && $this->runtime->getPlanId() !== '') {
            $plan = $this->planManager->getPlan($this->runtime->getPlanId());
            $context['has_plan'] = $plan !== null && !$plan->isComplete();
        }
        $context['has_subagents'] = $this->subAgents !== null && $this->subAgents->all() !== [];
        return $context;
    }

    /**
     * @param string $type
     * @param array<string, mixed> $data
     * @return void
     */
    protected function event($type, array $data = [])
    {
        if ($this->emit !== null) {
            call_user_func($this->emit, array_merge(['type' => $type], $data));
        }
    }
}
