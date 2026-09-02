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

    /** @var string 简短知识（frontmatter 的 knowledge 字段），匹配到场景时随描述一起注入 */
    protected $knowledge = '';

    /** @var string[] 触发该技能的文件通配符（frontmatter 的 files 字段） */
    protected $filePatterns = [];

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
        $this->knowledge    = isset($data['knowledge']) ? (string) $data['knowledge'] : '';
        $this->filePatterns = isset($data['filePatterns']) && is_array($data['filePatterns'])
            ? array_values(array_map('strval', $data['filePatterns']))
            : [];
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
            'loaded'       => $this->loaded,
            'active'       => $this->active,
        ];
    }
}
