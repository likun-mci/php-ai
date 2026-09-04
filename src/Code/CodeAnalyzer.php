<?php
namespace Ai\Code;

/**
 * CodeAnalyzer——代码分析总入口
 *
 * 扫描一个目录，建立类索引与调用 / 依赖两张图，之后 Agent 就能回答
 * "改这个方法会影响谁"、"这个类依赖了什么"、"跟这个文件相关的还有哪些文件"，
 * 而不用每次都 grep 一遍整个项目。
 *
 * ```php
 * $analyzer = new CodeAnalyzer();
 * $analyzer->scan('/var/www/project/src');
 *
 * $analyzer->analyzeClass('App\Auth');              // ClassAnalysis
 * $analyzer->findCallers('login');                  // 谁调用了 login
 * $analyzer->findDependencies('App\Auth');          // Auth 依赖谁
 * $analyzer->findRelatedFiles('src/Auth.php');      // 相关文件
 * echo $analyzer->explain('App\Auth');              // 一段可直接注入提示词的说明
 * ```
 *
 * 扫描是一次性的：`scan()` 之后结果缓存在内存里，文件改了要重新 `scan()` 或
 * 用 `refreshFile()` 只更新单个文件。项目大时配合 `RepositoryIndexer` 落盘复用。
 */
class CodeAnalyzer
{
    /** @var FileAnalyzer */
    protected $fileAnalyzer;

    /** @var AstFileAnalyzer|null 装了 nikic/php-parser 时的精度增强解析器（可选依赖） */
    protected $astAnalyzer = null;

    /** @var ClassAnalyzer */
    protected $classAnalyzer;

    /** @var FunctionAnalyzer */
    protected $functionAnalyzer;

    /** @var array<string, FileAnalysis> 文件路径 => 分析结果 */
    protected $files = [];

    /** @var array<string, ClassAnalysis> 完整类名 => 分析结果 */
    protected $classes = [];

    /** @var CallGraph */
    protected $callGraph;

    /** @var DependencyGraph */
    protected $dependencyGraph;

    /** @var string[] 扫描时跳过的目录名 */
    protected $excludeDirs = ['vendor', 'node_modules', '.git', 'cache', 'storage'];

    /** @var int 单文件大小上限（字节），超过跳过——多半是生成的巨型文件 */
    protected $maxFileSize = 2097152;

    /**
     * @param array<string, mixed> $options excludeDirs / maxFileSize
     */
    public function __construct(array $options = [])
    {
        $this->fileAnalyzer = new FileAnalyzer();
        // 渐进增强：装了 nikic/php-parser 就走 AST（类名全限定、结构不会漏配对），
        // 没装则维持 token 级解析——本库零运行时依赖的承诺不变
        if (AstFileAnalyzer::isAvailable()) {
            $this->astAnalyzer = new AstFileAnalyzer();
        }
        $this->classAnalyzer = new ClassAnalyzer($this->fileAnalyzer);
        $this->functionAnalyzer = new FunctionAnalyzer($this->fileAnalyzer);
        $this->callGraph = new CallGraph();
        $this->dependencyGraph = new DependencyGraph();

        if (isset($options['excludeDirs']) && is_array($options['excludeDirs'])) {
            $this->excludeDirs = array_values(array_map('strval', $options['excludeDirs']));
        }
        if (isset($options['maxFileSize'])) {
            $this->maxFileSize = max(0, (int) $options['maxFileSize']);
        }
    }

    /**
     * 扫描目录，建立索引与两张图
     *
     * @param string $dir
     * @return int 分析成功的文件数
     */
    public function scan($dir)
    {
        $dir = rtrim(str_replace('\\', '/', (string) $dir), '/');
        if ($dir === '' || !is_dir($dir)) {
            return 0;
        }

        $count = 0;
        foreach ($this->phpFiles($dir) as $path) {
            if ($this->analyzeFile($path) !== null) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 分析单个文件并并入索引
     *
     * @param string $path
     * @return FileAnalysis|null
     */
    public function analyzeFile($path)
    {
        $path = (string) $path;
        // AST 解析失败（语法错误、不支持的新语法）时回退 token 版，绝不因此丢掉整个文件
        $analysis = null;
        if ($this->astAnalyzer !== null) {
            $analysis = $this->astAnalyzer->analyze($path);
        }
        if ($analysis === null) {
            $analysis = $this->fileAnalyzer->analyze($path);
        }
        if ($analysis === null) {
            return null;
        }

        $this->files[$path] = $analysis;
        foreach ($analysis->getClasses() as $name => $class) {
            $this->classes[$name] = $class;
        }
        $this->callGraph->addFile($analysis);
        $this->dependencyGraph->addFile($analysis);
        return $analysis;
    }

    /**
     * 重新分析单个文件——文件改动后用它，比整棵树重扫便宜得多
     *
     * 注意：图是增量并入的，改动前的旧边不会被撤掉。删除了方法或依赖之后
     * 需要精确结果时重新 `scan()`。
     *
     * @param string $path
     * @return FileAnalysis|null
     */
    public function refreshFile($path)
    {
        return $this->analyzeFile($path);
    }

    /**
     * 取某个类的分析结果
     *
     * 支持完整类名与短类名；短名有重名时返回第一个匹配。
     *
     * @param string $className
     * @return ClassAnalysis|null
     */
    public function analyzeClass($className)
    {
        $className = ltrim((string) $className, '\\');
        if (isset($this->classes[$className])) {
            return $this->classes[$className];
        }
        foreach ($this->classes as $name => $class) {
            if ($class->getShortName() === $className) {
                return $class;
            }
        }
        return null;
    }

    /**
     * 谁调用了指定函数 / 方法
     *
     * @param string $functionName 短名、`Foo::bar` 或完整名
     * @return string[] 调用方完整名
     */
    public function findCallers($functionName)
    {
        return $this->callGraph->callers($functionName);
    }

    /**
     * 指定类依赖了哪些类
     *
     * @param string $className
     * @param bool $transitive true 时含间接依赖
     * @return string[]
     */
    public function findDependencies($className, $transitive = false)
    {
        $class = $this->analyzeClass($className);
        $name = $class !== null ? $class->getName() : ltrim((string) $className, '\\');
        return $transitive
            ? $this->dependencyGraph->transitiveDependencies($name)
            : $this->dependencyGraph->dependenciesOf($name);
    }

    /**
     * 谁依赖了指定类——改动影响面
     *
     * @param string $className
     * @param bool $transitive
     * @return string[]
     */
    public function findDependents($className, $transitive = false)
    {
        $class = $this->analyzeClass($className);
        $name = $class !== null ? $class->getName() : ltrim((string) $className, '\\');
        return $transitive
            ? $this->dependencyGraph->transitiveDependents($name)
            : $this->dependencyGraph->dependentsOf($name);
    }

    /**
     * 与指定文件相关的其它文件
     *
     * 相关 = 这个文件里的类依赖的类所在文件 + 依赖这些类的文件。
     * Agent 拿到一个改动任务时，用它一次性把该看的文件圈出来。
     *
     * @param string $path
     * @param int $limit 最多返回几个
     * @return string[] 文件路径
     */
    public function findRelatedFiles($path, $limit = 20)
    {
        $path = (string) $path;
        if (!isset($this->files[$path])) {
            $analysis = $this->analyzeFile($path);
            if ($analysis === null) {
                return [];
            }
        }

        $related = [];
        foreach ($this->files[$path]->getClasses() as $name => $class) {
            foreach ($this->dependencyGraph->dependenciesOf($name) as $dep) {
                $file = $this->fileOfClass($dep);
                if ($file !== '' && $file !== $path) {
                    $related[$file] = true;
                }
            }
            foreach ($this->dependencyGraph->dependentsOf($name) as $dependent) {
                $file = $this->fileOfClass($dependent);
                if ($file !== '' && $file !== $path) {
                    $related[$file] = true;
                }
            }
        }
        return array_slice(array_keys($related), 0, max(1, (int) $limit));
    }

    /**
     * 找符号定义在哪——类名、函数名、方法名都行
     *
     * @param string $symbol
     * @return array<int, array{type: string, name: string, file: string, line: int}>
     */
    public function findSymbol($symbol)
    {
        $symbol = ltrim((string) $symbol, '\\');
        $hits = [];

        foreach ($this->classes as $name => $class) {
            if ($name === $symbol || $class->getShortName() === $symbol) {
                $hits[] = [
                    'type' => $class->getKind(),
                    'name' => $name,
                    'file' => $class->getFile(),
                    'line' => $class->getLine(),
                ];
            }
            foreach ($class->getMethods() as $method) {
                if ($method->getName() === $symbol || $method->getFullName() === $symbol) {
                    $hits[] = [
                        'type' => 'method',
                        'name' => $method->getFullName(),
                        'file' => $class->getFile(),
                        'line' => $method->getLine(),
                    ];
                }
            }
        }

        foreach ($this->files as $path => $analysis) {
            foreach ($analysis->getFunctions() as $fn) {
                if ($fn->getName() === $symbol) {
                    $hits[] = [
                        'type' => 'function',
                        'name' => $fn->getName(),
                        'file' => $path,
                        'line' => $fn->getLine(),
                    ];
                }
            }
        }
        return $hits;
    }

    /**
     * 生成一段可直接注入提示词的类说明
     *
     * 比把整个类的源码塞进上下文省得多，而且带上了单看源码看不到的
     * "谁依赖我"信息。
     *
     * @param string $className
     * @return string 类不在索引里时返回空串
     */
    public function explain($className)
    {
        $class = $this->analyzeClass($className);
        if ($class === null) {
            return '';
        }

        $lines = [$class->toSummary()];

        $deps = $this->dependencyGraph->dependenciesOf($class->getName());
        if ($deps) {
            $lines[] = '  依赖: ' . implode(', ', array_slice($deps, 0, 10));
        }
        $dependents = $this->dependencyGraph->dependentsOf($class->getName());
        if ($dependents) {
            $lines[] = '  被依赖: ' . implode(', ', array_slice($dependents, 0, 10));
        }
        $ancestors = $this->classAnalyzer->ancestors($class->getName(), $this->classes);
        if ($ancestors) {
            $lines[] = '  继承链: ' . implode(' → ', $ancestors);
        }
        $subclasses = $this->classAnalyzer->subclassesOf($class->getName(), $this->classes);
        if ($subclasses) {
            $lines[] = '  子类: ' . implode(', ', $subclasses);
        }
        return implode("\n", $lines);
    }

    /**
     * 类索引
     *
     * @return array<string, ClassAnalysis>
     */
    public function index()
    {
        return $this->classes;
    }

    /**
     * 文件索引
     *
     * @return array<string, FileAnalysis>
     */
    public function files()
    {
        return $this->files;
    }

    /**
     * 全部函数与方法（拉平）
     *
     * @return array<string, FunctionAnalysis>
     */
    public function functions()
    {
        $all = [];
        foreach ($this->files as $analysis) {
            foreach ($this->functionAnalyzer->flatten($analysis) as $name => $fn) {
                $all[$name] = $fn;
            }
        }
        return $all;
    }

    /** @return CallGraph */
    public function callGraph()
    {
        return $this->callGraph;
    }

    /** @return DependencyGraph */
    public function dependencyGraph()
    {
        return $this->dependencyGraph;
    }

    /** @return ClassAnalyzer */
    public function classAnalyzer()
    {
        return $this->classAnalyzer;
    }

    /** @return FunctionAnalyzer */
    public function functionAnalyzer()
    {
        return $this->functionAnalyzer;
    }

    /**
     * 索引统计
     *
     * @return array{files: int, classes: int, methods: int, callEdges: int, dependencyEdges: int}
     */
    public function stats()
    {
        $methods = 0;
        foreach ($this->classes as $class) {
            $methods += count($class->getMethods());
        }
        $depEdges = 0;
        foreach ($this->dependencyGraph->edges() as $deps) {
            $depEdges += count($deps);
        }
        return [
            'files'           => count($this->files),
            'classes'         => count($this->classes),
            'methods'         => $methods,
            'callEdges'       => $this->callGraph->countEdges(),
            'dependencyEdges' => $depEdges,
        ];
    }

    /**
     * 清空索引
     *
     * @return $this
     */
    public function clear()
    {
        $this->files = [];
        $this->classes = [];
        $this->callGraph->clear();
        $this->dependencyGraph->clear();
        return $this;
    }

    /**
     * 类定义在哪个文件
     *
     * @param string $className
     * @return string
     */
    public function fileOfClass($className)
    {
        $className = ltrim((string) $className, '\\');
        return isset($this->classes[$className]) ? $this->classes[$className]->getFile() : '';
    }

    /**
     * 遍历目录下的 PHP 文件
     *
     * @param string $dir
     * @return string[]
     */
    protected function phpFiles($dir)
    {
        $files = [];
        $entries = @scandir($dir);
        if ($entries === false) {
            return $files;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                if (in_array($entry, $this->excludeDirs, true) || strpos($entry, '.') === 0) {
                    continue;
                }
                foreach ($this->phpFiles($path) as $sub) {
                    $files[] = $sub;
                }
                continue;
            }
            if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }
            if ($this->maxFileSize > 0 && (int) @filesize($path) > $this->maxFileSize) {
                continue;
            }
            $files[] = $path;
        }
        return $files;
    }
}
