<?php
/**
 * 异步任务与文生视频测试
 *
 * 全离线，用 FakeTransport 模拟「先 running 后 succeeded」的轮询过程。
 * **绝不打真实接口**——视频生成按次计费且单价高，测试跑一遍就是真金白银。
 *
 * 这一期的风险和前几期不同：
 *   - 任务耗时分钟级，阻塞等待会占死 PHP-FPM worker，所以 generate() 必须**不阻塞**
 *   - 结果要跨请求恢复，序列化不能丢字段
 *   - 四家平台的状态字段名、取值、甚至**流程步数**都不一样
 *     （MiniMax 是三段式：提交 → 查状态拿 file_id → 再取下载地址）
 *
 * 运行：php tests/video_test.php
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/fixtures/FakeTransport.php';

use Ai\AI;
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
    echo pad($name, 58), $ok ? "✓\n" : "✗ {$detail}\n";
}

function makeAI(string $protocol, string $model = 'video-model', array $extra = []): array
{
    $fake = new FakeTransport();
    $ai = new AI(array_merge(['api_key' => 'sk-test', 'model' => $model, 'protocol' => $protocol], $extra));
    $ai->setTransport($fake);
    return [$ai, $fake];
}

// =====================================================================
echo "=== 一、能力声明与端点 ===\n\n";

$expect = [
    'qwen'    => '/api/v1/services/aigc/video-generation/video-synthesis',
    'zhipu'   => '/v4/videos/generations',
    'doubao'  => '/api/v3/contents/generations/tasks',
    'minimax' => '/v1/video_generation',
];
foreach ($expect as $key => $path) {
    $class = \Ai\Helpers\Protocols::resolveClass($key);
    $p = new $class();
    check($p->capabilityPath(Capabilities::VIDEO) === $path, sprintf('  %-8s → %s', $key, $path),
          $p->capabilityPath(Capabilities::VIDEO));
}
check(!in_array(Capabilities::VIDEO, (new \Ai\Protocol\OpenAI())->capabilities(), true),
      '  openai 不声明视频（无此接口）');

// 通义的视频不在对话路径前缀之下，走 capabilityEndpoint 逃生口
$endpoints = [
    ['qwen',    [], 'https://dashscope.aliyuncs.com/api/v1/services/aigc/video-generation/video-synthesis'],
    ['zhipu',   [], 'https://open.bigmodel.cn/api/paas/v4/videos/generations'],
    ['doubao',  [], 'https://ark.cn-beijing.volces.com/api/v3/contents/generations/tasks'],
    ['minimax', [], 'https://api.minimaxi.com/v1/video_generation'],
    // 自建网关：路径前缀必须保留
    ['qwen',    ['base_url' => 'https://gw.internal/ds'],
                'https://gw.internal/ds/api/v1/services/aigc/video-generation/video-synthesis'],
    ['minimax', ['base_url' => 'https://gw.internal/mm'], 'https://gw.internal/mm/v1/video_generation'],
];
foreach ($endpoints as [$proto, $extra, $want]) {
    list($ai) = makeAI($proto, 'm', $extra);
    $m = new ReflectionMethod($ai->video(), 'endpoint');
    $m->setAccessible(true);
    $got = $m->invoke($ai->video());
    check($got === $want, '  ' . $proto . ($extra ? '（自建网关）' : '') . ' 端点', $got);
}

// 万相必须带异步头，不带会退化成同步然后超时
check((new \Ai\Protocol\Qwen())->capabilityHeaders(Capabilities::VIDEO) === ['X-DashScope-Async' => 'enable'],
      '  **通义带 X-DashScope-Async 头**（不带会退化成同步调用）');
check((new \Ai\Protocol\Qwen())->capabilityHeaders(Capabilities::TTS) === [],
      '  该头只加在视频能力上，不污染其它请求');

// =====================================================================
echo "\n=== 二、提交任务：不阻塞，返回任务对象 ===\n\n";

list($ai, $fake) = makeAI('zhipu', 'cogvideox-3');
$fake->queuePost(['id' => 'TASK-123', 'request_id' => 'r1', 'model' => 'cogvideox-3', 'task_status' => 'PROCESSING']);
$task = $ai->video()->generate('日落的海边', ['duration' => 5]);

check($task instanceof AsyncTask, '**generate() 返回 AsyncTask 而不是视频**（不阻塞）');
check($task->getId() === 'TASK-123', '任务 ID 解析', $task->getId());
check($task->getCapability() === Capabilities::VIDEO, '能力标识为 video');
check(!$task->isDone(), '刚提交时 isDone() 为 false');
check($task->getResult() === null, '未完成时 getResult() 为 null');
check(count($fake->getRequests()) === 1, '只发了一次请求（没有偷偷轮询）');

$req = $fake->lastRequest();
check($req['data']['prompt'] === '日落的海边', '提示词就位');
check($req['data']['duration'] === 5, '参数透传');

// 平台没返回任务 ID 要当场失败
$fake->reset();
$fake->queuePost(['request_id' => 'r1']);
try {
    $ai->video()->generate('x');
    check(false, '平台未返回任务 ID 时报错', '未抛出');
} catch (\Ai\Exceptions\UnsupportedCapabilityException $e) {
    check(strpos($e->getMessage(), '任务 ID') !== false, '平台未返回任务 ID 时报错', $e->getMessage());
}

// =====================================================================
echo "\n=== 三、各平台请求体差异 ===\n\n";

// 万相：input / parameters 两段式
list($ai, $fake) = makeAI('qwen', 'wan2.7-t2v');
$fake->queuePost(['output' => ['task_id' => 'W1', 'task_status' => 'PENDING']]);
$ai->video()->generate('一只猫在跑', ['negative_prompt' => '模糊', 'duration' => 5, 'size' => '1920x1080']);
$req = $fake->lastRequest();
check(isset($req['data']['input']['prompt']) && $req['data']['input']['prompt'] === '一只猫在跑',
      '万相：prompt 进 input 段', json_encode($req['data'], JSON_UNESCAPED_UNICODE));
check($req['data']['input']['negative_prompt'] === '模糊', '万相：negative_prompt 进 input 段');
check($req['data']['parameters']['duration'] === 5, '万相：duration 进 parameters 段');
check($req['data']['parameters']['resolution'] === '1080P', '万相：size 换算成 resolution 档位',
      json_encode($req['data']['parameters']));
check(!isset($req['data']['prompt']), '万相：不残留平铺字段');
check(isset($req['headers']['X-DashScope-Async']), '万相：异步头随请求发出');

// 方舟：content 数组
list($ai, $fake) = makeAI('doubao', 'doubao-seedance-1-0-pro');
$fake->queuePost(['id' => 'D1', 'status' => 'queued']);
$ai->video()->generate('海边日落', ['ratio' => '16:9', 'image_url' => 'https://x/first.png']);
$req = $fake->lastRequest();
check(isset($req['data']['content'][0]['type']) && $req['data']['content'][0]['type'] === 'text',
      '方舟：提示词进 content 数组', json_encode($req['data'], JSON_UNESCAPED_UNICODE));
check($req['data']['content'][0]['text'] === '海边日落', '方舟：文本内容正确');
check(isset($req['data']['content'][1]['image_url']['url']), '方舟：首帧图进 content 数组');
check($req['data']['ratio'] === '16:9', '方舟：ratio 平铺在顶层');

// 调用方已按方舟结构写好时不动
$fake->reset();
$fake->queuePost(['id' => 'D2']);
$ai->video()->generate('x', ['content' => [['type' => 'text', 'text' => '自定义']]]);
check($fake->lastRequest()['data']['content'][0]['text'] === '自定义', '方舟：调用方自写 content 时不被改写');

// =====================================================================
echo "\n=== 四、轮询：先 running 后 succeeded ===\n\n";

list($ai, $fake) = makeAI('zhipu', 'cogvideox-3');
$fake->queuePost(['id' => 'Z1', 'task_status' => 'PROCESSING']);
$task = $ai->video()->generate('测试');

$fake->queueGet(['task_status' => 'PROCESSING', 'model' => 'cogvideox-3']);
$task->refresh();
check($task->getStatus() === AsyncTask::STATUS_RUNNING, '第一次查询：running', $task->getStatus());
check(!$task->isDone(), 'running 时 isDone() 为 false');
check($task->isPending(), 'running 时 isPending() 为 true');
check($task->getResult() === null, 'running 时无结果');

$fake->queueGet([
    'task_status'  => 'SUCCESS',
    'model'        => 'cogvideox-3',
    'video_result' => [['url' => 'https://cdn/v.mp4', 'cover_image_url' => 'https://cdn/c.png']],
]);
$task->refresh();
check($task->getStatus() === AsyncTask::STATUS_SUCCEEDED, '第二次查询：succeeded', $task->getStatus());
check($task->isDone() && $task->isSucceeded(), 'isDone() 与 isSucceeded() 均为 true');
check(!$task->isPending(), '完成后 isPending() 为 false');

$result = $task->getResult();
check($result instanceof VideoResponse, '拿到 VideoResponse');
check($result->getUrl() === 'https://cdn/v.mp4', '视频地址解析', $result->getUrl());
check($result->getCoverUrl() === 'https://cdn/c.png', '封面地址解析');
check($task->getPolls() === 2, '轮询次数计数正确', (string) $task->getPolls());

// 查询地址推导
$reqs = $fake->getRequests();
$getReq = null;
foreach ($reqs as $r) {
    if (isset($r['method']) && $r['method'] === 'GET') { $getReq = $r; break; }
}
check($getReq !== null && strpos($getReq['url'], '/api/paas/v4/async-result/Z1') !== false,
      '智谱：查询地址是 /async-result/{id}', $getReq ? $getReq['url'] : '(无 GET 请求)');

// 已有结论就不再打扰平台
$before = count($fake->getRequests());
$task->refresh();
check(count($fake->getRequests()) === $before, '**已完成的任务再 refresh() 不发请求**');

// =====================================================================
echo "\n=== 五、失败与状态归一 ===\n\n";

list($ai, $fake) = makeAI('doubao', 'seedance');
$fake->queuePost(['id' => 'D9', 'status' => 'queued']);
$task = $ai->video()->generate('x');
$fake->queueGet(['status' => 'failed', 'error' => ['code' => 'xx', 'message' => '内容不合规']]);
$task->refresh();
check($task->isFailed(), '方舟：failed 判为失败');
check($task->getError() === '内容不合规', '错误信息透传', $task->getError());
check(strpos($task->getMessage(), '内容不合规') !== false, 'getMessage() 含原因', $task->getMessage());
check($task->getResult() === null, '失败时无结果');

// 各平台状态取值归一
$norm = new ReflectionMethod(AsyncTask::class, 'normalizeStatus');
$norm->setAccessible(true);
$probe = new AsyncTask('x', Capabilities::VIDEO);
$cases = [
    'SUCCEEDED' => AsyncTask::STATUS_SUCCEEDED,   // 通义
    'SUCCESS'   => AsyncTask::STATUS_SUCCEEDED,   // 智谱
    'succeeded' => AsyncTask::STATUS_SUCCEEDED,   // 方舟
    'Success'   => AsyncTask::STATUS_SUCCEEDED,   // MiniMax
    'FAIL'      => AsyncTask::STATUS_FAILED,      // 智谱
    'failed'    => AsyncTask::STATUS_FAILED,
    'CANCELED'  => AsyncTask::STATUS_FAILED,      // 通义
    'RUNNING'   => AsyncTask::STATUS_RUNNING,
    'PROCESSING' => AsyncTask::STATUS_RUNNING,
    'PENDING'   => AsyncTask::STATUS_PENDING,
    'Preparing' => AsyncTask::STATUS_PENDING,     // MiniMax
    'Queueing'  => AsyncTask::STATUS_PENDING,     // MiniMax
    'queued'    => AsyncTask::STATUS_PENDING,     // 方舟
];
$bad = [];
foreach ($cases as $raw => $want) {
    if ($norm->invoke($probe, $raw) !== $want) {
        $bad[] = $raw;
    }
}
check($bad === [], '**四家平台的 13 种状态写法全部归一**', implode(',', $bad));

// 认不出的状态按「还在跑」处理，不能当失败
check($norm->invoke($probe, 'SOME_NEW_STATUS') === AsyncTask::STATUS_RUNNING,
      '**未知状态按处理中对待**（平台新增取值不该让任务全变失败）');

// =====================================================================
echo "\n=== 六、MiniMax 三段流程 ===\n\n";

list($ai, $fake) = makeAI('minimax', 'MiniMax-Hailuo-02');
$fake->queuePost(['task_id' => 'M1', 'base_resp' => ['status_code' => 0]]);
$task = $ai->video()->generate('海边日落');
check($task->getId() === 'M1', 'MiniMax：task_id 解析', $task->getId());

// 第二步：状态成功但只给 file_id
// 第三步：再取下载地址
$fake->queueGet(['status' => 'Success', 'file_id' => 'F9', 'base_resp' => ['status_code' => 0]]);
$fake->queueGet(['file' => ['file_id' => 'F9', 'download_url' => 'https://cdn/hailuo.mp4'],
                 'base_resp' => ['status_code' => 0]]);
$task->refresh();

check($task->isSucceeded(), 'MiniMax：任务成功');
check($task->getResult() !== null, '**MiniMax：三段流程走完拿到结果**');
check($task->getResult()->getUrl() === 'https://cdn/hailuo.mp4', 'MiniMax：下载地址正确',
      $task->getResult() ? $task->getResult()->getUrl() : '(null)');

$gets = [];
foreach ($fake->getRequests() as $r) {
    if (isset($r['method']) && $r['method'] === 'GET') { $gets[] = $r['url']; }
}
check(count($gets) === 2, 'MiniMax：发了两次 GET（查状态 + 取文件）', (string) count($gets));
check(isset($gets[0]) && strpos($gets[0], '/v1/query/video_generation?task_id=M1') !== false,
      '第二步地址正确', isset($gets[0]) ? $gets[0] : '(无)');
check(isset($gets[1]) && strpos($gets[1], '/v1/files/retrieve?file_id=F9') !== false,
      '第三步地址正确', isset($gets[1]) ? $gets[1] : '(无)');

// MiniMax 的失败不体现在 HTTP 状态码上
list($ai, $fake) = makeAI('minimax', 'MiniMax-Hailuo-02');
$fake->queuePost(['task_id' => 'M2', 'base_resp' => ['status_code' => 0]]);
$task = $ai->video()->generate('x');
$fake->queueGet(['status' => 'Processing', 'base_resp' => ['status_code' => 1004, 'status_msg' => 'API key 无效']]);
$task->refresh();
check($task->isFailed(), '**MiniMax：base_resp 非 0 判为失败**（HTTP 仍是 200）');
check($task->getError() === 'API key 无效', 'MiniMax：错误信息透传', $task->getError());

// 网关下第三步也要跟随
list($ai, $fake) = makeAI('minimax', 'm', ['base_url' => 'https://gw.internal/mm']);
$fake->queuePost(['task_id' => 'M3', 'base_resp' => ['status_code' => 0]]);
$task = $ai->video()->generate('x');
$fake->queueGet(['status' => 'Success', 'file_id' => 'F3', 'base_resp' => ['status_code' => 0]]);
$fake->queueGet(['file' => ['download_url' => 'https://cdn/x.mp4'], 'base_resp' => ['status_code' => 0]]);
$task->refresh();
$gets = [];
foreach ($fake->getRequests() as $r) {
    if (isset($r['method']) && $r['method'] === 'GET') { $gets[] = $r['url']; }
}
check(isset($gets[1]) && strpos($gets[1], 'https://gw.internal/mm/v1/files/retrieve') === 0,
      '**自建网关下第三步也走同一个网关**（不回落官方域名）', isset($gets[1]) ? $gets[1] : '(无)');

// =====================================================================
echo "\n=== 七、序列化恢复（跨请求）===\n\n";

list($ai, $fake) = makeAI('zhipu', 'cogvideox-3');
$fake->queuePost(['id' => 'S1', 'task_status' => 'PROCESSING']);
$task = $ai->video()->generate('测试');

$stored = json_encode($task->toArray());
check(is_string($stored) && $stored !== '', '任务可序列化成 JSON');

$data = json_decode((string) $stored, true);
check($data['id'] === 'S1', '序列化含任务 ID');
check(!empty($data['query_url']), '序列化含查询地址（恢复后才能继续查）');
check(!isset($data['ai']), '**序列化不含 AI 实例**（内含密钥，不该落库）');

// 模拟「下一个请求」：新建 AI 实例后恢复
list($ai2, $fake2) = makeAI('zhipu', 'cogvideox-3');
$restored = AsyncTask::fromArray($data, $ai2);
check($restored->getId() === 'S1', '恢复后 ID 一致');
check($restored->getCapability() === Capabilities::VIDEO, '恢复后能力标识一致');

$fake2->queueGet(['task_status' => 'SUCCESS', 'video_result' => [['url' => 'https://cdn/restored.mp4']]]);
$restored->refresh();
check($restored->isSucceeded(), '**恢复后能继续查询并拿到结果**');
check($restored->getResult()->getUrl() === 'https://cdn/restored.mp4', '恢复后结果正确');

// 没注入 AI 实例时要说清楚怎么办
$orphan = AsyncTask::fromArray($data);
try {
    $orphan->refresh();
    check(false, '未注入 AI 实例时报错', '未抛出');
} catch (\Ai\Exceptions\UnsupportedCapabilityException $e) {
    check(strpos($e->getMessage(), 'fromArray') !== false, '未注入 AI 实例时报错并给出做法', $e->getMessage());
}
check($orphan->setAI($ai2)->getId() === 'S1', 'setAI() 可事后注入');

// =====================================================================
echo "\n=== 八、wait()：超时不算失败 ===\n\n";

list($ai, $fake) = makeAI('zhipu', 'cogvideox-3');
$fake->queuePost(['id' => 'W1', 'task_status' => 'PROCESSING']);
$task = $ai->video()->generate('测试');

// 一直 PROCESSING，1 秒超时
for ($i = 0; $i < 10; $i++) {
    $fake->queueGet(['task_status' => 'PROCESSING']);
}
$start = time();
$task->wait(1, 1);
$elapsed = time() - $start;

check($task->isTimeout(), '超时后状态为 timeout');
check(!$task->isDone(), '**超时时 isDone() 仍为 false**（不会被误当成功）');
check($task->isPending(), '超时算 pending —— 任务在平台侧还活着');
check(!$task->isFailed(), '**超时不算失败**（任务还在跑，当失败会白丢一次付费生成）');
check($task->getResult() === null, '超时时无结果');
check(strpos($task->getMessage(), 'W1') !== false, '提示里带上 task_id 便于稍后恢复', $task->getMessage());
check(strpos($task->getMessage(), '不是失败') !== false, '提示明确说明「这不是失败」');
check($elapsed <= 4, 'wait() 在超时后及时返回', $elapsed . ' 秒');

// 成功时 wait() 立刻返回
list($ai, $fake) = makeAI('zhipu', 'cogvideox-3');
$fake->queuePost(['id' => 'W2', 'task_status' => 'PROCESSING']);
$task = $ai->video()->generate('测试');
$fake->queueGet(['task_status' => 'SUCCESS', 'video_result' => [['url' => 'https://cdn/ok.mp4']]]);
$start = time();
$task->wait(60, 1);
check($task->isSucceeded() && (time() - $start) <= 2, 'wait() 在任务完成后立刻返回，不空等');

// =====================================================================
echo "\n=== 九、模型清单（据官方文档）===\n\n";

$models = [
    ['qwen', 'wan2.7-t2v'],
    ['zhipu', 'cogvideox-3'],
];
foreach ($models as [$key, $expectModel]) {
    $class = \Ai\Helpers\Protocols::resolveClass($key);
    $p = new $class();
    check(in_array($expectModel, $p->knownVideoModels(), true), "  {$key} 登记了 {$expectModel}",
          implode(',', $p->knownVideoModels()));
}

// =====================================================================
echo "\n=== 十、跨平台一致性 ===\n\n";

$shapes = [];
$scripts = [
    'qwen'    => [['output' => ['task_id' => 'T']], ['output' => ['task_status' => 'SUCCEEDED', 'video_url' => 'https://cdn/v.mp4']]],
    'zhipu'   => [['id' => 'T'], ['task_status' => 'SUCCESS', 'video_result' => [['url' => 'https://cdn/v.mp4']]]],
    'doubao'  => [['id' => 'T'], ['status' => 'succeeded', 'content' => ['video_url' => 'https://cdn/v.mp4']]],
];
foreach ($scripts as $key => [$submit, $query]) {
    list($ai, $fake) = makeAI($key, 'm');
    $fake->queuePost($submit);
    $task = $ai->video()->generate('统一的调用代码');
    $fake->queueGet($query);
    $task->refresh();
    $shapes[$key] = [
        $task->getId(),
        $task->getStatus(),
        $task->isSucceeded(),
        $task->getResult() ? $task->getResult()->getUrl() : null,
    ];
}
$first = reset($shapes);
$diff = [];
foreach ($shapes as $k => $v) {
    if ($v !== $first) { $diff[] = $k; }
}
check($diff === [], '3 个平台（形态各异）产出完全一致的任务与结果', implode(',', $diff));

// 不支持的协议明确报错
list($ai) = makeAI('openai', 'gpt-4o');
try {
    $ai->video()->generate('x');
    check(false, 'OpenAI 不支持时抛异常', '未抛出');
} catch (\Ai\Exceptions\UnsupportedCapabilityException $e) {
    check(strpos($e->getMessage(), '视频生成') !== false, 'OpenAI 不支持时抛异常并点名能力', $e->getMessage());
}

echo "\n" . str_repeat('=', 64) . "\n";
if ($failures) {
    echo count($failures) . " 项未通过：\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "全部通过：四家平台的异步任务已归一，超时不误判为失败\n";
exit(0);
