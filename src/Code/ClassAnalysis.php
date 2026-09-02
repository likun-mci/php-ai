<?php
namespace Ai\Code;

/**
 * ClassAnalysis——类 / 接口 / trait 的分析结果
 *
 * 记录类的结构：完整类名、父类、实现的接口、use 的 trait、方法、属性、常量，
 * 以及它依赖了哪些其它类型（供 DependencyGraph 建图）。
 */
class ClassAnalysis
{
    const KIND_CLASS     = 'class';
    const KIND_INTERFACE = 'interface';
    const KIND_TRAIT     = 'trait';

    /** @var string 完整类名（含命名空间） */
    protected $name = '';

    /** @var string 命名空间 */
    protected $namespace = '';

    /** @var string class|interface|trait */
    protected $kind = self::KIND_CLASS;

    /** @var string 父类完整名，无父类为空串 */
    protected $parent = '';

    /** @var string[] 实现的接口 */
    protected $interfaces = [];

    /** @var string[] use 的 trait */
    protected $traits = [];

    /** @var array<string, FunctionAnalysis> 方法名 => 分析结果 */
    protected $methods = [];

    /** @var array<int, array{name: string, visibility: string, static: bool, line: int}> */
    protected $properties = [];

    /** @var array<int, array{name: string, line: int}> */
    protected $constants = [];

    /** @var bool */
    protected $abstract = false;

    /** @var bool */
    protected $final = false;

    /** @var int 起始行 */
    protected $line = 0;

    /** @var int 结束行 */
    protected $endLine = 0;

    /** @var string 所在文件 */
    protected $file = '';

    /** @var string[] 依赖的类型（父类、接口、trait、参数类型、new 的类、静态调用目标） */
    protected $dependencies = [];

    /**
     * @param string $name
     * @param array<string, mixed> $data
     */
    public function __construct($name, array $data = [])
    {
        $this->name = (string) $name;
        foreach (['namespace', 'kind', 'parent', 'file'] as $key) {
            if (isset($data[$key])) {
                $this->$key = (string) $data[$key];
            }
        }
        foreach (['abstract', 'final'] as $key) {
            if (isset($data[$key])) {
                $this->$key = (bool) $data[$key];
            }
        }
        foreach (['line', 'endLine'] as $key) {
            if (isset($data[$key])) {
                $this->$key = (int) $data[$key];
            }
        }
        foreach (['interfaces', 'traits', 'dependencies'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $this->$key = array_values(array_unique(array_map('strval', $data[$key])));
            }
        }
        foreach (['properties', 'constants'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $this->$key = $data[$key];
            }
        }
        if (isset($data['methods']) && is_array($data['methods'])) {
            foreach ($data['methods'] as $method) {
                if ($method instanceof FunctionAnalysis) {
                    $this->methods[$method->getName()] = $method;
                }
            }
        }
    }

    /** @return string */
    public function getName()
    {
        return $this->name;
    }

    /**
     * 短类名（去掉命名空间）
     *
     * @return string
     */
    public function getShortName()
    {
        $pos = strrpos($this->name, '\\');
        return $pos === false ? $this->name : substr($this->name, $pos + 1);
    }

    /** @return string */
    public function getNamespace()
    {
        return $this->namespace;
    }

    /** @return string */
    public function getKind()
    {
        return $this->kind;
    }

    /** @return bool */
    public function isInterface()
    {
        return $this->kind === self::KIND_INTERFACE;
    }

    /** @return bool */
    public function isTrait()
    {
        return $this->kind === self::KIND_TRAIT;
    }

    /** @return string */
    public function getParent()
    {
        return $this->parent;
    }

    /** @return string[] */
    public function getInterfaces()
    {
        return $this->interfaces;
    }

    /** @return string[] */
    public function getTraits()
    {
        return $this->traits;
    }

    /**
     * @return array<string, FunctionAnalysis>
     */
    public function getMethods()
    {
        return $this->methods;
    }

    /**
     * @param string $name
     * @return FunctionAnalysis|null
     */
    public function getMethod($name)
    {
        $name = (string) $name;
        return isset($this->methods[$name]) ? $this->methods[$name] : null;
    }

    /**
     * @param string $name
     * @return bool
     */
    public function hasMethod($name)
    {
        return isset($this->methods[(string) $name]);
    }

    /**
     * 公开方法列表
     *
     * @return array<string, FunctionAnalysis>
     */
    public function getPublicMethods()
    {
        $public = [];
        foreach ($this->methods as $name => $method) {
            if ($method->getVisibility() === '' || $method->getVisibility() === 'public') {
                $public[$name] = $method;
            }
        }
        return $public;
    }

    /**
     * @return array<int, array{name: string, visibility: string, static: bool, line: int}>
     */
    public function getProperties()
    {
        return $this->properties;
    }

    /**
     * @return array<int, array{name: string, line: int}>
     */
    public function getConstants()
    {
        return $this->constants;
    }

    /** @return bool */
    public function isAbstract()
    {
        return $this->abstract;
    }

    /** @return bool */
    public function isFinal()
    {
        return $this->final;
    }

    /** @return int */
    public function getLine()
    {
        return $this->line;
    }

    /** @return int */
    public function getEndLine()
    {
        return $this->endLine;
    }

    /** @return string */
    public function getFile()
    {
        return $this->file;
    }

    /**
     * @param string $file
     * @return $this
     */
    public function setFile($file)
    {
        $this->file = (string) $file;
        return $this;
    }

    /**
     * 依赖的类型名
     *
     * @return string[]
     */
    public function getDependencies()
    {
        return $this->dependencies;
    }

    /**
     * 结构摘要——注入提示词用，比整份源码省得多
     *
     * @return string
     */
    public function toSummary()
    {
        $lines = [];
        $header = $this->kind . ' ' . $this->name;
        if ($this->parent !== '') {
            $header .= ' extends ' . $this->parent;
        }
        if ($this->interfaces) {
            $header .= ' implements ' . implode(', ', $this->interfaces);
        }
        $lines[] = $header;
        if ($this->file !== '') {
            $lines[] = '  file: ' . $this->file . ':' . $this->line;
        }
        if ($this->traits) {
            $lines[] = '  uses: ' . implode(', ', $this->traits);
        }
        foreach ($this->methods as $method) {
            $lines[] = '  ' . $method->getSignature() . '   # line ' . $method->getLine();
        }
        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        $methods = [];
        foreach ($this->methods as $name => $method) {
            $methods[$name] = $method->toArray();
        }
        return [
            'name'         => $this->name,
            'namespace'    => $this->namespace,
            'kind'         => $this->kind,
            'parent'       => $this->parent,
            'interfaces'   => $this->interfaces,
            'traits'       => $this->traits,
            'methods'      => $methods,
            'properties'   => $this->properties,
            'constants'    => $this->constants,
            'abstract'     => $this->abstract,
            'final'        => $this->final,
            'line'         => $this->line,
            'endLine'      => $this->endLine,
            'file'         => $this->file,
            'dependencies' => $this->dependencies,
        ];
    }
}
