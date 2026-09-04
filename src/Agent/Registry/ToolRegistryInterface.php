<?php
namespace Ai\Agent\Registry;

use Ai\Agent\Tool\ToolDefinition;

/**
 * Tool Registry 接口 —— Agent 不关心 Tool 元数据存在哪
 *
 * 默认实现是 SQLite（`SqliteToolRegistry`），单机 PHP-FPM 装完 Composer 就能用；
 * 多实例 / SaaS 场景以后加 MySQL / PostgreSQL 实现即可，Agent 上层逻辑不用改
 * （规范 §8 / §26 / §30.3）。
 *
 * ```php
 * $registry = new SqliteToolRegistry(__DIR__ . '/.ai/registry.sqlite');
 * $registry->register($toolDefinition);
 * $tools = $registry->search('文章 修改', new ToolSearchContext(['permissions' => $perms]));
 * $tool  = $registry->get('article.update');
 * ```
 *
 * 注：接口方法不写 PHP 类型声明，保持 PHP 7.1 兼容（库的版本下限）。
 */
interface ToolRegistryInterface
{
    /**
     * 搜索相关工具
     *
     * 查询串为空时返回前 N 个（按名字排序），不报错。
     * `$context` 的权限过滤只是 Discovery 优化，**不是安全边界**。
     *
     * @param string $query 自然语言 / 关键词
     * @param ToolSearchContext|null $context
     * @return ToolDefinition[]
     */
    public function search($query, $context = null);

    /**
     * 获取完整 Tool 定义
     *
     * @param string $name
     * @return ToolDefinition|null 不存在时返回 null
     */
    public function get($name);

    /**
     * 注册或更新 Tool（按 name 覆盖）
     *
     * @param ToolDefinition $tool
     * @return void
     */
    public function register(ToolDefinition $tool);

    /**
     * 删除 Tool
     *
     * @param string $name
     * @return void
     */
    public function remove($name);

    /**
     * 全部 Tool（管理界面 / CLI `php-ai tools` 用）
     *
     * @param bool $includeDisabled
     * @return ToolDefinition[]
     */
    public function all($includeDisabled = false);

    /**
     * Tool 数量
     *
     * @param bool $includeDisabled
     * @return int
     */
    public function count($includeDisabled = false);

    /**
     * 清空 Registry（`php-ai index --clear` 用）
     *
     * @return void
     */
    public function clear();

    /**
     * 记录某个源文件的内容 hash（增量扫描用，规范 §18）
     *
     * @param string $path 文件绝对路径
     * @param string $hash 内容 hash
     * @param int $toolCount 该文件产出的 Tool 数
     * @return void
     */
    public function setFileHash($path, $hash, $toolCount = 0);

    /**
     * 取某个源文件上次索引时的 hash
     *
     * @param string $path
     * @return string|null 没索引过返回 null
     */
    public function getFileHash($path);

    /**
     * 全部已索引文件的 hash 映射
     *
     * @return array<string, string> path => hash
     */
    public function fileHashes();

    /**
     * 移除某个源文件的索引记录及其名下全部 Tool
     *
     * @param string $path
     * @return int 被删除的 Tool 数
     */
    public function removeFile($path);
}
