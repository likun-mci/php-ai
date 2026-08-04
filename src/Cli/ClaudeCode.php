<?php
namespace Ai\Cli;

use Ai\Exceptions\ConfigException;
use Ai\Exceptions\ProcessException;

/**
 * Claude Code CLI 程序调用封装
 *
 * 直接调用本机安装的 claude 可执行程序（Claude Code CLI），而非 Anthropic HTTP API。
 * 典型场景：在代码编辑器中让 AI 直接读/写工作区文件、执行工具调用。
 *
 * 默认参数参考生产环境实践（--print 非交互 + stream-json 流式 + 仅文件类工具 + acceptEdits）：
 *     claude --print --output-format stream-json --verbose
 *            --allowedTools "Read Edit Write Grep Glob" --disallowedTools "Bash"
 *            --permission-mode acceptEdits [--resume <会话ID>] < prompt.txt
 *
 * 主要能力：
 *  - 可执行文件路径自动检测 + 缓存（手动设置优先级最高）
 *  - 默认 CLI 参数 + 用户自定义/覆盖/删除参数
 *  - 会话续接（--resume）与 session_id 自动回写
 *  - stream-json 逐事件回调（转发给 SSE 或自行渲染）
 *  - 本地 proc_open 执行，也可注入自定义执行器（如经 SSH/SFTP 远程执行）
 *
 * 用法：
 * ```php
 * $cli = ClaudeCode::create(['workdir' => '/var/www']);
 * $res = $cli->chat('帮我检查这个文件的语法');
 * echo $res->getContent();       // 最终文本
 * echo $res->getSessionId();     // 会话 ID，下轮传回即可续接
 * $cli->setSessionId($res->getSessionId()); // 自动续接
 * ```
 */
class ClaudeCode
{
    /** 进程内缓存：自动检测到的 claude 路径（PHP 请求生命周期内有效） */
    protected static $binaryCache = '';

    /** 默认 CLI 参数（用户未覆盖时生效）。值 true 表示布尔 flag，数组/字符串为带值 flag */
    protected static $defaultFlags = [
        'output-format'   => 'stream-json',
        'verbose'         => true,
        'allowedTools'    => ['Read', 'Edit', 'Write', 'Grep', 'Glob'],
        'disallowedTools' => ['Bash'],
        'permission-mode' => 'acceptEdits',
    ];

    /** @var string 手动指定的 claude 可执行文件路径（最高优先级） */
    protected $binary = '';

    /** @var bool 是否启用自动检测结果的文件缓存 */
    protected $binaryCacheEnabled = true;

    /** @var string 缓存文件路径（默认 sys_get_temp_dir()/ai_claude_binary_cache.json） */
    protected $binaryCachePath = '';

    /** @var int 缓存有效期（秒），过期后重新检测 */
    protected $binaryCacheTtl = 86400;

    /** @var string 默认工作目录（未设置时用当前 PHP 进程 cwd） */
    protected $workdir = '';

    /** @var array 额外环境变量（本地执行时传给 proc_open） */
    protected $env = [];

    /** @var bool 本地执行时自动把 claude 所在目录（如 nvm node bin）加入 PATH */
    protected $autoNvmPath = true;

    /** @var string 命令前缀（如 export PATH=...; cd ... &&），自定义执行器场景常用 */
    protected $shellPrefix = '';

    /** @var int 执行超时秒数（0 表示不限制） */
    protected $timeout = 0;

    /** @var string 会话 ID，非空时自动追加 --resume */
    protected $sessionId = '';

    /** @var string 模型名（对应 --model），空则不传 */
    protected $model = '';

    /** @var string 提示词临时文件目录（默认 sys_get_temp_dir()） */
    protected $promptDir = '';

    /** @var array 用户覆盖的 CLI 参数 */
    protected $flags = [];

    /** @var array 需要删除的默认 CLI 参数名 */
    protected $removedFlags = [];

    /** @var callable|null 自定义执行器 function(string $cmd, callable $onChunk): int */
    protected $runner = null;

    /** @var callable|null 事件回调（构造时配置的默认 onEvent） */
    protected $onEvent = null;

    /** @var bool 是否已执行过进程内二进制探测（避免 getBinary 重复抛异常） */
    protected $binaryResolved = false;

    /**
     * @param array $config 支持键：
     *                      binary、binary_cache、binary_cache_path、binary_cache_ttl、
     *                      workdir、env、auto_nvm_path、shell、timeout、session_id、
     *                      model、prompt_dir、flags、runner、on_event
     */
    public function __construct(array $config = [])
    {
        foreach ([
            'binary'             => 'binary',
            'binary_cache'       => 'binaryCacheEnabled',
            'binary_cache_path'  => 'binaryCachePath',
            'binary_cache_ttl'   => 'binaryCacheTtl',
            'workdir'            => 'workdir',
            'env'                => 'env',
            'auto_nvm_path'      => 'autoNvmPath',
            'shell'              => 'shellPrefix',
            'timeout'            => 'timeout',
            'session_id'         => 'sessionId',
            'model'              => 'model',
            'prompt_dir'         => 'promptDir',
            'flags'              => 'flags',
            'runner'             => 'runner',
            'on_event'           => 'onEvent',
        ] as $key => $prop) {
            if (isset($config[$key])) {
                $this->{$prop} = $config[$key];
            }
        }
    }

    /**
     * 快捷构造
     */
    public static function create(array $config = []): self
    {
        return new static($config);
    }

    // ---------------------------------------------------------------------
    // 可执行文件路径：手动设置 / 自动检测 / 缓存
    // ---------------------------------------------------------------------

    /**
     * 手动指定 claude 可执行文件路径（优先级最高，跳过自动检测）
     */
    public function setBinary(string $path): self
    {
        $this->binary = trim($path);
        $this->binaryResolved = $this->binary !== '';
        return $this;
    }

    /**
     * 获取 claude 可执行文件路径
     * 优先手动设置 → 进程内缓存 → 文件缓存 → 自动探测；未找到抛 ConfigException
     */
    public function getBinary(): string
    {
        if ($this->binaryResolved) {
            return $this->binary;
        }
        if ($this->binary !== '') {
            $this->binaryResolved = true;
            return $this->binary;
        }
        $this->binary = $this->detectBinary();
        $this->binaryResolved = true;
        return $this->binary;
    }

    /**
     * 自动检测 claude 路径（含缓存读取/写入）。
     * 业务层一般无需直接调用，getBinary() 内部自动完成。
     */
    public function detectBinary(): string
    {
        if (self::$binaryCache !== '') {
            return self::$binaryCache;
        }
        if ($this->binaryCacheEnabled) {
            $cached = $this->readBinaryCache();
            if ($cached !== '') {
                self::$binaryCache = $cached;
                return $cached;
            }
        }
        $found = $this->probeBinary();
        if ($found === '') {
            throw new ConfigException(
                '未找到 claude 可执行文件。请确认已安装 Claude Code CLI，'
                . '或通过 setBinary() / config["binary"] 手动指定路径。'
            );
        }
        self::$binaryCache = $found;
        if ($this->binaryCacheEnabled) {
            $this->writeBinaryCache($found);
        }
        return $found;
    }

    /**
     * 设置是否启用自动检测结果的文件缓存（默认 true）。
     * 关闭后仅保留进程内缓存，PHP-FPM 下每次请求都会重新探测。
     */
    public function setBinaryCacheEnabled(bool $enabled): self
    {
        $this->binaryCacheEnabled = $enabled;
        return $this;
    }

    /**
     * 设置二进制缓存文件路径（默认 sys_get_temp_dir()/ai_claude_binary_cache.json）
     */
    public function setBinaryCachePath(string $path): self
    {
        $this->binaryCachePath = trim($path);
        return $this;
    }

    /**
     * 设置缓存有效期秒数（默认 86400，过期后重新探测）
     */
    public function setBinaryCacheTtl(int $seconds): self
    {
        $this->binaryCacheTtl = max(0, $seconds);
        return $this;
    }

    /**
     * 清除已缓存路径（进程内 + 文件），下次 getBinary() 会重新探测
     */
    public function clearBinaryCache(): self
    {
        self::$binaryCache = '';
        $this->binaryResolved = false;
        $this->binary = '';
        if ($this->binaryCacheEnabled) {
            $path = $this->resolveCachePath();
            if (is_file($path)) {
                @unlink($path);
            }
        }
        return $this;
    }

    /**
     * 探测本机 claude 安装位置。
     * 顺序：PATH 的 command -v → 常见安装路径 → nvm 最新 node bin → 登录 shell 的 command -v
     */
    protected function probeBinary(): string
    {
        // PATH 已含 claude 时最快命中（原生安装 ~/.local/bin、Homebrew、npm 全局等）
        $found = $this->locateViaCommand(false);
        if ($found !== '') {
            return $found;
        }

        $home = getenv('HOME');
        $home = is_string($home) ? $home : '';

        $candidates = [];
        if ($home !== '') {
            $candidates[] = $home . '/.local/bin/claude';
            $candidates[] = $home . '/bin/claude';
        }
        $candidates[] = '/usr/local/bin/claude';
        $candidates[] = '/usr/bin/claude';
        $candidates[] = '/opt/homebrew/bin/claude';
        $candidates[] = '/opt/local/bin/claude';
        $candidates[] = '/snap/bin/claude';

        // nvm 下按 node 版本目录探测，取版本号最大的（sort -V 语义）
        if ($home !== '') {
            $nvmDirs = glob($home . '/.nvm/versions/node/*/bin');
            if (is_array($nvmDirs)) {
                usort($nvmDirs, function ($a, $b) {
                    return version_compare(basename($a), basename($b));
                });
                for ($i = count($nvmDirs) - 1; $i >= 0; $i--) {
                    $candidates[] = $nvmDirs[$i] . '/claude';
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        // 兜底：登录 shell 里 command -v（nvm 环境变量生效）
        $found = $this->locateViaCommand(true);
        if ($found !== '') {
            return $found;
        }

        return '';
    }

    /**
     * 经 shell 的 command -v 定位 claude（覆盖 PATH / nvm 注入的场景）
     * @param bool $loginShell 是否使用登录 shell（bash -lc，能加载 nvm 等初始化脚本）
     */
    protected function locateViaCommand(bool $loginShell = false): string
    {
        $queries = $loginShell
            ? ['bash -lc "command -v claude" 2>/dev/null']
            : ['sh -c "command -v claude" 2>/dev/null'];
        foreach ($queries as $query) {
            if (!function_exists('shell_exec')) {
                break;
            }
            $out = @shell_exec($query);
            if (!is_string($out)) {
                continue;
            }
            $out = trim($out);
            if ($out !== '' && strpos($out, "\n") === false && is_file($out) && is_executable($out)) {
                return $out;
            }
        }
        return '';
    }

    /**
     * 缓存文件绝对路径
     */
    protected function resolveCachePath(): string
    {
        if ($this->binaryCachePath !== '') {
            return $this->binaryCachePath;
        }
        return rtrim(sys_get_temp_dir(), '/\\') . '/ai_claude_binary_cache.json';
    }

    /**
     * 读取文件缓存，未命中或过期返回空串
     */
    protected function readBinaryCache(): string
    {
        $path = $this->resolveCachePath();
        if (!is_file($path)) {
            return '';
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            return '';
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['path'])) {
            return '';
        }
        if ($this->binaryCacheTtl > 0) {
            $foundAt = (int) ($data['found_at'] ?? 0);
            if ($foundAt > 0 && (time() - $foundAt) > $this->binaryCacheTtl) {
                return '';
            }
        }
        if (!is_file($data['path']) || !is_executable($data['path'])) {
            return '';
        }
        return $data['path'];
    }

    /**
     * 写入文件缓存
     */
    protected function writeBinaryCache(string $path): void
    {
        $data = json_encode([
            'path'     => $path,
            'found_at' => time(),
        ]);
        if ($data === false) {
            return;
        }
        $file = $this->resolveCachePath();
        @file_put_contents($file, $data, LOCK_EX);
    }

    // ---------------------------------------------------------------------
    // CLI 参数：默认参数 + 用户自定义
    // ---------------------------------------------------------------------

    /**
     * 设置单个 CLI 参数（覆盖默认值；false/null 表示不传）
     * @param mixed $value true=布尔 flag；数组/字符串=带值 flag
     */
    public function setFlag(string $name, $value): self
    {
        $name = $this->normalizeFlagName($name);
        $this->flags[$name] = $value;
        unset($this->removedFlags[$name]);
        return $this;
    }

    /**
     * 批量设置 CLI 参数
     */
    public function setFlags(array $flags): self
    {
        foreach ($flags as $name => $value) {
            $this->setFlag($name, $value);
        }
        return $this;
    }

    /**
     * 删除某个参数（含默认参数），如 removeFlag('verbose')
     */
    public function removeFlag(string $name): self
    {
        $name = $this->normalizeFlagName($name);
        unset($this->flags[$name]);
        $this->removedFlags[$name] = true;
        return $this;
    }

    /**
     * 恢复全部参数为默认值
     */
    public function resetFlags(): self
    {
        $this->flags = [];
        $this->removedFlags = [];
        return $this;
    }

    /**
     * 获取最终生效的参数（默认 + 用户覆盖 - 已删除）
     */
    public function getFlags(): array
    {
        $flags = self::$defaultFlags;
        foreach ($this->flags as $name => $value) {
            $flags[$name] = $value;
        }
        foreach ($this->removedFlags as $name => $_) {
            unset($flags[$name]);
        }
        return $flags;
    }

    /**
     * 参数名归一化：允许下划线写法（permission_mode → permission-mode）。
     * Claude Code CLI 参数统一为 kebab-case，原样保留已有连字符。
     */
    protected function normalizeFlagName(string $name): string
    {
        $name = trim($name);
        $kebab = str_replace('_', '-', $name);
        return $kebab !== '' ? $kebab : $name;
    }

    /**
     * 单个参数渲染为命令行片段
     */
    protected function renderFlag(string $name, $value): string
    {
        if ($value === true) {
            return ' --' . $name;
        }
        if ($value === false || $value === null || $value === '') {
            return '';
        }
        if (is_array($value)) {
            $value = implode(' ', array_filter(array_map('strval', $value), 'strlen'));
        }
        return ' --' . $name . ' ' . escapeshellarg((string) $value);
    }

    /**
     * 渲染全部参数（含选项级 model / flags 注入）
     */
    protected function renderFlags(array $options): string
    {
        $flags = $this->getFlags();

        $model = (string) ($options['model'] ?? $this->model);
        if ($model !== '') {
            $flags['model'] = $model;
        }

        if (isset($options['flags']) && is_array($options['flags'])) {
            foreach ($options['flags'] as $name => $value) {
                $flags[$this->normalizeFlagName($name)] = $value;
            }
        }

        $out = '';
        foreach ($flags as $name => $value) {
            $out .= $this->renderFlag($name, $value);
        }
        return $out;
    }

    // ---------------------------------------------------------------------
    // 运行环境
    // ---------------------------------------------------------------------

    /**
     * 设置默认工作目录（对应 cd {dir} && claude ...）
     */
    public function setWorkdir(string $dir): self
    {
        $this->workdir = trim($dir);
        return $this;
    }

    /**
     * 追加环境变量（本地执行时传给 proc_open）
     */
    public function setEnv(array $env): self
    {
        $this->env = array_merge($this->env, $env);
        return $this;
    }

    /**
     * 是否自动把 claude 所在目录（如 nvm node bin）加入 PATH（默认 true，仅本地执行生效）
     */
    public function setAutoNvmPath(bool $enabled): self
    {
        $this->autoNvmPath = $enabled;
        return $this;
    }

    /**
     * 设置命令前缀，如 "export LANG=en_US.UTF-8; cd /data && "
     * 自定义执行器（SSH/SFTP）场景用它注入 PATH/LANG 等环境
     */
    public function setShellPrefix(string $prefix): self
    {
        $this->shellPrefix = trim($prefix);
        return $this;
    }

    /**
     * 设置执行超时秒数（0=不限制）
     */
    public function setTimeout(int $seconds): self
    {
        $this->timeout = max(0, $seconds);
        return $this;
    }

    /**
     * 设置提示词临时文件目录（容器与宿主机共享挂载时需指向双方可见路径）
     */
    public function setPromptDir(string $dir): self
    {
        $this->promptDir = trim($dir);
        return $this;
    }

    /**
     * 注入自定义执行器：function(string $cmd, callable $onChunk): int
     * $onChunk(string $chunk, string $type)，$type 为 'out'|'err'。
     * 返回进程退出码。未设置时使用本地 proc_open。
     */
    public function setRunner(callable $runner): self
    {
        $this->runner = $runner;
        return $this;
    }

    // ---------------------------------------------------------------------
    // 会话续接
    // ---------------------------------------------------------------------

    /**
     * 设置会话 ID，非空时自动追加 --resume <id>
     */
    public function setSessionId(?string $sessionId): self
    {
        $this->sessionId = (string) $sessionId;
        return $this;
    }

    /**
     * 获取当前会话 ID（每次执行后自动从输出回写）
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * 设置默认模型（对应 --model，空则不传）
     */
    public function setModel(string $model): self
    {
        $this->model = trim($model);
        return $this;
    }

    // ---------------------------------------------------------------------
    // 执行
    // ---------------------------------------------------------------------

    /**
     * 非流式调用：执行一轮 claude，返回最终结果。
     *
     * @param string $prompt  用户提示词
     * @param array  $options 可选：session_id（覆盖续接）、reset（清空会话）、
     *                        workdir、model、flags（临时参数）、env、timeout
     */
    public function chat(string $prompt, array $options = []): ClaudeCodeResponse
    {
        return $this->run($prompt, $options);
    }

    /**
     * run 的别名（与 chat 等价）
     */
    public function run(string $prompt, array $options = []): ClaudeCodeResponse
    {
        return $this->runStream($prompt, null, $options);
    }

    /**
     * 流式调用：逐事件回调 + 返回最终汇总结果。
     *
     * $onEvent(string $event, mixed $data)：
     *  - 'start'    ['resume' => bool] 是否续接会话
     *  - 'message'  原始 stream-json 事件数组（assistant/user/result/... 原样透传）
     *  - 'stderr'   stderr 文本块
     *  - 'line'     非 JSON 的 stdout 原始行
     *  - 'result'   汇总数组 ['content','session_id','model','usage','total_cost_usd',
     *                          'num_turns','duration_ms','exit_code']
     *  - 'done'     null
     *
     * @param string   $prompt  用户提示词
     * @param callable|null $onEvent 事件回调；为空时仅返回结果对象
     * @param array    $options 同 chat()
     */
    public function runStream(string $prompt, $onEvent = null, array $options = []): ClaudeCodeResponse
    {
        $emit = $onEvent ?: $this->onEvent;
        $emit = is_callable($emit) ? $emit : function () {
        };

        // 会话解析：options.session_id 优先，其次实例默认；reset 强制开新会话
        $sessionId = isset($options['session_id']) ? (string) $options['session_id'] : $this->sessionId;
        if (!empty($options['reset'])) {
            $sessionId = '';
        }
        $options['session_id'] = $sessionId;

        $binary = $this->getBinary();
        $workdir = (string) ($options['workdir'] ?? $this->workdir);

        // 提示词写入临时文件（命令经 stdin 重定向喂给 claude，规避命令行长度限制）
        $promptFile = $this->writePromptFile($prompt);
        $cmd = $this->buildCommand($binary, $promptFile, $workdir, $options);

        $emit('start', ['resume' => ($sessionId !== '')]);

        $collect = [
            'session_id' => $sessionId,
            'model'      => '',
            'usage'      => [],
            'cost'       => 0.0,
            'num_turns'  => 0,
            'duration'   => 0,
            'result_text'=> '',
            'asst_text'  => '',
            'exit_code'  => -1,
            'is_error'   => false,
        ];

        $lineBuf = '';
        $onChunk = function ($chunk, $type) use (&$collect, &$lineBuf, $emit) {
            if ($type === 'err') {
                $emit('stderr', $chunk);
                return;
            }
            $lineBuf .= $chunk;
            while (($pos = strpos($lineBuf, "\n")) !== false) {
                $line = substr($lineBuf, 0, $pos);
                $lineBuf = substr($lineBuf, $pos + 1);
                $this->handleLine(trim($line), $collect, $emit);
            }
        };

        $startedAt = microtime(true);
        try {
            $exitCode = $this->execute($cmd, $onChunk);
            if (trim($lineBuf) !== '') {
                $this->handleLine(trim($lineBuf), $collect, $emit);
            }
        } catch (ProcessException $e) {
            throw $e;
        } finally {
            @unlink($promptFile);
        }
        $collect['exit_code'] = (int) $exitCode;
        $collect['duration']  = (int) ((microtime(true) - $startedAt) * 1000);

        // 回写会话 ID（未开新会话且输出给出新 id 时）
        if ($collect['session_id'] !== '' && $collect['session_id'] !== $sessionId) {
            $this->sessionId = $collect['session_id'];
        }

        $result = [
            'content'      => $collect['result_text'] !== '' ? $collect['result_text'] : $collect['asst_text'],
            'model'        => $collect['model'],
            'usage'        => $collect['usage'],
            'success'      => !$collect['is_error'],
            'session_id'   => $collect['session_id'],
            'cost_usd'     => $collect['cost'],
            'num_turns'    => $collect['num_turns'],
            'duration_ms'  => $collect['duration'],
            'exit_code'    => $collect['exit_code'],
            'command'      => $cmd,
            'raw'          => [],
        ];

        $emit('result', $result);
        $emit('done', null);

        return new ClaudeCodeResponse($result);
    }

    /**
     * 处理一行 stream-json 事件（或非 JSON 原始行）
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
        $emit('message', $ev);

        // 会话 ID 可能出现在任意事件上（通常 result 事件携带）
        if (isset($ev['session_id']) && is_string($ev['session_id']) && $ev['session_id'] !== '') {
            $collect['session_id'] = $ev['session_id'];
        }

        if (($ev['type'] ?? '') === 'assistant') {
            if (isset($ev['message']['model']) && is_string($ev['message']['model'])) {
                $collect['model'] = $ev['message']['model'];
            }
            if (isset($ev['message']['content']) && is_array($ev['message']['content'])) {
                foreach ($ev['message']['content'] as $block) {
                    if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                        $collect['asst_text'] .= (string) $block['text'];
                    }
                }
            }
        }

        if (($ev['type'] ?? '') === 'result') {
            if (isset($ev['result']) && is_string($ev['result'])) {
                $collect['result_text'] = $ev['result'];
            }
            if (isset($ev['usage']) && is_array($ev['usage'])) {
                $collect['usage'] = $ev['usage'];
            }
            if (isset($ev['total_cost_usd'])) {
                $collect['cost'] = (float) $ev['total_cost_usd'];
            }
            if (isset($ev['num_turns'])) {
                $collect['num_turns'] = (int) $ev['num_turns'];
            }
            if (isset($ev['duration_ms'])) {
                $collect['duration'] = (int) $ev['duration_ms'];
            }
            if (isset($ev['is_error'])) {
                $collect['is_error'] = (bool) $ev['is_error'];
            }
        }
    }

    /**
     * 解析一行 stream-json 输出为事件数组；非 JSON 或空行返回 null
     */
    public static function parseEventLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }
        $ev = json_decode($line, true);
        if (!is_array($ev) || !isset($ev['type'])) {
            return null;
        }
        return $ev;
    }

    /**
     * 组装最终命令：{shellPrefix} {binary} --print {flags} [--resume id] < promptFile
     */
    public function buildCommand(string $binary, string $promptFile, string $workdir = '', array $options = []): string
    {
        $cmd = $binary . ' --print' . $this->renderFlags($options);

        $sessionId = isset($options['session_id']) ? (string) $options['session_id'] : $this->sessionId;
        if (!empty($options['reset'])) {
            $sessionId = '';
        }
        if ($sessionId !== '') {
            $cmd .= ' --resume ' . escapeshellarg($sessionId);
        }

        $cmd .= ' < ' . escapeshellarg($promptFile);

        if ($workdir !== '') {
            $cmd = 'cd ' . escapeshellarg($workdir) . ' && ' . $cmd;
        }
        if ($this->shellPrefix !== '') {
            $cmd = $this->shellPrefix . ' ' . $cmd;
        }
        return $cmd;
    }

    /**
     * 写入提示词临时文件，返回文件绝对路径
     */
    protected function writePromptFile(string $prompt): string
    {
        $dir = $this->promptDir !== '' ? $this->promptDir : sys_get_temp_dir();
        $file = rtrim($dir, '/\\') . '/ai_claude_prompt_' . getmypid() . '_' . mt_rand(10000, 99999) . '.txt';
        if (@file_put_contents($file, $prompt) === false) {
            throw new ProcessException('无法写入提示词临时文件: ' . $file);
        }
        return $file;
    }

    /**
     * 执行命令：优先自定义执行器，否则本地 proc_open
     */
    protected function execute(string $cmd, callable $onChunk): int
    {
        if ($this->runner !== null) {
            $exit = call_user_func($this->runner, $cmd, $onChunk);
            return (int) $exit;
        }
        return $this->runLocal($cmd, $onChunk);
    }

    /**
     * 本地 proc_open 执行：非阻塞读取 stdout/stderr，按行转发给回调
     */
    protected function runLocal(string $cmd, callable $onChunk): int
    {
        if (!function_exists('proc_open')) {
            throw new ProcessException('当前 PHP 环境未启用 proc_open，无法本地执行 claude');
        }
        $env = $this->buildLocalEnv();

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes, $this->workdir !== '' ? $this->workdir : null, $env);
        if (!is_resource($proc)) {
            throw new ProcessException('无法启动 claude 进程: ' . $cmd);
        }

        // 提示词经文件重定向输入，stdin 直接关闭
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $started = microtime(true);
        $exitCode = -1;
        while (true) {
            $out = @fread($pipes[1], 8192);
            if ($out !== '' && $out !== false) {
                $onChunk($out, 'out');
            }
            $err = @fread($pipes[2], 8192);
            if ($err !== '' && $err !== false) {
                $onChunk($err, 'err');
            }
            $status = @proc_get_status($proc);
            if ($status === false || !$status['running']) {
                $exitCode = is_array($status) ? (int) $status['exitcode'] : -1;
                break;
            }
            if ($this->timeout > 0 && (microtime(true) - $started) > $this->timeout) {
                @proc_terminate($proc);
                throw new ProcessException('claude 执行超时（' . $this->timeout . 's）');
            }
            usleep(20000);
        }

        // 排空剩余输出
        while (($out = @fread($pipes[1], 8192)) !== false && $out !== '') {
            $onChunk($out, 'out');
        }
        while (($err = @fread($pipes[2], 8192)) !== false && $err !== '') {
            $onChunk($err, 'err');
        }
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        @proc_close($proc);
        return $exitCode;
    }

    /**
     * 构建本地执行环境变量（自动把 nvm 下 claude 所在目录加入 PATH）
     */
    protected function buildLocalEnv(): array
    {
        $env = $this->env;
        if ($this->autoNvmPath) {
            $binary = $this->getBinary();
            if ($binary !== '' && strpos($binary, '/.nvm/') !== false) {
                $nodeBin = dirname($binary);
                $path = isset($env['PATH']) ? (string) $env['PATH'] : (string) (getenv('PATH') ?: '');
                if ($path === '') {
                    $env['PATH'] = $nodeBin;
                } elseif (strpos($path, $nodeBin) === false) {
                    $env['PATH'] = $nodeBin . PATH_SEPARATOR . $path;
                }
            }
        }
        return $env;
    }
}
