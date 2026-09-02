<?php
namespace Ai\Code;

/**
 * FunctionAnalysis——函数 / 方法的分析结果
 *
 * 记录一个函数或方法的签名信息：名称、参数、返回类型、所在行、可见性，
 * 以及函数体里调用了哪些别的函数（供 CallGraph 建图）。
 */
class FunctionAnalysis
{
    /** @var string */
    protected $name = '';

    /** @var string 所属类名，全局函数为空串 */
    protected $class = '';

    /** @var array<int, array{name: string, type: string, optional: bool, byRef: bool, variadic: bool}> */
    protected $params = [];

    /** @var string 返回类型，未声明为空串 */
    protected $returnType = '';

    /** @var string public|protected|private，全局函数为空串 */
    protected $visibility = '';

    /** @var bool */
    protected $static = false;

    /** @var bool */
    protected $abstract = false;

    /** @var int 起始行 */
    protected $line = 0;

    /** @var int 结束行 */
    protected $endLine = 0;

    /** @var string[] 函数体内调用的函数 / 方法名 */
    protected $calls = [];

    /**
     * @param string $name
     * @param array<string, mixed> $data
     */
    public function __construct($name, array $data = [])
    {
        $this->name = (string) $name;
        foreach (['class', 'returnType', 'visibility'] as $key) {
            if (isset($data[$key])) {
                $this->$key = (string) $data[$key];
            }
        }
        foreach (['static', 'abstract'] as $key) {
            if (isset($data[$key])) {
                $this->$key = (bool) $data[$key];
            }
        }
        foreach (['line', 'endLine'] as $key) {
            if (isset($data[$key])) {
                $this->$key = (int) $data[$key];
            }
        }
        if (isset($data['params']) && is_array($data['params'])) {
            $this->params = $data['params'];
        }
        if (isset($data['calls']) && is_array($data['calls'])) {
            $this->calls = array_values(array_map('strval', $data['calls']));
        }
    }

    /** @return string */
    public function getName()
    {
        return $this->name;
    }

    /** @return string */
    public function getClass()
    {
        return $this->class;
    }

    /**
     * 完整名称：全局函数是 `foo`，方法是 `Foo\Bar::baz`
     *
     * @return string
     */
    public function getFullName()
    {
        return $this->class !== '' ? $this->class . '::' . $this->name : $this->name;
    }

    /**
     * @return array<int, array{name: string, type: string, optional: bool, byRef: bool, variadic: bool}>
     */
    public function getParams()
    {
        return $this->params;
    }

    /** @return int */
    public function countParams()
    {
        return count($this->params);
    }

    /**
     * 必填参数个数（无默认值、非可变参数）
     *
     * @return int
     */
    public function countRequiredParams()
    {
        $n = 0;
        foreach ($this->params as $param) {
            if (empty($param['optional']) && empty($param['variadic'])) {
                $n++;
            }
        }
        return $n;
    }

    /** @return string */
    public function getReturnType()
    {
        return $this->returnType;
    }

    /** @return string */
    public function getVisibility()
    {
        return $this->visibility;
    }

    /** @return bool */
    public function isStatic()
    {
        return $this->static;
    }

    /** @return bool */
    public function isAbstract()
    {
        return $this->abstract;
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

    /**
     * 函数体内调用的函数 / 方法名
     *
     * @return string[]
     */
    public function getCalls()
    {
        return $this->calls;
    }

    /**
     * 签名文本，如 `public function save(string $path, int $mode = 0): bool`
     *
     * @return string
     */
    public function getSignature()
    {
        $parts = [];
        if ($this->visibility !== '') {
            $parts[] = $this->visibility;
        }
        if ($this->abstract) {
            $parts[] = 'abstract';
        }
        if ($this->static) {
            $parts[] = 'static';
        }
        $parts[] = 'function';

        $params = [];
        foreach ($this->params as $param) {
            $text = '';
            if (isset($param['type']) && $param['type'] !== '') {
                $text .= $param['type'] . ' ';
            }
            if (!empty($param['byRef'])) {
                $text .= '&';
            }
            if (!empty($param['variadic'])) {
                $text .= '...';
            }
            $text .= '$' . $param['name'];
            if (!empty($param['optional'])) {
                $text .= ' = ...';
            }
            $params[] = $text;
        }

        $signature = implode(' ', $parts) . ' ' . $this->name . '(' . implode(', ', $params) . ')';
        if ($this->returnType !== '') {
            $signature .= ': ' . $this->returnType;
        }
        return $signature;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'name'       => $this->name,
            'class'      => $this->class,
            'params'     => $this->params,
            'returnType' => $this->returnType,
            'visibility' => $this->visibility,
            'static'     => $this->static,
            'abstract'   => $this->abstract,
            'line'       => $this->line,
            'endLine'    => $this->endLine,
            'calls'      => $this->calls,
        ];
    }
}
