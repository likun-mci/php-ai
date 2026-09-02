<?php
namespace Ai\Agent\Tool;

/**
 * ToolGroup——工具分组
 *
 * 工具多了不该一股脑全发给模型：几十个工具定义塞进每一轮请求，
 * 既占 token 又让模型更难选对。按用途分组之后可以整组开关。
 *
 * ```php
 * $groups = new ToolGroup();
 * $groups->assign('git', ['bash']);
 * $groups->disable('deployment');       // 这次任务不允许碰部署
 *
 * $groups->isEnabled('read_file');      // true
 * $groups->filter($allTools);           // 只留下启用分组里的工具
 * ```
 *
 * 内置分组按用途划分：filesystem / git / database / network / browser /
 * cloud / testing / deployment。没归过组的工具默认可用——分组是用来**收窄**的，
 * 不该因为忘了归类就让工具消失。
 */
class ToolGroup
{
    const FILESYSTEM = 'filesystem';
    const GIT        = 'git';
    const DATABASE   = 'database';
    const NETWORK    = 'network';
    const BROWSER    = 'browser';
    const CLOUD      = 'cloud';
    const TESTING    = 'testing';
    const DEPLOYMENT = 'deployment';

    /** @var array<string, string[]> 分组 => 工具名 */
    protected $groups = [];

    /** @var array<string, bool> 分组 => 是否启用 */
    protected $enabled = [];

    /** @var array<string, string[]> 内置分组的默认成员 */
    protected static $defaults = [
        self::FILESYSTEM => ['read_file', 'write_file', 'edit_file', 'glob', 'grep', 'code_index'],
        self::GIT        => ['git_status', 'git_diff', 'git_commit'],
        self::DATABASE   => ['sql_query', 'db_schema'],
        self::NETWORK    => ['http_fetch', 'web_search'],
        self::BROWSER    => ['browser'],
        self::CLOUD      => ['s3_put', 's3_get'],
        self::TESTING    => ['run_tests'],
        self::DEPLOYMENT => ['deploy', 'rollback'],
    ];

    /**
     * @param array<string, string[]> $groups 自定义分组，不传则用内置分组
     */
    public function __construct(array $groups = [])
    {
        $this->groups = $groups ? $groups : self::$defaults;
        foreach (array_keys($this->groups) as $name) {
            $this->enabled[$name] = true;
        }
    }

    /**
     * 内置分组名
     *
     * @return string[]
     */
    public static function builtinNames()
    {
        return array_keys(self::$defaults);
    }

    /**
     * 把工具归入某个分组
     *
     * @param string $group
     * @param string|string[] $tools
     * @return $this
     */
    public function assign($group, $tools)
    {
        $group = (string) $group;
        if ($group === '') {
            return $this;
        }
        if (!isset($this->groups[$group])) {
            $this->groups[$group] = [];
            $this->enabled[$group] = true;
        }
        foreach (is_array($tools) ? $tools : [$tools] as $tool) {
            $tool = (string) $tool;
            if ($tool !== '' && !in_array($tool, $this->groups[$group], true)) {
                $this->groups[$group][] = $tool;
            }
        }
        return $this;
    }

    /**
     * 启用一个分组
     *
     * @param string $group
     * @return $this
     */
    public function enable($group)
    {
        $this->enabled[(string) $group] = true;
        return $this;
    }

    /**
     * 停用一个分组
     *
     * @param string $group
     * @return $this
     */
    public function disable($group)
    {
        $this->enabled[(string) $group] = false;
        return $this;
    }

    /**
     * 只启用这几个分组，其余全停
     *
     * @param string[] $groups
     * @return $this
     */
    public function only(array $groups)
    {
        foreach (array_keys($this->enabled) as $name) {
            $this->enabled[$name] = in_array($name, $groups, true);
        }
        foreach ($groups as $name) {
            if (!isset($this->groups[(string) $name])) {
                $this->groups[(string) $name] = [];
            }
            $this->enabled[(string) $name] = true;
        }
        return $this;
    }

    /**
     * 分组是否启用
     *
     * @param string $group
     * @return bool 未知分组返回 true（没归过组的不该被误伤）
     */
    public function isGroupEnabled($group)
    {
        $group = (string) $group;
        return !isset($this->enabled[$group]) || $this->enabled[$group];
    }

    /**
     * 某个工具当前可用吗
     *
     * 没归过组的工具一律可用——分组用来收窄，不该因为忘了归类就让工具消失。
     *
     * @param string $tool
     * @return bool
     */
    public function isEnabled($tool)
    {
        $tool = (string) $tool;
        $found = false;

        foreach ($this->groups as $group => $tools) {
            if (!in_array($tool, $tools, true)) {
                continue;
            }
            $found = true;
            // 属于任一启用分组即可用
            if ($this->isGroupEnabled($group)) {
                return true;
            }
        }
        return !$found;
    }

    /**
     * 过滤一批工具，只留下当前可用的
     *
     * @param array<string, mixed> $tools 工具名 => 定义
     * @return array<string, mixed>
     */
    public function filter(array $tools)
    {
        $filtered = [];
        foreach ($tools as $name => $tool) {
            if ($this->isEnabled((string) $name)) {
                $filtered[$name] = $tool;
            }
        }
        return $filtered;
    }

    /**
     * 某个工具属于哪些分组
     *
     * @param string $tool
     * @return string[]
     */
    public function groupsOf($tool)
    {
        $tool = (string) $tool;
        $found = [];
        foreach ($this->groups as $group => $tools) {
            if (in_array($tool, $tools, true)) {
                $found[] = (string) $group;
            }
        }
        return $found;
    }

    /**
     * 某个分组里有哪些工具
     *
     * @param string $group
     * @return string[]
     */
    public function toolsIn($group)
    {
        $group = (string) $group;
        return isset($this->groups[$group]) ? $this->groups[$group] : [];
    }

    /**
     * 全部分组
     *
     * @return array<string, string[]>
     */
    public function all()
    {
        return $this->groups;
    }

    /**
     * 当前启用的分组
     *
     * @return string[]
     */
    public function enabledGroups()
    {
        $names = [];
        foreach ($this->groups as $group => $tools) {
            if ($this->isGroupEnabled($group)) {
                $names[] = (string) $group;
            }
        }
        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'groups'  => $this->groups,
            'enabled' => $this->enabled,
        ];
    }
}
