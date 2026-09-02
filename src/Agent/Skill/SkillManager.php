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

    /** @var callable|null 生命周期事件回调 function(array $event): void */
    protected $emit = null;

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
            $skill = $this->buildFromFile($mdFile, $entry, true);
            if ($skill !== null) {
                $this->skills[$skill->getName()] = $skill;
            }
        }
        return $this;
    }

    /**
     * 订阅技能生命周期事件
     *
     * 事件：`skill_discovered` / `skill_loaded` / `skill_activated` / `skill_deactivated`。
     * 技能什么时候被加载、被谁激活，是排查「模型为什么突然按某套流程干活」的线索。
     *
     * @param callable|null $emit function(array $event): void
     * @return $this
     */
    public function onEvent($emit)
    {
        $this->emit = is_callable($emit) ? $emit : null;
        return $this;
    }

    /**
     * 停用一个已激活的技能
     *
     * 停用不会撤销已经进过上下文的内容——那已经在消息历史里了。
     * 它影响的是后续轮次：技能正文不再注入，allowed-tools 也不再计入。
     *
     * @param string $name
     * @return bool 技能不存在或本来就没激活返回 false
     */
    public function deactivate($name)
    {
        $skill = $this->get((string) $name);
        if ($skill === null || !$skill->isActive()) {
            return false;
        }
        $skill->setActive(false);

        // 重算允许工具：不能只减这一个技能的，别的技能可能声明了同样的工具
        $this->allowedTools = [];
        foreach ($this->activeSkills() as $active) {
            foreach ($active->getAllowedTools() as $rule) {
                $rule = trim((string) $rule);
                if ($rule !== '') {
                    $this->allowedTools[] = $rule;
                }
            }
        }

        $this->event('skill_deactivated', ['skill' => $skill->getName()]);
        return true;
    }

    /**
     * 检查技能声明的依赖是否满足
     *
     * `required_tools` 里的工具当前拿不到时，这个技能加载了也用不了——
     * 与其让模型按技能指示去调一个不存在的工具，不如提前说清楚。
     *
     * @param string $name
     * @param string[] $availableTools 当前可用的工具名
     * @return array{satisfied: bool, missing: string[]}
     */
    public function checkRequirements($name, array $availableTools)
    {
        $skill = $this->get((string) $name);
        if ($skill === null) {
            return ['satisfied' => false, 'missing' => []];
        }

        $missing = [];
        foreach ($skill->getRequiredTools() as $tool) {
            if (!in_array($tool, $availableTools, true)) {
                $missing[] = $tool;
            }
        }
        foreach ($skill->getDependencies() as $dependency) {
            if ($this->get($dependency) === null) {
                $missing[] = 'skill:' . $dependency;
            }
        }
        return ['satisfied' => $missing === [], 'missing' => $missing];
    }

    /**
     * 发出一个生命周期事件
     *
     * @param string $type
     * @param array<string, mixed> $data
     * @return void
     */
    protected function event($type, array $data = [])
    {
        if ($this->emit !== null) {
            call_user_func($this->emit, array_merge(['type' => $type], $data));
        }
    }

    /**
     * 发现目录下的技能，但不读正文
     *
     * 与 `loadFromDir()` 的区别：只解析 frontmatter，正文留到模型真正
     * `use_skill` 时再读盘。技能多、正文长时省的是内存不是 Context——
     * 两种方式注入系统提示词的内容是一样的。
     *
     * @param string $dir
     * @return string[] 发现的技能名
     */
    public function discover($dir)
    {
        $dir = rtrim(str_replace('\\', '/', (string) $dir), '/');
        if ($dir === '' || !is_dir($dir)) {
            return [];
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return [];
        }

        $found = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $mdFile = $dir . '/' . $entry . '/SKILL.md';
            if (!is_dir($dir . '/' . $entry) || !is_file($mdFile)) {
                continue;
            }
            $skill = $this->buildFromFile($mdFile, $entry, false);
            if ($skill !== null) {
                $this->skills[$skill->getName()] = $skill;
                $found[] = $skill->getName();
                $this->event('skill_discovered', [
                    'skill' => $skill->getName(),
                    'path'  => $mdFile,
                ]);
            }
        }
        return $found;
    }

    /**
     * 按名加载技能正文（不激活）
     *
     * 与 `useSkill()` 的区别：这个只把正文读进来，不把技能标成已激活、
     * 也不合并它的 allowed-tools。想让模型"用上"某技能时用 `useSkill()`。
     *
     * @param string $name
     * @return string 正文；技能不存在或读不到返回空串
     */
    public function loadByName($name)
    {
        $skill = $this->get((string) $name);
        if ($skill === null) {
            return '';
        }
        if (!$skill->isLoaded()) {
            $this->loadContent($skill);
        }
        return $skill->getContent();
    }

    /**
     * 找出适用于某个文件的技能
     *
     * 匹配依据是 frontmatter 里的 `files` 通配符。没配 `files` 的技能
     * 永远匹配不到——按技能名去猜文件路径太容易误伤。
     *
     * @param string $path
     * @return array<string, SkillDefinition>
     */
    public function forFile($path)
    {
        $matched = [];
        foreach ($this->skills as $name => $skill) {
            if ($skill->matchesFile($path)) {
                $matched[$name] = $skill;
            }
        }
        return $matched;
    }

    /**
     * 按文件路径自动激活匹配的技能
     *
     * Agent 打开某个文件时调一次，相关技能的正文就进了上下文，
     * 省掉模型自己判断"该不该 use_skill"这一步。
     *
     * @param string $path
     * @return string[] 被激活的技能名
     */
    public function activateForFile($path)
    {
        $activated = [];
        foreach ($this->forFile($path) as $name => $skill) {
            if ($skill->isActive()) {
                continue;
            }
            $this->useSkill($name);
            $activated[] = $name;
        }
        return $activated;
    }

    /**
     * 技能知识块——注入系统提示词
     *
     * 只包含 frontmatter 里的 `knowledge` 字段（几行要点），不是完整正文。
     * 正文仍然要模型主动 `use_skill` 才加载。
     *
     * @param bool $activeOnly true 时只输出已激活技能的知识
     * @return string
     */
    public function knowledgeForPrompt($activeOnly = false)
    {
        if (!$this->enabled) {
            return '';
        }
        $parts = [];
        foreach ($this->skills as $skill) {
            if ($activeOnly && !$skill->isActive()) {
                continue;
            }
            $knowledge = trim($skill->getKnowledge());
            if ($knowledge !== '') {
                $parts[] = '## ' . $skill->getName() . "\n" . $knowledge;
            }
        }
        if (!$parts) {
            return '';
        }
        return "<skill-knowledge>\n" . implode("\n\n", $parts) . "\n</skill-knowledge>";
    }

    /**
     * 从 SKILL.md 造一个技能定义
     *
     * @param string $mdFile
     * @param string $fallbackName 目录名，frontmatter 没写 name 时用它
     * @param bool $withContent 是否连正文一起读进来
     * @return SkillDefinition|null
     */
    protected function buildFromFile($mdFile, $fallbackName, $withContent)
    {
        $raw = @file_get_contents($mdFile);
        if ($raw === false) {
            return null;
        }
        $parsed = self::parseFrontmatter($raw);
        $fm = $parsed['meta'];

        $name = isset($fm['name']) ? (string) $fm['name'] : (string) $fallbackName;
        $allowed = isset($fm['allowed-tools']) && is_array($fm['allowed-tools'])
            ? array_values(array_map('strval', $fm['allowed-tools']))
            : [];
        $patterns = [];
        foreach (['files', 'paths'] as $key) {
            if (isset($fm[$key])) {
                $patterns = array_merge(
                    $patterns,
                    is_array($fm[$key]) ? array_map('strval', $fm[$key]) : [(string) $fm[$key]]
                );
            }
        }

        $listField = function ($key) use ($fm) {
            if (!isset($fm[$key])) {
                return [];
            }
            return is_array($fm[$key]) ? array_map('strval', $fm[$key]) : [(string) $fm[$key]];
        };

        return new SkillDefinition([
            'name'          => $name,
            'description'   => isset($fm['description']) ? (string) $fm['description'] : '',
            'content'       => $withContent ? $parsed['content'] : '',
            'knowledge'     => isset($fm['knowledge']) ? (string) $fm['knowledge'] : '',
            'allowedTools'  => $allowed,
            'requiredTools' => array_merge($listField('required-tools'), $listField('required_tools')),
            'dependencies'  => $listField('dependencies'),
            'filePatterns'  => $patterns,
            'path'          => $mdFile,
        ]);
    }

    /**
     * 从磁盘补上技能正文
     *
     * @param SkillDefinition $skill
     * @return bool
     */
    protected function loadContent(SkillDefinition $skill)
    {
        $path = $skill->getPath();
        if ($path === '' || !is_file($path)) {
            return false;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return false;
        }
        $parsed = self::parseFrontmatter($raw);
        $skill->setContent($parsed['content']);
        $this->event('skill_loaded', ['skill' => $skill->getName(), 'path' => $path]);
        return true;
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
        $blockKey = '';
        $blockLines = [];

        foreach (explode("\n", $fmBlock) as $line) {
            $line = rtrim($line, "\r");

            // 块标量正文：`knowledge: |` 之后的缩进行，原样保留
            if ($blockKey !== '') {
                if ($line === '' || preg_match('/^\s/', $line)) {
                    $blockLines[] = preg_replace('/^ {1,2}/', '', $line);
                    continue;
                }
                $meta[$blockKey] = trim(implode("\n", $blockLines));
                $blockKey = '';
                $blockLines = [];
            }

            // 缩进列表项：- item
            if (preg_match('/^\s+-\s+(.+)$/', $line, $mm)) {
                if ($currentKey !== '') {
                    $listItems[] = trim($mm[1], '"\'' );
                }
                continue;
            }

            // 普通键：key: value 或 key: 或 key: |
            if (preg_match('/^([a-zA-Z0-9_-]+):\s*(.*)$/', $line, $mm)) {
                // 收尾上一个键的列表
                if ($currentKey !== '' && $listItems) {
                    $meta[$currentKey] = $listItems;
                }
                $currentKey = $mm[1];
                $listItems = [];
                $value = trim($mm[2], '"\'' );
                if ($value === '|' || $value === '>' || $value === '|-' || $value === '>-') {
                    $blockKey = $currentKey;
                    $blockLines = [];
                    $currentKey = '';
                    continue;
                }
                if ($value !== '') {
                    $meta[$currentKey] = $value;
                    $currentKey = '';  // 标量已存，后续列表项不再归它
                }
            }
        }

        // 收尾最后一组列表 / 块标量
        if ($currentKey !== '' && $listItems) {
            $meta[$currentKey] = $listItems;
        }
        if ($blockKey !== '') {
            $meta[$blockKey] = trim(implode("\n", $blockLines));
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
        if (!$skill->isLoaded()) {
            $this->loadContent($skill);
        }
        $wasActive = $skill->isActive();
        $skill->setActive(true);
        if (!$wasActive) {
            $this->event('skill_activated', ['skill' => $skill->getName()]);
        }

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