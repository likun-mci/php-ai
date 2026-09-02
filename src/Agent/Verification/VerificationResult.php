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
 *
 * 由 VerifierInterface 实现类返回时，还会带上验证器名称与结构化错误列表：
 * ```php
 * $result->getVerifierName();  // 'php_syntax'
 * $result->getErrors();        // [['file' => 'src/Auth.php', 'line' => 12, 'message' => '...']]
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

    /** @var string 产生该结果的验证器名称，命令式验证为空串 */
    protected $verifierName = '';

    /** @var array<int, array{file: string, line: int, message: string}> 结构化错误列表 */
    protected $errors = [];

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
     * @param string $verifierName
     * @return self
     */
    public static function passed($command = '', $output = '', $verifierName = '')
    {
        $result = new self(true, $command, $output);
        return $result->setVerifierName($verifierName);
    }

    /**
     * 创建验证失败的结果
     *
     * @param string $command
     * @param string $error
     * @param string $verifierName
     * @return self
     */
    public static function failed($command = '', $error = '', $verifierName = '')
    {
        $result = new self(false, $command, '', $error);
        return $result->setVerifierName($verifierName);
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

    /**
     * 产生该结果的验证器名称
     *
     * 由 VerificationManager 直接执行命令时为空串。
     *
     * @return string
     */
    public function getVerifierName()
    {
        return $this->verifierName;
    }

    /**
     * @param string $verifierName
     * @return $this
     */
    public function setVerifierName($verifierName)
    {
        $this->verifierName = (string) $verifierName;
        return $this;
    }

    /**
     * 结构化错误列表
     *
     * 每项形如 `['file' => ..., 'line' => ..., 'message' => ...]`，
     * 供上层定位问题；未解析出结构化信息时为空数组，此时看 getError()。
     *
     * @return array<int, array{file: string, line: int, message: string}>
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * @param array<int, array<string, mixed>> $errors
     * @return $this
     */
    public function setErrors(array $errors)
    {
        $this->errors = [];
        foreach ($errors as $error) {
            $this->addError(
                isset($error['message']) ? $error['message'] : '',
                isset($error['file']) ? $error['file'] : '',
                isset($error['line']) ? $error['line'] : 0
            );
        }
        return $this;
    }

    /**
     * 追加一条结构化错误
     *
     * @param string $message
     * @param string $file
     * @param int $line
     * @return $this
     */
    public function addError($message, $file = '', $line = 0)
    {
        $this->errors[] = [
            'file'    => (string) $file,
            'line'    => (int) $line,
            'message' => (string) $message,
        ];
        return $this;
    }

    /**
     * 导出为数组（用于日志、检查点持久化）
     *
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'passed'   => $this->passed,
            'verifier' => $this->verifierName,
            'command'  => $this->command,
            'output'   => $this->output,
            'error'    => $this->error,
            'errors'   => $this->errors,
        ];
    }
}
