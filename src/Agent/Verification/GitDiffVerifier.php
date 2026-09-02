<?php
namespace Ai\Agent\Verification;

/**
 * GitDiffVerifier——Git 差异验证器
 *
 * 检查 Agent 这一轮改了什么、改了多少。用途是给「放手让 Agent 改代码」加一道护栏：
 * 一次动了 40 个文件、几千行的改动，通常不是任务要求的，而是模型跑偏了。
 *
 * ```php
 * $verifier = new GitDiffVerifier([
 *     'workdir'      => '/var/www/project',
 *     'maxFiles'     => 10,
 *     'maxLines'     => 500,
 *     'protectPaths' => ['composer.json', '.github/'],
 * ]);
 * $result = $verifier->verify(['tool_name' => 'edit_file']);
 * $result->getOutput();   // ' src/Auth.php | 42 +++---'
 * ```
 *
 * 说明：
 *  - 目录不是 git 仓库时直接返回通过（没有可比对的基线，不阻断流程）
 *  - `protectPaths` 命中即判失败，用于保护构建配置、CI 配置这类不该被顺手改掉的文件
 *  - 统计口径是工作区相对 HEAD 的全部改动（含未暂存），不区分是谁改的
 */
class GitDiffVerifier extends BaseVerifier
{
    /** @var string git 仓库目录 */
    protected $workdir = '';

    /** @var int 允许改动的最大文件数，0 不限制 */
    protected $maxFiles = 0;

    /** @var int 允许改动的最大行数（增 + 删），0 不限制 */
    protected $maxLines = 0;

    /** @var string[] 受保护路径前缀，命中即判失败 */
    protected $protectPaths = [];

    /** @var string[] 触发验证的工具名 */
    protected $tools = ['write_file', 'edit_file'];

    /**
     * @param array<string, mixed> $options workdir / maxFiles / maxLines / protectPaths / tools / enabled
     */
    public function __construct(array $options = [])
    {
        parent::__construct($options);
        if (isset($options['workdir'])) {
            $this->workdir = rtrim(str_replace('\\', '/', (string) $options['workdir']), '/');
        }
        if (isset($options['maxFiles'])) {
            $this->maxFiles = max(0, (int) $options['maxFiles']);
        }
        if (isset($options['maxLines'])) {
            $this->maxLines = max(0, (int) $options['maxLines']);
        }
        if (isset($options['protectPaths']) && is_array($options['protectPaths'])) {
            $this->protectPaths = array_values(array_map('strval', $options['protectPaths']));
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
        return 'git_diff';
    }

    /**
     * @return string[]
     */
    public function supportedTools()
    {
        return $this->tools;
    }

    /**
     * 统计并检查改动规模
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

        $dir = $this->workdir !== '' ? $this->workdir : $this->guessWorkdir($context);
        if ($dir === '' || !is_dir($dir . '/.git')) {
            return VerificationResult::passed('', '不是 git 仓库，跳过差异检查', $name);
        }

        $cmd = 'cd ' . escapeshellarg($dir) . ' && git diff HEAD --numstat';
        $result = $this->exec($cmd);
        if ($result['code'] !== 0) {
            return VerificationResult::passed('git diff HEAD --numstat', $result['output'], $name);
        }

        $changes = $this->parseNumstat($result['output']);
        $files = count($changes);
        $lines = 0;
        foreach ($changes as $change) {
            $lines += $change['added'] + $change['deleted'];
        }

        $summary = sprintf('%d 个文件改动，共 %d 行（+/-）', $files, $lines);
        $vr = null;

        if ($this->maxFiles > 0 && $files > $this->maxFiles) {
            $vr = VerificationResult::failed(
                'git diff HEAD --numstat',
                "改动文件数 {$files} 超过上限 {$this->maxFiles}：{$summary}",
                $name
            );
        } elseif ($this->maxLines > 0 && $lines > $this->maxLines) {
            $vr = VerificationResult::failed(
                'git diff HEAD --numstat',
                "改动行数 {$lines} 超过上限 {$this->maxLines}：{$summary}",
                $name
            );
        }

        $protected = $this->matchProtected($changes);
        if ($protected) {
            $vr = VerificationResult::failed(
                'git diff HEAD --numstat',
                '改动了受保护路径：' . implode('、', $protected),
                $name
            );
        }

        if ($vr === null) {
            return VerificationResult::passed('git diff HEAD --numstat', $summary, $name);
        }

        foreach ($changes as $change) {
            $vr->addError(
                sprintf('+%d / -%d', $change['added'], $change['deleted']),
                $change['file'],
                0
            );
        }
        return $vr;
    }

    /**
     * 解析 `git diff --numstat` 输出
     *
     * 每行形如 `12\t3\tsrc/Auth.php`；二进制文件的增删列是 `-`，按 0 计。
     *
     * @param string $output
     * @return array<int, array{file: string, added: int, deleted: int}>
     */
    protected function parseNumstat($output)
    {
        $changes = [];
        $lines = preg_split('/\r?\n/', (string) $output);
        foreach ($lines === false ? [] : $lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\t/', $line);
            if ($parts === false || count($parts) < 3) {
                continue;
            }
            $changes[] = [
                'file'    => $parts[2],
                'added'   => is_numeric($parts[0]) ? (int) $parts[0] : 0,
                'deleted' => is_numeric($parts[1]) ? (int) $parts[1] : 0,
            ];
        }
        return $changes;
    }

    /**
     * 找出命中受保护路径的文件
     *
     * @param array<int, array{file: string, added: int, deleted: int}> $changes
     * @return string[]
     */
    protected function matchProtected(array $changes)
    {
        if (!$this->protectPaths) {
            return [];
        }
        $hits = [];
        foreach ($changes as $change) {
            foreach ($this->protectPaths as $prefix) {
                if ($prefix !== '' && strpos($change['file'], $prefix) === 0) {
                    $hits[] = $change['file'];
                    break;
                }
            }
        }
        return $hits;
    }

    /**
     * 未显式配置 workdir 时，从上下文里的文件路径推断仓库目录
     *
     * @param array<string, mixed> $context
     * @return string
     */
    protected function guessWorkdir(array $context)
    {
        if (isset($context['workdir']) && is_string($context['workdir'])) {
            return rtrim(str_replace('\\', '/', $context['workdir']), '/');
        }
        $filePath = $this->getFilePath($context);
        if ($filePath === '') {
            return '';
        }
        $dir = dirname($filePath);
        // 向上找 .git，最多 10 层
        for ($i = 0; $i < 10 && $dir !== '' && $dir !== '/' && $dir !== '.'; $i++) {
            if (is_dir($dir . '/.git')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return '';
    }
}
