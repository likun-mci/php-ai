<?php
/**
 * 冒烟测试：每个类都能被加载与实例化
 *
 * 存在的理由很具体：v1.8.0 里给 AIResponse::cost() 加了个参数，
 * 子类 ClaudeCodeResponse::cost() 的签名随之不兼容——**只要 use 到这个类
 * 就是 Fatal error**。而当时全部测试都没加载过它，于是这个致命错误直接发版了。
 *
 * 本测试遍历 src/ 下每一个类：加载它、检查继承链签名兼容、能实例化的就实例化。
 * 任何「改了父类忘了改子类」的问题都会在这里当场暴露。
 *
 * 运行：php tests/smoke_test.php
 */

require __DIR__ . '/../autoload.php';

function pad(string $t, int $w): string
{
    $n = $w - mb_strwidth($t, 'UTF-8');
    return $t . ($n > 0 ? str_repeat(' ', $n) : '');
}

$srcDir = realpath(__DIR__ . '/../src');
$failures = [];

/**
 * 取方法的返回类型，并把 self / static 解析成实际声明它的类
 * 只比字面量的话，父子都写 self 会被误判为一致
 */
function resolveReturnType(ReflectionMethod $m): string
{
    $t = $m->getReturnType();
    if ($t === null) {
        return '(无)';
    }
    $name = $t instanceof ReflectionNamedType ? $t->getName() : (string) $t;
    if ($name === 'self' || $name === 'static') {
        return $m->getDeclaringClass()->getName();
    }
    return $name;
}

// 收集 src/ 下所有类名
$classes = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $rel = str_replace([$srcDir . DIRECTORY_SEPARATOR, '.php'], '', $file->getPathname());
    $classes[] = 'Ai\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $rel);
}
sort($classes);

echo "=== 加载 src/ 下全部 " . count($classes) . " 个类 ===\n\n";

$loaded = 0;
foreach ($classes as $class) {
    // class_exists() 会触发自动加载并执行继承链检查；
    // 签名不兼容会在这一步抛 Fatal error（PHP 8 是 FatalError，PHP 7 是 E_ERROR）
    try {
        if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
            $failures[] = "{$class}：类名与文件路径不匹配（PSR-4 未命中）";
            continue;
        }
        $loaded++;
    } catch (\Throwable $e) {
        $failures[] = "{$class}：加载失败 " . get_class($e) . ' - ' . $e->getMessage();
    }
}
echo "成功加载：{$loaded} / " . count($classes) . "\n";

// ---------------------------------------------------------------
// 无参（或全可选参）构造的类，实际实例化一次
// ---------------------------------------------------------------
echo "\n=== 实例化检查 ===\n\n";

$instantiated = 0;
$skipped = 0;
foreach ($classes as $class) {
    if (!class_exists($class)) {
        continue;
    }
    $rc = new ReflectionClass($class);
    if ($rc->isAbstract() || $rc->isInterface() || $rc->isTrait()) {
        $skipped++;
        continue;
    }
    $ctor = $rc->getConstructor();
    if ($ctor && $ctor->getNumberOfRequiredParameters() > 0) {
        $skipped++;   // 需要必填参数的类交给各自的专项测试
        continue;
    }
    try {
        $rc->newInstance();
        $instantiated++;
    } catch (\Throwable $e) {
        $failures[] = "{$class}：实例化失败 " . get_class($e) . ' - ' . $e->getMessage();
    }
}
echo "实例化成功：{$instantiated}，跳过（抽象/需必填参数）：{$skipped}\n";

// ---------------------------------------------------------------
// 接口实现的完整性：声明实现了接口就必须真的实现全部方法
// ---------------------------------------------------------------
echo "\n=== 接口实现完整性 ===\n\n";

$interfaces = [
    'Ai\Contracts\ProtocolInterface',
    'Ai\Contracts\ModelInterface',
    'Ai\Contracts\TransportInterface',
    'Ai\Contracts\AIResponseInterface',
];
foreach ($interfaces as $iface) {
    $impls = array_filter($classes, function ($c) use ($iface) {
        return class_exists($c) && in_array($iface, class_implements($c) ?: [], true);
    });
    $methods = get_class_methods($iface);
    $bad = [];
    foreach ($impls as $impl) {
        $rc = new ReflectionClass($impl);
        if ($rc->isAbstract()) {
            continue;
        }
        foreach ($methods as $m) {
            if (!$rc->hasMethod($m)) {
                $bad[] = "{$impl}::{$m}";
            }
        }
    }
    if ($bad) {
        $failures[] = "{$iface} 的实现缺方法：" . implode(', ', $bad);
    }
    echo pad(basename(str_replace('\\', '/', $iface)), 24),
         count($impls), ' 个实现，', $bad ? '✗' : '✓', "\n";
}

// ---------------------------------------------------------------
// 父子类同名方法的签名兼容性（上次翻车的正是这里）
// ---------------------------------------------------------------
echo "\n=== 继承链签名兼容性 ===\n\n";

$checked = 0;
foreach ($classes as $class) {
    if (!class_exists($class)) {
        continue;
    }
    $rc = new ReflectionClass($class);
    $parent = $rc->getParentClass();
    if (!$parent) {
        continue;
    }
    foreach ($rc->getMethods() as $m) {
        if ($m->getDeclaringClass()->getName() !== $class || !$parent->hasMethod($m->getName())) {
            continue;
        }
        $pm = $parent->getMethod($m->getName());
        $checked++;
        // 子类的参数个数不能少于父类，否则 PHP 直接 Fatal
        if ($m->getNumberOfParameters() < $pm->getNumberOfParameters()) {
            $failures[] = sprintf(
                '%s::%s() 参数比父类 %s::%s() 少（%d < %d），PHP 会直接 Fatal error',
                $class, $m->getName(), $parent->getName(), $pm->getName(),
                $m->getNumberOfParameters(), $pm->getNumberOfParameters()
            );
        }
        // 返回类型协变：PHP 7.4 才允许，本库声明兼容 7.2，必须与父类完全一致。
        // 注意 self 要解析成「声明它的类」才看得出来——父子都写 self 时字面相同，
        // 实际类型却不同，正是这一点让 ClaudeCodeSession 在 7.2 上加载即 Fatal。
        $childRet  = resolveReturnType($m);
        $parentRet = resolveReturnType($pm);
        if ($childRet !== $parentRet) {
            $failures[] = sprintf(
                '%s::%s() 返回 %s，父类 %s::%s() 返回 %s —— 返回类型协变，PHP 7.2 会 Fatal',
                $class, $m->getName(), $childRet,
                $parent->getName(), $pm->getName(), $parentRet
            );
        }
    }
}
echo "检查了 {$checked} 个覆盖方法\n";

echo "\n", str_repeat('=', 60), "\n";
if ($failures) {
    echo count($failures) . " 项未通过：\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    exit(1);
}
echo "全部通过\n";
exit(0);
