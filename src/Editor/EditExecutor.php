<?php
namespace Ai\Editor;

/**
 * 编辑执行器（可复用「工具」层）
 *
 * 模型只返回 JSON 协议，由本类负责把动作落到文件内容/磁盘：
 *  - 路径安全：target_file 仅相对沙箱根，拼接后 realpath 必须落在沙箱根内，拒绝穿越
 *  - 内容变换：str_replace / replace_range / replace_file / create_file / delete_file
 *
 * 说明：
 *  - replace_selection / insert_before_cursor / insert_after_cursor 依赖编辑器选区/光标，
 *    只适用于「当前打开的文件」，由前端 Monaco 应用，不在本类磁盘执行范围内。
 *  - 写盘动作建议由调用方在写入前做备份（复用站点既有备份机制）。
 */
class EditExecutor
{
    protected $rootDir;

    public function __construct($rootDir)
    {
        $this->rootDir = rtrim(str_replace('\\', '/', (string) $rootDir), '/') . '/';
    }

    /**
     * 解析 target_file（相对沙箱根的路径）为绝对路径，并强制限制在沙箱根内
     * @return string 绝对路径
     * @throws \InvalidArgumentException 非法/越界路径
     */
    public function resolveAbsolute($targetFile)
    {
        $rel = ltrim(str_replace('\\', '/', (string) $targetFile), '/');
        if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
            throw new \InvalidArgumentException('非法的目标文件路径');
        }
        $abs = $this->rootDir . $rel;

        // 目录可能尚不存在（create_file），用已存在的父级做边界校验
        $rootReal = realpath($this->rootDir);
        if ($rootReal === false) {
            throw new \InvalidArgumentException('沙箱根目录不存在');
        }
        $rootReal = str_replace('\\', '/', $rootReal);

        $checkDir = dirname($abs);
        $depthGuard = 0;
        while ($checkDir && !is_dir($checkDir) && $depthGuard++ < 64) {
            $checkDir = dirname($checkDir);
        }
        $dirReal = realpath($checkDir);
        if ($dirReal === false) {
            throw new \InvalidArgumentException('目标路径无效');
        }
        $dirReal = str_replace('\\', '/', $dirReal);
        if (strpos($dirReal . '/', $rootReal . '/') !== 0) {
            throw new \InvalidArgumentException('目标文件超出沙箱目录范围');
        }
        return $abs;
    }

    /**
     * 计算某动作作用于给定内容后的结果（不写盘）
     * @return array ['op'=>'write|create|delete', 'content'=>?string, 'error'=>string]
     */
    public function computeContent($content, EditAction $a)
    {
        switch ($a->action) {
            case 'str_replace':
                return $this->applyStrReplace($content, $a);

            case 'replace_range':
                return $this->applyReplaceRange($content, $a);

            case 'replace_file':
                return ['op' => 'write', 'content' => self::nl((string) $a->content), 'error' => ''];

            case 'create_file':
                return ['op' => 'create', 'content' => self::nl((string) $a->content), 'error' => ''];

            case 'delete_file':
                return ['op' => 'delete', 'content' => null, 'error' => ''];

            default:
                return ['op' => 'write', 'content' => $content, 'error' => "动作 {$a->action} 不支持磁盘执行（应由编辑器应用）"];
        }
    }

    /** 统一换行为 \n，消除 \r\n / \r 导致的匹配失败 */
    public static function nl($s) { return str_replace(["\r\n", "\r"], "\n", (string) $s); }

    protected function applyStrReplace($content, EditAction $a)
    {
        // 按 \n 规范化后匹配：模型几乎总是用 \n，而文件可能是 \r\n
        $content = self::nl($content);
        $old = self::nl((string) $a->oldString);
        $new = self::nl((string) $a->newString);
        $count = substr_count($content, $old);
        if ($count === 0) {
            return ['op' => 'write', 'content' => $content, 'error' => 'old_string 未在文件中找到，无法定位'];
        }
        if ($count > 1 && !$a->replaceAll) {
            return ['op' => 'write', 'content' => $content, 'error' => "old_string 匹配到 {$count} 处，不唯一（可设 replace_all 或补充更多上下文）"];
        }
        $result = $a->replaceAll
            ? str_replace($old, $new, $content)
            : self::str_replace_first($content, $old, $new);
        return ['op' => 'write', 'content' => $result, 'error' => ''];
    }

    protected function applyReplaceRange($content, EditAction $a)
    {
        $content = self::nl($content);
        $total = mb_strlen($content);
        $s = (int) $a->startOffset;
        $e = (int) $a->endOffset;
        if ($s < 0 || $e < $s || $e > $total) {
            return ['op' => 'write', 'content' => $content, 'error' => 'replace_range 偏移越界'];
        }
        $result = mb_substr($content, 0, $s) . self::nl((string) $a->replacement) . mb_substr($content, $e);
        return ['op' => 'write', 'content' => $result, 'error' => ''];
    }

    protected static function str_replace_first($haystack, $needle, $replace)
    {
        $pos = strpos($haystack, $needle);
        if ($pos === false) return $haystack;
        return substr_replace($haystack, $replace, $pos, strlen($needle));
    }
}
