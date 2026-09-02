<?php
namespace Ai\Code;

/**
 * DependencyGraph——类之间的依赖关系图
 *
 * 一个类依赖了哪些类（父类、接口、trait、参数与返回类型、`new` 的类、静态调用目标），
 * 反过来又被哪些类依赖。Agent 改一个类之前先看这张图，就知道波及面有多大。
 *
 * ```php
 * $graph = new DependencyGraph();
 * $graph->addClass($classAnalysis);
 *
 * $graph->dependenciesOf('App\Auth');    // Auth 依赖谁
 * $graph->dependentsOf('App\Auth');      // 谁依赖 Auth
 * $graph->detectCycles();                // 循环依赖
 * $graph->layers();                      // 按依赖深度分层，越靠前越底层
 * ```
 */
class DependencyGraph
{
    /** @var array<string, string[]> 类名 => 依赖的类名列表 */
    protected $edges = [];

    /** @var array<string, string[]> 类名 => 依赖它的类名列表 */
    protected $reverse = [];

    /** @var array<string, string> 类名 => 所在文件 */
    protected $locations = [];

    /**
     * 加入一个类
     *
     * @param ClassAnalysis $class
     * @return $this
     */
    public function addClass(ClassAnalysis $class)
    {
        $name = $class->getName();
        $this->locations[$name] = $class->getFile();
        if (!isset($this->edges[$name])) {
            $this->edges[$name] = [];
        }

        foreach ($class->getDependencies() as $dep) {
            if ($dep === $name) {
                continue;   // 自引用不算依赖
            }
            if (!in_array($dep, $this->edges[$name], true)) {
                $this->edges[$name][] = $dep;
            }
            if (!isset($this->reverse[$dep])) {
                $this->reverse[$dep] = [];
            }
            if (!in_array($name, $this->reverse[$dep], true)) {
                $this->reverse[$dep][] = $name;
            }
        }
        return $this;
    }

    /**
     * 把一个文件里的全部类加入
     *
     * @param FileAnalysis $analysis
     * @return $this
     */
    public function addFile(FileAnalysis $analysis)
    {
        foreach ($analysis->getClasses() as $class) {
            $this->addClass($class);
        }
        return $this;
    }

    /**
     * 批量加入
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
     * 指定类的直接依赖
     *
     * @param string $className
     * @return string[]
     */
    public function dependenciesOf($className)
    {
        $className = (string) $className;
        return isset($this->edges[$className]) ? $this->edges[$className] : [];
    }

    /**
     * 依赖指定类的类
     *
     * @param string $className
     * @return string[]
     */
    public function dependentsOf($className)
    {
        $className = (string) $className;
        return isset($this->reverse[$className]) ? $this->reverse[$className] : [];
    }

    /**
     * 传递依赖——直接间接依赖到的全部类
     *
     * @param string $className
     * @param int $maxDepth
     * @return string[]
     */
    public function transitiveDependencies($className, $maxDepth = 10)
    {
        return $this->walk($className, $this->edges, (int) $maxDepth);
    }

    /**
     * 传递影响面——直接间接依赖它的全部类
     *
     * @param string $className
     * @param int $maxDepth
     * @return string[]
     */
    public function transitiveDependents($className, $maxDepth = 10)
    {
        return $this->walk($className, $this->reverse, (int) $maxDepth);
    }

    /**
     * 检测循环依赖
     *
     * @return array<int, string[]> 每项是一条环上的类名序列
     */
    public function detectCycles()
    {
        $cycles = [];
        $visited = [];

        foreach (array_keys($this->edges) as $node) {
            if (isset($visited[$node])) {
                continue;
            }
            $stack = [];
            $this->dfsCycle($node, $visited, $stack, $cycles);
        }
        return $cycles;
    }

    /**
     * 按依赖深度分层——第 0 层不依赖图内任何类，越往后越上层
     *
     * 有环时环上的类会落在最后一层，`detectCycles()` 能拿到具体是哪些。
     *
     * @return array<int, string[]>
     */
    public function layers()
    {
        $remaining = [];
        foreach ($this->edges as $node => $deps) {
            $remaining[$node] = array_values(array_filter($deps, function ($d) {
                return isset($this->edges[$d]);
            }));
        }

        $layers = [];
        $placed = [];

        while ($remaining) {
            $layer = [];
            foreach ($remaining as $node => $deps) {
                $pending = array_filter($deps, function ($d) use ($placed) {
                    return !isset($placed[$d]);
                });
                if (!$pending) {
                    $layer[] = $node;
                }
            }
            if (!$layer) {
                // 剩下的全在环里，一次性放进最后一层
                $layers[] = array_keys($remaining);
                break;
            }
            foreach ($layer as $node) {
                $placed[$node] = true;
                unset($remaining[$node]);
            }
            $layers[] = $layer;
        }
        return $layers;
    }

    /**
     * 被依赖最多的类——通常是核心抽象，改动它风险最高
     *
     * @param int $limit
     * @return array<string, int> 类名 => 被依赖次数，降序
     */
    public function mostDepended($limit = 10)
    {
        $counts = [];
        foreach ($this->reverse as $name => $dependents) {
            $counts[$name] = count($dependents);
        }
        arsort($counts);
        return array_slice($counts, 0, max(1, (int) $limit), true);
    }

    /**
     * 类所在文件
     *
     * @param string $className
     * @return string
     */
    public function fileOf($className)
    {
        $className = (string) $className;
        return isset($this->locations[$className]) ? $this->locations[$className] : '';
    }

    /**
     * @return string[] 图里的全部类
     */
    public function nodes()
    {
        return array_keys($this->edges);
    }

    /**
     * @return array<string, string[]>
     */
    public function edges()
    {
        return $this->edges;
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
     * 沿指定方向做广度遍历
     *
     * @param string $start
     * @param array<string, string[]> $graph
     * @param int $maxDepth
     * @return string[]
     */
    protected function walk($start, array $graph, $maxDepth)
    {
        $maxDepth = max(1, $maxDepth);
        $seen = [];
        $frontier = [(string) $start];

        for ($depth = 0; $depth < $maxDepth && $frontier; $depth++) {
            $next = [];
            foreach ($frontier as $node) {
                $neighbours = isset($graph[$node]) ? $graph[$node] : [];
                foreach ($neighbours as $neighbour) {
                    if (isset($seen[$neighbour]) || $neighbour === $start) {
                        continue;
                    }
                    $seen[$neighbour] = true;
                    $next[] = $neighbour;
                }
            }
            $frontier = $next;
        }
        return array_keys($seen);
    }

    /**
     * DFS 找环
     *
     * @param string $node
     * @param array<string, bool> $visited
     * @param string[] $stack
     * @param array<int, string[]> $cycles
     * @return void
     */
    protected function dfsCycle($node, array &$visited, array $stack, array &$cycles)
    {
        $pos = array_search($node, $stack, true);
        if ($pos !== false) {
            $cycle = array_slice($stack, (int) $pos);
            $cycle[] = $node;
            $cycles[] = $cycle;
            return;
        }
        if (isset($visited[$node])) {
            return;
        }

        $stack[] = $node;
        foreach ($this->dependenciesOf($node) as $dep) {
            if (isset($this->edges[$dep])) {
                $this->dfsCycle($dep, $visited, $stack, $cycles);
            }
        }
        $visited[$node] = true;
    }
}
