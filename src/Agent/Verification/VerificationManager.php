<?php
namespace Ai\Agent\Verification;

/**
 * VerificationManager——验证管理器
 *
 * 在工具执行后自动运行验证命令（如 `php -l`），确保代码改动真实可用，
 * 而不是让模型"记得自己测试"。验证失败的错误信息会回填给模型，让它自行修复。
 *
 * 策略按工具名配置，支持多条命令；命令中的 `{file}` 占位符会替换为
 * 该次工具调用输入里的 `file_path`：
 *
 * ```php
 * $vm = new VerificationManager([
 *     'edit_file'  => ['php -l {file}'],
 *     'write_file' => ['php -l {file}'],
 *     'test'       => ['vendor/bin/phpunit'],
 * ]);
 *
 * $results = $vm->verify('edit_file', ['file_path' => 'src/Auth.php']);
 * // VerificationResult[]：passed / failed
 * ```
 *
 * 说明：
 *  - 命令通过 `exec()` 同步执行，按退出码判断通过与否（0 = 通过）
 *  - 验证命令由业务层配置，不属于模型输入；`{file}` 占位符按原文替换
 *  - 命令中带 `{file}` 但输入里没有 `file_path` 时，该命令被跳过（无法验证空路径）
 */
class VerificationManager
{
    /** @var array<string, string[]> 工具名 => 验证命令列表 */
    protected $rules = [];

    /** @var bool */
    protected $enabled = true;

    /**
     * @param array<string, string|string[]> $rules 工具名 => 命令或命令数组
     */
    public function __construct(array $rules = [])
    {
        if ($rules) {
            $this->setRules($rules);
        }
    }

    /**
     * 整体设置验证策略（覆盖）
     *
     * @param array<string, string|string[]> $rules
     * @return $this
     */
    public function setRules(array $rules)
    {
        $this->rules = [];
        foreach ($rules as $tool => $commands) {
            $this->addRule((string) $tool, $commands);
        }
        return $this;
    }

    /**
     * 为某个工具追加验证命令
     *
     * @param string $toolName
     * @param string|string[] $commands 单条命令或命令数组，支持 {file} 占位符
     * @return $this
     */
    public function addRule($toolName, $commands)
    {
        $list = is_array($commands) ? $commands : [$commands];
        $clean = [];
        foreach ($list as $cmd) {
            $cmd = trim((string) $cmd);
            if ($cmd !== '') {
                $clean[] = $cmd;
            }
        }
        if ($clean) {
            $this->rules[(string) $toolName] = $clean;
        }
        return $this;
    }

    /**
     * 启用/停用验证
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
     * 全部验证策略
     *
     * @return array<string, string[]>
     */
    public function rules()
    {
        return $this->rules;
    }

    /**
     * 指定工具是否配置了验证
     *
     * @param string $toolName
     * @return bool
     */
    public function hasRule($toolName)
    {
        return $this->enabled && isset($this->rules[(string) $toolName]);
    }

    /**
     * 对一次工具调用执行验证
     *
     * @param string $toolName
     * @param array<string, mixed> $input 工具输入（用于 {file} 占位替换）
     * @return VerificationResult[]
     */
    public function verify($toolName, array $input)
    {
        $results = [];
        $commands = $this->commandsFor((string) $toolName, $input);
        foreach ($commands as $command) {
            $results[] = $this->runCommand($command);
        }
        return $results;
    }

    /**
     * 展开占位符后返回可执行的命令列表
     *
     * 带 `{file}` 占位符但输入里没有 file_path 的命令会被跳过。
     *
     * @param string $toolName
     * @param array<string, mixed> $input
     * @return string[]
     */
    protected function commandsFor($toolName, array $input)
    {
        if (!$this->hasRule($toolName)) {
            return [];
        }
        $file = isset($input['file_path']) ? (string) $input['file_path'] : '';
        $commands = [];
        foreach ($this->rules[(string) $toolName] as $command) {
            if (strpos($command, '{file}') !== false && $file === '') {
                continue;  // 需要文件路径但拿不到，跳过
            }
            $expanded = str_replace('{file}', $file, $command);
            $commands[] = $expanded;
        }
        return $commands;
    }

    /**
     * 执行一条验证命令
     *
     * 用 `exec()` 同步执行，退出码 0 视为通过，stdout 作为输出，
     * 非 0 退出码的 stderr 合并进错误信息。
     *
     * @param string $command
     * @return VerificationResult
     */
    protected function runCommand($command)
    {
        $output = [];
        $code = -1;
        exec($command . ' 2>&1', $output, $code);
        $text = implode("\n", $output);
        if ($code === 0) {
            return VerificationResult::passed($command, $text);
        }
        return VerificationResult::failed($command, $text !== '' ? $text : 'exit code ' . $code);
    }
}