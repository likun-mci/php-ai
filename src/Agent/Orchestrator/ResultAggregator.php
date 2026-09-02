<?php
namespace Ai\Agent\Orchestrator;

/**
 * ResultAggregator——子 Agent 结果聚合
 *
 * 并行派出去三个 explorer，回来三份几千字的报告。**主 Agent 默认只该收到摘要**，
 * 完整内容留在各自的 transcript 里按需查——否则并行省下的时间，全赔在被污染的
 * 上下文里了。这是 Phase 5 上下文治理的核心手段。
 *
 * ```php
 * $aggregator = new ResultAggregator();
 * $summary = $aggregator->aggregate($results);
 *
 * $summary['summary'];          // 给主 Agent 的合并摘要
 * $summary['findings'];         // 各路的关键结论
 * $summary['files'];            // 提到的文件（去重）
 * $summary['errors'];           // 失败的那几路
 * $summary['recommendations'];  // 建议
 * $summary['transcripts'];      // task_id 列表，要看细节时按这个查
 * ```
 *
 * 默认是**规则聚合**（截断 + 归类 + 去重），不调模型。需要更好的摘要时注入
 * `setSummarizer()` 用模型合并——但那要多花一次调用，默认不替使用者做这个决定。
 */
class ResultAggregator
{
    /** @var int 每路结论保留的最大字符数 */
    protected $perResultLimit = 600;

    /** @var int 合并摘要的最大字符数 */
    protected $summaryLimit = 3000;

    /** @var callable|null 自定义摘要器 function(array $results): string */
    protected $summarizer = null;

    /**
     * @param array<string, mixed> $options perResultLimit / summaryLimit / summarizer
     */
    public function __construct(array $options = [])
    {
        if (isset($options['perResultLimit'])) {
            $this->perResultLimit = max(50, (int) $options['perResultLimit']);
        }
        if (isset($options['summaryLimit'])) {
            $this->summaryLimit = max(100, (int) $options['summaryLimit']);
        }
        if (isset($options['summarizer'])) {
            $this->summarizer = $options['summarizer'];
        }
    }

    /**
     * 聚合多路结果
     *
     * @param array<int, array<string, mixed>> $results 每项：agent / task / status / summary / task_id
     * @return array<string, mixed> summary / findings / files / errors / recommendations / transcripts / stats
     */
    public function aggregate(array $results)
    {
        $findings = [];
        $errors = [];
        $files = [];
        $recommendations = [];
        $transcripts = [];
        $completed = 0;

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            $agent = isset($result['agent']) ? (string) $result['agent'] : '';
            $task = isset($result['task']) ? (string) $result['task'] : '';
            $status = isset($result['status']) ? (string) $result['status'] : '';
            $text = isset($result['summary']) ? (string) $result['summary'] : '';

            if (isset($result['task_id'])) {
                $transcripts[] = (string) $result['task_id'];
            }

            if ($status !== 'completed') {
                $errors[] = [
                    'agent'  => $agent,
                    'task'   => $task,
                    'status' => $status,
                    'reason' => isset($result['reason']) ? (string) $result['reason'] : '',
                    'detail' => $this->truncate($text, 200),
                ];
                continue;
            }

            $completed++;
            $findings[] = [
                'agent'   => $agent,
                'task'    => $task,
                'content' => $this->truncate($text, $this->perResultLimit),
            ];

            foreach ($this->extractFiles($text) as $file) {
                $files[$file] = true;
            }
            foreach ($this->extractRecommendations($text) as $line) {
                $recommendations[] = $line;
            }
        }

        return [
            'summary'         => $this->buildSummary($results, $findings, $errors),
            'findings'        => $findings,
            'files'           => array_keys($files),
            'errors'          => $errors,
            'recommendations' => array_values(array_unique($recommendations)),
            'transcripts'     => $transcripts,
            'stats'           => [
                'total'     => count($results),
                'completed' => $completed,
                'failed'    => count($errors),
            ],
        ];
    }

    /**
     * 注入模型驱动的摘要器
     *
     * @param callable|null $summarizer function(array $results): string
     * @return $this
     */
    public function setSummarizer($summarizer)
    {
        $this->summarizer = $summarizer;
        return $this;
    }

    /**
     * @param int $limit
     * @return $this
     */
    public function setPerResultLimit($limit)
    {
        $this->perResultLimit = max(50, (int) $limit);
        return $this;
    }

    /**
     * 生成合并摘要
     *
     * @param array<int, array<string, mixed>> $results
     * @param array<int, array<string, mixed>> $findings
     * @param array<int, array<string, mixed>> $errors
     * @return string
     */
    protected function buildSummary(array $results, array $findings, array $errors)
    {
        if ($this->summarizer !== null) {
            try {
                $text = (string) call_user_func($this->summarizer, $results);
                if (trim($text) !== '') {
                    return $this->truncate($text, $this->summaryLimit);
                }
            } catch (\Throwable $e) {
                // 摘要器失败就退回规则拼接，不能因为摘要挂了把结果整个丢了
            }
        }

        $lines = [];
        $lines[] = sprintf(
            '共 %d 路任务，%d 路完成，%d 路未完成。',
            count($results),
            count($findings),
            count($errors)
        );

        foreach ($findings as $finding) {
            $head = $finding['agent'] !== '' ? '[' . $finding['agent'] . '] ' : '';
            $lines[] = "\n" . $head . $finding['task'] . "\n" . $finding['content'];
        }

        foreach ($errors as $error) {
            $lines[] = sprintf(
                "\n[未完成] %s %s（%s）%s",
                $error['agent'],
                $error['task'],
                $error['status'],
                $error['detail'] !== '' ? '：' . $error['detail'] : ''
            );
        }

        return $this->truncate(implode("\n", $lines), $this->summaryLimit);
    }

    /**
     * 从文本里抽出提到的文件路径
     *
     * 只认带扩展名的路径形态，避免把普通词误判成文件。
     *
     * @param string $text
     * @return string[]
     */
    protected function extractFiles($text)
    {
        $files = [];
        if (preg_match_all('#[\w./\-]+\.(?:php|js|ts|json|md|ya?ml|sql|html|css|twig|blade\.php)#i', (string) $text, $m)) {
            foreach ($m[0] as $file) {
                $file = trim($file, '.,;:()[]');
                if ($file !== '' && strlen($file) < 200) {
                    $files[$file] = true;
                }
            }
        }
        return array_keys($files);
    }

    /**
     * 抽出建议类语句
     *
     * @param string $text
     * @return string[]
     */
    protected function extractRecommendations($text)
    {
        $lines = [];
        foreach (preg_split('/\r?\n/', (string) $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^[-*\d.)\s]*(建议|应该|需要|推荐|recommend|should|consider)/iu', $line)) {
                $lines[] = $this->truncate($line, 200);
            }
        }
        return $lines;
    }

    /**
     * 按字符截断（不按字节，中文不会被截成乱码）
     *
     * @param string $text
     * @param int $limit
     * @return string
     */
    protected function truncate($text, $limit)
    {
        $text = trim((string) $text);
        $limit = max(1, (int) $limit);

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= $limit) {
                return $text;
            }
            return mb_substr($text, 0, $limit, 'UTF-8') . '…';
        }
        return strlen($text) <= $limit ? $text : substr($text, 0, $limit) . '…';
    }
}
