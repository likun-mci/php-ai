<?php
namespace Ai\Agent\Skill;

/**
 * SkillDefinition——技能定义值对象
 *
 * 一个 Skill 是一份带 frontmatter 的 Markdown 文档（SKILL.md），
 * 描述某项能力 / 工作流程。frontmatter 提供元数据（名称、描述、可用工具），
 * 正文是完整的技能指令。
 *
 * 默认只把名称与描述提供给模型（节省 Context），
 * 模型需要时通过 use_skill 工具加载完整正文。
 *
 * 技能目录里的**附带文件**（Anthropic 官方技能约定里的 `references/*.md`、
 * `scripts/*`、`assets/*`）是技能的第二层正文：SKILL.md 只说明「遇到 X 时
 * 去看 references/y.md」，真正的方法论在被引用的文件里。这些文件靠
 * `getDir()` / `getResources()` 暴露，读取走 `SkillManager::readResource()`。
 *
 * 用法：
 * ```php
 * $skill = new SkillDefinition([
 *     'name'        => 'deploy',
 *     'description' => '部署项目到生产环境',
 *     'content'     => "# 部署流程\n\n1. 构建...",
 *     'allowedTools' => ['Bash(git *)', 'Bash(docker *)'],
 * ]);
 * echo $skill->getName();        // 'deploy'
 * echo $skill->getDescription(); // '部署项目到生产环境'
 *
 * // 从目录加载的技能才有附带文件
 * $skill->getResources();        // ['references/a.md', 'references/b.md']
 * ```
 */
class SkillDefinition
{
    /** @var string */
    protected $name = '';

    /** @var string */
    protected $description = '';

    /** @var string 完整正文（加载后才有） */
    protected $content = '';

    /** @var string[] 工具限制（可选，不能突破全局权限） */
    protected $allowedTools = [];

    /** @var string 来源路径 */
    protected $path = '';

    /** @var string 技能目录（SKILL.md 所在目录，注释见 getDir()） */
    protected $dir = '';

    /** @var string[]|null 附带文件清单缓存（相对技能目录的路径） */
    protected $resources = null;

    /**
     * @var int 单个附带文件体积上限（字节），超过的不列进清单；0 = 不限
     *
     * 默认 1MB：清单是给模型看的，列一个读不动的文件只会诱发一次徒劳的读取。
     */
    protected $resourceMaxBytes = 1048576;

    /** @var string 简短知识（frontmatter 的 knowledge 字段），匹配到场景时随描述一起注入 */
    protected $knowledge = '';

    /** @var string[] 触发该技能的文件通配符（frontmatter 的 files 字段） */
    protected $filePatterns = [];

    /** @var string[] 该技能必须有的工具，拿不到就用不了 */
    protected $requiredTools = [];

    /** @var string[] 该技能依赖的其它技能 */
    protected $dependencies = [];

    /** @var bool 完整内容是否已加载 */
    protected $loaded = false;

    /** @var bool 是否已被模型激活 */
    protected $active = false;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->name         = isset($data['name']) ? (string) $data['name'] : '';
        $this->description  = isset($data['description']) ? (string) $data['description'] : '';
        $this->content      = isset($data['content']) ? (string) $data['content'] : '';
        $this->allowedTools = isset($data['allowedTools']) && is_array($data['allowedTools'])
            ? array_values($data['allowedTools'])
            : [];
        $this->path         = isset($data['path']) ? (string) $data['path'] : '';
        $this->dir          = isset($data['dir']) ? (string) $data['dir'] : '';
        if (isset($data['resources']) && is_array($data['resources'])) {
            $this->setResources($data['resources']);
        }
        if (isset($data['resourceMaxBytes'])) {
            $this->setResourceMaxBytes($data['resourceMaxBytes']);
        }
        $this->knowledge    = isset($data['knowledge']) ? (string) $data['knowledge'] : '';
        $this->filePatterns = isset($data['filePatterns']) && is_array($data['filePatterns'])
            ? array_values(array_map('strval', $data['filePatterns']))
            : [];
        foreach (['requiredTools', 'dependencies'] as $listKey) {
            if (isset($data[$listKey]) && is_array($data[$listKey])) {
                $this->$listKey = array_values(array_unique(array_map('strval', $data[$listKey])));
            }
        }
        $this->loaded       = $this->content !== '';
        $this->active       = !empty($data['active']);
    }

    /** @return string */
    public function getName()
    {
        return $this->name;
    }

    /** @return string */
    public function getDescription()
    {
        return $this->description;
    }

    /** @return string */
    public function getContent()
    {
        return $this->content;
    }

    /** @return string[] */
    public function getAllowedTools()
    {
        return $this->allowedTools;
    }

    /** @return string */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * 技能目录
     *
     * 显式设过就用显式的；否则从 `path`（SKILL.md 路径）推。
     * 只给了文件名（没有目录部分）时返回空串——那种情况下 `dirname()` 会
     * 得到 `.`，把当前工作目录当成技能目录去扫，不是我们想要的。
     *
     * @return string 目录绝对/相对路径；无从得知返回空串
     */
    public function getDir()
    {
        if ($this->dir !== '') {
            return $this->dir;
        }
        if ($this->path === '') {
            return '';
        }
        $dir = dirname(str_replace('\\', '/', $this->path));
        return ($dir === '.' || $dir === '/' || $dir === '') ? '' : $dir;
    }

    /**
     * @param string $dir
     * @return $this
     */
    public function setDir($dir)
    {
        $this->dir      = (string) $dir;
        $this->resources = null;  // 目录换了，旧的清单作废
        return $this;
    }

    /**
     * 附带文件清单（相对技能目录的路径，已排序）
     *
     * 懒扫描：第一次调用时遍历技能目录，之后走缓存（`$reload = true` 强制重扫）。
     * 只列文件名不读内容，几十个附件的开销可以忽略。
     *
     * 入列规则：
     *  - 只列普通文件，目录本身不列（层级由文件路径体现）
     *  - 跳过任何以 `.` 开头的路径段（`.git`、`.DS_Store` 等）
     *  - 跳过根目录的 `SKILL.md`（正文由 use_skill 返回，不必当附件）
     *  - 跳过超过 `resourceMaxBytes` 的大文件
     *
     * @param bool $reload 是否强制重扫
     * @return string[] 相对路径，如 ['references/a.md', 'scripts/run.sh']
     */
    public function getResources($reload = false)
    {
        if ($this->resources === null || $reload) {
            $this->resources = $this->scanResources();
        }
        return $this->resources;
    }

    /**
     * 直接给定清单（宿主自己有过索引时可省掉一次扫盘）
     *
     * @param string[] $resources 相对技能目录的路径
     * @return $this
     */
    public function setResources(array $resources)
    {
        $clean = [];
        foreach ($resources as $rel) {
            $rel = trim(str_replace('\\', '/', (string) $rel), '/');
            if ($rel !== '') {
                $clean[] = $rel;
            }
        }
        sort($clean, SORT_STRING);
        $this->resources = $clean;
        return $this;
    }

    /** @return bool 是否有附带文件 */
    public function hasResources()
    {
        return $this->getResources() !== [];
    }

    /**
     * @param int $bytes 0 = 不限
     * @return $this
     */
    public function setResourceMaxBytes($bytes)
    {
        $this->resourceMaxBytes = max(0, (int) $bytes);
        $this->resources = null;  // 体积上限参与筛选，清单作废
        return $this;
    }

    /** @return int */
    public function getResourceMaxBytes()
    {
        return $this->resourceMaxBytes;
    }

    /**
     * 扫盘得到附带文件清单
     *
     * @return string[]
     */
    protected function scanResources()
    {
        $dir = $this->getDir();
        if ($dir === '' || !is_dir($dir)) {
            return [];
        }
        $files = [];
        $this->walkResources($dir, '', $files, 0);
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * 递归收集附带文件
     *
     * 深度上限 16 纯粹是防御性的：软链接成环时 scandir 会一直往下走。
     *
     * @param string   $absDir
     * @param string   $prefix 相对技能目录的前缀
     * @param string[] $files  收集结果（引用传递）
     * @param int      $depth
     * @return void
     */
    protected function walkResources($absDir, $prefix, array &$files, $depth)
    {
        if ($depth > 16) {
            return;
        }
        $entries = @scandir($absDir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || strpos($entry, '.') === 0) {
                continue;
            }
            $abs = $absDir . '/' . $entry;
            $rel = $prefix === '' ? $entry : $prefix . '/' . $entry;
            if (is_dir($abs)) {
                $this->walkResources($abs, $rel, $files, $depth + 1);
                continue;
            }
            if (!is_file($abs) || ($prefix === '' && $entry === 'SKILL.md')) {
                continue;
            }
            if ($this->resourceMaxBytes > 0) {
                $size = @filesize($abs);
                if ($size !== false && $size > $this->resourceMaxBytes) {
                    continue;
                }
            }
            $files[] = $rel;
        }
    }

    /**
     * 标记完整内容已加载
     *
     * @param string $content
     * @return $this
     */
    public function setContent($content)
    {
        $this->content = (string) $content;
        $this->loaded  = $this->content !== '';
        return $this;
    }

    /** @return bool */
    public function isLoaded()
    {
        return $this->loaded;
    }

    /** @return bool */
    public function isActive()
    {
        return $this->active;
    }

    /**
     * @param bool $active
     * @return $this
     */
    public function setActive($active = true)
    {
        $this->active = (bool) $active;
        return $this;
    }

    /**
     * 紧凑的描述（注入系统提示词用）
     *
     * @return string
     */
    public function toDescriptionLine()
    {
        $line = '- ' . $this->name;
        if ($this->description !== '') {
            $line .= ': ' . $this->description;
        }
        return $line;
    }

    /**
     * 简短知识——比完整正文短得多，可以在匹配到场景时直接注入
     *
     * @return string
     */
    public function getKnowledge()
    {
        return $this->knowledge;
    }

    /**
     * @param string $knowledge
     * @return $this
     */
    public function setKnowledge($knowledge)
    {
        $this->knowledge = (string) $knowledge;
        return $this;
    }

    /**
     * 触发该技能的文件通配符
     *
     * @return string[]
     */
    public function getFilePatterns()
    {
        return $this->filePatterns;
    }

    /**
     * 该技能是否适用于指定文件
     *
     * 通配符按 `fnmatch()` 匹配，同时对纯路径片段做包含匹配——
     * `wp-content` 这样的写法比 `*wp-content*` 更符合直觉。
     *
     * @param string $path
     * @return bool 没有配置通配符时返回 false
     */
    public function matchesFile($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        if ($path === '' || !$this->filePatterns) {
            return false;
        }
        foreach ($this->filePatterns as $pattern) {
            $pattern = str_replace('\\', '/', $pattern);
            if ($pattern === '') {
                continue;
            }
            if (strpos($pattern, '*') === false && strpos($pattern, '?') === false) {
                if (strpos($path, $pattern) !== false) {
                    return true;
                }
                continue;
            }
            if (fnmatch($pattern, $path) || fnmatch($pattern, basename($path))) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'name'         => $this->name,
            'description'  => $this->description,
            'knowledge'    => $this->knowledge,
            'allowedTools' => $this->allowedTools,
            'filePatterns' => $this->filePatterns,
            'path'         => $this->path,
            'dir'          => $this->getDir(),
            'loaded'       => $this->loaded,
            'active'       => $this->active,
        ];
    }

    /**
     * 该技能必须有的工具
     *
     * @return string[]
     */
    public function getRequiredTools()
    {
        return $this->requiredTools;
    }

    /**
     * 该技能依赖的其它技能
     *
     * @return string[]
     */
    public function getDependencies()
    {
        return $this->dependencies;
    }
}
