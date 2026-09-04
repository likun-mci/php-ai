<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ParallelSafeToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;
use Ai\Helpers\Shell;

/**
 * 文件搜索工具（Glob）
 *
 * 按 pattern 匹配工作区内的文件路径，类似命令行 glob。
 * 让 Agent 可以不依赖 Bash 就发现项目结构。
 *
 * v2.1（dev.md §1.4）：用 RecursiveDirectoryIterator 可靠递归（PHP glob 的 ** 不可靠），
 * 并**尊重 .gitignore**——探测到 git 就用 `git ls-files`（完整 gitignore 语义），
 * 无 git 时回退递归遍历 + 基础排除（.git/vendor/node_modules/隐藏目录）。
 *
 * 用法：
 *   glob(pattern: "** /*.php")
 *   glob(pattern: "src/** /*.php", limit: 20)
 */
class GlobTool implements AgentToolInterface, ParallelSafeToolInterface
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

    public function isParallelSafe()
    {
        return true;
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

        // 候选文件（相对路径），尊重 .gitignore
        $candidates = $this->listFiles($searchDir);
        $regex = $this->globToRegex(ltrim($pattern, '/'));

        $results = [];
        foreach ($candidates as $rel) {
            if (@preg_match($regex, $rel) === 1) {
                $results[] = $rel;
            }
        }

        if (!$results) {
            return ToolResult::success('未找到匹配的文件：' . $pattern, [
                'pattern' => $pattern,
                'count'   => 0,
            ]);
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

    /**
     * 列出工作区内的文件（相对路径），尊重 .gitignore
     *
     * git 可用且是 git 仓库时用 `git ls-files`（完整 gitignore 语义）；否则递归遍历
     * 并做基础排除（.git/vendor/node_modules/隐藏目录）。
     *
     * @param string $searchDir 带尾斜杠的绝对根目录
     * @return string[] 相对路径列表
     */
    protected function listFiles($searchDir)
    {
        $root = rtrim($searchDir, '/');

        if (Shell::hasBinary('git') && is_dir($root . '/.git')) {
            $cmd = 'git -C ' . escapeshellarg($root)
                . ' ls-files --cached --others --exclude-standard';
            $res = Shell::capture($cmd, ['timeout' => 20, 'cwd' => $root]);
            if ($res['code'] === 0) {
                $files = preg_split('/\r?\n/', trim($res['out']));
                $out = [];
                foreach ($files === false ? [] : $files as $f) {
                    $f = trim($f);
                    if ($f !== '' && is_file($root . '/' . $f)) {
                        $out[] = $f;
                    }
                }
                return $out;
            }
        }

        // 回退：递归遍历 + 基础排除
        $out = [];
        if (!is_dir($root)) {
            return $out;
        }
        $it = new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS);
        $it = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::SELF_FIRST);
        $rootLen = strlen($root) + 1;
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = substr($file->getPathname(), $rootLen);
            $rel = str_replace('\\', '/', $rel);
            if ($rel === '' || $rel[0] === '.' || strpos($rel, '/.') !== false) {
                continue;   // 隐藏文件/目录
            }
            if (strpos($rel, 'vendor/') === 0 || strpos($rel, 'node_modules/') === 0) {
                continue;
            }
            $out[] = $rel;
        }
        return $out;
    }

    /**
     * 把 glob 模式转成锚定的正则，支持 **（跨目录）、*、?
     *
     * `**\/` 匹配零或多层目录；`*` 不跨 `/`；`?` 匹配单个非 `/` 字符。
     *
     * @param string $glob
     * @return string
     */
    protected function globToRegex($glob)
    {
        $glob = str_replace('\\', '/', $glob);
        $re = '';
        $len = strlen($glob);
        for ($i = 0; $i < $len; $i++) {
            $c = $glob[$i];
            if ($c === '*') {
                if ($i + 1 < $len && $glob[$i + 1] === '*') {
                    // ** ，若后跟 / 则吞掉，匹配零或多层目录
                    $i++;
                    if ($i + 1 < $len && $glob[$i + 1] === '/') {
                        $i++;
                        $re .= '(?:[^/]+/)*';
                    } else {
                        $re .= '.*';
                    }
                } else {
                    $re .= '[^/]*';
                }
            } elseif ($c === '?') {
                $re .= '[^/]';
            } else {
                $re .= preg_quote($c, '#');
            }
        }
        return '#^' . $re . '$#';
    }
}