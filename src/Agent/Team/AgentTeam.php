<?php
namespace Ai\Agent\Team;

use Ai\AI;
use Ai\Agent\AgentRuntime;

/**
 * AgentTeam——多角色 Agent 团队
 *
 * 从"父 Agent 派生子 Agent"升级为"一组各司其职的角色协作"。区别在于成员是长期存在的：
 * Developer 改完代码，Tester 拿到的是同一轮任务的上下文，Reviewer 能看到前两者的结论。
 *
 * ```php
 * $team = new AgentTeam($ai);
 * $team->addMember(AgentRole::developer());
 * $team->addMember(AgentRole::tester());
 * $team->addMember(AgentRole::reviewer());
 *
 * // 单独分派
 * $result = $team->assign('developer', '实现登录接口');
 *
 * // 流水线：前一环的输出作为后一环的输入
 * $results = $team->pipeline('给 Auth 模块补测试', ['developer', 'tester', 'reviewer']);
 *
 * print_r($team->getResults());
 * ```
 *
 * 成员共享工具与权限配置（由团队统一给），但各自持有独立的 AgentRuntime 与上下文——
 * 这正是多角色的意义：Tester 的上下文里不该塞满 Developer 的思考过程。
 *
 * 消息通过 `AgentCommunication` 投递，分派任务时会把收件箱里的未读消息一起带上。
 */
class AgentTeam
{
    /** @var AI */
    protected $ai;

    /** @var array<string, AgentRole> 角色名 => 角色 */
    protected $roles = [];

    /** @var array<string, AgentRuntime> 角色名 => 运行时 */
    protected $runtimes = [];

    /** @var AgentCommunication */
    protected $bus;

    /** @var array<string, mixed> 团队统一的工具集 */
    protected $tools = [];

    /** @var string 团队共享的基础系统提示词 */
    protected $system = '';

    /** @var string 工作目录 */
    protected $workdir = '';

    /** @var \Ai\Agent\Permission\PermissionManager|null 团队统一权限 */
    protected $permission = null;

    /** @var array<int, array<string, mixed>> 每次分派的结果记录 */
    protected $results = [];

    /** @var callable|null 事件回调 function(array $event): void */
    protected $emit = null;

    /** @var AgentHandoff[] 交接记录 */
    protected $handoffs = [];

    /**
     * @param AI $ai
     * @param array<string, mixed> $options system / workdir / tools / permission / maxHistory
     */
    public function __construct(AI $ai, array $options = [])
    {
        $this->ai = $ai;
        $this->bus = new AgentCommunication(
            isset($options['maxHistory']) ? ['maxHistory' => $options['maxHistory']] : []
        );
        if (isset($options['system'])) {
            $this->system = (string) $options['system'];
        }
        if (isset($options['workdir'])) {
            $this->workdir = (string) $options['workdir'];
        }
        if (isset($options['tools']) && is_array($options['tools'])) {
            $this->tools = $options['tools'];
        }
        if (isset($options['permission'])) {
            $this->permission = $options['permission'];
        }
    }

    /**
     * 加入一个成员
     *
     * 传 AgentRole 用它的配置；传字符串则按内置角色名创建，认不出就建一个空白角色。
     *
     * @param AgentRole|string $role
     * @param AgentRuntime|null $runtime 自定义运行时，不传则按角色配置创建
     * @return $this
     */
    public function addMember($role, $runtime = null)
    {
        if (!$role instanceof AgentRole) {
            $role = $this->makeRole((string) $role);
        }
        $name = $role->getName();
        if ($name === '') {
            return $this;
        }

        $this->roles[$name] = $role;
        $this->runtimes[$name] = $runtime instanceof AgentRuntime
            ? $runtime
            : $this->makeRuntime($role);
        $this->bus->addMember($name);
        return $this;
    }

    /**
     * 移除成员
     *
     * @param string $role
     * @return $this
     */
    public function removeMember($role)
    {
        $role = (string) $role;
        unset($this->roles[$role], $this->runtimes[$role]);
        $this->bus->removeMember($role);
        return $this;
    }

    /**
     * 取成员的运行时
     *
     * @param string $role
     * @return AgentRuntime|null
     */
    public function getMember($role)
    {
        $role = (string) $role;
        return isset($this->runtimes[$role]) ? $this->runtimes[$role] : null;
    }

    /**
     * 取角色定义
     *
     * @param string $role
     * @return AgentRole|null
     */
    public function getRole($role)
    {
        $role = (string) $role;
        return isset($this->roles[$role]) ? $this->roles[$role] : null;
    }

    /**
     * 全部成员角色名
     *
     * @return string[]
     */
    public function members()
    {
        return array_keys($this->roles);
    }

    /**
     * 是否有某个成员
     *
     * @param string $role
     * @return bool
     */
    public function has($role)
    {
        return isset($this->roles[(string) $role]);
    }

    /**
     * 给某个成员分派任务
     *
     * 收件箱里的未读消息会拼在任务描述前面一起给出去——上一环留给他的话，
     * 不该等他自己去问。
     *
     * @param string $role
     * @param string $task
     * @param array<string, mixed> $context 额外上下文，会附在任务后
     * @return array<string, mixed> 结果记录：role / task / status / text / iterations
     */
    public function assign($role, $task, array $context = [])
    {
        $role = (string) $role;
        if (!isset($this->runtimes[$role])) {
            $record = [
                'role'   => $role,
                'task'   => (string) $task,
                'status' => 'failed',
                'reason' => 'unknown_role',
                'text'   => '团队里没有角色：' . $role,
                'iterations' => 0,
            ];
            $this->results[] = $record;
            return $record;
        }

        $prompt = $this->buildPrompt($role, (string) $task, $context);
        $this->event('team_assign', ['role' => $role, 'task' => (string) $task]);

        $start = microtime(true);
        try {
            $result = $this->runtimes[$role]->run([['role' => 'user', 'content' => $prompt]]);
            $record = [
                'role'        => $role,
                'task'        => (string) $task,
                'status'      => $result->isDone() ? 'completed' : 'stopped',
                'reason'      => $result->getStopReason(),
                'text'        => $result->getText(),
                'iterations'  => $result->getIterations(),
                'duration_ms' => round((microtime(true) - $start) * 1000, 1),
            ];
        } catch (\Throwable $e) {
            // 一个成员失败不该让整个团队中断——记下来，让调用方决定要不要继续
            $record = [
                'role'        => $role,
                'task'        => (string) $task,
                'status'      => 'failed',
                'reason'      => 'exception',
                'text'        => $e->getMessage(),
                'iterations'  => 0,
                'duration_ms' => round((microtime(true) - $start) * 1000, 1),
            ];
        }

        $this->results[] = $record;
        $this->event('team_result', $record);

        // 结果作为消息留在总线上，后续成员看得到
        $this->bus->send(AgentMessage::result($role, '', $record['text'], [
            'status' => $record['status'],
            'task'   => $record['task'],
        ]));

        return $record;
    }

    /**
     * 流水线执行——前一环的输出作为后一环的输入
     *
     * ```php
     * $team->pipeline('给 Auth 补测试', ['developer', 'tester', 'reviewer']);
     * ```
     *
     * 某一环失败时后续照常执行，失败信息会传下去——Reviewer 知道 Tester 挂了，
     * 比什么都不知道有用。
     *
     * @param string $task
     * @param string[] $roles 按顺序执行的角色
     * @return array<int, array<string, mixed>> 每一环的结果
     */
    public function pipeline($task, array $roles)
    {
        $results = [];
        $previous = '';

        foreach ($roles as $role) {
            $context = $previous !== '' ? ['上一环的结果' => $previous] : [];
            $record = $this->assign((string) $role, (string) $task, $context);
            $results[] = $record;
            $previous = $record['text'];
        }
        return $results;
    }

    /**
     * 把任务从一个成员交接给另一个成员
     *
     * 交接会留痕并投递一条 handoff 消息给接手方——接手方下次被分派任务时，
     * 收件箱里就带着「谁交给我的、为什么、进展到哪」。
     *
     * ```php
     * $handoff = $team->handoff('coder', 'dba', '发现是索引缺失', [
     *     'task_id'         => 'task_1',
     *     'context_summary' => '已定位到 UserRepo::findByEmail，全表扫描',
     * ]);
     *
     * // 处理完交回去
     * $team->handoffBack($handoff, '索引已补，慢查询消失');
     * ```
     *
     * @param string $from
     * @param string $to
     * @param string $reason
     * @param array<string, mixed> $options task_id / context_summary
     * @return AgentHandoff|null 接手方不在团队里返回 null
     */
    public function handoff($from, $to, $reason = '', array $options = [])
    {
        $to = (string) $to;
        if (!isset($this->roles[$to])) {
            return null;
        }

        $taskId = isset($options['task_id']) ? (string) $options['task_id'] : '';
        $handoff = new AgentHandoff((string) $from, $to, $taskId, $reason, $options);

        $this->handoffs[] = $handoff;
        $this->bus->send($handoff->toMessage());
        $this->event('handoff', $handoff->toArray());

        return $handoff;
    }

    /**
     * 把任务交回给原交出方
     *
     * @param AgentHandoff $handoff 原交接记录
     * @param string $reason
     * @param string $contextSummary
     * @return AgentHandoff
     */
    public function handoffBack(AgentHandoff $handoff, $reason = '', $contextSummary = '')
    {
        $reverse = $handoff->reverse($reason, $contextSummary);

        $this->handoffs[] = $reverse;
        $this->bus->send($reverse->toMessage());
        $this->event('handoff', $reverse->toArray());

        return $reverse;
    }

    /**
     * 全部交接记录
     *
     * @param string $taskId 只看某个任务的，空则全部
     * @return AgentHandoff[]
     */
    public function handoffs($taskId = '')
    {
        $taskId = (string) $taskId;
        if ($taskId === '') {
            return $this->handoffs;
        }
        return array_values(array_filter($this->handoffs, function (AgentHandoff $h) use ($taskId) {
            return $h->getTaskId() === $taskId;
        }));
    }

    /**
     * 某个任务的交接链——一个任务在几个角色之间转了几圈，看这个
     *
     * @param string $taskId
     * @return string[] 形如 ['coder → dba', 'dba → coder']
     */
    public function handoffChain($taskId)
    {
        $chain = [];
        foreach ($this->handoffs($taskId) as $handoff) {
            $chain[] = $handoff->getSourceAgent() . ' → ' . $handoff->getTargetAgent();
        }
        return $chain;
    }

    /**
     * 广播一条消息给全部成员
     *
     * @param string $content
     * @param string $from 发送者，默认 manager
     * @param string $type 消息类型
     * @return $this
     */
    public function broadcast($content, $from = AgentRole::MANAGER, $type = AgentMessage::TYPE_STATUS)
    {
        $this->bus->broadcast(new AgentMessage((string) $from, '', (string) $type, (string) $content));
        return $this;
    }

    /**
     * 成员之间发一条消息
     *
     * @param AgentMessage $message
     * @return $this
     */
    public function send(AgentMessage $message)
    {
        $this->bus->send($message);
        return $this;
    }

    /**
     * 消息总线
     *
     * @return AgentCommunication
     */
    public function communication()
    {
        return $this->bus;
    }

    /**
     * 全部分派结果
     *
     * @return array<int, array<string, mixed>>
     */
    public function getResults()
    {
        return $this->results;
    }

    /**
     * 最近一次结果
     *
     * @return array<string, mixed>|null
     */
    public function lastResult()
    {
        return $this->results ? $this->results[count($this->results) - 1] : null;
    }

    /**
     * 团队执行摘要——注入提示词或给人看
     *
     * @return string
     */
    public function toSummary()
    {
        if (!$this->results) {
            return '团队尚未执行任何任务';
        }
        $lines = [];
        foreach ($this->results as $record) {
            $text = trim((string) $record['text']);
            if (strlen($text) > 200) {
                $text = substr($text, 0, 200) . '…';
            }
            $lines[] = sprintf(
                '[%s] %s（%s，%d 轮）：%s',
                $record['role'],
                $record['task'],
                $record['status'],
                $record['iterations'],
                $text
            );
        }
        return implode("\n", $lines);
    }

    /**
     * 设置团队统一的工具集（对已加入的成员立即生效）
     *
     * @param array<string, mixed> $tools
     * @return $this
     */
    public function setTools(array $tools)
    {
        $this->tools = $tools;
        foreach ($this->roles as $name => $role) {
            $this->runtimes[$name]->setTools($this->toolsFor($role));
        }
        return $this;
    }

    /**
     * 设置团队统一权限管理器
     *
     * @param \Ai\Agent\Permission\PermissionManager|null $pm
     * @return $this
     */
    public function setPermission($pm)
    {
        $this->permission = $pm;
        foreach ($this->runtimes as $runtime) {
            if ($pm !== null) {
                $runtime->setPermission($pm);
            }
        }
        return $this;
    }

    /**
     * 设置工作目录
     *
     * @param string $dir
     * @return $this
     */
    public function setWorkdir($dir)
    {
        $this->workdir = (string) $dir;
        foreach ($this->runtimes as $runtime) {
            $runtime->setWorkdir($this->workdir);
        }
        return $this;
    }

    /**
     * 事件回调
     *
     * @param callable|null $emit function(array $event): void
     * @return $this
     */
    public function onEvent($emit)
    {
        $this->emit = $emit;
        return $this;
    }

    /**
     * 清空执行记录与消息
     *
     * @return $this
     */
    public function reset()
    {
        $this->results = [];
        $this->handoffs = [];
        $this->bus->clear();
        return $this;
    }

    /**
     * 按角色配置创建运行时
     *
     * @param AgentRole $role
     * @return AgentRuntime
     */
    protected function makeRuntime(AgentRole $role)
    {
        $runtime = new AgentRuntime($this->ai);
        $system = $role->getPrompt();
        if ($this->system !== '') {
            $system = $this->system . "\n\n" . $system;
        }
        $runtime->setSystem($system);
        $runtime->setMaxIter($role->getMaxIter());

        $tools = $this->toolsFor($role);
        if ($tools) {
            $runtime->setTools($tools);
        }
        if ($this->workdir !== '') {
            $runtime->setWorkdir($this->workdir);
        }
        if ($this->permission !== null) {
            $runtime->setPermission($this->permission);
        }
        return $runtime;
    }

    /**
     * 该角色实际可用的工具
     *
     * 角色声明了 tools 就只给这些（团队工具集里有的部分），否则给全部。
     *
     * @param AgentRole $role
     * @return array<string, mixed>
     */
    protected function toolsFor(AgentRole $role)
    {
        $allowed = $role->getTools();
        if (!$allowed || !$this->tools) {
            return $this->tools;
        }
        $tools = [];
        foreach ($this->tools as $name => $tool) {
            if (in_array((string) $name, $allowed, true)) {
                $tools[$name] = $tool;
            }
        }
        return $tools;
    }

    /**
     * 按角色名造一个内置角色
     *
     * @param string $name
     * @return AgentRole
     */
    protected function makeRole($name)
    {
        $defaults = AgentRole::defaults();
        return isset($defaults[$name]) ? $defaults[$name] : new AgentRole($name);
    }

    /**
     * 拼出交给成员的任务提示词
     *
     * @param string $role
     * @param string $task
     * @param array<string, mixed> $context
     * @return string
     */
    protected function buildPrompt($role, $task, array $context)
    {
        $parts = [];

        $messages = $this->bus->inboxPrompt($role);
        if ($messages !== '') {
            $parts[] = $messages;
        }
        $parts[] = $task;

        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $text = (string) $value;
            } else {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
                $text = $encoded === false ? '' : $encoded;
            }
            if (trim($text) !== '') {
                $parts[] = $key . '：' . $text;
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * 发一个团队事件
     *
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
