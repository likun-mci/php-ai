<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;
use Ai\Helpers\Path;
use Ai\Helpers\Text;

/**
 * notebook_edit 工具——按 cell 读写 Jupyter Notebook（.ipynb）
 *
 * 为什么不直接用 read_file / edit_file：.ipynb 是 JSON，直接整份读会把
 * `outputs` 里的 base64 图片、执行计数等噪声全灌进上下文（一个图多的 notebook
 * 轻松几百 KB），而真正有用的只是各 cell 的 source。本工具按 cell 呈现，
 * 输出只给摘要，不回传 base64。
 *
 * action：
 *   read    列出全部 cell（序号 / 类型 / 源码，outputs 只给摘要）
 *   replace 用 source 覆盖第 cell_index 个 cell
 *   insert  在 cell_index 位置插入新 cell
 *   delete  删除第 cell_index 个 cell
 *
 * 写入走 Path::atomicWrite，保留 nbformat 等顶层字段，不重排结构。
 */
class NotebookEditTool implements AgentToolInterface
{
    /** @var PathSafety */
    protected $pathSafety;

    /** @var int 单个 cell 源码回显的最大字节 */
    protected $maxCellBytes;

    /**
     * @param PathSafety $pathSafety
     * @param int $maxCellBytes
     */
    public function __construct(PathSafety $pathSafety, $maxCellBytes = 4000)
    {
        $this->pathSafety = $pathSafety;
        $this->maxCellBytes = max(200, (int) $maxCellBytes);
    }

    public function name()
    {
        return 'notebook_edit';
    }

    public function description()
    {
        return '按 cell 读写 Jupyter Notebook（.ipynb）。action=read 列出各 cell 的源码'
            . '（outputs 只给摘要，不回传 base64）；replace/insert/delete 按 cell_index 改动。'
            . '不要用 read_file/edit_file 直接改 .ipynb——那会把 JSON 噪声灌进上下文且容易改坏结构。';
    }

    public function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'path' => [
                    'type'        => 'string',
                    'description' => '.ipynb 文件路径',
                ],
                'action' => [
                    'type'        => 'string',
                    'description' => 'read（默认）/ replace / insert / delete',
                    'default'     => 'read',
                ],
                'cell_index' => [
                    'type'        => 'integer',
                    'description' => 'cell 序号（从 0 起）；replace/delete 必填，insert 表示插入位置',
                ],
                'source' => [
                    'type'        => 'string',
                    'description' => 'cell 源码，replace / insert 必填',
                ],
                'cell_type' => [
                    'type'        => 'string',
                    'description' => 'code 或 markdown；insert 必填，replace 可选（改变类型）',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $input, ToolContext $context)
    {
        $path   = isset($input['path']) ? (string) $input['path'] : '';
        $action = isset($input['action']) ? (string) $input['action'] : 'read';

        if ($path === '') {
            return ToolResult::error('参数 path 不能为空');
        }
        try {
            $abs = $this->pathSafety->resolve($path);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }
        if (!is_file($abs) || !is_readable($abs)) {
            return ToolResult::error('文件不存在或不可读：' . $path);
        }

        $raw = file_get_contents($abs);
        if ($raw === false) {
            return ToolResult::error('读取失败：' . $path);
        }
        $nb = json_decode($raw, true);
        if (!is_array($nb) || !isset($nb['cells']) || !is_array($nb['cells'])) {
            return ToolResult::error('不是合法的 .ipynb（缺少 cells）：' . $path);
        }
        $cells = array_values($nb['cells']);

        if ($action === 'read') {
            return $this->readCells($path, $cells);
        }

        $index = isset($input['cell_index']) ? (int) $input['cell_index'] : -1;
        $source = isset($input['source']) ? (string) $input['source'] : '';
        $cellType = isset($input['cell_type']) ? (string) $input['cell_type'] : '';

        if ($action === 'delete') {
            if (!isset($cells[$index])) {
                return ToolResult::error('cell_index 越界：' . $index . '（共 ' . count($cells) . ' 个）');
            }
            array_splice($cells, $index, 1);
        } elseif ($action === 'replace') {
            if (!isset($cells[$index])) {
                return ToolResult::error('cell_index 越界：' . $index . '（共 ' . count($cells) . ' 个）');
            }
            if ($source === '') {
                return ToolResult::error('replace 需要 source');
            }
            $cells[$index]['source'] = $this->toSourceLines($source);
            if ($cellType !== '') {
                $cells[$index]['cell_type'] = $cellType;
            }
            // 源码变了，旧输出与执行计数已失效
            if (isset($cells[$index]['cell_type']) && $cells[$index]['cell_type'] === 'code') {
                $cells[$index]['outputs'] = [];
                $cells[$index]['execution_count'] = null;
            }
        } elseif ($action === 'insert') {
            if ($source === '') {
                return ToolResult::error('insert 需要 source');
            }
            if ($cellType === '') {
                return ToolResult::error('insert 需要 cell_type（code 或 markdown）');
            }
            if ($index < 0 || $index > count($cells)) {
                $index = count($cells);
            }
            $new = [
                'cell_type' => $cellType,
                'metadata'  => new \stdClass(),
                'source'    => $this->toSourceLines($source),
            ];
            if ($cellType === 'code') {
                $new['outputs'] = [];
                $new['execution_count'] = null;
            }
            array_splice($cells, $index, 0, [$new]);
        } else {
            return ToolResult::error('未知 action：' . $action . '（read/replace/insert/delete）');
        }

        $nb['cells'] = $cells;
        $json = json_encode($nb, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ToolResult::error('notebook 序列化失败');
        }
        if (!Path::atomicWrite($abs, $json . "\n", 0755)) {
            return ToolResult::error('写入失败：' . $path);
        }

        return ToolResult::success(
            '已' . $this->actionLabel($action) . '：' . $path . ' 第 ' . $index . ' 个 cell（现共 ' . count($cells) . ' 个）',
            ['path' => $path, 'action' => $action, 'cell_index' => $index, 'cells' => count($cells)]
        );
    }

    /**
     * @param string $action
     * @return string
     */
    protected function actionLabel($action)
    {
        $map = ['replace' => '替换', 'insert' => '插入', 'delete' => '删除'];
        return isset($map[$action]) ? $map[$action] : $action;
    }

    /**
     * 列出全部 cell（outputs 只给摘要）
     *
     * @param string $path
     * @param array<int, mixed> $cells
     * @return ToolResult
     */
    protected function readCells($path, array $cells)
    {
        $lines = ['Notebook: ' . $path . '（' . count($cells) . ' 个 cell）', str_repeat('-', 40)];
        foreach ($cells as $i => $cell) {
            $type = is_array($cell) && isset($cell['cell_type']) ? (string) $cell['cell_type'] : '?';
            $src = is_array($cell) && isset($cell['source']) ? $this->fromSource($cell['source']) : '';
            if (strlen($src) > $this->maxCellBytes) {
                $src = Text::cutBytes($src, $this->maxCellBytes) . "\n…(cell 源码已截断)";
            }
            $outNote = '';
            if (is_array($cell) && isset($cell['outputs']) && is_array($cell['outputs']) && $cell['outputs']) {
                $outNote = '  [' . count($cell['outputs']) . ' 个输出，已省略]';
            }
            $lines[] = '[' . $i . '] ' . $type . $outNote;
            $lines[] = $src === '' ? '(空)' : $src;
            $lines[] = '';
        }
        return new ToolResult([
            'success'  => true,
            'content'  => implode("\n", $lines),
            'metadata' => ['path' => $path, 'cells' => count($cells)],
            'display'  => 'Notebook ' . $path . '：' . count($cells) . ' 个 cell',
        ]);
    }

    /**
     * nbformat 的 source 可能是字符串或字符串数组，统一成字符串
     *
     * @param mixed $source
     * @return string
     */
    protected function fromSource($source)
    {
        if (is_array($source)) {
            return implode('', array_map('strval', $source));
        }
        return (string) $source;
    }

    /**
     * 写回时按 nbformat 惯例存成「每行末尾带 \n」的字符串数组（最后一行不带）
     *
     * @param string $source
     * @return string[]
     */
    protected function toSourceLines($source)
    {
        $source = str_replace(["\r\n", "\r"], "\n", (string) $source);
        $parts = explode("\n", $source);
        $out = [];
        $last = count($parts) - 1;
        foreach ($parts as $i => $line) {
            $out[] = $i === $last ? $line : ($line . "\n");
        }
        if ($out === [''] ) {
            return [];
        }
        return $out;
    }
}
