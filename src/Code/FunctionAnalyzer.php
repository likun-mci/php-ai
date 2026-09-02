<?php
namespace Ai\Code;

/**
 * FunctionAnalyzer——函数 / 方法维度的分析
 *
 * 把文件里的全局函数与类方法拉平成一张表，按名字查签名、查定义位置。
 * Agent 常见的问题是"`save()` 定义在哪、参数是什么"，这个类回答它，
 * 不必把整个类的源码读进上下文。
 *
 * ```php
 * $fa = new FunctionAnalyzer();
 * $functions = $fa->analyzeFile('src/Auth.php');
 * // ['App\Auth::login' => FunctionAnalysis, 'App\Auth::logout' => ...]
 *
 * $fa->findByName($functions, 'login');   // 按短名找，返回全部同名方法
 * ```
 */
class FunctionAnalyzer
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
     * 分析一个文件里的全部函数与方法
     *
     * @param string $path
     * @return array<string, FunctionAnalysis> 完整名（`foo` / `Foo::bar`） => 分析结果
     */
    public function analyzeFile($path)
    {
        $analysis = $this->fileAnalyzer->analyze($path);
        return $analysis === null ? [] : $this->flatten($analysis);
    }

    /**
     * 分析一段源码里的全部函数与方法
     *
     * @param string $code
     * @param string $path
     * @return array<string, FunctionAnalysis>
     */
    public function analyzeCode($code, $path = '')
    {
        return $this->flatten($this->fileAnalyzer->analyzeCode($code, $path));
    }

    /**
     * 把 FileAnalysis 里的全局函数与类方法拉平
     *
     * @param FileAnalysis $analysis
     * @return array<string, FunctionAnalysis>
     */
    public function flatten(FileAnalysis $analysis)
    {
        $functions = [];
        foreach ($analysis->getFunctions() as $fn) {
            $functions[$fn->getFullName()] = $fn;
        }
        foreach ($analysis->getClasses() as $class) {
            foreach ($class->getMethods() as $method) {
                $functions[$method->getFullName()] = $method;
            }
        }
        return $functions;
    }

    /**
     * 按短名查找——同名方法可能存在于多个类里，所以返回数组
     *
     * @param array<string, FunctionAnalysis> $functions
     * @param string $name 短名（`login`）或完整名（`App\Auth::login`）
     * @return array<string, FunctionAnalysis>
     */
    public function findByName(array $functions, $name)
    {
        $name = (string) $name;
        if (isset($functions[$name])) {
            return [$name => $functions[$name]];
        }
        $matched = [];
        foreach ($functions as $full => $fn) {
            if ($fn->getName() === $name) {
                $matched[$full] = $fn;
            }
        }
        return $matched;
    }

    /**
     * 找出调用了指定目标的函数
     *
     * 目标可以是函数名（`file_get_contents`）、静态调用（`Foo::bar`）或
     * 方法名（`save` / `->save`）。方法调用靠 `->名字` 匹配，拿不到接收者的
     * 真实类型——同名方法会一起命中，这是静态扫描的固有限制。
     *
     * @param array<string, FunctionAnalysis> $functions
     * @param string $target
     * @return string[] 调用方的完整名
     */
    public function callersOf(array $functions, $target)
    {
        $target = (string) $target;
        $variants = [$target, '->' . ltrim($target, '>-')];
        $callers = [];

        foreach ($functions as $full => $fn) {
            foreach ($fn->getCalls() as $call) {
                if (in_array($call, $variants, true)) {
                    $callers[] = $full;
                    break;
                }
                // Foo::bar 目标写成短名 bar 时也算命中
                if (strpos($call, '::') !== false && substr($call, -strlen('::' . $target)) === '::' . $target) {
                    $callers[] = $full;
                    break;
                }
            }
        }
        return array_values(array_unique($callers));
    }

    /**
     * 复杂度粗估——按函数体行数与调用数量给个数字
     *
     * 不是圈复杂度，只是"这个函数是不是该拆了"的快速信号。
     *
     * @param FunctionAnalysis $fn
     * @return int
     */
    public function roughComplexity(FunctionAnalysis $fn)
    {
        $lines = max(0, $fn->getEndLine() - $fn->getLine());
        return $lines + count($fn->getCalls()) * 2 + $fn->countParams();
    }

    /**
     * 挑出"看着该拆了"的函数
     *
     * @param array<string, FunctionAnalysis> $functions
     * @param int $threshold 行数阈值
     * @return array<string, int> 完整名 => 行数，按行数降序
     */
    public function longFunctions(array $functions, $threshold = 50)
    {
        $threshold = (int) $threshold;
        $long = [];
        foreach ($functions as $full => $fn) {
            $lines = max(0, $fn->getEndLine() - $fn->getLine());
            if ($lines >= $threshold) {
                $long[$full] = $lines;
            }
        }
        arsort($long);
        return $long;
    }
}
