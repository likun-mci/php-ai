<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ParallelSafeToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;
use Ai\Helpers\Text;

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
class ReadFileTool implements AgentToolInterface, ParallelSafeToolInterface
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

    public function isParallelSafe()
    {
        return true;
    }

    public function description()
    {
        // 必须点明能读图片：只写「读取文件内容」时，模型会自己推断这是个纯文本工具
        // 而拒绝对 .png 调用它——实测里模型原话是「read_file 用于读取文本文件，
        // 无法处理图像文件」。链路通不通是一回事，模型肯不肯调是另一回事
        return '读取工作区内的文件内容。文本文件返回正文，支持 offset（从第几行开始，从 1 计）'
            . '和 limit（最多返回多少行），大文件会自动截断并提示用 offset/limit 继续读取。'
            . '**图片（png/jpg/gif/webp/bmp）与 PDF 也可以读**：它们会作为附件加入本轮对话，'
            . '如果当前模型支持视觉输入，你就能直接看到图片内容并回答关于它的问题。';
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

        // 二进制文件（图片/PDF/压缩包等）绝不能把原始字节灌进上下文：
        // 非法 UTF-8 会让下一次请求的 json_encode() 直接失败，整个 Agent 运行中断。
        // 这里给出结构化说明，让模型换用别的手段（如让应用层以附件形式传给多模态模型）。
        if ($this->isBinary($content)) {
            return $this->binaryResult($path, $absPath, $content, (int) $filesize, $context);
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
            $result = Text::cutBytes($result, $this->maxBytes)
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
    /**
     * 是否二进制内容——含 NUL 字节或不是合法 UTF-8
     *
     * @param string $content
     * @return bool
     */
    protected function isBinary($content)
    {
        if ($content === '') {
            return false;
        }
        // 只看前 8KB 足够判定，避免大文件全量扫描
        $head = substr($content, 0, 8192);
        if (strpos($head, "\0") !== false) {
            return true;
        }
        return !mb_check_encoding($head, 'UTF-8');
    }

    /**
     * 二进制文件的结构化说明（不含原始字节）
     *
     * @param string $path 相对路径
     * @param string $absPath 绝对路径
     * @param string $content 原始内容（仅用于探测，不回传）
     * @param int $filesize
     * @param ToolContext|null $context 有媒体门面时图片会被落库并作为引用交出去
     * @return ToolResult
     */
    protected function binaryResult($path, $absPath, $content, $filesize, $context = null)
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $kind = '二进制文件';
        $hint = '本工具只读文本；如需处理请改用 bash（如 file/strings/pdftotext）等外部手段。';
        $meta = [
            'path'      => $path,
            'size'      => $filesize,
            'binary'    => true,
            'extension' => $ext,
        ];

        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg', 'ico'], true)
            || strpos($content, "\x89PNG") === 0 || strpos($content, "\xFF\xD8\xFF") === 0) {
            $kind = '图片';
            $hint = '工具结果无法携带图像（各平台 tool_result 不统一支持）；'
                . '需要视觉理解请由应用层把该文件作为附件传给多模态模型。';
            $isImage = true;
            if (function_exists('getimagesize')) {
                $size = @getimagesize($absPath);
                if (is_array($size)) {
                    // getimagesize 不校验损坏/截断的图片，会把 IHDR 之后的字节当宽高读出来
                    // （实测残缺 PNG 得到 1634892064×1280264009 这种垃圾值）。
                    // 给模型报这种数字是误导，超出常理就干脆不报。
                    $w = (int) $size[0];
                    $h = (int) $size[1];
                    if ($w > 0 && $h > 0 && $w <= 100000 && $h <= 100000) {
                        $meta['width'] = $w;
                        $meta['height'] = $h;
                    }
                    $meta['mime'] = (string) $size['mime'];
                }
            }
        } elseif ($ext === 'pdf' || strpos($content, '%PDF-') === 0) {
            $kind = 'PDF';
            $meta['mime'] = 'application/pdf';
            $hint = '需要正文请用 bash 调 pdftotext 等工具转成文本后再读。';
        }

        $dim = isset($meta['width']) ? ('，' . $meta['width'] . '×' . $meta['height']) : '';

        // 图片/PDF：交给媒体门面落库，把**引用**放进结果。
        //
        // 工具只负责说「我发现了一张图，引用在这里」，不决定它以什么协议格式
        // 进入对话——那是 Runtime 与协议层的事（设计文档 §16）。所以这里既不
        // 拼 image_url 也不拼 Anthropic 的 source 块，ReadFileTool 完全不需要
        // 知道当前跑的是哪家协议。
        $media = [];
        $mediaNote = '';
        $wantsMedia = !empty($isImage) || (isset($meta['mime']) && $meta['mime'] === 'application/pdf');
        if ($wantsMedia && $context instanceof ToolContext && $context->mediaManager() !== null) {
            try {
                $media = $context->mediaManager()->ingest([
                    ['data' => $content, 'mime' => isset($meta['mime']) ? $meta['mime'] : '', 'name' => basename($path)],
                ]);
                $mediaNote = "\n该文件已作为媒体附加到本轮上下文；若当前模型支持视觉输入，你可以直接查看它。";
            } catch (\Ai\Agent\Media\MediaException $e) {
                // 超限或类型不支持：如实说明，不静默吞掉——模型需要知道
                // 「这里有张图但没能给你」，否则它会以为自己看过了
                $mediaNote = "\n（未能附加该文件：" . $e->getMessage() . '）';
            }
        }

        $result = new ToolResult([
            'success'  => true,
            'content'  => $kind . '：' . $path . '（' . $filesize . ' 字节' . $dim . '）' . "\n"
                . ($media === [] ? $hint : '已附加，见下方内容。') . $mediaNote,
            'metadata' => $meta,
            'display'  => 'Read（' . $kind . '）: ' . $path,
            'media'    => $media,
        ]);
        return $result;
    }
}
