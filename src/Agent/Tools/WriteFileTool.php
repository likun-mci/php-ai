<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;

/**
 * 文件写入工具
 *
 * 允许 Agent 在工作区内创建新文件或覆盖已有文件。
 * 路径受 PathSafety 限制，无法逃逸沙箱。
 *
 * 注意：为避免 AI 意外覆盖，write_file 不适用于局部修改——局部修改请用 edit_file。
 */
class WriteFileTool implements AgentToolInterface
{
    /** @var PathSafety */
    protected $pathSafety;

    /**
     * @param PathSafety $pathSafety
     */
    public function __construct(PathSafety $pathSafety)
    {
        $this->pathSafety = $pathSafety;
    }

    public function name()
    {
        return 'write_file';
    }

    public function description()
    {
        return '创建新文件或覆盖已有文件。写入前会创建缺失的目录。'
            . '对于局部修改请使用 edit_file 而不是 write_file。';
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
                'content' => [
                    'type'        => 'string',
                    'description' => '文件内容',
                ],
            ],
            'required' => ['path', 'content'],
        ];
    }

    public function execute(array $input, ToolContext $context)
    {
        $path    = isset($input['path']) ? (string) $input['path'] : '';
        $content = isset($input['content']) ? (string) $input['content'] : '';

        if ($path === '') {
            return ToolResult::error('参数 path 不能为空');
        }

        try {
            $absPath = $this->pathSafety->resolve($path);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }

        // 创建父目录
        $dir = dirname($absPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ToolResult::error('无法创建目录：' . $dir);
        }

        $bytes = @file_put_contents($absPath, $content);
        if ($bytes === false) {
            return ToolResult::error('写入文件失败：' . $path);
        }

        return ToolResult::success('文件已写入：' . $path . ' (' . $bytes . ' bytes)', [
            'path'  => $path,
            'bytes' => $bytes,
        ]);
    }
}