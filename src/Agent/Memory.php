<?php
namespace Ai\Agent;

/**
 * Agent 长期记忆（通用）
 *
 * 把一个 Markdown 文件当作 Agent 的持久记忆：读取后注入对话、按需追加/覆盖。
 *
 * 文件操作一律用 @ 抑制 warning 并显式检查返回值——这是「失败在预期内、由代码
 * 自己处理」的正确写法。但记忆写不进去等于丢数据，所以每个失败分支都会把
 * 真实原因写进 Ai\Helpers\Log，不做静默吞掉。
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
    /**
     * @var string
     */
    protected $file;
    /**
     * @var int
     */
    protected $maxInject;

    /**
     * @param mixed $file 记忆文件绝对路径
     * @param int $maxInject 注入对话时的最大字符数（防止 token 膨胀）
     */
    public function __construct($file, $maxInject = 20000)
    {
        $this->file = (string) $file;
        $this->maxInject = (int) $maxInject;
    }

    /**
     * @return string
     */
    public function path() { return $this->file; }

    /** 确保目录与文件存在（不存在则创建空文件）
     * @return $this
     */
    public function ensure()
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            // 再判一次 is_dir 是因为并发下可能已被别的进程建好，那不算失败
            \Ai\Helpers\Log::error('记忆目录创建失败', [
                'dir' => $dir, 'reason' => $this->lastError(),
            ]);
        }
        if (!is_file($this->file) && @file_put_contents($this->file, '') === false) {
            \Ai\Helpers\Log::error('记忆文件创建失败', [
                'file' => $this->file, 'reason' => $this->lastError(),
            ]);
        }
        return $this;
    }

    /** 取最近一次 PHP 文件操作的错误描述，用于把失败原因写进日志
     * @return string
     */
    protected function lastError()
    {
        $err = error_get_last();
        return isset($err['message']) ? $err['message'] : '未知原因';
    }

    /** 读取原始内容（文件不存在返回空串）
     * @return string
     */
    public function read()
    {
        return is_file($this->file) ? (string) file_get_contents($this->file) : '';
    }

    /** 注入对话用文本：去除首尾空白并按 maxInject 截断；空记忆返回 ''
     * @return string
     */
    public function forPrompt()
    {
        $c = trim($this->read());
        if ($c === '') return '';
        if (mb_strlen($c) > $this->maxInject) {
            $c = mb_substr($c, 0, $this->maxInject) . "\n\n…(记忆过长已截断)";
        }
        return $c;
    }

    /**
     * 覆盖整份记忆（原子写）
     *
     * 先写同目录临时文件再 rename：rename 在同一文件系统内是原子操作，
     * 因此不会出现「写到一半进程被杀 → 记忆被截断」的情况。
     * 直接 file_put_contents 到目标文件则会。
     * @param mixed $content
     * @return bool
     */
    public function write($content)
    {
        $this->ensure();
        $content = (string) $content;

        $tmp = $this->file . '.tmp.' . getmypid() . '.' . mt_rand(1000, 9999);
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            // 临时文件写不了（目录只读等），退回直接写，至少不丢功能
            \Ai\Helpers\Log::warning('记忆临时文件写入失败，退回直接覆盖写', [
                'tmp' => $tmp, 'reason' => $this->lastError(),
            ]);
            $ok = @file_put_contents($this->file, $content, LOCK_EX) !== false;
            if (!$ok) {
                \Ai\Helpers\Log::error('记忆写入失败', [
                    'file' => $this->file, 'reason' => $this->lastError(),
                ]);
            }
            return $ok;
        }
        // 尽量沿用原文件权限，避免 rename 后权限变成 umask 默认值
        if (is_file($this->file)) {
            $perms = @fileperms($this->file);
            if ($perms !== false) {
                @chmod($tmp, $perms & 0777);
            }
        }
        if (!@rename($tmp, $this->file)) {
            \Ai\Helpers\Log::warning('记忆文件 rename 失败，退回直接覆盖写', [
                'file' => $this->file, 'reason' => $this->lastError(),
            ]);
            @unlink($tmp);
            $ok = @file_put_contents($this->file, $content, LOCK_EX) !== false;
            if (!$ok) {
                \Ai\Helpers\Log::error('记忆写入失败', [
                    'file' => $this->file, 'reason' => $this->lastError(),
                ]);
            }
            return $ok;
        }
        return true;
    }

    /**
     * 追加一段记忆（自动补换行分隔；空内容不写）
     *
     * 原实现是「读全文 → 拼接 → 整体写回」，两个进程同时 append 时后写的会
     * 覆盖掉先写的，直接丢数据。改为用 'a' 模式 + LOCK_EX 独占追加：
     * 追加本身就是幂等的尾部写入，不需要先读全文，也就不存在竞态。
     * @param mixed $content
     * @return bool
     */
    public function append($content)
    {
        $content = trim((string) $content);
        if ($content === '') return false;

        $this->ensure();
        $fp = @fopen($this->file, 'a');
        if ($fp === false) {
            \Ai\Helpers\Log::error('记忆文件无法打开，本次追加丢失', [
                'file' => $this->file, 'reason' => $this->lastError(),
            ]);
            return false;
        }
        // 阻塞直到拿到独占锁，避免并发交错
        if (!@flock($fp, LOCK_EX)) {
            \Ai\Helpers\Log::error('记忆文件加锁失败，本次追加丢失', [
                'file' => $this->file, 'reason' => $this->lastError(),
            ]);
            @fclose($fp);
            return false;
        }

        // 拿到锁之后再判断结尾是否需要补换行——此时文件状态才是确定的
        $sep  = '';
        $size = @filesize($this->file);
        if ($size !== false && $size > 0) {
            $fh = @fopen($this->file, 'r');
            if ($fh !== false) {
                @fseek($fh, -1, SEEK_END);
                $lastChar = @fread($fh, 1);
                @fclose($fh);
                if ($lastChar !== "\n") {
                    $sep = "\n";
                }
            }
        }

        $ok = @fwrite($fp, $sep . $content . "\n") !== false;
        if (!$ok) {
            \Ai\Helpers\Log::error('记忆追加写入失败', [
                'file' => $this->file, 'reason' => $this->lastError(),
            ]);
        }
        @fflush($fp);
        @flock($fp, LOCK_UN);
        @fclose($fp);
        return $ok;
    }
}
