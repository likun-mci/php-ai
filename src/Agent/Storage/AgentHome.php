<?php
namespace Ai\Agent\Storage;

use Ai\Helpers\Path;

/**
 * AgentHome——只负责“数据应该放在哪里”
 *
 * 双根存储的布局中枢（见 dev.md 第三节）。它只做路径推导，不碰：
 * Memory 内容格式、Prompt 注入、Session 消息序列化、权限策略。
 *
 * 两个根：
 *   - 项目根 <project>/.agent/        项目团队共识（可进 Git）
 *   - HOME 根 ~/.agent/               含用户输入/输出/绝对路径/凭据等，0700
 *
 * 关键不变量（见 dev.md 2.3 / 2.4）：
 *   - 向上找项目根最多 10 层，到 HOME 必停，HOME 绝不作 project root
 *   - 不依赖 .git、不用 realpath() 计算项目身份
 *   - 环境变量只经 getenv()
 *   - 只读方法零副作用：不创建任何目录（见 dev.md 第十一节）
 */
class AgentHome
{
    /** @var string 工作目录 */
    protected $workdir;

    /** @var string 显式指定的项目根（空表示自动推导） */
    protected $explicitProjectRoot = '';

    /** @var string 显式指定的 HOME 数据根 .agent（空表示自动探测） */
    protected $explicitHome = '';

    /** @var string 用户标识（原始值，不进路径） */
    protected $userId = '';

    /** @var string 缓存：解析后的 HOME 数据根（.agent 基址） */
    protected $homeCache = '';

    /** @var string 缓存：解析后的项目根 */
    protected $projectRootCache = '';

    /** @var bool 项目侧本次运行是否被标记为不可写（仅内存，不持久化） */
    protected $projectUnwritable = false;

    /** @var int HOME 侧数据目录权限位 */
    protected $dirMode = 0700;

    /** @var int 项目 .agent 目录权限位 */
    protected $projectDirMode = 0755;

    /** @var array<string, bool> 一次性 warning 去重（按 message key） */
    protected $warned = [];

    /**
     * @param string $workdir 工作目录
     * @param array<string, string> $options home / projectRoot / userId
     */
    public function __construct($workdir = '', array $options = [])
    {
        $this->workdir = $workdir === '' ? self::cwd() : Path::normalize($workdir);
        if (isset($options['home']) && $options['home'] !== '') {
            $this->explicitHome = Path::normalize($options['home']);
        }
        if (isset($options['projectRoot']) && $options['projectRoot'] !== '') {
            $this->explicitProjectRoot = Path::normalize($options['projectRoot']);
        }
        if (isset($options['userId'])) {
            $this->userId = (string) $options['userId'];
        }
    }

    /**
     * 便捷工厂
     *
     * @param string $workdir
     * @param array<string, string> $options
     * @return self
     */
    public static function detect($workdir = '', array $options = [])
    {
        return new self($workdir, $options);
    }

    /** @return string */
    protected static function cwd()
    {
        $cwd = getcwd();
        return is_string($cwd) && $cwd !== '' ? Path::normalize($cwd) : '.';
    }

    /**
     * HOME 侧数据根（即 .agent 基址，一切 HOME 数据挂它下面）
     *
     * 解析顺序：显式 setAgentHome → <realhome>/.agent → 临时回退。
     * 纯路径计算，不创建目录。
     *
     * @return string
     */
    public function home()
    {
        if ($this->homeCache !== '') {
            return $this->homeCache;
        }
        if ($this->explicitHome !== '') {
            return $this->homeCache = $this->explicitHome;
        }
        $real = Path::home();
        if ($real !== '') {
            return $this->homeCache = $real . '/.agent';
        }
        // 回退：临时根（见 dev.md 2.4）
        $uid = function_exists('getmyuid') ? getmyuid() : 0;
        $uid = is_int($uid) ? $uid : 0;
        $tmp = Path::normalize(sys_get_temp_dir() . '/.agent-' . $uid);
        return $this->homeCache = $tmp;
    }

    /**
     * 是否使用了临时回退根（非真实 HOME）
     *
     * @return bool
     */
    public function isTempHome()
    {
        return $this->explicitHome === '' && Path::home() === '';
    }

    /**
     * 项目根解析（见 dev.md 2.3）
     *
     * 优先级：显式 setProjectRoot → 从 workdir 向上找 .agent/（到 HOME 必停）
     * → workdir。绝不把 HOME 当项目根。
     *
     * @return string
     */
    public function projectRoot()
    {
        if ($this->projectRootCache !== '') {
            return $this->projectRootCache;
        }
        if ($this->explicitProjectRoot !== '') {
            return $this->projectRootCache = $this->explicitProjectRoot;
        }

        // 到 HOME 必停：HOME 的父目录也不能越过
        $realHome = Path::home();
        $stopAt = $realHome !== '' ? $realHome : '';

        $found = Path::findUp($this->workdir, '.agent', 10, $stopAt);
        if ($found !== '' && !$this->isHome($found)) {
            return $this->projectRootCache = $found;
        }
        return $this->projectRootCache = $this->workdir;
    }

    /**
     * 某目录是否就是用户 HOME（HOME 不能作项目根）
     *
     * @param string $dir
     * @return bool
     */
    protected function isHome($dir)
    {
        $realHome = Path::home();
        if ($realHome === '') {
            return false;
        }
        return Path::normalize($dir) === $realHome;
    }

    /**
     * 项目 slug（用于 HOME 侧按项目隔离）
     *
     * @return string
     */
    public function projectSlug()
    {
        return Path::slug($this->projectRoot());
    }

    /** @return string 工作目录 */
    public function workdir()
    {
        return $this->workdir;
    }

    // ===== Memory 路径映射（见 dev.md 10.3） =====

    /**
     * 某 scope 的写入目标文件（agent/project/user）
     *
     * session/task 需要 sessionId，不由本方法处理——由 Agent 用 sessionDir() 拼。
     * 无法定位（如 user 无 userId）时返回 ''。
     *
     * @param string $scope
     * @return string
     */
    public function memoryPath($scope)
    {
        switch ((string) $scope) {
            case 'agent':
                return $this->home() . '/AGENT.md';
            case 'project':
                if ($this->isProjectWritable()) {
                    return $this->projectRoot() . '/.agent/AGENT.md';
                }
                return $this->home() . '/projects/' . $this->projectSlug() . '/AGENT.md';
            case 'user':
                $ud = $this->userDir($this->userId);
                return $ud === '' ? '' : $ud . '/AGENT.md';
            default:
                return '';
        }
    }

    /**
     * 某 scope 的读取路径列表（project 有主/回退两处，其余单一）
     *
     * 读顺序：project > fallback（见 dev.md 10.4）。
     *
     * @param string $scope
     * @return string[]
     */
    public function memoryReadPaths($scope)
    {
        if ((string) $scope === 'project') {
            $primary = $this->projectRoot() . '/.agent/AGENT.md';
            $fallback = $this->home() . '/projects/' . $this->projectSlug() . '/AGENT.md';
            return $primary === $fallback ? [$primary] : [$primary, $fallback];
        }
        $p = $this->memoryPath($scope);
        return $p === '' ? [] : [$p];
    }

    /**
     * 项目 memory 写目标（可写走项目侧，否则 HOME 回退）
     *
     * @return string
     */
    public function projectMemoryPath()
    {
        return $this->memoryPath('project');
    }

    /**
     * 项目 memory 读路径（主 + 回退）
     *
     * @return string[]
     */
    public function projectMemoryReadPaths()
    {
        return $this->memoryReadPaths('project');
    }

    // ===== 用户 / 会话目录（见 dev.md 2.6 / 二.四） =====

    /**
     * 用户数据目录：users/<h[0:2]>/<h[2:2]>/<h>/
     *
     * 原始 userId 绝不进路径（见 dev.md 2.6）。userId 为空返回 ''。
     *
     * @param string|null $userId
     * @return string
     */
    public function userDir($userId = null)
    {
        $uid = $userId === null ? $this->userId : (string) $userId;
        if ($uid === '') {
            return '';
        }
        $h = substr(hash('sha256', $uid), 0, 32);
        return $this->home() . '/users/' . substr($h, 0, 2) . '/' . substr($h, 2, 2) . '/' . $h;
    }

    /**
     * 会话目录
     *
     * 传了 userId → users/<hash>/sessions/；否则 → projects/<slug>/sessions/。
     * 纯路径推导，不创建目录。
     *
     * @param string $sessionId
     * @param string|null $userId
     * @return string
     */
    public function sessionDir($sessionId, $userId = null)
    {
        $uid = $userId === null ? $this->userId : (string) $userId;
        if ($uid !== '') {
            $base = $this->userDir($uid) . '/sessions';
        } else {
            $base = $this->home() . '/projects/' . $this->projectSlug() . '/sessions';
        }
        // sessionId 目前只决定文件名，不建子目录；此处仅返回目录
        return $base;
    }

    // ===== project 可写性（见 dev.md 第十二节） =====

    /**
     * 项目侧是否可写（第一阶段预判，不创建目录）
     *
     * .agent/ 已存在则判它可写；否则判 projectRoot 可写。一旦运行中写失败
     * 被 markProjectUnwritable() 标记，则恒为 false。
     *
     * @return bool
     */
    public function isProjectWritable()
    {
        if ($this->projectUnwritable) {
            return false;
        }
        $agentDir = $this->projectRoot() . '/.agent';
        if (is_dir($agentDir)) {
            return is_writable($agentDir);
        }
        return is_writable($this->projectRoot());
    }

    /**
     * 标记项目侧不可写，后续 project 写走 HOME 回退（仅内存，不持久化）
     *
     * @param string $reason
     * @return void
     */
    public function markProjectUnwritable($reason)
    {
        $this->projectUnwritable = true;
        $key = 'project_unwritable';
        if (!isset($this->warned[$key])) {
            $this->warned[$key] = true;
            \Ai\Helpers\Log::warning('项目目录不可写，project 记忆回退到 HOME', [
                'project' => $this->projectRoot(),
                'reason'  => (string) $reason,
            ]);
        }
    }

    // ===== 目录创建（仅写入路径调用） =====

    /**
     * 确保 HOME 数据根存在并通过安全校验（0700 + 临时根 owner/symlink 检查）
     *
     * 只在真正要写 HOME 数据时调用。临时根未通过校验时返回 false（拒绝持久化）。
     *
     * @return bool
     */
    public function ensureHome()
    {
        $home = $this->home();
        if (!Path::ensureDir($home, $this->dirMode)) {
            return false;
        }
        // 临时回退根：必须非软链接且属主是自己（见 dev.md 2.4）
        if ($this->isTempHome()) {
            if (is_link($home)) {
                $this->warnOnce('temp_home_symlink', '临时数据根是软链接，拒绝持久化', ['dir' => $home]);
                return false;
            }
            if (function_exists('getmyuid')) {
                $owner = @fileowner($home);
                $uid = getmyuid();
                if ($owner !== false && is_int($uid) && $owner !== $uid) {
                    $this->warnOnce('temp_home_owner', '临时数据根属主不符，拒绝持久化', [
                        'dir' => $home, 'owner' => $owner, 'uid' => $uid,
                    ]);
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * 确保某目录存在（HOME 侧 0700，项目侧 0755）
     *
     * @param string $dir
     * @param bool $isProject 是否项目侧目录
     * @return bool
     */
    public function ensureDir($dir, $isProject = false)
    {
        return Path::ensureDir($dir, $isProject ? $this->projectDirMode : $this->dirMode);
    }

    /**
     * 覆盖 HOME 侧数据目录权限（见 dev.md 第十八节，多 Unix 用户场景）
     *
     * @param int $mode
     * @return $this
     */
    public function setDirMode($mode)
    {
        $this->dirMode = (int) $mode;
        return $this;
    }

    /** @return int */
    public function dirMode()
    {
        return $this->dirMode;
    }

    /** @return string */
    public function userId()
    {
        return $this->userId;
    }

    /**
     * @param string $key
     * @param string $msg
     * @param array<string, mixed> $ctx
     * @return void
     */
    protected function warnOnce($key, $msg, array $ctx = [])
    {
        if (isset($this->warned[$key])) {
            return;
        }
        $this->warned[$key] = true;
        \Ai\Helpers\Log::warning($msg, $ctx);
    }
}
