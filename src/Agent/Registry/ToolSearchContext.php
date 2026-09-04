<?php
namespace Ai\Agent\Registry;

/**
 * Tool 搜索上下文 —— Discovery 阶段的候选过滤条件
 *
 * ⚠️ **这里的权限过滤只是 Discovery 优化，不是安全边界**（规范 §14）。
 * 它的作用是把 3000 个 Tool 缩到当前用户可能用得上的 120 个，减少模型的选择空间；
 * 最终能不能执行，由应用现有的 Controller 入口权限校验在执行时重新判定。
 * 任何「搜出来了就等于有权限」的假设都是错的。
 *
 * ```php
 * $ctx = new ToolSearchContext([
 *     'user_id'     => 7,
 *     'permissions' => ['article/read', 'article/update', 'order/*'],
 *     'limit'       => 20,
 * ]);
 * ```
 *
 * `permissions` 的三种取值：
 *   - `null`（默认）—— 不过滤，全部候选都返回
 *   - `[]`          —— 一个都不给（严格模式）
 *   - 字符串数组     —— 命中其一才保留，支持 `article/*` 前缀通配
 */
class ToolSearchContext
{
    /** @var string|int|null 当前用户标识 */
    protected $userId = null;

    /** @var string|int|null 租户标识（多租户应用用） */
    protected $tenantId = null;

    /** @var string[]|null 允许的 Controller 入口路径 / 权限标识；null 表示不过滤 */
    protected $permissions = null;

    /** @var int 返回条数上限 */
    protected $limit = 20;

    /** @var bool 是否把 enabled=0 的 Tool 也搜出来 */
    protected $includeDisabled = false;

    /** @var array<string, mixed> 透传给 Gateway 的附加上下文 */
    protected $extra = [];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->userId   = isset($data['user_id']) ? $data['user_id'] : null;
        $this->tenantId = isset($data['tenant_id']) ? $data['tenant_id'] : null;

        if (array_key_exists('permissions', $data) && $data['permissions'] !== null) {
            $perms = $data['permissions'];
            if (is_string($perms)) {
                $perms = [$perms];
            }
            $list = [];
            if (is_array($perms)) {
                foreach ($perms as $p) {
                    if (is_string($p) || is_numeric($p)) {
                        $p = trim((string) $p);
                        if ($p !== '') {
                            $list[] = $p;
                        }
                    }
                }
            }
            $this->permissions = $list;
        }

        if (isset($data['limit'])) {
            $this->limit = max(1, (int) $data['limit']);
        }
        if (isset($data['include_disabled'])) {
            $this->includeDisabled = (bool) $data['include_disabled'];
        }
        if (isset($data['extra']) && is_array($data['extra'])) {
            $this->extra = $data['extra'];
        }
    }

    /** @return string|int|null */
    public function getUserId()
    {
        return $this->userId;
    }

    /** @return string|int|null */
    public function getTenantId()
    {
        return $this->tenantId;
    }

    /** @return string[]|null */
    public function getPermissions()
    {
        return $this->permissions;
    }

    /** @return int */
    public function getLimit()
    {
        return $this->limit;
    }

    /**
     * @param int $limit
     * @return $this
     */
    public function setLimit($limit)
    {
        $this->limit = max(1, (int) $limit);
        return $this;
    }

    /** @return bool */
    public function includeDisabled()
    {
        return $this->includeDisabled;
    }

    /** @return array<string, mixed> */
    public function getExtra()
    {
        return $this->extra;
    }

    /**
     * 当前上下文是否允许某个 Controller 入口出现在候选里
     *
     * 匹配规则：完全相等，或规则以 `*` 结尾时按前缀匹配。
     * 前后的 `/` 会被忽略，`article/update` 与 `/article/update` 视为同一个。
     *
     * @param string $controllerPath
     * @param string[] $permissions Tool 上冗余记录的权限标识
     * @return bool
     */
    public function allows($controllerPath, array $permissions = [])
    {
        if ($this->permissions === null) {
            return true;
        }
        if ($this->permissions === []) {
            return false;
        }

        $candidates = [];
        if ((string) $controllerPath !== '') {
            $candidates[] = self::normalizePath($controllerPath);
        }
        foreach ($permissions as $p) {
            $candidates[] = self::normalizePath($p);
        }
        if ($candidates === []) {
            return false;
        }

        foreach ($this->permissions as $rule) {
            $rule = self::normalizePath($rule);
            if ($rule === '*') {
                return true;
            }
            $isPrefix = substr($rule, -1) === '*';
            $prefix   = $isPrefix ? rtrim(substr($rule, 0, -1), '/') : '';
            foreach ($candidates as $c) {
                if ($c === $rule) {
                    return true;
                }
                if ($isPrefix && ($c === $prefix || strpos($c, $prefix . '/') === 0)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 去掉首尾斜杠并转小写，让 `/Article/Update` 与 `article/update` 等价
     *
     * @param string $path
     * @return string
     */
    public static function normalizePath($path)
    {
        return strtolower(trim(trim((string) $path), '/'));
    }
}
