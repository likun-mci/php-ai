<?php
namespace Ai\Agent\Indexer;

use Ai\Agent\Registry\RegistryException;
use Ai\Agent\Registry\ToolRegistryInterface;
use Ai\Agent\Tool\ToolDefinition;

/**
 * Tool Indexer —— 把 PHP 源码里的 Agent 标注扫成 Registry 里的 Tool
 *
 * 应用只负责说「扫哪些目录」，其余（文件遍历、Reflection、PHPDoc/Attribute 解析、
 * JSON Schema 生成、源码 hash、增量、写库）全部由 php-ai 承担（规范 §31.1）。
 *
 * ```php
 * $registry = new SqliteToolRegistry(APP_PATH . '/.ai/registry.sqlite');
 * $indexer  = new ToolIndexer($registry);
 *
 * $result = $indexer->scan([
 *     APP_PATH . '/Controller',
 *     APP_PATH . '/Service',
 * ]);
 * echo $result->summary();
 * ```
 *
 * 两条硬规则：
 *
 * 1. **只收显式标注的方法**（规范 §31.10）。没有 `@agent-tool` / `#[AgentTool]` 的
 *    public 方法一律不进 Registry，内部 API 与辅助方法不会被误暴露。
 * 2. **不含标注的文件不会被 include**。先做纯文本预筛，再 token 级取类名，
 *    最后才 `require`——业务代码里的顶层副作用不会因为一次索引被跑起来。
 *
 * 增量（规范 §18）：每个文件的内容 hash 存在 Registry 里，hash 没变直接跳过；
 * 本次扫描没再出现的文件，其名下 Tool 全部删除（方法被删/改名也能收敛）。
 *
 * ⚠️ **一个进程里同一个类只会被读到一次。** PHP 没有「卸载并重新载入类」这回事，
 * 所以在**同一次 PHP 执行**里改了源码再 `scan()`，Reflection 拿到的仍是先前载入的
 * 那份定义（hash 变了、文件会被重新解析，但读到的 docblock 是旧的）。
 * 这不影响正常用法——`php-ai index` 每次都是新进程；只有「同一个请求里先改文件
 * 再重新索引」这种写法会踩到，那种场景应当另起一个进程去索引。
 */
class ToolIndexer
{
    /** @var ToolRegistryInterface */
    protected $registry;

    /** @var PhpDocParser */
    protected $docParser;

    /** @var AttributeParser */
    protected $attrParser;

    /** @var ReflectionSchemaBuilder */
    protected $schemaBuilder;

    /** @var string[] 遍历时跳过的目录名 */
    protected $excludes = ['vendor', 'node_modules', '.git', '.svn', 'cache', 'runtime', 'storage'];

    /** @var string[] 认作 PHP 源码的扩展名 */
    protected $extensions = ['php'];

    /** @var bool 是否跟随符号链接（默认否，避免目录环） */
    protected $followSymlinks = false;

    /** @var int 单文件大小上限（字节），超过视为不是手写源码 */
    protected $maxFileSize = 4194304;

    /** @var callable|null 进度回调 function(string $file, string $stage) */
    protected $onProgress = null;

    /**
     * @param ToolRegistryInterface $registry
     * @param array<string, mixed> $options excludes / extensions / follow_symlinks / max_file_size
     */
    public function __construct(ToolRegistryInterface $registry, array $options = [])
    {
        $this->registry      = $registry;
        $this->docParser     = new PhpDocParser();
        $this->attrParser    = new AttributeParser();
        $this->schemaBuilder = new ReflectionSchemaBuilder();

        if (isset($options['excludes']) && is_array($options['excludes'])) {
            $this->excludes = array_values(array_map('strval', $options['excludes']));
        }
        if (isset($options['extensions']) && is_array($options['extensions'])) {
            $this->extensions = array_values(array_map('strval', $options['extensions']));
        }
        if (isset($options['follow_symlinks'])) {
            $this->followSymlinks = (bool) $options['follow_symlinks'];
        }
        if (isset($options['max_file_size'])) {
            $this->maxFileSize = max(1024, (int) $options['max_file_size']);
        }
    }

    /**
     * @param string[] $dirs
     * @return $this
     */
    public function setExcludes(array $dirs)
    {
        $this->excludes = array_values(array_map('strval', $dirs));
        return $this;
    }

    /**
     * @param callable|null $cb function(string $file, string $stage)
     * @return $this
     */
    public function onProgress($cb)
    {
        $this->onProgress = is_callable($cb) ? $cb : null;
        return $this;
    }

    /** @return ToolRegistryInterface */
    public function registry()
    {
        return $this->registry;
    }

    /**
     * 扫描目录并写入 Registry
     *
     * @param string|string[] $paths 目录或文件路径
     * @param array<string, mixed> $options force(bool) 忽略 hash 全部重扫 /
     *                                      prune(bool) 是否清理消失文件的 Tool（默认 true）
     * @return IndexResult
     */
    public function scan($paths, array $options = [])
    {
        $start  = microtime(true);
        $result = new IndexResult();

        $force = !empty($options['force']);
        $prune = !isset($options['prune']) || $options['prune'];

        $files = $this->collectFiles($paths, $result);
        $seen  = [];

        // 同一次扫描里出现重名 Tool 要报错而不是静默覆盖——两个文件抢一个名字，
        // 谁赢取决于目录遍历顺序，那是最难查的一类「配置生效不稳定」
        $claimed = [];

        foreach ($files as $file) {
            $result->filesScanned++;
            $seen[$file] = true;

            $contents = @file_get_contents($file);
            if ($contents === false) {
                $result->addError('读取失败: ' . $file);
                continue;
            }

            $hash = hash('sha256', $contents);
            $old  = $this->registry->getFileHash($file);

            if (!$force && $old !== null && $old === $hash) {
                $result->filesSkipped++;
                continue;
            }

            // 预筛：没有标记的文件连 token 都不用扫，更不会被 include
            if (!ClassLocator::looksLikeTool($contents)) {
                // 之前有过 Tool、现在标注被删掉了：要把旧的清掉
                if ($old !== null) {
                    $result->toolsRemoved += $this->registry->removeFile($file);
                }
                $this->registry->setFileHash($file, $hash, 0);
                $result->filesSkipped++;
                continue;
            }

            $result->filesParsed++;
            $this->progress($file, 'parse');

            $defs = $this->definitionsInFile($file, $contents, $result);

            // 先删掉这个文件上一轮留下的 Tool，再写新的——方法被改名/删除时才收敛
            $existingNames = $this->toolNamesOfFile($file);
            $newNames      = [];
            foreach ($defs as $def) {
                $newNames[$def->getName()] = true;
            }
            foreach ($existingNames as $n) {
                if (!isset($newNames[$n])) {
                    $this->registry->remove($n);
                    $result->toolsRemoved++;
                }
            }

            foreach ($defs as $def) {
                $name = $def->getName();
                if (isset($claimed[$name]) && $claimed[$name] !== $file) {
                    $result->addError(
                        'Tool 名重复: ' . $name . ' 同时出现在 ' . $claimed[$name] . ' 与 ' . $file
                        . '，保留前者'
                    );
                    continue;
                }
                $claimed[$name] = $file;

                $isNew = !in_array($name, $existingNames, true) && $this->registry->get($name) === null;
                $def->setHash($hash);
                $this->registry->register($def);
                $result->tools[] = $name;
                if ($isNew) {
                    $result->toolsAdded++;
                } else {
                    $result->toolsUpdated++;
                }
            }

            $this->registry->setFileHash($file, $hash, count($defs));
        }

        // 清理：库里记着、但这次扫描路径下已经不存在的文件
        if ($prune) {
            foreach ($this->registry->fileHashes() as $path => $_hash) {
                if (isset($seen[$path])) {
                    continue;
                }
                if (is_file($path)) {
                    // 文件还在，只是这次没扫这个目录——不动它
                    continue;
                }
                $result->toolsRemoved += $this->registry->removeFile($path);
            }
        }

        $result->duration = microtime(true) - $start;
        return $result;
    }

    /**
     * 只检查不写库：索引是否已经过期
     *
     * 部署自检与 CLI `--check` 用。不会修改 Registry。
     *
     * @param string|string[] $paths
     * @return IndexResult filesParsed > 0 或 toolsRemoved > 0 即表示 stale
     */
    public function check($paths)
    {
        $start  = microtime(true);
        $result = new IndexResult();

        $files = $this->collectFiles($paths, $result);
        $seen  = [];

        foreach ($files as $file) {
            $result->filesScanned++;
            $seen[$file] = true;

            $contents = @file_get_contents($file);
            if ($contents === false) {
                $result->addError('读取失败: ' . $file);
                continue;
            }
            $hash = hash('sha256', $contents);
            $old  = $this->registry->getFileHash($file);
            if ($old !== null && $old === $hash) {
                $result->filesSkipped++;
                continue;
            }
            $result->filesParsed++;
        }

        foreach ($this->registry->fileHashes() as $path => $_hash) {
            if (!isset($seen[$path]) && !is_file($path)) {
                $result->toolsRemoved++;
            }
        }

        $result->duration = microtime(true) - $start;
        return $result;
    }

    /**
     * 索引单个类（模块安装 / 插件动态注册用，规范 §31.7 的 API 场景）
     *
     * @param string|object $class 类名或实例
     * @return IndexResult
     */
    public function scanClass($class)
    {
        $start  = microtime(true);
        $result = new IndexResult();

        $name = is_object($class) ? get_class($class) : (string) $class;
        if (!class_exists($name)) {
            $result->addError('类不存在: ' . $name);
            $result->duration = microtime(true) - $start;
            return $result;
        }

        // class_exists 已经确认它存在，ReflectionClass 不会再抛
        $rc = new \ReflectionClass($name);

        $file = (string) $rc->getFileName();
        $hash = '';
        if ($file !== '' && is_file($file)) {
            $contents = (string) @file_get_contents($file);
            $hash     = hash('sha256', $contents);
        }

        $result->filesScanned = 1;
        $result->filesParsed  = 1;

        foreach ($this->definitionsInClass($rc, $result) as $def) {
            $isNew = $this->registry->get($def->getName()) === null;
            $def->setHash($hash);
            $this->registry->register($def);
            $result->tools[] = $def->getName();
            if ($isNew) {
                $result->toolsAdded++;
            } else {
                $result->toolsUpdated++;
            }
        }

        if ($file !== '' && $hash !== '') {
            $this->registry->setFileHash($file, $hash, count($result->tools));
        }

        $result->duration = microtime(true) - $start;
        return $result;
    }

    /**
     * 清空 Registry
     *
     * @return void
     */
    public function clear()
    {
        $this->registry->clear();
    }

    /**
     * 某个源文件当前在 Registry 里的 Tool 名
     *
     * @param string $file
     * @return string[]
     */
    protected function toolNamesOfFile($file)
    {
        $out = [];
        foreach ($this->registry->all(true) as $tool) {
            if ($tool->getSourceFile() === $file) {
                $out[] = $tool->getName();
            }
        }
        return $out;
    }

    /**
     * 从一个文件里解析出全部 ToolDefinition
     *
     * @param string $file
     * @param string $contents
     * @param IndexResult $result
     * @return ToolDefinition[]
     */
    protected function definitionsInFile($file, $contents, IndexResult $result)
    {
        $classes = ClassLocator::classesIn($contents);
        if ($classes === []) {
            return [];
        }

        $defs = [];
        foreach ($classes as $fqcn) {
            if (!ClassLocator::ensureLoaded($fqcn, $file)) {
                $result->addError('类无法载入: ' . $fqcn . ' (' . $file . ')');
                continue;
            }
            // ensureLoaded 已经保证载入成功，这里再判一次是给静态分析看的
            if (!class_exists($fqcn)) {
                continue;
            }
            $rc = new \ReflectionClass($fqcn);
            // 只索引本文件里声明的类：一个文件 require 了别的类时不要重复收
            $rcFile = $rc->getFileName();
            if ($rcFile !== false && realpath($rcFile) !== realpath($file)) {
                continue;
            }
            foreach ($this->definitionsInClass($rc, $result) as $def) {
                $defs[] = $def;
            }
        }
        return $defs;
    }

    /**
     * @param \ReflectionClass<object> $rc
     * @param IndexResult $result
     * @return ToolDefinition[]
     */
    protected function definitionsInClass(\ReflectionClass $rc, IndexResult $result)
    {
        if ($rc->isAbstract() || $rc->isInterface() || $rc->isTrait()) {
            return [];
        }

        $defs = [];
        foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() && $method->isConstructor()) {
                continue;
            }
            if ($method->isConstructor() || $method->isDestructor()) {
                continue;
            }
            // 继承来的方法归它自己的类去索引，避免子类重复产出同名 Tool
            if ($method->getDeclaringClass()->getName() !== $rc->getName()) {
                continue;
            }

            $def = $this->buildDefinition($rc, $method, $result);
            if ($def !== null) {
                $defs[] = $def;
            }
        }
        return $defs;
    }

    /**
     * 单个方法 → ToolDefinition（没有标注则返回 null）
     *
     * 字段合并优先级（规范 §6）：Attribute > PHPDoc > Reflection 推断。
     *
     * @param \ReflectionClass<object> $rc
     * @param \ReflectionMethod $method
     * @param IndexResult $result
     * @return ToolDefinition|null
     */
    protected function buildDefinition(\ReflectionClass $rc, \ReflectionMethod $method, IndexResult $result)
    {
        $doc    = $this->docParser->parse($method->getDocComment());
        $attr   = $this->attrParser->parse($method);

        $name = isset($attr['name']) && $attr['name'] !== '' ? (string) $attr['name'] : $doc['tool'];
        if ($name === '') {
            // 没有 @agent-tool 也没有 #[AgentTool] —— 不是 Tool，跳过（规范 §31.10）
            return null;
        }

        $params = $this->schemaBuilder->build($method, $doc['params']);

        $data = [
            'name'            => $name,
            'description'     => $doc['description'],
            'controller_path' => $doc['controller'],
            'risk'            => $doc['risk'],
            'permissions'     => $doc['permission'],
            'keywords'        => $doc['keywords'],
            'version'         => $doc['version'],
            'returns'         => $doc['return']['description'],
            'class_name'      => $rc->getName(),
            'method_name'     => $method->getName(),
            'source_file'     => (string) $rc->getFileName(),
            'source_line'     => (int) $method->getStartLine(),
            'parameters'      => $params,
        ];
        if ($doc['confirm'] !== null) {
            $data['requires_confirmation'] = $doc['confirm'];
        }
        if ($doc['enabled'] !== null) {
            $data['enabled'] = $doc['enabled'];
        }

        // Attribute 显式给过的字段覆盖 PHPDoc
        foreach ($attr as $k => $v) {
            $data[$k] = $v;
        }

        if ((string) $data['description'] === '') {
            $data['description'] = $name;
        }

        $def = new ToolDefinition($data);

        if ($def->getControllerPath() === '') {
            // 不是致命错误（Registry 里留着能被搜到），但执行会被 Executor 拒绝，
            // 所以要在索引阶段就把话说清楚
            $result->addError(
                'Tool ' . $name . ' 缺少 @agent-controller，将无法执行 ('
                . $rc->getName() . '::' . $method->getName() . ')'
            );
        }

        return $def;
    }

    /**
     * 收集待扫描的 PHP 文件（绝对路径，已排序去重）
     *
     * @param string|string[] $paths
     * @param IndexResult $result
     * @return string[]
     */
    public function collectFiles($paths, IndexResult $result)
    {
        if (is_string($paths)) {
            $paths = [$paths];
        }
        if (!is_array($paths)) {
            throw new RegistryException('scan() 需要目录路径字符串或数组');
        }

        $files = [];
        foreach ($paths as $path) {
            $path = (string) $path;
            if ($path === '') {
                continue;
            }
            $real = realpath($path);
            if ($real === false) {
                $result->addError('路径不存在: ' . $path);
                continue;
            }
            if (is_file($real)) {
                if ($this->isPhpFile($real)) {
                    $files[$real] = true;
                }
                continue;
            }
            foreach ($this->walk($real) as $f) {
                $files[$f] = true;
            }
        }

        $out = array_keys($files);
        sort($out);
        return $out;
    }

    /**
     * 递归遍历目录
     *
     * @param string $dir
     * @return string[]
     */
    protected function walk($dir)
    {
        $out   = [];
        $stack = [$dir];
        $seen  = [];

        while ($stack !== []) {
            $current = array_pop($stack);
            $realCurrent = realpath($current);
            if ($realCurrent === false || isset($seen[$realCurrent])) {
                continue;
            }
            $seen[$realCurrent] = true;

            $handle = @opendir($current);
            if ($handle === false) {
                continue;
            }
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $current . DIRECTORY_SEPARATOR . $entry;

                if (is_link($full) && !$this->followSymlinks) {
                    continue;
                }
                if (is_dir($full)) {
                    if (in_array($entry, $this->excludes, true)) {
                        continue;
                    }
                    $stack[] = $full;
                    continue;
                }
                if ($this->isPhpFile($full)) {
                    $real = realpath($full);
                    if ($real !== false) {
                        $out[] = $real;
                    }
                }
            }
            closedir($handle);
        }
        return $out;
    }

    /**
     * @param string $file
     * @return bool
     */
    protected function isPhpFile($file)
    {
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, $this->extensions, true)) {
            return false;
        }
        $size = @filesize($file);
        return $size !== false && $size <= $this->maxFileSize;
    }

    /**
     * @param string $file
     * @param string $stage
     * @return void
     */
    protected function progress($file, $stage)
    {
        if ($this->onProgress !== null) {
            call_user_func($this->onProgress, $file, $stage);
        }
    }
}
