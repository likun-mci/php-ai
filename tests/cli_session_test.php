<?php
/**
 * ClaudeCodeSession 双工会话测试
 *
 * cli_test.php 只覆盖参数渲染这类纯字符串逻辑，真正难写对的是**进程与协议**：
 * 非阻塞投递、事件泵、写队列、权限回调、中断、杀进程。这些一旦回归，
 * 表现是"卡住"或"进程杀不掉"，靠人工点是点不出来的。
 *
 * 本测试用 tests/fixtures/fake_claude.php 冒充 claude CLI：它实现了双工
 * stream-json 里被本库用到的那部分协议，因此整条链路（proc_open → 写 stdin →
 * 读 stdout → 派发事件 → 收轮）都是真跑，只是不联网、不烧额度。
 *
 * 运行：php tests/cli_session_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Cli\ClaudeCode;
use Ai\Cli\ClaudeCodeSession;
use Ai\Exceptions\ProcessException;

define('FAKE_CLI', __DIR__ . '/fixtures/fake_claude.php');
define('HANG_CLI', __DIR__ . '/fixtures/hang.php');

function pad(string $t, int $w): string
{
    $n = $w - mb_strwidth($t, 'UTF-8');
    return $t . ($n > 0 ? str_repeat(' ', $n) : '');
}

$failures = [];
function check(bool $ok, string $name, string $detail = ''): void
{
    global $failures;
    if (!$ok) { $failures[] = $name . ($detail !== '' ? "（{$detail}）" : ''); }
    echo pad($name, 46), $ok ? "✓\n" : "✗ {$detail}\n";
}

/**
 * 把 claude 换成假 CLI 的会话类：只改"执行哪个程序"，其余全部走真实代码路径
 */
class FakeCliSession extends ClaudeCodeSession
{
    /** @var string 假 CLI 把自己的 PID 写到这个文件，供 kill 测试核对 */
    public $pidFile = '';

    public function getBinary(): string
    {
        return PHP_BINARY;
    }

    protected function buildBaseCommand(string $binary, string $workdir, array $options, string $suffix = ''): string
    {
        // 走真实的 execPrefix()：kill 测试要验的正是"信号能不能直达 CLI 本身"，
        // 少了 exec 就会多一层 sh，proc_terminate 打不到假 CLI
        return $this->execPrefix() . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(FAKE_CLI)
            . ($this->pidFile !== '' ? ' ' . escapeshellarg($this->pidFile) : '');
    }

    public function procPid(): int
    {
        if (!is_resource($this->proc)) { return 0; }
        $status = @proc_get_status($this->proc);
        return is_array($status) ? (int) $status['pid'] : 0;
    }

    public function pendingWriteBytes(): int
    {
        return strlen($this->writeBuf);
    }
}

/**
 * 一次性模式：跑一个永不退出的假 CLI，用来验证超时后进程是否真的被收掉
 */
class HangingCli extends ClaudeCode
{
    /** @var string */
    public $pidFile = '';

    public function getBinary(): string
    {
        return PHP_BINARY;
    }

    public function buildCommand(string $binary, string $promptFile, string $workdir = '', array $options = []): string
    {
        return $this->execPrefix() . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(HANG_CLI)
            . ' ' . escapeshellarg($this->pidFile);
    }
}

/** 事件收集器 */
class EventLog
{
    /** @var array<int, array{0:string,1:mixed}> */
    public $items = [];

    public function handler(): callable
    {
        $self = $this;
        return function ($event, $data) use ($self) { $self->items[] = [$event, $data]; };
    }

    public function names(): array
    {
        return array_map(function ($i) { return $i[0]; }, $this->items);
    }

    public function has(string $name): bool
    {
        return in_array($name, $this->names(), true);
    }

    public function first(string $name)
    {
        foreach ($this->items as $item) {
            if ($item[0] === $name) { return $item[1]; }
        }
        return null;
    }

    public function count(string $name): int
    {
        return count(array_filter($this->names(), function ($n) use ($name) { return $n === $name; }));
    }
}

function processAlive(int $pid): bool
{
    if ($pid <= 0) { return false; }
    if (function_exists('posix_kill')) { return @posix_kill($pid, 0); }
    if (is_dir('/proc')) { return is_dir('/proc/' . $pid); }
    return trim((string) @shell_exec('ps -p ' . $pid . ' -o pid= 2>/dev/null')) !== '';
}

/** 反复泵事件，直到 $until 返回 true 或超时 */
function pumpUntil(FakeCliSession $s, EventLog $log, callable $until, float $seconds = 10): bool
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $s->tick($log->handler());
        if ($until()) { return true; }
        usleep(5000);
    }
    return false;
}

// ===============================================================
// 一、阻塞式 API 不受影响（回归）
// ===============================================================
echo "=== 一、阻塞式 send()（回归） ===\n\n";

$s = new FakeCliSession(['turn_timeout' => 15]);
$log = new EventLog();
$res = $s->send('你好', $log->handler());

check($res->getContent() === '回答:你好', 'send() 拿到本轮完整回复', $res->getContent());
check($res->isSuccess(), 'success 标记正确');
check($res->getSessionId() === 'fake-session-0001', '会话 ID 回写', (string) $res->getSessionId());
check($res->getNumTurns() === 1, 'num_turns 采集', (string) $res->getNumTurns());
check($res->getDurationMs() > 0, 'duration_ms 有值');
check($res->getToolUses() && $res->getToolUses()[0]['name'] === 'Read', 'tool_use 采集');
check($s->getAvailableTools() === ['Read', 'Write', 'Bash'], 'init 事件的工具列表已存下');

$names = $log->names();
check($names[0] === 'start', '首个事件是 start', implode(',', array_slice($names, 0, 3)));
check(end($names) === 'done', '末个事件是 done');
check($log->has('result') && $log->has('text') && $log->has('tool_use'), '中间事件齐全');

$pid1 = $s->procPid();
$res2 = $s->send('第二轮');
check($s->procPid() === $pid1 && $pid1 > 0, '多轮复用同一进程（不重启）');
check($res2->getContent() === '回答:第二轮', '第二轮内容正确', $res2->getContent());
check($s->takeResult() === null, 'send() 已把结果取走，takeResult 返回 null');
$s->close();
check(!$s->isRunning(), 'close() 后进程退出');

// ===============================================================
// 二、非阻塞投递 + 宿主驱动的事件泵
// ===============================================================
echo "\n=== 二、post() / tick() / takeResult() ===\n\n";

$s = new FakeCliSession(['turn_timeout' => 15]);
$log = new EventLog();

$started = microtime(true);
$id1 = $s->post('@hold 第一条需求');
check((microtime(true) - $started) < 1.0, 'post() 立即返回，不等本轮结束');
check($id1 !== '' && strpos($id1, 'msg-') === 0, 'post() 返回本地消息 ID', $id1);
check($s->isTurnActive(), 'post() 之后处于一轮之中');

$ok = pumpUntil($s, $log, function () use ($log) { return $log->has('tool_use'); });
check($ok, 'tick() 泵出了轮内事件');
check($s->tick($log->handler()) === true, '本轮未结束时 tick() 返回 true');
check($log->has('posted'), 'posted 事件已派发');
$posted = $log->first('posted');
check($posted['id'] === $id1 && $posted['injected'] === false, 'posted 带上消息 ID，标记为非轮内插入');
$delivered = $log->first('delivered');
check($delivered !== null && $delivered['id'] === $id1, 'CLI 回显后派发 delivered 回执，ID 对得上');
check($s->takeResult() === null, '本轮未结束，takeResult 为 null');

// ---- 轮内插入 ----
$id2 = $s->post('第二条需求');
$posted2 = null;
foreach ($log->items as $item) {
    if ($item[0] === 'posted' && $item[1]['id'] === $id2) { $posted2 = $item[1]; }
}
$s->tick($log->handler());
foreach ($log->items as $item) {
    if ($item[0] === 'posted' && $item[1]['id'] === $id2) { $posted2 = $item[1]; }
}
check($posted2 !== null && $posted2['injected'] === true, '轮内投递被标记为 injected');

$ok = pumpUntil($s, $log, function () use ($s) { return !$s->isTurnActive(); });
check($ok, '轮内插入后本轮正常收口');
check($log->count('result') === 1, '整轮只产生一个 result 事件', (string) $log->count('result'));

$res = $s->takeResult();
check($res !== null, 'takeResult 拿到本轮结果');
check($res->getNumTurns() === 2, '轮内插入并入同一轮，num_turns 累计', (string) $res->getNumTurns());
check(strpos($res->getContent(), '第二条需求') !== false, '回复里含插入的那条需求', $res->getContent());
check($s->takeResult() === null, '取走即清空');
check($s->isRunning(), '收轮后进程仍在（可直接投下一轮）');

// ---- 连续下一轮 ----
$pid = $s->procPid();
$s->post('新的一轮');
pumpUntil($s, $log, function () use ($s) { return !$s->isTurnActive(); });
$res = $s->takeResult();
check($res !== null && $res->getContent() === '回答:新的一轮', '同一进程直接开下一轮', $res ? $res->getContent() : 'null');
check($s->procPid() === $pid, '第二轮没有重启进程');
$s->close();

// ===============================================================
// 三、大消息：stdin 非阻塞，整行 JSON 不被截断
// ===============================================================
echo "\n=== 三、大消息与写队列 ===\n\n";

$s = new FakeCliSession(['turn_timeout' => 20]);
$log = new EventLog();
$big = str_repeat('长', 80000);            // 240KB UTF-8，远超 64KB 管道缓冲
$started = microtime(true);
$s->post($big . ' @big');
$elapsed = microtime(true) - $started;
check($elapsed < 2.0, '超出管道缓冲的消息不会把 post() 卡住', sprintf('%.2fs', $elapsed));
check($s->pendingWriteBytes() > 0, '写不完的部分留在队列里', (string) $s->pendingWriteBytes());

$ok = pumpUntil($s, $log, function () use ($s) { return !$s->isTurnActive(); }, 20);
check($ok, '事件泵把剩余数据冲刷完并收轮');
check($s->pendingWriteBytes() === 0, '写队列已排空');
$res = $s->takeResult();
check($res !== null && mb_strlen($res->getContent()) === 100000, '大 payload 往返完整', $res ? (string) mb_strlen($res->getContent()) : 'null');
$s->close();

// ===============================================================
// 四、权限回调与中断
// ===============================================================
echo "\n=== 四、权限回调与中断 ===\n\n";

$s = new FakeCliSession(['turn_timeout' => 15]);
$asked = [];
$s->onPermission(function (array $req) use (&$asked) {
    $asked[] = $req['tool_name'];
    return '本环境禁止执行 shell 命令';
});
$log = new EventLog();
$res = $s->send('@perm 跑个命令', $log->handler());
check($asked === ['Bash'], '权限回调收到 can_use_tool 请求', implode(',', $asked));
$decision = $log->first('permission_decision');
check($decision['response']['behavior'] === 'deny', '拒绝决策被回写给 CLI');
check($res->getContent() !== '', '权限询问不影响本轮收口');

// 轮内发控制指令（停止按钮、热切模型、查花费都走这条路）不能把本轮读丢
$s3 = new FakeCliSession(['turn_timeout' => 15]);
$log3 = new EventLog();
$s3->post('@hold 先干着');
pumpUntil($s3, $log3, function () use ($log3) { return $log3->has('tool_use'); });
$resp = $s3->control(['subtype' => 'get_session_cost'], true, 5);
check(isset($resp['response']['ok']), '轮内控制指令拿到回复', json_encode($resp, JSON_UNESCAPED_UNICODE));
check($s3->isTurnActive(), '控制指令不影响本轮进行中的状态');
$s3->post('继续第二条');
pumpUntil($s3, $log3, function () use ($s3) { return !$s3->isTurnActive(); });
$res = $s3->takeResult();
check($res !== null && $res->getNumTurns() === 2, '控制指令之后本轮照常收口，事件没丢',
      $res ? (string) $res->getNumTurns() : 'null');
check(strpos($res ? $res->getContent() : '', '继续第二条') !== false, '本轮正文完整');
$s3->close();

// 中断
$s2 = new FakeCliSession(['turn_timeout' => 15]);
$log2 = new EventLog();
$s2->post('@hold 长任务');
pumpUntil($s2, $log2, function () use ($log2) { return $log2->has('tool_use'); });
$s2->interrupt();
$ok = pumpUntil($s2, $log2, function () use ($s2) { return !$s2->isTurnActive(); });
check($ok, 'interrupt() 之后本轮收口');
$res = $s2->takeResult();
check($res !== null && $res->getSubtype() === 'error_during_execution', '中断后的 result 子类型正确', $res ? $res->getSubtype() : 'null');
check($s2->isRunning(), 'interrupt() 不杀进程，可继续下一轮');
$s2->close();
$s->close();

// ===============================================================
// 五、kill() 真的杀得掉（exec 前缀防回归）
// ===============================================================
echo "\n=== 五、kill() 与进程残留 ===\n\n";

$pidFile = sys_get_temp_dir() . '/ai_fake_claude_' . getmypid() . '.pid';
@unlink($pidFile);
$s = new FakeCliSession();
$s->pidFile = $pidFile;
$s->post('@sleep 挂住不退出');
$log = new EventLog();
pumpUntil($s, $log, function () use ($log) { return $log->has('delivered'); }, 5);

// 注意核对的是假 CLI **自己**写下的 PID：proc_get_status() 给的是直接子进程，
// 命令串里若多一层 sh，那个 PID 死了不代表 claude 死了——现网 bug 正是这样漏掉的
$cliPid = (int) @file_get_contents($pidFile);
check($cliPid > 0 && processAlive($cliPid), '假 CLI 已在运行', (string) $cliPid);
check($s->procPid() === $cliPid, 'claude 就是 proc_open 的直接子进程（没有 sh 中间层）',
      'proc_open 子进程=' . $s->procPid() . ' CLI 自称=' . $cliPid);
$s->kill();
usleep(300000);
check(!processAlive($cliPid), 'kill() 之后 CLI 进程真的没了', (string) $cliPid);
check(!$s->isRunning() && !$s->isTurnActive(), 'kill() 复位会话状态');
@unlink($pidFile);

// 一次性模式：超时之后进程必须一并收掉（否则 claude 会在后台跑完整轮、额度照烧）
$pidFile2 = sys_get_temp_dir() . '/ai_hang_cli_' . getmypid() . '.pid';
@unlink($pidFile2);
$cli = new HangingCli();
$cli->pidFile = $pidFile2;
$cli->setTimeout(1)->setKillGrace(1);
$threw = false;
try {
    $cli->chat('随便什么');
} catch (ProcessException $e) {
    $threw = strpos($e->getMessage(), '超时') !== false;
}
check($threw, '一次性模式超时抛 ProcessException');
$hangPid = (int) @file_get_contents($pidFile2);
usleep(300000);
check($hangPid > 0 && !processAlive($hangPid), '超时之后进程也被收掉了，没有后台残留', (string) $hangPid);
@unlink($pidFile2);

// ===============================================================
// 六、命令构造：exec 前缀
// ===============================================================
echo "\n=== 六、命令构造 ===\n\n";

$cli = new ClaudeCode();
$cli->setBinary('/usr/local/bin/claude')->setWorkdir('/srv/app');
$build = new ReflectionMethod($cli, 'buildBaseCommand');
$build->setAccessible(true);
$cmd = $build->invoke($cli, '/usr/local/bin/claude', '/srv/app', []);
check(strpos($cmd, "cd '/srv/app' && exec '/usr/local/bin/claude' --print") === 0,
      '默认给 claude 加 exec 前缀（信号能直达）', $cmd);

$cli->setExecReplace(false);
$cmd = $build->invoke($cli, '/usr/local/bin/claude', '/srv/app', []);
check(strpos($cmd, 'exec ') === false, 'setExecReplace(false) 可关掉', $cmd);

// ===============================================================
// 七、子进程环境变量
// ===============================================================
echo "\n=== 七、环境变量继承 ===\n\n";

$cli = new ClaudeCode();
$cli->setBinary('/usr/local/bin/claude');
$procEnv = new ReflectionMethod($cli, 'procEnv');
$procEnv->setAccessible(true);

check($procEnv->invoke($cli) === null, '默认不传 env 数组 → 子进程完整继承父进程环境');

$cli->setEnv(['MY_FLAG' => '1']);
$env = $procEnv->invoke($cli);
check(is_array($env) && isset($env['MY_FLAG']) && $env['MY_FLAG'] === '1', 'setEnv 的变量在里面');
check(is_array($env) && count($env) > 1 && isset($env['PATH']),
      '继承模式下父进程变量一并带上（不再是只剩 setEnv 那几个）',
      is_array($env) ? (string) count($env) : 'not-array');

$cli->setInheritEnv(false);
$env = $procEnv->invoke($cli);
check($env === ['MY_FLAG' => '1'], 'setInheritEnv(false) 回到"整体替换"的老行为',
      json_encode($env, JSON_UNESCAPED_UNICODE));

// ===============================================================
// 八、setSleeper：内部等待可被接管
// ===============================================================
echo "\n=== 八、setSleeper ===\n\n";

$s = new FakeCliSession(['turn_timeout' => 15]);
$slept = 0.0;
$s->setSleeper(function ($sec) use (&$slept) { $slept += $sec; usleep((int) ($sec * 1000000)); });
$res = $s->send('走一轮');
check($res->getContent() === '回答:走一轮', '接管等待后 send() 照常工作', $res->getContent());
check($slept > 0, '内部等待确实走了自定义实现（stream_select 已让路）', (string) $slept);
$s->close();

echo "\n", str_repeat('=', 60), "\n";
if ($failures) {
    echo count($failures) . " 项未通过：\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    exit(1);
}
echo "全部通过\n";
exit(0);
