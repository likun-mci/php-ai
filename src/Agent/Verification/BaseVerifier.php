<?php
namespace Ai\Agent\Verification;

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
        $output = [];
        $code = -1;
        exec($command . ' 2>&1', $output, $code);
        return [
            'code' => $code,
            'output' => implode("\n", $output),
        ];
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