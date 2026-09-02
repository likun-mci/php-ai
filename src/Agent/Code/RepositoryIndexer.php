<?php
namespace Ai\Agent\Code;

/**
 * RepositoryIndexer——仓库索引器
 *
 * 首次进入一个项目时扫一遍结构，认出框架、入口、控制器 / 模型 / 服务 / 配置在哪，
 * 存成 `project.index.json`。之后 Agent 直接读索引，不用每次都 `find` + `grep`
 * 把项目结构重新摸一遍。
 *
 * ```php
 * $indexer = new RepositoryIndexer();
 * $index = $indexer->index('/var/www/project');   // 扫描并落盘
 *
 * echo $index->getFramework();     // 'Laravel'
 * echo $index->toSummary();        // 注入提示词的项目摘要
 *
 * // 下次直接读
 * $index = $indexer->loadIndex('/var/www/project');
 * if ($indexer->isIndexStale('/var/www/project')) {
 *     $index = $indexer->refreshIndex('/var/www/project');
 * }
 * ```
 *
 * 框架识别基于 composer 依赖与目录约定，覆盖 Laravel / Symfony / CodeIgniter /
 * ThinkPHP / Yii / WordPress / Slim。认不出来时 `getFramework()` 返回空串——
 * 宁可留空也不猜错，猜错会让模型按错误的约定去找文件。
 */
class RepositoryIndexer
{
    /** @var string 索引文件名 */
    protected $indexFile = 'project.index.json';

    /** @var string[] 扫描时跳过的目录 */
    protected $excludeDirs = ['vendor', 'node_modules', '.git', 'storage', 'cache', 'runtime', 'tmp'];

    /** @var int 索引有效期（秒），超过视为过期 */
    protected $ttl = 86400;

    /** @var int 单个分类最多收录的文件数，避免大项目索引膨胀 */
    protected $maxPerCategory = 200;

    /** @var array<string, string[]> 框架 => composer 包名特征 */
    protected static $frameworkPackages = [
        'Laravel'      => ['laravel/framework', 'illuminate/support'],
        'Symfony'      => ['symfony/framework-bundle', 'symfony/symfony'],
        'CodeIgniter'  => ['codeigniter4/framework', 'codeigniter/framework'],
        'ThinkPHP'     => ['topthink/framework'],
        'Yii'          => ['yiisoft/yii2'],
        'Slim'         => ['slim/slim'],
        'CakePHP'      => ['cakephp/cakephp'],
        'Laminas'      => ['laminas/laminas-mvc'],
    ];

    /** @var array<string, string[]> 框架 => 目录 / 文件特征 */
    protected static $frameworkPaths = [
        'Laravel'     => ['artisan', 'app/Http/Controllers'],
        'CodeIgniter' => ['application/controllers', 'system/core/CodeIgniter.php'],
        'ThinkPHP'    => ['think', 'application/index/controller'],
        'WordPress'   => ['wp-config.php', 'wp-content'],
        'Symfony'     => ['bin/console', 'src/Controller'],
        'Yii'         => ['yii', 'protected/controllers'],
    ];

    /**
     * @param array<string, mixed> $options indexFile / excludeDirs / ttl / maxPerCategory
     */
    public function __construct(array $options = [])
    {
        if (isset($options['indexFile'])) {
            $this->indexFile = (string) $options['indexFile'];
        }
        if (isset($options['excludeDirs']) && is_array($options['excludeDirs'])) {
            $this->excludeDirs = array_values(array_map('strval', $options['excludeDirs']));
        }
        if (isset($options['ttl'])) {
            $this->ttl = max(0, (int) $options['ttl']);
        }
        if (isset($options['maxPerCategory'])) {
            $this->maxPerCategory = max(1, (int) $options['maxPerCategory']);
        }
    }

    /**
     * 扫描项目并建立索引（同时落盘）
     *
     * @param string $rootDir
     * @return ProjectIndex
     */
    public function index($rootDir)
    {
        $rootDir = rtrim(str_replace('\\', '/', (string) $rootDir), '/');
        $index = new ProjectIndex($rootDir);

        if ($rootDir === '' || !is_dir($rootDir)) {
            return $index;
        }

        $start = microtime(true);
        $composer = $this->readComposer($rootDir);
        if ($composer) {
            $index->setDependencies($this->extractDependencies($composer));
            $index->setNamespaces($this->extractNamespaces($composer));
        }

        $index->set('framework', $this->detectFramework($rootDir, $composer));
        $index->set('entry', $this->detectEntry($rootDir));

        foreach ($this->scanFiles($rootDir) as $relative) {
            $category = $this->categorize($relative);
            if ($category !== '' && count($index->getFiles($category)) < $this->maxPerCategory) {
                $index->addFile($category, $relative);
            }
        }

        $index->setDatabase($this->detectDatabase($rootDir));
        $index->setMeta('scan_ms', round((microtime(true) - $start) * 1000, 1));
        $index->setMeta('total_files', $index->countFiles());
        $index->setIndexedAt(time());

        $this->saveIndex($index);
        return $index;
    }

    /**
     * 读取已有索引
     *
     * @param string $rootDir
     * @return ProjectIndex|null 索引不存在或损坏时返回 null
     */
    public function loadIndex($rootDir)
    {
        $file = $this->indexPath($rootDir);
        if ($file === '' || !is_file($file)) {
            return null;
        }
        $json = @file_get_contents($file);
        if ($json === false || trim($json) === '') {
            return null;
        }
        $index = ProjectIndex::fromJson($json);
        return $index->getRoot() === '' ? null : $index;
    }

    /**
     * 落盘
     *
     * @param ProjectIndex $index
     * @return bool
     */
    public function saveIndex(ProjectIndex $index)
    {
        $file = $this->indexPath($index->getRoot());
        if ($file === '') {
            return false;
        }
        return @file_put_contents($file, $index->toJson()) !== false;
    }

    /**
     * 索引是不是过期了
     *
     * 判据有两条：超过 ttl，或 composer.json 比索引新（依赖变了，结构多半也变了）。
     * 逐个文件比 mtime 太慢，大项目上得不偿失。
     *
     * @param string $rootDir
     * @return bool 索引不存在时返回 true
     */
    public function isIndexStale($rootDir)
    {
        $index = $this->loadIndex($rootDir);
        if ($index === null) {
            return true;
        }
        if ($this->ttl > 0 && time() - $index->getIndexedAt() > $this->ttl) {
            return true;
        }

        $rootDir = rtrim(str_replace('\\', '/', (string) $rootDir), '/');
        $composerFile = $rootDir . '/composer.json';
        if (is_file($composerFile) && (int) @filemtime($composerFile) > $index->getIndexedAt()) {
            return true;
        }
        return false;
    }

    /**
     * 强制重建索引
     *
     * @param string $rootDir
     * @return ProjectIndex
     */
    public function refreshIndex($rootDir)
    {
        return $this->index($rootDir);
    }

    /**
     * 拿到可用的索引——有且没过期就复用，否则重建
     *
     * @param string $rootDir
     * @return ProjectIndex
     */
    public function ensureIndex($rootDir)
    {
        if (!$this->isIndexStale($rootDir)) {
            $index = $this->loadIndex($rootDir);
            if ($index !== null) {
                return $index;
            }
        }
        return $this->index($rootDir);
    }

    /**
     * 删除索引文件
     *
     * @param string $rootDir
     * @return bool
     */
    public function deleteIndex($rootDir)
    {
        $file = $this->indexPath($rootDir);
        return $file !== '' && is_file($file) ? @unlink($file) : false;
    }

    /**
     * 索引文件路径
     *
     * @param string $rootDir
     * @return string
     */
    public function indexPath($rootDir)
    {
        $rootDir = rtrim(str_replace('\\', '/', (string) $rootDir), '/');
        return $rootDir === '' ? '' : $rootDir . '/' . $this->indexFile;
    }

    /**
     * 识别框架
     *
     * @param string $rootDir
     * @param array<string, mixed> $composer
     * @return string 认不出返回空串
     */
    public function detectFramework($rootDir, array $composer = [])
    {
        // composer 依赖最可靠
        $require = [];
        foreach (['require', 'require-dev'] as $section) {
            if (isset($composer[$section]) && is_array($composer[$section])) {
                $require = array_merge($require, array_keys($composer[$section]));
            }
        }
        foreach (self::$frameworkPackages as $framework => $packages) {
            foreach ($packages as $package) {
                if (in_array($package, $require, true)) {
                    return $framework;
                }
            }
        }

        // 退而求其次：看目录特征
        foreach (self::$frameworkPaths as $framework => $paths) {
            $hits = 0;
            foreach ($paths as $path) {
                if (file_exists($rootDir . '/' . $path)) {
                    $hits++;
                }
            }
            if ($hits === count($paths)) {
                return $framework;
            }
        }
        return '';
    }

    /**
     * 识别入口文件
     *
     * @param string $rootDir
     * @return string 相对路径，找不到返回空串
     */
    public function detectEntry($rootDir)
    {
        $candidates = [
            'public/index.php', 'index.php', 'web/index.php', 'html/index.php',
            'artisan', 'bin/console', 'app.php',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($rootDir . '/' . $candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * 按路径把文件归类
     *
     * 只认路径里的目录名与文件名后缀这类强信号——读文件内容判断代价太高，
     * 而 `app/Http/Controllers/Auth.php` 这样的路径本身已经说明问题。
     *
     * @param string $relative 相对路径
     * @return string 分类名，归不了类返回空串
     */
    public function categorize($relative)
    {
        $lower = strtolower((string) $relative);
        $base = basename($lower);

        if (preg_match('#(^|/)(tests?|spec)(/|$)#', $lower) || substr($base, -8) === 'test.php') {
            return 'tests';
        }
        if (strpos($lower, 'controller') !== false) {
            return 'controllers';
        }
        if (preg_match('#(^|/)models?(/|$)#', $lower)
            || substr($base, -9) === 'model.php'
            || preg_match('#(^|/)(entity|entities|repositor(y|ies))(/|$)#', $lower)) {
            return 'models';
        }
        if (preg_match('#(^|/)(services?|service)(/|$)#', $lower) || substr($base, -11) === 'service.php') {
            return 'services';
        }
        if (preg_match('#(^|/)(config|configs|settings)(/|$)#', $lower)
            || in_array($base, ['config.php', 'database.php', '.env.example'], true)) {
            return 'configs';
        }
        if (preg_match('#(^|/)routes?(/|$)#', $lower) || $base === 'routes.php' || $base === 'web.php') {
            return 'routes';
        }
        if (preg_match('#(^|/)(views?|templates?|resources/views)(/|$)#', $lower)
            || substr($base, -10) === '.blade.php') {
            return 'views';
        }
        if (in_array($base, ['index.php', 'artisan', 'console', 'app.php'], true)) {
            return 'entries';
        }
        return '';
    }

    /**
     * 识别数据库相关信息
     *
     * @param string $rootDir
     * @return array<string, mixed>
     */
    public function detectDatabase($rootDir)
    {
        $info = [];
        $configFiles = [
            'config/database.php', 'application/config/database.php',
            'app/config/database.php', 'config/db.php', '.env',
        ];
        foreach ($configFiles as $file) {
            if (is_file($rootDir . '/' . $file)) {
                $info['config'] = $file;
                break;
            }
        }

        foreach (['database/migrations', 'migrations', 'db/migrate'] as $dir) {
            if (is_dir($rootDir . '/' . $dir)) {
                $info['migrations'] = $dir;
                break;
            }
        }

        // 从 .env 里读驱动名——只读这一个键，不解析整份配置
        $env = $rootDir . '/.env';
        if (is_file($env)) {
            $content = @file_get_contents($env);
            if ($content !== false && preg_match('/^DB_CONNECTION\s*=\s*(\S+)/m', $content, $m)) {
                $info['driver'] = trim($m[1], "\"'");
            }
        }
        return $info;
    }

    /**
     * 读 composer.json
     *
     * @param string $rootDir
     * @return array<string, mixed> 读不到返回空数组
     */
    public function readComposer($rootDir)
    {
        $file = rtrim(str_replace('\\', '/', (string) $rootDir), '/') . '/composer.json';
        if (!is_file($file)) {
            return [];
        }
        $json = @file_get_contents($file);
        if ($json === false) {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * 从 composer.json 提取依赖
     *
     * @param array<string, mixed> $composer
     * @return array<string, string>
     */
    protected function extractDependencies(array $composer)
    {
        $deps = [];
        foreach (['require', 'require-dev'] as $section) {
            if (isset($composer[$section]) && is_array($composer[$section])) {
                foreach ($composer[$section] as $package => $version) {
                    $deps[(string) $package] = (string) $version;
                }
            }
        }
        return $deps;
    }

    /**
     * 从 composer.json 提取 PSR-4 命名空间映射
     *
     * @param array<string, mixed> $composer
     * @return array<string, string>
     */
    protected function extractNamespaces(array $composer)
    {
        $namespaces = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            if (!isset($composer[$section]['psr-4']) || !is_array($composer[$section]['psr-4'])) {
                continue;
            }
            foreach ($composer[$section]['psr-4'] as $prefix => $dir) {
                $namespaces[(string) $prefix] = is_array($dir)
                    ? (string) reset($dir)
                    : (string) $dir;
            }
        }
        return $namespaces;
    }

    /**
     * 遍历项目里的 PHP 文件，返回相对路径
     *
     * @param string $rootDir
     * @param string $prefix 递归用的相对前缀
     * @return string[]
     */
    protected function scanFiles($rootDir, $prefix = '')
    {
        $dir = $prefix === '' ? $rootDir : $rootDir . '/' . $prefix;
        $entries = @scandir($dir);
        if ($entries === false) {
            return [];
        }

        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || strpos($entry, '.') === 0) {
                continue;
            }
            $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;
            $path = $rootDir . '/' . $relative;

            if (is_dir($path)) {
                if (in_array($entry, $this->excludeDirs, true)) {
                    continue;
                }
                foreach ($this->scanFiles($rootDir, $relative) as $sub) {
                    $files[] = $sub;
                }
                continue;
            }
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            if ($ext === 'php') {
                $files[] = $relative;
            }
        }
        return $files;
    }

    /**
     * @return string
     */
    public function getIndexFile()
    {
        return $this->indexFile;
    }

    /**
     * @param int $ttl
     * @return $this
     */
    public function setTtl($ttl)
    {
        $this->ttl = max(0, (int) $ttl);
        return $this;
    }

    /** @return int */
    public function getTtl()
    {
        return $this->ttl;
    }
}
