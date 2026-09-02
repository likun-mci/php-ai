<?php
namespace Ai\Agent\Approval;

/**
 * ApprovalWorkflow——人工审批工作流
 *
 * AI 改完代码不直接算数：先提交审核（附 diff），等人批准才继续，驳回则带着理由退回去改。
 * 企业环境里这是硬要求——没有人签字的自动改动进不了生产。
 *
 * ```php
 * $workflow = new ApprovalWorkflow('/var/data/approvals');
 *
 * // AI 侧：改完提交
 * $request = $workflow->submitForReview($diff, [
 *     'summary' => '修复登录 401',
 *     'files'   => ['src/Auth.php'],
 * ]);
 *
 * // 人工侧：另一个进程 / 后台页面
 * foreach ($workflow->getPendingRequests() as $req) {
 *     echo $req->toSummary();
 * }
 * $workflow->approve($request->getId(), '张三');
 * // 或 $workflow->reject($request->getId(), '缺少输入校验', '张三');
 *
 * // AI 侧：轮询结果
 * if ($workflow->getStatus($request->getId()) === ApprovalRequest::STATUS_APPROVED) {
 *     // 继续执行
 * }
 * ```
 *
 * 审批天然跨进程：提交的是 Agent，批准的是人，中间可能隔几小时。所以请求默认落盘
 * （传入 baseDir 时），Agent 崩了重启也能接着等；不传目录则只放内存，适合同进程内的
 * 交互式确认。
 */
class ApprovalWorkflow
{
    /** @var array<string, ApprovalRequest> 请求 ID => 请求 */
    protected $requests = [];

    /** @var string 持久化目录，空则只放内存 */
    protected $baseDir = '';

    /** @var int 请求默认有效期（秒），0 表示不过期 */
    protected $ttl = 0;

    /** @var int 自增序号 */
    protected $counter = 0;

    /** @var callable|null 提交时的通知钩子 function(ApprovalRequest): void */
    protected $notifier = null;

    /** @var bool 是否自动批准——仅用于测试与开发环境 */
    protected $autoApprove = false;

    /**
     * @param string $baseDir 持久化目录，空字符串则只放内存
     * @param array<string, mixed> $options ttl / notifier / autoApprove
     */
    public function __construct($baseDir = '', array $options = [])
    {
        $this->baseDir = rtrim(str_replace('\\', '/', (string) $baseDir), '/');
        if (isset($options['ttl'])) {
            $this->ttl = max(0, (int) $options['ttl']);
        }
        if (isset($options['notifier'])) {
            $this->notifier = $options['notifier'];
        }
        if (isset($options['autoApprove'])) {
            $this->autoApprove = (bool) $options['autoApprove'];
        }

        if ($this->baseDir !== '' && !is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0777, true);
        }
        $this->loadAll();
    }

    /**
     * 提交一份改动等待审核
     *
     * @param string $changes 完整 diff
     * @param array<string, mixed> $context summary / files / 任务 ID / 角色等
     * @return ApprovalRequest
     */
    public function submitForReview($changes, array $context = [])
    {
        $this->counter++;
        $id = 'req_' . $this->counter . '_' . substr(md5(uniqid('', true)), 0, 8);

        $data = [
            'diff'    => (string) $changes,
            'summary' => isset($context['summary']) ? (string) $context['summary'] : '',
            'files'   => isset($context['files']) && is_array($context['files']) ? $context['files'] : [],
            'context' => $context,
        ];
        if ($this->ttl > 0) {
            $data['expiresAt'] = time() + $this->ttl;
        }

        $request = new ApprovalRequest($id, $data);

        // 开发环境的开关：自动过审，省掉本地调试时每次都要手动点一下
        if ($this->autoApprove) {
            $request->approve('auto');
        }

        $this->requests[$id] = $request;
        $this->persist($request);

        if ($this->notifier !== null) {
            call_user_func($this->notifier, $request);
        }
        return $request;
    }

    /**
     * 批准
     *
     * @param string $requestId
     * @param string $reviewer
     * @return bool 请求不存在、已处理或已过期时返回 false
     */
    public function approve($requestId, $reviewer = '')
    {
        $request = $this->getRequest($requestId);
        if ($request === null || !$request->approve($reviewer)) {
            return false;
        }
        $this->persist($request);
        return true;
    }

    /**
     * 驳回
     *
     * @param string $requestId
     * @param string $reason 驳回理由——退回给 AI 时这是它唯一的输入，写清楚
     * @param string $reviewer
     * @return bool
     */
    public function reject($requestId, $reason = '', $reviewer = '')
    {
        $request = $this->getRequest($requestId);
        if ($request === null || !$request->reject($reason, $reviewer)) {
            return false;
        }
        $this->persist($request);
        return true;
    }

    /**
     * 取一个请求
     *
     * 落盘模式下每次都从磁盘重读——审批多半发生在另一个进程里，
     * 只看内存副本会一直看到 pending。
     *
     * @param string $requestId
     * @return ApprovalRequest|null
     */
    public function getRequest($requestId)
    {
        $requestId = (string) $requestId;

        if ($this->baseDir !== '') {
            $fresh = $this->loadOne($requestId);
            if ($fresh !== null) {
                $this->requests[$requestId] = $fresh;
                return $fresh;
            }
        }
        return isset($this->requests[$requestId]) ? $this->requests[$requestId] : null;
    }

    /**
     * 请求状态
     *
     * @param string $requestId
     * @return string 请求不存在返回空串
     */
    public function getStatus($requestId)
    {
        $request = $this->getRequest($requestId);
        return $request === null ? '' : $request->getStatus();
    }

    /**
     * 等待审批的请求
     *
     * @return ApprovalRequest[]
     */
    public function getPendingRequests()
    {
        $this->refresh();
        $pending = [];
        foreach ($this->requests as $request) {
            if ($request->isPending()) {
                $pending[] = $request;
            }
        }
        return $pending;
    }

    /**
     * 全部请求
     *
     * @param string $status 按状态过滤，空串表示全部
     * @return ApprovalRequest[]
     */
    public function allRequests($status = '')
    {
        $this->refresh();
        $status = (string) $status;
        if ($status === '') {
            return array_values($this->requests);
        }
        $matched = [];
        foreach ($this->requests as $request) {
            if ($request->getStatus() === $status) {
                $matched[] = $request;
            }
        }
        return $matched;
    }

    /**
     * 阻塞等待某个请求被处理
     *
     * 用于同步流程：Agent 提交后原地等人批。`$timeout` 到了还没结果就返回当前状态，
     * 由调用方决定是继续等还是放弃——把 Agent 无限期挂在这里不是好设计。
     *
     * @param string $requestId
     * @param int $timeout 最长等待秒数
     * @param int $intervalMs 轮询间隔毫秒
     * @return string 最终状态
     */
    public function waitFor($requestId, $timeout = 300, $intervalMs = 1000)
    {
        $deadline = time() + max(1, (int) $timeout);
        $interval = max(100, (int) $intervalMs) * 1000;

        while (time() < $deadline) {
            $status = $this->getStatus($requestId);
            if ($status !== ApprovalRequest::STATUS_PENDING) {
                return $status;
            }
            usleep($interval);
        }
        return $this->getStatus($requestId);
    }

    /**
     * 删除一个请求
     *
     * @param string $requestId
     * @return bool
     */
    public function delete($requestId)
    {
        $requestId = (string) $requestId;
        unset($this->requests[$requestId]);
        $file = $this->fileFor($requestId);
        if ($file !== '' && is_file($file)) {
            return @unlink($file);
        }
        return true;
    }

    /**
     * 清掉已处理与已过期的请求
     *
     * @return int 清掉的数量
     */
    public function purgeDecided()
    {
        $this->refresh();
        $removed = 0;
        foreach ($this->requests as $id => $request) {
            if (!$request->isPending()) {
                $this->delete($id);
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * 设置提交通知钩子（发邮件、发飞书、写工单）
     *
     * @param callable|null $notifier function(ApprovalRequest $request): void
     * @return $this
     */
    public function onSubmit($notifier)
    {
        $this->notifier = $notifier;
        return $this;
    }

    /**
     * 开发环境自动过审
     *
     * @param bool $auto
     * @return $this
     */
    public function setAutoApprove($auto)
    {
        $this->autoApprove = (bool) $auto;
        return $this;
    }

    /**
     * @return bool
     */
    public function isAutoApprove()
    {
        return $this->autoApprove;
    }

    /**
     * @return string
     */
    public function getBaseDir()
    {
        return $this->baseDir;
    }

    /**
     * 从磁盘重读全部请求
     *
     * @return $this
     */
    public function refresh()
    {
        if ($this->baseDir !== '') {
            $this->loadAll();
        }
        return $this;
    }

    /**
     * 落盘
     *
     * @param ApprovalRequest $request
     * @return void
     */
    protected function persist(ApprovalRequest $request)
    {
        $file = $this->fileFor($request->getId());
        if ($file === '') {
            return;
        }
        $json = json_encode($request->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json !== false) {
            @file_put_contents($file, $json);
        }
    }

    /**
     * 读一个请求
     *
     * @param string $requestId
     * @return ApprovalRequest|null
     */
    protected function loadOne($requestId)
    {
        $file = $this->fileFor($requestId);
        if ($file === '' || !is_file($file)) {
            return null;
        }
        $json = @file_get_contents($file);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? ApprovalRequest::fromArray($data) : null;
    }

    /**
     * 读全部请求
     *
     * @return void
     */
    protected function loadAll()
    {
        if ($this->baseDir === '' || !is_dir($this->baseDir)) {
            return;
        }
        $files = glob($this->baseDir . '/req_*.json');
        foreach ($files === false ? [] : $files as $file) {
            $json = @file_get_contents($file);
            if ($json === false) {
                continue;
            }
            $data = json_decode($json, true);
            if (!is_array($data) || !isset($data['id'])) {
                continue;
            }
            $request = ApprovalRequest::fromArray($data);
            $this->requests[$request->getId()] = $request;
            // 让计数器越过已有请求，避免重启后 ID 撞车
            if (preg_match('/^req_(\d+)_/', $request->getId(), $m)) {
                $this->counter = max($this->counter, (int) $m[1]);
            }
        }
    }

    /**
     * @param string $requestId
     * @return string
     */
    protected function fileFor($requestId)
    {
        if ($this->baseDir === '') {
            return '';
        }
        $requestId = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $requestId);
        return $requestId === '' ? '' : $this->baseDir . '/' . $requestId . '.json';
    }
}
