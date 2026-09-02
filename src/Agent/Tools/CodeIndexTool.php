<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ParallelSafeToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;
use Ai\Code\CodeAnalyzer;

/**
 * 代码索引工具（code_index）
 *
 * 把 `Ai\Code\CodeAnalyzer` 的能力交给 Agent：查类结构、查调用方、查依赖、
 * 查符号定义在哪。**一次扫描，反复查询**——explorer 调查一个类时不必每次都
 * grep 一遍全项目，也不必把整个类的源码读进上下文。
 *
 * ```php
 * $agent->addTool(new CodeIndexTool('/var/www/project/src'));
 * // 模型调用：
 * //   code_index(action: "explain", target: "App\Auth")
 * //   code_index(action: "callers", target: "login")
 * //   code_index(action: "dependents", target: "App\Auth")
 * //   code_index(action: "related", target: "src/Auth.php")
 * //   code_index(action: "symbol", target: "login")
 * ```
 *
 * 索引是惰性建立的（首次调用时扫描），之后常驻内存。改过文件后调
 * `action: "refresh"` 重扫，或用 `refreshFile` 只更新一个文件。
 *
 * **精度限制照实说**：`$obj->save()` 拿不到接收者的真实类型，`callers` 查方法名时
 * 所有同名方法会一起命中。结果是「可能的调用方」，用来缩小排查范围可以，
 * 当作重构的唯一依据不行——工具返回里也会带上这句提醒。
 */
class CodeIndexTool implements AgentToolInterface, ParallelSafeToolInterface
{
    /** @var string 索引根目录 */
    protected $rootDir = '';

    /** @var CodeAnalyzer */
    protected $analyzer;

    /** @var bool 索引是否已建立 */
    protected $indexed = false;

    /** @var int 单次返回的最大条目数 */
    protected $maxItems = 50;

    /**
     * @param string $rootDir 要索引的目录
     * @param array<string, mixed> $options maxItems / excludeDirs / maxFileSize
     */
    public function __construct($rootDir, array $options = [])
    {
        $this->rootDir = rtrim(str_replace('\\', '/', (string) $rootDir), '/');
        if (isset($options['maxItems'])) {
            $this->maxItems = max(1, (int) $options['maxItems']);
        }
        $this->analyzer = new CodeAnalyzer($options);
    }

    /**
     * @return string
     */
    public function name()
    {
        return 'code_index';
    }

    /**
     * @return bool
     */
    public function isParallelSafe()
    {
        return true;
    }

    /**
     * @return string
     */
    public function description()
    {
        return '查询项目代码结构索引：类的结构与继承关系、谁调用了某个方法、某个类被谁依赖、'
            . '与某文件相关的文件、符号定义在哪一行。比 grep 更准且不必读整份源码；'
            . '改过文件后用 action=refresh 重建索引。';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type'        => 'string',
                    'description' => '查询类型',
                    'enum'        => ['explain', 'callers', 'dependencies', 'dependents',
                                      'related', 'symbol', 'stats', 'refresh'],
                ],
                'target' => [
                    'type'        => 'string',
                    'description' => '查询目标：类名（explain/dependencies/dependents）、'
                        . '方法名或函数名（callers/symbol）、文件路径（related）',
                ],
                'transitive' => [
                    'type'        => 'boolean',
                    'description' => 'dependencies / dependents 是否含间接依赖',
                    'default'     => false,
                ],
            ],
            'required' => ['action'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param ToolContext $context
     * @return ToolResult
     */
    public function execute(array $input, ToolContext $context)
    {
        $action = isset($input['action']) ? strtolower((string) $input['action']) : '';
        $target = isset($input['target']) ? trim((string) $input['target']) : '';
        $transitive = !empty($input['transitive']);

        if ($action === '') {
            return ToolResult::error('缺少 action 参数');
        }

        $root = $this->resolveRoot($context);
        if ($root === '' || !is_dir($root)) {
            return ToolResult::error('索引目录不存在：' . $root);
        }

        if ($action === 'refresh') {
            $this->analyzer->clear();
            $this->indexed = false;
            $count = $this->ensureIndex($root);
            return ToolResult::success("索引已重建，共 {$count} 个文件");
        }

        $this->ensureIndex($root);

        switch ($action) {
            case 'stats':
                return ToolResult::success(
                    json_encode($this->analyzer->stats(), JSON_UNESCAPED_UNICODE) ?: '{}'
                );

            case 'explain':
                if ($target === '') {
                    return ToolResult::error('explain 需要 target（类名）');
                }
                $text = $this->analyzer->explain($target);
                return $text === ''
                    ? ToolResult::error('索引里没有这个类：' . $target)
                    : ToolResult::success($text);

            case 'callers':
                if ($target === '') {
                    return ToolResult::error('callers 需要 target（方法名或函数名）');
                }
                $callers = $this->analyzer->findCallers($target);
                if (!$callers) {
                    return ToolResult::success('没有找到调用方：' . $target);
                }
                return ToolResult::success(
                    $this->formatList('可能调用了 ' . $target . ' 的位置', $callers)
                    . "\n注意：方法调用无法确定接收者类型，同名方法会一并命中，请自行核对。"
                );

            case 'dependencies':
                if ($target === '') {
                    return ToolResult::error('dependencies 需要 target（类名）');
                }
                return ToolResult::success($this->formatList(
                    $target . ' 依赖的类' . ($transitive ? '（含间接）' : ''),
                    $this->analyzer->findDependencies($target, $transitive)
                ));

            case 'dependents':
                if ($target === '') {
                    return ToolResult::error('dependents 需要 target（类名）');
                }
                return ToolResult::success($this->formatList(
                    '依赖 ' . $target . ' 的类' . ($transitive ? '（含间接）' : '') . '——改动影响面',
                    $this->analyzer->findDependents($target, $transitive)
                ));

            case 'related':
                if ($target === '') {
                    return ToolResult::error('related 需要 target（文件路径）');
                }
                $path = $this->absolutePath($target, $root);
                return ToolResult::success($this->formatList(
                    '与 ' . $target . ' 相关的文件',
                    $this->analyzer->findRelatedFiles($path, $this->maxItems)
                ));

            case 'symbol':
                if ($target === '') {
                    return ToolResult::error('symbol 需要 target（类名/方法名/函数名）');
                }
                $hits = $this->analyzer->findSymbol($target);
                if (!$hits) {
                    return ToolResult::success('索引里没有这个符号：' . $target);
                }
                $lines = [];
                foreach (array_slice($hits, 0, $this->maxItems) as $hit) {
                    $lines[] = sprintf('[%s] %s — %s:%d', $hit['type'], $hit['name'], $hit['file'], $hit['line']);
                }
                return ToolResult::success(implode("\n", $lines));
        }

        return ToolResult::error('不支持的 action：' . $action);
    }

    /**
     * 底层分析器——业务代码可以直接拿来用
     *
     * @return CodeAnalyzer
     */
    public function analyzer()
    {
        return $this->analyzer;
    }

    /**
     * 确保索引已建立
     *
     * @param string $root
     * @return int 索引里的文件数
     */
    protected function ensureIndex($root)
    {
        if (!$this->indexed) {
            $this->analyzer->scan($root);
            $this->indexed = true;
        }
        $stats = $this->analyzer->stats();
        return $stats['files'];
    }

    /**
     * 索引根目录：构造时给的优先，否则用工具上下文的工作目录
     *
     * @param ToolContext $context
     * @return string
     */
    protected function resolveRoot(ToolContext $context)
    {
        if ($this->rootDir !== '') {
            return $this->rootDir;
        }
        return rtrim(str_replace('\\', '/', (string) $context->workdir()), '/');
    }

    /**
     * @param string $path
     * @param string $root
     * @return string
     */
    protected function absolutePath($path, $root)
    {
        $path = str_replace('\\', '/', (string) $path);
        return strpos($path, '/') === 0 ? $path : $root . '/' . ltrim($path, './');
    }

    /**
     * @param string $title
     * @param string[] $items
     * @return string
     */
    protected function formatList($title, array $items)
    {
        if (!$items) {
            return $title . '：无';
        }
        $shown = array_slice($items, 0, $this->maxItems);
        $text = $title . '（' . count($items) . '）：';
        foreach ($shown as $item) {
            $text .= "\n  - " . $item;
        }
        if (count($items) > count($shown)) {
            $text .= "\n  …（其余 " . (count($items) - count($shown)) . ' 条已省略）';
        }
        return $text;
    }
}
