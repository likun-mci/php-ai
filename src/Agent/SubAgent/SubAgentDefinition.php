<?php
namespace Ai\Agent\SubAgent;

/**
 * 子 Agent 定义
 *
 * 注册一个可由主 Agent 通过 spawn_agent 工具调用的子 Agent。
 * 每个子 Agent 有独立的系统提示词和工具，运行在隔离的上下文中。
 */
class SubAgentDefinition
{
    /** @var string 名称（主 Agent 用此标识调用） */
    protected $name;

    /** @var string 描述（告诉主 Agent 这个子 Agent 做什么） */
    protected $description;

    /** @var string 系统提示词 */
    protected $systemPrompt;

    /** @var array<string, mixed> 工具有效定义 */
    protected $tools = [];

    /** @var int 最大迭代次数 */
    protected $maxIter = 25;

    /** @var array<string, mixed> 额外配置 */
    protected $extra = [];

    /**
     * @param string $name
     * @param array<string, mixed> $config
     */
    public function __construct($name, array $config = [])
    {
        $this->name = (string) $name;
        $this->description = isset($config['description']) ? (string) $config['description'] : '';
        $this->systemPrompt = isset($config['prompt']) ? (string) $config['prompt'] : '';
        if (isset($config['system'])) {
            $this->systemPrompt = (string) $config['system'];
        }
        $this->tools = isset($config['tools']) && is_array($config['tools']) ? $config['tools'] : [];
        $this->maxIter = isset($config['max_iter']) ? (int) $config['max_iter'] : 25;
        $this->extra = isset($config['extra']) && is_array($config['extra']) ? $config['extra'] : [];
    }

    /** @return string */
    public function getName() { return $this->name; }
    /** @return string */
    public function getDescription() { return $this->description; }
    /** @return string */
    public function getSystemPrompt() { return $this->systemPrompt; }
    /** @return array<string, mixed> */
    public function getTools() { return $this->tools; }
    /** @return int */
    public function getMaxIter() { return $this->maxIter; }
    /** @return array<string, mixed> */
    public function getExtra() { return $this->extra; }
}