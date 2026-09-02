<?php
namespace Ai\Agent\Memory;

/**
 * MemoryConsolidator——记忆整理
 *
 * **不要让所有工具结果自动进记忆。** 那样记忆很快会变成一堆噪音：读过的每个文件、
 * 跑过的每条命令都在里面，真正重要的两三条反而被淹没，检索时也捞不出来。
 *
 * 正确的路径是先筛后写：
 *
 * ```text
 * Events → Task Result → Reflection → Memory Candidate → Consolidation → Memory
 * ```
 *
 * ```php
 * $consolidator = new MemoryConsolidator($memoryManager);
 *
 * // 反思之后，把值得记的挑出来
 * $consolidator->propose('project', '登录走 JWT，密钥在 config/jwt.php', ['confidence' => 0.9]);
 * $consolidator->proposeFromResult($agentResult, 'task');
 *
 * // 整理并写入：去重、按置信度排序、超量截断
 * $written = $consolidator->consolidate();   // 实际写入的条数
 * ```
 *
 * 候选不会立刻写盘——`consolidate()` 之前它们只在内存里排队。这一步存在的意义
 * 就是「攒一批再筛」：单条判断谁重要很难，一批放一起比较就容易多了。
 */
class MemoryConsolidator
{
    /** @var MemoryManager */
    protected $manager;

    /** @var array<int, array{scope: string, content: string, confidence: float, source: string}> */
    protected $candidates = [];

    /** @var float 低于这个置信度的候选直接丢掉 */
    protected $minConfidence = 0.5;

    /** @var int 一次整理最多写入多少条 */
    protected $maxPerRun = 10;

    /** @var float 与已有记忆相似度超过它就算重复 */
    protected $dedupeThreshold = 60.0;

    /** @var callable|null 自定义筛选器 function(array $candidate): bool */
    protected $filter = null;

    /**
     * @param MemoryManager $manager
     * @param array<string, mixed> $options minConfidence / maxPerRun / dedupeThreshold / filter
     */
    public function __construct(MemoryManager $manager, array $options = [])
    {
        $this->manager = $manager;
        if (isset($options['minConfidence'])) {
            $this->minConfidence = (float) $options['minConfidence'];
        }
        if (isset($options['maxPerRun'])) {
            $this->maxPerRun = max(1, (int) $options['maxPerRun']);
        }
        if (isset($options['dedupeThreshold'])) {
            $this->dedupeThreshold = (float) $options['dedupeThreshold'];
        }
        if (isset($options['filter'])) {
            $this->filter = $options['filter'];
        }
    }

    /**
     * 提出一条候选记忆
     *
     * @param string $scope user / project / session / task / agent
     * @param string $content
     * @param array<string, mixed> $options confidence / source
     * @return bool 作用域非法或内容为空时返回 false
     */
    public function propose($scope, $content, array $options = [])
    {
        $scope = (string) $scope;
        $content = trim((string) $content);

        if ($content === '' || !MemoryManager::isValidScope($scope)) {
            return false;
        }

        $this->candidates[] = [
            'scope'      => $scope,
            'content'    => $content,
            'confidence' => isset($options['confidence']) ? (float) $options['confidence'] : 0.7,
            'source'     => isset($options['source']) ? (string) $options['source'] : '',
        ];
        return true;
    }

    /**
     * 从反思结果里提候选
     *
     * 反思判定「已完成」时，它给出的原因往往就是这次任务真正的结论，值得记。
     * 判定「未完成」时记的是「试过什么没成」——同样有价值，下次不必再撞一遍。
     *
     * @param \Ai\Agent\Reflection\ReflectionResult $reflection
     * @param string $scope
     * @return bool
     */
    public function proposeFromReflection($reflection, $scope = MemoryManager::SCOPE_TASK)
    {
        if (!is_object($reflection) || !method_exists($reflection, 'getReason')) {
            return false;
        }
        $reason = trim((string) $reflection->getReason());
        if ($reason === '') {
            return false;
        }

        $success = method_exists($reflection, 'isSuccess') && $reflection->isSuccess();
        return $this->propose($scope, ($success ? '[结论] ' : '[未完成] ') . $reason, [
            'confidence' => $success ? 0.8 : 0.6,
            'source'     => 'reflection',
        ]);
    }

    /**
     * 从执行结果里提候选
     *
     * @param \Ai\Agent\AgentResult $result
     * @param string $scope
     * @return bool
     */
    public function proposeFromResult($result, $scope = MemoryManager::SCOPE_TASK)
    {
        if (!is_object($result) || !method_exists($result, 'getText')) {
            return false;
        }
        $text = trim((string) $result->getText());
        if ($text === '') {
            return false;
        }
        // 只取首段：完整回复往往几百字，整段塞进记忆等于没筛
        $firstParagraph = preg_split('/\n\s*\n/', $text);
        $summary = $firstParagraph === false ? $text : trim($firstParagraph[0]);

        return $this->propose($scope, $summary, [
            'confidence' => method_exists($result, 'isDone') && $result->isDone() ? 0.75 : 0.5,
            'source'     => 'result',
        ]);
    }

    /**
     * 整理候选并写入记忆
     *
     * 步骤：丢掉低置信度的 → 过自定义筛选器 → 与已有记忆去重 → 按置信度排序 →
     * 取前 N 条写入。写完清空候选队列。
     *
     * @return int 实际写入的条数
     */
    public function consolidate()
    {
        if (!$this->candidates) {
            return 0;
        }

        $accepted = [];
        foreach ($this->candidates as $candidate) {
            if ($candidate['confidence'] < $this->minConfidence) {
                continue;
            }
            if ($this->filter !== null && !call_user_func($this->filter, $candidate)) {
                continue;
            }
            if ($this->isDuplicate($candidate)) {
                continue;
            }
            // 同一批里的重复也要去掉
            if ($this->isDuplicateAmong($candidate, $accepted)) {
                continue;
            }
            $accepted[] = $candidate;
        }

        // 置信度高的先写——超出 maxPerRun 时留下的是更可靠的那些
        usort($accepted, function ($a, $b) {
            if ($a['confidence'] === $b['confidence']) {
                return 0;
            }
            return $a['confidence'] > $b['confidence'] ? -1 : 1;
        });
        $accepted = array_slice($accepted, 0, $this->maxPerRun);

        $written = 0;
        foreach ($accepted as $candidate) {
            if ($this->manager->remember($candidate['scope'], $candidate['content'])) {
                $written++;
            }
        }

        $this->candidates = [];
        return $written;
    }

    /**
     * 当前候选
     *
     * @return array<int, array<string, mixed>>
     */
    public function candidates()
    {
        return $this->candidates;
    }

    /**
     * 丢掉全部候选（不写入）
     *
     * @return $this
     */
    public function discard()
    {
        $this->candidates = [];
        return $this;
    }

    /**
     * 自定义筛选器——返回 false 的候选不写入
     *
     * @param callable|null $filter function(array $candidate): bool
     * @return $this
     */
    public function setFilter($filter)
    {
        $this->filter = $filter;
        return $this;
    }

    /**
     * @param float $threshold
     * @return $this
     */
    public function setMinConfidence($threshold)
    {
        $this->minConfidence = (float) $threshold;
        return $this;
    }

    /**
     * @param int $max
     * @return $this
     */
    public function setMaxPerRun($max)
    {
        $this->maxPerRun = max(1, (int) $max);
        return $this;
    }

    /**
     * 与已有记忆重复吗
     *
     * 用检索器的相关性打分做近似判断：分数高说明说的是同一件事。
     *
     * @param array<string, mixed> $candidate
     * @return bool
     */
    protected function isDuplicate(array $candidate)
    {
        $existing = $this->manager->retriever()->entries([$candidate['scope']]);
        if (!$existing) {
            return false;
        }

        $hits = $this->manager->retriever()->relevancyRank($candidate['content'], $existing);
        if (!$hits) {
            return false;
        }
        return $hits[0]['score'] >= $this->dedupeThreshold;
    }

    /**
     * 与同批候选重复吗
     *
     * @param array<string, mixed> $candidate
     * @param array<int, array<string, mixed>> $accepted
     * @return bool
     */
    protected function isDuplicateAmong(array $candidate, array $accepted)
    {
        foreach ($accepted as $item) {
            if ($item['scope'] !== $candidate['scope']) {
                continue;
            }
            if ($item['content'] === $candidate['content']) {
                return true;
            }
        }
        return false;
    }
}
