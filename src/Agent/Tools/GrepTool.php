<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ParallelSafeToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;

/**
 * 文本搜索工具（Grep）
 *
 * 在工作区内按模式搜索文件内容，类似命令行 grep。
 * 让 Agent 可以快速定位哪些文件包含特定代码、函数调用或配置。
 *
 * 用法：
 * ```php
 * grep(pattern: "class User", include: "*.php")
 * grep(pattern: "login", path: "src/", include: "*.php", limit: 20)
 * ```
 */
class GrepTool implements AgentToolInterface, ParallelSafeToolInterface
{
    /** @var PathSafety */
    protected $pathSafety;

    /** @var int 最大结果条数 */
    protected $maxResults = 50;

    /** @var int 每行上下文最大字符数 */
    protected $maxLineLength = 500;

    /**
     * @param PathSafety $pathSafety
     * @param int $maxResults
     */
    public function __construct(PathSafety $pathSafety, $maxResults = 50)
    {
        $this->pathSafety = $pathSafety;
        $this->maxResults = max(1, (int) $maxResults);
    }

    public function name()
    {
        return 'grep';
    }

    public function isParallelSafe()
    {
        return true;
    }

    public function description()
    {
        return '在工作区内按模式搜索文件内容。支持 include（文件通配符过滤）和 path（路径限制）。'
            . '适合在修改前找到相关的代码位置。';
    }

    public function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'pattern' => [
                    'type'        => 'string',
                    'description' => '搜索模式（字符串或正则表达式）',
                ],
                'include' => [
                    'type'        => 'string',
                    'description' => '文件通配符过滤，如 *.php、*.{php,js}',
                    'default'     => null,
                ],
                'path' => [
                    'type'        => 'string',
                    'description' => '搜索路径限制（相对工作区）',
                    'default'     => null,
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
        $include = isset($input['include']) ? (string) $input['include'] : '';
        $path    = isset($input['path']) ? (string) $input['path'] : '';
        $limit   = isset($input['limit']) ? (int) $input['limit'] : $this->maxResults;

        if ($pattern === '') {
            return ToolResult::error('参数 pattern 不能为空');
        }
        if ($limit <= 0) {
            $limit = $this->maxResults;
        }

        $rootDir = $this->pathSafety->rootDir();
        $searchDir = rtrim($rootDir, '/') . '/';

        // 构建搜索路径
        $searchPath = $searchDir;
        if ($path !== '') {
            $searchPath .= ltrim($path, '/');
        }

        if (!is_dir($searchPath)) {
            return ToolResult::error('搜索路径不存在：' . $path);
        }

        // 如果是正则模式，用 preg_match；否则用 strpos
        $isRegex = (strlen($pattern) > 2 && $pattern[0] === '/');

        // 递归收集文件（PHP glob 的 ** 递归不可靠，自己遍历）
        $files = $this->collectFiles($searchPath, $include);
        if (!$files) {
            return ToolResult::success('未找到匹配的文件：' . $pattern, [
                'pattern' => $pattern,
                'count'   => 0,
            ]);
        }

        $results = [];
        $fileCount = 0;

        foreach ($files as $abs) {
            if (count($results) >= $limit) {
                break;
            }
            $rel = str_replace($searchDir, '', $abs);

            // 大文件只读前 5000 行
            $handle = @fopen($abs, 'r');
            if ($handle === false) {
                continue;
            }

            $lineNum = 0;
            $fileResult = [];

            while (($line = fgets($handle)) !== false) {
                $lineNum++;
                if (count($results) + count($fileResult) >= $limit) {
                    break;
                }

                $matched = false;
                if ($isRegex) {
                    $matched = preg_match($pattern, $line) === 1;
                } else {
                    $matched = strpos($line, $pattern) !== false;
                }

                if ($matched) {
                    $truncated = mb_strlen($line) > $this->maxLineLength
                        ? mb_substr($line, 0, $this->maxLineLength) . '…'
                        : $line;
                    $fileResult[] = $rel . ':' . $lineNum . ':' . rtrim($truncated);
                }
            }
            fclose($handle);

            if ($fileResult) {
                $fileCount++;
                $results = array_merge($results, $fileResult);
            }
        }

        $total = count($results);
        $isPartial = $total > $limit;

        if ($total === 0) {
            return ToolResult::success('未找到匹配 "' . $pattern . '" 的内容', [
                'pattern' => $pattern,
                'count'   => 0,
            ]);
        }

        $displayResults = $isPartial ? array_slice($results, 0, $limit) : $results;
        $content = "Found {$total} match(es) in {$fileCount} file(s) for '{$pattern}':\n";
        $content .= "---\n";
        $content .= implode("\n", $displayResults) . "\n";
        if ($isPartial) {
            $content .= "… and " . ($total - $limit) . " more (use a more specific pattern to narrow)\n";
        }

        return new ToolResult([
            'success'    => true,
            'content'    => $content,
            'metadata'   => [
                'pattern' => $pattern,
                'matches' => $total,
                'files'   => $fileCount,
            ],
            'is_partial' => $isPartial,
            'display'    => "Grep '{$pattern}': {$total} matches in {$fileCount} files",
        ]);
    }

    /**
     * 递归收集文件
     *
     * @param string $dir 搜索根目录
     * @param string $include 文件通配符过滤（如 *.php）
     * @return string[] 匹配的文件绝对路径列表
     */
    protected function collectFiles($dir, $include)
    {
        $files = [];
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            return $files;
        }

        $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $it = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($it as $file) {
            if (!$file->isFile() || !$file->isReadable()) {
                continue;
            }
            $path = $file->getPathname();
            // 跳过隐藏目录和 vendor/node_modules
            $relPath = str_replace($dir . '/', '', $path);
            if (strpos($relPath, '.') === 0 || strpos($relPath, '/.') !== false) {
                continue;
            }
            if (strpos($relPath, 'vendor/') === 0 || strpos($relPath, 'node_modules/') === 0) {
                continue;
            }
            if ($include !== '') {
                if (!fnmatch($include, $file->getFilename())) {
                    continue;
                }
            }
            $files[] = $path;
        }
        return $files;
    }
}