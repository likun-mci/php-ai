<?php
namespace Ai\Code;

/**
 * CallGraph——调用关系图
 *
 * 谁调用了谁。Agent 改一个方法前先问"改了会影响谁"，这张图给出答案。
 *
 * ```php
 * $graph = new CallGraph();
 * $graph->addFile($fileAnalysis);          // 逐个文件喂进来
 *
 * $graph->callers('App\Auth::login');      // 谁调用了 login
 * $graph->callees('App\Auth::login');      // login 调用了谁
 * $graph->reachableFrom('App\Auth::login'); // login 直接间接会走到哪些函数
 * ```
 *
 * 精度说明：`$obj->save()` 这类方法调用拿不到 `$obj` 的真实类型，图里记的是
 * `->save`，`callers()` 查 `save` 时会把所有类的同名方法调用一并算上。
 * 这是静态扫描的固有限制，结果是"可能的调用方"，不是精确调用图——
 * 用它缩小排查范围可以，当作重构的唯一依据不行。
 */
class CallGraph
{
    /** @var array<string, string[]> 调用方完整名 => 被调用目标列表 */
    protected $edges = [];

    /** @var array<string, string[]> 被调用目标 => 调用方完整名列表（反向索引） */
    protected $reverse = [];

    /** @var array<string, string> 完整名 => 所在文件 */
    protected $locations = [];

    /**
     * 把一个文件的分析结果并入图
     *
     * @param FileAnalysis $analysis
     * @return $this
     */
    public function addFile(FileAnalysis $analysis)
    {
        foreach ($analysis->getFunctions() as $fn) {
            $this->addFunction($fn, $analysis->getPath());
        }
        foreach ($analysis->getClasses() as $class) {
            foreach ($class->getMethods() as $method) {
                $this->addFunction($method, $analysis->getPath());
            }
        }
        return $this;
    }

    /**
     * 批量并入
     *
     * @param FileAnalysis[] $analyses
     * @return $this
     */
    public function addFiles(array $analyses)
    {
        foreach ($analyses as $analysis) {
            if ($analysis instanceof FileAnalysis) {
                $this->addFile($analysis);
            }
        }
        return $this;
    }

    /**
     * 加入一个函数及其调用边
     *
     * @param FunctionAnalysis $fn
     * @param string $file
     * @return $this
     */
    public function addFunction(FunctionAnalysis $fn, $file = '')
    {
        $from = $fn->getFullName();
        $this->locations[$from] = (string) $file;
        if (!isset($this->edges[$from])) {
            $this->edges[$from] = [];
        }
        foreach ($fn->getCalls() as $to) {
            if (!in_array($to, $this->edges[$from], true)) {
                $this->edges[$from][] = $to;
            }
            if (!isset($this->reverse[$to])) {
                $this->reverse[$to] = [];
            }
            if (!in_array($from, $this->reverse[$to], true)) {
                $this->reverse[$to][] = $from;
            }
        }
        return $this;
    }

    /**
     * 谁调用了指定目标
     *
     * 目标写完整名（`App\Auth::login`）、方法短名（`login`）或函数名都行，
     * 三种形态会一起匹配。
     *
     * @param string $target
     * @return string[] 调用方完整名
     */
    public function callers($target)
    {
        $target = (string) $target;
        $callers = [];

        foreach ($this->matchTargets($target) as $key) {
            foreach ($this->reverse[$key] as $caller) {
                $callers[] = $caller;
            }
        }
        return array_values(array_unique($callers));
    }

    /**
     * 指定函数调用了谁
     *
     * @param string $from 完整名
     * @return string[]
     */
    public function callees($from)
    {
        $from = (string) $from;
        return isset($this->edges[$from]) ? $this->edges[$from] : [];
    }

    /**
     * 从指定函数出发，直接间接能走到的全部目标
     *
     * @param string $from
     * @param int $maxDepth 最大深度，防止在环上绕不出来
     * @return string[]
     */
    public function reachableFrom($from, $maxDepth = 5)
    {
        $maxDepth = max(1, (int) $maxDepth);
        $seen = [];
        $frontier = [(string) $from];

        for ($depth = 0; $depth < $maxDepth && $frontier; $depth++) {
            $next = [];
            foreach ($frontier as $node) {
                foreach ($this->callees($node) as $callee) {
                    if (isset($seen[$callee])) {
                        continue;
                    }
                    $seen[$callee] = true;
                    $next[] = $this->resolveToFullName($callee);
                }
            }
            $frontier = array_values(array_filter($next, function ($n) {
                return isset($this->edges[$n]);
            }));
        }
        return array_keys($seen);
    }

    /**
     * 反过来：从指定目标出发，哪些函数直接间接会调到它
     *
     * @param string $target
     * @param int $maxDepth
     * @return string[]
     */
    public function impactOf($target, $maxDepth = 5)
    {
        $maxDepth = max(1, (int) $maxDepth);
        $seen = [];
        $frontier = [(string) $target];

        for ($depth = 0; $depth < $maxDepth && $frontier; $depth++) {
            $next = [];
            foreach ($frontier as $node) {
                foreach ($this->callers($node) as $caller) {
                    if (isset($seen[$caller])) {
                        continue;
                    }
                    $seen[$caller] = true;
                    $next[] = $caller;
                }
            }
            $frontier = $next;
        }
        return array_keys($seen);
    }

    /**
     * 没有任何调用方的函数——可能是入口，也可能是死代码
     *
     * 只覆盖图里的函数：被框架反射调用、被字符串回调调到的方法看不出来，
     * 所以这个列表是"值得看一眼的候选"，不是删除清单。
     *
     * @return string[]
     */
    public function unreferenced()
    {
        $result = [];
        foreach (array_keys($this->edges) as $name) {
            if (!$this->callers($name)) {
                $result[] = $name;
            }
        }
        return $result;
    }

    /**
     * 函数所在文件
     *
     * @param string $fullName
     * @return string
     */
    public function fileOf($fullName)
    {
        $fullName = (string) $fullName;
        return isset($this->locations[$fullName]) ? $this->locations[$fullName] : '';
    }

    /**
     * 图里的全部函数
     *
     * @return string[]
     */
    public function nodes()
    {
        return array_keys($this->edges);
    }

    /**
     * @return array<string, string[]> 调用方 => 被调用目标
     */
    public function edges()
    {
        return $this->edges;
    }

    /**
     * @return int 边数
     */
    public function countEdges()
    {
        $n = 0;
        foreach ($this->edges as $targets) {
            $n += count($targets);
        }
        return $n;
    }

    /**
     * 清空
     *
     * @return $this
     */
    public function clear()
    {
        $this->edges = [];
        $this->reverse = [];
        $this->locations = [];
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'edges'     => $this->edges,
            'locations' => $this->locations,
        ];
    }

    /**
     * 找出反向索引里能匹配目标的键
     *
     * @param string $target
     * @return string[]
     */
    protected function matchTargets($target)
    {
        $keys = [];
        if (isset($this->reverse[$target])) {
            $keys[] = $target;
        }

        $short = $target;
        $pos = strrpos($target, '::');
        if ($pos !== false) {
            $short = substr($target, $pos + 2);
        }

        // 方法调用记的是 `->名字`
        if (isset($this->reverse['->' . $short])) {
            $keys[] = '->' . $short;
        }
        // 静态调用记的是 `完整类名::名字`；目标给短名时匹配全部同名
        foreach (array_keys($this->reverse) as $key) {
            if ($key === $target) {
                continue;
            }
            if (strpos($key, '::') !== false && substr($key, -strlen('::' . $short)) === '::' . $short) {
                $keys[] = $key;
            }
        }
        return array_values(array_unique($keys));
    }

    /**
     * 把调用目标尽量还原成图里的完整名
     *
     * `->save` 这种拿不到接收者类型，还原不了就原样返回。
     *
     * @param string $call
     * @return string
     */
    protected function resolveToFullName($call)
    {
        $call = (string) $call;
        if (isset($this->edges[$call])) {
            return $call;
        }
        if (strpos($call, '->') === 0) {
            $short = substr($call, 2);
            foreach (array_keys($this->edges) as $name) {
                if (substr($name, -strlen('::' . $short)) === '::' . $short) {
                    return $name;
                }
            }
        }
        return $call;
    }
}
