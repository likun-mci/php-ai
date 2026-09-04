<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;
use Ai\Helpers\Path;

/**
 * 文件编辑工具——精确替换（支持批量原子编辑 MultiEdit）
 *
 * 对已存在文件做局部替换，而不是覆盖整个文件。
 * 采用 str_replace 语义：模型给出 old_string（被替换的原文片段）和 new_string（替换后的内容），
 * 工具在原文件里做精确匹配。这样避免了 AI 重写整文件时可能引入的副作用。
 *
 * 两种用法（见 dev.md v2.1 §1.2）：
 * - 单次：old_string / new_string / replace_all
 * - 批量：edits: [{old_string, new_string, replace_all?}, ...]，**按顺序应用、全部成功才落盘**，
 *   任一失败整体不写（原子），并报第几条失败。第 N 条在第 N-1 条的结果上匹配。
 *
 * 执行流程：读原文 → 逐条匹配替换（内存）→ 全成功后 atomicWrite 一次落盘 → 返回 diff 摘要。
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
            . '一次改多处用 edits 数组（按顺序应用、全部成功才落盘，任一失败整体不写）。'
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
                    'description' => '被替换的原文片段（必须逐字一致且唯一，含缩进）。批量编辑时用 edits 代替。',
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
                'edits' => [
                    'type'        => 'array',
                    'description' => '批量编辑：[{old_string, new_string, replace_all?}, ...]，按顺序应用、原子落盘。给了 edits 就忽略顶层 old_string/new_string。',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'old_string'  => ['type' => 'string'],
                            'new_string'  => ['type' => 'string'],
                            'replace_all' => ['type' => 'boolean', 'default' => false],
                        ],
                        'required'   => ['old_string', 'new_string'],
                    ],
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $input, ToolContext $context)
    {
        $path = isset($input['path']) ? (string) $input['path'] : '';
        if ($path === '') {
            return ToolResult::error('参数 path 不能为空');
        }

        // 规整编辑列表：优先 edits 数组，否则退回单次 old_string/new_string
        $edits = [];
        if (isset($input['edits']) && is_array($input['edits']) && $input['edits']) {
            foreach ($input['edits'] as $e) {
                if (!is_array($e)) {
                    return ToolResult::error('edits 每一项必须是对象');
                }
                $edits[] = [
                    'old' => isset($e['old_string']) ? (string) $e['old_string'] : '',
                    'new' => isset($e['new_string']) ? (string) $e['new_string'] : '',
                    'all' => !empty($e['replace_all']),
                ];
            }
        } else {
            $edits[] = [
                'old' => isset($input['old_string']) ? (string) $input['old_string'] : '',
                'new' => isset($input['new_string']) ? (string) $input['new_string'] : '',
                'all' => !empty($input['replace_all']),
            ];
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
        $working = str_replace(["\r\n", "\r"], "\n", $content);

        $isMulti = count($edits) > 1 || (isset($input['edits']) && is_array($input['edits']) && $input['edits']);
        $totalReplaced = 0;
        $oldLines = 0;
        $newLines = 0;
        // 逐条在内存里应用；任一失败直接返回错误，绝不落盘（原子）
        foreach ($edits as $i => $edit) {
            $label = $isMulti ? ('edits[' . $i . '] ') : '';
            $res = $this->applyOneEdit($working, $edit['old'], $edit['new'], $edit['all'], $label);
            if ($res['error'] !== '') {
                return ToolResult::error($res['error']);
            }
            $working = $res['content'];
            $totalReplaced += $res['replaced'];
            $oldLines += count(explode("\n", str_replace(["\r\n", "\r"], "\n", $edit['old'])));
            $newLines += count(explode("\n", $edit['new']));
        }

        // 全部成功，一次性原子落盘
        if (!Path::atomicWrite($absPath, $working)) {
            return ToolResult::error('写入文件失败：' . $path);
        }

        $editCount = count($edits);
        return ToolResult::success('已编辑文件：' . $path . '（' . $editCount . ' 处编辑，替换 ' . $totalReplaced . ' 次）', [
            'path'      => $path,
            'edits'     => $editCount,
            'replaced'  => $totalReplaced,
            'old_lines' => $oldLines,
            'new_lines' => $newLines,
            'diff'      => $editCount . ' 处编辑，替换 ' . $totalReplaced . ' 次，-' . $oldLines . ' +' . $newLines . ' 行',
        ]);
    }

    /**
     * 在内存字符串上应用一条编辑；成功返回 [content, replaced]，失败返回 [error]
     *
     * @param string $content 当前内容（已规整换行）
     * @param string $oldString
     * @param string $newString
     * @param bool $replaceAll
     * @param string $label 报错前缀（批量时形如 "edits[2] "，单次为空）
     * @return array{content: string, replaced: int, error: string}
     */
    protected function applyOneEdit($content, $oldString, $newString, $replaceAll, $label = '')
    {
        $label = (string) $label;
        $oldNorm = str_replace(["\r\n", "\r"], "\n", (string) $oldString);
        if ($oldNorm === '') {
            return ['content' => '', 'replaced' => 0, 'error' => $label . 'old_string 不能为空'];
        }
        $count = substr_count($content, $oldNorm);
        if ($count === 0) {
            return ['content' => '', 'replaced' => 0, 'error' => $label . 'old_string 未在文件中找到，无法定位。请确认原文内容（含缩进和换行）完全一致。'];
        }
        if ($count > 1 && !$replaceAll) {
            return ['content' => '', 'replaced' => 0, 'error' => $label . 'old_string 匹配到 ' . $count . ' 处，不唯一。请补充更多上下文或设置 replace_all=true。'];
        }
        $result = $replaceAll
            ? str_replace($oldNorm, (string) $newString, $content)
            : $this->strReplaceFirst($content, $oldNorm, (string) $newString);
        return ['content' => $result, 'replaced' => $count, 'error' => ''];
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