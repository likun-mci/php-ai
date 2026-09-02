<?php
namespace Ai\Agent\Orchestrator;

use Ai\Agent\SubAgent\SubAgentManager;

/**
 * StrategySelector——决定一个任务该怎么干
 *
 * 默认实现是**基于规则**的，不调模型：看任务描述的长度、动词、涉及范围，
 * 再看手上有没有合适的子 Agent。这么设计有两个原因——多花一次模型调用去决定
 * 「要不要多花模型调用」不划算；规则版的决策可复现，出问题能查。
 *
 * 拿不准时一律退回 `DIRECT`。保守执行的代价是多跑几轮工具，错误委派的代价是
 * 一个子 Agent 带着错误的上下文跑十几轮——前者便宜得多。
 *
 * ```php
 * $selector = new StrategySelector($subAgentManager);
 * $decision = $selector->select('重构整个认证系统');
 * $decision->getStrategy();   // 'plan'
 *
 * // 换成模型驱动
 * $selector->setResolver(function ($task, $context) use ($ai) { … });
 * ```
 */
class StrategySelector
{
    /** @var SubAgentManager|null 用于按 description 匹配子 Agent */
    protected $subAgents = null;

    /** @var callable|null 自定义决策器 function(string $task, array $context): StrategyDecision|null */
    protected $resolver = null;

    /** @var bool 是否允许自动委派 */
    protected $autoDelegate = true;

    /** @var bool 是否允许自动规划 */
    protected $autoPlan = true;

    /** @var int 任务描述超过这个字符数视为复杂任务 */
    protected $planThreshold = 60;

    /** @var array<string, string[]> 策略 => 触发关键词 */
    protected static $keywords = [
        ExecutionStrategy::PLAN => [
            '重构', '迁移', '改造', '整个', '全面', '系统性', '架构',
            'refactor', 'migrate', 'redesign', 'rewrite', 'overhaul',
        ],
        ExecutionStrategy::VERIFY => [
            '验证', '检查一下', '跑一下测试', '确认', '复核',
            'verify', 'validate', 'check that',
        ],
        ExecutionStrategy::BACKGROUND => [
            '后台', '异步', '慢慢', '不用等',
            'background', 'async',
        ],
    ];

    /** @var string[] 表示"多件事"的分隔信号，用于识别可并行任务 */
    protected static $parallelMarkers = ['、', '；', ';', ' 和 ', ' 以及 ', ' and ', ','];

    /**
     * @var string[] 明确要求改动的动词
     *
     * 一句「修复 Bug，改完跑测试确认通过」里同时有「修复」和「确认」，
     * 它是修复任务而不是验证任务——验证只是修复的收尾。所以只要出现改动动词，
     * 就不再按 verify 处理，否则 VERIFY 一旦走上独立执行路径（只跑验证不改代码），
     * 这类任务会什么都不做就返回。
     */
    protected static $mutatingVerbs = [
        '修复', '修改', '实现', '新增', '增加', '删除', '重构', '重写', '优化',
        '补充', '调整', '改成', '改为', '换成', '迁移', '升级', '接入',
        'fix', 'implement', 'add ', 'remove', 'refactor', 'rewrite', 'update',
        'change', 'migrate', 'upgrade', 'create',
    ];

    /**
     * @param SubAgentManager|null $subAgents
     * @param array<string, mixed> $options autoDelegate / autoPlan / planThreshold / resolver
     */
    public function __construct($subAgents = null, array $options = [])
    {
        $this->subAgents = $subAgents instanceof SubAgentManager ? $subAgents : null;
        if (isset($options['autoDelegate'])) {
            $this->autoDelegate = (bool) $options['autoDelegate'];
        }
        if (isset($options['autoPlan'])) {
            $this->autoPlan = (bool) $options['autoPlan'];
        }
        if (isset($options['planThreshold'])) {
            $this->planThreshold = max(1, (int) $options['planThreshold']);
        }
        if (isset($options['resolver'])) {
            $this->resolver = $options['resolver'];
        }
    }

    /**
     * 为一个任务选择执行策略
     *
     * @param string $task 任务描述
     * @param array<string, mixed> $context 额外上下文：has_plan / iteration / files / budget_left
     * @return StrategyDecision
     */
    public function select($task, array $context = [])
    {
        $task = (string) $task;

        // 自定义决策器优先；返回 null 表示"我也拿不准"，退回规则判断
        if ($this->resolver !== null) {
            $decision = call_user_func($this->resolver, $task, $context);
            if ($decision instanceof StrategyDecision) {
                return $decision;
            }
        }

        $trimmed = trim($task);
        if ($trimmed === '') {
            return StrategyDecision::askUser('任务描述为空');
        }

        // 已经在执行计划中途，不要再重新规划
        if (!empty($context['has_plan'])) {
            return $this->selectForPlanStep($trimmed, $context);
        }

        // 明确的验证类任务——但带改动动词的不算，那是"改完顺便验证"
        if ($this->matchKeywords($trimmed, ExecutionStrategy::VERIFY) && !$this->hasMutatingVerb($trimmed)) {
            return StrategyDecision::verify('任务描述指向验证既有改动');
        }

        // 明确要求后台
        if ($this->matchKeywords($trimmed, ExecutionStrategy::BACKGROUND)) {
            return StrategyDecision::background('任务描述要求后台执行');
        }

        // 多个互不相关的子任务 → 并行
        $subtasks = $this->splitParallel($trimmed);
        if (count($subtasks) >= 2) {
            $agent = $this->matchAgent($trimmed);
            return StrategyDecision::parallel(
                $subtasks,
                '识别到 ' . count($subtasks) . ' 个互不相关的子任务',
                $agent
            );
        }

        // 复杂任务 → 先规划
        if ($this->autoPlan && $this->looksComplex($trimmed)) {
            return StrategyDecision::plan('任务范围较大，先拆解为有序步骤');
        }

        // 有合适的专职子 Agent → 委派
        if ($this->autoDelegate) {
            $agent = $this->matchAgent($trimmed);
            if ($agent !== '') {
                return StrategyDecision::delegate(
                    $agent,
                    '任务与子 Agent "' . $agent . '" 的职责匹配'
                );
            }
        }

        return StrategyDecision::direct('任务范围明确，直接执行');
    }

    /**
     * 计划执行途中的单步策略
     *
     * 已经在计划里了就不该再规划，只在「这一步适合谁干」上做判断。
     *
     * @param string $task
     * @param array<string, mixed> $context
     * @return StrategyDecision
     */
    protected function selectForPlanStep($task, array $context)
    {
        if ($this->autoDelegate) {
            $agent = $this->matchAgent($task);
            if ($agent !== '') {
                return StrategyDecision::delegate($agent, '计划步骤匹配子 Agent "' . $agent . '"');
            }
        }
        return StrategyDecision::direct('计划步骤直接执行');
    }

    /**
     * 按 description 匹配最合适的子 Agent
     *
     * 打分规则：任务描述与子 Agent 的 name + description 的词命中数。
     * 命中为 0 时返回空串——宁可自己干，也不硬派给一个不相关的子 Agent。
     *
     * @param string $task
     * @return string 子 Agent 名，匹配不到返回空串
     */
    public function matchAgent($task)
    {
        if ($this->subAgents === null) {
            return '';
        }

        $tokens = $this->tokenize($task);
        if (!$tokens) {
            return '';
        }

        $best = '';
        $bestScore = 0.0;

        foreach ($this->subAgents->all() as $name => $def) {
            $haystack = $this->normalize($name . ' ' . $def->getDescription());
            $score = 0.0;
            foreach ($tokens as $token) {
                if (strpos($haystack, $token) !== false) {
                    $score += 1.0;
                }
            }
            // 名字被直接点名时权重加倍——"让 explorer 去看看"应当稳定命中
            if (strpos($this->normalize($task), $this->normalize((string) $name)) !== false) {
                $score += 3.0;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (string) $name;
            }
        }

        return $bestScore >= 2.0 ? $best : '';
    }

    /**
     * 任务里有没有明确要求改动的动词
     *
     * @param string $task
     * @return bool
     */
    public function hasMutatingVerb($task)
    {
        $normalized = $this->normalize($task);
        foreach (self::$mutatingVerbs as $verb) {
            if (strpos($normalized, $this->normalize($verb)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 任务看起来复杂吗
     *
     * @param string $task
     * @return bool
     */
    public function looksComplex($task)
    {
        if ($this->matchKeywords($task, ExecutionStrategy::PLAN)) {
            return true;
        }
        // 长描述通常意味着多个要求
        if ($this->length($task) >= $this->planThreshold) {
            return true;
        }
        // 显式的步骤编号
        return (bool) preg_match('/(^|\n)\s*[1-9][.、)]\s*\S/u', $task);
    }

    /**
     * 把任务拆成可并行的子任务
     *
     * 只在出现明确的并列结构、且每一项都足够短时才拆——
     * 把一句完整需求硬切成几段丢给不同 Agent，比不拆更糟。
     *
     * @param string $task
     * @return string[] 拆不出来时返回空数组
     */
    public function splitParallel($task)
    {
        // 「分析 A、B、C」这类：动词 + 并列宾语
        if (!preg_match('/^(.{0,12}?(分析|检查|调查|审查|扫描|analyze|review|inspect).{0,6}?)[:：]?\s*(.+)$/u', $task, $m)) {
            return [];
        }

        $prefix = trim($m[1]);
        $rest = trim($m[3]);
        $parts = $this->splitByMarkers($rest);
        if (count($parts) < 2) {
            return [];
        }

        $subtasks = [];
        foreach ($parts as $part) {
            $part = trim($part);
            // 每一项都该是短名词短语；出现长句说明这不是并列结构
            if ($part === '' || $this->length($part) > 20) {
                return [];
            }
            $subtasks[] = $prefix . ' ' . $part;
        }
        return count($subtasks) >= 2 ? $subtasks : [];
    }

    /**
     * 注入自定义决策器（模型驱动策略）
     *
     * 返回 null 时退回规则判断。
     *
     * @param callable|null $resolver function(string $task, array $context): ?StrategyDecision
     * @return $this
     */
    public function setResolver($resolver)
    {
        $this->resolver = $resolver;
        return $this;
    }

    /**
     * @param SubAgentManager|null $subAgents
     * @return $this
     */
    public function setSubAgents($subAgents)
    {
        $this->subAgents = $subAgents instanceof SubAgentManager ? $subAgents : null;
        return $this;
    }

    /**
     * @param bool $enabled
     * @return $this
     */
    public function setAutoDelegate($enabled)
    {
        $this->autoDelegate = (bool) $enabled;
        return $this;
    }

    /**
     * @param bool $enabled
     * @return $this
     */
    public function setAutoPlan($enabled)
    {
        $this->autoPlan = (bool) $enabled;
        return $this;
    }

    /**
     * @param string $task
     * @param string $strategy
     * @return bool
     */
    protected function matchKeywords($task, $strategy)
    {
        if (!isset(self::$keywords[$strategy])) {
            return false;
        }
        $normalized = $this->normalize($task);
        foreach (self::$keywords[$strategy] as $keyword) {
            if (strpos($normalized, $this->normalize($keyword)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 按并列分隔符切分
     *
     * @param string $text
     * @return string[]
     */
    protected function splitByMarkers($text)
    {
        $normalized = $text;
        foreach (self::$parallelMarkers as $marker) {
            $normalized = str_replace($marker, "\x00", $normalized);
        }
        $parts = explode("\x00", $normalized);
        return array_values(array_filter(array_map('trim', $parts), function ($p) {
            return $p !== '';
        }));
    }

    /**
     * 切词：英文按词，中文按二元组
     *
     * @param string $text
     * @return string[]
     */
    protected function tokenize($text)
    {
        $text = $this->normalize($text);
        $tokens = [];

        if (preg_match_all('/[a-z0-9_]{3,}/u', $text, $m)) {
            foreach ($m[0] as $word) {
                $tokens[] = $word;
            }
        }
        if (preg_match_all('/[\x{4e00}-\x{9fff}]+/u', $text, $m)) {
            foreach ($m[0] as $run) {
                $chars = preg_split('//u', $run, -1, PREG_SPLIT_NO_EMPTY);
                if ($chars === false) {
                    continue;
                }
                $count = count($chars);
                for ($i = 0; $i < $count - 1; $i++) {
                    $tokens[] = $chars[$i] . $chars[$i + 1];
                }
            }
        }
        return array_values(array_unique($tokens));
    }

    /**
     * @param string $text
     * @return string
     */
    protected function normalize($text)
    {
        $text = (string) $text;
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }

    /**
     * 字符数（按字符而非字节，中文任务描述不会因为编码被误判成长文本）
     *
     * @param string $text
     * @return int
     */
    protected function length($text)
    {
        return function_exists('mb_strlen') ? mb_strlen((string) $text, 'UTF-8') : strlen((string) $text);
    }
}
