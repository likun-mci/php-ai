<?php
namespace Ai\Cli;

use Ai\Exceptions\ConfigException;
use Ai\Exceptions\ProcessException;

/**
 * Claude Code CLI 常驻双工会话
 *
 * 复刻官方 IDE 插件（VSCode / JetBrains）的进程工作方式：claude 以长驻进程运行，
 * stdin 持续接收 JSON 行消息，stdout 持续吐出 stream-json 事件，工具权限通过
 * stdio 上的 control_request 协议实时回调给宿主程序决策。
 *
 * 插件实际启动参数：
 *     claude --output-format stream-json --verbose --input-format stream-json
 *            --max-thinking-tokens 31999 --permission-prompt-tool stdio
 *            --setting-sources=user,project,local --permission-mode auto
 *            --debug --debug-to-stderr --enable-auth-status --no-chrome
 *            --replay-user-messages
 *
 * 本类默认采用其中与无人值守场景相符的部分（双工 + stdio 权限回调 + 全设置源 +
 * 消息回显 + 禁用 Chrome），权限模式保持更保守的 acceptEdits，思考预算与调试
 * 日志默认不开，需要时用 setThinkingTokens() / setDebug() 打开。
 *
 * 相比一次性的 ClaudeCode，多出的能力：
 *  - 多轮对话共用一个进程，上下文常驻，无需每轮 --resume 重放历史
 *  - 工具权限逐次回调宿主决策（onPermission），可放行 / 拒绝 / 改写工具入参
 *  - 运行中可中断（interrupt）、可热切换权限模式 / 模型 / 思考预算
 *  - 一轮进行中可以继续投新消息（post），CLI 会并入当前轮
 *
 * 权限回调不是完整拦截层：预授权规则、acceptEdits 自动放行的编辑、CLI 判定
 * 安全的沙箱只读命令都不会询问宿主。硬性禁用工具请用 setDisallowedTools()，
 * 详见 onPermission() 的说明。
 *
 * 用法：
 * ```php
 * $s = ClaudeCodeSession::create(['workdir' => '/var/www']);
 * $s->onPermission(function (array $req) {
 *     if ($req['tool_name'] === 'Bash') {
 *         return '本环境禁止执行 shell 命令';   // 返回字符串 = 拒绝并说明理由
 *     }
 *     return true;                              // 放行
 * });
 *
 * $a = $s->send('看一下 src 目录结构');
 * $b = $s->send('把刚才第一个文件的注释补全');   // 同一进程，接着上一轮
 * $s->close();
 * ```
 *
 * 非阻塞用法（常驻服务：跑着的时候还能继续提需求、随时停止、不钉住 worker）：
 * ```php
 * $s = ClaudeCodeSession::create(['workdir' => '/var/www'])
 *         ->setSleeper(function ($sec) { \Swoole\Coroutine::sleep($sec); });
 *
 * $s->post('把 src 下的注释补全');            // 立即返回，不等本轮结束
 *
 * while ($clientAlive()) {
 *     while ($msg = $queue->pop()) {
 *         $s->post($msg);                      // 轮内轮外一样调：轮内 = 插入当前轮
 *     }
 *     $active = $s->tick($onEvent);            // 泵一批事件，立即返回
 *     if (!$active && ($res = $s->takeResult())) {
 *         $saveAnswer($res);                   // 本轮收口，进程留着等下一轮
 *     }
 *     $s->isTurnActive() ? $pause(0.02) : $pause(0.1);
 * }
 * $s->close();
 * ```
 *
 * 轮内插入的语义（已在 claude CLI 2.1.207 上实测）：
 *  - 生效时机是"当前这次工具调用执行完之后"，不是立即打断正在跑的工具；
 *  - 不产生额外的 result 事件，整轮仍然只有一个 result，num_turns 累计——
 *    宿主若按 result 落库，要意识到这条记录对应"多条用户消息 + 一个回复"；
 *  - 每投递一轮 CLI 都会重发一个 system/init 事件（第二轮起几乎无耗时），
 *    getInit() 的内容会被覆盖，这是预期行为。
 *
 * 注意：本类依赖本地 proc_open 的双向管道，不支持 ClaudeCode 的自定义执行器
 * （setRunner）。受限 PHP 环境请改用 ClaudeCode 的一次性模式；协程环境用
 * setSleeper() 把内部等待换成协程让出即可，无需接管进程。
 *
 * @see ClaudeCode 一次性调用模式
 */
class ClaudeCodeSession extends ClaudeCode
{
    /** 默认 CLI 参数：双工 stream-json + stdio 权限回调（对齐 IDE 插件）
     * @var array<string, mixed>
     */
    protected static $defaultFlags = [
        'output-format'          => 'stream-json',
        'input-format'           => 'stream-json',
        'verbose'                => true,
        'replay-user-messages'   => true,
        'permission-prompt-tool' => 'stdio',
        'setting-sources'        => 'user,project,local',
        'no-chrome'              => true,
        'permission-mode'        => 'acceptEdits',
    ];

    /** @var resource|null 子进程句柄 */
    protected $proc = null;

    /** @var array<int, resource> 子进程管道 [0=>stdin, 1=>stdout, 2=>stderr] */
    protected $pipes = [];

    /** @var string stdout 行缓冲 */
    protected $lineBuf = '';

    /** @var string stderr 累积（用于进程异常退出时的报错信息） */
    protected $stderrBuf = '';

    /** @var string 实际执行的命令 */
    protected $command = '';

    /** @var array<string, mixed> system/init 事件原文 */
    protected $init = [];

    /** @var string[] 本次会话可用工具名列表 */
    protected $availableTools = [];

    /** @var callable|null 权限决策回调 function(array $request): bool|string|array */
    protected $permissionHandler = null;

    /** @var string[]|null 未注册回调时自动放行的工具名；null 表示全部放行 */
    protected $autoApproveTools = ['Read', 'Edit', 'Write', 'Grep', 'Glob'];

    /** @var array<string, mixed> 宿主发起、等待 CLI 回复的 control_request：request_id => true */
    protected $pendingRequests = [];

    /** @var array<string, mixed> 宿主发起的请求收到的回复：request_id => response */
    protected $requestResults = [];

    /** @var bool 当前这一轮是否已收到 result 事件 */
    protected $turnDone = false;

    /** @var bool 当前是否处在一轮之中（post 开轮，result 收轮） */
    protected $turnActive = false;

    /** @var array<mixed> 本轮采集容器（tick 跨多次调用累积，故存在实例上） */
    protected $turnCollect = [];

    /** @var float 本轮开始时间戳 */
    protected $turnStartedAt = 0.0;

    /** @var float 本轮超时时刻（0 表示不限制） */
    protected $turnDeadline = 0.0;

    /** @var array<string, mixed>|null 最近一轮的结果，takeResult() 取走即清空 */
    protected $lastResult = null;

    /** @var array<int, array{0:string, 1:mixed}> 待派发的本地事件（start / posted） */
    protected $pendingEvents = [];

    /** @var string[] 已投递、等待 CLI 回显确认的消息 ID（FIFO 与 replay 事件对账） */
    protected $postedQueue = [];

    /** @var int 消息 ID 自增序号 */
    protected $postSeq = 0;

    /** @var string 待写入 stdin 的缓冲（整行入队，保证单条 JSON 不被并发写穿插） */
    protected $writeBuf = '';

    /** @var bool 是否有执行流正在 flushWrites（协程下防止两条 JSON 交错写入） */
    protected $flushing = false;

    /** @var bool 是否有执行流正在 drainPipes（协程下防止行缓冲被两处同时切分） */
    protected $draining = false;

    /** @var bool 最近一次 tick() 是否读到了数据（阻塞式循环据此决定要不要等） */
    protected $tickHadData = false;

    /** @var callable|null 最近一次 tick() 使用的事件回调（控制指令等待期间复用） */
    protected $turnEmit = null;

    /** @var int 每轮等待上限秒数（0 表示不限制），未单独设置时取 timeout */
    protected $turnTimeout = 0;

    /** @var int 请求 ID 自增序号 */
    protected $requestSeq = 0;

    /**
     * @param array<mixed> $config 除 ClaudeCode 支持的键外，额外支持：
     *                      on_permission（权限回调）、auto_approve_tools（自动放行工具名数组）、
     *                      turn_timeout（单轮超时秒数）
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
        if (isset($config['on_permission'])) {
            $this->permissionHandler = $config['on_permission'];
        }
        if (array_key_exists('auto_approve_tools', $config)) {
            $this->autoApproveTools = $config['auto_approve_tools'];
        }
        if (isset($config['turn_timeout'])) {
            $this->turnTimeout = max(0, (int) $config['turn_timeout']);
        }
    }

    // ---------------------------------------------------------------------
    // 权限决策
    // ---------------------------------------------------------------------

    /**
     * 注册工具权限回调，等价于 IDE 插件里弹出的"是否允许执行"对话框。
     *
     * 回调签名 function(array $request): bool|string|array，$request 含：
     *  - tool_name              工具名，如 Bash / Write / Edit
     *  - display_name           展示名
     *  - input                  工具入参数组
     *  - description            简述，如目标文件名
     *  - tool_use_id            工具调用 ID
     *  - permission_suggestions CLI 给出的建议动作
     *
     * 返回值：
     *  - true            放行，按原入参执行
     *  - false           拒绝（使用默认提示语）
     *  - string          拒绝，字符串作为给模型的理由
     *  - ['behavior' => 'allow', 'updatedInput' => [...]]  放行并改写入参
     *  - ['behavior' => 'deny', 'message' => '...']        拒绝
     *
     * 重要：回调只会收到 CLI 认为"需要询问"的调用，它不是一道完整的拦截层。
     * 以下情况 CLI 会自行放行、不询问宿主：
     *  - 设置文件（~/.claude/settings.json、项目 .claude/settings.json）里已预授权的规则，
     *    可用 setSettingSources([]) 不加载这些规则
     *  - permission-mode 已自动放行的类别（如 acceptEdits 下的文件编辑）
     *  - CLI 判定为只读、在沙箱中执行的安全命令
     * 要硬性禁止某个工具，用 setDisallowedTools(['Bash'])（从工具集里摘掉，
     * 模型根本看不到）或 setTools([...]) 限定可用工具集，不要只依赖本回调。
     */
    public function onPermission(callable $handler): self
    {
        $this->permissionHandler = $handler;
        return $this;
    }

    /**
     * 未注册 onPermission 回调时，自动放行的工具名单（默认与 ClaudeCode 的白名单一致）。
     * 名单外的工具会被拒绝。
     * @param array<mixed> $tools
     */
    public function setAutoApproveTools(array $tools): self
    {
        $this->autoApproveTools = $tools;
        return $this;
    }

    /**
     * 未注册 onPermission 回调时放行全部工具请求。
     * 等价于把权限判断完全交给 CLI 自身的 permission-mode 与设置文件规则。
     */
    public function allowAllTools(): self
    {
        $this->autoApproveTools = null;
        return $this;
    }

    /**
     * 设置单轮等待上限秒数（0 表示不限制）。未设置时回退到 setTimeout() 的值。
     */
    public function setTurnTimeout(int $seconds): self
    {
        $this->turnTimeout = max(0, $seconds);
        return $this;
    }

    // ---------------------------------------------------------------------
    // 进程生命周期
    // ---------------------------------------------------------------------

    /**
     * 启动常驻 claude 进程（send() 会自动调用，一般无需手动执行）
     */
    public function start(): self
    {
        if ($this->isRunning()) {
            return $this;
        }
        if (!function_exists('proc_open')) {
            throw new ProcessException('当前 PHP 环境未启用 proc_open，无法启动 claude 会话进程');
        }

        $binary = $this->getBinary();
        $this->command = $this->buildBaseCommand($binary, $this->workdir, []);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open(
            $this->command,
            $descriptors,
            $pipes,
            $this->workdir !== '' ? $this->workdir : null,
            $this->buildLocalEnv()
        );
        if (!is_resource($proc)) {
            throw new ProcessException('无法启动 claude 会话进程: ' . $this->command);
        }

        $this->proc = $proc;
        $this->pipes = $pipes;
        $this->lineBuf = '';
        $this->stderrBuf = '';
        $this->writeBuf = '';
        $this->postedQueue = [];
        $this->pendingEvents = [];
        // stdin 也设成非阻塞：管道缓冲区满时 fwrite 不再把整个执行流钉住，
        // 写不完的部分留在 $writeBuf 里，由事件泵继续冲刷（见 flushWrites）
        stream_set_blocking($this->pipes[0], false);
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        return $this;
    }

    /**
     * 进程是否仍在运行
     */
    public function isRunning(): bool
    {
        if (!is_resource($this->proc)) {
            return false;
        }
        $status = @proc_get_status($this->proc);
        return is_array($status) && $status['running'];
    }

    /**
     * 关闭会话：合上 stdin 让 claude 正常退出，返回退出码
     */
    public function close(): int
    {
        if (!is_resource($this->proc)) {
            return 0;
        }
        // 句柄先存到局部：下面几步会调用别的方法，静态分析无法确定 $this->proc 还在
        $proc = $this->proc;
        // 合上 stdin 前先把队列里没写完的消息送出去，否则最后一条会被丢掉
        $flushDeadline = microtime(true) + 5;
        while ($this->writeBuf !== '' && microtime(true) < $flushDeadline && $this->isRunning()) {
            if (!$this->flushWrites()) {
                $this->drainPipes(function () {
                });
                $this->pause(0.02);
            }
        }
        $this->writeBuf = '';
        if (isset($this->pipes[0]) && is_resource($this->pipes[0])) {
            @fclose($this->pipes[0]);
        }
        // 排空剩余输出，避免管道缓冲区把子进程卡住
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline && $this->isRunning()) {
            $this->drainPipes(function () {
            });
            $this->pause(0.02);
        }
        $this->drainPipes(function () {
        });

        foreach ([1, 2] as $i) {
            if (isset($this->pipes[$i]) && is_resource($this->pipes[$i])) {
                @fclose($this->pipes[$i]);
            }
        }
        $exit = @proc_close($proc);
        $this->proc = null;
        $this->pipes = [];
        $this->turnActive = false;
        return (int) $exit;
    }

    /**
     * 结束进程（用于超时或异常场景）
     *
     * 先发 SIGTERM 并留出 setKillGrace() 的宽限期（默认 2 秒），让 claude 自己
     * 收尾、把本轮 session 落盘（之后还能 --resume）；宽限期内没退出才 SIGKILL。
     * 想让 claude 正常结束请优先用 interrupt()（中断当轮、进程保活）或 close()。
     */
    public function kill(): self
    {
        if (is_resource($this->proc)) {
            $proc = $this->proc;
            $this->terminateProc($proc);
            $this->writeBuf = '';
            $this->turnActive = false;
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            @proc_close($proc);
            $this->proc = null;
            $this->pipes = [];
        }
        return $this;
    }

    public function __destruct()
    {
        if (is_resource($this->proc)) {
            $this->kill();
        }
    }

    // ---------------------------------------------------------------------
    // 对话
    // ---------------------------------------------------------------------

    /**
     * 发送一轮用户消息，阻塞直到本轮结束（收到 result 事件）
     *
     * @param string        $text    用户消息文本
     * @param callable|null $onEvent 逐事件回调，事件语义同 ClaudeCode::runStream()
     */
    public function send(string $text, $onEvent = null): ClaudeCodeResponse
    {
        return $this->sendMessage([['type' => 'text', 'text' => $text]], $onEvent);
    }

    /**
     * chat 别名，与 ClaudeCode::chat() 保持一致的调用习惯
     * @param array<mixed> $options
     */
    public function chat(string $prompt, array $options = []): ClaudeCodeResponse
    {
        $onEvent = isset($options['on_event']) ? $options['on_event'] : null;
        return $this->send($prompt, $onEvent);
    }

    /**
     * 发送任意内容块数组（可混合 text 与图片等块），阻塞直到本轮结束
     *
     * @param array<mixed> $content 内容块数组，如 [['type'=>'text','text'=>'...']]
     * @param callable|null $onEvent 逐事件回调
     */
    public function sendMessage(array $content, $onEvent = null): ClaudeCodeResponse
    {
        $emit = $onEvent ?: $this->onEvent;
        $emit = is_callable($emit) ? $emit : function () {
        };

        $this->postMessage($content);

        $collect = [];
        $this->pump($emit, $collect);

        $result = $this->takeResult();
        if ($result === null) {
            // 只有在事件回调里自己把结果取走时才会走到这里
            throw new ProcessException('本轮结果已被 takeResult() 取走，无法作为 send() 的返回值');
        }
        return $result;
    }

    // ---------------------------------------------------------------------
    // 非阻塞用法：宿主自己驱动事件泵（常驻服务 / 处理中继续提需求）
    // ---------------------------------------------------------------------

    /**
     * 非阻塞投递一条用户消息，立即返回，不等待本轮结束
     *
     * 轮内调用 = 插入当前轮：CLI 会在**当前这次工具调用执行完之后**把新消息并入
     * 下一次模型调用，不打断正在跑的工具，整轮仍然只产生一个 result 事件
     * （num_turns 累计）。轮外调用 = 开启新一轮。两种情况调用方式完全一样。
     *
     * 与 send() 的区别只在"等不等"：本方法写完就返回，事件要靠 tick() 去泵。
     *
     * @return string 本地消息 ID，可与 'posted' / 'delivered' 事件对账
     */
    public function post(string $text): string
    {
        return $this->postMessage([['type' => 'text', 'text' => $text]]);
    }

    /**
     * 同 post()，投递任意内容块数组（可混 text / image 块）
     *
     * @param array<mixed> $content 内容块数组，如 [['type'=>'text','text'=>'...']]
     * @return string 本地消息 ID
     */
    public function postMessage(array $content): string
    {
        $this->start();

        $injected = $this->turnActive;
        if (!$injected) {
            $this->beginTurn();
        } elseif ($this->turnDeadline > 0) {
            // 轮内又来了新需求，本轮的活变多了，超时从此刻重新计
            $timeout = $this->turnTimeout > 0 ? $this->turnTimeout : $this->timeout;
            $this->turnDeadline = microtime(true) + $timeout;
        }

        $id = $this->nextMessageId();
        $this->writeLine([
            'type'    => 'user',
            'message' => ['role' => 'user', 'content' => $content],
        ]);
        $this->postedQueue[] = $id;
        $this->pendingEvents[] = ['posted', [
            'id'       => $id,
            'content'  => $content,
            'injected' => $injected,
        ]];

        return $id;
    }

    /**
     * 非阻塞事件泵：处理当前已经可读的输出并派发事件，随即返回
     *
     * 由宿主自己的循环驱动，因此两次调用之间可以做任何事——投递新消息、
     * 检查客户端是否还连着、把增量落库。本方法自身不等待、不 sleep，
     * 空转时请由调用方决定歇多久（见类注释里的示例）。
     *
     * @param  callable|null $onEvent 事件回调，语义同 send()；为空时用构造时配置的 on_event
     * @return bool 本轮是否仍在进行中（true = 还没收到 result）
     * @throws ProcessException 进程意外退出或本轮超时
     */
    public function tick($onEvent = null): bool
    {
        $emit = $onEvent ?: $this->onEvent;
        $emit = is_callable($emit) ? $emit : function () {
        };

        $this->turnEmit = $emit;
        $this->tickHadData = false;
        $this->flushPendingEvents($emit);

        if (!is_resource($this->proc)) {
            return false;
        }

        $this->flushWrites();
        $this->tickHadData = $this->drainPipes($emit, $this->turnCollect);

        if ($this->turnActive && $this->turnDone) {
            $this->finalizeTurn($emit);
            return $this->turnActive;
        }

        if (!$this->isRunning()) {
            // 进程退出前可能还有缓冲输出没读完
            $this->tickHadData = $this->drainPipes($emit, $this->turnCollect) || $this->tickHadData;
            if ($this->turnActive && $this->turnDone) {
                $this->finalizeTurn($emit);
                return $this->turnActive;
            }
            if (!$this->turnActive) {
                return false;
            }
            $this->turnActive = false;
            throw new ProcessException(
                'claude 会话进程意外退出' . ($this->stderrBuf !== ''
                    ? '：' . substr(trim($this->stderrBuf), -500) : '')
            );
        }

        if ($this->turnActive && $this->turnDeadline > 0 && microtime(true) > $this->turnDeadline) {
            $timeout = $this->turnTimeout > 0 ? $this->turnTimeout : $this->timeout;
            $this->turnActive = false;
            throw new ProcessException('claude 会话本轮超时（' . $timeout . 's）');
        }

        return $this->turnActive;
    }

    /**
     * 当前是否处在一轮之中（供 UI 决定输入框 / 停止按钮的状态）
     */
    public function isTurnActive(): bool
    {
        return $this->turnActive;
    }

    /**
     * 取走最近一轮的完整结果，取走即清空；没有未取走的结果时返回 null
     *
     * 结构与 send() 的返回完全一致，两套 API 可以复用同一份落库代码。
     * 未取走的结果会被下一轮覆盖。
     */
    public function takeResult(): ?ClaudeCodeResponse
    {
        if ($this->lastResult === null) {
            return null;
        }
        $result = $this->lastResult;
        $this->lastResult = null;
        return new ClaudeCodeResponse($result);
    }

    /**
     * 开启新一轮：重置采集容器与超时，并排队一个 start 事件
     */
    protected function beginTurn(): void
    {
        $this->turnCollect   = $this->newCollect();
        $this->turnDone      = false;
        $this->turnActive    = true;
        $this->turnStartedAt = microtime(true);
        $timeout = $this->turnTimeout > 0 ? $this->turnTimeout : $this->timeout;
        $this->turnDeadline = $timeout > 0 ? microtime(true) + $timeout : 0.0;
        $this->pendingEvents[] = ['start', ['resume' => ($this->sessionId !== '')]];
    }

    /**
     * 收轮：把采集结果汇总成 result 数组存起来，并派发 result / done 事件
     */
    protected function finalizeTurn(callable $emit): void
    {
        $collect = $this->turnCollect;
        $collect['duration'] = $collect['duration'] > 0
            ? $collect['duration']
            : (int) ((microtime(true) - $this->turnStartedAt) * 1000);

        if ($collect['session_id'] !== '') {
            $this->sessionId = $collect['session_id'];
        }
        if ($collect['init']) {
            $this->init = $collect['init'];
        }
        if ($collect['tools']) {
            $this->availableTools = $collect['tools'];
        }

        $text = $collect['result_text'] !== '' ? $collect['result_text'] : $collect['asst_text'];
        $result = [
            'content'      => $text,
            'model'        => $collect['model'],
            'usage'        => $collect['usage'],
            'success'      => !$collect['is_error'],
            'session_id'   => $collect['session_id'],
            'cost_usd'     => $collect['cost'],
            'num_turns'    => $collect['num_turns'],
            'duration_ms'  => $collect['duration'],
            'exit_code'    => $this->isRunning() ? 0 : -1,
            'subtype'      => $collect['subtype'],
            'stop_reason'  => $collect['stop_reason'],
            'thinking'     => $collect['thinking'],
            'tools'        => $collect['tools'] ?: $this->availableTools,
            'tool_uses'    => $collect['tool_uses'],
            'permission_denials' => $collect['denials'],
            'init'         => $collect['init'] ?: $this->init,
            'structured'   => self::decodeStructured($text),
            'command'      => $this->command,
            'raw'          => [],
        ];

        $this->lastResult = $result;
        // 先落状态再派发：回调里紧接着 post() 下一轮时状态才是对的
        $this->turnActive = false;
        $emit('result', $result);
        $emit('done', null);
        // done 之后本轮的回调就该收摊了，别让轮外的控制指令再往它上面派事件
        $this->turnEmit = null;
    }

    /**
     * 派发排队中的本地事件（start / posted）
     */
    protected function flushPendingEvents(callable $emit): void
    {
        while ($this->pendingEvents) {
            $event = array_shift($this->pendingEvents);
            $emit($event[0], $event[1]);
        }
    }

    /**
     * 生成本地消息 ID
     */
    protected function nextMessageId(): string
    {
        $this->postSeq++;
        return 'msg-' . getmypid() . '-' . $this->postSeq . '-' . dechex(mt_rand(0x100000, 0xffffff));
    }

    // ---------------------------------------------------------------------
    // 运行时控制（宿主 → CLI 的 control_request）
    // ---------------------------------------------------------------------

    /**
     * 中断当前正在进行的回合，等价于 IDE 插件里的"停止"按钮。
     * 通常在 send() 的事件回调中根据条件调用。
     * @return $this
     */
    public function interrupt(): self
    {
        return $this->control(['subtype' => 'interrupt'], false);
    }

    /**
     * 切换权限模式。进程未启动时只改启动参数，已启动则同时热切换。
     *
     * 返回类型必须写成父类的 ClaudeCode 而不是 self：两边都写 self 时，
     * 父类的 self 解析为 ClaudeCode、子类的解析为 ClaudeCodeSession，
     * 属于返回类型协变——PHP 7.4 才允许，7.2 上会直接 Fatal error 导致本类无法加载。
     * 本库声明兼容 PHP >= 7.2，因此这里保持与父类完全一致的返回类型，
     * 由 @return static 让 IDE 与静态分析仍能推断出链式调用的实际类型。
     *
     * @return static
     */
    public function setPermissionMode(string $mode): ClaudeCode
    {
        parent::setPermissionMode($mode);
        if ($this->isRunning()) {
            $this->control(['subtype' => 'set_permission_mode', 'mode' => $mode], false);
        }
        return $this;
    }

    /**
     * 运行中热切换模型（进程未启动时请用 setModel()）
     */
    public function switchModel(string $model): self
    {
        $this->model = trim($model);
        if ($this->isRunning()) {
            $this->control(['subtype' => 'set_model', 'model' => $this->model], false);
        }
        return $this;
    }

    /**
     * 运行中热调整思考预算（进程未启动时请用 setThinkingTokens()）
     */
    public function switchThinkingTokens(int $tokens): self
    {
        if ($this->isRunning()) {
            $this->control([
                'subtype' => 'set_max_thinking_tokens',
                'maxThinkingTokens' => $tokens,
            ], false);
        } else {
            $this->setThinkingTokens($tokens);
        }
        return $this;
    }

    /**
     * 发送一条自定义 control_request。
     *
     * @param array<string, mixed> $request 请求体，必须含 subtype
     * @param bool  $wait    是否阻塞等待 CLI 回复
     * @param int   $timeout 等待上限秒数，0 表示取 turn_timeout / timeout 配置
     * @return ($wait is true ? array<string, mixed> : $this)
     *         $wait 为 true 时返回 CLI 的回复数组，否则返回 $this 以便链式调用。
     *         写成条件返回类型而非联合类型，调用方按 true 调用时静态分析才能确定拿到的是数组
     */
    public function control(array $request, bool $wait = false, int $timeout = 0)
    {
        if (!$this->isRunning()) {
            throw new ProcessException('claude 会话进程未运行，无法发送控制指令');
        }
        $requestId = $this->nextRequestId();
        $this->pendingRequests[$requestId] = true;
        $this->writeLine([
            'type'       => 'control_request',
            'request_id' => $requestId,
            'request'    => $request,
        ]);
        if (!$wait) {
            return $this;
        }
        return $this->waitControlResponse($requestId, $timeout);
    }

    /**
     * 覆盖父类：进程已在运行时直接复用，避免为一次查询多起一个进程。
     * 这样 getUsage() 拿到的 session 花费就是本会话的真实累计值。
     * @param array<mixed> $extra
     * @return array<mixed>
     */
    protected function queryControl(string $subtype, array $extra = [], int $timeout = 60): array
    {
        if (!$this->isRunning()) {
            return parent::queryControl($subtype, $extra, $timeout);
        }
        $resp = $this->control(array_merge(['subtype' => $subtype], $extra), true, $timeout);
        return self::unwrapControlResponse($subtype, $resp);
    }

    /**
     * 获取本会话花费概览的文本报告（同交互式 /cost）
     *
     * 注：CLI 的 get_context_usage（上下文窗口占用）只在交互式 UI 下响应，
     * headless 模式下不会回包，故本库未提供对应方法；确需尝试可自行调用
     * control(['subtype' => 'get_context_usage'], true, 5)，注意超时后
     * 同一进程的后续控制查询可能一并失效。
     */
    public function getSessionCost(): string
    {
        $resp = $this->queryControl('get_session_cost', [], 60);
        return isset($resp['text']) ? (string) $resp['text'] : '';
    }

    // ---------------------------------------------------------------------
    // 会话信息
    // ---------------------------------------------------------------------

    /**
     * 获取 system/init 事件原文（cwd、可用工具、MCP 服务器、斜杠命令等）
     * @return array<mixed>
     */
    public function getInit(): array
    {
        return $this->init;
    }

    /**
     * 获取本次会话可用的工具名列表
     * @return array<mixed>
     */
    public function getAvailableTools(): array
    {
        return $this->availableTools;
    }

    /**
     * 获取实际执行的命令
     */
    public function getCommand(): string
    {
        return $this->command;
    }

    // ---------------------------------------------------------------------
    // 内部：读写与事件循环
    // ---------------------------------------------------------------------

    /**
     * 本轮采集容器初始值
     * @return array<mixed>
     */
    protected function newCollect(): array
    {
        return [
            'session_id'  => $this->sessionId,
            'model'       => $this->model,
            'usage'       => [],
            'cost'        => 0.0,
            'num_turns'   => 0,
            'duration'    => 0,
            'result_text' => '',
            'asst_text'   => '',
            'thinking'    => '',
            'exit_code'   => -1,
            'is_error'    => false,
            'subtype'     => '',
            'stop_reason' => '',
            'tools'       => [],
            'tool_uses'   => [],
            'denials'     => [],
            'init'        => [],
        ];
    }

    /**
     * 把一行 JSON 排进 stdin 写队列，并立即尝试冲刷
     *
     * 整行一次性入队是这里的关键：投递消息与泵事件必然来自不同的执行流
     * （不同协程 / 不同 WS 帧），若直接 fwrite 且中途让出，另一处再写就会把两条
     * JSON 交错进同一行，CLI 当场解析失败。入队后由 flushWrites() 单点写出，
     * 顺序与完整性都有保证。
     *
     * @param array<mixed> $message
     */
    protected function writeLine(array $message): void
    {
        if (!isset($this->pipes[0]) || !is_resource($this->pipes[0])) {
            throw new ProcessException('claude 会话 stdin 已关闭');
        }
        $json = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new ConfigException('消息序列化失败: ' . json_last_error_msg());
        }
        $this->writeBuf .= $json . "\n";
        $this->flushWrites();
    }

    /**
     * 把写队列冲进 stdin（非阻塞）
     *
     * @return bool 队列是否已全部写出；false = 管道缓冲区满，剩余部分留待下次
     * @throws ProcessException 管道已关闭或写入失败
     */
    protected function flushWrites(): bool
    {
        if ($this->writeBuf === '') {
            return true;
        }
        if (!isset($this->pipes[0]) || !is_resource($this->pipes[0])) {
            throw new ProcessException('claude 会话 stdin 已关闭，仍有 '
                . strlen($this->writeBuf) . ' 字节未写出');
        }
        if ($this->flushing) {
            return false;   // 已有执行流在写，让它写完，避免两条 JSON 交错
        }
        $this->flushing = true;
        try {
            while ($this->writeBuf !== '') {
                $n = @fwrite($this->pipes[0], $this->writeBuf);
                if ($n === false) {
                    throw new ProcessException('写入 claude 会话 stdin 失败（进程可能已退出）');
                }
                if ($n === 0) {
                    return false;   // 缓冲区满，下一次 tick 再写
                }
                $this->writeBuf = $n >= strlen($this->writeBuf)
                    ? ''
                    : (string) substr($this->writeBuf, $n);
            }
            @fflush($this->pipes[0]);
        } finally {
            $this->flushing = false;
        }
        return true;
    }

    /**
     * 阻塞式事件循环：反复驱动 tick() 直到本轮 result 事件到达
     *
     * @param array<mixed> $collect 出参，回填本轮采集容器（保持历史签名）
     */
    protected function pump(callable $emit, array &$collect): void
    {
        while ($this->tick($emit)) {
            if (!$this->tickHadData) {
                $this->waitReadable(200000);
            }
        }
        $collect = $this->turnCollect;
    }

    /**
     * 读取当前可读的 stdout/stderr 数据并派发，返回是否读到内容
     * @param array<mixed> $collect
     */
    protected function drainPipes(callable $emit, array &$collect = null): bool
    {
        if ($this->draining) {
            // 另一个执行流正在读同一个行缓冲，让它读完，这里直接放行
            return false;
        }
        $this->draining = true;
        try {
            return $this->readPipes($emit, $collect);
        } finally {
            $this->draining = false;
        }
    }

    /**
     * drainPipes 的实际读取逻辑
     * @param array<mixed> $collect
     */
    protected function readPipes(callable $emit, array &$collect = null): bool
    {
        $got = false;
        if (isset($this->pipes[1]) && is_resource($this->pipes[1])) {
            while (($out = @fread($this->pipes[1], 65536)) !== false && $out !== '') {
                $got = true;
                $this->lineBuf .= $out;
                while (($pos = strpos($this->lineBuf, "\n")) !== false) {
                    $line = substr($this->lineBuf, 0, $pos);
                    $this->lineBuf = substr($this->lineBuf, $pos + 1);
                    if ($collect === null) {
                        continue;
                    }
                    $this->handleLine(trim($line), $collect, $emit);
                }
            }
        }
        if (isset($this->pipes[2]) && is_resource($this->pipes[2])) {
            while (($err = @fread($this->pipes[2], 65536)) !== false && $err !== '') {
                $got = true;
                $this->stderrBuf .= $err;
                if (strlen($this->stderrBuf) > 65536) {
                    $this->stderrBuf = substr($this->stderrBuf, -32768);
                }
                $emit('stderr', $err);
            }
        }
        return $got;
    }

    /**
     * 等待管道可读（无数据时让出 CPU）
     */
    protected function waitReadable(int $microseconds): void
    {
        if ($this->sleeper !== null) {
            // 协程环境：stream_select 会把整个 worker 钉死，改成"轮询 + 让出"
            $this->pause($microseconds / 1000000);
            return;
        }
        $read = [];
        foreach ([1, 2] as $i) {
            if (isset($this->pipes[$i]) && is_resource($this->pipes[$i])) {
                $read[] = $this->pipes[$i];
            }
        }
        if (!$read) {
            usleep($microseconds);
            return;
        }
        $write = $except = null;
        $sec = (int) ($microseconds / 1000000);
        $usec = $microseconds % 1000000;
        @stream_select($read, $write, $except, $sec, $usec);
    }

    /**
     * 覆盖父类：先处理双工模式特有的 control 消息，其余交给父类的事件分派
     * @param array<mixed> $collect
     */
    protected function handleLine(string $line, array &$collect, callable $emit): void
    {
        if ($line === '') {
            return;
        }
        $ev = self::parseEventLine($line);
        if ($ev === null) {
            $emit('line', $line);
            return;
        }

        $type = isset($ev['type']) ? (string) $ev['type'] : '';

        if ($type === 'control_request') {
            $this->handleControlRequest($ev, $emit);
            return;
        }
        if ($type === 'control_response') {
            $this->handleControlResponse($ev, $emit);
            return;
        }

        parent::handleLine($line, $collect, $emit);

        if ($type === 'user' && !empty($ev['isReplay'])) {
            // --replay-user-messages 的回显 = CLI 已收下这条消息。按投递顺序与
            // post() 返回的 ID 对账，宿主的 UI 可以把"已排队"改成"已送达"。
            $id = $this->postedQueue ? (string) array_shift($this->postedQueue) : '';
            $emit('delivered', ['id' => $id, 'event' => $ev]);
        }

        if ($type === 'result') {
            $this->turnDone = true;
        }
    }

    /**
     * 处理 CLI 发来的 control_request（主要是 can_use_tool 权限询问）
     * @param array<mixed> $ev
     */
    protected function handleControlRequest(array $ev, callable $emit): void
    {
        $requestId = isset($ev['request_id']) ? (string) $ev['request_id'] : '';
        $request = isset($ev['request']) && is_array($ev['request']) ? $ev['request'] : [];
        $subtype = isset($request['subtype']) ? (string) $request['subtype'] : '';

        if ($subtype !== 'can_use_tool') {
            // 其它子类型（hook_callback / mcp_message 等）本类未实现，明确回错避免 CLI 干等
            $emit('control_request', $ev);
            $this->writeLine([
                'type'     => 'control_response',
                'response' => [
                    'subtype'    => 'error',
                    'request_id' => $requestId,
                    'error'      => 'unsupported control_request subtype: ' . $subtype,
                ],
            ]);
            return;
        }

        $emit('permission_request', $request);
        $decision = $this->decidePermission($request);
        $emit('permission_decision', ['request' => $request, 'response' => $decision]);

        $this->writeLine([
            'type'     => 'control_response',
            'response' => [
                'subtype'    => 'success',
                'request_id' => $requestId,
                'response'   => $decision,
            ],
        ]);
    }

    /**
     * 把回调返回值归一化为 CLI 要求的权限响应结构
     * @param array<mixed> $request
     * @return array<mixed>
     */
    protected function decidePermission(array $request): array
    {
        if ($this->permissionHandler !== null) {
            $ret = call_user_func($this->permissionHandler, $request);
        } elseif ($this->autoApproveTools === null) {
            $ret = true;
        } else {
            $toolName = isset($request['tool_name']) ? (string) $request['tool_name'] : '';
            $ret = in_array($toolName, $this->autoApproveTools, true)
                ? true
                : '工具 ' . $toolName . ' 不在放行名单内，本次调用被宿主拒绝';
        }

        if ($ret === true) {
            return ['behavior' => 'allow', 'updatedInput' => isset($request['input']) ? $request['input'] : []];
        }
        if ($ret === false || $ret === null) {
            return ['behavior' => 'deny', 'message' => '宿主程序拒绝了本次工具调用'];
        }
        if (is_string($ret)) {
            return ['behavior' => 'deny', 'message' => $ret];
        }
        if (is_array($ret)) {
            if (!isset($ret['behavior'])) {
                $ret['behavior'] = 'allow';
            }
            if ($ret['behavior'] === 'allow' && !isset($ret['updatedInput'])) {
                $ret['updatedInput'] = isset($request['input']) ? $request['input'] : [];
            }
            return $ret;
        }
        return ['behavior' => 'deny', 'message' => '权限回调返回值无法识别'];
    }

    /**
     * 处理 control_response：只认宿主自己发出过的 request_id，
     * 其余（--replay-user-messages 把宿主写入的响应原样回显）直接忽略
     * @param array<mixed> $ev
     */
    protected function handleControlResponse(array $ev, callable $emit): void
    {
        $response = isset($ev['response']) && is_array($ev['response']) ? $ev['response'] : [];
        $requestId = isset($response['request_id']) ? (string) $response['request_id'] : '';
        if ($requestId === '' || !isset($this->pendingRequests[$requestId])) {
            return;
        }
        unset($this->pendingRequests[$requestId]);
        $this->requestResults[$requestId] = $response;
        $emit('control_response', $response);
    }

    /**
     * 阻塞等待某条宿主控制请求的回复
     *
     * @param int $timeout 等待上限秒数，0 表示取 turn_timeout / timeout 配置
     * @return array<mixed>
     */
    protected function waitControlResponse(string $requestId, int $timeout = 0): array
    {
        if ($timeout <= 0) {
            $timeout = $this->turnTimeout > 0 ? $this->turnTimeout : ($this->timeout > 0 ? $this->timeout : 30);
        }
        $deadline = microtime(true) + $timeout;
        // 等待期间照样要把事件派发出去、写进本轮采集容器：控制指令随时可能在
        // 一轮进行中发出（停止按钮、热切模型），这里若吞掉输出，本轮的正文与
        // result 就丢了。回调复用最近一次 tick() 的那个。
        $emit = $this->turnEmit;
        $emit = is_callable($emit) ? $emit : function () {
        };
        while (!isset($this->requestResults[$requestId])) {
            $this->flushWrites();
            if (!$this->drainPipes($emit, $this->turnCollect)) {
                $this->waitReadable(100000);
            }
            if (!$this->isRunning()) {
                throw new ProcessException('claude 会话进程已退出，未收到控制指令回复');
            }
            if (microtime(true) > $deadline) {
                throw new ProcessException('等待控制指令回复超时（' . $timeout . 's）');
            }
        }
        $result = $this->requestResults[$requestId];
        unset($this->requestResults[$requestId]);
        return $result;
    }

    /**
     * 生成控制请求 ID
     */
    protected function nextRequestId(): string
    {
        $this->requestSeq++;
        return 'php-' . getmypid() . '-' . $this->requestSeq . '-' . dechex(mt_rand(0x100000, 0xffffff));
    }
}
