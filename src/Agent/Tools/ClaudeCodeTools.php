<?php
namespace Ai\Agent\Tools;

/**
 * Claude Code 风格内置工具工厂
 *
 * 一键创建 Agent 的完整内置工具集（Read/Write/Edit/Glob/Grep/Bash），
 * 这些工具与 Claude Code CLI 默认工具集对齐，让 Agent 具备结构化文件操作能力。
 *
 * 用法：
 * ```php
 * use Ai\Agent\Tools\ClaudeCodeTools;
 *
 * $agent->setTools(ClaudeCodeTools::all([
 *     'workdir' => '/var/www/project',
 * ]));
 * ```
 */
class ClaudeCodeTools
{
    /**
     * 创建全部内置工具
     *
     * @param array<string, mixed> $options 配置项：
     *   - workdir: string 工作目录
     *   - max_read_bytes: int 单次读取最大字节数（默认 100000）
     *   - bash_timeout: int 命令超时秒数（默认 30）
     *   - glob_max: int glob 最大返回条数（默认 100）
     *   - grep_max: int grep 最大返回条数（默认 50）
     * @return array<string, object> 工具名 => AgentToolInterface 实例
     */
    public static function all(array $options = [])
    {
        $cwd = getcwd();
        $workdir = isset($options['workdir']) ? (string) $options['workdir'] : ($cwd !== false ? $cwd : '/');
        $pathSafety = new PathSafety($workdir);
        $bashTimeout = isset($options['bash_timeout']) ? (int) $options['bash_timeout'] : 30;
        $maxReadBytes = isset($options['max_read_bytes']) ? (int) $options['max_read_bytes'] : 100000;
        $globMax = isset($options['glob_max']) ? (int) $options['glob_max'] : 100;
        $grepMax = isset($options['grep_max']) ? (int) $options['grep_max'] : 50;

        $tools = [];
        $tools['read_file'] = new ReadFileTool($pathSafety, $maxReadBytes);
        $tools['write_file'] = new WriteFileTool($pathSafety);
        $tools['edit_file'] = new EditFileTool($pathSafety);
        $tools['glob'] = new GlobTool($pathSafety, $globMax);
        $tools['grep'] = new GrepTool($pathSafety, $grepMax);

        $bashTool = new BashTool($bashTimeout);
        $bashTool->setWorkdir($workdir);
        $tools['bash'] = $bashTool;

        return $tools;
    }

    /**
     * 只读工具集（适合 plan 模式）
     *
     * @param array<string, mixed> $options
     * @return array<string, object>
     */
    public static function readOnly(array $options = [])
    {
        $workdir = isset($options['workdir']) ? (string) $options['workdir'] : '';
        if ($workdir === '') {
            $cwd = getcwd();
            $workdir = is_string($cwd) ? $cwd : '/';
        }
        $pathSafety = new PathSafety($workdir);
        $maxReadBytes = isset($options['max_read_bytes']) ? (int) $options['max_read_bytes'] : 100000;
        $globMax = isset($options['glob_max']) ? (int) $options['glob_max'] : 100;
        $grepMax = isset($options['grep_max']) ? (int) $options['grep_max'] : 50;

        $tools = [];
        $tools['read_file'] = new ReadFileTool($pathSafety, $maxReadBytes);
        $tools['glob'] = new GlobTool($pathSafety, $globMax);
        $tools['grep'] = new GrepTool($pathSafety, $grepMax);

        return $tools;
    }
}