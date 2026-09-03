<?php
namespace Ai\Agent\Memory;

/**
 * MemoryRetriever——记忆检索器
 *
 * `MemoryManager::forPrompt()` 会把所有作用域的记忆整段注入系统提示词。记忆攒多了
 * 之后这就成了负担：几千字的历史里可能只有两行和当前任务有关，其余全在挤占上下文。
 *
 * 检索器把记忆按行拆成条目，按与查询的相关性排序，只注入最相关的几条：
 *
 * ```php
 * $retriever = new MemoryRetriever($mm);
 *
 * $hits = $retriever->retrieve('登录接口报 401');
 * // [['scope' => 'project', 'text' => '登录走 JWT，密钥在 config/jwt.php', 'score' => 62.5, 'line' => 3], ...]
 *
 * echo $retriever->forPrompt('登录接口报 401');
 * // <memory-relevant query="登录接口报 401">
 * // [project] 登录走 JWT，密钥在 config/jwt.php
 * // </memory-relevant>
 * ```
 *
 * 相关性是纯本地计算，不调模型：英文按词、中文按二元组切分，命中越多、
 * 覆盖查询的比例越高，分数越高。这套打分认字面不认语义——问"鉴权"匹配不到
 * 只写了"登录"的记忆。需要语义检索时用 `setScorer()` 换成向量或模型打分。
 */
class MemoryRetriever
{
    /** @var MemoryManager */
    protected $manager;

    /** @var int 默认返回的最大条目数 */
    protected $topK = 5;

    /** @var float 相关性分数下限，低于此值不返回 */
    protected $minScore = 1.0;

    /** @var callable|null 自定义打分器 function(string $query, string $text): float */
    protected $scorer = null;

    /**
     * @param MemoryManager $manager
     * @param array<string, mixed> $options topK / minScore / scorer
     */
    public function __construct(MemoryManager $manager, array $options = [])
    {
        $this->manager = $manager;
        if (isset($options['topK'])) {
            $this->topK = max(1, (int) $options['topK']);
        }
        if (isset($options['minScore'])) {
            $this->minScore = (float) $options['minScore'];
        }
        if (isset($options['scorer'])) {
            $this->scorer = $options['scorer'];
        }
    }

    /**
     * 检索与查询相关的记忆条目
     *
     * @param string $query 查询文本（通常是当前任务描述）
     * @param string[] $scopes 限定作用域，空数组表示全部作用域
     * @param int $limit 最多返回几条，0 表示用默认 topK
     * @return array<int, array{scope: string, line: int, text: string, score: float}>
     */
    public function retrieve($query, array $scopes = [], $limit = 0)
    {
        $query = (string) $query;
        if (trim($query) === '') {
            return [];
        }

        $entries = $this->entries($scopes);
        $ranked = $this->relevancyRank($query, $entries);

        $limit = $limit > 0 ? (int) $limit : $this->topK;
        return array_slice($ranked, 0, $limit);
    }

    /**
     * 关键词搜索——按字面包含匹配，不打分排序
     *
     * @param string $keyword
     * @param string|null $scope 限定作用域，null 表示全部
     * @return array<int, array{scope: string, line: int, text: string, score: float}>
     */
    public function search($keyword, $scope = null)
    {
        $keyword = trim((string) $keyword);
        if ($keyword === '') {
            return [];
        }

        $scopes = $scope === null ? [] : [(string) $scope];
        $hits = [];
        foreach ($this->entries($scopes) as $entry) {
            if (stripos($entry['text'], $keyword) !== false) {
                $hits[] = $entry;
            }
        }
        return $hits;
    }

    /**
     * 按相关性排序记忆条目
     *
     * 低于 minScore 的条目被丢弃；分数相同时保持原有顺序（作用域顺序 + 行号）。
     *
     * @param string $query
     * @param array<int, array{scope: string, line: int, text: string, score: float}> $memories
     * @return array<int, array{scope: string, line: int, text: string, score: float}>
     */
    public function relevancyRank($query, array $memories)
    {
        $scored = [];
        foreach ($memories as $i => $entry) {
            $score = $this->score((string) $query, $entry['text']);
            if ($score < $this->minScore) {
                continue;
            }
            $entry['score'] = $score;
            $scored[] = ['order' => $i, 'entry' => $entry];
        }

        usort($scored, function (array $a, array $b) {
            if ($a['entry']['score'] === $b['entry']['score']) {
                return $a['order'] < $b['order'] ? -1 : 1;
            }
            return $a['entry']['score'] > $b['entry']['score'] ? -1 : 1;
        });

        $result = [];
        foreach ($scored as $item) {
            $result[] = $item['entry'];
        }
        return $result;
    }

    /**
     * 生成注入系统提示词的相关记忆块
     *
     * 查询为空时退回 `MemoryManager::forPrompt()`（注入全部记忆），
     * 这样在没有明确任务目标时行为与升级前一致。
     *
     * @param string $query
     * @param string[] $scopes
     * @return string
     */
    public function forPrompt($query = '', array $scopes = [])
    {
        if (!$this->manager->isEnabled()) {
            return '';
        }
        if (trim((string) $query) === '') {
            return $this->manager->forPrompt();
        }

        $hits = $this->retrieve($query, $scopes);
        if (!$hits) {
            return '';
        }

        $lines = [];
        foreach ($hits as $hit) {
            $lines[] = '[' . $hit['scope'] . '] ' . $hit['text'];
        }

        return '<memory-relevant query="' . str_replace('"', "'", (string) $query) . "\">\n"
            . implode("\n", $lines)
            . "\n</memory-relevant>";
    }

    /**
     * 把记忆按行拆成条目
     *
     * 空行与 markdown 标题行被跳过——标题是分节标记，不是记忆内容本身。
     *
     * @param string[] $scopes 空数组表示全部作用域
     * @return array<int, array{scope: string, line: int, id: string, date: string, text: string, raw: string, score: float}>
     */
    public function entries(array $scopes = [])
    {
        $targets = $scopes ? $scopes : MemoryManager::validScopes();
        $entries = [];
        foreach ($targets as $scope) {
            $scope = (string) $scope;
            if (!MemoryManager::isValidScope($scope)) {
                continue;
            }
            $content = $this->manager->read($scope);
            if (trim($content) === '') {
                continue;
            }
            $lines = preg_split('/\r?\n/', $content);
            foreach ($lines === false ? [] : $lines as $no => $line) {
                $raw = trim($line);
                if ($raw === '' || strpos($raw, '#') === 0) {
                    continue;  // 空行 / markdown 标题（# 开头）跳过
                }
                $parsed = $this->parseEntry($raw);
                $entries[] = [
                    'scope' => $scope,
                    'line'  => $no + 1,
                    'id'    => $parsed['id'],
                    'date'  => $parsed['date'],
                    'text'  => $parsed['text'],
                    'raw'   => $raw,
                    'score' => 0.0,
                ];
            }
        }
        return $entries;
    }

    /**
     * 解析一条记忆行的三段结构：`- [date] (#id) text`
     *
     * 三段都可缺省，向后兼容：旧的无前缀行整行即文本（打分不受影响，见 dev.md 14.3）。
     * 打分只用 `text`，不让 6 位 hex 的 id 干扰中日韩二元组匹配。
     *
     * @param string $raw 已 trim 的整行
     * @return array{id: string, date: string, text: string}
     */
    protected function parseEntry($raw)
    {
        $rest = $raw;
        // 可选 bullet
        if (preg_match('/^-\s+/', $rest, $bm)) {
            $rest = substr($rest, strlen($bm[0]));
        }
        $id = '';
        $date = '';
        $matched = false;
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2})\]\s*/', $rest, $dm)) {
            $date = $dm[1];
            $rest = substr($rest, strlen($dm[0]));
            $matched = true;
        }
        if (preg_match('/^\(#([0-9a-fA-F]{4,12})\)\s*/', $rest, $im)) {
            $id = strtolower($im[1]);
            $rest = substr($rest, strlen($im[0]));
            $matched = true;
        }
        // 没有任何日期/id 前缀 → 整行即文本（保持旧打分行为）
        return [
            'id'   => $id,
            'date' => $date,
            'text' => $matched ? trim($rest) : $raw,
        ];
    }

    /**
     * 压缩指定作用域——只保留最近 N 条记忆
     *
     * 记忆文件按追加写入，越靠后越新。长跑任务里记忆会无限增长，
     * 定期压缩比等到超出 maxInject 被截断更可控。
     *
     * @param string $scope
     * @param int $keep 保留条目数
     * @return int 实际删除的条目数
     */
    public function compress($scope, $keep)
    {
        $keep = max(0, (int) $keep);
        $entries = $this->entries([(string) $scope]);
        $total = count($entries);
        if ($total <= $keep) {
            return 0;
        }

        $kept = array_slice($entries, $total - $keep);
        $lines = [];
        foreach ($kept as $entry) {
            // 原样保留 `- [date] (#id) ` 前缀，不重新序列化成裸文本（见 dev.md 14.3）
            $lines[] = $entry['raw'];
        }
        $this->manager->write((string) $scope, implode("\n", $lines));
        return $total - $keep;
    }

    /**
     * 清理过期记忆
     *
     * 只处理带日期前缀的条目（`[2026-09-02] ...` 或 `2026-09-02 ...`），
     * 无日期的条目一律保留——分不清写入时间就不该替用户决定它过期了。
     *
     * @param string $scope
     * @param int $days 保留最近多少天
     * @return int 实际删除的条目数
     */
    public function expire($scope, $days)
    {
        $days = max(0, (int) $days);
        $cutoff = time() - $days * 86400;
        $entries = $this->entries([(string) $scope]);
        if (!$entries) {
            return 0;
        }

        $kept = [];
        $removed = 0;
        foreach ($entries as $entry) {
            // 优先用解析出的 date 段（新格式日期在前缀里，已从 text 剥离）；
            // 无 date 段再回退到从 raw 里抽取，兼容旧的裸日期行
            $ts = $entry['date'] !== '' ? $this->dateToTs($entry['date']) : $this->extractDate($entry['raw']);
            if ($ts !== null && $ts < $cutoff) {
                $removed++;
                continue;
            }
            $kept[] = $entry['raw'];  // 原样保留前缀
        }

        if ($removed > 0) {
            $this->manager->write((string) $scope, implode("\n", $kept));
        }
        return $removed;
    }

    /**
     * 设置自定义打分器（如向量相似度、模型打分）
     *
     * @param callable|null $scorer function(string $query, string $text): float
     * @return $this
     */
    public function setScorer($scorer)
    {
        $this->scorer = $scorer;
        return $this;
    }

    /**
     * @param int $topK
     * @return $this
     */
    public function setTopK($topK)
    {
        $this->topK = max(1, (int) $topK);
        return $this;
    }

    /** @return int */
    public function getTopK()
    {
        return $this->topK;
    }

    /**
     * @param float $minScore
     * @return $this
     */
    public function setMinScore($minScore)
    {
        $this->minScore = (float) $minScore;
        return $this;
    }

    /** @return float */
    public function getMinScore()
    {
        return $this->minScore;
    }

    /**
     * 给一条记忆打分
     *
     * 分数 = 命中词占查询词的比例 × 100 + 命中次数，范围下限 0。
     *
     * @param string $query
     * @param string $text
     * @return float
     */
    protected function score($query, $text)
    {
        if ($this->scorer !== null) {
            return (float) call_user_func($this->scorer, $query, $text);
        }

        $queryTokens = $this->tokenize($query);
        if (!$queryTokens) {
            return 0.0;
        }
        $textLower = $this->normalize($text);

        $matched = 0;
        $hits = 0;
        foreach ($queryTokens as $token) {
            $count = substr_count($textLower, $token);
            if ($count > 0) {
                $matched++;
                $hits += $count;
            }
        }
        if ($matched === 0) {
            return 0.0;
        }

        return round($matched / count($queryTokens) * 100 + $hits, 2);
    }

    /**
     * 切词——英文数字按词，中文按二元组
     *
     * 二元组（"登录接口" → "登录"、"录接"、"接口"）是无分词库时的折中：
     * 单字太容易误命中，整串又几乎命不中。
     *
     * @param string $text
     * @return string[] 去重后的词表
     */
    protected function tokenize($text)
    {
        $text = $this->normalize($text);
        $tokens = [];

        // 英文 / 数字词
        if (preg_match_all('/[a-z0-9_]{2,}/u', $text, $m)) {
            foreach ($m[0] as $word) {
                $tokens[] = $word;
            }
        }

        // 中日韩字符：二元组
        if (preg_match_all('/[\x{4e00}-\x{9fff}\x{3040}-\x{30ff}]+/u', $text, $m)) {
            foreach ($m[0] as $run) {
                $chars = preg_split('//u', $run, -1, PREG_SPLIT_NO_EMPTY);
                if ($chars === false) {
                    continue;
                }
                $count = count($chars);
                if ($count === 1) {
                    $tokens[] = $chars[0];
                    continue;
                }
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
     * 从条目文本里提取日期，取不到返回 null
     *
     * @param string $text
     * @return int|null Unix 时间戳
     */
    protected function extractDate($text)
    {
        if (!preg_match('/^\[?(\d{4})-(\d{2})-(\d{2})\]?/', trim((string) $text), $m)) {
            return null;
        }
        $ts = mktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
        return $ts === false ? null : $ts;
    }

    /**
     * 把 YYYY-MM-DD 转 Unix 时间戳，非法返回 null
     *
     * @param string $date
     * @return int|null
     */
    protected function dateToTs($date)
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $m)) {
            return null;
        }
        $ts = mktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
        return $ts === false ? null : $ts;
    }
}
