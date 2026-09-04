<?php
namespace Ai\Agent\Lsp;

use Ai\Agent\Mcp\McpStdioTransport;

/**
 * 极简 LSP 客户端——把语言服务器的精确定义/引用/悬停接进 Agent
 *
 * 这是 dev.md 第三梯队「LSP」那一项：CodeIndex 是词法级的（自述「callers 可能不准」），
 * 而语言服务器有完整类型系统，改接口找全部实现、跳定义这类事只有它能做准。
 *
 * 复用 {@see McpStdioTransport} 做传输——LSP 与 MCP 都是「Content-Length 分帧 +
 * JSON-RPC 2.0 over stdio」，同一套机制，没必要再写一遍分帧与超时。
 *
 * 渐进增强：语言服务器是**可选**外部程序（intelephense / phpactor / gopls /
 * typescript-language-server 等），探测不到就明确报错，绝不进 composer 依赖。
 *
 * v1 只做请求/响应类能力（definition / references / hover / documentSymbol）。
 * 诊断（diagnostics）走服务器主动推送的通知，传输层当前会跳过通知，故未支持——
 * 见类末尾说明。
 */
class LspClient
{
    /** @var McpStdioTransport */
    protected $transport;

    /** @var string 项目根绝对路径 */
    protected $rootPath;

    /** @var int 单次请求超时（秒） */
    protected $timeout;

    /** @var bool 是否已完成 initialize 握手 */
    protected $initialized = false;

    /** @var array<string, bool> 已 didOpen 的文件 URI */
    protected $opened = [];

    /** @var int JSON-RPC 自增 id */
    protected $seq = 0;

    /** @var array<string, mixed> initialize 返回的服务器能力 */
    protected $capabilities = [];

    /**
     * @param string $command 语言服务器可执行程序
     * @param string[] $args 启动参数（如 ['--stdio']）
     * @param array<string, mixed> $options rootPath / timeout / env / cwd
     */
    public function __construct($command, array $args = [], array $options = [])
    {
        $root = isset($options['rootPath']) ? (string) $options['rootPath'] : '';
        if ($root === '') {
            $cwd = getcwd();
            $root = is_string($cwd) ? $cwd : '.';
        }
        $this->rootPath = rtrim(str_replace('\\', '/', $root), '/');
        $this->timeout = isset($options['timeout']) ? max(1, (int) $options['timeout']) : 20;

        $tOpts = [];
        if (isset($options['env']) && is_array($options['env'])) {
            $tOpts['env'] = $options['env'];
        }
        $tOpts['cwd'] = isset($options['cwd']) ? (string) $options['cwd'] : $this->rootPath;
        $this->transport = new McpStdioTransport($command, $args, $tOpts);
    }

    /**
     * 完成 initialize / initialized 握手（幂等）
     *
     * @return bool 成功与否
     */
    public function initialize()
    {
        if ($this->initialized) {
            return true;
        }
        try {
            $res = $this->request('initialize', [
                'processId' => null,
                'rootPath'  => $this->rootPath,
                'rootUri'   => $this->pathToUri($this->rootPath),
                'capabilities' => [
                    'textDocument' => [
                        'definition'     => ['dynamicRegistration' => false],
                        'references'     => ['dynamicRegistration' => false],
                        'hover'          => ['contentFormat' => ['plaintext', 'markdown']],
                        'documentSymbol' => ['dynamicRegistration' => false],
                    ],
                ],
                'workspaceFolders' => [[
                    'uri'  => $this->pathToUri($this->rootPath),
                    'name' => basename($this->rootPath),
                ]],
            ]);
        } catch (\Exception $e) {
            \Ai\Helpers\Log::warning('LSP initialize 失败', ['error' => $e->getMessage()]);
            return false;
        }
        if (!is_array($res) || !isset($res['result'])) {
            return false;
        }
        if (isset($res['result']['capabilities']) && is_array($res['result']['capabilities'])) {
            $this->capabilities = $res['result']['capabilities'];
        }
        $this->transport->notify(['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => new \stdClass()]);
        $this->initialized = true;
        return true;
    }

    /**
     * 服务器声明的能力（initialize 的返回）
     *
     * @return array<string, mixed>
     */
    public function capabilities()
    {
        return $this->capabilities;
    }

    /**
     * 跳转到定义
     *
     * @param string $file 绝对路径
     * @param int $line 1 基行号
     * @param int $character 0 基列号
     * @return array<int, array{file: string, line: int, character: int}>
     */
    public function definition($file, $line, $character)
    {
        $res = $this->docRequest('textDocument/definition', $file, $line, $character);
        return $this->normalizeLocations($res);
    }

    /**
     * 查找全部引用
     *
     * @param string $file
     * @param int $line 1 基
     * @param int $character 0 基
     * @param bool $includeDeclaration
     * @return array<int, array{file: string, line: int, character: int}>
     */
    public function references($file, $line, $character, $includeDeclaration = true)
    {
        $res = $this->docRequest('textDocument/references', $file, $line, $character, [
            'context' => ['includeDeclaration' => (bool) $includeDeclaration],
        ]);
        return $this->normalizeLocations($res);
    }

    /**
     * 悬停信息（类型/签名/文档）
     *
     * @param string $file
     * @param int $line 1 基
     * @param int $character 0 基
     * @return string 取不到返回 ''
     */
    public function hover($file, $line, $character)
    {
        $res = $this->docRequest('textDocument/hover', $file, $line, $character);
        if (!is_array($res) || !isset($res['result']) || !is_array($res['result'])) {
            return '';
        }
        return $this->flattenHover($res['result']);
    }

    /**
     * 文档内符号列表
     *
     * @param string $file
     * @return array<int, array{name: string, kind: int, line: int}>
     */
    public function documentSymbols($file)
    {
        if (!$this->ensureOpen($file)) {
            return [];
        }
        try {
            $res = $this->request('textDocument/documentSymbol', [
                'textDocument' => ['uri' => $this->pathToUri($file)],
            ]);
        } catch (\Exception $e) {
            return [];
        }
        $out = [];
        if (!is_array($res) || !isset($res['result']) || !is_array($res['result'])) {
            return $out;
        }
        foreach ($res['result'] as $sym) {
            if (!is_array($sym) || !isset($sym['name'])) {
                continue;
            }
            // DocumentSymbol（有 range）与 SymbolInformation（有 location.range）两种形态
            $line = 0;
            if (isset($sym['range']['start']['line'])) {
                $line = (int) $sym['range']['start']['line'] + 1;
            } elseif (isset($sym['location']['range']['start']['line'])) {
                $line = (int) $sym['location']['range']['start']['line'] + 1;
            }
            $out[] = [
                'name' => (string) $sym['name'],
                'kind' => isset($sym['kind']) ? (int) $sym['kind'] : 0,
                'line' => $line,
            ];
        }
        return $out;
    }

    /**
     * 关闭语言服务器
     *
     * @return void
     */
    public function close()
    {
        if ($this->initialized) {
            try {
                $this->transport->notify(['jsonrpc' => '2.0', 'method' => 'exit']);
            } catch (\Exception $e) {
                // 关闭阶段的异常无需上报
            }
        }
        $this->transport->close();
        $this->initialized = false;
        $this->opened = [];
    }

    // ===== 内部 =====

    /**
     * 带位置的文档请求（自动 initialize + didOpen）
     *
     * @param string $method
     * @param string $file
     * @param int $line 1 基
     * @param int $character 0 基
     * @param array<string, mixed> $extra
     * @return array<string, mixed>|null
     */
    protected function docRequest($method, $file, $line, $character, array $extra = [])
    {
        if (!$this->ensureOpen($file)) {
            return null;
        }
        $params = array_merge([
            'textDocument' => ['uri' => $this->pathToUri($file)],
            'position'     => [
                'line'      => max(0, (int) $line - 1),   // LSP 是 0 基
                'character' => max(0, (int) $character),
            ],
        ], $extra);
        try {
            return $this->request($method, $params);
        } catch (\Exception $e) {
            \Ai\Helpers\Log::warning('LSP 请求失败', ['method' => $method, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 确保已握手且该文件已 didOpen
     *
     * @param string $file
     * @return bool
     */
    protected function ensureOpen($file)
    {
        if (!$this->initialize()) {
            return false;
        }
        $uri = $this->pathToUri($file);
        if (isset($this->opened[$uri])) {
            return true;
        }
        if (!is_file($file) || !is_readable($file)) {
            return false;
        }
        $text = file_get_contents($file);
        if ($text === false) {
            return false;
        }
        $this->transport->notify([
            'jsonrpc' => '2.0',
            'method'  => 'textDocument/didOpen',
            'params'  => [
                'textDocument' => [
                    'uri'        => $uri,
                    'languageId' => $this->languageIdOf($file),
                    'version'    => 1,
                    'text'       => $text,
                ],
            ],
        ]);
        $this->opened[$uri] = true;
        return true;
    }

    /**
     * 发一次 JSON-RPC 请求
     *
     * @param string $method
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    protected function request($method, array $params)
    {
        $this->seq++;
        return $this->transport->request([
            'jsonrpc' => '2.0',
            'id'      => $this->seq,
            'method'  => $method,
            'params'  => $params,
        ], $this->timeout);
    }

    /**
     * 把 definition/references 的多种返回形态统一成位置列表
     *
     * 可能是 Location、Location[]、LocationLink[]（有 targetUri/targetRange）。
     *
     * @param array<string, mixed>|null $res
     * @return array<int, array{file: string, line: int, character: int}>
     */
    protected function normalizeLocations($res)
    {
        $out = [];
        if (!is_array($res) || !isset($res['result']) || !is_array($res['result'])) {
            return $out;
        }
        $result = $res['result'];
        // 单个 Location（有 uri 键）也包成数组统一处理
        if (isset($result['uri'])) {
            $result = [$result];
        }
        foreach ($result as $loc) {
            if (!is_array($loc)) {
                continue;
            }
            $uri = '';
            $range = null;
            if (isset($loc['uri'])) {
                $uri = (string) $loc['uri'];
                $range = isset($loc['range']) ? $loc['range'] : null;
            } elseif (isset($loc['targetUri'])) {
                $uri = (string) $loc['targetUri'];
                $range = isset($loc['targetSelectionRange']) ? $loc['targetSelectionRange']
                    : (isset($loc['targetRange']) ? $loc['targetRange'] : null);
            }
            if ($uri === '') {
                continue;
            }
            $out[] = [
                'file'      => $this->uriToPath($uri),
                'line'      => isset($range['start']['line']) ? ((int) $range['start']['line'] + 1) : 0,
                'character' => isset($range['start']['character']) ? (int) $range['start']['character'] : 0,
            ];
        }
        return $out;
    }

    /**
     * hover 结果可能是字符串、MarkedString、MarkupContent 或它们的数组
     *
     * @param array<string, mixed> $result
     * @return string
     */
    protected function flattenHover(array $result)
    {
        if (!isset($result['contents'])) {
            return '';
        }
        $c = $result['contents'];
        if (is_string($c)) {
            return trim($c);
        }
        if (is_array($c)) {
            if (isset($c['value'])) {
                return trim((string) $c['value']);
            }
            $parts = [];
            foreach ($c as $item) {
                if (is_string($item)) {
                    $parts[] = $item;
                } elseif (is_array($item) && isset($item['value'])) {
                    $parts[] = (string) $item['value'];
                }
            }
            return trim(implode("\n", $parts));
        }
        return '';
    }

    /**
     * @param string $path
     * @return string
     */
    protected function pathToUri($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        if (strpos($path, '/') !== 0) {
            $path = '/' . ltrim($path, '/');
        }
        // 路径分段编码，保留分隔符
        $parts = array_map('rawurlencode', explode('/', $path));
        return 'file://' . implode('/', $parts);
    }

    /**
     * @param string $uri
     * @return string
     */
    protected function uriToPath($uri)
    {
        $uri = (string) $uri;
        if (strpos($uri, 'file://') === 0) {
            $uri = substr($uri, 7);
        }
        return rawurldecode($uri);
    }

    /**
     * 由扩展名猜 languageId
     *
     * @param string $file
     * @return string
     */
    protected function languageIdOf($file)
    {
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        $map = [
            'php' => 'php', 'js' => 'javascript', 'jsx' => 'javascriptreact',
            'ts' => 'typescript', 'tsx' => 'typescriptreact', 'go' => 'go',
            'py' => 'python', 'rs' => 'rust', 'java' => 'java', 'rb' => 'ruby',
            'c' => 'c', 'h' => 'c', 'cpp' => 'cpp', 'hpp' => 'cpp', 'cs' => 'csharp',
        ];
        return isset($map[$ext]) ? $map[$ext] : 'plaintext';
    }
}
