<?php
namespace Ai\Code;

/**
 * FileAnalyzer——解析单个 PHP 文件的结构
 *
 * 基于 `token_get_all()` 做轻量解析，不依赖 nikic/php-parser——本库承诺零运行时依赖，
 * 而 Agent 需要的是"这个文件里有哪些类和方法、谁调用了谁"，不是完整 AST。
 *
 * ```php
 * $analyzer = new FileAnalyzer();
 * $analysis = $analyzer->analyze('src/Auth.php');
 *
 * echo $analysis->getNamespace();                  // 'App\Service'
 * echo $analysis->getMainClass()->getName();       // 'App\Service\Auth'
 * echo $analysis->getMainClass()->toSummary();     // 类结构摘要
 * ```
 *
 * 能解析：命名空间、use 导入、类 / 接口 / trait、继承与实现、方法签名（含参数类型与
 * 返回类型）、属性、类常量、函数体内的调用目标。
 *
 * 不能解析：变量的实际类型、动态调用（`$class::$method()`）、`eval` 里的代码。
 * 这些靠静态扫描本来就拿不准，`findCallers()` 之类的结果因此是"可能的调用方"，
 * 不是精确调用图。
 */
class FileAnalyzer
{
    /** @var TokenScanner 当前文件的 token 流 */
    protected $scanner;

    public function __construct()
    {
        // 先放一个空扫描器，analyzeCode() 之前调到解析方法也不会炸
        $this->scanner = new TokenScanner('');
    }

    /** @var string 当前命名空间 */
    protected $namespace = '';

    /** @var array<string, string> 当前文件的 import 别名表 */
    protected $imports = [];

    /**
     * 分析一个文件
     *
     * @param string $path
     * @return FileAnalysis|null 文件不存在或不可读时返回 null
     */
    public function analyze($path)
    {
        $path = (string) $path;
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $code = @file_get_contents($path);
        if ($code === false) {
            return null;
        }
        return $this->analyzeCode($code, $path);
    }

    /**
     * 分析一段源码
     *
     * @param string $code
     * @param string $path 关联的文件路径（仅用于结果标注）
     * @return FileAnalysis
     */
    public function analyzeCode($code, $path = '')
    {
        $this->scanner = new TokenScanner($code);
        $this->namespace = '';
        $this->imports = [];

        $classes = [];
        $functions = [];
        $constants = [];
        $calls = [];

        $scanner = $this->scanner;
        $count = $scanner->count();

        for ($i = 0; $i < $count; $i++) {
            $token = $scanner->at($i);
            if (!is_array($token)) {
                continue;
            }

            switch ($token[0]) {
                case T_NAMESPACE:
                    $this->namespace = $this->readNamespace($i);
                    break;

                case T_USE:
                    // 只处理文件级 use（类内 use trait 由 parseClass 处理）
                    if ($this->isFileLevelUse($i)) {
                        $this->readImports($i);
                    }
                    break;

                case T_CLASS:
                case T_INTERFACE:
                case T_TRAIT:
                    // `Foo::class` 里的 class 不是类声明
                    if ($token[0] === T_CLASS && $this->isClassConstantFetch($i)) {
                        break;
                    }
                    $class = $this->parseClass($i, $token[0]);
                    if ($class !== null) {
                        $class->setFile($path);
                        $classes[] = $class;
                        $i = $this->classEndIndex;
                    }
                    break;

                case T_FUNCTION:
                    // 类内方法已由 parseClass 消费掉，这里只剩全局函数
                    $fn = $this->parseFunction($i, '');
                    if ($fn !== null) {
                        $functions[] = $fn;
                        foreach ($fn->getCalls() as $call) {
                            $calls[] = $call;
                        }
                        $i = $this->functionEndIndex;
                    }
                    break;

                case T_STRING:
                    if (strtolower($token[1]) === 'define' && $scanner->isChar($scanner->skipWhitespace($i + 1), '(')) {
                        $nameIdx = $scanner->skipWhitespace($i + 2);
                        $nameToken = $scanner->at($nameIdx);
                        if (is_array($nameToken) && $nameToken[0] === T_CONSTANT_ENCAPSED_STRING) {
                            $constants[] = trim($nameToken[1], "'\"");
                        }
                    }
                    break;
            }
        }

        foreach ($classes as $class) {
            foreach ($class->getMethods() as $method) {
                foreach ($method->getCalls() as $call) {
                    $calls[] = $call;
                }
            }
        }

        return new FileAnalysis($path, [
            'namespace' => $this->namespace,
            'imports'   => $this->imports,
            'classes'   => $classes,
            'functions' => $functions,
            'constants' => $constants,
            'calls'     => $calls,
            'lines'     => substr_count($code, "\n") + 1,
        ]);
    }

    /** @var int parseClass 消费到的最后一个 token 下标 */
    protected $classEndIndex = 0;

    /** @var int parseFunction 消费到的最后一个 token 下标 */
    protected $functionEndIndex = 0;

    /**
     * 读命名空间声明
     *
     * @param int $i T_NAMESPACE 的下标
     * @return string
     */
    protected function readNamespace($i)
    {
        $scanner = $this->scanner;
        $start = $scanner->skipWhitespace($i + 1);
        if ($scanner->isChar($start, '{')) {
            return '';   // 匿名命名空间 namespace { }
        }
        return $scanner->readName($start);
    }

    /**
     * 判断某个 T_USE 是不是文件级 import
     *
     * 类内的 `use SomeTrait;` 与闭包的 `use ($x)` 不算。
     *
     * @param int $i
     * @return bool
     */
    protected function isFileLevelUse($i)
    {
        $scanner = $this->scanner;
        $next = $scanner->skipWhitespace($i + 1);
        if ($scanner->isChar($next, '(')) {
            return false;   // 闭包 use ($var)
        }
        // 类内 use 由 parseClass 提前消费，走不到这里
        return true;
    }

    /**
     * 读 use 导入，填进 $this->imports
     *
     * 支持 `use A\B;`、`use A\B as C;`、`use A\{B, C as D};`。
     *
     * @param int $i T_USE 的下标
     * @return void
     */
    protected function readImports($i)
    {
        $scanner = $this->scanner;
        $j = $scanner->skipWhitespace($i + 1);

        // use function / use const：按同样规则记，别名表里带前缀便于区分
        $prefixKind = '';
        $token = $scanner->at($j);
        if (is_array($token) && ($token[0] === T_FUNCTION || $token[0] === T_CONST)) {
            $prefixKind = strtolower($token[1]);
            $j = $scanner->skipWhitespace($j + 1);
        }

        $end = $j;
        $base = $scanner->readName($j, $end);
        $j = $scanner->skipWhitespace($end + 1);

        // PHP 8 把 `App\Support` 合成一个 token，成组导入前的 `\` 会单独留下；
        // PHP 7 上它已经被 readName 吃进 $base 了。两边都要能走到下面的 `{`。
        if ($scanner->isType($j, T_NS_SEPARATOR)) {
            $base = rtrim($base, '\\') . '\\';
            $j = $scanner->skipWhitespace($j + 1);
        }

        // 成组导入 use A\{B, C as D}
        if ($scanner->isChar($j, '{')) {
            $close = $scanner->matchBrace($j);
            for ($k = $j + 1; $k < $close; $k++) {
                $k = $scanner->skipWhitespace($k);
                if ($k >= $close) {
                    break;
                }
                $itemEnd = $k;
                $item = $scanner->readName($k, $itemEnd);
                if ($item === '') {
                    continue;
                }
                $k = $itemEnd;
                $alias = '';
                $next = $scanner->skipWhitespace($k + 1);
                if ($scanner->isType($next, T_AS)) {
                    $aliasIdx = $scanner->skipWhitespace($next + 1);
                    $aliasEnd = $aliasIdx;
                    $alias = $scanner->readName($aliasIdx, $aliasEnd);
                    $k = $aliasEnd;
                }
                $this->addImport(rtrim($base, '\\') . '\\' . $item, $alias, $prefixKind);
            }
            return;
        }

        // 单个导入，可能带 as 别名，也可能逗号分隔多个
        $alias = '';
        if ($scanner->isType($j, T_AS)) {
            $aliasIdx = $scanner->skipWhitespace($j + 1);
            $aliasEnd = $aliasIdx;
            $alias = $scanner->readName($aliasIdx, $aliasEnd);
            $j = $scanner->skipWhitespace($aliasEnd + 1);
        }
        $this->addImport($base, $alias, $prefixKind);

        while ($scanner->isChar($j, ',')) {
            $j = $scanner->skipWhitespace($j + 1);
            $itemEnd = $j;
            $item = $scanner->readName($j, $itemEnd);
            if ($item === '') {
                break;
            }
            $j = $scanner->skipWhitespace($itemEnd + 1);
            $alias = '';
            if ($scanner->isType($j, T_AS)) {
                $aliasIdx = $scanner->skipWhitespace($j + 1);
                $aliasEnd = $aliasIdx;
                $alias = $scanner->readName($aliasIdx, $aliasEnd);
                $j = $scanner->skipWhitespace($aliasEnd + 1);
            }
            $this->addImport($item, $alias, $prefixKind);
        }
    }

    /**
     * @param string $full 完整名
     * @param string $alias 别名，空则取短名
     * @param string $kind '' | 'function' | 'const'
     * @return void
     */
    protected function addImport($full, $alias, $kind = '')
    {
        $full = ltrim((string) $full, '\\');
        if ($full === '') {
            return;
        }
        if ($alias === '') {
            $pos = strrpos($full, '\\');
            $alias = $pos === false ? $full : substr($full, $pos + 1);
        }
        $key = $kind !== '' ? $kind . ' ' . $alias : $alias;
        $this->imports[$key] = $full;
    }

    /**
     * 判断 T_CLASS 是不是 `Foo::class` 里的那个 class
     *
     * @param int $i
     * @return bool
     */
    protected function isClassConstantFetch($i)
    {
        $scanner = $this->scanner;
        for ($j = $i - 1; $j >= 0; $j--) {
            $token = $scanner->at($j);
            if (is_array($token)
                && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $scanner->isType($j, T_DOUBLE_COLON);
        }
        return false;
    }

    /**
     * 解析一个类 / 接口 / trait 声明
     *
     * @param int $i T_CLASS / T_INTERFACE / T_TRAIT 的下标
     * @param int $type token 类型
     * @return ClassAnalysis|null 匿名类返回 null（没有名字，进不了索引）
     */
    protected function parseClass($i, $type)
    {
        $scanner = $this->scanner;
        $this->classEndIndex = $i;

        $nameIdx = $scanner->skipWhitespace($i + 1);
        if (!$scanner->isType($nameIdx, T_STRING)) {
            return null;   // 匿名类 new class { }
        }
        $shortName = $scanner->at($nameIdx);
        $shortName = is_array($shortName) ? $shortName[1] : '';
        $line = $scanner->lineAt($i);

        $kind = ClassAnalysis::KIND_CLASS;
        if ($type === T_INTERFACE) {
            $kind = ClassAnalysis::KIND_INTERFACE;
        } elseif ($type === T_TRAIT) {
            $kind = ClassAnalysis::KIND_TRAIT;
        }

        // 修饰符在类关键字之前
        $abstract = false;
        $final = false;
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($scanner->isType($j, T_WHITESPACE)) {
                continue;
            }
            if ($scanner->isType($j, T_ABSTRACT)) {
                $abstract = true;
                continue;
            }
            if ($scanner->isType($j, T_FINAL)) {
                $final = true;
                continue;
            }
            break;
        }

        // extends / implements
        $parent = '';
        $interfaces = [];
        $j = $scanner->skipWhitespace($nameIdx + 1);
        $bodyStart = $scanner->findBodyStart($j);
        for (; $j < $bodyStart; $j++) {
            if ($scanner->isType($j, T_EXTENDS)) {
                $k = $scanner->skipWhitespace($j + 1);
                $end = $k;
                $name = $scanner->readName($k, $end);
                if ($name !== '') {
                    // 接口可以 extends 多个
                    if ($kind === ClassAnalysis::KIND_INTERFACE) {
                        $interfaces[] = $this->resolveName($name);
                    } else {
                        $parent = $this->resolveName($name);
                    }
                }
                $j = $end;
                continue;
            }
            if ($scanner->isType($j, T_IMPLEMENTS)) {
                for ($k = $j + 1; $k < $bodyStart; $k++) {
                    $k = $scanner->skipWhitespace($k);
                    if ($k >= $bodyStart) {
                        break;
                    }
                    $end = $k;
                    $name = $scanner->readName($k, $end);
                    if ($name !== '') {
                        $interfaces[] = $this->resolveName($name);
                        $k = $end;
                    }
                }
                break;
            }
            // 接口的多继承：extends A, B
            if ($scanner->isChar($j, ',') && $kind === ClassAnalysis::KIND_INTERFACE) {
                $k = $scanner->skipWhitespace($j + 1);
                $end = $k;
                $name = $scanner->readName($k, $end);
                if ($name !== '') {
                    $interfaces[] = $this->resolveName($name);
                }
                $j = $end;
            }
        }

        $fullName = $this->namespace !== '' ? $this->namespace . '\\' . $shortName : $shortName;

        // 类体
        $methods = [];
        $properties = [];
        $constants = [];
        $traits = [];
        $dependencies = [];
        $endLine = $line;

        if ($scanner->isChar($bodyStart, '{')) {
            $bodyEnd = $scanner->matchBrace($bodyStart);
            $endLine = $scanner->lineAt($bodyEnd);
            $this->classEndIndex = $bodyEnd;

            for ($j = $bodyStart + 1; $j < $bodyEnd; $j++) {
                $token = $scanner->at($j);
                if (!is_array($token)) {
                    continue;
                }
                if ($token[0] === T_USE) {
                    // 类内 use trait
                    $k = $scanner->skipWhitespace($j + 1);
                    while ($k < $bodyEnd) {
                        $end = $k;
                        $name = $scanner->readName($k, $end);
                        if ($name === '') {
                            break;
                        }
                        $traits[] = $this->resolveName($name);
                        $k = $scanner->skipWhitespace($end + 1);
                        if (!$scanner->isChar($k, ',')) {
                            break;
                        }
                        $k = $scanner->skipWhitespace($k + 1);
                    }
                    $j = $k;
                    continue;
                }
                if ($token[0] === T_CONST) {
                    $k = $scanner->skipWhitespace($j + 1);
                    $constToken = $scanner->at($k);
                    if (is_array($constToken) && $constToken[0] === T_STRING) {
                        $constants[] = ['name' => $constToken[1], 'line' => (int) $constToken[2]];
                    }
                    continue;
                }
                if ($token[0] === T_FUNCTION) {
                    $method = $this->parseFunction($j, $fullName);
                    if ($method !== null) {
                        $methods[] = $method;
                        foreach ($method->getParams() as $param) {
                            if (isset($param['type']) && $param['type'] !== '') {
                                $dependencies[] = $this->resolveName(ltrim($param['type'], '?'));
                            }
                        }
                        if ($method->getReturnType() !== '') {
                            $dependencies[] = $this->resolveName(ltrim($method->getReturnType(), '?'));
                        }
                        foreach ($this->classDependenciesInRange($j, $this->functionEndIndex) as $dep) {
                            $dependencies[] = $dep;
                        }
                        $j = $this->functionEndIndex;
                    }
                    continue;
                }
                if ($token[0] === T_VARIABLE
                    && $this->isPropertyDeclaration($j, $bodyStart, $bodyEnd)) {
                    $properties[] = [
                        'name'       => ltrim($token[1], '$'),
                        'visibility' => $this->visibilityBefore($j),
                        'static'     => $this->hasModifierBefore($j, T_STATIC),
                        'line'       => (int) $token[2],
                    ];
                }
            }
        } elseif ($scanner->isChar($bodyStart, ';')) {
            $this->classEndIndex = $bodyStart;
        }

        if ($parent !== '') {
            $dependencies[] = $parent;
        }
        foreach ($interfaces as $interface) {
            $dependencies[] = $interface;
        }
        foreach ($traits as $trait) {
            $dependencies[] = $trait;
        }

        return new ClassAnalysis($fullName, [
            'namespace'    => $this->namespace,
            'kind'         => $kind,
            'parent'       => $parent,
            'interfaces'   => $interfaces,
            'traits'       => $traits,
            'methods'      => $methods,
            'properties'   => $properties,
            'constants'    => $constants,
            'abstract'     => $abstract,
            'final'        => $final,
            'line'         => $line,
            'endLine'      => $endLine,
            'dependencies' => $this->filterTypes($dependencies),
        ]);
    }

    /**
     * 解析一个函数 / 方法
     *
     * @param int $i T_FUNCTION 的下标
     * @param string $className 所属类名，全局函数传空串
     * @return FunctionAnalysis|null 闭包返回 null
     */
    protected function parseFunction($i, $className)
    {
        $scanner = $this->scanner;
        $this->functionEndIndex = $i;

        $nameIdx = $scanner->skipWhitespace($i + 1);
        // 引用返回 function &foo()
        if ($scanner->isChar($nameIdx, '&')) {
            $nameIdx = $scanner->skipWhitespace($nameIdx + 1);
        }
        if (!$scanner->isType($nameIdx, T_STRING)) {
            // 闭包 function () {}：跳过整个函数体，避免里面的内容被误当成类成员
            $paren = $scanner->skipWhitespace($nameIdx);
            $bodyStart = $scanner->findBodyStart($paren);
            $this->functionEndIndex = $scanner->isChar($bodyStart, '{')
                ? $scanner->matchBrace($bodyStart)
                : $bodyStart;
            return null;
        }

        $nameToken = $scanner->at($nameIdx);
        $name = is_array($nameToken) ? $nameToken[1] : '';
        $line = $scanner->lineAt($i);

        // 参数列表
        $parenOpen = $scanner->skipWhitespace($nameIdx + 1);
        $parenClose = $this->matchParen($parenOpen);
        $params = $this->parseParams($parenOpen, $parenClose);

        // 返回类型
        $returnType = '';
        $j = $scanner->skipWhitespace($parenClose + 1);
        if ($scanner->isChar($j, ':')) {
            $k = $scanner->skipWhitespace($j + 1);
            $returnType = $this->readTypeAt($k, $j);
        }

        $bodyStart = $scanner->findBodyStart($j);
        $endLine = $line;
        $calls = [];
        if ($scanner->isChar($bodyStart, '{')) {
            $bodyEnd = $scanner->matchBrace($bodyStart);
            $endLine = $scanner->lineAt($bodyEnd);
            $calls = $this->callsInRange($bodyStart, $bodyEnd);
            $this->functionEndIndex = $bodyEnd;
        } else {
            $this->functionEndIndex = $bodyStart;
        }

        return new FunctionAnalysis($name, [
            'class'      => $className,
            'params'     => $params,
            'returnType' => $returnType,
            'visibility' => $className !== '' ? $this->visibilityBefore($i) : '',
            'static'     => $this->hasModifierBefore($i, T_STATIC),
            'abstract'   => $this->hasModifierBefore($i, T_ABSTRACT),
            'line'       => $line,
            'endLine'    => $endLine,
            'calls'      => $calls,
        ]);
    }

    /**
     * 解析参数列表
     *
     * @param int $open `(` 下标
     * @param int $close `)` 下标
     * @return array<int, array{name: string, type: string, optional: bool, byRef: bool, variadic: bool}>
     */
    protected function parseParams($open, $close)
    {
        $scanner = $this->scanner;
        $params = [];
        $type = '';
        $byRef = false;
        $variadic = false;
        $depth = 0;

        for ($i = $open + 1; $i < $close; $i++) {
            $token = $scanner->at($i);

            if (is_string($token)) {
                if ($token === '(' || $token === '[') {
                    $depth++;
                } elseif ($token === ')' || $token === ']') {
                    $depth--;
                } elseif ($token === ',' && $depth === 0) {
                    $type = '';
                    $byRef = false;
                    $variadic = false;
                } elseif ($token === '&' && $depth === 0) {
                    $byRef = true;
                } elseif ($token === '?' && $depth === 0) {
                    $type = '?';
                }
                continue;
            }

            if (!is_array($token)) {
                continue;   // 越界，token 取不到
            }
            if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            if ($token[0] === T_ELLIPSIS) {
                $variadic = true;
                continue;
            }
            if ($token[0] === T_VARIABLE && $depth === 0) {
                // 变量后面跟 `=` 说明有默认值
                $next = $scanner->skipWhitespace($i + 1);
                $optional = $scanner->isChar($next, '=');
                $params[] = [
                    'name'     => ltrim($token[1], '$'),
                    'type'     => $type,
                    'optional' => $optional,
                    'byRef'    => $byRef,
                    'variadic' => $variadic,
                ];
                $byRef = false;
                $variadic = false;
                continue;
            }
            // 类型声明部分
            if ($depth === 0 && $this->isTypeToken($token)) {
                $end = $i;
                $piece = $scanner->readName($i, $end);
                if ($piece === '' && is_array($token)) {
                    $piece = $token[1];
                }
                $type = $type === '?' ? '?' . $piece : ($type === '' ? $piece : $type . '|' . $piece);
                $i = $end;
            }
        }

        return $params;
    }

    /**
     * 从某个位置读一个类型声明（含可空 `?` 与联合类型）
     *
     * @param int $i
     * @param int $prev `:` 的下标，用于判断可空标记
     * @return string
     */
    protected function readTypeAt($i, $prev)
    {
        $scanner = $this->scanner;
        $nullable = false;
        if ($scanner->isChar($i, '?')) {
            $nullable = true;
            $i = $scanner->skipWhitespace($i + 1);
        }
        $end = $i;
        $type = $scanner->readName($i, $end);
        if ($type === '') {
            $token = $scanner->at($i);
            $type = is_array($token) ? $token[1] : '';
        }
        return $nullable && $type !== '' ? '?' . $type : $type;
    }

    /**
     * 判断某个 token 能否作为类型声明的一部分
     *
     * @param array{0: int, 1: string, 2: int} $token
     * @return bool
     */
    protected function isTypeToken(array $token)
    {
        if ($token[0] === T_STRING || $token[0] === T_NS_SEPARATOR || $token[0] === T_ARRAY) {
            return true;
        }
        if (in_array($token[0], TokenScanner::qualifiedNameTypes(), true)) {
            return true;
        }
        // callable / static 等关键字
        return defined('T_CALLABLE') && $token[0] === T_CALLABLE;
    }

    /**
     * 找与 `(` 配对的 `)`
     *
     * @param int $open
     * @return int
     */
    protected function matchParen($open)
    {
        $scanner = $this->scanner;
        $depth = 0;
        $count = $scanner->count();
        for ($i = $open; $i < $count; $i++) {
            if ($scanner->isChar($i, '(')) {
                $depth++;
            } elseif ($scanner->isChar($i, ')')) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return $count - 1;
    }

    /**
     * 收集一段 token 范围内的调用目标
     *
     * 形态：`foo(`（函数）、`Foo::bar(`（静态）、`->bar(`（方法，记为 `->bar`）。
     * 变量方法名（`$obj->$m()`）拿不到名字，跳过。
     *
     * @param int $from
     * @param int $to
     * @return string[]
     */
    protected function callsInRange($from, $to)
    {
        $scanner = $this->scanner;
        $calls = [];

        for ($i = $from; $i < $to; $i++) {
            $token = $scanner->at($i);
            if (!is_array($token)) {
                continue;
            }

            // ->method(  /  ?->method(
            if ($token[0] === T_OBJECT_OPERATOR
                || (defined('T_NULLSAFE_OBJECT_OPERATOR') && $token[0] === constant('T_NULLSAFE_OBJECT_OPERATOR'))) {
                $nameIdx = $scanner->skipWhitespace($i + 1);
                if ($scanner->isType($nameIdx, T_STRING)
                    && $scanner->isChar($scanner->skipWhitespace($nameIdx + 1), '(')) {
                    $nameToken = $scanner->at($nameIdx);
                    if (is_array($nameToken)) {
                        $calls[] = '->' . $nameToken[1];
                    }
                }
                continue;
            }

            // Foo::bar(
            if ($token[0] === T_STRING || in_array($token[0], TokenScanner::qualifiedNameTypes(), true)) {
                $end = $i;
                $name = $scanner->readName($i, $end);
                if ($name === '') {
                    continue;
                }
                $next = $scanner->skipWhitespace($end + 1);
                if ($scanner->isType($next, T_DOUBLE_COLON)) {
                    $methodIdx = $scanner->skipWhitespace($next + 1);
                    if ($scanner->isType($methodIdx, T_STRING)
                        && $scanner->isChar($scanner->skipWhitespace($methodIdx + 1), '(')) {
                        $methodToken = $scanner->at($methodIdx);
                        if (is_array($methodToken)) {
                            $calls[] = $this->resolveName($name) . '::' . $methodToken[1];
                        }
                    }
                    $i = $methodIdx;
                    continue;
                }
                // 普通函数调用：名字后紧跟 `(`，且前面不是 function / -> / ::
                if ($scanner->isChar($next, '(') && !$this->isDeclarationContext($i)) {
                    $calls[] = ltrim($name, '\\');
                }
                $i = $end;
            }
        }

        return array_values(array_unique($calls));
    }

    /**
     * 收集一段范围内引用到的类名（new X、X::、类型声明）
     *
     * @param int $from
     * @param int $to
     * @return string[]
     */
    protected function classDependenciesInRange($from, $to)
    {
        $scanner = $this->scanner;
        $deps = [];
        for ($i = $from; $i < $to; $i++) {
            if ($scanner->isType($i, T_NEW)) {
                $nameIdx = $scanner->skipWhitespace($i + 1);
                $end = $nameIdx;
                $name = $scanner->readName($nameIdx, $end);
                if ($name !== '') {
                    $deps[] = $this->resolveName($name);
                    $i = $end;
                }
                continue;
            }
            if ($scanner->isType($i, T_DOUBLE_COLON)) {
                // 往前找类名
                $prev = $i - 1;
                while ($prev >= 0 && $scanner->isType($prev, T_WHITESPACE)) {
                    $prev--;
                }
                $startIdx = $prev;
                while ($startIdx > 0
                    && ($scanner->isType($startIdx - 1, T_STRING) || $scanner->isType($startIdx - 1, T_NS_SEPARATOR))) {
                    $startIdx--;
                }
                $end = $startIdx;
                $name = $scanner->readName($startIdx, $end);
                if ($name !== '') {
                    $deps[] = $this->resolveName($name);
                }
            }
        }
        return $deps;
    }

    /**
     * 判断名字所在位置是不是声明（function foo / class Foo），而非调用
     *
     * @param int $i
     * @return bool
     */
    protected function isDeclarationContext($i)
    {
        $scanner = $this->scanner;
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($scanner->isType($j, T_WHITESPACE)
                || $scanner->isType($j, T_COMMENT)
                || $scanner->isType($j, T_DOC_COMMENT)) {
                continue;
            }
            return $scanner->isType($j, T_FUNCTION)
                || $scanner->isType($j, T_CLASS)
                || $scanner->isType($j, T_OBJECT_OPERATOR)
                || $scanner->isType($j, T_DOUBLE_COLON)
                || $scanner->isType($j, T_NEW);
        }
        return false;
    }

    /**
     * 判断类体里的某个变量是不是属性声明（而非方法体内的局部变量）
     *
     * @param int $i T_VARIABLE 下标
     * @param int $bodyStart
     * @param int $bodyEnd
     * @return bool
     */
    protected function isPropertyDeclaration($i, $bodyStart, $bodyEnd)
    {
        $scanner = $this->scanner;
        for ($j = $i - 1; $j > $bodyStart; $j--) {
            if ($scanner->isType($j, T_WHITESPACE)
                || $scanner->isType($j, T_COMMENT)
                || $scanner->isType($j, T_DOC_COMMENT)
                || $scanner->isType($j, T_STATIC)) {
                continue;
            }
            // 类型化属性（7.4+）：`protected ?string $x` 的类型部分要跳过才能看到可见性
            if ($this->isTypeDeclarationToken($j)) {
                continue;
            }
            return $scanner->isType($j, T_PUBLIC)
                || $scanner->isType($j, T_PROTECTED)
                || $scanner->isType($j, T_PRIVATE)
                || $scanner->isType($j, T_VAR);
        }
        return false;
    }

    /**
     * 判断某个位置的 token 能否是属性类型声明的一部分
     *
     * @param int $i
     * @return bool
     */
    protected function isTypeDeclarationToken($i)
    {
        $scanner = $this->scanner;
        if ($scanner->isChar($i, '?') || $scanner->isChar($i, '|')) {
            return true;
        }
        $token = $scanner->at($i);
        if (!is_array($token)) {
            return false;
        }
        // readonly 在 8.1+ 是独立 token，7.x 上是普通 T_STRING（下面按类型 token 放过）
        if (defined('T_READONLY') && $token[0] === constant('T_READONLY')) {
            return true;
        }
        return $this->isTypeToken($token);
    }

    /**
     * 取某个位置之前的可见性修饰符
     *
     * @param int $i
     * @return string public|protected|private，未声明返回 'public'
     */
    protected function visibilityBefore($i)
    {
        $scanner = $this->scanner;
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($scanner->isType($j, T_WHITESPACE)
                || $scanner->isType($j, T_STATIC)
                || $scanner->isType($j, T_ABSTRACT)
                || $scanner->isType($j, T_FINAL)
                || $this->isTypeDeclarationToken($j)) {
                continue;
            }
            if ($scanner->isType($j, T_PUBLIC)) {
                return 'public';
            }
            if ($scanner->isType($j, T_PROTECTED)) {
                return 'protected';
            }
            if ($scanner->isType($j, T_PRIVATE)) {
                return 'private';
            }
            break;
        }
        return 'public';
    }

    /**
     * 判断某个位置之前有没有指定修饰符
     *
     * @param int $i
     * @param int $type
     * @return bool
     */
    protected function hasModifierBefore($i, $type)
    {
        $scanner = $this->scanner;
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($scanner->isType($j, T_WHITESPACE)) {
                continue;
            }
            if ($scanner->isType($j, $type)) {
                return true;
            }
            if ($scanner->isType($j, T_PUBLIC)
                || $scanner->isType($j, T_PROTECTED)
                || $scanner->isType($j, T_PRIVATE)
                || $scanner->isType($j, T_ABSTRACT)
                || $scanner->isType($j, T_FINAL)
                || $scanner->isType($j, T_STATIC)
                || $this->isTypeDeclarationToken($j)) {
                continue;
            }
            break;
        }
        return false;
    }

    /**
     * 把短名按 import 表与当前命名空间解析成完整名
     *
     * 解析不出来时原样返回——静态分析拿不准的地方不该硬猜。
     *
     * @param string $name
     * @return string
     */
    public function resolveName($name)
    {
        $name = (string) $name;
        if ($name === '') {
            return '';
        }
        // 完全限定名：前导 \ 表示"不走 import 表、不加当前命名空间"
        if ($name[0] === '\\') {
            return ltrim($name, '\\');
        }
        if (isset($this->imports[$name])) {
            return $this->imports[$name];
        }
        // 相对名：Foo\Bar，取首段查 import 表
        $pos = strpos($name, '\\');
        if ($pos !== false) {
            $first = substr($name, 0, $pos);
            if (isset($this->imports[$first])) {
                return $this->imports[$first] . substr($name, $pos);
            }
        }
        // 内置类型与 self/static/parent 不加命名空间
        if ($this->isBuiltinType($name)) {
            return $name;
        }
        return $this->namespace !== '' ? $this->namespace . '\\' . $name : $name;
    }

    /**
     * @param string $name
     * @return bool
     */
    protected function isBuiltinType($name)
    {
        static $builtin = [
            'self', 'static', 'parent', 'int', 'float', 'string', 'bool', 'array', 'object',
            'callable', 'iterable', 'void', 'mixed', 'null', 'false', 'true', 'never',
        ];
        return in_array(strtolower($name), $builtin, true);
    }

    /**
     * 过滤掉内置类型、self/static/parent 等非真实依赖
     *
     * 传进来的名字应当已经过 `resolveName()`——这里只做过滤与去重，
     * 再解析一次会把 `App\Foo` 变成 `App\App\Foo`。
     *
     * @param string[] $types
     * @return string[]
     */
    protected function filterTypes(array $types)
    {
        $result = [];
        foreach ($types as $type) {
            $type = ltrim((string) $type, '?\\');
            if ($type === '' || $this->isBuiltinType($type)) {
                continue;
            }
            $result[] = $type;
        }
        return array_values(array_unique($result));
    }

    /**
     * 当前文件的 import 表（analyze 之后可用）
     *
     * @return array<string, string>
     */
    public function getImports()
    {
        return $this->imports;
    }

    /**
     * 当前文件的命名空间（analyze 之后可用）
     *
     * @return string
     */
    public function getNamespace()
    {
        return $this->namespace;
    }
}
