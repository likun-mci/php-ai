<?php
namespace Ai\Agent\Orchestrator;

use Ai\AI;
use Ai\Agent\SubAgent\SubAgentManager;

/**
 * ModelStrategyResolver——用模型选策略，而不是用词表
 *
 * `StrategySelector` 的默认判断是基于关键词的：描述里出现「重构」就先拆计划，
 * 长度过 60 字就算复杂，撞上某个子 Agent 的 description 就派给它。规则跑得快、
 * 可复现，但它读不懂任务——「把这段话里的错别字改一下，顺便重构一下措辞」会被
 * 判成大型重构，而「让支付、退款、对账三条链路的状态机对齐」看不出该并行。
 *
 * 「这活该怎么干」必须看懂任务才能答，所以它归模型。这个类把决策做成一次
 * **小而便宜**的模型调用：只要一个 JSON，不给工具，不进 Agent 循环。
 *
 * ```php
 * $selector->setResolver(new ModelStrategyResolver($ai, $subAgents));
 * ```
 *
 * **拿不准就退回 null**，由 `StrategySelector` 的规则版接手。模型抽风、超时、
 * 返回的不是 JSON——这些时候宁可用一个保守的规则判断，也不该让整个任务失败在
 * 「决定怎么做」这一步上。决策失败不是任务失败。
 *
 * 结果会缓存：同一个任务描述在一次运行里可能被问好几次（编排层决策一次、
 * 计划每步再问一次），没必要每次都花一趟模型往返。
 */
class ModelStrategyResolver
{
    /** @var AI */
    protected $ai;

    /** @var SubAgentManager|null */
    protected $subAgents = null;

    /** @var string|null 决策专用模型（便宜的小模型足够），null 用当前模型 */
    protected $model = null;

    /** @var array<string, StrategyDecision> 任务描述 => 决策 */
    protected $cache = [];

    /** @var string 最近一次的原始返回，排查用 */
    protected $lastRaw = '';

    /** @var \Throwable|null 最近一次的失败 */
    protected $lastError = null;

    /**
     * @param AI $ai
     * @param SubAgentManager|null $subAgents 用于把可选的子 Agent 告诉模型
     * @param array<string, mixed> $options model
     */
    public function __construct(AI $ai, $subAgents = null, array $options = [])
    {
        $this->ai = $ai;
        $this->subAgents = $subAgents instanceof SubAgentManager ? $subAgents : null;
        if (isset($options['model']) && (string) $options['model'] !== '') {
            $this->model = (string) $options['model'];
        }
    }

    /**
     * 让 StrategySelector 直接把本对象当 resolver 用
     *
     * @param string $task
     * @param array<string, mixed> $context
     * @return StrategyDecision|null null 表示拿不准，交回规则版
     */
    public function __invoke($task, array $context = [])
    {
        return $this->resolve($task, $context);
    }

    /**
     * @param string $task
     * @param array<string, mixed> $context
     * @return StrategyDecision|null
     */
    public function resolve($task, array $context = [])
    {
        $task = trim((string) $task);
        if ($task === '') {
            return null;
        }

        // 已经在执行计划中途就不该再问「要不要拆计划」——那一定是 direct 或 delegate，
        // 白花一次模型调用
        if (!empty($context['has_plan'])) {
            return null;
        }

        $key = md5($task);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $this->lastError = null;
        try {
            $raw = $this->ask($task);
        } catch (\Throwable $e) {
            // 决策失败不是任务失败——退回规则版，任务照跑
            $this->lastError = $e;
            return null;
        }

        $this->lastRaw = $raw;
        $decision = $this->parse($raw);
        if ($decision !== null) {
            $this->cache[$key] = $decision;
        }
        return $decision;
    }

    /**
     * 问一次模型
     *
     * @param string $task
     * @return string
     */
    protected function ask($task)
    {
        $params = [
            'system'   => $this->systemPrompt(),
            'messages' => [['role' => 'user', 'content' => $task]],
        ];

        $previousModel = null;
        if ($this->model !== null) {
            $current = $this->ai->model();
            $previousModel = $current ? $current->getName() : null;
            $this->ai->setModel($this->model);
        }

        // 决策调用不该被流式开关影响：这里要的是一段完整 JSON，
        // 不是给用户看的增量输出
        $wasStreaming = $this->ai->isStreaming();
        $this->ai->setStream(false);

        try {
            $resp = $this->ai->chat($params);
            return (string) $resp->getContent();
        } finally {
            $this->ai->setStream($wasStreaming);
            if ($previousModel !== null) {
                $this->ai->setModel($previousModel);
            }
        }
    }

    /**
     * 决策用的 system prompt
     *
     * @return string
     */
    protected function systemPrompt()
    {
        $lines = [
            '你是一个任务调度器。判断给定任务应该用哪种执行策略，只输出 JSON，不要解释。',
            '',
            '可选策略：',
            '- direct：范围明确，直接开干。**默认选它**。',
            '- plan：多步骤、跨多个模块，值得先拆成有序步骤。',
            '- parallel：包含几件互不依赖、可以同时做的事，需要给出 subtasks。',
            '- delegate：整件事适合交给某一个专职子 Agent，需要给出 agent。',
            '- background：任务明确要求后台/异步执行，不用等结果。',
            '- verify：只是要确认既有改动是否正常，不涉及任何修改。',
            '- ask_user：缺少必要信息，问清楚才能动手。',
            '',
            '判断要点：',
            '- 拿不准就选 direct。选错 direct 的代价是多跑几轮工具；'
                . '选错 delegate 的代价是一个子 Agent 带着错误的上下文跑十几轮。',
            '- 「改完顺便验证一下」是修改任务（direct/plan），不是 verify。'
                . 'verify 只给纯确认、不改任何东西的任务。',
            '- 简单问答、单文件小改动一律 direct，不要拆计划。',
        ];

        $agents = $this->agentLines();
        if ($agents !== '') {
            $lines[] = '';
            $lines[] = '可用的子 Agent（delegate / parallel 时从中选）：';
            $lines[] = $agents;
        } else {
            $lines[] = '';
            $lines[] = '当前没有可用的子 Agent，不要选 delegate 或 parallel。';
        }

        $lines[] = '';
        $lines[] = '输出格式（严格 JSON）：';
        $lines[] = '{"strategy":"direct","reason":"一句话理由","agent":"","subtasks":[],"confidence":0.9}';

        return implode("\n", $lines);
    }

    /**
     * @return string
     */
    protected function agentLines()
    {
        if ($this->subAgents === null) {
            return '';
        }
        $lines = [];
        foreach ($this->subAgents->all() as $name => $def) {
            $lines[] = '- ' . $name . '：' . trim((string) $def->getDescription());
        }
        return implode("\n", $lines);
    }

    /**
     * 解析模型返回
     *
     * @param string $raw
     * @return StrategyDecision|null 解析不出合法策略时返回 null
     */
    protected function parse($raw)
    {
        $json = $this->extractJson($raw);
        if ($json === null) {
            return null;
        }

        $strategy = isset($json['strategy']) ? strtolower(trim((string) $json['strategy'])) : '';
        if (!in_array($strategy, ExecutionStrategy::all(), true)) {
            return null;
        }

        $reason = isset($json['reason']) ? trim((string) $json['reason']) : '模型决策';
        $agent  = isset($json['agent']) ? trim((string) $json['agent']) : '';
        $confidence = isset($json['confidence']) ? (float) $json['confidence'] : 0.8;

        $subtasks = [];
        if (isset($json['subtasks']) && is_array($json['subtasks'])) {
            foreach ($json['subtasks'] as $st) {
                if (is_string($st) && trim($st) !== '') {
                    $subtasks[] = trim($st);
                }
            }
        }

        // 模型点名的子 Agent 不存在时不要硬派——降级成 direct 自己干，
        // 比派给一个不存在的角色（然后在编排层静默回退）更容易查
        if (($strategy === ExecutionStrategy::DELEGATE)
            && ($agent === '' || $this->subAgents === null || $this->subAgents->get($agent) === null)) {
            return StrategyDecision::direct(
                '模型建议委派给 "' . $agent . '"，但该子 Agent 不存在，改为直接执行',
                $confidence
            );
        }
        // 并行至少要有两件事，否则并行没有意义
        if ($strategy === ExecutionStrategy::PARALLEL && count($subtasks) < 2) {
            return StrategyDecision::direct('模型建议并行但只给出不足两个子任务，改为直接执行', $confidence);
        }

        switch ($strategy) {
            case ExecutionStrategy::PLAN:
                return StrategyDecision::plan($reason, $subtasks);
            case ExecutionStrategy::DELEGATE:
                return StrategyDecision::delegate($agent, $reason);
            case ExecutionStrategy::PARALLEL:
                return StrategyDecision::parallel($subtasks, $reason, $agent);
            case ExecutionStrategy::BACKGROUND:
                return StrategyDecision::background($reason, $agent);
            case ExecutionStrategy::ASK_USER:
                return StrategyDecision::askUser($reason);
            case ExecutionStrategy::VERIFY:
                return StrategyDecision::verify($reason);
            default:
                return StrategyDecision::direct($reason, $confidence);
        }
    }

    /**
     * 从模型返回里抠出 JSON 对象
     *
     * 模型经常在 JSON 外面裹一层 ```json 围栏或一句「好的，我的判断是：」，
     * 直接 json_decode 会失败。要求它别这么干不如自己容错——
     * 为一个围栏符号退回规则版不值当。
     *
     * @param string $raw
     * @return array<string, mixed>|null
     */
    protected function extractJson($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // 退一步：取第一个 { 到最后一个 } 之间的内容
        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 指定决策专用模型
     *
     * 决策只要一小段 JSON，用便宜的小模型就够——没必要为「决定怎么干」
     * 付主力模型的价钱。
     *
     * @param string $model
     * @return $this
     */
    public function setModel($model)
    {
        $this->model = (string) $model !== '' ? (string) $model : null;
        return $this;
    }

    /** @return string 最近一次模型原始返回 */
    public function lastRaw()
    {
        return $this->lastRaw;
    }

    /** @return \Throwable|null 最近一次决策失败 */
    public function lastError()
    {
        return $this->lastError;
    }

    /** @return $this 清空缓存 */
    public function clearCache()
    {
        $this->cache = [];
        return $this;
    }
}
