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

    /** @var array<string, string> scope => 写入目标文件绝对路径（由 AgentHome 注入） */
    protected $scopeFiles = [];

    /** @var array<string, string[]> scope => 读取路径列表（project 有主+回退，见 dev.md 10.4） */
    protected $scopeReadPaths = [];

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
     * 注入 scope → 文件映射（由 AgentHome 决定布局，见 dev.md 第十节）
     *
     * MemoryManager 不认识 HOME / project root / user 目录，只接受
     * “scope => 绝对文件路径”。设置后覆盖 baseDir 的默认命名。
     *
     * @param array<string, string> $files scope => 绝对文件路径
     * @return $this
     */
    public function setScopeFiles(array $files)
    {
        foreach ($files as $scope => $file) {
            $this->setScopeFile((string) $scope, (string) $file);
        }
        return $this;
    }

    /**
     * 设置单个 scope 的写入目标文件
     *
     * @param string $scope
     * @param string $file 绝对文件路径
     * @return $this
     */
    public function setScopeFile($scope, $file)
    {
        $scope = (string) $scope;
        $this->scopeFiles[$scope] = (string) $file;
        unset($this->memories[$scope]);  // 令缓存的 Memory 失效，下次按新路径重建
        return $this;
    }

    /**
     * 设置某 scope 的读取路径列表（project 用：主 + HOME 回退，见 dev.md 10.4）
     *
     * 只影响 forPrompt / 相关注入的合并读取，不改写入目标。
     *
     * @param string $scope
     * @param string[] $paths 按优先级排列（前者优先）
     * @return $this
     */
    public function setScopeReadPaths($scope, array $paths)
    {
        $this->scopeReadPaths[(string) $scope] = array_values(array_map('strval', $paths));
        return $this;
    }

    /**
     * 解析某 scope 的写入目标文件；无法定位返回 ''（不创建任何东西）
     *
     * @param string $scope
     * @return string
     */
    public function resolveFile($scope)
    {
        $scope = (string) $scope;
        if (!self::isValidScope($scope)) {
            return '';
        }
        if (isset($this->scopeFiles[$scope]) && $this->scopeFiles[$scope] !== '') {
            return $this->scopeFiles[$scope];
        }
        if ($this->baseDir !== '') {
            return $this->baseDir . '/' . $scope . '.md';
        }
        return '';
    }

    /**
     * 全部 scope 的写入目标路径映射（可定位的才列出）
     *
     * @return array<string, string>
     */
    public function paths()
    {
        $out = [];
        foreach (self::$validScopes as $scope) {
            $f = $this->resolveFile($scope);
            if ($f !== '') {
                $out[$scope] = $f;
            }
        }
        return $out;
    }

    /**
     * 某 scope 的读取路径列表（去重、保序）
     *
     * @param string $scope
     * @return string[]
     */
    protected function readPathsFor($scope)
    {
        $scope = (string) $scope;
        if (isset($this->scopeReadPaths[$scope]) && $this->scopeReadPaths[$scope]) {
            $paths = $this->scopeReadPaths[$scope];
        } else {
            $f = $this->resolveFile($scope);
            $paths = $f === '' ? [] : [$f];
        }
        $seen = [];
        $out = [];
        foreach ($paths as $p) {
            if ($p !== '' && !isset($seen[$p])) {
                $seen[$p] = true;
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * 合并读取某 scope 的所有读取路径内容（前者优先，去重空段）
     *
     * 只读，不创建目录（见 dev.md 第十一节）。
     *
     * @param string $scope
     * @return string
     */
    protected function readScopeMerged($scope)
    {
        $parts = [];
        foreach ($this->readPathsFor($scope) as $file) {
            if (is_file($file) && is_readable($file)) {
                $c = trim((string) @file_get_contents($file));
                if ($c !== '') {
                    $parts[] = $c;
                }
            }
        }
        return implode("\n\n", $parts);
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
        $file = $this->resolveFile($scope);
        if ($file === '') {
            return null;
        }
        $this->memories[$scope] = new Memory($file, $this->maxInject);
        return $this->memories[$scope];
    }

    /**
     * 向指定作用域追加一条记忆（默认带日期 + 内容散列 id 前缀）
     *
     * 行格式（见 dev.md 14.3 / 十六节）：
     *   - [2026-09-03] (#9bda3a) 项目使用 CodeIgniter 3
     *
     * id 取**内容散列**而非随机/自增：同样内容必得同样 id，于是重复 remember
     * 天然幂等（几轮沉淀不会把 AGENT.md 撑满）。`['date' => false]` 只关日期，
     * id 仍写——否则 forget(scope, memory_id) 对这条就失效了。
     *
     * @param string $scope
     * @param string $content
     * @param array<string, mixed> $options date（默认 true）
     * @return bool
     */
    public function remember($scope, $content, array $options = [])
    {
        $mem = $this->getMemory((string) $scope);
        if ($mem === null) {
            return false;
        }
        $content = trim((string) $content);
        if ($content === '') {
            return false;
        }

        $existing = $this->existingIds((string) $scope);
        $id = $this->makeMemoryId($content, $existing);
        if ($id === '') {
            // 已存在同 id 同内容：幂等，视为成功但不再落一条
            return true;
        }

        $withDate = !array_key_exists('date', $options) || $options['date'] !== false;
        $prefix = '- ';
        if ($withDate) {
            $prefix .= '[' . date('Y-m-d') . '] ';
        }
        $prefix .= '(#' . $id . ') ';
        return $mem->append($prefix . $content);
    }

    /**
     * 收集某 scope 已有条目的 id → text 映射（用于 remember 查重）
     *
     * @param string $scope
     * @return array<string, string>
     */
    protected function existingIds($scope)
    {
        $map = [];
        foreach ($this->retriever()->entries([(string) $scope]) as $entry) {
            if ($entry['id'] !== '') {
                $map[$entry['id']] = $entry['text'];
            }
        }
        return $map;
    }

    /**
     * 生成内容散列 id，处理碰撞（见 dev.md 14.3）
     *
     * 6 位起，撞上且内容不同则提到 8 位、再 10 位；撞上且内容相同返回 '' 表示应跳过。
     *
     * @param string $content 已 trim 的内容
     * @param array<string, string> $existing 已有 id → text
     * @return string 空串表示重复（幂等跳过）
     */
    protected function makeMemoryId($content, array $existing)
    {
        $full = hash('sha256', $content);
        foreach ([6, 8, 10] as $len) {
            $id = substr($full, 0, $len);
            if (!isset($existing[$id])) {
                return $id;       // 空位，直接用
            }
            if ($existing[$id] === $content) {
                return '';        // 同 id 同内容 → 幂等跳过
            }
            // 同 id 不同内容 → 加长再试，不静默覆盖别人的条目
        }
        return substr($full, 0, 10);  // 极端情况下仍用 10 位（接受）
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
        // 不为不存在的记忆创建文件（见 dev.md 第二十四节 16）
        if (!is_file($mem->path())) {
            return true;
        }
        return $mem->write('');
    }

    /**
     * 按 memory_id 精确删除一条记忆
     *
     * 找不到文件 / 找不到该 id 都不创建任何东西，返回删除条数（0 或 1）。
     *
     * @param string $scope
     * @param string $id
     * @return int
     */
    public function forgetById($scope, $id)
    {
        $scope = (string) $scope;
        $id = strtolower(trim((string) $id));
        $id = ltrim($id, '#');
        if ($id === '') {
            return 0;
        }
        $entries = $this->retriever()->entries([$scope]);
        if (!$entries) {
            return 0;
        }
        $kept = [];
        $removed = 0;
        foreach ($entries as $e) {
            if ($e['id'] !== '' && $e['id'] === $id) {
                $removed++;
                continue;
            }
            $kept[] = $e['raw'];
        }
        if ($removed > 0) {
            $this->write($scope, implode("\n", $kept));
        }
        return $removed;
    }

    /**
     * 按 pattern 查找匹配条目（子串，大小写不敏感），返回候选列表
     *
     * 供 forget 的 pattern 分支使用：命中多条时交给调用方确认，不直接批量删。
     *
     * @param string $scope
     * @param string $pattern
     * @return array<int, array{id: string, text: string}>
     */
    public function findByPattern($scope, $pattern)
    {
        $pattern = trim((string) $pattern);
        if ($pattern === '') {
            return [];
        }
        $out = [];
        foreach ($this->retriever()->entries([(string) $scope]) as $e) {
            if (stripos($e['text'], $pattern) !== false) {
                $out[] = ['id' => $e['id'], 'text' => $e['text']];
            }
        }
        return $out;
    }

    /**
     * 删除某 scope 中文本恰好等于给定文本的条目（pattern 命中唯一时用）
     *
     * @param string $scope
     * @param string $text
     * @return int
     */
    public function forgetByText($scope, $text)
    {
        $scope = (string) $scope;
        $text = (string) $text;
        $entries = $this->retriever()->entries([$scope]);
        if (!$entries) {
            return 0;
        }
        $kept = [];
        $removed = 0;
        foreach ($entries as $e) {
            if ($e['text'] === $text && $removed === 0) {
                $removed++;
                continue;
            }
            $kept[] = $e['raw'];
        }
        if ($removed > 0) {
            $this->write($scope, implode("\n", $kept));
        }
        return $removed;
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
     * @param bool $withId 是否保留 (#id) 标记（默认剥离，见 dev.md 14.3）
     * @return string
     */
    public function forPrompt($withId = false)
    {
        if (!$this->enabled) {
            return '';
        }
        return $this->forPromptScopes(self::$validScopes, (bool) $withId);
    }

    /**
     * 长期 scope 常驻 + 短期 scope 按相关性——注入系统提示词的最终策略（见 dev.md 第十七节）
     *
     * agent / user / project 长期记忆常驻（零命中也不失忆），session / task 走相关性检索。
     * 全部受 maxInject 上限约束，不会在零命中时无限量注入。
     *
     * @param string $query 当前任务目标
     * @param bool $withId 是否输出 (#id)（仅当模型有删除能力时，见 dev.md 14.3）
     * @return string
     */
    public function forInjection($query = '', $withId = false)
    {
        if (!$this->enabled) {
            return '';
        }
        $withId = (bool) $withId;
        $parts = [];

        // 长期：常驻
        $longTerm = $this->forPromptScopes(['agent', 'user', 'project'], $withId);
        if ($longTerm !== '') {
            $parts[] = $longTerm;
        }

        // 短期：有目标按相关性，无目标则全量
        $q = trim((string) $query);
        if ($q !== '') {
            $rel = $this->retriever()->forPrompt($q, ['session', 'task']);
            if (!$withId) {
                $rel = $this->stripIds($rel);
            }
            if ($rel !== '') {
                $parts[] = $rel;
            }
        } else {
            $short = $this->forPromptScopes(['session', 'task'], $withId);
            if ($short !== '') {
                $parts[] = $short;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * 为指定 scope 集合构建 `<memory>` 注入块（受 maxInject 上限约束）
     *
     * @param string[] $scopes
     * @param bool $withId 是否保留 (#id) 标记
     * @return string
     */
    protected function forPromptScopes(array $scopes, $withId)
    {
        $parts = [];
        foreach ($scopes as $scope) {
            $content = trim($this->readScopeMerged((string) $scope));
            if ($content === '') {
                continue;
            }
            if (!$withId) {
                $content = $this->stripIds($content);
            }
            if (mb_strlen($content) > $this->maxInject) {
                $content = mb_substr($content, 0, $this->maxInject) . "\n\n…(记忆过长已截断)";
            }
            $parts[] = "## {$scope}\n" . $content;
        }
        if (!$parts) {
            return '';
        }
        return "<memory>\n" . implode("\n\n", $parts) . "\n</memory>";
    }

    /**
     * 去掉注入文本里的 `(#id)` 标记（模型无删除能力时那是纯浪费 token，见 dev.md 14.3）
     *
     * @param string $text
     * @return string
     */
    protected function stripIds($text)
    {
        $out = preg_replace('/\(#[0-9a-fA-F]{4,12}\)\s*/', '', (string) $text);
        return is_string($out) ? $out : (string) $text;
    }
}