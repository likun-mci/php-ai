<?php
namespace Ai\Agent\Verification;

/**
 * VerifierInterface——验证器接口
 *
 * 所有验证器必须实现此接口。每个验证器专注于一种验证维度：
 * - PhpSyntaxVerifier: PHP 语法检查
 * - UnitTestVerifier: 单元测试
 * - GitDiffVerifier: Git 差异检查
 * - SecurityVerifier: 安全检查
 *
 * ```php
 * if ($verifier->supports($toolName)) {
 *     $result = $verifier->verify($taskState);
 * }
 * ```
 */
interface VerifierInterface
{
    /**
     * 验证器名称
     *
     * @return string
     */
    public function name();

    /**
     * 执行验证
     *
     * @param array<string, mixed> $context 验证上下文（包含工具名、输入参数、任务状态等）
     * @return VerificationResult
     */
    public function verify(array $context);

    /**
     * 该验证器支持哪些工具
     *
     * @return string[]
     */
    public function supportedTools();

    /**
     * 是否支持指定工具
     *
     * @param string $toolName
     * @return bool
     */
    public function supports($toolName);
}