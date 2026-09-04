<?php
namespace Ai\Agent\Registry;

use Ai\Agent\Tool\ToolDefinition;
use Ai\Agent\Tool\ToolParameter;

/**
 * SQLite Tool Registry（默认实现）
 *
 * ```php
 * $registry = new SqliteToolRegistry(__DIR__ . '/.ai/registry.sqlite');
 * ```
 *
 * 为什么默认 SQLite（规范 §9）：不用配数据库服务器、Composer 装完即用、
 * 部署就是拷一个文件、Tool 上万条也够快，还自带 FTS5 全文检索。
 * 多 PHP 实例 / SaaS 需要 MySQL / PostgreSQL 时另写一个 `ToolRegistryInterface`
 * 实现即可，Agent 上层不用改。
 *
 * 表结构见规范 §10，在其基础上补了三样：
 *   - `agent_tools.controller_path` —— 权限与执行入口（§30.1 的核心字段）
 *   - `agent_tools.schema_json`     —— 缓存好的完整 JSON Schema，读的时候不用重拼
 *   - `agent_index_files`           —— 源文件 hash，支撑增量扫描（§18）
 *
 * 中文检索：`unicode61` 分词器不切汉字，因此写进 FTS 表的是
 * `SearchText::normalize()` 归一化后的 token 串（单字 + 二元组），查询走同一套切法。
 * FTS5 不可用（编译期没开）时自动降级到 LIKE 扫描 + 纯 PHP 打分，功能不中断。
 *
 * ⚠️ 这个库只存 **Agent Tool 元数据**，不是业务数据库，也绝不给 Agent 任意 SQL
 * 能力（规范 §31.11）。业务数据永远走 Controller / Service。
 */
class SqliteToolRegistry implements ToolRegistryInterface
{
    /** @var int 表结构版本，将来迁移用 */
    const SCHEMA_VERSION = 1;

    /** @var \PDO */
    protected $pdo;

    /** @var string 数据库文件路径（:memory: 时为空） */
    protected $path = '';

    /** @var bool FTS5 是否可用（不可用则降级 LIKE） */
    protected $ftsAvailable = false;

    /**
     * @param string|\PDO $pathOrPdo sqlite 文件路径，或已建好的 PDO 连接
     * @param array<string, mixed> $options wal(bool, 默认 true) / busy_timeout(ms)
     * @throws RegistryException PDO / pdo_sqlite 缺失，或建表失败
     */
    public function __construct($pathOrPdo, array $options = [])
    {
        if ($pathOrPdo instanceof \PDO) {
            $this->pdo = $pathOrPdo;
        } else {
            $this->pdo = $this->connect((string) $pathOrPdo, $options);
            $this->path = (string) $pathOrPdo;
        }

        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $this->exec('PRAGMA foreign_keys = ON');
        $busy = isset($options['busy_timeout']) ? (int) $options['busy_timeout'] : 5000;
        $this->exec('PRAGMA busy_timeout = ' . $busy);

        $this->migrate();
    }

    /**
     * @param string $path
     * @param array<string, mixed> $options
     * @return \PDO
     */
    protected function connect($path, array $options)
    {
        if (!class_exists('PDO')) {
            throw new RegistryException('缺少 PDO 扩展，无法使用 SqliteToolRegistry；可改用 MemoryToolRegistry');
        }
        $drivers = \PDO::getAvailableDrivers();
        if (!in_array('sqlite', $drivers, true)) {
            throw new RegistryException('缺少 pdo_sqlite 扩展，无法使用 SqliteToolRegistry；可改用 MemoryToolRegistry');
        }

        if ($path !== ':memory:' && $path !== '') {
            $dir = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RegistryException('无法创建 Registry 目录: ' . $dir);
            }
        }

        try {
            $pdo = new \PDO('sqlite:' . $path);
        } catch (\PDOException $e) {
            throw new RegistryException('打开 Registry 数据库失败: ' . $e->getMessage(), 0, $e);
        }

        // WAL 让读写不互相阻塞；内存库与部分文件系统不支持，失败不致命
        $wal = !isset($options['wal']) || $options['wal'];
        if ($wal && $path !== ':memory:') {
            try {
                $pdo->exec('PRAGMA journal_mode = WAL');
            } catch (\PDOException $e) {
                // 忽略：只是少了并发优化，功能不受影响
            }
        }
        return $pdo;
    }

    /** 底层 PDO（给 CLI / 高级用法用）
     * @return \PDO
     */
    public function pdo()
    {
        return $this->pdo;
    }

    /** 数据库文件路径
     * @return string
     */
    public function path()
    {
        return $this->path;
    }

    /** FTS5 是否可用
     * @return bool
     */
    public function hasFts()
    {
        return $this->ftsAvailable;
    }

    /**
     * @param string $sql
     * @return void
     */
    protected function exec($sql)
    {
        try {
            $this->pdo->exec($sql);
        } catch (\PDOException $e) {
            throw new RegistryException('SQL 执行失败: ' . $e->getMessage() . ' — ' . $sql, 0, $e);
        }
    }

    /** 建表（幂等）
     * @return void
     */
    protected function migrate()
    {
        $this->exec(
            'CREATE TABLE IF NOT EXISTS agent_tools (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL UNIQUE,
                description TEXT NOT NULL DEFAULT "",
                controller_path TEXT,
                class_name TEXT NOT NULL DEFAULT "",
                method_name TEXT NOT NULL DEFAULT "",
                risk_level VARCHAR(32) DEFAULT "low",
                requires_confirmation INTEGER DEFAULT 0,
                confirm_declared INTEGER DEFAULT 0,
                enabled INTEGER DEFAULT 1,
                version VARCHAR(64),
                keywords TEXT,
                returns TEXT,
                schema_json TEXT,
                metadata_json TEXT,
                source_file TEXT,
                source_line INTEGER,
                hash VARCHAR(64),
                created_at INTEGER,
                updated_at INTEGER
            )'
        );

        $this->exec(
            'CREATE TABLE IF NOT EXISTS agent_tool_parameters (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tool_id INTEGER NOT NULL,
                name VARCHAR(255) NOT NULL,
                type VARCHAR(64),
                description TEXT,
                required INTEGER DEFAULT 0,
                schema_json TEXT,
                default_json TEXT,
                sort_order INTEGER DEFAULT 0,
                FOREIGN KEY (tool_id) REFERENCES agent_tools(id) ON DELETE CASCADE
            )'
        );

        $this->exec(
            'CREATE TABLE IF NOT EXISTS agent_tool_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tool_id INTEGER NOT NULL,
                permission VARCHAR(255) NOT NULL,
                FOREIGN KEY (tool_id) REFERENCES agent_tools(id) ON DELETE CASCADE
            )'
        );

        $this->exec(
            'CREATE TABLE IF NOT EXISTS agent_index_files (
                path TEXT PRIMARY KEY,
                hash VARCHAR(64) NOT NULL,
                tool_count INTEGER DEFAULT 0,
                indexed_at INTEGER
            )'
        );

        $this->exec('CREATE TABLE IF NOT EXISTS agent_registry_meta (key TEXT PRIMARY KEY, value TEXT)');

        $this->exec('CREATE INDEX IF NOT EXISTS idx_agent_tools_controller ON agent_tools(controller_path)');
        $this->exec('CREATE INDEX IF NOT EXISTS idx_agent_tools_enabled ON agent_tools(enabled)');
        $this->exec('CREATE INDEX IF NOT EXISTS idx_agent_tools_source ON agent_tools(source_file)');
        $this->exec('CREATE INDEX IF NOT EXISTS idx_agent_params_tool ON agent_tool_parameters(tool_id)');
        $this->exec('CREATE INDEX IF NOT EXISTS idx_agent_perms_tool ON agent_tool_permissions(tool_id)');

        // FTS5 建不出来（编译期没开）就降级，不让整个 Registry 不可用
        try {
            $this->pdo->exec(
                'CREATE VIRTUAL TABLE IF NOT EXISTS agent_tools_fts USING fts5(
                    tool_name UNINDEXED, name, description, keywords, tokenize="unicode61"
                )'
            );
            $this->ftsAvailable = true;
        } catch (\PDOException $e) {
            $this->ftsAvailable = false;
        }

        $stmt = $this->pdo->prepare('INSERT OR REPLACE INTO agent_registry_meta (key, value) VALUES (?, ?)');
        $stmt->execute(['schema_version', (string) self::SCHEMA_VERSION]);
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

        // 权限过滤会淘汰一部分，先多取一些候选再截断，避免过滤后不足 limit
        $fetch = $ctx->getLimit() * 5 + 20;

        if (trim($query) === '') {
            $names = $this->listNames($ctx->includeDisabled(), $fetch);
        } elseif ($this->ftsAvailable) {
            $names = $this->searchFts($query, $ctx->includeDisabled(), $fetch);
            if ($names === []) {
                // FTS 一无所获时再试一次 LIKE：模型给的查询串可能是整句话，
                // 切出来的 token 一个都没进索引，但子串仍可能命中
                $names = $this->searchLike($query, $ctx->includeDisabled(), $fetch);
            }
        } else {
            $names = $this->searchLike($query, $ctx->includeDisabled(), $fetch);
        }

        $out = [];
        foreach ($names as $name) {
            $tool = $this->get($name);
            if ($tool === null) {
                continue;
            }
            if (!$tool->isEnabled() && !$ctx->includeDisabled()) {
                continue;
            }
            if (!$ctx->allows($tool->getControllerPath(), $tool->getPermissions())) {
                continue;
            }
            $out[] = $tool;
            if (count($out) >= $ctx->getLimit()) {
                break;
            }
        }
        return $out;
    }

    /**
     * FTS5 检索，按 bm25 排序
     *
     * @param string $query
     * @param bool $includeDisabled
     * @param int $limit
     * @return string[] tool name 列表
     */
    protected function searchFts($query, $includeDisabled, $limit)
    {
        $match = SearchText::toMatchQuery($query);
        if ($match === '') {
            return [];
        }
        $sql = 'SELECT f.tool_name FROM agent_tools_fts f
                JOIN agent_tools t ON t.name = f.tool_name
                WHERE agent_tools_fts MATCH :q';
        if (!$includeDisabled) {
            $sql .= ' AND t.enabled = 1';
        }
        // name 列权重最高：工具名命中比描述命中更能说明意图
        $sql .= ' ORDER BY bm25(agent_tools_fts, 0.0, 10.0, 3.0, 5.0) LIMIT :lim';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':q', $match, \PDO::PARAM_STR);
            $stmt->bindValue(':lim', (int) $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
        } catch (\PDOException $e) {
            // MATCH 语法炸了（查询串里有 FTS5 特殊字符没转干净）时降级，不抛给调用方
            return [];
        }

        $out = [];
        foreach ((array) $rows as $row) {
            if (isset($row['tool_name'])) {
                $out[] = (string) $row['tool_name'];
            }
        }
        return $out;
    }

    /**
     * LIKE 降级检索 + 纯 PHP 打分
     *
     * FTS5 不可用，或 FTS 没命中时走这里。先用 LIKE 粗筛（避免把整表读进内存），
     * 再用与 MemoryToolRegistry 相同的打分排序。
     *
     * @param string $query
     * @param bool $includeDisabled
     * @param int $limit
     * @return string[]
     */
    protected function searchLike($query, $includeDisabled, $limit)
    {
        $tokens = SearchText::tokenize($query);
        if ($tokens === []) {
            return [];
        }

        $where  = [];
        $params = [];
        $i      = 0;
        foreach ($tokens as $t) {
            $key = ':t' . $i;
            $where[] = '(LOWER(name) LIKE ' . $key . ' OR LOWER(description) LIKE ' . $key
                . ' OR LOWER(IFNULL(keywords, "")) LIKE ' . $key . ')';
            $params[$key] = '%' . strtolower($t) . '%';
            $i++;
        }

        $sql = 'SELECT name, description, IFNULL(keywords, "") AS keywords, IFNULL(controller_path, "") AS controller_path
                FROM agent_tools WHERE (' . implode(' OR ', $where) . ')';
        if (!$includeDisabled) {
            $sql .= ' AND enabled = 1';
        }
        $sql .= ' LIMIT ' . (int) ($limit * 5 + 50);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $scored = [];
        foreach ((array) $rows as $idx => $row) {
            $hay = (string) $row['description'] . ' ' . (string) $row['keywords']
                . ' ' . (string) $row['controller_path'];
            $score = SearchText::score($query, (string) $row['name'], $hay);
            if ($score > 0) {
                $scored[] = ['score' => $score, 'i' => $idx, 'name' => (string) $row['name']];
            }
        }
        usort($scored, function (array $a, array $b) {
            if ($a['score'] === $b['score']) {
                return $a['i'] < $b['i'] ? -1 : 1;
            }
            return $a['score'] > $b['score'] ? -1 : 1;
        });

        $out = [];
        foreach (array_slice($scored, 0, $limit) as $row) {
            $out[] = $row['name'];
        }
        return $out;
    }

    /**
     * @param bool $includeDisabled
     * @param int $limit
     * @return string[]
     */
    protected function listNames($includeDisabled, $limit)
    {
        $sql = 'SELECT name FROM agent_tools';
        if (!$includeDisabled) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY name LIMIT ' . (int) $limit;
        $stmt = $this->pdo->query($sql);
        $out  = [];
        if ($stmt !== false) {
            foreach ($stmt->fetchAll() as $row) {
                $out[] = (string) $row['name'];
            }
        }
        return $out;
    }

    /**
     * @param string $name
     * @return ToolDefinition|null
     */
    public function get($name)
    {
        $stmt = $this->pdo->prepare('SELECT * FROM agent_tools WHERE name = ? LIMIT 1');
        $stmt->execute([(string) $name]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        return $this->hydrate($row);
    }

    /**
     * 数据库行 → ToolDefinition
     *
     * @param array<string, mixed> $row
     * @return ToolDefinition
     */
    protected function hydrate(array $row)
    {
        $id = (int) $row['id'];

        $stmt = $this->pdo->prepare('SELECT * FROM agent_tool_parameters WHERE tool_id = ? ORDER BY sort_order, id');
        $stmt->execute([$id]);
        $params = [];
        foreach ($stmt->fetchAll() as $p) {
            $schema = $this->jsonDecode(isset($p['schema_json']) ? $p['schema_json'] : '');
            $data   = is_array($schema) ? $schema : [];
            $data['name']        = (string) $p['name'];
            $data['description'] = isset($p['description']) ? (string) $p['description'] : '';
            $data['required']    = !empty($p['required']);
            $data['sort_order']  = (int) $p['sort_order'];
            $params[] = ToolParameter::fromArray($data);
        }

        $stmt = $this->pdo->prepare('SELECT permission FROM agent_tool_permissions WHERE tool_id = ? ORDER BY id');
        $stmt->execute([$id]);
        $perms = [];
        foreach ($stmt->fetchAll() as $p) {
            $perms[] = (string) $p['permission'];
        }

        $metadata = $this->jsonDecode(isset($row['metadata_json']) ? $row['metadata_json'] : '');

        return new ToolDefinition([
            'name'                  => (string) $row['name'],
            'description'           => (string) $row['description'],
            'controller_path'       => isset($row['controller_path']) ? (string) $row['controller_path'] : '',
            'class_name'            => (string) $row['class_name'],
            'method_name'           => (string) $row['method_name'],
            'risk'                  => (string) $row['risk_level'],
            'requires_confirmation' => !empty($row['requires_confirmation']),
            'confirm_declared'      => !empty($row['confirm_declared']),
            'enabled'               => !empty($row['enabled']),
            'version'               => isset($row['version']) ? (string) $row['version'] : '',
            'keywords'              => isset($row['keywords']) ? (string) $row['keywords'] : '',
            'permissions'           => $perms,
            'returns'               => isset($row['returns']) ? (string) $row['returns'] : '',
            'source_file'           => isset($row['source_file']) ? (string) $row['source_file'] : '',
            'source_line'           => (int) $row['source_line'],
            'hash'                  => isset($row['hash']) ? (string) $row['hash'] : '',
            'metadata'              => is_array($metadata) ? $metadata : [],
            'created_at'            => (int) $row['created_at'],
            'updated_at'            => (int) $row['updated_at'],
            'parameters'            => $params,
        ]);
    }

    /**
     * @param mixed $json
     * @return mixed
     */
    protected function jsonDecode($json)
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $out = json_decode($json, true);
        return $out === null ? null : $out;
    }

    /**
     * 注册或更新（按 name 覆盖）
     *
     * 整个写入在一个事务里：主表、参数表、权限表、FTS 行要么一起生效要么一起回滚，
     * 不会出现「Tool 在但搜不到」这种半吊子状态。
     *
     * @param ToolDefinition $tool
     * @return void
     */
    public function register(ToolDefinition $tool)
    {
        $name = $tool->getName();
        if ($name === '') {
            throw new RegistryException('ToolDefinition 缺少 name，无法注册');
        }

        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }

        try {
            $now = time();
            $stmt = $this->pdo->prepare('SELECT id, created_at FROM agent_tools WHERE name = ? LIMIT 1');
            $stmt->execute([$name]);
            $existing = $stmt->fetch();

            $createdAt = is_array($existing) ? (int) $existing['created_at'] : ($tool->getCreatedAt() ?: $now);
            $tool->setCreatedAt($createdAt);
            $tool->setUpdatedAt($now);

            $fields = [
                'name'                  => $name,
                'description'           => $tool->getDescription(),
                'controller_path'       => $tool->getControllerPath(),
                'class_name'            => $tool->getClassName(),
                'method_name'           => $tool->getMethodName(),
                'risk_level'            => $tool->getRisk(),
                'requires_confirmation' => $tool->requiresConfirmation() ? 1 : 0,
                'confirm_declared'      => $tool->isConfirmDeclared() ? 1 : 0,
                'enabled'               => $tool->isEnabled() ? 1 : 0,
                'version'               => $tool->getVersion(),
                'keywords'              => implode(',', $tool->getKeywords()),
                'returns'               => $tool->getReturns(),
                'schema_json'           => (string) json_encode($tool->schema(), JSON_UNESCAPED_UNICODE),
                'metadata_json'         => (string) json_encode($tool->getMetadata(), JSON_UNESCAPED_UNICODE),
                'source_file'           => $tool->getSourceFile(),
                'source_line'           => $tool->getSourceLine(),
                'hash'                  => $tool->getHash(),
                'created_at'            => $createdAt,
                'updated_at'            => $now,
            ];

            if (is_array($existing)) {
                $id  = (int) $existing['id'];
                $set = [];
                foreach (array_keys($fields) as $k) {
                    $set[] = $k . ' = :' . $k;
                }
                $sql = 'UPDATE agent_tools SET ' . implode(', ', $set) . ' WHERE id = :id';
                $stmt = $this->pdo->prepare($sql);
                $fields['id'] = $id;
                $stmt->execute($fields);
                unset($fields['id']);

                $this->pdo->prepare('DELETE FROM agent_tool_parameters WHERE tool_id = ?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM agent_tool_permissions WHERE tool_id = ?')->execute([$id]);
            } else {
                $cols = array_keys($fields);
                $sql  = 'INSERT INTO agent_tools (' . implode(', ', $cols) . ') VALUES (:'
                    . implode(', :', $cols) . ')';
                $this->pdo->prepare($sql)->execute($fields);
                $id = (int) $this->pdo->lastInsertId();
            }

            $pStmt = $this->pdo->prepare(
                'INSERT INTO agent_tool_parameters
                 (tool_id, name, type, description, required, schema_json, default_json, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($tool->getParameters() as $p) {
                $types = $p->getTypes();
                $pStmt->execute([
                    $id,
                    $p->getName(),
                    implode('|', $types),
                    $p->getDescription(),
                    $p->isRequired() ? 1 : 0,
                    (string) json_encode($p->toArray(), JSON_UNESCAPED_UNICODE),
                    $p->hasDefault() ? (string) json_encode($p->getDefault(), JSON_UNESCAPED_UNICODE) : null,
                    $p->getSortOrder(),
                ]);
            }

            $permStmt = $this->pdo->prepare('INSERT INTO agent_tool_permissions (tool_id, permission) VALUES (?, ?)');
            foreach ($tool->getPermissions() as $perm) {
                $permStmt->execute([$id, $perm]);
            }

            $this->syncFts($tool);

            if ($own) {
                $this->pdo->commit();
            }
        } catch (\PDOException $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RegistryException('注册 Tool 失败 (' . $name . '): ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 更新 FTS 行（先删后插，保证不重复）
     *
     * @param ToolDefinition $tool
     * @return void
     */
    protected function syncFts(ToolDefinition $tool)
    {
        if (!$this->ftsAvailable) {
            return;
        }
        $name = $tool->getName();
        $this->pdo->prepare('DELETE FROM agent_tools_fts WHERE tool_name = ?')->execute([$name]);
        $this->pdo->prepare(
            'INSERT INTO agent_tools_fts (tool_name, name, description, keywords) VALUES (?, ?, ?, ?)'
        )->execute([
            $name,
            SearchText::normalize($name),
            SearchText::normalize($tool->getDescription()),
            SearchText::normalize(implode(' ', $tool->getKeywords()) . ' ' . $tool->getControllerPath()),
        ]);
    }

    /**
     * @param string $name
     * @return void
     */
    public function remove($name)
    {
        $name = (string) $name;
        $own  = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            // 外键 CASCADE 负责参数与权限表；FTS 是独立表要手工删
            $this->pdo->prepare('DELETE FROM agent_tools WHERE name = ?')->execute([$name]);
            if ($this->ftsAvailable) {
                $this->pdo->prepare('DELETE FROM agent_tools_fts WHERE tool_name = ?')->execute([$name]);
            }
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\PDOException $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RegistryException('删除 Tool 失败 (' . $name . '): ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param bool $includeDisabled
     * @return ToolDefinition[]
     */
    public function all($includeDisabled = false)
    {
        $sql = 'SELECT * FROM agent_tools';
        if (!$includeDisabled) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY name';
        $stmt = $this->pdo->query($sql);
        $out  = [];
        if ($stmt !== false) {
            foreach ($stmt->fetchAll() as $row) {
                $out[] = $this->hydrate($row);
            }
        }
        return $out;
    }

    /**
     * @param bool $includeDisabled
     * @return int
     */
    public function count($includeDisabled = false)
    {
        $sql = 'SELECT COUNT(*) AS c FROM agent_tools';
        if (!$includeDisabled) {
            $sql .= ' WHERE enabled = 1';
        }
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            return 0;
        }
        $row = $stmt->fetch();
        return is_array($row) ? (int) $row['c'] : 0;
    }

    /** @return void */
    public function clear()
    {
        $this->exec('DELETE FROM agent_tool_parameters');
        $this->exec('DELETE FROM agent_tool_permissions');
        $this->exec('DELETE FROM agent_tools');
        $this->exec('DELETE FROM agent_index_files');
        if ($this->ftsAvailable) {
            $this->exec('DELETE FROM agent_tools_fts');
        }
    }

    /**
     * @param string $path
     * @param string $hash
     * @param int $toolCount
     * @return void
     */
    public function setFileHash($path, $hash, $toolCount = 0)
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR REPLACE INTO agent_index_files (path, hash, tool_count, indexed_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([(string) $path, (string) $hash, (int) $toolCount, time()]);
    }

    /**
     * @param string $path
     * @return string|null
     */
    public function getFileHash($path)
    {
        $stmt = $this->pdo->prepare('SELECT hash FROM agent_index_files WHERE path = ? LIMIT 1');
        $stmt->execute([(string) $path]);
        $row = $stmt->fetch();
        return is_array($row) ? (string) $row['hash'] : null;
    }

    /** @return array<string, string> */
    public function fileHashes()
    {
        $stmt = $this->pdo->query('SELECT path, hash FROM agent_index_files');
        $out  = [];
        if ($stmt !== false) {
            foreach ($stmt->fetchAll() as $row) {
                $out[(string) $row['path']] = (string) $row['hash'];
            }
        }
        return $out;
    }

    /**
     * @param string $path
     * @return int
     */
    public function removeFile($path)
    {
        $path = (string) $path;
        $stmt = $this->pdo->prepare('SELECT name FROM agent_tools WHERE source_file = ?');
        $stmt->execute([$path]);
        $names = [];
        foreach ($stmt->fetchAll() as $row) {
            $names[] = (string) $row['name'];
        }
        foreach ($names as $n) {
            $this->remove($n);
        }
        $this->pdo->prepare('DELETE FROM agent_index_files WHERE path = ?')->execute([$path]);
        return count($names);
    }
}
