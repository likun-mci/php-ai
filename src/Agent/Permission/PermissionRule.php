<?php
namespace Ai\Agent\Permission;

/**
 * 权限规则
 *
 * 对「工具 + 参数模式」的组合做匹配。支持通配符匹配工具参数（如 path 前缀）。
 *
 * 规则分两级：
 *   - 工具级：allowTool('read_file') / denyTool('bash')
 *   - 参数级：allowTool('write_file', ['path' => '/var/www/project/*'])
 */
class PermissionRule
{
    /** @var string allow | deny */
    protected $action;

    /** @var string 工具名 */
    protected $tool;

    /** @var array<string, string> 参数模式（值支持 * 通配） */
    protected $argPatterns = [];

    /**
     * @param string $action allow | deny
     * @param string $tool 工具名
     * @param array<string, string> $argPatterns 参数模式
     */
    public function __construct($action, $tool, array $argPatterns = [])
    {
        $this->action = $action;
        $this->tool = (string) $tool;
        $this->argPatterns = $argPatterns;
    }

    /** @return string */
    public function getAction() { return $this->action; }
    /** @return string */
    public function getTool() { return $this->tool; }
    /** @return array<string, string> */
    public function getArgPatterns() { return $this->argPatterns; }

    /**
     * 判断该规则是否匹配某次工具调用
     *
     * @param string $tool 工具名
     * @param array<string, mixed> $input 工具参数
     * @return bool
     */
    public function matches($tool, array $input)
    {
        if ($tool !== $this->tool) {
            return false;
        }
        // 无参数模式 = 匹配该工具的所有调用
        if (!$this->argPatterns) {
            return true;
        }
        foreach ($this->argPatterns as $key => $pattern) {
            $value = isset($input[$key]) ? (string) $input[$key] : '';
            if (!self::matchPattern($pattern, $value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * glob 风格通配符匹配
     *
     * @param string $pattern 支持 * 与 ?
     * @param string $value
     * @return bool
     */
    public static function matchPattern($pattern, $value)
    {
        if ($pattern === $value) {
            return true;
        }
        // 转义正则
        $regex = preg_quote($pattern, '/');
        $regex = str_replace(['\*', '\?'], ['.*', '.'], $regex);
        return preg_match('/^' . $regex . '$/', $value) === 1;
    }
}