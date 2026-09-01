<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;

/**
 * 文件搜索工具（Glob）
 *
 * 按 pattern 匹配工作区内的文件路径，类似命令行 glob。
 * 让 Agent 可以不依赖 Bash 就发现项目结构。
 *
 * 用法：
 *   glob(pattern: "** /*.php")
 *   glob(pattern: "src/** /*.php", limit: 20)
 */
class GlobTool implements AgentToolInterface
{
    /** @var PathSafety */
    protected $pathSafety;

    /** @var int 最大返回条数 */
    protected $maxResults = 100;

    /**
     * @param PathSafety $pathSafety
     * @param int $maxResults
     */
    public function __construct(PathSafety $pathSafety, $maxResults = 100)
    {
        $this->pathSafety = $pathSafety;
        $this->maxResults = max(1, (int) $maxResults);
    }

    public function name()
    {
        return 'glob';
    }

    public function description()
    {
        return '按 glob 模式匹配工作区内的文件路径。支持 **/*.php 等通配符。'
            . '适合在修改/阅读前先找到文件位置。';
    }

    public function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'pattern' => [
                    'type'        => 'string',
                    'description' => 'glob 模式，如 **/*.php、src/**/*、*.md',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => '最多返回多少条结果',
                    'default'     => $this->maxResults,
                ],
            ],
            'required' => ['pattern'],
        ];
    }

    public function execute(array $input, ToolContext $context)
    {
        $pattern = isset($input['pattern']) ? (string) $input['pattern'] : '';
        $limit   = isset($input['limit']) ? (int) $input['limit'] : $this->maxResults;

        if ($pattern === '') {
            return ToolResult::error('参数 pattern 不能为空');
        }

        if ($limit <= 0) {
            $limit = $this->maxResults;
        }

        // 只允许在沙箱内搜索
        $rootDir = $this->pathSafety->rootDir();
        $searchDir = rtrim($rootDir, '/') . '/';

        if (!is_dir($searchDir)) {
            return ToolResult::error('工作目录不存在：' . $searchDir);
        }

        // 用 PHP 的 glob 匹配
        $globPattern = $searchDir . ltrim($pattern, '/');
        $files = glob($globPattern);

        if ($files === false || !$files) {
            return ToolResult::success('未找到匹配的文件：' . $pattern, [
                'pattern' => $pattern,
                'count'   => 0,
            ]);
        }

        // 转为相对路径，过滤掉目录
        $results = [];
        foreach ($files as $abs) {
            $rel = str_replace($searchDir, '', $abs);
            $results[] = $rel;
        }
        sort($results);

        // 限制返回条数
        $total = count($results);
        $isPartial = false;
        if ($total > $limit) {
            $results = array_slice($results, 0, $limit);
            $isPartial = true;
        }

        $content = "Found {$total} file(s) matching '{$pattern}':\n";
        foreach ($results as $r) {
            $content .= "  {$r}\n";
        }
        if ($isPartial) {
            $content .= "… and " . ($total - $limit) . " more (use a more specific pattern to narrow results)\n";
        }

        return new ToolResult([
            'success'    => true,
            'content'    => $content,
            'metadata'   => [
                'pattern' => $pattern,
                'count'   => $total,
                'returned' => count($results),
            ],
            'is_partial' => $isPartial,
            'display'    => "Glob '{$pattern}': {$total} files",
        ]);
    }
}