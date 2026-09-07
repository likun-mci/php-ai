<?php
namespace Ai\Agent\Verification;

use Ai\Helpers\Shell;

/**
 * BaseVerifier——验证器基类
 *
 * 提供各验证器通用的工具方法：命令执行、路径检查等。
 */
abstract class BaseVerifier implements VerifierInterface
{
    /** @var bool 是否启用 */
    protected $enabled = true;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        if (isset($options['enabled'])) {
            $this->enabled = (bool) $options['enabled'];
        }
    }

    /**
     * 执行 shell 命令并返回结果
     *
     * @param string $command
     * @return array{code: int, output: string}
     */
    protected function exec($command)
    {
        if (!$this->canRunCommands()) {
            return ['code' => -1, 'output' => Shell::disabledMessage()];
        }
        $res = Shell::run($command . ' 2>&1');
        return [
            'code' => $res['code'],
            'output' => $res['out'],
        ];
    }

    /**
     * 当前环境能不能跑外部命令
     *
     * 生产环境常在 php.ini 的 disable_functions 里禁掉 exec / proc_open。
     * 依赖外部命令的验证器应当先问这一句，问不到就按「跳过」处理——
     * 跑不了检查不等于代码有问题，报 failed 会让 Agent 白白重试。
     *
     * @return bool
     */
    protected function canRunCommands()
    {
        return Shell::canRunCommand();
    }

    /**
     * 检查文件是否存在
     *
     * @param string $path
     * @return bool
     */
    protected function fileExists($path)
    {
        return $path !== '' && file_exists($path);
    }

    /**
     * 从上下文中获取 file_path
     *
     * @param array<string, mixed> $context
     * @return string
     */
    protected function getFilePath(array $context)
    {
        return isset($context['file_path']) ? (string) $context['file_path'] : '';
    }

    /**
     * @param bool $enabled
     * @return $this
     */
    public function setEnabled($enabled)
    {
        $this->enabled = (bool) $enabled;
        return $this;
    }

    /**
     * @return bool
     */
    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * @param string $toolName
     * @return bool
     */
    public function supports($toolName)
    {
        return in_array($toolName, $this->supportedTools(), true);
    }
}