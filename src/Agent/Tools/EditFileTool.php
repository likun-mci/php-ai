<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;

/**
 * 文件编辑工具——精确替换
 *
 * 对已存在文件做局部替换，而不是覆盖整个文件。
 * 采用 str_replace 语义：模型给出 old_string（被替换的原文片段）和 new_string（替换后的内容），
 * 工具在原文件里做精确匹配。这样避免了 AI 重写整文件时可能引入的副作用。
 *
 * 执行流程：
 * 1. 读取原文件
 * 2. 匹配 old_string（必须唯一）
 * 3. 替换为 new_string
 * 4. 写回文件
 * 5. 返回 diff 摘要
 */
class EditFileTool implements AgentToolInterface
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
        return 'edit_file';
    }

    public function description()
    {
        return '对文件做精确局部替换。用 old_string 定位原文片段，用 new_string 替换。'
            . 'old_string 必须与文件现有内容完全一致且唯一。'
            . '适合修补 bug、修改配置等局部改动。'
            . '新建文件请用 write_file，删除文件请用 bash rm。';
    }

    public function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'path' => [
                    'type'        => 'string',
                    'description' => '文件路径',
                ],
                'old_string' => [
                    'type'        => 'string',
                    'description' => '被替换的原文片段（必须逐字一致且唯一，含缩进）',
                ],
                'new_string' => [
                    'type'        => 'string',
                    'description' => '替换后的内容',
                ],
                'replace_all' => [
                    'type'        => 'boolean',
                    'description' => '如果 old_string 出现多次是否全部替换',
                    'default'     => false,
                ],
            ],
            'required' => ['path', 'old_string', 'new_string'],
        ];
    }

    public function execute(array $input, ToolContext $context)
    {
        $path       = isset($input['path']) ? (string) $input['path'] : '';
        $oldString  = isset($input['old_string']) ? (string) $input['old_string'] : '';
        $newString  = isset($input['new_string']) ? (string) $input['new_string'] : '';
        $replaceAll = !empty($input['replace_all']);

        if ($path === '') {
            return ToolResult::error('参数 path 不能为空');
        }
        if ($oldString === '') {
            return ToolResult::error('参数 old_string 不能为空');
        }

        try {
            $absPath = $this->pathSafety->resolve($path);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }

        if (!is_file($absPath) || !is_writable($absPath)) {
            return ToolResult::error('文件不存在或不可写：' . $path);
        }

        $content = file_get_contents($absPath);
        if ($content === false) {
            return ToolResult::error('读取文件失败：' . $path);
        }

        // 统一换行符（\r\n → \n），避免匹配失败
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $oldNorm    = str_replace(["\r\n", "\r"], "\n", $oldString);

        $count = substr_count($normalized, $oldNorm);
        if ($count === 0) {
            return ToolResult::error('old_string 未在文件中找到，无法定位。请确认原文内容（含缩进和换行）完全一致。');
        }
        if ($count > 1 && !$replaceAll) {
            return ToolResult::error("old_string 匹配到 {$count} 处，不唯一。请补充更多上下文或设置 replace_all=true。");
        }

        $result = $replaceAll
            ? str_replace($oldNorm, $newString, $normalized)
            : $this->strReplaceFirst($normalized, $oldNorm, $newString);

        $bytes = file_put_contents($absPath, $result);
        if ($bytes === false) {
            return ToolResult::error('写入文件失败：' . $path);
        }

        // 生成 diff 摘要
        $oldLines = explode("\n", $oldNorm);
        $newLines = explode("\n", $newString);

        return ToolResult::success('已编辑文件：' . $path, [
            'path'       => $path,
            'replaced'   => $count,
            'old_lines'  => count($oldLines),
            'new_lines'  => count($newLines),
            'diff'       => '替换 ' . $count . ' 处，-' . count($oldLines) . ' +' . count($newLines) . ' 行',
        ]);
    }

    /**
     * @param string $haystack
     * @param string $needle
     * @param string $replace
     * @return string
     */
    protected function strReplaceFirst($haystack, $needle, $replace)
    {
        $pos = strpos($haystack, $needle);
        if ($pos === false) {
            return $haystack;
        }
        return substr_replace($haystack, $replace, $pos, strlen($needle));
    }
}