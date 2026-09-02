<?php
namespace Ai\Agent\Memory;

use Ai\Agent\Memory;

/**
 * MemoryManager——分作用域记忆管理器
 *
 * 管理多个作用域（user / project / session / task / agent）的长期记忆，
 * 每个作用域有独立的记忆文件。注入系统提示词时按作用域顺序合并，让模型
 * 感知不同层级的记忆上下文。
 *
 * 作用域说明：
 *   user    — 跨用户偏好（如"用户喜欢 PHP"）
 *   project — 项目级长期事实（如"项目使用 CodeIgniter 3"）
 *   session — 当前会话相关（如"正在修登录"）
 *   task    — 当前任务相关（如"正在修改 Auth.php"）
 *   agent   — Agent 自身行为记忆（如"上次尝试了方案 A 但失败"）
 *
 * 文件持久化：
 *   {baseDir}/user.md
 *   {baseDir}/project.md
 *   {baseDir}/session.md
 *   {baseDir}/task.md
 *   {baseDir}/agent.md
 *
 * 用法：
 * ```php
 * $mm = new MemoryManager('/tmp/agent_memory');
 * $mm->remember('user', '用户喜欢 PHP');
 * $mm->remember('project', '项目使用 CodeIgniter 3');
 * echo $mm->forPrompt();
 * // <memory>
 * // ## user
 * // 用户喜欢 PHP
 * // ...
 * // </memory>
 * ```
 */
class MemoryManager
{
    const SCOPE_USER    = 'user';
    const SCOPE_PROJECT = 'project';
    const SCOPE_SESSION = 'session';
    const SCOPE_TASK    = 'task';
    const SCOPE_AGENT   = 'agent';

    /** @var string[] 所有有效作用域 */
    protected static $validScopes = ['user', 'project', 'session', 'task', 'agent'];

    /** @var array<string, Memory> scope => Memory 实例 */
    protected $memories = [];

    /** @var bool */
    protected $enabled = true;

    /** @var int 每条记忆注入的最大字符数 */
    protected $maxInject = 10000;

    /** @var string 基础目录 */
    protected $baseDir = '';

    /** @var MemoryRetriever|null 记忆检索器（惰性创建） */
    protected $retriever = null;

    /**
     * @param string $baseDir 记忆文件存储目录
     * @param array<string, mixed> $options maxInject, enabled
     */
    public function __construct($baseDir = '', array $options = [])
    {
        if ($baseDir !== '') {
            $this->setBaseDir((string) $baseDir);
        }
        if (isset($options['maxInject'])) {
            $this->maxInject = (int) $options['maxInject'];
        }
        if (isset($options['enabled'])) {
            $this->enabled = (bool) $options['enabled'];
        }
    }

    /**
     * 设置记忆文件存储目录
     *
     * @param string $dir
     * @return $this
     */
    public function setBaseDir($dir)
    {
        $this->baseDir = rtrim(str_replace('\\', '/', (string) $dir), '/');
        $this->memories = [];  // 清空缓存，下次访问时重建
        return $this;
    }

    /** @return string */
    public function getBaseDir()
    {
        return $this->baseDir;
    }

    /**
     * 启用/停用
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
     * 获取有效作用域列表
     *
     * @return string[]
     */
    public static function validScopes()
    {
        return self::$validScopes;
    }

    /**
     * 判断作用域是否有效
     *
     * @param string $scope
     * @return bool
     */
    public static function isValidScope($scope)
    {
        return in_array((string) $scope, self::$validScopes, true);
    }

    /**
     * 获取指定作用域的 Memory 实例
     *
     * @param string $scope
     * @return Memory|null
     */
    public function getMemory($scope)
    {
        $scope = (string) $scope;
        if (!self::isValidScope($scope)) {
            return null;
        }
        if (isset($this->memories[$scope])) {
            return $this->memories[$scope];
        }
        if ($this->baseDir === '') {
            return null;
        }
        $file = $this->baseDir . '/' . $scope . '.md';
        $this->memories[$scope] = new Memory($file, $this->maxInject);
        return $this->memories[$scope];
    }

    /**
     * 向指定作用域追加记忆
     *
     * @param string $scope
     * @param string $content
     * @return bool
     */
    public function remember($scope, $content)
    {
        $mem = $this->getMemory((string) $scope);
        if ($mem === null) {
            return false;
        }
        return $mem->append((string) $content);
    }

    /**
     * 覆盖指定作用域的全部记忆
     *
     * @param string $scope
     * @param string $content
     * @return bool
     */
    public function write($scope, $content)
    {
        $mem = $this->getMemory((string) $scope);
        if ($mem === null) {
            return false;
        }
        return $mem->write((string) $content);
    }

    /**
     * 读取指定作用域的记忆
     *
     * @param string $scope
     * @return string
     */
    public function read($scope)
    {
        $mem = $this->getMemory((string) $scope);
        if ($mem === null) {
            return '';
        }
        return $mem->read();
    }

    /**
     * 清空指定作用域的记忆
     *
     * @param string $scope
     * @return bool
     */
    public function forget($scope)
    {
        $mem = $this->getMemory((string) $scope);
        if ($mem === null) {
            return false;
        }
        return $mem->write('');
    }

    /**
     * 清空全部作用域的记忆
     *
     * @return $this
     */
    public function clearAll()
    {
        foreach (self::$validScopes as $scope) {
            $this->forget($scope);
        }
        return $this;
    }

    /**
     * 记忆检索器（惰性创建）
     *
     * @return MemoryRetriever
     */
    public function retriever()
    {
        if ($this->retriever === null) {
            $this->retriever = new MemoryRetriever($this);
        }
        return $this->retriever;
    }

    /**
     * 替换记忆检索器（如需自定义打分策略）
     *
     * @param MemoryRetriever|null $retriever
     * @return $this
     */
    public function setRetriever($retriever)
    {
        $this->retriever = $retriever;
        return $this;
    }

    /**
     * 检索与查询相关的记忆条目
     *
     * @param string $query
     * @param string[] $scopes 限定作用域，空数组表示全部
     * @return array<int, array{scope: string, line: int, text: string, score: float}>
     */
    public function retrieve($query, array $scopes = [])
    {
        return $this->retriever()->retrieve((string) $query, $scopes);
    }

    /**
     * 生成注入系统提示词的相关记忆文本
     *
     * 与 `forPrompt()` 的区别：只注入与查询相关的条目，而不是全部记忆。
     * 查询为空时退回 `forPrompt()`。
     *
     * @param string $query
     * @return string
     */
    public function forPromptRelevant($query)
    {
        if (!$this->enabled) {
            return '';
        }
        return $this->retriever()->forPrompt((string) $query);
    }

    /**
     * 生成注入系统提示词的记忆文本
     *
     * 合并所有作用域中非空的记忆，按作用域顺序排列。
     * 空记忆（文件不存在或内容为空）被跳过。
     *
     * @return string
     */
    public function forPrompt()
    {
        if (!$this->enabled) {
            return '';
        }

        $parts = [];
        foreach (self::$validScopes as $scope) {
            $mem = $this->getMemory($scope);
            if ($mem === null) {
                continue;
            }
            $content = $mem->forPrompt();
            if ($content === '') {
                continue;
            }
            $parts[] = "## {$scope}\n" . $content;
        }

        if (!$parts) {
            return '';
        }

        return "<memory>\n" . implode("\n\n", $parts) . "\n</memory>";
    }
}