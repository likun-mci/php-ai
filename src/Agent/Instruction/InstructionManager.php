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
    protected $filenames = ['CLAUDE.md', 'AGENTS.md', 'AI.md', '.ai/AGENTS.md'];

    /** @var string 项目根目录，向上查找时的边界 */
    protected $projectRoot = '';

    /** @var array<string, bool> 已加载过的路径，避免同一份指令重复注入 */
    protected $loaded = [];

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
            // 同一份指令加载两遍不会让模型更遵守它，只会白占上下文
            if (isset($this->loaded[$fullPath])) {
                continue;
            }
            if (is_file($fullPath) && is_readable($fullPath)) {
                $content = @file_get_contents($fullPath);
                if ($content !== false) {
                    $this->loaded[$fullPath] = true;
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
     * 设置项目根目录
     *
     * `discoverFor()` 向上查找时以它为边界——没有边界的话会一路找到文件系统根，
     * 把别的项目甚至用户主目录的规则也拉进来。
     *
     * @param string $dir
     * @return $this
     */
    public function setProjectRoot($dir)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', (string) $dir), '/');
        return $this;
    }

    /**
     * @return string
     */
    public function getProjectRoot()
    {
        return $this->projectRoot;
    }

    /**
     * 按当前文件位置动态发现相关指令
     *
     * 从文件所在目录向上找到项目根，把沿途的指令文件按「远的在前、近的在后」
     * 加载——离当前文件最近的规则优先级最高，因为它最具体。
     *
     * ```php
     * $im->setProjectRoot('/var/www/project');
     * $im->discoverFor('/var/www/project/src/Admin/User.php');
     * // 依次加载：project/CLAUDE.md → project/src/CLAUDE.md → project/src/Admin/CLAUDE.md
     * ```
     *
     * **不要一次把整个项目的 CLAUDE.md 全塞进 System Prompt**——一个大项目里
     * 几十份子目录规则，绝大多数与当前任务无关，只是在挤占上下文。
     *
     * @param string $path 当前正在处理的文件或目录
     * @param int $maxLevels 最多向上找几层
     * @return string[] 实际加载的指令文件路径
     */
    public function discoverFor($path, $maxLevels = 10)
    {
        $path = rtrim(str_replace('\\', '/', (string) $path), '/');
        if ($path === '') {
            return [];
        }

        $dir = is_dir($path) ? $path : dirname($path);
        $root = $this->projectRoot;

        // 从当前目录一路向上收集，再反过来加载：远的先加载，近的后加载覆盖它
        $chain = [];
        for ($i = 0; $i < max(1, (int) $maxLevels); $i++) {
            if ($dir === '' || $dir === '.' || $dir === '/') {
                break;
            }
            $chain[] = $dir;
            if ($root !== '' && $dir === $root) {
                break;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            // 已经走出项目根就停下——别的项目的规则跟这次任务无关
            if ($root !== '' && strpos($parent, $root) !== 0) {
                break;
            }
            $dir = $parent;
        }

        $loadedPaths = [];
        foreach (array_reverse($chain) as $level) {
            foreach ($this->loadFromDirTracked($level) as $file) {
                $loadedPaths[] = $file;
            }
        }
        return $loadedPaths;
    }

    /**
     * 加载一个目录的指令并返回实际加载的文件
     *
     * 已经加载过的路径会跳过——同一份规则注入两遍不会让模型更遵守它。
     *
     * @param string $dir
     * @return string[]
     */
    public function loadFromDirTracked($dir)
    {
        $before = count($this->instructions);
        $this->loadFromDir($dir);

        $added = [];
        for ($i = $before; $i < count($this->instructions); $i++) {
            $added[] = $this->instructions[$i]['path'];
        }
        return $added;
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
        $this->loaded = [];
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