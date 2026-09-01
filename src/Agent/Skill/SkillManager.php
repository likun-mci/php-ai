<?php
namespace Ai\Agent\Skill;

/**
 * SkillManager——技能管理器
 *
 * 管理一组 Skill，并提供一个 use_skill 工具让模型按需加载完整内容。
 * 默认只把「名称 + 描述」注入系统提示词（节省 Context），
 * 模型需要某项能力时调用 use_skill(name) 加载完整正文。
 *
 * 目录约定（加载文件系统技能时）：
 * ```text
 * skills/
 * ├── wordpress/SKILL.md
 * ├── seo/SKILL.md
 * └── deploy/SKILL.md
 * ```
 *
 * SKILL.md 支持 YAML frontmatter（name / description / allowed-tools）：
 * ```markdown
 * ---
 * name: deploy
 * description: 部署项目到生产环境
 * allowed-tools:
 *   - Bash(git *)
 *   - Bash(docker *)
 * ---
 * # 部署流程
 * ...
 * ```
 *
 * 用法：
 * ```php
 * $sm = new SkillManager();
 * $sm->register('deploy', [
 *     'description'  => '部署项目到生产环境',
 *     'content'      => "# 部署流程\n1. ...",
 *     'allowedTools' => ['Bash(git *)'],
 * ]);
 * $sm->loadFromDir('/path/to/skills');
 * echo $sm->toSystemPrompt();       // 注入系统提示词的描述列表
 * $sm->getUseSkillToolSchema();     // use_skill 工具元数据
 * $sm->getUseSkillHandler();        // use_skill 工具的 handler
 * ```
 */
class SkillManager
{
    /** @var array<string, SkillDefinition> */
    protected $skills = [];

    /** @var bool 是否启用 use_skill 工具 */
    protected $enabled = true;

    /** @var string[] 已激活技能的允许工具（合并自 allowedTools） */
    protected $allowedTools = [];

    /**
     * 注册一个技能
     *
     * @param string $name
     * @param array<string, mixed> $config description / content / allowedTools / path
     * @return $this
     */
    public function register($name, array $config = [])
    {
        $config['name'] = (string) $name;
        $this->skills[(string) $name] = new SkillDefinition($config);
        return $this;
    }

    /**
     * 获取技能
     *
     * @param string $name
     * @return SkillDefinition|null
     */
    public function get($name)
    {
        return isset($this->skills[(string) $name]) ? $this->skills[(string) $name] : null;
    }

    /**
     * 全部技能
     *
     * @return array<string, SkillDefinition>
     */
    public function all()
    {
        return $this->skills;
    }

    /**
     * 技能数量
     *
     * @return int
     */
    public function count()
    {
        return count($this->skills);
    }

    /**
     * 是否有指定技能
     *
     * @param string $name
     * @return bool
     */
    public function has($name)
    {
        return isset($this->skills[(string) $name]);
    }

    /**
     * 启用/停用技能系统
     *
     * @param bool $enabled
     * @return $this
     */
    public function setEnabled($enabled)
    {
        $this->enabled = (bool) $enabled;
        return $this;
    }

    /** @return bool */
    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * 从目录加载技能（每个子目录下的 SKILL.md）
     *
     * 支持目录约定：{dir}/{skill-name}/SKILL.md
     * frontmatter 解析 name / description / allowed-tools。
     *
     * @param string $dir
     * @return $this
     */
    public function loadFromDir($dir)
    {
        $dir = rtrim(str_replace('\\', '/', (string) $dir), '/');
        if ($dir === '' || !is_dir($dir)) {
            return $this;
        }

        $entries = @scandir($dir);
        if ($entries === false) {
            return $this;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $skillDir = $dir . '/' . $entry;
            if (!is_dir($skillDir)) {
                continue;
            }
            $mdFile = $skillDir . '/SKILL.md';
            if (!is_file($mdFile)) {
                continue;
            }
            $raw = @file_get_contents($mdFile);
            if ($raw === false) {
                continue;
            }
            $meta = self::parseFrontmatter($raw);
            $content = $meta['content'];
            $fm = $meta['meta'];

            $name = isset($fm['name']) ? (string) $fm['name'] : $entry;
            $desc = isset($fm['description']) ? (string) $fm['description'] : '';
            $allowed = isset($fm['allowed-tools']) && is_array($fm['allowed-tools'])
                ? array_values(array_map('strval', $fm['allowed-tools']))
                : [];

            $this->skills[(string) $name] = new SkillDefinition([
                'name'         => $name,
                'description'  => $desc,
                'content'      => $content,
                'allowedTools' => $allowed,
                'path'         => $mdFile,
            ]);
        }
        return $this;
    }

    /**
     * 解析 SKILL.md 的 frontmatter
     *
     * 单趟解析：识别标量键（key: value）、键下缩进的列表项（- item）。
     *
     * @param string $raw
     * @return array{meta: array<string, mixed>, content: string}
     */
    public static function parseFrontmatter($raw)
    {
        $content = (string) $raw;
        $meta = [];

        if (!preg_match('/^---\r?\n(.*?)\r?\n---\r?\n?(.*)$/s', $content, $m)) {
            return ['meta' => $meta, 'content' => trim($content)];
        }

        $fmBlock = $m[1];
        $content = $m[2];

        $currentKey = '';
        $listItems = [];

        foreach (explode("\n", $fmBlock) as $line) {
            $line = rtrim($line, "\r");

            // 缩进列表项：- item
            if (preg_match('/^\s+-\s+(.+)$/', $line, $mm)) {
                if ($currentKey !== '') {
                    $listItems[] = trim($mm[1], '"\'' );
                }
                continue;
            }

            // 普通键：key: value 或 key:
            if (preg_match('/^([a-zA-Z0-9_-]+):\s*(.*)$/', $line, $mm)) {
                // 收尾上一个键的列表
                if ($currentKey !== '' && $listItems) {
                    $meta[$currentKey] = $listItems;
                }
                $currentKey = $mm[1];
                $listItems = [];
                $value = trim($mm[2], '"\'' );
                if ($value !== '') {
                    $meta[$currentKey] = $value;
                    $currentKey = '';  // 标量已存，后续列表项不再归它
                }
            }
        }

        // 收尾最后一组列表
        if ($currentKey !== '' && $listItems) {
            $meta[$currentKey] = $listItems;
        }

        return ['meta' => $meta, 'content' => trim($content)];
    }

    /**
     * 生成注入系统提示词的技能描述（只有名称与描述）
     *
     * @return string
     */
    public function toSystemPrompt()
    {
        if (!$this->enabled || !$this->skills) {
            return '';
        }
        $lines = ['可用的技能（需要时调用 use_skill 加载完整内容）：'];
        foreach ($this->skills as $skill) {
            $lines[] = $skill->toDescriptionLine();
        }
        return implode("\n", $lines);
    }

    /**
     * 加载并激活一个技能，返回完整内容
     *
     * @param string $name
     * @return string 完整技能内容；不存在返回空字符串
     */
    public function useSkill($name)
    {
        $skill = $this->get((string) $name);
        if ($skill === null) {
            return '';
        }
        // 从文件读取时内容可能尚未加载
        if (!$skill->isLoaded() && $skill->getPath() !== '' && is_file($skill->getPath())) {
            $raw = @file_get_contents($skill->getPath());
            if ($raw !== false) {
                $meta = self::parseFrontmatter($raw);
                $skill->setContent($meta['content']);
            }
        }
        $skill->setActive(true);

        // 收集允许工具限制（合并到全局，但不能突破权限系统）
        foreach ($skill->getAllowedTools() as $rule) {
            $rule = trim((string) $rule);
            if ($rule !== '') {
                $this->allowedTools[] = $rule;
            }
        }
        return $skill->getContent();
    }

    /**
     * 已激活技能
     *
     * @return array<string, SkillDefinition>
     */
    public function activeSkills()
    {
        $active = [];
        foreach ($this->skills as $name => $skill) {
            if ($skill->isActive()) {
                $active[$name] = $skill;
            }
        }
        return $active;
    }

    /**
     * 全部已收集的工具限制（来自激活技能）
     *
     * @return string[]
     */
    public function getAllowedTools()
    {
        return $this->allowedTools;
    }

    /**
     * use_skill 工具的 schema
     *
     * @return array<string, mixed>
     */
    public function getUseSkillToolSchema()
    {
        $names = [];
        foreach ($this->skills as $name => $skill) {
            $names[] = (string) $name;
        }

        return [
            'name'        => 'use_skill',
            'description' => '加载一个技能（Skill）的完整内容。'
                . '技能是某项能力或工作流程的详细指令。'
                . '当前可用的技能：' . implode(', ', $names),
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'skill' => [
                        'type'        => 'string',
                        'description' => '要加载的技能名称',
                        'enum'        => $names,
                    ],
                ],
                'required' => ['skill'],
            ],
        ];
    }

    /**
     * use_skill 工具的 handler
     *
     * @return callable
     */
    public function getUseSkillHandler()
    {
        $self = $this;
        return function (array $input) use ($self) {
            $name = isset($input['skill']) ? (string) $input['skill'] : '';
            if ($name === '') {
                return 'ERROR: 请指定要加载的技能名称';
            }
            $content = $self->useSkill($name);
            if ($content === '') {
                return 'ERROR: 技能 "' . $name . '" 不存在';
            }
            return $content;
        };
    }
}