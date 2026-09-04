<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;
use Ai\Agent\Lsp\LspClient;
use Ai\Helpers\Shell;

/**
 * lsp 工具——把语言服务器的精确定义/引用/悬停接给模型
 *
 * CodeIndex 是词法级的（自述「callers 可能不准，当作重构唯一依据不行」），
 * 而语言服务器有完整类型系统：改接口找全部实现、跳定义、看真实签名，只有它做得准。
 *
 * 渐进增强：语言服务器是**可选**外部程序，不进 composer 依赖。探测不到就直接
 * 告诉模型「未安装」，让它退回 grep / code_index，而不是静默给错答案。
 *
 * 装配（不在 all() 里，因为要按项目语言选服务器）：
 * ```php
 * use Ai\Agent\Tools\LspTool;
 * use Ai\Agent\Tools\PathSafety;
 *
 * $agent->tools(['lsp' => new LspTool(
 *     new PathSafety('/var/www/project'),
 *     'intelephense', ['--stdio'],                 // PHP
 *     ['rootPath' => '/var/www/project']
 * )]);
 * // 其它语言：gopls / typescript-language-server --stdio / pylsp / rust-analyzer …
 * ```
 *
 * 位置约定：`line` 用 **1 基**（与 read_file / grep 的行号一致），
 * `character` 用 **0 基**（LSP 原生列号）。
 */
class LspTool implements AgentToolInterface
{
    /** @var PathSafety */
    protected $pathSafety;

    /** @var string 语言服务器可执行程序 */
    protected $command;

    /** @var string[] 启动参数 */
    protected $args;

    /** @var array<string, mixed> LspClient 选项 */
    protected $options;

    /** @var LspClient|null 惰性创建，跨调用复用（省掉每次握手与索引） */
    protected $client = null;

    /**
     * @param PathSafety $pathSafety
     * @param string $command
     * @param string[] $args
     * @param array<string, mixed> $options rootPath / timeout / env / cwd
     */
    public function __construct(PathSafety $pathSafety, $command, array $args = [], array $options = [])
    {
        $this->pathSafety = $pathSafety;
        $this->command = (string) $command;
        $this->args = array_values(array_map('strval', $args));
        if (!isset($options['rootPath'])) {
            $options['rootPath'] = rtrim($pathSafety->rootDir(), '/');
        }
        $this->options = $options;
    }

    public function name()
    {
        return 'lsp';
    }

    public function description()
    {
        return '用语言服务器做精确的代码导航：definition 跳定义、references 找全部引用、'
            . 'hover 看类型与签名、symbols 列文件内符号。比 grep / code_index 准（有类型系统），'
            . '改接口找实现、确认调用方时优先用它。line 从 1 起，character 从 0 起。';
    }

    public function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type'        => 'string',
                    'description' => 'definition / references / hover / symbols',
                    'default'     => 'definition',
                ],
                'path' => [
                    'type'        => 'string',
                    'description' => '文件路径（相对工作区）',
                ],
                'line' => [
                    'type'        => 'integer',
                    'description' => '行号，从 1 起（symbols 不需要）',
                ],
                'character' => [
                    'type'        => 'integer',
                    'description' => '列号，从 0 起（symbols 不需要）',
                    'default'     => 0,
                ],
                'include_declaration' => [
                    'type'        => 'boolean',
                    'description' => 'references 是否包含声明本身',
                    'default'     => true,
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $input, ToolContext $context)
    {
        $action = isset($input['action']) ? (string) $input['action'] : 'definition';
        $path   = isset($input['path']) ? (string) $input['path'] : '';
        $line   = isset($input['line']) ? (int) $input['line'] : 0;
        $char   = isset($input['character']) ? (int) $input['character'] : 0;

        if ($path === '') {
            return ToolResult::error('参数 path 不能为空');
        }
        if (!in_array($action, ['definition', 'references', 'hover', 'symbols'], true)) {
            return ToolResult::error('未知 action：' . $action . '（definition/references/hover/symbols）');
        }
        if ($action !== 'symbols' && $line < 1) {
            return ToolResult::error('参数 line 必填且从 1 起');
        }

        if (!$this->serverAvailable()) {
            return ToolResult::error(
                '未安装语言服务器 ' . $this->command . '，lsp 不可用。'
                . '请改用 grep / code_index 定位，或先安装该语言服务器。'
            );
        }

        try {
            $abs = $this->pathSafety->resolve($path);
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }
        if (!is_file($abs)) {
            return ToolResult::error('文件不存在：' . $path);
        }

        $client = $this->client();
        if (!$client->initialize()) {
            return ToolResult::error('语言服务器握手失败：' . $this->command);
        }

        if ($action === 'hover') {
            $text = $client->hover($abs, $line, $char);
            if ($text === '') {
                return ToolResult::success('该位置没有悬停信息', ['action' => $action, 'path' => $path]);
            }
            return ToolResult::success($text, ['action' => $action, 'path' => $path]);
        }

        if ($action === 'symbols') {
            $syms = $client->documentSymbols($abs);
            if (!$syms) {
                return ToolResult::success('未取到符号（服务器可能仍在索引，或该文件无符号）', [
                    'action' => $action, 'path' => $path, 'count' => 0,
                ]);
            }
            $lines = [];
            foreach ($syms as $s) {
                $lines[] = $path . ':' . $s['line'] . '  ' . $s['name'] . '  (kind=' . $s['kind'] . ')';
            }
            return ToolResult::success(
                count($syms) . " 个符号：\n" . implode("\n", $lines),
                ['action' => $action, 'path' => $path, 'count' => count($syms)]
            );
        }

        $locs = $action === 'definition'
            ? $client->definition($abs, $line, $char)
            : $client->references($abs, $line, $char, !isset($input['include_declaration']) || !empty($input['include_declaration']));

        if (!$locs) {
            return ToolResult::success(
                $action === 'definition' ? '未找到定义（可能位置不对，或服务器仍在索引）' : '未找到引用',
                ['action' => $action, 'path' => $path, 'count' => 0]
            );
        }

        $root = rtrim($this->pathSafety->rootDir(), '/') . '/';
        $lines = [];
        foreach ($locs as $l) {
            $f = $l['file'];
            if (strpos($f, $root) === 0) {
                $f = substr($f, strlen($root));   // 转相对路径，省 token 也更好读
            }
            $lines[] = $f . ':' . $l['line'] . ':' . $l['character'];
        }
        return new ToolResult([
            'success'  => true,
            'content'  => count($locs) . ' 处' . ($action === 'definition' ? '定义' : '引用') . "：\n" . implode("\n", $lines),
            'metadata' => ['action' => $action, 'path' => $path, 'count' => count($locs)],
            'display'  => 'lsp ' . $action . ' ' . $path . ':' . $line,
        ]);
    }

    /**
     * 语言服务器是否可用
     *
     * 命令含路径分隔符时按可执行文件判断，裸名字才去 PATH 里找——
     * 否则给了绝对路径反而查不到。
     *
     * @return bool
     */
    protected function serverAvailable()
    {
        if (strpos($this->command, '/') !== false || strpos($this->command, '\\') !== false) {
            return is_file($this->command) && is_executable($this->command);
        }
        return Shell::hasBinary($this->command);
    }

    /**
     * 惰性创建并复用客户端——语言服务器启动与索引都很贵，不能每次调用重来
     *
     * @return LspClient
     */
    protected function client()
    {
        if ($this->client === null) {
            $this->client = new LspClient($this->command, $this->args, $this->options);
        }
        return $this->client;
    }

    /**
     * 注入客户端（测试用）
     *
     * @param LspClient $client
     * @return $this
     */
    public function setClient(LspClient $client)
    {
        $this->client = $client;
        return $this;
    }

    /**
     * 关闭语言服务器（Agent 结束时调用，避免留下孤儿进程）
     *
     * @return void
     */
    public function shutdown()
    {
        if ($this->client !== null) {
            $this->client->close();
            $this->client = null;
        }
    }
}
