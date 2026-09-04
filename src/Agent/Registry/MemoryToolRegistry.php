<?php
namespace Ai\Agent\Registry;

use Ai\Agent\Tool\ToolDefinition;

/**
 * 内存 Tool Registry
 *
 * 纯数组实现，不碰任何扩展（PDO / sqlite 都不需要）。用途：
 *   - Demo 与文档示例
 *   - 单元测试（与 SqliteToolRegistry 跑同一组接口契约断言）
 *   - CI 上 PHP 7.1 那个只装非 dev 依赖的作业
 *   - `php-ai index --dry-run` 这类不落盘的场景
 *
 * 搜索用 `SearchText::score()`，与 SQLite 的 LIKE 降级路径同一套打分逻辑。
 */
class MemoryToolRegistry implements ToolRegistryInterface
{
    /** @var array<string, ToolDefinition> name => 定义 */
    protected $tools = [];

    /** @var array<string, string> path => hash */
    protected $fileHashes = [];

    /** @var array<string, int> path => tool_count */
    protected $fileCounts = [];

    /**
     * @param ToolDefinition[] $tools 可选的初始工具
     */
    public function __construct(array $tools = [])
    {
        foreach ($tools as $t) {
            if ($t instanceof ToolDefinition) {
                $this->register($t);
            }
        }
    }

    /**
     * @param string $query
     * @param ToolSearchContext|null $context
     * @return ToolDefinition[]
     */
    public function search($query, $context = null)
    {
        $ctx   = $context instanceof ToolSearchContext ? $context : new ToolSearchContext();
        $query = (string) $query;

        $candidates = [];
        foreach ($this->tools as $tool) {
            if (!$tool->isEnabled() && !$ctx->includeDisabled()) {
                continue;
            }
            if (!$ctx->allows($tool->getControllerPath(), $tool->getPermissions())) {
                continue;
            }
            $candidates[] = $tool;
        }

        if (trim($query) === '') {
            usort($candidates, function (ToolDefinition $a, ToolDefinition $b) {
                return strcmp($a->getName(), $b->getName());
            });
            return array_slice($candidates, 0, $ctx->getLimit());
        }

        $scored = [];
        foreach ($candidates as $i => $tool) {
            $hay = $tool->getDescription() . ' ' . implode(' ', $tool->getKeywords())
                . ' ' . $tool->getControllerPath();
            $score = SearchText::score($query, $tool->getName(), $hay);
            if ($score > 0) {
                // 带上原始下标，分数相同时保持稳定顺序（usort 在 PHP 7 不稳定）
                $scored[] = ['score' => $score, 'i' => $i, 'tool' => $tool];
            }
        }

        usort($scored, function (array $a, array $b) {
            if ($a['score'] === $b['score']) {
                return $a['i'] < $b['i'] ? -1 : 1;
            }
            return $a['score'] > $b['score'] ? -1 : 1;
        });

        $out = [];
        foreach (array_slice($scored, 0, $ctx->getLimit()) as $row) {
            $out[] = $row['tool'];
        }
        return $out;
    }

    /**
     * @param string $name
     * @return ToolDefinition|null
     */
    public function get($name)
    {
        $name = (string) $name;
        return isset($this->tools[$name]) ? $this->tools[$name] : null;
    }

    /**
     * @param ToolDefinition $tool
     * @return void
     */
    public function register(ToolDefinition $tool)
    {
        $name = $tool->getName();
        if ($name === '') {
            throw new RegistryException('ToolDefinition 缺少 name，无法注册');
        }
        $now = time();
        if (isset($this->tools[$name])) {
            $tool->setCreatedAt($this->tools[$name]->getCreatedAt());
        } elseif ($tool->getCreatedAt() === 0) {
            $tool->setCreatedAt($now);
        }
        $tool->setUpdatedAt($now);
        $this->tools[$name] = $tool;
    }

    /**
     * @param string $name
     * @return void
     */
    public function remove($name)
    {
        unset($this->tools[(string) $name]);
    }

    /**
     * @param bool $includeDisabled
     * @return ToolDefinition[]
     */
    public function all($includeDisabled = false)
    {
        $out = [];
        foreach ($this->tools as $t) {
            if (!$t->isEnabled() && !$includeDisabled) {
                continue;
            }
            $out[] = $t;
        }
        usort($out, function (ToolDefinition $a, ToolDefinition $b) {
            return strcmp($a->getName(), $b->getName());
        });
        return $out;
    }

    /**
     * @param bool $includeDisabled
     * @return int
     */
    public function count($includeDisabled = false)
    {
        return count($this->all($includeDisabled));
    }

    /** @return void */
    public function clear()
    {
        $this->tools      = [];
        $this->fileHashes = [];
        $this->fileCounts = [];
    }

    /**
     * @param string $path
     * @param string $hash
     * @param int $toolCount
     * @return void
     */
    public function setFileHash($path, $hash, $toolCount = 0)
    {
        $this->fileHashes[(string) $path] = (string) $hash;
        $this->fileCounts[(string) $path] = (int) $toolCount;
    }

    /**
     * @param string $path
     * @return string|null
     */
    public function getFileHash($path)
    {
        $path = (string) $path;
        return isset($this->fileHashes[$path]) ? $this->fileHashes[$path] : null;
    }

    /** @return array<string, string> */
    public function fileHashes()
    {
        return $this->fileHashes;
    }

    /**
     * @param string $path
     * @return int
     */
    public function removeFile($path)
    {
        $path    = (string) $path;
        $removed = 0;
        foreach ($this->tools as $name => $tool) {
            if ($tool->getSourceFile() === $path) {
                unset($this->tools[$name]);
                $removed++;
            }
        }
        unset($this->fileHashes[$path], $this->fileCounts[$path]);
        return $removed;
    }
}
