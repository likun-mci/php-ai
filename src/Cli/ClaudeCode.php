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
 * 默认参数（--print 非交互 + stream-json 流式 + acceptEdits）：
 *     claude --print --output-format stream-json --verbose
 *            --setting-sources user,project,local --no-chrome
 *            --allowedTools "Read Edit Write Grep Glob" --disallowedTools "Bash"
 *            --permission-mode acceptEdits [--resume <会话ID>] < prompt.txt
 *
 * 其中 --setting-sources 与 --no-chrome 对齐官方 IDE 插件的启动参数：
 * print 模式下 CLI 默认不加载全部设置源，不显式指定会导致项目 CLAUDE.md、
 * .claude/settings.json 的权限规则、自定义 agent/skill 全部失效。
 *
 * 注意 --allowedTools / --disallowedTools 是"免权限提示白名单 / 拒绝名单"，
 * 不是可用工具集合。要真正限制模型能看到哪些工具用 setTools()（--tools）。
 *
 * 主要能力：
 *  - 可执行文件路径自动检测 + 缓存（手动设置优先级最高）
 *  - 默认 CLI 参数 + 用户自定义/覆盖/删除参数，全部 CLI 选项有对应 setter
 *  - 会话续接（--resume / --continue / --fork-session）与 session_id 自动回写
 *  - stream-json 逐事件回调，事件已按 IDE 插件的语义细分
 *    （init/text/thinking/tool_use/tool_result/rate_limit/...）
 *  - 结构化输出（--json-schema）、预算上限（--max-budget-usd）、
 *    降级模型（--fallback-model）、思考预算（--max-thinking-tokens）
 *  - 本地 proc_open 执行，也可注入自定义执行器（如经 SSH/SFTP 远程执行）
 *
 * 需要"一个进程内多轮对话 + 工具权限实时回调 + 中断"时，改用 ClaudeCodeSession，
 * 那是 IDE 插件采用的双工（--input-format stream-json）工作模式。
 *
 * 用法：
 * ```php
 * $cli = ClaudeCode::create(['workdir' => '/var/www']);
 * $res = $cli->chat('帮我检查这个文件的语法');
 * echo $res->getContent();       // 最终文本
 * echo $res->getSessionId();     // 会话 ID，下轮传回即可续接
 * $cli->setSessionId($res->getSessionId()); // 自动续接
 * ```
 *
 * @see ClaudeCodeSession 常驻双工会话（多轮 / 权限回调 / 中断）
 */
class ClaudeCode
{
    /** 进程内缓存：自动检测到的 claude 路径（PHP 请求生命周期内有效）
     * @var string
     */
    protected static $binaryCache = '';

    /** 默认 CLI 参数（用户未覆盖时生效）。值 true 表示布尔 flag，数组/字符串为带值 flag
     * @var array<string, mixed>
     */
    protected static $defaultFlags = [
        'output-format'   => 'stream-json',
        'verbose'         => true,
        'setting-sources' => 'user,project,local',
        'no-chrome'       => true,
        'allowedTools'    => ['Read', 'Edit', 'Write', 'Grep', 'Glob'],
        'disallowedTools' => ['Bash'],
        'permission-mode' => 'acceptEdits',
    ];

    /**
     * 多值参数的渲染方式（与 CLI 的参数解析规则对应）：
     *  comma    → --flag 'a,b'         （逗号分隔单值）
     *  variadic → --flag 'a' 'b'       （一个 flag 后跟多个值）
     *  repeat   → --flag 'a' --flag 'b'（flag 本身重复）
     * 未列出的数组值按 --flag 'a b'（空格拼成单值）渲染。
     * @var array<string, string>
     */
    protected static $arrayFlagStyles = [
        'setting-sources' => 'comma',
        'fallback-model'  => 'comma',
        'tools'           => 'comma',
        'add-dir'         => 'variadic',
        'mcp-config'      => 'variadic',
        'betas'           => 'variadic',
        'file'            => 'variadic',
        'plugin-dir'      => 'repeat',
        'plugin-url'      => 'repeat',
    ];

    /** --permission-mode 合法取值
     * @var string[]
     */
    protected static $permissionModes = [
        'acceptEdits', 'auto', 'bypassPermissions', 'manual', 'dontAsk', 'plan',
    ];

    /** --effort 合法取值
     * @var string[]
     */
    protected static $effortLevels = ['low', 'medium', 'high', 'xhigh', 'max'];

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

    /** @var array<string, string> 额外环境变量（本地执行时传给 proc_open） */
    protected $env = [];

    /** @var bool 子进程是否继承当前进程的环境变量（setEnv 的内容叠加在其上） */
    protected $inheritEnv = true;

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

    /** @var array<string, mixed> 用户覆盖的 CLI 参数 */
    protected $flags = [];

    /** @var array<string, bool> 需要删除的默认 CLI 参数名 */
    protected $removedFlags = [];

    /** @var callable|null 自定义执行器 function(string $cmd, callable $onChunk): int */
    protected $runner = null;

    /** @var callable|null 事件回调（构造时配置的默认 onEvent） */
    protected $onEvent = null;

    /** @var callable|null 自定义等待实现 function(float $seconds): void（协程环境用） */
    protected $sleeper = null;

    /** @var bool 命令中是否给 claude 加 exec 前缀（让它取代中间的 sh 进程） */
    protected $execReplace = true;

    /** @var int SIGTERM 之后等待进程自行退出的秒数，超时改发 SIGKILL */
    protected $killGrace = 2;

    /** @var bool 是否已执行过进程内二进制探测（避免 getBinary 重复抛异常） */
    protected $binaryResolved = false;

    /** @var string CLI 版本号缓存 */
    protected $version = '';

    /** @var array<string, mixed>|null 模型列表缓存（null 表示尚未查询） */
    protected $modelsCache = null;

    /**
     * @param array<mixed> $config 支持键：
     *                      binary、binary_cache、binary_cache_path、binary_cache_ttl、
     *                      workdir、env、inherit_env、auto_nvm_path、shell、timeout、
     *                      session_id、model、prompt_dir、flags、runner、on_event、
     *                      sleeper、exec_replace、kill_grace
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
            'inherit_env'        => 'inheritEnv',
            'auto_nvm_path'      => 'autoNvmPath',
            'shell'              => 'shellPrefix',
            'timeout'            => 'timeout',
            'session_id'         => 'sessionId',
            'model'              => 'model',
            'prompt_dir'         => 'promptDir',
            'flags'              => 'flags',
            'runner'             => 'runner',
            'on_event'           => 'onEvent',
            'sleeper'            => 'sleeper',
            'exec_replace'       => 'execReplace',
            'kill_grace'         => 'killGrace',
        ] as $key => $prop) {
            if (isset($config[$key])) {
                $this->{$prop} = $config[$key];
            }
        }
    }

    /**
     * 快捷构造
     *
     * @return static
     * @param array<mixed> $config
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
     * @param array<mixed> $flags
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
     * @return array<mixed>
     */
    public function getFlags(): array
    {
        $flags = static::$defaultFlags;
        foreach ($this->flags as $name => $value) {
            $flags[$name] = $value;
        }
        foreach ($this->removedFlags as $name => $_) {
            unset($flags[$name]);
        }
        return $flags;
    }

    // ---------------------------------------------------------------------
    // 常用参数快捷设置（等价于 setFlag()，仅提供更明确的签名与取值校验）
    // ---------------------------------------------------------------------

    /**
     * 权限模式：acceptEdits（默认，自动接受文件编辑）、auto（智能判定，IDE 插件默认）、
     * bypassPermissions（全放行）、manual（逐次询问）、dontAsk、plan（只规划不改动）
     */
    public function setPermissionMode(string $mode): self
    {
        if (!in_array($mode, static::$permissionModes, true)) {
            throw new ConfigException(
                '不支持的 permission-mode: ' . $mode . '，可选：' . implode(' / ', static::$permissionModes)
            );
        }
        return $this->setFlag('permission-mode', $mode);
    }

    /**
     * 免权限提示的工具白名单（--allowedTools），支持 "Bash(git *)" 这类细粒度写法
     * @param array<mixed> $tools
     */
    public function setAllowedTools($tools): self
    {
        return $this->setFlag('allowedTools', $tools);
    }

    /**
     * 拒绝使用的工具名单（--disallowedTools）
     * @param array<mixed> $tools
     */
    public function setDisallowedTools($tools): self
    {
        return $this->setFlag('disallowedTools', $tools);
    }

    /**
     * 限定模型可用的内置工具集合（--tools）。
     * 传空数组/空串禁用全部工具，传 'default' 使用全部工具。
     * 与 setAllowedTools() 的区别：这里决定"有哪些工具"，那里决定"哪些不用问"。
     * @param array<mixed> $tools 如 ['Read','Edit','Grep'] 或 'default'
     */
    public function setTools($tools): self
    {
        if (is_array($tools) && !$tools) {
            return $this->setFlag('tools', '');
        }
        return $this->setFlag('tools', $tools);
    }

    /**
     * 权限询问交给外部工具处理（--permission-prompt-tool），
     * IDE 插件用 'stdio' 走标准输入输出的 control_request 协议。
     * 一次性模式下无回路可用，通常只在 ClaudeCodeSession 中设置。
     */
    public function setPermissionPromptTool(string $tool): self
    {
        return $this->setFlag('permission-prompt-tool', $tool);
    }

    /**
     * 跳过全部权限检查（--dangerously-skip-permissions）。
     * 仅建议在无外网的沙箱中使用。
     */
    public function setSkipPermissions(bool $enabled = true): self
    {
        return $enabled ? $this->setFlag('dangerously-skip-permissions', true)
                        : $this->removeFlag('dangerously-skip-permissions');
    }

    /**
     * 额外授权可访问的目录（--add-dir），工作目录之外的路径需在此声明
     * @param array<mixed> $dirs
     */
    public function setAddDirs($dirs): self
    {
        return $this->setFlag('add-dir', is_array($dirs) ? $dirs : [$dirs]);
    }

    /**
     * 追加一个额外授权目录
     */
    public function addDir(string $dir): self
    {
        $flags = $this->getFlags();
        $dirs = isset($flags['add-dir']) ? (array) $flags['add-dir'] : [];
        $dirs[] = $dir;
        return $this->setFlag('add-dir', array_values(array_unique($dirs)));
    }

    /**
     * 思考预算 token 上限（--max-thinking-tokens）。
     * IDE 插件使用 31999；默认不传（由 CLI 自行决定），调高会显著增加成本。
     */
    public function setThinkingTokens(int $tokens): self
    {
        return $tokens > 0 ? $this->setFlag('max-thinking-tokens', $tokens)
                           : $this->removeFlag('max-thinking-tokens');
    }

    /**
     * 推理投入档位（--effort）：low / medium / high / xhigh / max
     */
    public function setEffort(string $level): self
    {
        if (!in_array($level, static::$effortLevels, true)) {
            throw new ConfigException(
                '不支持的 effort: ' . $level . '，可选：' . implode(' / ', static::$effortLevels)
            );
        }
        return $this->setFlag('effort', $level);
    }

    /**
     * 主模型过载/不可用时的降级模型（--fallback-model），按顺序尝试
     * @param array<mixed> $models
     */
    public function setFallbackModel($models): self
    {
        return $this->setFlag('fallback-model', $models);
    }

    /**
     * 本次调用的花费上限（--max-budget-usd），超出即终止。无人值守场景强烈建议设置。
     */
    public function setMaxBudgetUsd(float $amount): self
    {
        return $amount > 0 ? $this->setFlag('max-budget-usd', $amount)
                           : $this->removeFlag('max-budget-usd');
    }

    /**
     * 结构化输出（--json-schema）：约束最终结果符合给定 JSON Schema，
     * 结果可用 ClaudeCodeResponse::getStructured() 取回数组。
     * @param array<mixed> $schema 数组会自动 json_encode
     */
    public function setJsonSchema($schema): self
    {
        if (is_array($schema)) {
            $schema = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($schema === false) {
                throw new ConfigException('json-schema 序列化失败');
            }
        }
        return $this->setFlag('json-schema', (string) $schema);
    }

    /**
     * 替换默认系统提示词（--system-prompt）
     */
    public function setSystemPrompt(string $prompt): self
    {
        return $this->setFlag('system-prompt', $prompt);
    }

    /**
     * 在默认系统提示词后追加内容（--append-system-prompt）
     */
    public function appendSystemPrompt(string $prompt): self
    {
        return $this->setFlag('append-system-prompt', $prompt);
    }

    /**
     * 指定本次会话使用的 agent（--agent）
     */
    public function setAgent(string $agent): self
    {
        return $this->setFlag('agent', $agent);
    }

    /**
     * 定义临时自定义 agent（--agents），如
     * ['reviewer' => ['description' => '代码审查', 'prompt' => '你是代码审查员']]
     * @param array<mixed> $agents
     */
    public function setAgents($agents): self
    {
        if (is_array($agents)) {
            $agents = json_encode($agents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($agents === false) {
                throw new ConfigException('agents 序列化失败');
            }
        }
        return $this->setFlag('agents', (string) $agents);
    }

    /**
     * 加载 MCP 服务器配置（--mcp-config），可传 JSON 文件路径或 JSON 字符串
     * @param array<mixed> $configs
     */
    public function setMcpConfig($configs): self
    {
        if (is_array($configs) && isset($configs['mcpServers'])) {
            $encoded = json_encode($configs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new ConfigException('mcp-config 序列化失败');
            }
            $configs = [$encoded];
        }
        return $this->setFlag('mcp-config', is_array($configs) ? $configs : [$configs]);
    }

    /**
     * 只使用 --mcp-config 指定的 MCP 服务器，忽略其它来源（--strict-mcp-config）
     */
    public function setStrictMcpConfig(bool $enabled = true): self
    {
        return $enabled ? $this->setFlag('strict-mcp-config', true)
                        : $this->removeFlag('strict-mcp-config');
    }

    /**
     * 设置加载哪些配置来源（--setting-sources），默认 ['user','project','local']。
     * 传空数组表示不加载任何配置（等价于纯净环境）。
     * @param array<mixed> $sources
     */
    public function setSettingSources($sources): self
    {
        if (is_array($sources) && !$sources) {
            return $this->removeFlag('setting-sources');
        }
        return $this->setFlag('setting-sources', $sources);
    }

    /**
     * 额外加载的设置（--settings），可传 JSON 文件路径或 JSON 字符串
     * @param array<mixed> $settings
     */
    public function setSettings($settings): self
    {
        if (is_array($settings)) {
            $settings = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($settings === false) {
                throw new ConfigException('settings 序列化失败');
            }
        }
        return $this->setFlag('settings', (string) $settings);
    }

    /**
     * 输出 token 级增量（--include-partial-messages），
     * 开启后事件流会多出 'partial' 事件（原始 stream_event）。
     */
    public function setPartialMessages(bool $enabled = true): self
    {
        return $enabled ? $this->setFlag('include-partial-messages', true)
                        : $this->removeFlag('include-partial-messages');
    }

    /**
     * 输出 hook 生命周期事件（--include-hook-events）
     */
    public function setIncludeHookEvents(bool $enabled = true): self
    {
        return $enabled ? $this->setFlag('include-hook-events', true)
                        : $this->removeFlag('include-hook-events');
    }

    /**
     * 转发子 agent 的文本与思考内容（--forward-subagent-text）
     */
    public function setForwardSubagentText(bool $enabled = true): self
    {
        return $enabled ? $this->setFlag('forward-subagent-text', true)
                        : $this->removeFlag('forward-subagent-text');
    }

    /**
     * 指定本次会话的 session id（--session-id，必须是合法 UUID）。
     * 与 setSessionId() 的区别：这里是"新建会话时指定 ID"，那里是"续接已有会话"。
     */
    public function setFixedSessionId(string $uuid): self
    {
        return $this->setFlag('session-id', $uuid);
    }

    /**
     * 续接时分叉出新会话 ID（--fork-session），不污染原会话
     */
    public function setForkSession(bool $enabled = true): self
    {
        return $enabled ? $this->setFlag('fork-session', true)
                        : $this->removeFlag('fork-session');
    }

    /**
     * 续接当前目录下最近一次会话（--continue），无需知道 session id
     */
    public function setContinueLast(bool $enabled = true): self
    {
        return $enabled ? $this->setFlag('continue', true)
                        : $this->removeFlag('continue');
    }

    /**
     * 会话是否落盘（--no-session-persistence）。关闭后无法 --resume 续接。
     */
    public function setSessionPersistence(bool $enabled): self
    {
        return $enabled ? $this->removeFlag('no-session-persistence')
                        : $this->setFlag('no-session-persistence', true);
    }

    /**
     * 调试输出（--debug + --debug-to-stderr，IDE 插件同款组合），
     * 日志走 stderr，可在 'stderr' 事件里收到，不污染 stream-json。
     * @param bool|string $filter true 全量，或类别过滤串如 "api,hooks" / "!file"
     */
    public function setDebug($filter = true): self
    {
        if ($filter === false) {
            return $this->removeFlag('debug')->removeFlag('debug-to-stderr');
        }
        return $this->setFlag('debug', $filter === true ? true : (string) $filter)
                    ->setFlag('debug-to-stderr', true);
    }

    /**
     * 输出认证状态事件（--enable-auth-status），IDE 插件用它展示登录态
     */
    public function setAuthStatus(bool $enabled = true): self
    {
        return $enabled ? $this->setFlag('enable-auth-status', true)
                        : $this->removeFlag('enable-auth-status');
    }

    /**
     * 精简模式（--bare）：跳过 hooks / LSP / 插件同步 / CLAUDE.md 自动发现等，
     * 启动最快、上下文最干净，需要什么就用 setSystemPrompt()/addDir() 显式给。
     */
    public function setBare(bool $enabled = true): self
    {
        return $enabled ? $this->setFlag('bare', true) : $this->removeFlag('bare');
    }

    /**
     * 安全模式（--safe-mode）：禁用全部自定义配置，用于排查配置问题
     */
    public function setSafeMode(bool $enabled = true): self
    {
        return $enabled ? $this->setFlag('safe-mode', true) : $this->removeFlag('safe-mode');
    }

    /**
     * 禁用全部 skill / 斜杠命令（--disable-slash-commands）
     */
    public function setDisableSlashCommands(bool $enabled = true): self
    {
        return $enabled ? $this->setFlag('disable-slash-commands', true)
                        : $this->removeFlag('disable-slash-commands');
    }

    /**
     * 自动压缩窗口（--autocompact），传 'auto' 或 100k–1M 之间的 token 数
     * @param string|int $value
     */
    public function setAutocompact($value): self
    {
        return $this->setFlag('autocompact', (string) $value);
    }

    /**
     * 输出格式（--output-format）：stream-json（默认）/ json / text。
     * 改成 text/json 后逐事件回调将不再有细分事件，只能拿到最终文本。
     */
    public function setOutputFormat(string $format): self
    {
        if (!in_array($format, ['text', 'json', 'stream-json'], true)) {
            throw new ConfigException('不支持的 output-format: ' . $format);
        }
        return $this->setFlag('output-format', $format);
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
     * 单个参数渲染为命令行片段（按 $arrayFlagStyles 决定多值写法）
     * @param mixed $value
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
            $items = array_values(array_filter(array_map('strval', $value), function ($s) { return $s !== ''; }));
            if (!$items) {
                return '';
            }
            $style = isset(static::$arrayFlagStyles[$name]) ? static::$arrayFlagStyles[$name] : 'space';
            if ($style === 'repeat') {
                $out = '';
                foreach ($items as $item) {
                    $out .= ' --' . $name . ' ' . escapeshellarg($item);
                }
                return $out;
            }
            if ($style === 'variadic') {
                $out = ' --' . $name;
                foreach ($items as $item) {
                    $out .= ' ' . escapeshellarg($item);
                }
                return $out;
            }
            $value = implode($style === 'comma' ? ',' : ' ', $items);
        }
        return ' --' . $name . ' ' . escapeshellarg((string) $value);
    }

    /**
     * 渲染全部参数（含选项级 model / flags 注入）
     * @param array<mixed> $options
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
     * @param array<mixed> $env
     */
    public function setEnv(array $env): self
    {
        $this->env = array_merge($this->env, $env);
        return $this;
    }

    /**
     * 子进程是否继承当前 PHP 进程的环境变量（默认 true）
     *
     * proc_open 收到非 null 的 env 数组时会**整体替换**子进程环境，而不是叠加。
     * 关掉继承（false）时，子进程只有 setEnv() 显式给的那几个变量：没有 HOME，
     * claude 只能靠 /etc/passwd 兜底才找得到 ~/.claude 下的登录凭据，容器里
     * /etc/passwd 常常没有对应条目，表现就是"登录态莫名丢失"；PATH 也只剩
     * shell 的内置默认值，nvm 装的 node 不在其中。
     *
     * 因此默认继承。注意继承是全量的：父进程若设了 ANTHROPIC_API_KEY、
     * CLAUDE_CODE_* 之类变量，子进程同样能读到（可能改变 claude 的计费与行为），
     * 需要干净环境时用 setInheritEnv(false) 关掉。
     */
    public function setInheritEnv(bool $enabled = true): self
    {
        $this->inheritEnv = $enabled;
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
     * 命令中是否给 claude 加 exec 前缀（默认 true）
     *
     * proc_open 传字符串走的是 `sh -c "<cmd>"`，实测 dash 不会对 `cd x && cmd`
     * 做 exec 优化（连裸命令也不会），进程树是 sh → claude 两层，于是
     * proc_terminate() 的信号只到中间那层 sh，claude 被丢下继续跑：
     * 超时分支抛完"执行超时"、会话 kill() 返回之后，claude 其实还在后台烧额度。
     * 加上 exec 后 claude 直接取代 sh，proc_terminate() 打的就是它本人。
     *
     * 关掉的唯一理由：自定义执行器（setRunner）拿到命令串后还要往**后面**接东西，
     * 如 `$cmd . '; echo done'` —— exec 之后 shell 已被替换，后面的命令不会执行；
     * 或调用方自己已经插过 exec（`exec exec cmd` 在 dash 下会直接报 127）。
     */
    public function setExecReplace(bool $enabled = true): self
    {
        $this->execReplace = $enabled;
        return $this;
    }

    /**
     * SIGTERM 之后留给进程自行退出的秒数，超时改发 SIGKILL（默认 2 秒，0 表示直接强杀）
     *
     * claude 收到 SIGTERM 会把本轮 session 落盘再退出，留出这段时间可以保住
     * 会话记录（后续还能 --resume）；强杀则没有这个机会。
     */
    public function setKillGrace(int $seconds): self
    {
        $this->killGrace = max(0, $seconds);
        return $this;
    }

    /**
     * 替换库内部的等待实现（默认 usleep）
     *
     * 常驻协程环境（Swoole / Workerman）里，usleep 与 stream_select 会把整个
     * worker 钉死，同 worker 上的其它请求全部排队。传入协程版的等待即可让出：
     *
     * ```php
     * $cli->setSleeper(function ($sec) { \Swoole\Coroutine::sleep($sec); });
     * ```
     *
     * 设置后，本地执行的轮询间隔、ClaudeCodeSession 的事件泵与关闭流程都改走它，
     * 且会话类的 stream_select 一并改为"轮询 + 让出"，不再阻塞整个线程。
     *
     * @param callable $fn function(float $seconds): void
     */
    public function setSleeper(callable $fn): self
    {
        $this->sleeper = $fn;
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
     * @param array<mixed> $options 可选：session_id（覆盖续接）、reset（清空会话）、
     *                        workdir、model、flags（临时参数）、env、timeout
     */
    public function chat(string $prompt, array $options = []): ClaudeCodeResponse
    {
        return $this->run($prompt, $options);
    }

    /**
     * run 的别名（与 chat 等价）
     * @param array<mixed> $options
     */
    public function run(string $prompt, array $options = []): ClaudeCodeResponse
    {
        return $this->runStream($prompt, null, $options);
    }

    /**
     * 流式调用：逐事件回调 + 返回最终汇总结果。
     *
     * $onEvent(string $event, mixed $data)，事件语义对齐官方 IDE 插件：
     *  - 'start'          ['resume' => bool] 是否续接会话
     *  - 'init'           system/init 事件（cwd、session_id、可用工具、模型、MCP 服务器）
     *  - 'message'        原始 stream-json 事件数组（所有事件都会先走这里，原样透传）
     *  - 'text'           助手正文文本块（string）
     *  - 'thinking'       助手思考内容（string）
     *  - 'tool_use'       ['id','name','input'] 工具调用
     *  - 'tool_result'    ['tool_use_id','content','is_error'] 工具执行结果
     *  - 'text_delta'     token 级正文增量（需 setPartialMessages(true)）
     *  - 'thinking_delta' token 级思考增量（需 setPartialMessages(true)）
     *  - 'partial'        原始 stream_event（需 setPartialMessages(true)）
     *  - 'system'         其它 system 子类型（thinking_tokens、compact_boundary 等）
     *  - 'rate_limit'     限流状态信息
     *  - 'error'          result 事件标记 is_error 时触发
     *  - 'stderr'         stderr 文本块（开 setDebug() 后调试日志走这里）
     *  - 'line'           非 JSON 的 stdout 原始行
     *  - 'result'         汇总数组（同 ClaudeCodeResponse::toArray()）
     *  - 'done'           null
     *
     * @param string   $prompt  用户提示词
     * @param callable|null $onEvent 事件回调；为空时仅返回结果对象
     * @param array<mixed> $options 同 chat()
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
            'session_id'  => $sessionId,
            'model'       => '',
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

        $content = $collect['result_text'] !== '' ? $collect['result_text'] : $collect['asst_text'];
        $result = [
            'content'      => $content,
            'model'        => $collect['model'],
            'usage'        => $collect['usage'],
            'success'      => !$collect['is_error'] && $collect['exit_code'] === 0,
            'session_id'   => $collect['session_id'],
            'cost_usd'     => $collect['cost'],
            'num_turns'    => $collect['num_turns'],
            'duration_ms'  => $collect['duration'],
            'exit_code'    => $collect['exit_code'],
            'subtype'      => $collect['subtype'],
            'stop_reason'  => $collect['stop_reason'],
            'thinking'     => $collect['thinking'],
            'tools'        => $collect['tools'],
            'tool_uses'    => $collect['tool_uses'],
            'permission_denials' => $collect['denials'],
            'init'         => $collect['init'],
            'structured'   => self::decodeStructured($content),
            'command'      => $cmd,
            'raw'          => [],
        ];

        $emit('result', $result);
        $emit('done', null);

        return new ClaudeCodeResponse($result);
    }

    /**
     * 处理一行 stream-json 事件（或非 JSON 原始行）
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
        $emit('message', $ev);

        // 会话 ID 可能出现在任意事件上（通常 result 事件携带）
        if (isset($ev['session_id']) && is_string($ev['session_id']) && $ev['session_id'] !== '') {
            $collect['session_id'] = $ev['session_id'];
        }

        $type = isset($ev['type']) ? (string) $ev['type'] : '';

        switch ($type) {
            case 'system':
                $this->handleSystemEvent($ev, $collect, $emit);
                break;

            case 'assistant':
                $this->handleAssistantEvent($ev, $collect, $emit);
                break;

            case 'user':
                // --replay-user-messages 下 CLI 会把收到的用户消息原样回显，用于确认已投递
                if (!empty($ev['isReplay'])) {
                    $emit('replay', $ev);
                    break;
                }
                // 工具执行结果由 CLI 以 user 消息回填
                if (isset($ev['message']['content']) && is_array($ev['message']['content'])) {
                    foreach ($ev['message']['content'] as $block) {
                        if (($block['type'] ?? '') === 'tool_result') {
                            $emit('tool_result', [
                                'tool_use_id' => (string) ($block['tool_use_id'] ?? ''),
                                'content'     => $block['content'] ?? null,
                                'is_error'    => !empty($block['is_error']),
                            ]);
                        }
                    }
                }
                break;

            case 'stream_event':
                // --include-partial-messages 下的 token 级增量
                $emit('partial', $ev);
                if (($ev['event']['type'] ?? '') === 'content_block_delta') {
                    $delta = $ev['event']['delta'] ?? [];
                    if (($delta['type'] ?? '') === 'text_delta' && isset($delta['text'])) {
                        $emit('text_delta', (string) $delta['text']);
                    } elseif (($delta['type'] ?? '') === 'thinking_delta' && isset($delta['thinking'])) {
                        $emit('thinking_delta', (string) $delta['thinking']);
                    }
                }
                break;

            case 'rate_limit_event':
                $emit('rate_limit', $ev['rate_limit_info'] ?? []);
                break;

            case 'result':
                $this->handleResultEvent($ev, $collect, $emit);
                break;
        }
    }

    /**
     * system 事件：init 携带会话初始信息，其余按子类型透传
     * @param array<mixed> $collect
     * @param array<mixed> $ev
     */
    protected function handleSystemEvent(array $ev, array &$collect, callable $emit): void
    {
        $subtype = isset($ev['subtype']) ? (string) $ev['subtype'] : '';
        if ($subtype === 'init') {
            $collect['init'] = $ev;
            if (isset($ev['tools']) && is_array($ev['tools'])) {
                $collect['tools'] = $ev['tools'];
            }
            if (isset($ev['model']) && is_string($ev['model']) && $ev['model'] !== '') {
                $collect['model'] = $ev['model'];
            }
            $emit('init', $ev);
            return;
        }
        $emit('system', $ev);
    }

    /**
     * assistant 事件：拆出文本 / 思考 / 工具调用三类内容块
     * @param array<mixed> $collect
     * @param array<mixed> $ev
     */
    protected function handleAssistantEvent(array $ev, array &$collect, callable $emit): void
    {
        if (isset($ev['message']['model']) && is_string($ev['message']['model'])) {
            $collect['model'] = $ev['message']['model'];
        }
        if (!isset($ev['message']['content']) || !is_array($ev['message']['content'])) {
            return;
        }
        foreach ($ev['message']['content'] as $block) {
            $blockType = isset($block['type']) ? (string) $block['type'] : '';
            if ($blockType === 'text' && isset($block['text'])) {
                $collect['asst_text'] .= (string) $block['text'];
                $emit('text', (string) $block['text']);
            } elseif ($blockType === 'thinking' && isset($block['thinking'])) {
                $collect['thinking'] .= (string) $block['thinking'];
                $emit('thinking', (string) $block['thinking']);
            } elseif ($blockType === 'tool_use') {
                $use = [
                    'id'    => (string) ($block['id'] ?? ''),
                    'name'  => (string) ($block['name'] ?? ''),
                    'input' => $block['input'] ?? [],
                ];
                $collect['tool_uses'][] = $use;
                $emit('tool_use', $use);
            }
        }
    }

    /**
     * result 事件：本轮汇总（用量、费用、轮数、终止原因、被拒工具）
     * @param array<mixed> $collect
     * @param array<mixed> $ev
     */
    protected function handleResultEvent(array $ev, array &$collect, callable $emit): void
    {
        if (isset($ev['result']) && is_string($ev['result'])) {
            $collect['result_text'] = $ev['result'];
        }
        if (isset($ev['usage']) && is_array($ev['usage'])) {
            $collect['usage'] = $ev['usage'];
        }
        if (isset($ev['modelUsage']) && is_array($ev['modelUsage']) && $collect['model'] === '') {
            $names = array_keys($ev['modelUsage']);
            if ($names) {
                $collect['model'] = (string) $names[0];
            }
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
        if (isset($ev['subtype'])) {
            $collect['subtype'] = (string) $ev['subtype'];
        }
        if (isset($ev['stop_reason']) && is_string($ev['stop_reason'])) {
            $collect['stop_reason'] = $ev['stop_reason'];
        }
        if (isset($ev['permission_denials']) && is_array($ev['permission_denials'])) {
            $collect['denials'] = $ev['permission_denials'];
        }
        if ($collect['is_error']) {
            $emit('error', $ev);
        }
    }

    /**
     * 尝试把最终文本解析为结构化数组（配合 --json-schema 使用），失败返回 null。
     * 兼容模型把 JSON 包在 ```json 代码块里的情况。
     * @return array<mixed>
     */
    public static function decodeStructured(string $content): ?array
    {
        $text = trim($content);
        if ($text === '') {
            return null;
        }
        if (strpos($text, '```') === 0) {
            $text = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $text);
            $text = trim((string) $text);
        }
        if ($text === '' || ($text[0] !== '{' && $text[0] !== '[')) {
            return null;
        }
        $data = json_decode($text, true);
        return is_array($data) ? $data : null;
    }

    /**
     * 解析一行 stream-json 输出为事件数组；非 JSON 或空行返回 null
     * @return array<mixed>
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
     * @param array<mixed> $options
     */
    public function buildCommand(string $binary, string $promptFile, string $workdir = '', array $options = []): string
    {
        return $this->buildBaseCommand($binary, $workdir, $options, ' < ' . escapeshellarg($promptFile));
    }

    /**
     * 组装不含 stdin 重定向的基础命令（双工会话模式复用）
     *
     * @param string $suffix 追加在参数之后、cd/前缀包裹之前的片段
     * @param array<mixed> $options
     */
    protected function buildBaseCommand(string $binary, string $workdir, array $options, string $suffix = ''): string
    {
        $cmd = $this->execPrefix() . escapeshellarg($binary) . ' --print' . $this->renderFlags($options);

        $sessionId = isset($options['session_id']) ? (string) $options['session_id'] : $this->sessionId;
        if (!empty($options['reset'])) {
            $sessionId = '';
        }
        if ($sessionId !== '') {
            $cmd .= ' --resume ' . escapeshellarg($sessionId);
        }

        $cmd .= $suffix;

        if ($workdir !== '') {
            $cmd = 'cd ' . escapeshellarg($workdir) . ' && ' . $cmd;
        }
        if ($this->shellPrefix !== '') {
            $cmd = $this->shellPrefix . ' ' . $cmd;
        }
        return $cmd;
    }

    /**
     * 命令里 claude 之前的 exec 前缀（见 setExecReplace）。Windows 的 cmd.exe
     * 没有 exec 这个内置命令，那边一律不加。
     */
    protected function execPrefix(): string
    {
        return ($this->execReplace && !self::isWindows()) ? 'exec ' : '';
    }

    /**
     * 当前是否运行在 Windows 上（PHP_OS_FAMILY 是 7.2 才有的，这里按 7.1 写法判断）
     */
    protected static function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    // ---------------------------------------------------------------------
    // 信息查询：版本 / 登录态 / 模型列表 / 额度用量 / 设置 / MCP
    // ---------------------------------------------------------------------

    /**
     * 获取 claude CLI 版本号，如 "2.1.222"（结果缓存在实例内）
     */
    public function getVersion(bool $refresh = false): string
    {
        if ($this->version !== '' && !$refresh) {
            return $this->version;
        }
        $ret = $this->runCommand(['--version'], 30);
        if (!preg_match('/(\d+\.\d+\.\d+)/', $ret['stdout'], $m)) {
            throw new ProcessException('无法解析 claude 版本号: ' . trim($ret['stdout'] . $ret['stderr']));
        }
        $this->version = $m[1];
        return $this->version;
    }

    /**
     * 获取登录 / 订阅状态（claude auth status --json）
     * 返回 ['loggedIn','authMethod','apiProvider','email','orgId','orgName','subscriptionType']
     * @return array<mixed>
     */
    public function getAuthStatus(): array
    {
        $ret = $this->runCommand(['auth', 'status', '--json'], 60);
        $data = json_decode(trim($ret['stdout']), true);
        if (!is_array($data)) {
            throw new ProcessException('无法解析登录状态: ' . trim($ret['stdout'] . $ret['stderr']));
        }
        return $data;
    }

    /**
     * 是否已登录
     */
    public function isLoggedIn(): bool
    {
        try {
            $status = $this->getAuthStatus();
        } catch (\Throwable $e) {
            // 探测登录态失败一律当作未登录，不该把异常抛给调用方
            return false;
        }
        return !empty($status['loggedIn']);
    }

    /**
     * 获取当前账号可用的模型列表。
     *
     * @param bool $raw false 返回可直接传给 setModel() 的标识数组；
     *                  true 返回完整条目（含 resolvedModel、displayName、description、
     *                  supportsEffort、supportedEffortLevels、supportsFastMode 等）
     * @return array<mixed>
     */
    public function listModels(bool $raw = false): array
    {
        if ($this->modelsCache === null) {
            $resp = $this->queryControl('list_models', [], 60);
            $this->modelsCache = isset($resp['models']) && is_array($resp['models']) ? $resp['models'] : [];
        }
        if ($raw) {
            return $this->modelsCache;
        }
        $out = [];
        foreach ($this->modelsCache as $model) {
            if (isset($model['value'])) {
                $out[] = (string) $model['value'];
            }
        }
        return $out;
    }

    /**
     * 获取额度用量与限流信息。返回结构：
     *  - session            本次进程的花费 / 耗时 / 代码增删行数 / 分模型用量
     *  - subscription_type  订阅类型，如 max / pro
     *  - rate_limits        各限流窗口（five_hour、seven_day…）的 utilization 百分比与重置时间
     *  - behaviors          近一天 / 一周的请求数、会话数等统计
     * @return array<mixed>
     */
    public function getUsage(): array
    {
        return $this->queryControl('get_usage', [], 60);
    }

    /**
     * 获取精简后的限流额度概览，每项含
     * ['key','percent','severity','resets_at','resets_in','is_active']，
     * percent 为已用百分比。未提供限流数据（如 API Key 计费）时返回空数组。
     * @return array<mixed>
     */
    public function getRateLimits(): array
    {
        $usage = $this->getUsage();
        if (empty($usage['rate_limits']) || !is_array($usage['rate_limits'])) {
            return [];
        }
        $limits = $usage['rate_limits'];
        $out = [];

        // limits 数组是 CLI 归一化后的统一视图，优先用它
        if (!empty($limits['limits']) && is_array($limits['limits'])) {
            foreach ($limits['limits'] as $item) {
                $out[] = [
                    'key'       => (string) ($item['kind'] ?? ''),
                    'percent'   => (float) ($item['percent'] ?? 0),
                    'severity'  => (string) ($item['severity'] ?? ''),
                    'resets_at' => (string) ($item['resets_at'] ?? ''),
                    'resets_in' => self::secondsUntil($item['resets_at'] ?? ''),
                    'is_active' => !empty($item['is_active']),
                ];
            }
            return $out;
        }

        // 回退：逐个窗口字段读取
        foreach ($limits as $key => $item) {
            if (!is_array($item) || !isset($item['utilization'])) {
                continue;
            }
            $out[] = [
                'key'       => (string) $key,
                'percent'   => (float) $item['utilization'],
                'severity'  => '',
                'resets_at' => (string) ($item['resets_at'] ?? ''),
                'resets_in' => self::secondsUntil($item['resets_at'] ?? ''),
                'is_active' => true,
            ];
        }
        return $out;
    }

    /**
     * 获取当前生效的设置（合并 user / project / local 之后的结果），
     * 含 env、permissions.allow/deny、model 等
     * @return array<mixed>
     */
    public function getSettings(): array
    {
        return $this->queryControl('get_settings', [], 90);
    }

    /**
     * 获取 MCP 服务器状态列表
     * @return array<mixed>
     */
    public function getMcpServers(): array
    {
        $resp = $this->queryControl('mcp_status', [], 60);
        return isset($resp['mcpServers']) && is_array($resp['mcpServers']) ? $resp['mcpServers'] : [];
    }

    /**
     * 获取 CLI 构建信息 ['version' => '2.1.222', 'buildTime' => '...']
     * @return array<mixed>
     */
    public function getBinaryVersion(): array
    {
        return $this->queryControl('get_binary_version', [], 60);
    }

    /**
     * 安装体检（claude doctor），返回原始文本报告
     */
    public function doctor(): string
    {
        $ret = $this->runCommand(['doctor'], 120);
        return trim($ret['stdout'] . $ret['stderr']);
    }

    /**
     * 执行任意 claude 子命令并收集完整输出。
     * 例：runCommand(['mcp', 'list'])、runCommand(['--version'])
     *
     * @return array<mixed> ['exit_code' => int, 'stdout' => string, 'stderr' => string]
     * @param array<mixed> $args
     */
    public function runCommand(array $args, int $timeout = 60): array
    {
        $cmd = $this->execPrefix() . escapeshellarg($this->getBinary());
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg((string) $arg);
        }
        if ($this->workdir !== '') {
            $cmd = 'cd ' . escapeshellarg($this->workdir) . ' && ' . $cmd;
        }
        if ($this->shellPrefix !== '') {
            $cmd = $this->shellPrefix . ' ' . $cmd;
        }

        $stdout = '';
        $stderr = '';
        $onChunk = function ($chunk, $type) use (&$stdout, &$stderr) {
            if ($type === 'err') {
                $stderr .= $chunk;
            } else {
                $stdout .= $chunk;
            }
        };

        $saved = $this->timeout;
        $this->timeout = max(0, $timeout);
        try {
            $exitCode = $this->execute($cmd, $onChunk);
        } finally {
            $this->timeout = $saved;
        }

        return ['exit_code' => (int) $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * 通过控制协议查询信息：临时起一个 claude 进程，问完即关。
     * ClaudeCodeSession 会覆盖此方法，复用自身已运行的进程。
     *
     * @throws ProcessException CLI 返回失败或超时
     * @param array<mixed> $extra
     * @return array<mixed>
     */
    protected function queryControl(string $subtype, array $extra = [], int $timeout = 60): array
    {
        $session = $this->newControlSession($timeout);
        try {
            $session->start();
            $resp = $session->control(array_merge(['subtype' => $subtype], $extra), true, $timeout);
        } finally {
            $session->close();
        }
        return self::unwrapControlResponse($subtype, $resp);
    }

    /**
     * 创建用于信息查询的临时会话（继承本实例的运行环境，会话不落盘）
     */
    protected function newControlSession(int $timeout): ClaudeCodeSession
    {
        $session = new ClaudeCodeSession([
            'binary'       => $this->getBinary(),
            'workdir'      => $this->workdir,
            'env'          => $this->env,
            'inherit_env'  => $this->inheritEnv,
            'exec_replace' => $this->execReplace,
            'kill_grace'   => $this->killGrace,
            'shell'        => $this->shellPrefix,
            'timeout'      => $timeout,
            'turn_timeout' => $timeout,
        ]);
        // 仅查询信息，不产生可续接的会话记录
        // 分两句写：链式返回会因父类声明的返回类型是 self 而丢掉子类类型
        $session->setSessionPersistence(false);
        // 等待实现要一并带过去，否则协程环境下这个临时进程会把 worker 钉死
        if ($this->sleeper !== null) {
            $session->setSleeper($this->sleeper);
        }
        return $session;
    }

    /**
     * 校验并取出控制响应的 response 主体
     * @param array<mixed> $resp
     * @return array<mixed>
     */
    protected static function unwrapControlResponse(string $subtype, array $resp): array
    {
        if (($resp['subtype'] ?? '') !== 'success') {
            $error = isset($resp['error']) ? (string) $resp['error'] : json_encode($resp);
            throw new ProcessException('查询 ' . $subtype . ' 失败: ' . $error);
        }
        return isset($resp['response']) && is_array($resp['response']) ? $resp['response'] : [];
    }

    /**
     * 距离给定 ISO8601 时间还有多少秒，无法解析或已过期返回 0
     * @param mixed $isoTime
     */
    protected static function secondsUntil($isoTime): int
    {
        if (!is_string($isoTime) || $isoTime === '') {
            return 0;
        }
        $ts = strtotime($isoTime);
        if ($ts === false) {
            return 0;
        }
        return max(0, $ts - time());
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
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open(
            $cmd,
            $descriptors,
            $pipes,
            $this->workdir !== '' ? $this->workdir : null,
            $this->procEnv()
        );
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
            // proc_get_status() 只返回数组；保留 is_array 判断只为兼容极端环境
            if (!is_array($status) || !$status['running']) {
                $exitCode = is_array($status) ? (int) $status['exitcode'] : -1;
                break;
            }
            if ($this->timeout > 0 && (microtime(true) - $started) > $this->timeout) {
                // 先礼后兵地收掉进程再抛异常，否则 claude 会脱离掌控继续跑到把整轮写完
                $this->terminateProc($proc);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        @fclose($pipe);
                    }
                }
                @proc_close($proc);
                throw new ProcessException('claude 执行超时（' . $this->timeout . 's）');
            }
            $this->pause(0.02);
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
     * @return array<mixed>
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

    /**
     * proc_open 的 env 参数：null = 继承父进程环境，数组 = 整体替换（见 setInheritEnv）
     * @return array<string, string>|null
     */
    protected function procEnv()
    {
        $env = $this->buildLocalEnv();
        if (!$this->inheritEnv) {
            return $env;
        }
        if (!$env) {
            return null;
        }
        $base = getenv();
        return array_merge(is_array($base) ? $base : [], $env);
    }

    /**
     * 等待若干秒：默认 usleep，设过 setSleeper() 时改走它（协程环境让出）
     */
    protected function pause(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        if ($this->sleeper !== null) {
            call_user_func($this->sleeper, $seconds);
            return;
        }
        usleep((int) round($seconds * 1000000));
    }

    /**
     * 收掉子进程：先 SIGTERM 给它落盘收尾的机会，宽限期内没退出再 SIGKILL
     * @param resource $proc
     */
    protected function terminateProc($proc): void
    {
        if (!is_resource($proc)) {
            return;
        }
        @proc_terminate($proc);
        $deadline = microtime(true) + $this->killGrace;
        while ($this->killGrace > 0 && microtime(true) < $deadline) {
            $status = @proc_get_status($proc);
            if (!is_array($status) || !$status['running']) {
                return;
            }
            $this->pause(0.05);
        }
        $status = @proc_get_status($proc);
        if (is_array($status) && $status['running']) {
            @proc_terminate($proc, 9);
        }
    }
}
