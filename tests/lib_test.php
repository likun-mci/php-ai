<?php
/**
 * 库基础设施回归测试
 *
 * 覆盖不属于「协议 / 流式 / 工具调用」但同样会在生产上咬人的部分：
 *   一、chatBatch() 并发批量：结果与入参键一一对应、单条失败不拖垮整批
 *   二、Memory 并发追加：旧的读-改-写实现在多进程下会丢数据
 *   三、cost() 计价：按百万 token、缓存 token 单独计价、两家字段名都认
 *   四、Log 注入：库内不再硬编码 error_log
 *
 * 不联网、不需要 Key。运行：php tests/lib_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Memory;
use Ai\Contracts\TransportInterface;
use Ai\Helpers\Log;
use Ai\Response\AIResponse;

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
    echo pad($name, 44), $ok ? "✓\n" : "✗ {$detail}\n";
}

// ===============================================================
// 一、chatBatch() 并发批量
// ===============================================================
echo "=== 一、chatBatch 并发批量 ===\n\n";

/** 支持并发接口的假传输层：按 messages 内容决定成败 */
class BatchTransport implements TransportInterface
{
    public $concurrency = 0;

    public function postConcurrent(array $requests, int $concurrency = 5): array
    {
        $this->concurrency = $concurrency;
        $out = [];
        foreach ($requests as $key => $req) {
            $text = $req['data']['messages'][0]['content'] ?? '';
            if (strpos($text, 'FAIL') !== false) {
                $out[$key] = ['ok' => false, 'status' => 429, 'error' => '限流', 'response' => []];
                continue;
            }
            $out[$key] = ['ok' => true, 'status' => 200, 'error' => '', 'response' => [
                'choices' => [['message' => ['content' => '回复:' . $text], 'finish_reason' => 'stop']],
                'usage'   => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]];
        }
        return $out;
    }
    public function post(string $u, array $d, array $h = []): array { return []; }
    public function get(string $u, array $p = [], array $h = []): array { return []; }
    public function setTimeout(int $t): TransportInterface { return $this; }
    public function setProxy(string $p): TransportInterface { return $this; }
    public function setStreamCallback(?callable $c): TransportInterface { return $this; }
}

/** 不支持并发的传输层，用于验证降级为串行 */
class SerialOnlyTransport implements TransportInterface
{
    public $calls = 0;
    public function post(string $u, array $d, array $h = []): array
    {
        $this->calls++;
        $text = $d['messages'][0]['content'] ?? '';
        return ['choices' => [['message' => ['content' => '串行:' . $text], 'finish_reason' => 'stop']]];
    }
    public function get(string $u, array $p = [], array $h = []): array { return []; }
    public function setTimeout(int $t): TransportInterface { return $this; }
    public function setProxy(string $p): TransportInterface { return $this; }
    public function setStreamCallback(?callable $c): TransportInterface { return $this; }
}

$tr = new BatchTransport();
$ai = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai->setTransport($tr);

$results = $ai->chatBatch([
    'title' => '标题',
    'body'  => ['messages' => [['role' => 'user', 'content' => '正文']]],
    'bad'   => 'FAIL 这条会失败',
    'tail'  => '结尾',
], 3);

check(array_keys($results) === ['title', 'body', 'bad', 'tail'], '返回键与入参键一一对应且保序',
      implode(',', array_keys($results)));
check($results['title']->getContent() === '回复:标题', '字符串入参正常');
check($results['body']->getContent() === '回复:正文', '数组 payload 入参正常');
check($results['tail']->getContent() === '回复:结尾', '失败项之后的条目不受影响');
check(!$results['bad']->isSuccess(), '失败项 isSuccess() 为 false');
check($results['bad']->getError() === '限流', '失败项可用 getError() 取原因',
      $results['bad']->getError());
check($tr->concurrency === 3, '并发度参数被正确传递', (string) $tr->concurrency);

$serialTr = new SerialOnlyTransport();
$ai2 = AI::create(['protocol' => 'openai', 'model' => 'm', 'api_key' => 'k']);
$ai2->setTransport($serialTr);
$r2 = $ai2->chatBatch(['a' => '甲', 'b' => '乙']);
check($serialTr->calls === 2 && $r2['a']->getContent() === '串行:甲',
      '传输层不支持并发时自动降级为串行');

// ===============================================================
// 二、Memory 并发追加
// ===============================================================
echo "\n=== 二、Memory 并发安全 ===\n\n";

$memFile = sys_get_temp_dir() . '/ai_mem_test_' . getmypid() . '.md';
@unlink($memFile);
$mem = new Memory($memFile);

$mem->append('第一条');
$mem->append('第二条');
check(trim($mem->read()) === "第一条\n第二条", 'append 顺序与换行正确',
      json_encode($mem->read(), JSON_UNESCAPED_UNICODE));

$mem->write("覆盖内容\n");
check(trim($mem->read()) === '覆盖内容', 'write 覆盖整份记忆');
$mem->append('追加到覆盖后');
check(trim($mem->read()) === "覆盖内容\n追加到覆盖后", 'write 之后 append 不会粘连');

check($mem->append('') === false, '空内容不写入');

// 多进程并发：旧的读-改-写实现在这里会丢掉大部分数据
if (function_exists('proc_open')) {
    @unlink($memFile);
    $php     = PHP_BINARY;
    $worker  = sys_get_temp_dir() . '/ai_mem_worker_' . getmypid() . '.php';
    $auto    = realpath(__DIR__ . '/../autoload.php');
    file_put_contents($worker, <<<PHP
<?php
require '{$auto}';
\$m = new Ai\\Agent\\Memory(\$argv[1]);
for (\$i = 1; \$i <= 25; \$i++) { \$m->append('p' . \$argv[2] . '-' . \$i); }
PHP
    );

    $procs = [];
    for ($p = 1; $p <= 6; $p++) {
        $procs[] = proc_open(
            escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($memFile) . ' ' . $p,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes
        );
        foreach ($pipes as $pipe) { fclose($pipe); }
    }
    foreach ($procs as $proc) { if (is_resource($proc)) { proc_close($proc); } }

    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($memFile)), 'strlen'));
    check(count($lines) === 150, '6 进程 × 25 条并发追加，一条不丢',
          '实际 ' . count($lines) . ' 条');
    $malformed = array_filter($lines, function ($l) { return !preg_match('/^p\d+-\d+$/', $l); });
    check(empty($malformed), '并发写入无残行/内容交错',
          implode(' | ', array_slice($malformed, 0, 3)));
    @unlink($worker);
} else {
    echo pad('（proc_open 被禁用，跳过并发测试）', 44), "—\n";
}
@unlink($memFile);

// ===============================================================
// 三、cost() 计价
// ===============================================================
echo "\n=== 三、cost() 计价 ===\n\n";

$resp = new AIResponse(['usage' => [
    'prompt_tokens'         => 1000000,
    'completion_tokens'     => 500000,
    'prompt_tokens_details' => ['cached_tokens' => 800000],
]]);

check(abs($resp->cost(['prompt' => 5.0, 'completion' => 25.0]) - 17.5) < 0.0001,
      '按百万 token 计价（5 + 12.5 = 17.5）',
      (string) $resp->cost(['prompt' => 5.0, 'completion' => 25.0]));

// 80 万走缓存价 0.5 = 0.40；剩 20 万走输入价 5 = 1.00；输出 12.50
check(abs($resp->cost(['prompt' => 5.0, 'completion' => 25.0, 'cached' => 0.5]) - 13.9) < 0.0001,
      '缓存 token 单独计价（OpenAI 字段）',
      (string) $resp->cost(['prompt' => 5.0, 'completion' => 25.0, 'cached' => 0.5]));

$anthropic = new AIResponse(['usage' => [
    'prompt_tokens' => 1000000, 'completion_tokens' => 0, 'cache_read_input_tokens' => 1000000,
]]);
check(abs($anthropic->cost(['prompt' => 5.0, 'completion' => 25.0, 'cached' => 0.5]) - 0.5) < 0.0001,
      '缓存 token 单独计价（Anthropic 字段）',
      (string) $anthropic->cost(['prompt' => 5.0, 'completion' => 25.0, 'cached' => 0.5]));

check(abs($resp->cost(['prompt' => 0.005, 'completion' => 0.025], 1000) - 17.5) < 0.0001,
      'perTokens=1000 可沿用旧的每千口径');
check($resp->cost([]) === 0.0, '未传价格表返回 0');

// ===============================================================
// 四、Log 注入
// ===============================================================
echo "\n=== 四、日志注入 ===\n\n";

check(!Log::hasLogger(), '默认无注入（退回 error_log）');

$captured = [];
Log::setLogger(function ($level, $message, array $context) use (&$captured) {
    $captured[] = [$level, $message, $context];
});
check(Log::hasLogger(), 'setLogger(callable) 生效');
Log::warning('测试消息', ['k' => 'v']);
check(count($captured) === 1 && $captured[0][0] === 'warning' && $captured[0][1] === '测试消息'
      && $captured[0][2] === ['k' => 'v'], 'callable 收到 level/message/context');

// PSR-3 风格对象（鸭子类型，不依赖 psr/log）
class FakePsrLogger
{
    public $records = [];
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [$level, $message];
    }
}
$psr = new FakePsrLogger();
Log::setLogger($psr);
Log::error('PSR 消息');
check(count($psr->records) === 1 && $psr->records[0][0] === 'error', 'PSR-3 风格对象生效');

// 日志器自身抛错不能影响主流程
Log::setLogger(function () { throw new \RuntimeException('logger 挂了'); });
$survived = true;
try { Log::warning('x'); } catch (\Throwable $e) { $survived = false; }
check($survived, '日志器自身抛错不会冒泡到主流程');

Log::setLogger(null);
check(!Log::hasLogger(), 'setLogger(null) 可恢复默认');

$rejected = false;
try { Log::setLogger('not-a-logger-string'); } catch (\InvalidArgumentException $e) { $rejected = true; }
check($rejected, '非法 logger 立即报错而不是静默失效');

// 库内不应再有裸 error_log
$srcDir = realpath(__DIR__ . '/../src');
$hits   = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php'
        && strpos($f->getPathname(), 'Helpers' . DIRECTORY_SEPARATOR . 'Log.php') === false) {
        if (strpos((string) file_get_contents($f->getPathname()), 'error_log(') !== false) {
            $hits[] = str_replace($srcDir . DIRECTORY_SEPARATOR, '', $f->getPathname());
        }
    }
}
check(empty($hits), '库内已无硬编码 error_log', implode(', ', $hits));

echo "\n", str_repeat('=', 58), "\n";
if ($failures) {
    echo count($failures) . " 项未通过：\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    exit(1);
}
echo "全部通过\n";
exit(0);
