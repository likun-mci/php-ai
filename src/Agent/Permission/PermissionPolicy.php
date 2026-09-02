<?php
namespace Ai\Agent\Permission;

/**
 * PermissionPolicy——分层权限策略
 *
 * 权限来自四个层面：全局配置、当前 Agent、已激活的 Skill、当前任务。
 * 它们的关系是**取交集**，不是取并集：
 *
 * ```text
 * 最终权限 = Global AND Agent AND Skill AND Task
 * ```
 *
 * 并且 **DENY 优先**——任何一层说不行就是不行。这个顺序不能反过来：
 * 如果允许下层放宽上层，那么一个被 Skill 声明允许的 `Bash(rm -rf *)`
 * 就能绕过全局禁令。
 *
 * ```php
 * $policy = new PermissionPolicy();
 * $policy->layer('global')->allow('Bash(git *)')->deny('Bash(rm -rf *)');
 * $policy->layer('task')->allow('Bash(git status)');
 *
 * $policy->check('Bash', 'git status');   // 'allow'
 * $policy->check('Bash', 'rm -rf /');     // 'deny'  —— 全局禁令挡下
 * $policy->check('Bash', 'curl x.com');   // 'ask'   —— 没人明确允许
 * ```
 */
class PermissionPolicy
{
    const ALLOW = 'allow';
    const DENY  = 'deny';
    const ASK   = 'ask';

    /** 层级顺序：越靠前越基础，任何一层的 deny 都是最终 deny */
    const LAYER_GLOBAL = 'global';
    const LAYER_AGENT  = 'agent';
    const LAYER_SKILL  = 'skill';
    const LAYER_TASK   = 'task';

    /** @var array<string, array<string, string[]>> 层 => ['allow' => [...], 'deny' => [...]] */
    protected $layers = [];

    /** @var array<string, string> 工具名 => 参数字段名，用于把规则翻译成 PermissionRule */
    protected static $argKeys = [
        'bash'       => 'command',
        'write_file' => 'file_path',
        'edit_file'  => 'file_path',
        'read_file'  => 'file_path',
        'glob'       => 'pattern',
        'grep'       => 'pattern',
    ];

    /** @var string 名字当前层的临时游标（供链式 layer()->allow() 用） */
    protected $cursor = self::LAYER_GLOBAL;

    /**
     * @param array<string, array<string, string[]>> $layers 预置规则
     */
    public function __construct(array $layers = [])
    {
        foreach ([self::LAYER_GLOBAL, self::LAYER_AGENT, self::LAYER_SKILL, self::LAYER_TASK] as $name) {
            $this->layers[$name] = ['allow' => [], 'deny' => []];
        }
        foreach ($layers as $name => $rules) {
            $name = (string) $name;
            if (!isset($this->layers[$name])) {
                $this->layers[$name] = ['allow' => [], 'deny' => []];
            }
            foreach (['allow', 'deny'] as $kind) {
                if (isset($rules[$kind]) && is_array($rules[$kind])) {
                    $this->layers[$name][$kind] = array_values(array_map('strval', $rules[$kind]));
                }
            }
        }
    }

    /**
     * 切到某一层（链式写规则用）
     *
     * @param string $layer
     * @return $this
     */
    public function layer($layer)
    {
        $layer = (string) $layer;
        if (!isset($this->layers[$layer])) {
            $this->layers[$layer] = ['allow' => [], 'deny' => []];
        }
        $this->cursor = $layer;
        return $this;
    }

    /**
     * 在当前层加一条允许规则
     *
     * 规则形如 `Bash(git *)`、`Write(src/*)`、`read_file`。
     *
     * @param string|string[] $patterns
     * @return $this
     */
    public function allow($patterns)
    {
        return $this->addRule('allow', $patterns);
    }

    /**
     * 在当前层加一条禁止规则
     *
     * @param string|string[] $patterns
     * @return $this
     */
    public function deny($patterns)
    {
        return $this->addRule('deny', $patterns);
    }

    /**
     * 判定一次调用
     *
     * @param string $tool 工具名
     * @param string $argument 关键参数（Bash 的命令、Write 的路径……）
     * @return string allow / deny / ask
     */
    public function check($tool, $argument = '')
    {
        $tool = (string) $tool;
        $argument = (string) $argument;
        $allowedBy = [];

        foreach ($this->layers as $name => $rules) {
            // DENY 优先：任何一层说不行就到此为止
            foreach ($rules['deny'] as $pattern) {
                if ($this->matches($pattern, $tool, $argument)) {
                    return self::DENY;
                }
            }
            foreach ($rules['allow'] as $pattern) {
                if ($this->matches($pattern, $tool, $argument)) {
                    $allowedBy[] = (string) $name;
                    break;
                }
            }
        }

        // 至少有一层明确允许才放行；没人表态就交给人来定
        return $allowedBy ? self::ALLOW : self::ASK;
    }

    /**
     * 判定并说明理由
     *
     * @param string $tool
     * @param string $argument
     * @return array{decision: string, layer: string, rule: string}
     */
    public function explain($tool, $argument = '')
    {
        $tool = (string) $tool;
        $argument = (string) $argument;

        foreach ($this->layers as $name => $rules) {
            foreach ($rules['deny'] as $pattern) {
                if ($this->matches($pattern, $tool, $argument)) {
                    return ['decision' => self::DENY, 'layer' => (string) $name, 'rule' => $pattern];
                }
            }
        }
        foreach ($this->layers as $name => $rules) {
            foreach ($rules['allow'] as $pattern) {
                if ($this->matches($pattern, $tool, $argument)) {
                    return ['decision' => self::ALLOW, 'layer' => (string) $name, 'rule' => $pattern];
                }
            }
        }
        return ['decision' => self::ASK, 'layer' => '', 'rule' => ''];
    }

    /**
     * 某一层的规则
     *
     * @param string $layer
     * @return array<string, string[]>
     */
    public function rulesOf($layer)
    {
        $layer = (string) $layer;
        if (!isset($this->layers[$layer])) {
            return ['allow' => [], 'deny' => []];
        }
        return [
            'allow' => $this->layers[$layer]['allow'],
            'deny'  => $this->layers[$layer]['deny'],
        ];
    }

    /**
     * 清空某一层
     *
     * 任务结束时清 task 层，换 Agent 时清 agent 层——层与层之间不该互相污染。
     *
     * @param string $layer
     * @return $this
     */
    public function clearLayer($layer)
    {
        $layer = (string) $layer;
        if (isset($this->layers[$layer])) {
            $this->layers[$layer] = ['allow' => [], 'deny' => []];
        }
        return $this;
    }

    /**
     * 全部层
     *
     * @return array<string, array<string, string[]>>
     */
    public function toArray()
    {
        return $this->layers;
    }

    /**
     * 把策略套用到 PermissionManager
     *
     * DENY 规则先注册——`PermissionManager` 按注册顺序匹配，禁令必须排在放行前面。
     *
     * @param PermissionManager $pm
     * @return PermissionManager
     */
    public function applyTo(PermissionManager $pm)
    {
        foreach ($this->layers as $rules) {
            foreach ($rules['deny'] as $pattern) {
                list($tool, $args) = $this->splitPattern($pattern);
                $pm->denyTool($tool, $this->argPatternFor($tool, $args));
            }
        }
        foreach ($this->layers as $rules) {
            foreach ($rules['allow'] as $pattern) {
                list($tool, $args) = $this->splitPattern($pattern);
                $pm->allowTool($tool, $this->argPatternFor($tool, $args));
            }
        }
        return $pm;
    }

    /**
     * 把 `Bash(git *)` 的参数部分翻译成 PermissionRule 认识的 `[字段名 => 模式]`
     *
     * `PermissionRule` 按工具输入的字段名匹配，所以得知道 `Bash` 的命令放在
     * `command` 里、`Write` 的路径放在 `file_path` 里。认不出的工具返回空数组——
     * 那样规则退化成「整个工具」级别，比按错误的字段名匹配（永远匹配不上）安全。
     *
     * @param string $tool
     * @param string $args
     * @return array<string, string>
     */
    protected function argPatternFor($tool, $args)
    {
        if ($args === '') {
            return [];
        }
        $key = strtolower((string) $tool);
        return isset(self::$argKeys[$key]) ? [self::$argKeys[$key] => $args] : [];
    }

    /**
     * 登记某个工具的参数字段名
     *
     * @param string $tool
     * @param string $argKey
     * @return void
     */
    public static function registerArgKey($tool, $argKey)
    {
        self::$argKeys[strtolower((string) $tool)] = (string) $argKey;
    }

    /**
     * @param string $kind allow|deny
     * @param string|string[] $patterns
     * @return $this
     */
    protected function addRule($kind, $patterns)
    {
        foreach (is_array($patterns) ? $patterns : [$patterns] as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern !== '' && !in_array($pattern, $this->layers[$this->cursor][$kind], true)) {
                $this->layers[$this->cursor][$kind][] = $pattern;
            }
        }
        return $this;
    }

    /**
     * 规则是否命中
     *
     * @param string $pattern 形如 `Bash(git *)` 或 `read_file`
     * @param string $tool
     * @param string $argument
     * @return bool
     */
    protected function matches($pattern, $tool, $argument)
    {
        list($patternTool, $patternArgs) = $this->splitPattern($pattern);

        if ($patternTool !== '*' && strcasecmp($patternTool, $tool) !== 0) {
            return false;
        }
        if ($patternArgs === '') {
            return true;   // 只写了工具名 = 整个工具都算命中
        }
        return $this->matchArgument($patternArgs, $argument);
    }

    /**
     * 拆 `Bash(git *)` 成 ['Bash', 'git *']
     *
     * @param string $pattern
     * @return array{0: string, 1: string}
     */
    protected function splitPattern($pattern)
    {
        $pattern = trim((string) $pattern);
        if (preg_match('/^([^(]+)\((.*)\)$/s', $pattern, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        return [$pattern, ''];
    }

    /**
     * 参数通配匹配
     *
     * @param string $pattern
     * @param string $argument
     * @return bool
     */
    protected function matchArgument($pattern, $argument)
    {
        if ($pattern === '*' || $pattern === '') {
            return true;
        }
        if (strpos($pattern, '*') === false && strpos($pattern, '?') === false) {
            // 无通配符：前缀匹配，`Bash(git status)` 命中 `git status --short`
            return strpos($argument, $pattern) === 0;
        }
        return fnmatch($pattern, $argument);
    }
}
