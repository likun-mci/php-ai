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
 * 除命令式策略外，还可挂载实现了 `VerifierInterface` 的验证器。命令式适合
 * 「跑一条命令看退出码」，验证器适合需要解析输出、定位到具体文件行号的场景：
 *
 * ```php
 * $vm->addVerifier(new PhpSyntaxVerifier());
 * $vm->addVerifier(new SecurityVerifier());
 * $results = $vm->verify('write_file', ['file_path' => 'src/Auth.php']);
 * ```
 *
 * 两者可以共存：`verify()` 先跑命令式规则，再跑支持该工具的验证器，结果合并返回。
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

    /** @var VerifierInterface[] 挂载的验证器 */
    protected $verifiers = [];

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
     * 挂载一个验证器
     *
     * 同名验证器会被覆盖，重复挂载不会跑两遍。
     *
     * @param VerifierInterface $verifier
     * @return $this
     */
    public function addVerifier(VerifierInterface $verifier)
    {
        $this->verifiers[$verifier->name()] = $verifier;
        return $this;
    }

    /**
     * 移除一个验证器
     *
     * @param string $name
     * @return $this
     */
    public function removeVerifier($name)
    {
        unset($this->verifiers[(string) $name]);
        return $this;
    }

    /**
     * 全部验证器
     *
     * @return VerifierInterface[] 验证器名 => 实例
     */
    public function verifiers()
    {
        return $this->verifiers;
    }

    /**
     * 取指定名称的验证器
     *
     * @param string $name
     * @return VerifierInterface|null
     */
    public function getVerifier($name)
    {
        $name = (string) $name;
        return isset($this->verifiers[$name]) ? $this->verifiers[$name] : null;
    }

    /**
     * 找出支持指定工具的验证器
     *
     * @param string $toolName
     * @return VerifierInterface[]
     */
    public function verifiersFor($toolName)
    {
        $matched = [];
        foreach ($this->verifiers as $name => $verifier) {
            if ($verifier->supports((string) $toolName)) {
                $matched[$name] = $verifier;
            }
        }
        return $matched;
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
     * 指定工具是否有任何验证（命令式规则或验证器）
     *
     * `hasRule()` 只看命令式规则，判断"要不要调 verify()"时用这个方法，
     * 否则只挂了验证器、没配规则的工具会被整个跳过。
     *
     * @param string $toolName
     * @return bool
     */
    public function hasVerification($toolName)
    {
        if (!$this->enabled) {
            return false;
        }
        return $this->hasRule($toolName) || $this->verifiersFor($toolName) !== [];
    }

    /**
     * 对一次工具调用执行验证
     *
     * 先跑命令式规则，再跑支持该工具的验证器，结果合并返回。
     *
     * @param string $toolName
     * @param array<string, mixed> $input 工具输入（用于 {file} 占位替换与验证器上下文）
     * @return VerificationResult[]
     */
    public function verify($toolName, array $input)
    {
        if (!$this->enabled) {
            return [];
        }

        $results = [];
        $commands = $this->commandsFor((string) $toolName, $input);
        foreach ($commands as $command) {
            $results[] = $this->runCommand($command);
        }

        // 验证器：把工具名并入上下文，验证器自行决定是否处理
        $context = $input;
        $context['tool_name'] = (string) $toolName;
        foreach ($this->verifiersFor((string) $toolName) as $verifier) {
            $results[] = $verifier->verify($context);
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