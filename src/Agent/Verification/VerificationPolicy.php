<?php
namespace Ai\Agent\Verification;

/**
 * VerificationPolicy——按任务类型配置验证链
 *
 * 不同任务该验的东西不一样：修 Bug 要先复现再回归，加功能要跑单测与集成测试，
 * 重构要证明行为没变，安全改动要静态扫描。一套验证跑遍所有任务，
 * 要么太松（漏掉该验的）要么太紧（改个错别字也跑全量集成测试）。
 *
 * ```php
 * $policy = VerificationPolicy::bugFix();
 * $policy->steps();       // ['reproduce', 'fix', 'regression_test']
 *
 * $policy = new VerificationPolicy('custom', [
 *     'steps'    => ['php_syntax', 'unit_test'],
 *     'required' => ['php_syntax'],       // 这些必须过，其余失败只记录
 * ]);
 * ```
 *
 * 步骤名对应验证器的 `name()`，也可以是任意标识——`VerificationGate` 按名字
 * 找验证器，找不到的步骤会被跳过并记录，不会让整条链卡住。
 */
class VerificationPolicy
{
    const TYPE_BUG_FIX  = 'bug_fix';
    const TYPE_FEATURE  = 'feature';
    const TYPE_REFACTOR = 'refactor';
    const TYPE_SECURITY = 'security';
    const TYPE_DEFAULT  = 'default';

    /** @var string 策略名 */
    protected $name = '';

    /** @var string[] 验证步骤（按顺序） */
    protected $steps = [];

    /** @var string[] 必须通过的步骤，空表示全部必须通过 */
    protected $required = [];

    /** @var bool 有步骤失败时是否立刻停止后续步骤 */
    protected $failFast = true;

    /** @var string 说明 */
    protected $description = '';

    /**
     * @param string $name
     * @param array<string, mixed> $config steps / required / failFast / description
     */
    public function __construct($name, array $config = [])
    {
        $this->name = (string) $name;
        if (isset($config['steps']) && is_array($config['steps'])) {
            $this->steps = array_values(array_map('strval', $config['steps']));
        }
        if (isset($config['required']) && is_array($config['required'])) {
            $this->required = array_values(array_map('strval', $config['required']));
        }
        if (isset($config['failFast'])) {
            $this->failFast = (bool) $config['failFast'];
        }
        if (isset($config['description'])) {
            $this->description = (string) $config['description'];
        }
    }

    /**
     * Bug 修复：先复现，再改，最后回归
     *
     * @return self
     */
    public static function bugFix()
    {
        return new self(self::TYPE_BUG_FIX, [
            'steps'       => ['php_syntax', 'unit_test'],
            'description' => '复现 → 修复 → 回归测试',
        ]);
    }

    /**
     * 新功能：单测 + 集成测试
     *
     * @return self
     */
    public static function feature()
    {
        return new self(self::TYPE_FEATURE, [
            'steps'       => ['php_syntax', 'security', 'unit_test'],
            'description' => '语法 → 安全 → 单元测试',
        ]);
    }

    /**
     * 重构：证明行为没变，且改动规模受控
     *
     * @return self
     */
    public static function refactor()
    {
        return new self(self::TYPE_REFACTOR, [
            'steps'       => ['php_syntax', 'unit_test', 'git_diff'],
            'description' => '语法 → 测试 → 改动规模检查',
        ]);
    }

    /**
     * 安全改动：静态扫描优先
     *
     * @return self
     */
    public static function security()
    {
        return new self(self::TYPE_SECURITY, [
            'steps'       => ['php_syntax', 'security', 'unit_test'],
            'required'    => ['php_syntax', 'security'],
            'description' => '语法 → 安全扫描（必过）→ 测试',
        ]);
    }

    /**
     * 默认策略：只做最基本的语法与安全检查
     *
     * @return self
     */
    public static function basic()
    {
        return new self(self::TYPE_DEFAULT, [
            'steps'       => ['php_syntax', 'security'],
            'description' => '语法 + 安全扫描',
        ]);
    }

    /**
     * 按任务类型取内置策略
     *
     * @param string $type
     * @return self 未知类型返回 basic()
     */
    public static function forType($type)
    {
        switch ((string) $type) {
            case self::TYPE_BUG_FIX:
                return self::bugFix();
            case self::TYPE_FEATURE:
                return self::feature();
            case self::TYPE_REFACTOR:
                return self::refactor();
            case self::TYPE_SECURITY:
                return self::security();
        }
        return self::basic();
    }

    /**
     * 从任务描述猜任务类型
     *
     * 猜不出来时返回 default——用基础策略比用错策略强。
     *
     * @param string $task
     * @return string
     */
    public static function detectType($task)
    {
        $text = function_exists('mb_strtolower')
            ? mb_strtolower((string) $task, 'UTF-8')
            : strtolower((string) $task);

        $map = [
            self::TYPE_SECURITY => ['安全', '漏洞', '注入', '越权', 'security', 'vulnerab', 'injection'],
            self::TYPE_BUG_FIX  => ['修复', '修一下', 'bug', '报错', '异常', '失败', 'fix', 'broken'],
            self::TYPE_REFACTOR => ['重构', '重写', '整理', 'refactor', 'rewrite', 'clean up'],
            self::TYPE_FEATURE  => ['新增', '实现', '增加', '支持', 'feature', 'implement', 'add support'],
        ];

        foreach ($map as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    return $type;
                }
            }
        }
        return self::TYPE_DEFAULT;
    }

    /** @return string */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string[]
     */
    public function steps()
    {
        return $this->steps;
    }

    /**
     * 某个步骤是不是必过的
     *
     * 没配 required 时全部必过。
     *
     * @param string $step
     * @return bool
     */
    public function isRequired($step)
    {
        if (!$this->required) {
            return true;
        }
        return in_array((string) $step, $this->required, true);
    }

    /** @return bool */
    public function isFailFast()
    {
        return $this->failFast;
    }

    /**
     * @param bool $failFast
     * @return $this
     */
    public function setFailFast($failFast)
    {
        $this->failFast = (bool) $failFast;
        return $this;
    }

    /** @return string */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * 追加一个验证步骤
     *
     * @param string $step
     * @param bool $required
     * @return $this
     */
    public function addStep($step, $required = true)
    {
        $step = (string) $step;
        if ($step !== '' && !in_array($step, $this->steps, true)) {
            $this->steps[] = $step;
            if ($required && $this->required) {
                $this->required[] = $step;
            }
        }
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'name'        => $this->name,
            'steps'       => $this->steps,
            'required'    => $this->required,
            'failFast'    => $this->failFast,
            'description' => $this->description,
        ];
    }
}
