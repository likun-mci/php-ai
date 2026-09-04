<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;
use Ai\Helpers\Text;

/**
 * bash_output 工具——读取/结束由 `bash(run_in_background=true)` 启动的后台命令
 *
 * 配合 {@see BackgroundShells}：read 返回**增量**输出（上次读取之后的新内容），
 * 适合轮询长任务进度；进程结束后会带上退出码。
 *
 * 与 bash 同属高风险档（能结束进程），权限上按 bash 的规则走。
 */
class BashOutputTool implements AgentToolInterface
{
    /** @var int 单次返回的输出字节上限 */
    protected $maxOutputBytes;

    /**
     * @param int $maxOutputBytes
     */
    public function __construct($maxOutputBytes = 100000)
    {
        $this->maxOutputBytes = max(1024, (int) $maxOutputBytes);
    }

    public function name()
    {
        return 'bash_output';
    }

    public function description()
    {
        return '读取后台命令的新增输出（由 bash 的 run_in_background 启动）。'
            . 'action=read 取增量输出（默认）、kill 结束进程、list 列出全部后台任务。';
    }

    public function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'bash_id' => [
                    'type'        => 'string',
                    'description' => 'bash 后台启动时返回的句柄 id；action=list 时可省略',
                ],
                'action' => [
                    'type'        => 'string',
                    'description' => 'read（默认，取增量输出）/ kill（结束）/ list（列出全部）',
                    'default'     => 'read',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $input, ToolContext $context)
    {
        $action = isset($input['action']) ? (string) $input['action'] : 'read';
        $id     = isset($input['bash_id']) ? (string) $input['bash_id'] : '';

        if ($action === 'list') {
            $all = BackgroundShells::all();
            if (!$all) {
                return ToolResult::success('当前没有后台任务', ['count' => 0]);
            }
            $lines = [];
            foreach ($all as $s) {
                $lines[] = $s['id'] . '  ' . ($s['running'] ? '运行中' : ('已结束(exit=' . $s['exit_code'] . ')'))
                    . '  ' . $s['duration'] . 's  ' . mb_substr($s['command'], 0, 60);
            }
            return ToolResult::success(implode("\n", $lines), ['count' => count($all)]);
        }

        if ($id === '') {
            return ToolResult::error('参数 bash_id 不能为空');
        }
        if (!BackgroundShells::has($id)) {
            return ToolResult::error('未知的 bash_id：' . $id . '（可能已结束并被清理，或从未存在）');
        }

        if ($action === 'kill') {
            BackgroundShells::kill($id);
            return ToolResult::success('已结束后台任务：' . $id, ['bash_id' => $id, 'killed' => true]);
        }

        $res = BackgroundShells::read($id, true);
        if ($res === null) {
            return ToolResult::error('未知的 bash_id：' . $id);
        }

        $out = $res['output'];
        if ($res['stderr'] !== '') {
            $out .= ($out !== '' ? "\n" : '') . "STDERR:\n" . $res['stderr'];
        }
        $truncated = false;
        if (strlen($out) > $this->maxOutputBytes) {
            $out = Text::cutBytes($out, $this->maxOutputBytes);
            $truncated = true;
        }

        $status = $res['running']
            ? '仍在运行（' . $res['duration'] . 's）'
            : '已结束，退出码 ' . $res['exit_code'] . '（' . $res['duration'] . 's）';
        $body = $out !== '' ? $out : '(暂无新输出)';

        return new ToolResult([
            'success'    => true,
            'content'    => $status . "\n---\n" . $body . ($truncated ? "\n[输出已截断]" : ''),
            'metadata'   => [
                'bash_id'   => $id,
                'running'   => $res['running'],
                'exit_code' => $res['exit_code'],
                'duration'  => $res['duration'],
                'truncated' => $truncated,
            ],
            'is_partial' => $truncated,
            'display'    => 'bash_output ' . $id . ($res['running'] ? ' (运行中)' : ' (已结束)'),
        ]);
    }
}
