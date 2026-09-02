<?php
namespace Ai\Agent\Verification;

/**
 * PhpSyntaxVerifier——PHP 语法验证器
 *
 * 对修改的 PHP 文件执行 `php -l` 检查语法错误。
 * 在文件写入或编辑后自动触发，确保生成的 PHP 代码可解析。
 *
 * ```php
 * $verifier = new PhpSyntaxVerifier();
 * $result = $verifier->verify(['tool_name' => 'write_file', 'file_path' => 'src/Auth.php']);
 * echo $result->isPassed(); // true / false
 * ```
 */
class PhpSyntaxVerifier extends BaseVerifier
{
    /**
     * @return string
     */
    public function name()
    {
        return 'php_syntax';
    }

    /**
     * @return string[]
     */
    public function supportedTools()
    {
        return ['write_file', 'edit_file'];
    }

    /**
     * 执行 PHP 语法检查
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
        if ($filePath === '') {
            return VerificationResult::passed('', '无文件路径，跳过语法检查', $name);
        }

        // 只检查 .php 文件
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        if (strtolower($ext) !== 'php') {
            return VerificationResult::passed('', '非 PHP 文件，跳过语法检查', $name);
        }

        if (!$this->fileExists($filePath)) {
            return VerificationResult::passed('', '文件不存在，跳过语法检查: ' . $filePath, $name);
        }

        $cmd = 'php -l ' . escapeshellarg($filePath);
        $result = $this->exec($cmd);

        if ($result['code'] === 0) {
            return VerificationResult::passed($cmd, $result['output'], $name);
        }

        $vr = VerificationResult::failed($cmd, $result['output'], $name);
        foreach ($this->parseErrors($result['output'], $filePath) as $error) {
            $vr->addError($error['message'], $error['file'], $error['line']);
        }
        return $vr;
    }

    /**
     * 解析 `php -l` 的错误输出
     *
     * 输出形如：
     * `PHP Parse error:  syntax error, unexpected token "{" in /path/a.php on line 2`
     *
     * @param string $output
     * @param string $filePath 解析不出文件名时的兜底路径
     * @return array<int, array{file: string, line: int, message: string}>
     */
    protected function parseErrors($output, $filePath)
    {
        $errors = [];
        $pattern = '/(?:Parse|Fatal) error:\s*(.+?)\s+in\s+(.+?)\s+on line\s+(\d+)/i';
        if (preg_match_all($pattern, (string) $output, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $errors[] = [
                    'file'    => trim($m[2]),
                    'line'    => (int) $m[3],
                    'message' => trim($m[1]),
                ];
            }
        }
        // 未匹配到标准格式时，至少给出一条带文件名的错误
        if (!$errors && trim((string) $output) !== '') {
            $errors[] = [
                'file'    => $filePath,
                'line'    => 0,
                'message' => trim((string) $output),
            ];
        }
        return $errors;
    }
}
