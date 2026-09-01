<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;

/**
 * 文件读取工具
 *
 * 允许 Agent 读取工作区内的文件，支持 offset（行偏移）与 limit（行数限制）。
 * 大文件自动截断，防止模型被过量 token 冲垮。
 *
 * 用法：
 * ```php
 * // 读小文件
 * read_file(path: "src/User.php")
 *
 * // 读大文件的特定行
 * read_file(path: "src/User.php", offset: 100, limit: 50)
 * ```
 */
class ReadFileTool implements AgentToolInterface
{
    /** @var PathSafety */
    protected $pathSafety;

    /** @var int 单次读取最大字节数（超出截断） */
    protected $maxBytes = 100000;

    /**
     * @param PathSafety $pathSafety
     * @param int $maxBytes 单次读取最大字节数
     */
    public function __construct(PathSafety $pathSafety, $maxBytes = 100000)
    {
        $this->pathSafety = $pathSafety;
        $this->maxBytes = max(1024, (int) $maxBytes);
    }

    public function name()
    {
        return 'read_file';
    }

    public function description()
    {
        return '读取工作区内的文件内容。支持 offset（从第几行开始，从 1 计）和 limit（最多返回多少行）。'
            . '大文件会自动截断，截断部分会提示用 offset/limit 继续读取。';
    }

    public function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'path' => [
                    'type'        => 'string',
                    'description' => '文件路径（相对工作区或绝对路径）',
                ],
                'offset' => [
                    'type'        => 'integer',
                    'description' => '起始行号（从 1 开始），默认 1',
                    'default'     => 1,
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => '最多返回的行数，默认不限制',
                    'default'     => null,
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $input, ToolContext $context)
    {
        $path   = isset($input['path']) ? (string) $input['path'] : '';
        $offset = isset($input['offset']) ? (int) $input['offset'] : 1;
        $limit  = isset($input['limit']) ? (int) $input['limit'] : 0;

        if ($path === '') {
            return ToolResult::error('参数 path 不能为空');
        }

        try {
            $absPath = $this->pathSafety->resolve($path);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }

        if (!is_file($absPath) || !is_readable($absPath)) {
            return ToolResult::error('文件不存在或不可读：' . $path);
        }

        $filesize = filesize($absPath);
        if ($filesize === false) {
            return ToolResult::error('无法获取文件大小：' . $path);
        }

        // 读取文件
        $content = file_get_contents($absPath);
        if ($content === false) {
            return ToolResult::error('读取文件失败：' . $path);
        }

        $lines = explode("\n", $content);
        $totalLines = count($lines);

        // 截断到指定行范围
        if ($offset > 1 || $limit > 0) {
            $start = max(0, $offset - 1);
            $lines = array_slice($lines, $start, $limit > 0 ? $limit : null);
            $result = implode("\n", $lines);
        } else {
            $result = $content;
        }

        $resultBytes = strlen($result);
        $isPartial = false;

        // 超出最大字节数的截断
        if ($resultBytes > $this->maxBytes) {
            $result = mb_substr($result, 0, $this->maxBytes)
                . "\n\n[Output truncated at {$this->maxBytes} bytes. "
                . "Use read_file with offset/limit to continue reading.]";
            $isPartial = true;
        }

        $metadata = [
            'path'       => $path,
            'size'       => $filesize,
            'lines'      => $totalLines,
            'returned_lines' => count($lines),
            'truncated'  => $isPartial,
        ];

        // 在文件头加一行元信息（对模型友好）
        $header = "File: {$path} ({$totalLines} lines, {$filesize} bytes)\n";
        if ($offset > 1 || $limit > 0) {
            $header = "File: {$path} (lines {$offset}-" . ($offset + count($lines) - 1) . " of {$totalLines}, {$filesize} bytes)\n";
        }
        $result = $header . str_repeat('-', 40) . "\n" . $result;

        return new ToolResult([
            'success'    => true,
            'content'    => $result,
            'metadata'   => $metadata,
            'is_partial' => $isPartial,
            'display'    => "Read {$path} (" . ($isPartial ? 'partial, ' : '') . "{$metadata['returned_lines']} lines)",
        ]);
    }
}