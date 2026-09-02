<?php
namespace Ai\Agent\Code;

/**
 * ProjectIndex——项目索引值对象
 *
 * 一个项目"长什么样"的结构化快照：什么语言、什么框架、入口在哪、
 * 控制器 / 模型 / 服务 / 配置分别在哪些文件、依赖了什么。
 *
 * Agent 拿到它就不用每次都 `find` + `grep` 一遍项目结构；索引落成 JSON 文件后
 * 跨会话复用。
 *
 * ```php
 * $index = new ProjectIndex('/var/www/project');
 * $index->set('framework', 'CodeIgniter');
 * $index->addFile('controllers', 'application/controllers/Auth.php');
 * echo $index->toSummary();   // 注入提示词用的摘要
 * ```
 */
class ProjectIndex
{
    /** @var string[] 索引里的文件分类 */
    protected static $categories = [
        'controllers', 'models', 'services', 'configs', 'routes', 'tests', 'views', 'entries',
    ];

    /** @var string 项目根目录 */
    protected $root = '';

    /** @var string */
    protected $language = 'PHP';

    /** @var string 框架名，识别不出为空串 */
    protected $framework = '';

    /** @var string 入口文件相对路径 */
    protected $entry = '';

    /** @var array<string, string[]> 分类 => 相对路径列表 */
    protected $files = [];

    /** @var array<string, string> composer 依赖：包名 => 版本约束 */
    protected $dependencies = [];

    /** @var array<string, string> PSR-4 命名空间前缀 => 目录 */
    protected $namespaces = [];

    /** @var array<string, mixed> 数据库相关信息（识别到的配置文件、驱动等） */
    protected $database = [];

    /** @var array<string, mixed> 其它元信息（统计、扫描耗时等） */
    protected $meta = [];

    /** @var int 建立索引的时间戳 */
    protected $indexedAt = 0;

    /**
     * @param string $root
     * @param array<string, mixed> $data
     */
    public function __construct($root = '', array $data = [])
    {
        $this->root = rtrim(str_replace('\\', '/', (string) $root), '/');
        foreach (self::$categories as $category) {
            $this->files[$category] = [];
        }
        $this->indexedAt = time();

        if ($data) {
            $this->fill($data);
        }
    }

    /**
     * 用数组填充索引（用于从 JSON 还原）
     *
     * @param array<string, mixed> $data
     * @return $this
     */
    public function fill(array $data)
    {
        foreach (['root', 'language', 'framework', 'entry'] as $key) {
            if (isset($data[$key])) {
                $this->$key = (string) $data[$key];
            }
        }
        foreach (['dependencies', 'namespaces', 'database', 'meta'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $this->$key = $data[$key];
            }
        }
        if (isset($data['files']) && is_array($data['files'])) {
            foreach ($data['files'] as $category => $list) {
                if (is_array($list)) {
                    $this->files[(string) $category] = array_values(array_map('strval', $list));
                }
            }
        }
        if (isset($data['indexedAt'])) {
            $this->indexedAt = (int) $data['indexedAt'];
        }
        return $this;
    }

    /**
     * 设置一个标量字段
     *
     * @param string $key language|framework|entry|root
     * @param string $value
     * @return $this
     */
    public function set($key, $value)
    {
        $key = (string) $key;
        if (in_array($key, ['root', 'language', 'framework', 'entry'], true)) {
            $this->$key = (string) $value;
        }
        return $this;
    }

    /**
     * 往某个分类里加文件
     *
     * @param string $category
     * @param string $relativePath
     * @return $this
     */
    public function addFile($category, $relativePath)
    {
        $category = (string) $category;
        $relativePath = (string) $relativePath;
        if (!isset($this->files[$category])) {
            $this->files[$category] = [];
        }
        if ($relativePath !== '' && !in_array($relativePath, $this->files[$category], true)) {
            $this->files[$category][] = $relativePath;
        }
        return $this;
    }

    /**
     * 取某个分类的文件列表
     *
     * @param string $category
     * @return string[]
     */
    public function getFiles($category)
    {
        $category = (string) $category;
        return isset($this->files[$category]) ? $this->files[$category] : [];
    }

    /**
     * @return array<string, string[]>
     */
    public function getAllFiles()
    {
        return $this->files;
    }

    /** @return string */
    public function getRoot()
    {
        return $this->root;
    }

    /** @return string */
    public function getLanguage()
    {
        return $this->language;
    }

    /** @return string */
    public function getFramework()
    {
        return $this->framework;
    }

    /** @return string */
    public function getEntry()
    {
        return $this->entry;
    }

    /**
     * @return array<string, string>
     */
    public function getDependencies()
    {
        return $this->dependencies;
    }

    /**
     * @param array<string, string> $dependencies
     * @return $this
     */
    public function setDependencies(array $dependencies)
    {
        $this->dependencies = $dependencies;
        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getNamespaces()
    {
        return $this->namespaces;
    }

    /**
     * @param array<string, string> $namespaces
     * @return $this
     */
    public function setNamespaces(array $namespaces)
    {
        $this->namespaces = $namespaces;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDatabase()
    {
        return $this->database;
    }

    /**
     * @param array<string, mixed> $database
     * @return $this
     */
    public function setDatabase(array $database)
    {
        $this->database = $database;
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
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function setMeta($key, $value)
    {
        $this->meta[(string) $key] = $value;
        return $this;
    }

    /** @return int */
    public function getIndexedAt()
    {
        return $this->indexedAt;
    }

    /**
     * @param int $timestamp
     * @return $this
     */
    public function setIndexedAt($timestamp)
    {
        $this->indexedAt = (int) $timestamp;
        return $this;
    }

    /**
     * 索引里的文件总数
     *
     * @return int
     */
    public function countFiles()
    {
        $n = 0;
        foreach ($this->files as $list) {
            $n += count($list);
        }
        return $n;
    }

    /**
     * 注入提示词用的项目摘要
     *
     * 每个分类只列前几个文件——目的是让模型知道"项目长这样、去哪找"，
     * 不是把文件清单整份搬进上下文。
     *
     * @param int $perCategory 每个分类最多列几个文件
     * @return string
     */
    public function toSummary($perCategory = 5)
    {
        $perCategory = max(1, (int) $perCategory);
        $lines = [];
        $lines[] = '<project>';
        $lines[] = '根目录: ' . $this->root;
        $lines[] = '语言: ' . $this->language;
        if ($this->framework !== '') {
            $lines[] = '框架: ' . $this->framework;
        }
        if ($this->entry !== '') {
            $lines[] = '入口: ' . $this->entry;
        }
        if ($this->namespaces) {
            $parts = [];
            foreach ($this->namespaces as $prefix => $dir) {
                $parts[] = $prefix . ' → ' . $dir;
            }
            $lines[] = '命名空间: ' . implode('，', $parts);
        }

        foreach ($this->files as $category => $list) {
            if (!$list) {
                continue;
            }
            $shown = array_slice($list, 0, $perCategory);
            $line = $category . '(' . count($list) . '): ' . implode(', ', $shown);
            if (count($list) > $perCategory) {
                $line .= ' …';
            }
            $lines[] = $line;
        }

        if ($this->dependencies) {
            $lines[] = '依赖: ' . implode(', ', array_slice(array_keys($this->dependencies), 0, 10));
        }
        if ($this->database) {
            $lines[] = '数据库: ' . json_encode($this->database, JSON_UNESCAPED_UNICODE);
        }
        $lines[] = '</project>';
        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'root'         => $this->root,
            'language'     => $this->language,
            'framework'    => $this->framework,
            'entry'        => $this->entry,
            'files'        => $this->files,
            'dependencies' => $this->dependencies,
            'namespaces'   => $this->namespaces,
            'database'     => $this->database,
            'meta'         => $this->meta,
            'indexedAt'    => $this->indexedAt,
        ];
    }

    /**
     * @return string
     */
    public function toJson()
    {
        $json = json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return $json === false ? '{}' : $json;
    }

    /**
     * @param string $json
     * @return self
     */
    public static function fromJson($json)
    {
        $data = json_decode((string) $json, true);
        if (!is_array($data)) {
            $data = [];
        }
        $root = isset($data['root']) ? (string) $data['root'] : '';
        return new self($root, $data);
    }

    /**
     * 全部分类名
     *
     * @return string[]
     */
    public static function categories()
    {
        return self::$categories;
    }
}
