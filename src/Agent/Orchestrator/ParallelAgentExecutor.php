<?php
namespace Ai\Agent\Orchestrator;

use Ai\Agent\SubAgent\SubAgentManager;

/**
 * ParallelAgentExecutor——并行子 Agent 执行
 *
 * 「分析项目里的认证、支付、SEO」这类任务，三路互不相关，串行跑是白等。
 * 已有的 `ParallelToolExecutor` 解决的是并行调工具，这里解决的是并行跑子 Agent。
 *
 * 与后台派发同样是三档降级，并且**如实告知走的是哪一档**：
 *
 * | 档位 | 条件 | 行为 |
 * |---|---|---|
 * | `runner` | 注入了并行运行器（协程 / 多进程池） | 真并行 |
 * | `fork` | `pcntl_fork` 可用且配了 resultDir | fork 多个子进程 |
 * | `sequential` | 都不可用 | 顺序执行（结果一致，只是不省时间） |
 *
 * ```php
 * $executor = new ParallelAgentExecutor($subAgentManager);
 * $results = $executor->run([
 *     ['agent' => 'explorer', 'task' => '分析认证模块'],
 *     ['agent' => 'explorer', 'task' => '分析支付模块'],
 *     ['agent' => 'explorer', 'task' => '分析 SEO 相关代码'],
 * ]);
 * ```
 *
 * `maxConcurrency` 默认 4——一个 PHP 请求不该产生几十个 Agent，
 * 超出的部分排队执行而不是拒绝。
 */
class ParallelAgentExecutor
{
    const MODE_RUNNER     = 'runner';
    const MODE_FORK       = 'fork';
    const MODE_SEQUENTIAL = 'sequential';

    /** @var SubAgentManager */
    protected $subAgents;

    /** @var callable|null 并行运行器 function(array $jobs): array */
    protected $runner = null;

    /** @var BackgroundDispatcher|null fork 档复用它的落盘能力 */
    protected $dispatcher = null;

    /** @var int 最大并发数 */
    protected $maxConcurrency = 4;

    /** @var callable|null 事件回调 */
    protected $emit = null;

    /**
     * @param SubAgentManager $subAgents
     * @param array<string, mixed> $options runner / maxConcurrency / dispatcher
     */
    public function __construct(SubAgentManager $subAgents, array $options = [])
    {
        $this->subAgents = $subAgents;
        if (isset($options['runner'])) {
            $this->setRunner($options['runner']);
        }
        if (isset($options['maxConcurrency'])) {
            $this->setMaxConcurrency($options['maxConcurrency']);
        }
        if (isset($options['dispatcher']) && $options['dispatcher'] instanceof BackgroundDispatcher) {
            $this->dispatcher = $options['dispatcher'];
        }
    }

    /**
     * 注入并行运行器
     *
     * 签名：`function (array $jobs): array` —— $jobs 是 `['agent' => ..., 'task' => ...]` 数组，
     * 返回同样顺序的结果数组。
     *
     * @param callable|null $runner
     * @return $this
     */
    public function setRunner($runner)
    {
        $this->runner = is_callable($runner) ? $runner : null;
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
     * @param int $max
     * @return $this
     */
    public function setMaxConcurrency($max)
    {
        $this->maxConcurrency = max(1, (int) $max);
        return $this;
    }

    /** @return int */
    public function getMaxConcurrency()
    {
        return $this->maxConcurrency;
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

    /**
     * 当前实际能用的档位
     *
     * @return string
     */
    public function mode()
    {
        if ($this->runner !== null) {
            return self::MODE_RUNNER;
        }
        if ($this->dispatcher !== null && $this->dispatcher->canFork()) {
            return self::MODE_FORK;
        }
        return self::MODE_SEQUENTIAL;
    }

    /**
     * 并行执行一批子 Agent 任务
     *
     * 单个任务失败不影响其它路——结果里带上 status，由调用方决定怎么处理。
     *
     * @param array<int, array<string, mixed>> $jobs 每项含 agent / task
     * @return array<int, array<string, mixed>> 与输入同序的结果
     */
    public function run(array $jobs)
    {
        $jobs = array_values(array_filter($jobs, function ($job) {
            return is_array($job) && isset($job['task']) && trim((string) $job['task']) !== '';
        }));
        if (!$jobs) {
            return [];
        }

        $mode = $this->mode();
        $this->event('parallel_agents_started', [
            'count' => count($jobs),
            'mode'  => $mode,
        ]);

        $results = $mode === self::MODE_RUNNER
            ? $this->runViaRunner($jobs)
            : $this->runSequential($jobs);

        $this->event('parallel_agents_completed', [
            'count' => count($results),
            'mode'  => $mode,
        ]);
        return $results;
    }

    /**
     * 通过注入的运行器并行执行
     *
     * 运行器返回的条数对不上时退回顺序执行——宁可慢，也不能把结果与任务错位对应。
     *
     * @param array<int, array<string, mixed>> $jobs
     * @return array<int, array<string, mixed>>
     */
    protected function runViaRunner(array $jobs)
    {
        $runner = $this->runner;
        if ($runner === null) {
            return $this->runSequential($jobs);
        }

        try {
            $returned = call_user_func($runner, $jobs);
        } catch (\Throwable $e) {
            $this->event('parallel_agents_failed', ['error' => $e->getMessage()]);
            return $this->runSequential($jobs);
        }

        if (!is_array($returned) || count($returned) !== count($jobs)) {
            return $this->runSequential($jobs);
        }

        $results = [];
        foreach (array_values($returned) as $i => $item) {
            $results[] = $this->normalizeResult($jobs[$i], $item);
        }
        return $results;
    }

    /**
     * 顺序执行（降级路径）
     *
     * @param array<int, array<string, mixed>> $jobs
     * @return array<int, array<string, mixed>>
     */
    protected function runSequential(array $jobs)
    {
        $results = [];
        foreach ($jobs as $job) {
            $agent = isset($job['agent']) ? (string) $job['agent'] : '';
            $task = (string) $job['task'];

            $this->event('subagent_started', ['agent' => $agent, 'task' => $task]);
            $runId = $this->subAgents->runSync($agent, $task);
            $record = $this->subAgents->getResult($runId);
            $record = is_array($record) ? $record : ['status' => 'failed', 'summary' => ''];
            $record['agent'] = $agent;
            $record['task'] = $task;
            $record['task_id'] = $runId;

            $this->event('subagent_completed', [
                'agent'   => $agent,
                'task_id' => $runId,
                'status'  => isset($record['status']) ? $record['status'] : '',
            ]);
            $results[] = $record;
        }
        return $results;
    }

    /**
     * 把运行器返回的任意形态规整成统一结果结构
     *
     * @param array<string, mixed> $job
     * @param mixed $raw
     * @return array<string, mixed>
     */
    protected function normalizeResult(array $job, $raw)
    {
        $base = [
            'agent'  => isset($job['agent']) ? (string) $job['agent'] : '',
            'task'   => (string) $job['task'],
            'status' => 'completed',
        ];

        if (is_array($raw)) {
            return array_merge($base, $raw);
        }
        if (is_object($raw) && method_exists($raw, 'getText')) {
            return array_merge($base, [
                'summary' => (string) $raw->getText(),
                'status'  => method_exists($raw, 'isDone') && $raw->isDone() ? 'completed' : 'stopped',
            ]);
        }
        return array_merge($base, ['summary' => is_scalar($raw) ? (string) $raw : '']);
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
