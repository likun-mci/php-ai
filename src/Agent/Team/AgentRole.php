<?php
namespace Ai\Agent\Team;

/**
 * AgentRole——团队角色定义
 *
 * 一个角色 = 名字 + 职责描述 + 系统提示词 + 允许的工具。多角色协作的价值在于
 * 每个角色只带自己需要的上下文与工具：Tester 不需要写代码的工具，Reviewer 不该
 * 有部署权限，各自的系统提示词也只讲自己那摊事。
 *
 * ```php
 * $role = AgentRole::developer();
 * $role = new AgentRole('dba', [
 *     'description' => '数据库结构与查询优化',
 *     'prompt'      => '你是 DBA，只负责表结构与索引，不改业务代码。',
 *     'tools'       => ['read_file', 'bash'],
 * ]);
 * ```
 */
class AgentRole
{
    const MANAGER   = 'manager';
    const DEVELOPER = 'developer';
    const TESTER    = 'tester';
    const SECURITY  = 'security';
    const REVIEWER  = 'reviewer';

    /** @var string 角色名 */
    protected $name = '';

    /** @var string 职责描述 */
    protected $description = '';

    /** @var string 系统提示词 */
    protected $prompt = '';

    /** @var string[] 该角色可用的工具名，空表示不限制 */
    protected $tools = [];

    /** @var int 该角色单次任务的最大迭代次数 */
    protected $maxIter = 15;

    /** @var array<string, mixed> 额外元信息 */
    protected $meta = [];

    /**
     * @param string $name
     * @param array<string, mixed> $config description / prompt / tools / maxIter / meta
     */
    public function __construct($name, array $config = [])
    {
        $this->name = (string) $name;
        foreach (['description', 'prompt'] as $key) {
            if (isset($config[$key])) {
                $this->$key = (string) $config[$key];
            }
        }
        if (isset($config['tools']) && is_array($config['tools'])) {
            $this->tools = array_values(array_map('strval', $config['tools']));
        }
        if (isset($config['maxIter'])) {
            $this->maxIter = max(1, (int) $config['maxIter']);
        }
        if (isset($config['meta']) && is_array($config['meta'])) {
            $this->meta = $config['meta'];
        }
        if ($this->prompt === '' && $this->description !== '') {
            $this->prompt = $this->description;
        }
    }

    /**
     * 开发角色——写代码、改代码
     *
     * @param array<string, mixed> $overrides
     * @return self
     */
    public static function developer(array $overrides = [])
    {
        return new self(self::DEVELOPER, array_merge([
            'description' => '实现功能与修复缺陷',
            'prompt'      => '你是开发工程师。按需求写出可运行的代码，改完自己跑一遍验证。'
                . '不确定需求时先问，不要猜着实现。',
        ], $overrides));
    }

    /**
     * 测试角色——写测试、跑测试、报缺陷
     *
     * @param array<string, mixed> $overrides
     * @return self
     */
    public static function tester(array $overrides = [])
    {
        return new self(self::TESTER, array_merge([
            'description' => '编写与执行测试，报告缺陷',
            'prompt'      => '你是测试工程师。针对改动补测试用例并执行，重点覆盖边界与错误分支。'
                . '发现问题时给出复现步骤与实际/期望结果，不要自己去改实现代码。',
        ], $overrides));
    }

    /**
     * 安全角色——找漏洞
     *
     * @param array<string, mixed> $overrides
     * @return self
     */
    public static function security(array $overrides = [])
    {
        return new self(self::SECURITY, array_merge([
            'description' => '安全审查',
            'prompt'      => '你是安全工程师。检查注入、越权、路径穿越、命令执行、敏感信息泄露等问题。'
                . '每条结论要指出具体文件与行号，说明可利用路径；没有实际可利用性的不要报。',
        ], $overrides));
    }

    /**
     * 审查角色——看代码质量
     *
     * @param array<string, mixed> $overrides
     * @return self
     */
    public static function reviewer(array $overrides = [])
    {
        return new self(self::REVIEWER, array_merge([
            'description' => '代码审查',
            'prompt'      => '你是代码审查者。看正确性、可读性与是否符合项目既有写法。'
                . '指出问题并给出具体改法，不要只说"建议优化"。',
        ], $overrides));
    }

    /**
     * 管理角色——拆任务、分派、汇总
     *
     * @param array<string, mixed> $overrides
     * @return self
     */
    public static function manager(array $overrides = [])
    {
        return new self(self::MANAGER, array_merge([
            'description' => '拆解任务、分派给成员、汇总结果',
            'prompt'      => '你是技术负责人。把任务拆成可分派的子任务，交给合适的角色，'
                . '汇总各方结果并判断是否达成目标。',
        ], $overrides));
    }

    /**
     * 全部内置角色
     *
     * @return array<string, self>
     */
    public static function defaults()
    {
        return [
            self::MANAGER   => self::manager(),
            self::DEVELOPER => self::developer(),
            self::TESTER    => self::tester(),
            self::SECURITY  => self::security(),
            self::REVIEWER  => self::reviewer(),
        ];
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
    public function getPrompt()
    {
        return $this->prompt;
    }

    /**
     * @param string $prompt
     * @return $this
     */
    public function setPrompt($prompt)
    {
        $this->prompt = (string) $prompt;
        return $this;
    }

    /** @return string[] */
    public function getTools()
    {
        return $this->tools;
    }

    /**
     * @param string[] $tools
     * @return $this
     */
    public function setTools(array $tools)
    {
        $this->tools = array_values(array_map('strval', $tools));
        return $this;
    }

    /** @return int */
    public function getMaxIter()
    {
        return $this->maxIter;
    }

    /**
     * @param int $maxIter
     * @return $this
     */
    public function setMaxIter($maxIter)
    {
        $this->maxIter = max(1, (int) $maxIter);
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta()
    {
        return $this->meta;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'name'        => $this->name,
            'description' => $this->description,
            'prompt'      => $this->prompt,
            'tools'       => $this->tools,
            'maxIter'     => $this->maxIter,
            'meta'        => $this->meta,
        ];
    }
}
