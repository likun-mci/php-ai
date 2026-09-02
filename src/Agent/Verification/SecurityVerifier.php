<?php
namespace Ai\Agent\Verification;

/**
 * SecurityVerifier——安全检查验证器
 *
 * 扫描 Agent 写入的 PHP 文件，报出危险函数与硬编码凭据。模型为了"快速跑通"
 * 经常写出 `exec($cmd)`、`eval($code)` 这类代码，人不看一眼很难发现。
 *
 * ```php
 * $verifier = new SecurityVerifier();
 * $result = $verifier->verify(['tool_name' => 'write_file', 'file_path' => 'src/Runner.php']);
 * print_r($result->getErrors());
 * // [['file' => 'src/Runner.php', 'line' => 18, 'message' => '危险函数 exec()']]
 * ```
 *
 * 说明：
 *  - 基于 token 扫描（`token_get_all`），只认真正的函数调用，
 *    注释与字符串里出现的 `eval` 不会误报
 *  - 命中即判失败，错误信息回填给模型让它换实现；确实需要某个函数时用
 *    `allow()` 放行，而不是关掉整个验证器
 */
class SecurityVerifier extends BaseVerifier
{
    /** @var string[] 默认危险函数列表 */
    protected static $defaultDangerous = [
        'eval', 'exec', 'system', 'passthru', 'shell_exec', 'popen', 'proc_open',
        'assert', 'create_function', 'unserialize', 'extract', 'putenv',
        'dl', 'pcntl_exec',
    ];

    /** @var string[] 检查的函数列表 */
    protected $dangerous = [];

    /** @var string[] 放行的函数名 */
    protected $allowed = [];

    /** @var bool 是否检查硬编码凭据 */
    protected $checkSecrets = true;

    /** @var string[] 触发验证的工具名 */
    protected $tools = ['write_file', 'edit_file'];

    /**
     * @param array<string, mixed> $options dangerous / allow / checkSecrets / tools / enabled
     */
    public function __construct(array $options = [])
    {
        parent::__construct($options);
        $this->dangerous = self::$defaultDangerous;
        if (isset($options['dangerous']) && is_array($options['dangerous'])) {
            $this->dangerous = array_values(array_map('strval', $options['dangerous']));
        }
        if (isset($options['allow']) && is_array($options['allow'])) {
            $this->allowed = array_values(array_map('strval', $options['allow']));
        }
        if (isset($options['checkSecrets'])) {
            $this->checkSecrets = (bool) $options['checkSecrets'];
        }
        if (isset($options['tools']) && is_array($options['tools'])) {
            $this->tools = array_values(array_map('strval', $options['tools']));
        }
    }

    /**
     * @return string
     */
    public function name()
    {
        return 'security';
    }

    /**
     * @return string[]
     */
    public function supportedTools()
    {
        return $this->tools;
    }

    /**
     * 放行指定函数（不再报危险）
     *
     * @param string|string[] $functions
     * @return $this
     */
    public function allow($functions)
    {
        foreach (is_array($functions) ? $functions : [$functions] as $fn) {
            $fn = strtolower(trim((string) $fn));
            if ($fn !== '' && !in_array($fn, $this->allowed, true)) {
                $this->allowed[] = $fn;
            }
        }
        return $this;
    }

    /**
     * 扫描文件
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
            return VerificationResult::passed('', '无文件路径，跳过安全检查', $name);
        }
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        if (strtolower($ext) !== 'php') {
            return VerificationResult::passed('', '非 PHP 文件，跳过安全检查', $name);
        }
        if (!$this->fileExists($filePath)) {
            return VerificationResult::passed('', '文件不存在，跳过安全检查: ' . $filePath, $name);
        }

        $code = @file_get_contents($filePath);
        if ($code === false) {
            return VerificationResult::passed('', '文件不可读，跳过安全检查: ' . $filePath, $name);
        }

        $findings = $this->scan($code);
        $cmd = 'security-scan ' . $filePath;

        if (!$findings) {
            return VerificationResult::passed($cmd, '未发现危险调用', $name);
        }

        $messages = [];
        foreach ($findings as $finding) {
            $messages[] = sprintf('%s:%d %s', $filePath, $finding['line'], $finding['message']);
        }
        $vr = VerificationResult::failed($cmd, implode("\n", $messages), $name);
        foreach ($findings as $finding) {
            $vr->addError($finding['message'], $filePath, $finding['line']);
        }
        return $vr;
    }

    /**
     * 扫描源码，返回命中项
     *
     * @param string $code
     * @return array<int, array{line: int, message: string}>
     */
    protected function scan($code)
    {
        $findings = [];
        $tokens = @token_get_all($code);
        if (!is_array($tokens)) {
            return $findings;
        }

        $prevSignificant = null;
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_STRING || $token[0] === T_EVAL) {
                $fn = strtolower($token[1]);
                if (!in_array($fn, $this->dangerous, true) || in_array($fn, $this->allowed, true)) {
                    $prevSignificant = $token;
                    continue;
                }
                // T_EVAL 是语言结构，本身即调用；其余需要后面跟 "(" 才算函数调用
                if ($token[0] === T_STRING && !$this->followedByParen($tokens, $i)) {
                    $prevSignificant = $token;
                    continue;
                }
                // 方法调用（$obj->exec()）与静态调用（Foo::exec()）不算内置危险函数
                if ($prevSignificant !== null
                    && in_array($prevSignificant[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                    $prevSignificant = $token;
                    continue;
                }
                $findings[] = [
                    'line'    => (int) $token[2],
                    'message' => '危险函数 ' . $fn . '()',
                ];
            }
            if ($token[0] !== T_WHITESPACE && $token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                $prevSignificant = $token;
            }
        }

        if ($this->checkSecrets) {
            foreach ($this->scanSecrets($code) as $secret) {
                $findings[] = $secret;
            }
        }

        return $findings;
    }

    /**
     * 判断某个 token 后面（跳过空白）是不是 "("
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param int $index
     * @return bool
     */
    protected function followedByParen(array $tokens, $index)
    {
        $count = count($tokens);
        for ($i = $index + 1; $i < $count; $i++) {
            $next = $tokens[$i];
            if (is_array($next)) {
                if ($next[0] === T_WHITESPACE || $next[0] === T_COMMENT || $next[0] === T_DOC_COMMENT) {
                    continue;
                }
                return false;
            }
            return $next === '(';
        }
        return false;
    }

    /**
     * 扫描硬编码凭据
     *
     * 只报明显形态：赋值给 password / secret / api_key / token 之类变量的长字面量。
     *
     * @param string $code
     * @return array<int, array{line: int, message: string}>
     */
    protected function scanSecrets($code)
    {
        $findings = [];
        $pattern = '/([\'"]?)(?:api[_-]?key|secret|password|passwd|access[_-]?token)\1'
            . '\s*(?:=>|=|:)\s*[\'"]([^\'"]{12,})[\'"]/i';
        $lines = preg_split('/\r?\n/', (string) $code);
        foreach ($lines === false ? [] : $lines as $no => $line) {
            if (preg_match($pattern, $line, $m)) {
                // 明显的占位符不报
                if (preg_match('/^(your|xxx|placeholder|changeme|sk-xxx|<.+>)/i', $m[2])) {
                    continue;
                }
                $findings[] = [
                    'line'    => $no + 1,
                    'message' => '疑似硬编码凭据',
                ];
            }
        }
        return $findings;
    }
}
