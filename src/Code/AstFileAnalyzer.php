<?php
namespace Ai\Code;

/**
 * AST 版单文件解析器——装了 nikic/php-parser 时的精度增强路径
 *
 * 本库承诺零运行时依赖，所以默认走 {@see FileAnalyzer} 的 token 级解析。
 * 但 token 扫描在嵌套闭包、匿名类、attribute、enum 这类语法上容易走偏，
 * 且类名解析要靠自己拼 use 别名表。装了 php-parser 就改走这里：
 * 由 `NameResolver` 把每个类名解析成**全限定名**，结构由语法树给出，不会漏配对。
 *
 * 输出与 FileAnalyzer 完全同形（同样返回 FileAnalysis / ClassAnalysis /
 * FunctionAnalysis），因此是**无缝替换**：装了就更准，没装就回退，调用方无感。
 *
 * 注意能力边界：AST 只给语法结构，**给不了类型**。
 * `$obj->save()` 这类接收者类型推断仍然做不到——那需要完整类型系统，
 * 由 `lsp` 工具（语言服务器）负责，见 dev.md 第三梯队。
 */
class AstFileAnalyzer
{
    /** @var mixed PhpParser\Parser 实例 */
    protected $parser = null;

    /**
     * 当前环境是否可用（是否装了 nikic/php-parser）
     *
     * @return bool
     */
    public static function isAvailable()
    {
        return class_exists('PhpParser\ParserFactory')
            && class_exists('PhpParser\NodeTraverser')
            && class_exists('PhpParser\NodeVisitor\NameResolver');
    }

    /**
     * 解析一个文件
     *
     * @param string $path
     * @return FileAnalysis|null 读不到或解析失败返回 null
     */
    public function analyze($path)
    {
        $code = @file_get_contents($path);
        if ($code === false) {
            return null;
        }
        return $this->analyzeCode($code, $path);
    }

    /**
     * 解析源码
     *
     * @param string $code
     * @param string $path
     * @return FileAnalysis|null 语法错误返回 null（调用方可回退 token 版）
     */
    public function analyzeCode($code, $path = '')
    {
        $ast = $this->parse($code);
        if ($ast === null) {
            return null;
        }

        $ctx = [
            'namespace' => '',
            'imports'   => [],
            'classes'   => [],
            'functions' => [],
            'constants' => [],
            'calls'     => [],
        ];
        $this->walk($ast, $ctx);

        return new FileAnalysis($path, [
            'namespace' => $ctx['namespace'],
            'imports'   => $ctx['imports'],
            'classes'   => $ctx['classes'],
            'functions' => $ctx['functions'],
            'constants' => $ctx['constants'],
            'calls'     => array_values(array_unique($ctx['calls'])),
            'lines'     => substr_count($code, "\n") + 1,
        ]);
    }

    /**
     * 解析成已做名称解析的语法树；语法错误返回 null
     *
     * @param string $code
     * @return array<int, mixed>|null
     */
    protected function parse($code)
    {
        if (!self::isAvailable()) {
            return null;
        }
        try {
            if ($this->parser === null) {
                $factory = new \PhpParser\ParserFactory();
                // prefer7 在 v4 里表示「优先按新语法解析，失败再退」，兼容面最广
                $this->parser = $factory->create(\PhpParser\ParserFactory::PREFER_PHP7);
            }
            $ast = $this->parser->parse((string) $code);
            if ($ast === null) {
                return null;
            }
            $traverser = new \PhpParser\NodeTraverser();
            // NameResolver 把 Name 节点补成全限定名，并给类/函数节点填 namespacedName
            $traverser->addVisitor(new \PhpParser\NodeVisitor\NameResolver(null, [
                'preserveOriginalNames' => true,
            ]));
            return $traverser->traverse($ast);
        } catch (\Throwable $e) {
            return null;   // 语法错误等：交给调用方回退
        }
    }

    /**
     * 遍历顶层节点
     *
     * @param array<int, mixed> $nodes
     * @param array<string, mixed> $ctx
     * @return void
     */
    protected function walk(array $nodes, array &$ctx)
    {
        foreach ($nodes as $node) {
            if ($node instanceof \PhpParser\Node\Stmt\Namespace_) {
                if ($node->name !== null) {
                    $ctx['namespace'] = $node->name->toString();
                }
                if (is_array($node->stmts)) {
                    $this->walk($node->stmts, $ctx);
                }
                continue;
            }
            if ($node instanceof \PhpParser\Node\Stmt\Use_) {
                foreach ($node->uses as $use) {
                    $alias = $use->alias !== null ? $use->alias->toString() : $use->name->getLast();
                    $ctx['imports'][$alias] = $use->name->toString();
                }
                continue;
            }
            if ($node instanceof \PhpParser\Node\Stmt\GroupUse) {
                $prefix = $node->prefix->toString();
                foreach ($node->uses as $use) {
                    $alias = $use->alias !== null ? $use->alias->toString() : $use->name->getLast();
                    $ctx['imports'][$alias] = $prefix . '\\' . $use->name->toString();
                }
                continue;
            }
            if ($node instanceof \PhpParser\Node\Stmt\ClassLike) {
                $cls = $this->buildClass($node, $ctx['namespace']);
                if ($cls !== null) {
                    $ctx['classes'][] = $cls;
                }
                continue;
            }
            if ($node instanceof \PhpParser\Node\Stmt\Function_) {
                $ctx['functions'][] = $this->buildFunction($node, '');
                continue;
            }
            if ($node instanceof \PhpParser\Node\Stmt\Const_) {
                foreach ($node->consts as $c) {
                    $ctx['constants'][] = ['name' => $c->name->toString(), 'line' => $c->getLine()];
                }
                continue;
            }
            // 其余顶层语句里的调用也算文件级调用
            foreach ($this->collectCalls($node) as $call) {
                $ctx['calls'][] = $call;
            }
        }
    }

    /**
     * 构造 ClassAnalysis
     *
     * @param mixed $node ClassLike 节点
     * @param string $namespace
     * @return ClassAnalysis|null
     */
    protected function buildClass($node, $namespace)
    {
        if ($node->name === null) {
            return null;   // 匿名类不入索引
        }
        $fullName = isset($node->namespacedName) && $node->namespacedName !== null
            ? $node->namespacedName->toString()
            : $node->name->toString();

        $kind = 'class';
        if ($node instanceof \PhpParser\Node\Stmt\Interface_) {
            $kind = 'interface';
        } elseif ($node instanceof \PhpParser\Node\Stmt\Trait_) {
            $kind = 'trait';
        } elseif (class_exists('PhpParser\Node\Stmt\Enum_') && $node instanceof \PhpParser\Node\Stmt\Enum_) {
            $kind = 'enum';
        }

        $parent = '';
        $interfaces = [];
        if ($node instanceof \PhpParser\Node\Stmt\Class_) {
            if ($node->extends !== null) {
                $parent = $node->extends->toString();
            }
            foreach ($node->implements as $i) {
                $interfaces[] = $i->toString();
            }
        } elseif ($node instanceof \PhpParser\Node\Stmt\Interface_) {
            foreach ($node->extends as $i) {
                $interfaces[] = $i->toString();
            }
        }

        $traits = [];
        $methods = [];
        $properties = [];
        $constants = [];
        $dependencies = [];

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\TraitUse) {
                foreach ($stmt->traits as $t) {
                    $traits[] = $t->toString();
                }
            } elseif ($stmt instanceof \PhpParser\Node\Stmt\ClassMethod) {
                $fn = $this->buildFunction($stmt, $fullName);
                $methods[] = $fn;
                foreach ($fn->getParams() as $p) {
                    if (isset($p['type']) && $p['type'] !== '') {
                        $dependencies[] = ltrim((string) $p['type'], '?\\');
                    }
                }
            } elseif ($stmt instanceof \PhpParser\Node\Stmt\Property) {
                $type = $stmt->type !== null ? $this->typeToString($stmt->type) : '';
                foreach ($stmt->props as $prop) {
                    $properties[] = [
                        'name'       => $prop->name->toString(),
                        'type'       => $type,
                        'visibility' => $this->visibilityOf($stmt),
                        'static'     => $stmt->isStatic(),
                        'line'       => $prop->getLine(),
                    ];
                }
                if ($type !== '') {
                    $dependencies[] = ltrim($type, '?\\');
                }
            } elseif ($stmt instanceof \PhpParser\Node\Stmt\ClassConst) {
                foreach ($stmt->consts as $c) {
                    $constants[] = ['name' => $c->name->toString(), 'line' => $c->getLine()];
                }
            }
        }

        if ($parent !== '') {
            $dependencies[] = $parent;
        }
        foreach ($interfaces as $i) {
            $dependencies[] = $i;
        }
        foreach ($traits as $t) {
            $dependencies[] = $t;
        }

        $abstract = $node instanceof \PhpParser\Node\Stmt\Class_ ? $node->isAbstract() : false;
        $final = $node instanceof \PhpParser\Node\Stmt\Class_ ? $node->isFinal() : false;

        return new ClassAnalysis($fullName, [
            'namespace'    => $namespace,
            'kind'         => $kind,
            'parent'       => $parent,
            'interfaces'   => $interfaces,
            'traits'       => $traits,
            'methods'      => $methods,
            'properties'   => $properties,
            'constants'    => $constants,
            'abstract'     => $abstract,
            'final'        => $final,
            'line'         => $node->getLine(),
            'endLine'      => $node->getEndLine(),
            'dependencies' => $this->filterTypes($dependencies),
        ]);
    }

    /**
     * 构造 FunctionAnalysis
     *
     * @param mixed $node Function_ 或 ClassMethod
     * @param string $className
     * @return FunctionAnalysis
     */
    protected function buildFunction($node, $className)
    {
        $params = [];
        foreach ($node->params as $p) {
            $params[] = [
                'name' => $p->var instanceof \PhpParser\Node\Expr\Variable && is_string($p->var->name)
                    ? $p->var->name : '',
                'type' => $p->type !== null ? $this->typeToString($p->type) : '',
            ];
        }
        $calls = [];
        if ($node->stmts !== null) {
            foreach ($node->stmts as $stmt) {
                foreach ($this->collectCalls($stmt) as $c) {
                    $calls[] = $c;
                }
            }
        }
        $visibility = '';
        if ($className !== '' && $node instanceof \PhpParser\Node\Stmt\ClassMethod) {
            $visibility = $this->visibilityOf($node);
        }
        return new FunctionAnalysis($node->name->toString(), [
            'class'      => $className,
            'params'     => $params,
            'returnType' => $node->returnType !== null ? $this->typeToString($node->returnType) : '',
            'visibility' => $visibility,
            'static'     => $node instanceof \PhpParser\Node\Stmt\ClassMethod ? $node->isStatic() : false,
            'abstract'   => $node instanceof \PhpParser\Node\Stmt\ClassMethod ? $node->isAbstract() : false,
            'line'       => $node->getLine(),
            'endLine'    => $node->getEndLine(),
            'calls'      => array_values(array_unique($calls)),
        ]);
    }

    /**
     * 递归收集一个节点子树里的调用，格式与 token 版一致：
     * `->method` / `FQN::method` / `funcName`
     *
     * @param mixed $node
     * @return string[]
     */
    protected function collectCalls($node)
    {
        $out = [];
        if (!$node instanceof \PhpParser\Node) {
            return $out;
        }
        if ($node instanceof \PhpParser\Node\Expr\MethodCall
            && $node->name instanceof \PhpParser\Node\Identifier) {
            $out[] = '->' . $node->name->toString();
        } elseif ($node instanceof \PhpParser\Node\Expr\StaticCall
            && $node->name instanceof \PhpParser\Node\Identifier
            && $node->class instanceof \PhpParser\Node\Name) {
            $out[] = $node->class->toString() . '::' . $node->name->toString();
        } elseif ($node instanceof \PhpParser\Node\Expr\FuncCall
            && $node->name instanceof \PhpParser\Node\Name) {
            $out[] = ltrim($node->name->toString(), '\\');
        } elseif ($node instanceof \PhpParser\Node\Expr\New_
            && $node->class instanceof \PhpParser\Node\Name) {
            $out[] = ltrim($node->class->toString(), '\\');
        }
        foreach ($node->getSubNodeNames() as $name) {
            $sub = $node->$name;
            if ($sub instanceof \PhpParser\Node) {
                foreach ($this->collectCalls($sub) as $c) {
                    $out[] = $c;
                }
            } elseif (is_array($sub)) {
                foreach ($sub as $item) {
                    if ($item instanceof \PhpParser\Node) {
                        foreach ($this->collectCalls($item) as $c) {
                            $out[] = $c;
                        }
                    }
                }
            }
        }
        return $out;
    }

    /**
     * 类型节点转字符串（含可空与联合类型）
     *
     * @param mixed $type
     * @return string
     */
    protected function typeToString($type)
    {
        if ($type instanceof \PhpParser\Node\NullableType) {
            return '?' . $this->typeToString($type->type);
        }
        if ($type instanceof \PhpParser\Node\UnionType) {
            $parts = [];
            foreach ($type->types as $t) {
                $parts[] = $this->typeToString($t);
            }
            return implode('|', $parts);
        }
        if ($type instanceof \PhpParser\Node\Name) {
            return $type->toString();
        }
        if ($type instanceof \PhpParser\Node\Identifier) {
            return $type->toString();
        }
        return '';
    }

    /**
     * @param mixed $stmt
     * @return string
     */
    protected function visibilityOf($stmt)
    {
        if (!is_object($stmt)) {
            return 'public';
        }
        if (method_exists($stmt, 'isPrivate') && $stmt->isPrivate()) {
            return 'private';
        }
        if (method_exists($stmt, 'isProtected') && $stmt->isProtected()) {
            return 'protected';
        }
        return 'public';
    }

    /**
     * 滤掉标量/伪类型，只留真正的类依赖（与 token 版口径一致）
     *
     * @param string[] $names
     * @return string[]
     */
    protected function filterTypes(array $names)
    {
        $skip = ['int', 'float', 'string', 'bool', 'array', 'callable', 'iterable',
                 'void', 'mixed', 'object', 'null', 'false', 'true', 'self', 'static',
                 'parent', 'never'];
        $out = [];
        foreach ($names as $n) {
            $n = ltrim((string) $n, '?\\');
            if ($n === '' || in_array(strtolower($n), $skip, true)) {
                continue;
            }
            $out[] = $n;
        }
        return array_values(array_unique($out));
    }
}
