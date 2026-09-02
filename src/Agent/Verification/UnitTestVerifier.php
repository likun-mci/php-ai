<?php
namespace Ai\Agent\Verification;

/**
 * UnitTestVerifier——单元测试验证器
 *
 * 代码改动后自动跑一遍测试，把失败用例回填给模型，而不是让模型"记得自己测试"。
 * 默认命令是 `vendor/bin/phpunit`，可换成项目自己的测试入口（含 composer 脚本）。
 *
 * ```php
 * $verifier = new UnitTestVerifier([
 *     'command' => 'composer test',
 *     'workdir' => '/var/www/project',
 *     'tools'   => ['write_file', 'edit_file'],
 * ]);
 * $result = $verifier->verify(['tool_name' => 'edit_file', 'file_path' => 'src/Auth.php']);
 * ```
 *
 * 说明：
 *  - 测试较慢时把 `tools` 收窄到真正改代码的工具，避免每个工具调用都跑一遍
 *  - 只在改动 PHP 文件后触发（`onlyPhp` 默认 true），改 README 不会跑测试
 *  - 失败输出里的 `1) Foo::testBar` 会被解析成结构化错误
 */
class UnitTestVerifier extends BaseVerifier
{
    /** @var string 测试命令 */
    protected $command = 'vendor/bin/phpunit';

    /** @var string 执行目录，空则用当前目录 */
    protected $workdir = '';

    /** @var string[] 触发验证的工具名 */
    protected $tools = ['write_file', 'edit_file'];

    /** @var bool 是否只在 PHP 文件改动后触发 */
    protected $onlyPhp = true;

    /**
     * @param array<string, mixed> $options command / workdir / tools / onlyPhp / enabled
     */
    public function __construct(array $options = [])
    {
        parent::__construct($options);
        if (isset($options['command'])) {
            $this->command = (string) $options['command'];
        }
        if (isset($options['workdir'])) {
            $this->workdir = rtrim(str_replace('\\', '/', (string) $options['workdir']), '/');
        }
        if (isset($options['tools']) && is_array($options['tools'])) {
            $this->tools = array_values(array_map('strval', $options['tools']));
        }
        if (isset($options['onlyPhp'])) {
            $this->onlyPhp = (bool) $options['onlyPhp'];
        }
    }

    /**
     * @return string
     */
    public function name()
    {
        return 'unit_test';
    }

    /**
     * @return string[]
     */
    public function supportedTools()
    {
        return $this->tools;
    }

    /**
     * 运行测试命令
     *
     * @param array<string, mixed> $context
     * @return VerificationResult
     */
    public function verify(array $context)
    {
        $name = $this->name();

        if (!$this->enabled) {
            return VerificationResult::passed('', '验证器已禁用', $name);
        }

        $filePath = $this->getFilePath($context);
        if ($this->onlyPhp && $filePath !== '') {
            $ext = pathinfo($filePath, PATHINFO_EXTENSION);
            if (strtolower($ext) !== 'php') {
                return VerificationResult::passed('', '非 PHP 文件改动，跳过测试', $name);
            }
        }

        $cmd = $this->command;
        if ($this->workdir !== '') {
            if (!is_dir($this->workdir)) {
                return VerificationResult::passed('', '执行目录不存在，跳过测试: ' . $this->workdir, $name);
            }
            $cmd = 'cd ' . escapeshellarg($this->workdir) . ' && ' . $this->command;
        }

        $result = $this->exec($cmd);
        if ($result['code'] === 0) {
            return VerificationResult::passed($this->command, $result['output'], $name);
        }

        $vr = VerificationResult::failed($this->command, $result['output'], $name);
        foreach ($this->parseFailures($result['output']) as $failure) {
            $vr->addError($failure, $filePath, 0);
        }
        return $vr;
    }

    /**
     * 解析测试输出中的失败用例名
     *
     * 兼容 PHPUnit 的 `1) Foo\BarTest::testBaz` 与本库测试脚本的 `✗ 用例名` 两种格式。
     *
     * @param string $output
     * @return string[]
     */
    protected function parseFailures($output)
    {
        $failures = [];
        $lines = preg_split('/\r?\n/', (string) $output);
        foreach ($lines === false ? [] : $lines as $line) {
            $line = trim($line);
            if (preg_match('/^\d+\)\s+(.+)$/', $line, $m)) {
                $failures[] = $m[1];
            } elseif (strpos($line, '✗') === 0) {
                $failures[] = trim(substr($line, strlen('✗')));
            }
        }
        return $failures;
    }

    /**
     * @return string 当前测试命令
     */
    public function getCommand()
    {
        return $this->command;
    }

    /**
     * @param string $command
     * @return $this
     */
    public function setCommand($command)
    {
        $this->command = (string) $command;
        return $this;
    }
}
