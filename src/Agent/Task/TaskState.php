<?php
namespace Ai\Agent\Task;

/**
 * TaskState——任务状态记录
 *
 * 记录任务的详细执行进度，包括已完成、待处理、阻塞项、重要发现、
 * 修改文件、测试结果、错误信息、子任务等。
 *
 * TaskState 与 AgentTask 分离：
 * - AgentTask 是标识/生命周期（轻量，用于列表/排序）
 * - TaskState 是详细进度（用于 Context Compaction 后 Agent 仍知道任务状态）
 *
 * 用途：
 * ```php
 * $state = new TaskState(['goal' => '修复登录问题']);
 * $state->addCompleted('找到 Auth.php');
 * $state->addPending('运行 PHPUnit');
 * $state->addModifiedFile('Auth.php');
 * echo $state->toJson();
 * ```
 */
class TaskState
{
    /** @var string */
    protected $goal = '';

    /** @var string[] */
    protected $completed = [];

    /** @var string[] */
    protected $pending = [];

    /** @var string[] */
    protected $blocked = [];

    /** @var string[] */
    protected $importantFacts = [];

    /** @var string[] */
    protected $modifiedFiles = [];

    /** @var string[] */
    protected $tests = [];

    /** @var string[] */
    protected $errors = [];

    /** @var array<string, array<string, mixed>> */
    protected $subtasks = [];

    /** @var array<string, mixed> 执行计划快照（Plan::toArray()），无计划时为空数组 */
    protected $plan = [];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->goal          = isset($data['goal']) ? (string) $data['goal'] : '';
        $this->completed     = isset($data['completed']) && is_array($data['completed']) ? $data['completed'] : [];
        $this->pending       = isset($data['pending']) && is_array($data['pending']) ? $data['pending'] : [];
        $this->blocked       = isset($data['blocked']) && is_array($data['blocked']) ? $data['blocked'] : [];
        $this->importantFacts = isset($data['importantFacts']) && is_array($data['importantFacts']) ? $data['importantFacts'] : [];
        $this->modifiedFiles = isset($data['modifiedFiles']) && is_array($data['modifiedFiles']) ? $data['modifiedFiles'] : [];
        $this->tests         = isset($data['tests']) && is_array($data['tests']) ? $data['tests'] : [];
        $this->errors        = isset($data['errors']) && is_array($data['errors']) ? $data['errors'] : [];
        $this->subtasks      = isset($data['subtasks']) && is_array($data['subtasks']) ? $data['subtasks'] : [];
        $this->plan          = isset($data['plan']) && is_array($data['plan']) ? $data['plan'] : [];
    }

    /**
     * 保存执行计划快照
     *
     * 存的是 `Plan::toArray()` 的结果而不是 Plan 对象——TaskState 要能整体
     * JSON 序列化落盘，崩溃恢复后再用 `Plan::fromArray()` 还原。
     *
     * @param \Ai\Agent\Planning\Plan|array<string, mixed>|null $plan
     * @return $this
     */
    public function setPlan($plan)
    {
        if ($plan === null) {
            $this->plan = [];
        } elseif (is_array($plan)) {
            $this->plan = $plan;
        } else {
            $this->plan = $plan->toArray();
        }
        return $this;
    }

    /**
     * 执行计划快照，无计划时为空数组
     *
     * @return array<string, mixed>
     */
    public function getPlan()
    {
        return $this->plan;
    }

    /**
     * 还原执行计划对象，无计划时返回 null
     *
     * @return \Ai\Agent\Planning\Plan|null
     */
    public function restorePlan()
    {
        if (!$this->plan) {
            return null;
        }
        return \Ai\Agent\Planning\Plan::fromArray($this->plan);
    }

    /**
     * @return string
     */
    public function getGoal()
    {
        return $this->goal;
    }

    /**
     * @return string[]
     */
    public function getCompleted()
    {
        return $this->completed;
    }

    /**
     * @return string[]
     */
    public function getPending()
    {
        return $this->pending;
    }

    /**
     * @return string[]
     */
    public function getBlocked()
    {
        return $this->blocked;
    }

    /**
     * @return string[]
     */
    public function getImportantFacts()
    {
        return $this->importantFacts;
    }

    /**
     * @return string[]
     */
    public function getModifiedFiles()
    {
        return $this->modifiedFiles;
    }

    /**
     * @return string[]
     */
    public function getTests()
    {
        return $this->tests;
    }

    /**
     * @return string[]
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getSubtasks()
    {
        return $this->subtasks;
    }

    // ---- 增删改 ----

    /**
     * 添加一条已完成项
     * @param string $item
     * @return $this
     */
    public function addCompleted($item)
    {
        $this->completed[] = (string) $item;
        return $this;
    }

    /**
     * 添加一条待处理项
     * @param string $item
     * @return $this
     */
    public function addPending($item)
    {
        $this->pending[] = (string) $item;
        return $this;
    }

    /**
     * 将待处理项移至已完成
     * @param string $item
     * @return $this
     */
    public function completePending($item)
    {
        $item = (string) $item;
        $this->pending = array_values(array_filter($this->pending, function ($p) use ($item) {
            return $p !== $item;
        }));
        $this->completed[] = $item;
        return $this;
    }

    /**
     * 添加一条阻塞项
     * @param string $item
     * @return $this
     */
    public function addBlocked($item)
    {
        $this->blocked[] = (string) $item;
        return $this;
    }

    /**
     * 添加一条重要发现
     * @param string $fact
     * @return $this
     */
    public function addImportantFact($fact)
    {
        $this->importantFacts[] = (string) $fact;
        return $this;
    }

    /**
     * 添加一个修改文件
     * @param string $file
     * @return $this
     */
    public function addModifiedFile($file)
    {
        $file = (string) $file;
        if (!in_array($file, $this->modifiedFiles, true)) {
            $this->modifiedFiles[] = $file;
        }
        return $this;
    }

    /**
     * 添加一条测试结果
     * @param string $test
     * @return $this
     */
    public function addTest($test)
    {
        $this->tests[] = (string) $test;
        return $this;
    }

    /**
     * 添加一条错误信息
     * @param string $error
     * @return $this
     */
    public function addError($error)
    {
        $this->errors[] = (string) $error;
        return $this;
    }

    /**
     * 添加子任务状态
     * @param string $taskId
     * @param array<string, mixed> $state
     * @return $this
     */
    public function addSubtask($taskId, array $state = [])
    {
        $this->subtasks[(string) $taskId] = $state;
        return $this;
    }

    /**
     * 更新子任务状态
     * @param string $taskId
     * @param array<string, mixed> $state
     * @return $this
     */
    public function updateSubtask($taskId, array $state = [])
    {
        $taskId = (string) $taskId;
        if (isset($this->subtasks[$taskId])) {
            foreach ($state as $key => $value) {
                $this->subtasks[$taskId][$key] = $value;
            }
        } else {
            $this->subtasks[$taskId] = $state;
        }
        return $this;
    }

    /**
     * 获取进度摘要文本（适合注入 Context Compaction 后的 system prompt）
     *
     * @return string
     */
    public function toSummary()
    {
        $lines = [];
        $lines[] = '# 任务状态';
        $lines[] = '目标：' . $this->goal;
        $lines[] = '';

        if ($this->plan) {
            $plan = $this->restorePlan();
            if ($plan !== null) {
                $lines[] = '## 执行计划';
                $lines[] = $plan->toSummary();
                $lines[] = '';
            }
        }

        if ($this->completed) {
            $lines[] = '## 已完成';
            foreach ($this->completed as $item) {
                $lines[] = '- ' . $item;
            }
            $lines[] = '';
        }

        if ($this->pending) {
            $lines[] = '## 待处理';
            foreach ($this->pending as $item) {
                $lines[] = '- ' . $item;
            }
            $lines[] = '';
        }

        if ($this->modifiedFiles) {
            $lines[] = '## 修改的文件';
            foreach ($this->modifiedFiles as $file) {
                $lines[] = '- ' . $file;
            }
            $lines[] = '';
        }

        if ($this->importantFacts) {
            $lines[] = '## 关键发现';
            foreach ($this->importantFacts as $fact) {
                $lines[] = '- ' . $fact;
            }
            $lines[] = '';
        }

        if ($this->errors) {
            $lines[] = '## 错误';
            foreach ($this->errors as $error) {
                $lines[] = '- ' . $error;
            }
            $lines[] = '';
        }

        if ($this->subtasks) {
            $lines[] = '## 子任务';
            foreach ($this->subtasks as $taskId => $state) {
                $status = isset($state['status']) ? $state['status'] : 'unknown';
                $summary = isset($state['summary']) ? $state['summary'] : '';
                $lines[] = "- {$taskId}：{$status} {$summary}";
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * 转为数组
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'goal'          => $this->goal,
            'completed'     => $this->completed,
            'pending'       => $this->pending,
            'blocked'       => $this->blocked,
            'importantFacts' => $this->importantFacts,
            'modifiedFiles' => $this->modifiedFiles,
            'tests'         => $this->tests,
            'errors'        => $this->errors,
            'subtasks'      => $this->subtasks,
            'plan'          => $this->plan,
        ];
    }

    /**
     * 转为 JSON
     * @return string
     */
    public function toJson()
    {
        $result = json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return $result !== false ? $result : '';
    }

    /**
     * 从 JSON 创建
     * @param string $json
     * @return self
     */
    public static function fromJson($json)
    {
        $data = json_decode((string) $json, true);
        return new self($data ? $data : []);
    }
}