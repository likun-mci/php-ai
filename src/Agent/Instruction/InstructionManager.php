<?php
namespace Ai\Agent\Instruction;

/**
 * InstructionManager——项目指令管理器
 *
 * 加载并合并项目级指令文件（CLAUDE.md / AGENTS.md），
 * 这些是项目必须遵守的长期规则，与 Skill 不同：
 *
 *   - CLAUDE.md / AGENTS.md = 项目必须遵守的长期规则
 *   - Skill = 某项能力 / 工作流程
 *   - Tool = 实际执行动作
 *
 * 加载优先级（后加载的优先级更高，同名条目覆盖）：
 *   Global → Project → Subdirectory → Task
 *
 * 用法：
 * ```php
 * $im = new InstructionManager();
 * $im->loadFromDir('/var/www/project');          // 加载 {dir}/CLAUDE.md
 * $im->loadFromDir('/var/www/project/src');      // 加载子目录级的指令
 * echo $im->toSystemPrompt();                     // 合并后的指令文本
 * ```
 */
class InstructionManager
{
    /** @var array<int, array{path: string, content: string, dir: string}> 已加载的指令内容 */
    protected $instructions = [];

    /** @var bool */
    protected $enabled = true;

    /** @var string[] 要搜索的文件名 */
    protected $filenames = ['CLAUDE.md', 'AGENTS.md', '.ai/AGENTS.md'];

    /**
     * 设置要搜索的文件名列表
     *
     * @param string[] $filenames
     * @return $this
     */
    public function setFilenames(array $filenames)
    {
        $this->filenames = $filenames;
        return $this;
    }

    /** @return string[] */
    public function getFilenames()
    {
        return $this->filenames;
    }

    /**
     * 启用/停用
     *
     * @param bool $enabled
     * @return $this
     */
    public function setEnabled($enabled)
    {
        $this->enabled = (bool) $enabled;
        return $this;
    }

    /** @return bool */
    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * 从目录加载指令文件
     *
     * 搜索顺序：对于每个目录，依次检查 CLAUDE.md、AGENTS.md、.ai/AGENTS.md。
     * 后加载的指令追加到列表末尾，合并时按"后加载覆盖先加载"。
     *
     * @param string $dir 项目目录或子目录
     * @return $this
     */
    public function loadFromDir($dir)
    {
        $dir = rtrim(str_replace('\\', '/', (string) $dir), '/');
        if ($dir === '' || !is_dir($dir)) {
            return $this;
        }

        foreach ($this->filenames as $filename) {
            // 支持子路径如 .ai/AGENTS.md
            $fullPath = $dir . '/' . ltrim($filename, '/');
            if (is_file($fullPath) && is_readable($fullPath)) {
                $content = @file_get_contents($fullPath);
                if ($content !== false) {
                    $this->instructions[] = [
                        'path'    => $fullPath,
                        'content' => $content,
                        'dir'     => $dir,
                    ];
                }
            }
        }

        return $this;
    }

    /**
     * 从目录树加载指令（从根目录到子目录）
     *
     * 子目录的指令优先级高于父目录。
     * 例如 loadTree('/var/www/project') 会尝试加载：
     *   /var/www/project/.claude/CLAUDE.md（如果 .claude 在 filenames 里）
     *   /var/www/project/.ai/AGENTS.md
     * 但不会递归子目录——由调用方自行决定哪些目录需要加载。
     *
     * 典型的加载顺序（优先级从低到高）：
     *   1. 全局：~/.claude/CLAUDE.md
     *   2. 项目根：/var/www/project/CLAUDE.md
     *   3. 子目录：/var/www/project/src/CLAUDE.md
     *   4. 任务级：/var/www/project/src/Admin/CLAUDE.md
     *
     * @param string $dir
     * @return $this
     */
    public function loadFromTree($dir)
    {
        $dir = rtrim(str_replace('\\', '/', (string) $dir), '/');
        // 先加载 .claude 子目录（如果存在）
        $claudeDir = $dir . '/.claude';
        if (is_dir($claudeDir)) {
            $this->loadFromDir($claudeDir);
        }
        // 再加载目录本身
        $this->loadFromDir($dir);
        // 再加载 .ai 子目录
        $aiDir = $dir . '/.ai';
        if (is_dir($aiDir)) {
            $this->loadFromDir($aiDir);
        }
        // 后加载的优先级更高，在合并时自然覆盖
        return $this;
    }

    /**
     * 已加载的指令列表
     *
     * @return array<int, array{path: string, content: string, dir: string}>
     */
    public function getInstructions()
    {
        return $this->instructions;
    }

    /**
     * 清空指令
     *
     * @return $this
     */
    public function clear()
    {
        $this->instructions = [];
        return $this;
    }

    /**
     * 生成注入系统提示词的指令文本
     *
     * 合并所有已加载的指令，后加载的追加在后面。
     * 格式：
     * ```
     * <instructions>
     * 来自 {path}：
     *（内容...）
     * </instructions>
     * ```
     *
     * @return string 空字符串表示没有指令
     */
    public function toSystemPrompt()
    {
        if (!$this->enabled || !$this->instructions) {
            return '';
        }

        $parts = [];
        foreach ($this->instructions as $inst) {
            $path = $inst['path'];
            $content = trim($inst['content']);
            if ($content === '') {
                continue;
            }
            $parts[] = "来自 {$path}：\n" . $content;
        }

        if (!$parts) {
            return '';
        }

        return "<instructions>\n" . implode("\n\n---\n\n", $parts) . "\n</instructions>";
    }
}