<?php
namespace Ai\Agent\Workspace;

/**
 * WorkspaceSnapshot——工作区状态快照
 *
 * 任务开始拍一张，任务结束再拍一张，两张一比就知道这个任务到底改了什么——
 * 而不是听模型自己报「我改了 Auth.php」。模型漏报和多报都很常见，
 * 尤其在它中途改了主意又改回去的时候。
 *
 * ```php
 * $before = WorkspaceSnapshot::capture('/var/www/project');
 * // …Agent 干活…
 * $after = WorkspaceSnapshot::capture('/var/www/project');
 *
 * $diff = WorkspaceSnapshot::diff($before, $after);
 * $diff['added'];     // 新增的文件
 * $diff['modified'];  // 改动的文件
 * $diff['deleted'];   // 删掉的文件
 * $diff['branch_changed'];  // 分支变了没有
 * ```
 *
 * 不是 git 仓库时快照仍然可用，只是 branch / commit / diff_hash 为空——
 * 能记的记下来，记不了的留空，不猜。
 */
class WorkspaceSnapshot
{
    /** @var string 工作目录 */
    protected $cwd = '';

    /** @var string 当前分支 */
    protected $branch = '';

    /** @var string 当前 commit（完整 SHA） */
    protected $commit = '';

    /** @var string[] 已修改但未提交的文件 */
    protected $modifiedFiles = [];

    /** @var string[] 未跟踪的文件 */
    protected $untrackedFiles = [];

    /** @var string 工作区 diff 的哈希——内容变没变一眼就能比出来 */
    protected $diffHash = '';

    /** @var bool 是否 git 仓库 */
    protected $isGitRepo = false;

    /** @var int 快照时间 */
    protected $capturedAt = 0;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        foreach (['cwd', 'branch', 'commit', 'diffHash'] as $key) {
            if (isset($data[$key])) {
                $this->$key = (string) $data[$key];
            }
        }
        foreach (['modifiedFiles', 'untrackedFiles'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $this->$key = array_values(array_map('strval', $data[$key]));
            }
        }
        $this->isGitRepo = !empty($data['isGitRepo']);
        $this->capturedAt = isset($data['capturedAt']) ? (int) $data['capturedAt'] : time();
    }

    /**
     * 给指定目录拍一张快照
     *
     * @param string $dir
     * @return self
     */
    public static function capture($dir)
    {
        $dir = rtrim(str_replace('\\', '/', (string) $dir), '/');
        $data = [
            'cwd'        => $dir,
            'capturedAt' => time(),
            'isGitRepo'  => $dir !== '' && is_dir($dir . '/.git'),
        ];

        if (!$data['isGitRepo']) {
            return new self($data);
        }

        $data['branch'] = trim(self::git($dir, 'rev-parse --abbrev-ref HEAD'));
        $data['commit'] = trim(self::git($dir, 'rev-parse HEAD'));

        $status = self::git($dir, 'status --porcelain');
        $modified = [];
        $untracked = [];
        foreach (preg_split('/\r?\n/', $status) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            // porcelain 的格式是「两位状态码 + 空格 + 路径」，状态码里的空格有含义，
            // 不能先 trim 整行——那会把未暂存改动的前导空格吃掉，路径跟着少一个字符
            $code = substr($line, 0, 2);
            $file = trim(substr($line, 2));
            if ($file === '') {
                continue;
            }
            if (strpos($code, '?') !== false) {
                $untracked[] = $file;
            } else {
                $modified[] = $file;
            }
        }
        $data['modifiedFiles'] = $modified;
        $data['untrackedFiles'] = $untracked;

        $diff = self::git($dir, 'diff HEAD');
        $data['diffHash'] = $diff === '' ? '' : md5($diff);

        return new self($data);
    }

    /**
     * 从 WorkspaceManager 拍快照（复用它已刷新的状态）
     *
     * @param WorkspaceManager $manager
     * @return self
     */
    public static function fromManager(WorkspaceManager $manager)
    {
        return self::capture($manager->getWorkdir());
    }

    /**
     * 比较两张快照
     *
     * @param self $before
     * @param self $after
     * @return array<string, mixed> added / deleted / modified / branch_changed / commit_changed / content_changed
     */
    public static function diff(self $before, self $after)
    {
        $beforeTouched = array_merge($before->getModifiedFiles(), $before->getUntrackedFiles());
        $afterTouched = array_merge($after->getModifiedFiles(), $after->getUntrackedFiles());

        return [
            'added'           => array_values(array_diff($after->getUntrackedFiles(), $before->getUntrackedFiles())),
            'deleted'         => array_values(array_diff($beforeTouched, $afterTouched)),
            'modified'        => array_values(array_diff($after->getModifiedFiles(), $before->getModifiedFiles())),
            'touched'         => array_values(array_unique(array_merge(
                array_diff($afterTouched, $beforeTouched),
                array_diff($beforeTouched, $afterTouched)
            ))),
            'branch_changed'  => $before->getBranch() !== $after->getBranch(),
            'commit_changed'  => $before->getCommit() !== $after->getCommit(),
            'content_changed' => $before->getDiffHash() !== $after->getDiffHash(),
        ];
    }

    /**
     * 与另一张快照相比有没有变化
     *
     * @param self $other
     * @return bool
     */
    public function differsFrom(self $other)
    {
        $diff = self::diff($other, $this);
        return $diff['content_changed']
            || $diff['commit_changed']
            || $diff['branch_changed']
            || $diff['touched'] !== [];
    }

    /** @return string */
    public function getCwd()
    {
        return $this->cwd;
    }

    /** @return string */
    public function getBranch()
    {
        return $this->branch;
    }

    /** @return string */
    public function getCommit()
    {
        return $this->commit;
    }

    /**
     * 短 commit（前 8 位）
     *
     * @return string
     */
    public function getShortCommit()
    {
        return $this->commit === '' ? '' : substr($this->commit, 0, 8);
    }

    /** @return string[] */
    public function getModifiedFiles()
    {
        return $this->modifiedFiles;
    }

    /** @return string[] */
    public function getUntrackedFiles()
    {
        return $this->untrackedFiles;
    }

    /** @return string */
    public function getDiffHash()
    {
        return $this->diffHash;
    }

    /** @return bool */
    public function isGitRepo()
    {
        return $this->isGitRepo;
    }

    /** @return int */
    public function getCapturedAt()
    {
        return $this->capturedAt;
    }

    /**
     * 工作区是不是干净的
     *
     * @return bool
     */
    public function isClean()
    {
        return $this->modifiedFiles === [] && $this->untrackedFiles === [];
    }

    /**
     * @return string
     */
    public function toSummary()
    {
        $lines = [];
        $lines[] = '工作区：' . $this->cwd;
        if ($this->isGitRepo) {
            $lines[] = '分支：' . $this->branch . '（' . $this->getShortCommit() . '）';
        } else {
            $lines[] = '（不是 git 仓库）';
        }
        if ($this->modifiedFiles) {
            $lines[] = '已修改：' . implode(', ', array_slice($this->modifiedFiles, 0, 10));
        }
        if ($this->untrackedFiles) {
            $lines[] = '未跟踪：' . implode(', ', array_slice($this->untrackedFiles, 0, 10));
        }
        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'cwd'            => $this->cwd,
            'branch'         => $this->branch,
            'commit'         => $this->commit,
            'modifiedFiles'  => $this->modifiedFiles,
            'untrackedFiles' => $this->untrackedFiles,
            'diffHash'       => $this->diffHash,
            'isGitRepo'      => $this->isGitRepo,
            'capturedAt'     => $this->capturedAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data)
    {
        return new self($data);
    }

    /**
     * 跑一条 git 命令
     *
     * 输出只做右侧裁剪：`git status --porcelain` 的行首空格是状态码的一部分，
     * 整体 trim 会把它吃掉，导致第一个文件名少一个字符。
     *
     * @param string $dir
     * @param string $args
     * @return string
     */
    protected static function git($dir, $args)
    {
        $output = [];
        $code = -1;
        @exec('cd ' . escapeshellarg($dir) . ' && git ' . $args . ' 2>/dev/null', $output, $code);
        return $code === 0 ? rtrim(implode("\n", $output)) : '';
    }
}
