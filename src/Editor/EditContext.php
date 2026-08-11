<?php
namespace Ai\Editor;

/**
 * AI 文件编辑上下文打包（通用，不含任何业务概念）
 *
 * 收集编辑器状态（文件/光标/选区/打开的标签）并：
 *  - 按文件大小/选区做内容窗口化，避免发送整文件
 *  - 可选注入一个「工作区」（Workspace）：当前文件所属的沙箱项目，
 *    用于支持跨文件操作、文件清单与规范文档注入
 *
 * 是否进入「工作区模式」、工作区里有哪些文件、注入什么规范文档，
 * 全部由业务层构建 Workspace 后通过 setWorkspace() 注入——库本身
 * 不认识「网站模板」「.prompts 文档」等业务细节。
 *
 * 输出 toPromptJson() 供模型理解；getGuidelines() 由协议层注入 system。
 */
class EditContext
{
    const LARGE_BYTES  = 204800;  // 200KB
    const SEL_CONTEXT  = 2000;    // 选区前后各保留字符数
    const CHUNK_HALF   = 5000;    // 大文件光标窗口半径

    /**
     * @var string
     */
    protected $fcpath;

    /**
     * @var string
     */
    protected $file = '';          // 相对 FCPATH，如 app/views/website-template/default/site/index.php
    /**
     * @var string
     */
    protected $language = '';
    /**
     * @var string|null
     */
    protected $content = null;     // 文件全文（可空）
    /**
     * @var array{line?: int, column?: int, offset?: int}|null
     */
    protected $cursor = null;      // ['line','column','offset']
    /**
     * @var array{start_offset?: int, end_offset?: int, start_line?: int, end_line?: int}|null
     */
    protected $selection = null;   // ['start_offset','end_offset','start_line','end_line']
    /**
     * @var string|null
     */
    protected $selectedContent = null;
    /**
     * @var array<int, array{file_name?: string, file_path?: string}>
     */
    protected $openedFiles = [];   // [['file_name','file_path'], ...]

    /** @var Workspace|null 业务层注入的工作区，null=无（仅当前文件可改） */
    protected $workspace = null;

    /**
     * @param string $fcpath 项目根目录绝对路径
     */
    public function __construct($fcpath)
    {
        $this->fcpath = rtrim((string) $fcpath, "/\\") . '/';
    }

    /**
     * @param string $relativePath
     * @return $this
     */
    public function setFile($relativePath)
    {
        $this->file = ltrim(str_replace('\\', '/', (string) $relativePath), '/');
        return $this;
    }

    /**
     * @param string $lang
     * @return $this
     */
    public function setLanguage($lang)
    {
        $this->language = (string) $lang;
        return $this;
    }

    /**
     * @param string|null $content
     * @return $this
     */
    public function setContent($content)
    {
        // 统一换行为 \n：模型据此产出 \n 的 old_string，避免与 \r\n 文件匹配失败
        $this->content = $content === null ? null : str_replace(["\r\n", "\r"], "\n", (string) $content);
        return $this;
    }

    /**
     * @param array{line?: int, column?: int, offset?: int}|null $cursor
     * @return $this
     */
    public function setCursor($cursor)
    {
        $this->cursor = is_array($cursor) ? $cursor : null;
        return $this;
    }

    /**
     * @param array{start_offset?: int, end_offset?: int, start_line?: int, end_line?: int}|null $selection
     * @param string|null $selectedContent
     * @return $this
     */
    public function setSelection($selection, $selectedContent = null)
    {
        $this->selection = (is_array($selection) && isset($selection['start_offset'], $selection['end_offset'])
            && $selection['end_offset'] > $selection['start_offset']) ? $selection : null;
        $this->selectedContent = ($selectedContent !== null && $selectedContent !== '') ? (string) $selectedContent : null;
        return $this;
    }

    /**
     * @param array<mixed> $files
     * @return $this
     */
    public function setOpenedFiles(array $files)
    {
        $this->openedFiles = [];
        foreach ($files as $f) {
            if (is_array($f) && !empty($f['file_path'])) {
                $this->openedFiles[] = [
                    'file_name' => isset($f['file_name']) ? (string) $f['file_name'] : basename($f['file_path']),
                    'file_path' => (string) $f['file_path'],
                ];
            }
        }
        return $this;
    }

    /* ---------- 工作区（由业务层注入） ---------- */

    /**
     * 注入工作区（当前文件所属的可跨文件编辑沙箱）。
     * 传 null 表示无工作区：模型只能操作当前文件。
     * @param \Ai\Editor\Workspace|null $workspace
     * @return $this
     */
    public function setWorkspace($workspace)
    {
        $this->workspace = ($workspace instanceof Workspace) ? $workspace : null;
        return $this;
    }

    /**
     * @return bool
     */
    public function hasWorkspace() { return $this->workspace !== null; }
    /**
     * @return \Ai\Editor\Workspace|null
     */
    public function getWorkspace() { return $this->workspace; }
    /**
     * @return string
     */
    public function getGuidelines() { return $this->workspace ? $this->workspace->getGuidelines() : ''; }

    /* ---------- 内容窗口化 ---------- */

    /**
     * @return array<string, mixed>|null 内容为空时返回 null
     */
    protected function buildFileView()
    {
        if ($this->content === null) return null;
        $total = mb_strlen($this->content);
        $bytes = strlen($this->content);

        // 有选区：仅给选区前后各 SEL_CONTEXT 字符
        if ($this->selection) {
            $s = max(0, (int) $this->selection['start_offset'] - self::SEL_CONTEXT);
            $e = min($total, (int) $this->selection['end_offset'] + self::SEL_CONTEXT);
            return [
                'mode' => 'selection_window',
                'total_chars' => $total,
                'window_start_offset' => $s,
                'content' => mb_substr($this->content, $s, $e - $s),
            ];
        }

        // 小文件：全文
        if ($bytes <= self::LARGE_BYTES) {
            return ['mode' => 'full', 'total_chars' => $total, 'content' => $this->content];
        }

        // 大文件无选区：光标附近窗口
        $cur = ($this->cursor && isset($this->cursor['offset'])) ? (int) $this->cursor['offset'] : 0;
        $s = max(0, $cur - self::CHUNK_HALF);
        $e = min($total, $cur + self::CHUNK_HALF);
        return [
            'mode' => 'chunk',
            'total_chars' => $total,
            'window_start_offset' => $s,
            'content' => mb_substr($this->content, $s, $e - $s),
        ];
    }

    /* ---------- 输出 ---------- */

    /**
     * @return array<string, mixed> 供注入提示词的上下文结构
     */
    public function toPromptJson()
    {
        $ext = strtolower(pathinfo($this->file, PATHINFO_EXTENSION));
        $hasWs = $this->hasWorkspace();
        // 有工作区：给相对工作区路径；否则给相对 FCPATH 路径
        $displayPath = $hasWs ? $this->workspace->getRelPath() : $this->file;

        $ctx = [
            'current_file' => [
                'file_name' => basename($this->file),
                'file_path' => $displayPath,
                'file_ext'  => $ext,
                'language'  => $this->language ?: $ext,
            ],
            'opened_files'   => $this->openedFiles,
            'cursor'         => $this->cursor,
            'selection'      => $this->selection,
            'selected_content' => $this->selectedContent,
            'file_view'      => $this->buildFileView(),
            'workspace_mode' => $hasWs,
        ];
        if ($hasWs) {
            $ctx['workspace_name']  = $this->workspace->getName();
            $ctx['workspace_files'] = $this->workspace->getFiles();
        }
        return $ctx;
    }
}
