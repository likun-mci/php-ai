<?php
namespace Ai\Code;

/**
 * FileAnalysis——单个 PHP 文件的分析结果
 *
 * 文件里有哪些命名空间、import、类、全局函数、常量，以及调用了哪些函数。
 * Agent 用它回答"这个文件里有什么"，不必把整份源码读进上下文。
 */
class FileAnalysis
{
    /** @var string 文件路径 */
    protected $path = '';

    /** @var string 命名空间，无命名空间为空串 */
    protected $namespace = '';

    /** @var array<string, string> 别名 => 完整名（use 语句） */
    protected $imports = [];

    /** @var array<string, ClassAnalysis> 完整类名 => 分析结果 */
    protected $classes = [];

    /** @var array<string, FunctionAnalysis> 函数名 => 分析结果（仅全局函数） */
    protected $functions = [];

    /** @var string[] 文件里定义的常量 */
    protected $constants = [];

    /** @var string[] 文件里出现的全部调用目标 */
    protected $calls = [];

    /** @var int 行数 */
    protected $lines = 0;

    /**
     * @param string $path
     * @param array<string, mixed> $data
     */
    public function __construct($path, array $data = [])
    {
        $this->path = (string) $path;
        if (isset($data['namespace'])) {
            $this->namespace = (string) $data['namespace'];
        }
        if (isset($data['imports']) && is_array($data['imports'])) {
            $this->imports = $data['imports'];
        }
        if (isset($data['classes']) && is_array($data['classes'])) {
            foreach ($data['classes'] as $class) {
                if ($class instanceof ClassAnalysis) {
                    $this->classes[$class->getName()] = $class;
                }
            }
        }
        if (isset($data['functions']) && is_array($data['functions'])) {
            foreach ($data['functions'] as $fn) {
                if ($fn instanceof FunctionAnalysis) {
                    $this->functions[$fn->getName()] = $fn;
                }
            }
        }
        foreach (['constants', 'calls'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $this->$key = array_values(array_unique(array_map('strval', $data[$key])));
            }
        }
        if (isset($data['lines'])) {
            $this->lines = (int) $data['lines'];
        }
    }

    /** @return string */
    public function getPath()
    {
        return $this->path;
    }

    /** @return string */
    public function getNamespace()
    {
        return $this->namespace;
    }

    /**
     * @return array<string, string> 别名 => 完整名
     */
    public function getImports()
    {
        return $this->imports;
    }

    /**
     * @return array<string, ClassAnalysis>
     */
    public function getClasses()
    {
        return $this->classes;
    }

    /**
     * 文件里的第一个类，没有则返回 null
     *
     * PSR-4 下一个文件通常就一个类，这个方法省掉调用方的取首元素样板代码。
     *
     * @return ClassAnalysis|null
     */
    public function getMainClass()
    {
        foreach ($this->classes as $class) {
            return $class;
        }
        return null;
    }

    /**
     * @param string $name 完整类名或短类名
     * @return ClassAnalysis|null
     */
    public function getClass($name)
    {
        $name = (string) $name;
        if (isset($this->classes[$name])) {
            return $this->classes[$name];
        }
        foreach ($this->classes as $class) {
            if ($class->getShortName() === $name) {
                return $class;
            }
        }
        return null;
    }

    /**
     * @return array<string, FunctionAnalysis>
     */
    public function getFunctions()
    {
        return $this->functions;
    }

    /** @return string[] */
    public function getConstants()
    {
        return $this->constants;
    }

    /**
     * 文件里出现的全部调用目标（函数名、`Foo::bar`、`->method`）
     *
     * @return string[]
     */
    public function getCalls()
    {
        return $this->calls;
    }

    /** @return int */
    public function getLines()
    {
        return $this->lines;
    }

    /**
     * 文件里的全部符号名（类 + 函数 + 常量）
     *
     * @return string[]
     */
    public function getSymbols()
    {
        $symbols = array_keys($this->classes);
        foreach (array_keys($this->functions) as $fn) {
            $symbols[] = $fn;
        }
        foreach ($this->constants as $const) {
            $symbols[] = $const;
        }
        return $symbols;
    }

    /**
     * 结构摘要——注入提示词用
     *
     * @return string
     */
    public function toSummary()
    {
        $lines = [];
        $lines[] = $this->path . ' (' . $this->lines . ' 行)';
        if ($this->namespace !== '') {
            $lines[] = 'namespace ' . $this->namespace;
        }
        foreach ($this->classes as $class) {
            $lines[] = $class->toSummary();
        }
        foreach ($this->functions as $fn) {
            $lines[] = $fn->getSignature() . '   # line ' . $fn->getLine();
        }
        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        $classes = [];
        foreach ($this->classes as $name => $class) {
            $classes[$name] = $class->toArray();
        }
        $functions = [];
        foreach ($this->functions as $name => $fn) {
            $functions[$name] = $fn->toArray();
        }
        return [
            'path'      => $this->path,
            'namespace' => $this->namespace,
            'imports'   => $this->imports,
            'classes'   => $classes,
            'functions' => $functions,
            'constants' => $this->constants,
            'calls'     => $this->calls,
            'lines'     => $this->lines,
        ];
    }
}
