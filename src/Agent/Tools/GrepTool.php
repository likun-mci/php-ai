<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ParallelSafeToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;
use Ai\Helpers\Shell;

/**
 * 文本搜索工具（Grep）
 *
 * 在工作区内按模式搜索文件内容，类似命令行 grep / ripgrep。
 *
 * 渐进增强（见 dev.md v2.1 §1.1）：探测到 `rg`（ripgrep）就用它（快、尊重 .gitignore、
 * 多行等），探测不到回退纯 PHP 遍历。两条路对同一查询结果一致，只是性能不同。
 *
 * 模式语义（两路一致，兼容旧行为）：pattern 以 `/.../` 包裹视为正则，否则按**字面**匹配。
 *
 * 用法：
 * ```php
 * grep(pattern: "class User", include: "*.php")
 * grep(pattern: "login", path: "src/", ignore_case: true, context: 2)
 * grep(pattern: "TODO", output_mode: "files_with_matches")
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

    /** @var string 引擎选择：auto|rg|php（auto=探测到 rg 用 rg，否则 php） */
    protected $engine = 'auto';

    /**
     * @param PathSafety $pathSafety
     * @param int $maxResults
     */
    public function __construct(PathSafety $pathSafety, $maxResults = 50)
    {
        $this->pathSafety = $pathSafety;
        $this->maxResults = max(1, (int) $maxResults);
    }

    /**
     * 强制引擎（测试用于对拍 rg 与 php 两路）
     *
     * @param string $engine auto|rg|php
     * @return $this
     */
    public function setEngine($engine)
    {
        $engine = (string) $engine;
        if (in_array($engine, ['auto', 'rg', 'php'], true)) {
            $this->engine = $engine;
        }
        return $this;
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
        return '在工作区内按模式搜索文件内容。pattern 用 /.../ 包裹为正则，否则按字面匹配。'
            . '支持 include（文件通配符）、path（路径限制）、ignore_case、上下文行（context/before/after）、'
            . 'output_mode（content|files_with_matches|count）。适合修改前定位相关代码。';
    }

    public function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'pattern' => [
                    'type'        => 'string',
                    'description' => '搜索模式；/.../ 包裹为正则，否则字面匹配',
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
                'ignore_case' => [
                    'type'        => 'boolean',
                    'description' => '忽略大小写',
                    'default'     => false,
                ],
                'context' => [
                    'type'        => 'integer',
                    'description' => '匹配行前后各显示多少行（等价 grep -C，仅 content 模式）',
                    'default'     => 0,
                ],
                'before' => [
                    'type'        => 'integer',
                    'description' => '匹配行前显示多少行（-B）',
                    'default'     => 0,
                ],
                'after' => [
                    'type'        => 'integer',
                    'description' => '匹配行后显示多少行（-A）',
                    'default'     => 0,
                ],
                'output_mode' => [
                    'type'        => 'string',
                    'description' => 'content=显示匹配行(默认)；files_with_matches=只列文件；count=每文件命中数',
                    'default'     => 'content',
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
        $ignoreCase = !empty($input['ignore_case']);
        $ctx     = isset($input['context']) ? max(0, (int) $input['context']) : 0;
        $before  = $ctx > 0 ? $ctx : (isset($input['before']) ? max(0, (int) $input['before']) : 0);
        $after   = $ctx > 0 ? $ctx : (isset($input['after']) ? max(0, (int) $input['after']) : 0);
        $mode    = isset($input['output_mode']) ? (string) $input['output_mode'] : 'content';
        if (!in_array($mode, ['content', 'files_with_matches', 'count'], true)) {
            $mode = 'content';
        }

        if ($pattern === '') {
            return ToolResult::error('参数 pattern 不能为空');
        }
        if ($limit <= 0) {
            $limit = $this->maxResults;
        }

        $rootDir = rtrim($this->pathSafety->rootDir(), '/') . '/';
        $searchPath = $rootDir . ($path !== '' ? ltrim($path, '/') : '');
        if (!is_dir($searchPath)) {
            return ToolResult::error('搜索路径不存在：' . $path);
        }

        $useRg = $this->engine === 'rg' || ($this->engine === 'auto' && Shell::hasBinary('rg'));

        $opts = [
            'include' => $include,
            'ignoreCase' => $ignoreCase,
            'before' => $before,
            'after'  => $after,
            'mode'   => $mode,
            'limit'  => $limit,
            'root'   => $rootDir,
        ];

        if ($useRg) {
            $hits = $this->runRipgrep($pattern, $searchPath, $opts);
            if ($hits === null) {
                // rg 异常，回退纯 PHP，保证可用
                $hits = $this->runPhp($pattern, $searchPath, $opts);
            }
        } else {
            $hits = $this->runPhp($pattern, $searchPath, $opts);
        }

        return $this->format($pattern, $hits, $opts, $useRg ? 'rg' : 'php');
    }

    /**
     * 解析 pattern：/.../ 视为正则，否则字面
     *
     * @param string $pattern
     * @return array{regex: bool, core: string, flags: string}
     */
    protected function parsePattern($pattern)
    {
        if (strlen($pattern) > 2 && $pattern[0] === '/') {
            $last = strrpos($pattern, '/');
            if ($last > 0) {
                return [
                    'regex' => true,
                    'core'  => substr($pattern, 1, $last - 1),
                    'flags' => substr($pattern, $last + 1),
                ];
            }
        }
        return ['regex' => false, 'core' => $pattern, 'flags' => ''];
    }

    /**
     * 纯 PHP 搜索
     *
     * @param string $pattern
     * @param string $searchPath
     * @param array<string, mixed> $opts
     * @return array<int, array{file: string, line: int, text: string, ctx: bool}>
     */
    protected function runPhp($pattern, $searchPath, array $opts)
    {
        $p = $this->parsePattern($pattern);
        $ignoreCase = !empty($opts['ignoreCase']);
        $before = (int) $opts['before'];
        $after  = (int) $opts['after'];
        $limit  = (int) $opts['limit'];
        $mode   = (string) $opts['mode'];
        $root   = (string) $opts['root'];

        // 构建匹配器
        if ($p['regex']) {
            $flags = $p['flags'];
            if ($ignoreCase && strpos($flags, 'i') === false) {
                $flags .= 'i';
            }
            $re = '/' . str_replace('/', '\\/', $p['core']) . '/' . $flags;
            $matcher = function ($line) use ($re) {
                return @preg_match($re, $line) === 1;
            };
        } else {
            $needle = $p['core'];
            $matcher = $ignoreCase
                ? function ($line) use ($needle) { return stripos($line, $needle) !== false; }
                : function ($line) use ($needle) { return strpos($line, $needle) !== false; };
        }

        $files = $this->collectFiles($searchPath, (string) $opts['include']);
        $hits = [];
        $matchCount = 0;
        $fileCounts = [];   // 非 content 模式：每文件命中数（保序）

        foreach ($files as $abs) {
            if ($mode === 'content' && $matchCount >= $limit) {
                break;
            }
            if ($mode !== 'content' && count($fileCounts) >= $limit && !isset($fileCounts[$this->rel($abs, $root)])) {
                break;
            }
            $rel = $this->rel($abs, $root);
            $lines = @file($abs, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            if (count($lines) > 5000) {
                $lines = array_slice($lines, 0, 5000);
            }
            $emitted = [];  // 该文件已加入的行号（去重上下文）
            foreach ($lines as $i => $line) {
                if (!$matcher($line)) {
                    continue;
                }
                if ($mode === 'content' && $matchCount >= $limit) {
                    break;
                }
                $matchCount++;
                if ($mode !== 'content') {
                    // files/count 模式：按文件聚合命中数
                    $fileCounts[$rel] = isset($fileCounts[$rel]) ? $fileCounts[$rel] + 1 : 1;
                    continue;
                }
                $from = max(0, $i - $before);
                $to   = min(count($lines) - 1, $i + $after);
                for ($j = $from; $j <= $to; $j++) {
                    if (isset($emitted[$j])) {
                        continue;
                    }
                    $emitted[$j] = true;
                    $hits[] = [
                        'file' => $rel,
                        'line' => $j + 1,
                        'text' => $this->clip($lines[$j]),
                        'ctx'  => $j !== $i,
                    ];
                }
            }
        }

        if ($mode !== 'content') {
            foreach ($fileCounts as $file => $cnt) {
                $hits[] = ['file' => $file, 'line' => 0, 'text' => (string) $cnt, 'ctx' => false];
            }
        }
        return $hits;
    }

    /**
     * ripgrep 搜索；rg 不可用/异常返回 null（由调用方回退）
     *
     * @param string $pattern
     * @param string $searchPath
     * @param array<string, mixed> $opts
     * @return array<int, array{file: string, line: int, text: string, ctx: bool}>|null
     */
    protected function runRipgrep($pattern, $searchPath, array $opts)
    {
        $p = $this->parsePattern($pattern);
        $mode = (string) $opts['mode'];
        $limit = (int) $opts['limit'];
        $root = (string) $opts['root'];

        $args = ['rg', '--no-heading', '--color', 'never'];
        if (!$p['regex']) {
            $args[] = '--fixed-strings';
        }
        if (!empty($opts['ignoreCase']) || ($p['regex'] && strpos($p['flags'], 'i') !== false)) {
            $args[] = '--ignore-case';
        }
        if ((string) $opts['include'] !== '') {
            $args[] = '--glob';
            $args[] = (string) $opts['include'];
        }

        if ($mode === 'files_with_matches') {
            $args[] = '--files-with-matches';
        } elseif ($mode === 'count') {
            $args[] = '--count';
        } else {
            $args[] = '--line-number';
            $args[] = '--with-filename';
            if ((int) $opts['before'] > 0) { $args[] = '--before-context'; $args[] = (string) (int) $opts['before']; }
            if ((int) $opts['after'] > 0)  { $args[] = '--after-context';  $args[] = (string) (int) $opts['after']; }
        }
        $args[] = '--';
        $args[] = $p['core'];
        $args[] = $searchPath;

        $cmd = implode(' ', array_map('escapeshellarg', $args));
        $res = Shell::capture($cmd, ['timeout' => 20, 'cwd' => $root]);
        // rg 退出码：0=有匹配，1=无匹配，2+=错误
        if ($res['code'] >= 2) {
            return null;
        }
        return $this->parseRgOutput($res['out'], $mode, $limit, $root);
    }

    /**
     * 解析 rg 输出为统一 hits 结构
     *
     * @param string $out
     * @param string $mode
     * @param int $limit
     * @param string $root
     * @return array<int, array{file: string, line: int, text: string, ctx: bool}>
     */
    protected function parseRgOutput($out, $mode, $limit, $root)
    {
        $hits = [];
        $lines = preg_split('/\r?\n/', rtrim($out));
        if ($lines === false) {
            return $hits;
        }
        $count = 0;
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if ($mode === 'files_with_matches') {
                if ($count >= $limit) { break; }
                $count++;
                $hits[] = ['file' => $this->rel($line, $root), 'line' => 0, 'text' => '', 'ctx' => false];
                continue;
            }
            if ($mode === 'count') {
                // 格式 file:count
                $pos = strrpos($line, ':');
                if ($pos === false) { continue; }
                $hits[] = [
                    'file' => $this->rel(substr($line, 0, $pos), $root),
                    'line' => 0,
                    'text' => substr($line, $pos + 1),
                    'ctx'  => false,
                ];
                continue;
            }
            // content：file:line:text（匹配）或 file-line-text（上下文）
            if ($count >= $limit) { break; }
            $isCtx = false;
            $sep = ':';
            if (!preg_match('/^(.*?):(\d+):(.*)$/s', $line, $m)) {
                if (preg_match('/^(.*?)-(\d+)-(.*)$/s', $line, $m)) {
                    $isCtx = true;
                    $sep = '-';
                } else {
                    continue;
                }
            }
            if (!$isCtx) { $count++; }
            $hits[] = [
                'file' => $this->rel($m[1], $root),
                'line' => (int) $m[2],
                'text' => $this->clip($m[3]),
                'ctx'  => $isCtx,
            ];
        }
        return $hits;
    }

    /**
     * 统一格式化输出
     *
     * @param string $pattern
     * @param array<int, array{file: string, line: int, text: string, ctx: bool}> $hits
     * @param array<string, mixed> $opts
     * @param string $engine
     * @return ToolResult
     */
    protected function format($pattern, array $hits, array $opts, $engine)
    {
        $mode = (string) $opts['mode'];

        if ($mode === 'files_with_matches') {
            $filesSeen = [];
            foreach ($hits as $h) {
                $filesSeen[$h['file']] = true;
            }
            $files = array_keys($filesSeen);
            if (!$files) {
                return ToolResult::success('未找到匹配 "' . $pattern . '" 的文件', ['pattern' => $pattern, 'files' => 0]);
            }
            return new ToolResult([
                'success'  => true,
                'content'  => count($files) . " file(s) matching '{$pattern}':\n" . implode("\n", $files),
                'metadata' => ['pattern' => $pattern, 'files' => count($files), 'engine' => $engine],
                'display'  => "Grep '{$pattern}': " . count($files) . ' files',
            ]);
        }

        if ($mode === 'count') {
            if (!$hits) {
                return ToolResult::success('未找到匹配 "' . $pattern . '"', ['pattern' => $pattern, 'files' => 0]);
            }
            $lines = [];
            $total = 0;
            foreach ($hits as $h) {
                $lines[] = $h['file'] . ':' . $h['text'];
                $total += (int) $h['text'];
            }
            return new ToolResult([
                'success'  => true,
                'content'  => implode("\n", $lines),
                'metadata' => ['pattern' => $pattern, 'files' => count($hits), 'matches' => $total, 'engine' => $engine],
                'display'  => "Grep '{$pattern}': {$total} matches in " . count($hits) . ' files',
            ]);
        }

        // content
        $matchLines = 0;
        $filesSeen = [];
        foreach ($hits as $h) {
            $filesSeen[$h['file']] = true;
            if (!$h['ctx']) {
                $matchLines++;
            }
        }
        if (!$hits) {
            return ToolResult::success('未找到匹配 "' . $pattern . '" 的内容', ['pattern' => $pattern, 'matches' => 0]);
        }
        $out = [];
        foreach ($hits as $h) {
            $sep = $h['ctx'] ? '-' : ':';
            $out[] = $h['file'] . $sep . $h['line'] . $sep . $h['text'];
        }
        $fileCount = count($filesSeen);
        $content = "Found {$matchLines} match(es) in {$fileCount} file(s) for '{$pattern}':\n---\n"
            . implode("\n", $out) . "\n";

        return new ToolResult([
            'success'  => true,
            'content'  => $content,
            'metadata' => [
                'pattern' => $pattern,
                'matches' => $matchLines,
                'files'   => $fileCount,
                'engine'  => $engine,
            ],
            'display'  => "Grep '{$pattern}': {$matchLines} matches in {$fileCount} files",
        ]);
    }

    /**
     * 绝对路径转工作区相对路径
     *
     * @param string $abs
     * @param string $root
     * @return string
     */
    protected function rel($abs, $root)
    {
        $abs = str_replace('\\', '/', $abs);
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        if (strpos($abs, $root) === 0) {
            return substr($abs, strlen($root));
        }
        return $abs;
    }

    /**
     * 行内容截断
     *
     * @param string $line
     * @return string
     */
    protected function clip($line)
    {
        $line = rtrim($line);
        if (mb_strlen($line) > $this->maxLineLength) {
            return mb_substr($line, 0, $this->maxLineLength) . '…';
        }
        return $line;
    }

    /**
     * 递归收集文件（纯 PHP 路）
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
            $relPath = str_replace($dir . '/', '', $path);
            if (strpos($relPath, '.') === 0 || strpos($relPath, '/.') !== false) {
                continue;
            }
            if (strpos($relPath, 'vendor/') === 0 || strpos($relPath, 'node_modules/') === 0) {
                continue;
            }
            if ($include !== '' && !fnmatch($include, $file->getFilename())) {
                continue;
            }
            $files[] = $path;
        }
        return $files;
    }
}
