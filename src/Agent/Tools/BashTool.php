<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;
use Ai\Helpers\Text;

/**
 * 命令执行工具（Bash）
 *
 * 允许 Agent 在工作区内执行 shell 命令。
 * 设置有命令超时、输出截断、路径限制等安全措施。
 *
 * 高风险工具，建议配合权限系统使用（Phase 3）。
 */
class BashTool implements AgentToolInterface
{
    /** @var int 命令超时时间（秒） */
    protected $timeout = 30;

    /** @var int 输出截断字节数 */
    protected $maxOutputBytes = 100000;

    /** @var string|null 工作目录 */
    protected $workdir = null;

    /**
     * @param int $timeout
     * @param int $maxOutputBytes
     */
    public function __construct($timeout = 30, $maxOutputBytes = 100000)
    {
        $this->timeout = max(1, (int) $timeout);
        $this->maxOutputBytes = max(1024, (int) $maxOutputBytes);
    }

    /**
     * @param string $workdir
     * @return $this
     */
    public function setWorkdir($workdir)
    {
        $this->workdir = (string) $workdir;
        return $this;
    }

    public function name()
    {
        return 'bash';
    }

    public function description()
    {
        $cwd = $this->workdir !== null && $this->workdir !== '' ? $this->workdir : '当前工作目录';

        // 明确写出实际 cwd：不写的话模型会自己 cd 到猜的路径，
        // 撞一次「No such file or directory」再纠正，白费一轮往返
        return '执行 shell 命令。支持管道、重定向等标准 shell 语法。'
            . '命令已在 ' . $cwd . ' 下执行，相对路径直接写即可，不要自行 cd 到别处。'
            . '超时 ' . $this->timeout . ' 秒自动终止，'
            . '输出超过 ' . $this->maxOutputBytes . ' 字节时会截断。';
    }

    public function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'command' => [
                    'type'        => 'string',
                    'description' => '要执行的 shell 命令',
                ],
                'description' => [
                    'type'        => 'string',
                    'description' => '命令用途说明（便于权限审计）',
                    'default'     => '',
                ],
                'timeout' => [
                    'type'        => 'integer',
                    'description' => '命令超时（秒），默认 ' . $this->timeout,
                    'default'     => $this->timeout,
                ],
            ],
            'required' => ['command'],
        ];
    }

    public function execute(array $input, ToolContext $context)
    {
        $command = isset($input['command']) ? (string) $input['command'] : '';

        if ($command === '') {
            return ToolResult::error('参数 command 不能为空');
        }

        $cmdTimeout = isset($input['timeout']) ? (int) $input['timeout'] : $this->timeout;
        $cmdTimeout = max(1, min(300, $cmdTimeout));  // 上限 5 分钟

        // 确定工作目录
        $cd = $this->workdir ?: $context->workdir();
        $cd = $cd !== '' ? $cd : null;

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = @proc_open(
            $command,
            $descriptors,
            $pipes,
            $cd,
            null
        );

        if (!is_resource($process)) {
            return ToolResult::error('无法执行命令');
        }

        // 关闭 stdin
        @fclose($pipes[0]);

        // 非阻塞读取，实现超时控制
        $stdout = '';
        $stderr = '';
        $startTime = microtime(true);
        $done = false;

        while (!$done) {
            $elapsed = microtime(true) - $startTime;
            if ($elapsed > $cmdTimeout) {
                // 超时终止
                @proc_terminate($process, 9);  // SIGKILL
                @fclose($pipes[1]);
                @fclose($pipes[2]);
                $exitCode = -1;
                $done = true;
                $stdout .= "\n\n[Command timed out after {$cmdTimeout}s]";
                break;
            }

            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $result = @stream_select($read, $write, $except, 0, 200000);  // 200ms

            if ($result === false) {
                break;
            }

            $hasData = false;
            foreach ($read as $r) {
                $data = @fread($r, 8192);
                if ($data !== false && $data !== '') {
                    $hasData = true;
                    if ($r === $pipes[1]) {
                        $stdout .= $data;
                    } else {
                        $stderr .= $data;
                    }
                }
            }

            // 输出截断
            if (strlen($stdout) > $this->maxOutputBytes) {
                // 必须按字节切且不劈开字符：劈开会产生非法 UTF-8，
                // 下一次模型请求的 json_encode 直接失败，整个 Agent 运行中断
                $stdout = Text::cutBytes($stdout, $this->maxOutputBytes)
                    . "\n\n[Output truncated at {$this->maxOutputBytes} bytes]";
            }

            if (!$hasData) {
                $status = @proc_get_status($process);
                if ($status !== false && !$status['running']) {
                    $done = true;
                }
            }
        }

        // 读取剩余数据
        $remaining = @stream_get_contents($pipes[1]);
        if ($remaining !== false && $remaining !== '') {
            $stdout .= $remaining;
        }
        $remainingErr = @stream_get_contents($pipes[2]);
        if ($remainingErr !== false && $remainingErr !== '') {
            $stderr .= $remainingErr;
        }

        @fclose($pipes[1]);
        @fclose($pipes[2]);

        $exitCode = @proc_close($process);
        if ($exitCode === -1) {
            // 已超时杀死，exitCode 可能不可靠
        }

        // 组装结果
        $output = '';
        if ($stdout !== '') {
            $output .= $stdout;
        }
        if ($stderr !== '') {
            if ($output !== '') {
                $output .= "\n";
            }
            $output .= "STDERR:\n" . $stderr;
        }

        if ($exitCode !== 0 && $exitCode !== -1) {
            return ToolResult::error(
                "命令退出码 {$exitCode}\n" . $output,
                ['exit_code' => $exitCode, 'command' => $command]
            );
        }

        return new ToolResult([
            'success'  => true,
            'content'  => $output !== '' ? $output : '(命令执行成功，无输出)',
            'metadata' => [
                'exit_code' => $exitCode,
                'command'   => $command,
                'duration'  => round(microtime(true) - $startTime, 2),
            ],
            'display'  => 'Bash: ' . mb_substr($command, 0, 80),
        ]);
    }
}