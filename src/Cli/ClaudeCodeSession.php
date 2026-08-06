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
 * 注意：本类依赖本地 proc_open 的双向管道，不支持 ClaudeCode 的自定义执行器
 * （setRunner）。受限 PHP 环境请改用 ClaudeCode 的一次性模式。
 *
 * @see ClaudeCode 一次性调用模式
 */
class ClaudeCodeSession extends ClaudeCode
{
    /** 默认 CLI 参数：双工 stream-json + stdio 权限回调（对齐 IDE 插件） */
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

    /** @var array 子进程管道 [0=>stdin, 1=>stdout, 2=>stderr] */
    protected $pipes = [];

    /** @var string stdout 行缓冲 */
    protected $lineBuf = '';

    /** @var string stderr 累积（用于进程异常退出时的报错信息） */
    protected $stderrBuf = '';

    /** @var string 实际执行的命令 */
    protected $command = '';

    /** @var array system/init 事件原文 */
    protected $init = [];

    /** @var array 本次会话可用工具名列表 */
    protected $availableTools = [];

    /** @var callable|null 权限决策回调 function(array $request): bool|string|array */
    protected $permissionHandler = null;

    /** @var array|null 未注册回调时自动放行的工具名；null 表示全部放行 */
    protected $autoApproveTools = ['Read', 'Edit', 'Write', 'Grep', 'Glob'];

    /** @var array 宿主发起、等待 CLI 回复的 control_request：request_id => true */
    protected $pendingRequests = [];

    /** @var array 宿主发起的请求收到的回复：request_id => response */
    protected $requestResults = [];

    /** @var bool 当前这一轮是否已收到 result 事件 */
    protected $turnDone = false;

    /** @var int 每轮等待上限秒数（0 表示不限制），未单独设置时取 timeout */
    protected $turnTimeout = 0;

    /** @var int 请求 ID 自增序号 */
    protected $requestSeq = 0;

    /**
     * @param array $config 除 ClaudeCode 支持的键外，额外支持：
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
        if (isset($this->pipes[0]) && is_resource($this->pipes[0])) {
            @fclose($this->pipes[0]);
        }
        // 排空剩余输出，避免管道缓冲区把子进程卡住
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline && $this->isRunning()) {
            $this->drainPipes(function () {
            });
            usleep(20000);
        }
        $this->drainPipes(function () {
        });

        foreach ([1, 2] as $i) {
            if (isset($this->pipes[$i]) && is_resource($this->pipes[$i])) {
                @fclose($this->pipes[$i]);
            }
        }
        $exit = @proc_close($this->proc);
        $this->proc = null;
        $this->pipes = [];
        return (int) $exit;
    }

    /**
     * 强制结束进程（用于超时或异常场景）
     */
    public function kill(): self
    {
        if (is_resource($this->proc)) {
            @proc_terminate($this->proc);
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            @proc_close($this->proc);
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
     */
    public function chat(string $prompt, array $options = []): ClaudeCodeResponse
    {
        $onEvent = isset($options['on_event']) ? $options['on_event'] : null;
        return $this->send($prompt, $onEvent);
    }

    /**
     * 发送任意内容块数组（可混合 text 与图片等块），阻塞直到本轮结束
     *
     * @param array         $content 内容块数组，如 [['type'=>'text','text'=>'...']]
     * @param callable|null $onEvent 逐事件回调
     */
    public function sendMessage(array $content, $onEvent = null): ClaudeCodeResponse
    {
        $this->start();

        $emit = $onEvent ?: $this->onEvent;
        $emit = is_callable($emit) ? $emit : function () {
        };

        $collect = $this->newCollect();
        $this->turnDone = false;

        $this->writeLine([
            'type'    => 'user',
            'message' => ['role' => 'user', 'content' => $content],
        ]);

        $emit('start', ['resume' => ($this->sessionId !== '')]);

        $startedAt = microtime(true);
        $this->pump($emit, $collect);
        $collect['duration'] = $collect['duration'] > 0
            ? $collect['duration']
            : (int) ((microtime(true) - $startedAt) * 1000);

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

        $emit('result', $result);
        $emit('done', null);

        return new ClaudeCodeResponse($result);
    }

    // ---------------------------------------------------------------------
    // 运行时控制（宿主 → CLI 的 control_request）
    // ---------------------------------------------------------------------

    /**
     * 中断当前正在进行的回合，等价于 IDE 插件里的"停止"按钮。
     * 通常在 send() 的事件回调中根据条件调用。
     */
    public function interrupt(): self
    {
        return $this->control(['subtype' => 'interrupt'], false);
    }

    /**
     * 切换权限模式。进程未启动时只改启动参数，已启动则同时热切换。
     */
    public function setPermissionMode(string $mode): self
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
     * @param array $request 请求体，必须含 subtype
     * @param bool  $wait    是否阻塞等待 CLI 回复
     * @return self|array    $wait 为 true 时返回 CLI 的回复数组，否则返回 $this
     */
    public function control(array $request, bool $wait = false)
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
        return $this->waitControlResponse($requestId);
    }

    // ---------------------------------------------------------------------
    // 会话信息
    // ---------------------------------------------------------------------

    /**
     * 获取 system/init 事件原文（cwd、可用工具、MCP 服务器、斜杠命令等）
     */
    public function getInit(): array
    {
        return $this->init;
    }

    /**
     * 获取本次会话可用的工具名列表
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
     * 写一行 JSON 到子进程 stdin
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
        $payload = $json . "\n";
        $len = strlen($payload);
        $written = 0;
        while ($written < $len) {
            $n = @fwrite($this->pipes[0], substr($payload, $written));
            if ($n === false || $n === 0) {
                throw new ProcessException('写入 claude 会话 stdin 失败（进程可能已退出）');
            }
            $written += $n;
        }
        @fflush($this->pipes[0]);
    }

    /**
     * 事件循环：读取输出并派发，直到本轮 result 事件到达
     */
    protected function pump(callable $emit, array &$collect): void
    {
        $timeout = $this->turnTimeout > 0 ? $this->turnTimeout : $this->timeout;
        $deadline = $timeout > 0 ? microtime(true) + $timeout : 0;

        while (!$this->turnDone) {
            $got = $this->drainPipes($emit, $collect);

            if ($this->turnDone) {
                break;
            }
            if (!$this->isRunning()) {
                // 进程退出前可能还有缓冲输出未读完
                $this->drainPipes($emit, $collect);
                if ($this->turnDone) {
                    break;
                }
                throw new ProcessException(
                    'claude 会话进程意外退出' . ($this->stderrBuf !== ''
                        ? '：' . substr(trim($this->stderrBuf), -500) : '')
                );
            }
            if ($deadline > 0 && microtime(true) > $deadline) {
                throw new ProcessException('claude 会话本轮超时（' . $timeout . 's）');
            }
            if (!$got) {
                $this->waitReadable(200000);
            }
        }
    }

    /**
     * 读取当前可读的 stdout/stderr 数据并派发，返回是否读到内容
     */
    protected function drainPipes(callable $emit, array &$collect = null): bool
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

        if ($type === 'result') {
            $this->turnDone = true;
        }
    }

    /**
     * 处理 CLI 发来的 control_request（主要是 can_use_tool 权限询问）
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
     */
    protected function waitControlResponse(string $requestId): array
    {
        $timeout = $this->turnTimeout > 0 ? $this->turnTimeout : ($this->timeout > 0 ? $this->timeout : 30);
        $deadline = microtime(true) + $timeout;
        $noop = function () {
        };
        $collect = $this->newCollect();
        while (!isset($this->requestResults[$requestId])) {
            if (!$this->drainPipes($noop, $collect)) {
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
