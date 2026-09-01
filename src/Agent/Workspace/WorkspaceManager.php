<?php
namespace Ai\Agent\Workspace;

/**
 * WorkspaceManager——工作区管理器
 *
 * 跟踪 Agent 当前工作区的 Git 状态，让模型了解 cwd、分支、已修改文件等。
 * 状态按需刷新，不会每轮都跑 git 命令。
 *
 * 用法：
 * ```php
 * $wm = new WorkspaceManager('/var/www');
 * $wm->refresh();
 * echo $wm->getBranch();        // 'main'
 * print_r($wm->getModified());  // ['src/Auth.php']
 * echo $wm->toContextString();  // 适合注入系统提示词的紧凑文本
 * ```
 */
class WorkspaceManager
{
    /** @var string */
    protected $workdir = '';

    /** @var string */
    protected $branch = '';

    /** @var string[] */
    protected $modified = [];

    /** @var string[] */
    protected $untracked = [];

    /** @var bool */
    protected $hasChanges = false;

    /** @var bool */
    protected $isGitRepo = false;

    /** @var int */
    protected $lastRefresh = 0;

    /** @var int 状态缓存有效期（秒），默认 5 秒 */
    protected $cacheTtl = 5;

    /** @var string 工作区状态摘要文本（缓存） */
    protected $cachedString = '';

    /**
     * @param string $workdir 工作目录
     * @param array<string, mixed> $options cache_ttl 等
     */
    public function __construct($workdir = '', array $options = [])
    {
        if ($workdir !== '') {
            $this->setWorkdir((string) $workdir);
        }
        if (isset($options['cache_ttl'])) {
            $this->cacheTtl = (int) $options['cache_ttl'];
        }
    }

    /**
     * 设置工作目录（自动刷新状态）
     *
     * @param string $workdir
     * @return $this
     */
    public function setWorkdir($workdir)
    {
        $this->workdir = rtrim(str_replace('\\', '/', (string) $workdir), '/');
        $this->lastRefresh = 0;  // 强制下次 refresh
        return $this;
    }

    /** @return string */
    public function getWorkdir()
    {
        return $this->workdir;
    }

    /**
     * 刷新工作区状态（读取 Git 信息）
     *
     * @return $this
     */
    public function refresh()
    {
        $now = time();
        if ($now - $this->lastRefresh < $this->cacheTtl && $this->lastRefresh > 0) {
            return $this;
        }
        $this->lastRefresh = $now;

        if ($this->workdir === '' || !is_dir($this->workdir)) {
            $this->isGitRepo = false;
            $this->branch = '';
            $this->modified = [];
            $this->untracked = [];
            $this->hasChanges = false;
            $this->cachedString = '';
            return $this;
        }

        // 检查是否是 git 仓库
        $gitDir = $this->exec('git rev-parse --git-dir 2>/dev/null');
        if ($gitDir === '') {
            $this->isGitRepo = false;
            $this->branch = '';
            $this->modified = [];
            $this->untracked = [];
            $this->hasChanges = false;
            $this->cachedString = $this->buildContextString();
            return $this;
        }

        $this->isGitRepo = true;

        // 获取分支名
        $this->branch = $this->exec('git rev-parse --abbrev-ref HEAD 2>/dev/null');

        // 获取已修改的文件（staged + unstaged）
        $status = $this->exec('git status --porcelain 2>/dev/null');
        $this->modified = [];
        $this->untracked = [];
        $this->hasChanges = false;

        if ($status !== '') {
            $lines = explode("\n", $status);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                // git status --porcelain 格式：XY filename
                $xy = substr($line, 0, 2);
                $file = trim(substr($line, 2));
                if ($file === '') {
                    continue;
                }
                if ($xy === '??') {
                    $this->untracked[] = $file;
                } else {
                    $this->modified[] = $file;
                }
                $this->hasChanges = true;
            }
        }

        $this->cachedString = $this->buildContextString();
        return $this;
    }

    /** @return string */
    public function getBranch()
    {
        return $this->branch;
    }

    /** @return string[] */
    public function getModified()
    {
        return $this->modified;
    }

    /** @return string[] */
    public function getUntracked()
    {
        return $this->untracked;
    }

    /** @return bool */
    public function hasChanges()
    {
        return $this->hasChanges;
    }

    /** @return bool */
    public function isGitRepo()
    {
        return $this->isGitRepo;
    }

    /**
     * 当前工作目录的 basename（项目名，用于上下文标识）
     *
     * @return string
     */
    public function getProjectName()
    {
        if ($this->workdir === '') {
            return '';
        }
        $parts = explode('/', $this->workdir);
        return end($parts);
    }

    /**
     * 生成紧凑的工作区状态摘要（适合注入系统提示词）
     *
     * 格式：
     * ```
     * cwd: /var/www/project
     * branch: main
     * modified: src/Auth.php, src/User.php
     * untracked: notes.txt
     * ```
     *
     * @return string
     */
    public function toContextString()
    {
        if ($this->cachedString !== '') {
            return $this->cachedString;
        }
        return $this->buildContextString();
    }

    /**
     * 构建工作区状态文本
     *
     * @return string
     */
    protected function buildContextString()
    {
        if ($this->workdir === '') {
            return '';
        }

        $parts = [];

        if ($this->workdir !== '') {
            $parts[] = 'cwd: ' . $this->workdir;
        }

        if ($this->isGitRepo) {
            $parts[] = 'branch: ' . ($this->branch !== '' ? $this->branch : '(detached)');
            if ($this->modified) {
                $parts[] = 'modified: ' . implode(', ', $this->modified);
            }
            if ($this->untracked) {
                $parts[] = 'untracked: ' . implode(', ', $this->untracked);
            }
            if (!$this->hasChanges) {
                $parts[] = 'clean: true';
            }
        } else {
            $parts[] = 'git: not a git repository';
        }

        return implode("\n", $parts);
    }

    /**
     * 在工作目录下执行 shell 命令并返回 stdout
     *
     * @param string $command
     * @return string
     */
    protected function exec($command)
    {
        $cwd = $this->workdir;
        if ($cwd === '' || !is_dir($cwd)) {
            return '';
        }
        $output = [];
        $code = -1;
        $cmd = 'cd ' . escapeshellarg($cwd) . ' && ' . $command;
        exec($cmd, $output, $code);
        if ($code !== 0) {
            return '';
        }
        return implode("\n", $output);
    }
}