<?php
namespace Ai\Agent;

/**
 * Agent 长期记忆（通用）
 *
 * 把一个 Markdown 文件当作 Agent 的持久记忆：读取后注入对话、按需追加/覆盖。
 * 类似 CLAUDE.md 的记忆机制。文件存放位置由业务层决定——库本身不认识
 * 任何具体路径（与 [[ai-editor-decouple]] 的设计原则一致）。
 *
 *   $mem = new Memory(FCPATH . 'writable/agent/memory.md');
 *   $mem->forPrompt();        // 注入对话的文本（带截断保护，空记忆返回 ''）
 *   $mem->append("用户偏好深色主题"); // AI 按需追加
 *   $mem->write($full);       // 覆盖整份记忆
 */
class Memory
{
    protected $file;
    protected $maxInject;

    /**
     * @param string $file      记忆文件绝对路径
     * @param int    $maxInject 注入对话时的最大字符数（防止 token 膨胀）
     */
    public function __construct($file, $maxInject = 20000)
    {
        $this->file = (string) $file;
        $this->maxInject = (int) $maxInject;
    }

    public function path() { return $this->file; }

    /** 确保目录与文件存在（不存在则创建空文件） */
    public function ensure()
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!is_file($this->file)) @file_put_contents($this->file, '');
        return $this;
    }

    /** 读取原始内容（文件不存在返回空串） */
    public function read()
    {
        return is_file($this->file) ? (string) file_get_contents($this->file) : '';
    }

    /** 注入对话用文本：去除首尾空白并按 maxInject 截断；空记忆返回 '' */
    public function forPrompt()
    {
        $c = trim($this->read());
        if ($c === '') return '';
        if (mb_strlen($c) > $this->maxInject) {
            $c = mb_substr($c, 0, $this->maxInject) . "\n\n…(记忆过长已截断)";
        }
        return $c;
    }

    /** 覆盖整份记忆 */
    public function write($content)
    {
        $this->ensure();
        return @file_put_contents($this->file, (string) $content) !== false;
    }

    /** 追加一段记忆（自动补换行分隔；空内容不写） */
    public function append($content)
    {
        $content = trim((string) $content);
        if ($content === '') return false;
        $existing = $this->read();
        $sep = ($existing === '' || substr($existing, -1) === "\n") ? '' : "\n";
        return $this->write($existing . $sep . $content . "\n");
    }
}
