<?php
namespace Ai\Code;

/**
 * ClassAnalyzer——类维度的分析
 *
 * `FileAnalyzer` 回答"这个文件里有什么"，`ClassAnalyzer` 回答关于类本身的问题：
 * 继承链是什么、谁继承了它、谁实现了这个接口、算上继承一共有哪些方法。
 *
 * 继承链相关的方法需要一份类索引（`完整类名 => ClassAnalysis`），通常由
 * `CodeAnalyzer::index()` 提供——单看一个文件是看不出"谁继承了我"的。
 *
 * ```php
 * $ca = new ClassAnalyzer();
 * $classes = $ca->analyzeFile('src/Auth.php');       // ['App\Auth' => ClassAnalysis]
 *
 * $index = $codeAnalyzer->index('/var/www/project');
 * $ca->ancestors('App\AdminAuth', $index);           // ['App\Auth', 'App\BaseAuth']
 * $ca->subclassesOf('App\Auth', $index);             // ['App\AdminAuth']
 * ```
 */
class ClassAnalyzer
{
    /** @var FileAnalyzer */
    protected $fileAnalyzer;

    /**
     * @param FileAnalyzer|null $fileAnalyzer
     */
    public function __construct($fileAnalyzer = null)
    {
        $this->fileAnalyzer = $fileAnalyzer instanceof FileAnalyzer ? $fileAnalyzer : new FileAnalyzer();
    }

    /**
     * 分析一个文件里的全部类
     *
     * @param string $path
     * @return array<string, ClassAnalysis> 完整类名 => 分析结果
     */
    public function analyzeFile($path)
    {
        $analysis = $this->fileAnalyzer->analyze($path);
        return $analysis === null ? [] : $analysis->getClasses();
    }

    /**
     * 分析一段源码里的全部类
     *
     * @param string $code
     * @param string $path
     * @return array<string, ClassAnalysis>
     */
    public function analyzeCode($code, $path = '')
    {
        return $this->fileAnalyzer->analyzeCode($code, $path)->getClasses();
    }

    /**
     * 在文件里找指定的类
     *
     * @param string $path
     * @param string $className 完整类名或短类名
     * @return ClassAnalysis|null
     */
    public function findInFile($path, $className)
    {
        $analysis = $this->fileAnalyzer->analyze($path);
        return $analysis === null ? null : $analysis->getClass($className);
    }

    /**
     * 继承链——从直接父类往上一直到根
     *
     * 父类不在索引里（如框架或 vendor 的类）时链条到此为止，不会去猜。
     *
     * @param string $className
     * @param array<string, ClassAnalysis> $index
     * @return string[] 由近及远的父类名
     */
    public function ancestors($className, array $index)
    {
        $chain = [];
        $current = (string) $className;
        $seen = [];

        while (isset($index[$current])) {
            $parent = $index[$current]->getParent();
            if ($parent === '' || isset($seen[$parent])) {
                break;
            }
            $chain[] = $parent;
            $seen[$parent] = true;
            $current = $parent;
        }
        return $chain;
    }

    /**
     * 直接子类
     *
     * @param string $className
     * @param array<string, ClassAnalysis> $index
     * @return string[]
     */
    public function subclassesOf($className, array $index)
    {
        $className = (string) $className;
        $subs = [];
        foreach ($index as $name => $class) {
            if ($class->getParent() === $className) {
                $subs[] = $name;
            }
        }
        return $subs;
    }

    /**
     * 全部后代类（递归子类）
     *
     * @param string $className
     * @param array<string, ClassAnalysis> $index
     * @return string[]
     */
    public function descendantsOf($className, array $index)
    {
        $result = [];
        $queue = $this->subclassesOf($className, $index);
        $seen = [];
        while ($queue) {
            $name = array_shift($queue);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $result[] = $name;
            foreach ($this->subclassesOf($name, $index) as $child) {
                $queue[] = $child;
            }
        }
        return $result;
    }

    /**
     * 实现了指定接口的类
     *
     * 只看直接 implements——通过父类间接实现的不算，因为那属于继承链的信息，
     * 需要时用 `ancestors()` 逐级查。
     *
     * @param string $interface
     * @param array<string, ClassAnalysis> $index
     * @return string[]
     */
    public function implementorsOf($interface, array $index)
    {
        $interface = (string) $interface;
        $result = [];
        foreach ($index as $name => $class) {
            if (in_array($interface, $class->getInterfaces(), true)) {
                $result[] = $name;
            }
        }
        return $result;
    }

    /**
     * 使用了指定 trait 的类
     *
     * @param string $trait
     * @param array<string, ClassAnalysis> $index
     * @return string[]
     */
    public function usersOfTrait($trait, array $index)
    {
        $trait = (string) $trait;
        $result = [];
        foreach ($index as $name => $class) {
            if (in_array($trait, $class->getTraits(), true)) {
                $result[] = $name;
            }
        }
        return $result;
    }

    /**
     * 算上继承与 trait 的全部方法
     *
     * 子类的同名方法覆盖父类的。父类不在索引里时只返回能看到的部分。
     *
     * @param string $className
     * @param array<string, ClassAnalysis> $index
     * @return array<string, FunctionAnalysis> 方法名 => 分析结果
     */
    public function allMethods($className, array $index)
    {
        $className = (string) $className;
        if (!isset($index[$className])) {
            return [];
        }

        $methods = [];
        // 从最远的祖先开始铺，越近的覆盖越远的
        $chain = array_reverse($this->ancestors($className, $index));
        $chain[] = $className;

        foreach ($chain as $name) {
            if (!isset($index[$name])) {
                continue;
            }
            foreach ($index[$name]->getTraits() as $trait) {
                if (isset($index[$trait])) {
                    foreach ($index[$trait]->getMethods() as $mName => $method) {
                        $methods[$mName] = $method;
                    }
                }
            }
            foreach ($index[$name]->getMethods() as $mName => $method) {
                $methods[$mName] = $method;
            }
        }
        return $methods;
    }

    /**
     * 类是不是另一个类的子类 / 接口实现
     *
     * @param string $className
     * @param string $ancestor
     * @param array<string, ClassAnalysis> $index
     * @return bool
     */
    public function isSubclassOf($className, $ancestor, array $index)
    {
        $ancestor = (string) $ancestor;
        if (in_array($ancestor, $this->ancestors($className, $index), true)) {
            return true;
        }
        // 沿继承链找 implements
        $chain = array_merge([(string) $className], $this->ancestors($className, $index));
        foreach ($chain as $name) {
            if (isset($index[$name]) && in_array($ancestor, $index[$name]->getInterfaces(), true)) {
                return true;
            }
        }
        return false;
    }
}
