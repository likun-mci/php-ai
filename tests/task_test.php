<?php
/**
 * 异步任务状态机测试
 *
 * AsyncTask 是视频生成的骨架，逻辑集中在三处：状态归一、超时语义、序列化恢复。
 * 这三处都不需要网络就能验，全部离线跑。
 *
 * 尤其要锁住的是**超时语义**：超时不是失败，任务在平台侧还活着。
 * 一旦哪天有人把它改成抛异常，调用方就会 catch 后当失败处理，
 * 白白丢掉一次已经付费的生成——这种退化不会有任何报错，只会悄悄浪费钱。
 *
 * 运行：php tests/task_test.php
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/fixtures/FakeTransport.php';

use Ai\AI;
use Ai\Contracts\AIResponseInterface;
use Ai\Contracts\ProtocolInterface;
use Ai\Helpers\Capabilities;
use Ai\Response\VideoResponse;
use Ai\Task\AsyncTask;
use Tests\Fixtures\FakeTransport;

function pad(string $t, int $w): string
{
    $n = $w - mb_strwidth($t, 'UTF-8');
    return $t . ($n > 0 ? str_repeat(' ', $n) : '');
}

$failures = [];
function check(bool $ok, string $name, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? "（{$detail}）" : '');
    }
    echo pad($name, 52), $ok ? "✓\n" : "✗ {$detail}\n";
}

/**
 * 桩协议：实现任务查询钩子，按预置脚本依次返回状态
 */
class StubTaskProtocol implements ProtocolInterface
{
    use \Ai\Protocol\Concerns\CapabilityDefaults;

    /** @var array<int, string> */
    public $script = [];
    /** @var int */
    public $calls = 0;

    public function capabilityPathMap(): array
    {
        return [Capabilities::VIDEO => '/v1/videos/generations'];
    }

    public function buildRequest(array $payload): array { return $payload; }
    public function parseResponse(array $response): AIResponseInterface
    {
        return new \Ai\Response\AIResponse(['content' => '', 'raw' => $response]);
    }
    public function buildHeaders(array $config): array { return ['Authorization' => 'Bearer x']; }
    public function parseStreamChunk(array $chunk): ?string { return null; }
    public function isStreamEnd(array $chunk): bool { return false; }
    public function listModels(array $config, $transport): ?array { return null; }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    public function parseTaskStatus(string $capability, array $response): array
    {
        $status = isset($this->script[$this->calls]) ? $this->script[$this->calls] : 'running';
        $this->calls++;

        $result = null;
        if ($status === 'SUCCEEDED') {
            $result = new VideoResponse('https://cdn.example.com/v.mp4', '', 5.0, $response);
        }
        return [
            'status' => $status,
            'error'  => $status === 'Failed' ? '内容审核未通过' : '',
            'result' => $result,
        ];
    }
}

/**
 * 造一个挂着桩协议的 AI 实例
 */
function makeAI(StubTaskProtocol $protocol, FakeTransport $transport): AI
{
    $ai = new AI(['api_key' => 'sk-test', 'model' => 'gpt-4o']);
    $ai->setTransport($transport);
    $r = new ReflectionProperty($ai, 'protocol');
    $r->setAccessible(true);
    $r->setValue($ai, $protocol);
    return $ai;
}

// =====================================================================
echo "=== 一、初始状态 ===\n\n";

$task = new AsyncTask('task-001', Capabilities::VIDEO);
check($task->getId() === 'task-001', '任务 ID');
check($task->getStatus() === AsyncTask::STATUS_PENDING, '初始状态为 pending');
check($task->isDone() === false, 'isDone() 初始为 false');
check($task->isPending() === true, 'isPending() 初始为 true');
check($task->getResult() === null, '未完成时 getResult() 返回 null');

// =====================================================================
echo "\n=== 二、平台状态取值归一 ===\n\n";

$normalize = new ReflectionMethod($task, 'normalizeStatus');
$normalize->setAccessible(true);

$cases = [
    // 成功的各种写法
    'SUCCEEDED' => AsyncTask::STATUS_SUCCEEDED,
    'success'   => AsyncTask::STATUS_SUCCEEDED,
    'Success'   => AsyncTask::STATUS_SUCCEEDED,
    'completed' => AsyncTask::STATUS_SUCCEEDED,
    '2'         => AsyncTask::STATUS_SUCCEEDED,
    // 失败
    'FAILED'    => AsyncTask::STATUS_FAILED,
    'Failed'    => AsyncTask::STATUS_FAILED,
    'cancelled' => AsyncTask::STATUS_FAILED,
    // 进行中
    'RUNNING'    => AsyncTask::STATUS_RUNNING,
    'processing' => AsyncTask::STATUS_RUNNING,
    'IN_PROGRESS'=> AsyncTask::STATUS_RUNNING,
    // 排队
    'PENDING'   => AsyncTask::STATUS_PENDING,
    'queued'    => AsyncTask::STATUS_PENDING,
];
foreach ($cases as $input => $expected) {
    $got = $normalize->invoke($task, $input);
    check($got === $expected, sprintf('  %-12s → %s', $input, $expected), $got);
}

// 认不出的状态必须按「还在跑」处理，绝不能当失败：
// 平台新增一个状态值就让用户所有任务变失败，是最糟的降级方式
$got = $normalize->invoke($task, 'SOME_NEW_STATUS_2027');
check($got === AsyncTask::STATUS_RUNNING, '  未知状态按 running 处理（不当失败）', $got);

// =====================================================================
echo "\n=== 三、缺前置条件时的报错必须明确 ===\n\n";

$orphan = new AsyncTask('task-002', Capabilities::VIDEO);
try {
    $orphan->refresh();
    check(false, '未注入 AI 实例时报错', '未抛异常');
} catch (\Ai\Exceptions\UnsupportedCapabilityException $e) {
    check(strpos($e->getMessage(), 'fromArray') !== false,
          '未注入 AI 实例时报错并给出恢复方法', $e->getMessage());
}

// 协议没实现 parseTaskStatus 时，要说清楚缺的是哪个方法
$plainAI = new AI(['api_key' => 'sk-test', 'model' => 'gpt-4o']);
$noHook = new AsyncTask('task-003', Capabilities::VIDEO, $plainAI, 'https://api.example.com/tasks/003');
try {
    $noHook->refresh();
    check(false, '协议缺少 parseTaskStatus 时报错', '未抛异常');
} catch (\Ai\Exceptions\UnsupportedCapabilityException $e) {
    check(strpos($e->getMessage(), 'parseTaskStatus') !== false,
          '协议缺 parseTaskStatus 时点名该方法', $e->getMessage());
}

// =====================================================================
echo "\n=== 四、轮询到成功 ===\n\n";

$proto = new StubTaskProtocol();
$proto->script = ['PENDING', 'RUNNING', 'SUCCEEDED'];
$fake = new FakeTransport();
$fake->queueGet(['a' => 1])->queueGet(['a' => 2])->queueGet(['a' => 3]);
$ai = makeAI($proto, $fake);

$task = new AsyncTask('task-100', Capabilities::VIDEO, $ai, 'https://api.example.com/tasks/100');
$task->refresh();
check($task->getStatus() === AsyncTask::STATUS_PENDING && !$task->isDone(), '第 1 次查询：排队中');
$task->refresh();
check($task->getStatus() === AsyncTask::STATUS_RUNNING && !$task->isDone(), '第 2 次查询：生成中');
$task->refresh();
check($task->isSucceeded() && $task->isDone(), '第 3 次查询：成功');
check($task->getResult() instanceof VideoResponse, '成功后拿到 VideoResponse');
$result = $task->getResult();
check($result !== null && $result->getUrl() === 'https://cdn.example.com/v.mp4', '结果地址正确');
check($task->getPolls() === 3, '轮询次数计数正确', (string) $task->getPolls());

// 已有结论后不再打扰平台
$before = $proto->calls;
$task->refresh();
check($proto->calls === $before, '已完成的任务不再重复查询平台');

// =====================================================================
echo "\n=== 五、失败路径 ===\n\n";

$proto2 = new StubTaskProtocol();
$proto2->script = ['Failed'];
$fake2 = new FakeTransport();
$fake2->queueGet(['x' => 1]);
$task2 = new AsyncTask('task-200', Capabilities::VIDEO, makeAI($proto2, $fake2), 'https://api.example.com/tasks/200');
$task2->refresh();
check($task2->isFailed() && $task2->isDone(), '失败任务 isDone() 为 true');
check($task2->getError() === '内容审核未通过', '错误原因透传', $task2->getError());
check($task2->getResult() === null, '失败时无结果');

// =====================================================================
echo "\n=== 六、等待超时：不是失败 ===\n\n";

$proto3 = new StubTaskProtocol();
$proto3->script = [];   // 一直 running
$fake3 = new FakeTransport();
for ($i = 0; $i < 10; $i++) {
    $fake3->queueGet(['still' => 'running']);
}
$task3 = new AsyncTask('task-300', Capabilities::VIDEO, makeAI($proto3, $fake3), 'https://api.example.com/tasks/300');

$start = time();
$returned = $task3->wait(1, 1);   // 1 秒就超时
$elapsed = time() - $start;

check($returned === $task3, 'wait() 返回任务自身而不是抛异常');
check($task3->isTimeout(), '状态为 timeout', $task3->getStatus());
check($task3->isDone() === false, '**isDone() 仍为 false**（任务还活着，不会被误当完成）');
check($task3->isPending() === true, 'isPending() 为 true');
check($task3->isFailed() === false, '不是 failed —— 超时不等于失败');
check(strpos($task3->getMessage(), 'task-300') !== false, '提示里带上 task_id 便于后续查询');
check(strpos($task3->getMessage(), '不是失败') !== false, '提示明确说明这不是失败');
check($elapsed <= 5, '超时后及时返回', "耗时 {$elapsed}s");
check($task3->getResult() === null, '超时时 getResult() 为 null');

// =====================================================================
echo "\n=== 七、序列化与跨请求恢复 ===\n\n";

$data = $task3->toArray();
check(!isset($data['ai']), '序列化结果不含 AI 实例（内含密钥，不该落库）');
check($data['id'] === 'task-300', 'id 已序列化');
check($data['query_url'] === 'https://api.example.com/tasks/300', 'query_url 已序列化');

// 模拟存库再取出
$json = json_encode($data);
check(is_string($json), '可 json_encode（能直接存库）');
$restoredData = json_decode((string) $json, true);

$proto4 = new StubTaskProtocol();
$proto4->script = ['SUCCEEDED'];
$fake4 = new FakeTransport();
$fake4->queueGet(['done' => true]);
$restored = AsyncTask::fromArray($restoredData, makeAI($proto4, $fake4));

check($restored->getId() === 'task-300', '恢复后 id 一致');
check($restored->getPolls() === $task3->getPolls(), '恢复后轮询次数一致');
$restored->refresh();
check($restored->isSucceeded(), '恢复后可继续查询并拿到结果');
$rr = $restored->getResult();
check($rr instanceof VideoResponse && $rr->getUrl() !== '', '恢复后能拿到最终视频地址');

// setAI 也能补注入
$late = AsyncTask::fromArray($restoredData);
$proto5 = new StubTaskProtocol();
$proto5->script = ['RUNNING'];
$fake5 = new FakeTransport();
$fake5->queueGet(['x' => 1]);
$late->setAI(makeAI($proto5, $fake5))->refresh();
check($late->getStatus() === AsyncTask::STATUS_RUNNING, 'setAI() 补注入后可查询');

// =====================================================================
echo "\n" . str_repeat('=', 60) . "\n";
if ($failures) {
    echo count($failures) . " 项未通过：\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "全部通过\n";
exit(0);
