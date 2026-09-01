<?php
namespace Ai\Agent\Verification;

/**
 * VerificationResult——验证结果值对象
 *
 * 封装一次验证命令的执行结果，包含是否通过、命令文本、输出与错误信息。
 *
 * 用法：
 * ```php
 * $result = VerificationResult::passed('php -l file.php', 'No syntax errors');
 * $result = VerificationResult::failed('php -l file.php', 'Parse error: ...');
 * echo $result->isPassed(); // true / false
 * ```
 */
class VerificationResult
{
    /** @var bool */
    protected $passed;

    /** @var string */
    protected $command = '';

    /** @var string */
    protected $output = '';

    /** @var string */
    protected $error = '';

    /**
     * @param bool $passed
     * @param string $command
     * @param string $output
     * @param string $error
     */
    public function __construct($passed, $command = '', $output = '', $error = '')
    {
        $this->passed  = (bool) $passed;
        $this->command = (string) $command;
        $this->output  = (string) $output;
        $this->error   = (string) $error;
    }

    /**
     * 创建验证通过的结果
     *
     * @param string $command
     * @param string $output
     * @return self
     */
    public static function passed($command = '', $output = '')
    {
        return new self(true, $command, $output);
    }

    /**
     * 创建验证失败的结果
     *
     * @param string $command
     * @param string $error
     * @return self
     */
    public static function failed($command = '', $error = '')
    {
        return new self(false, $command, '', $error);
    }

    /** @return bool */
    public function isPassed()
    {
        return $this->passed;
    }

    /** @return string */
    public function getCommand()
    {
        return $this->command;
    }

    /** @return string */
    public function getOutput()
    {
        return $this->output;
    }

    /** @return string */
    public function getError()
    {
        return $this->error;
    }
}