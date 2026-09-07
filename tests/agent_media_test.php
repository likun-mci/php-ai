<?php
/**
 * P1 Media 基础设施测试
 *
 * 覆盖 dev.md 进度表 P1：Attachment 三种来源与安全校验、MediaReference、
 * FileMediaStore（含原子写/回收/路径穿越防护）、MediaResolver 的请求级配额、
 * MessagePart 的块构造与追加语义、AgentHome::mediaDir() 的隔离规则。
 *
 * 全程临时目录，结束递归删除，绝不污染仓库；不发任何网络请求。
 *
 * 运行：php tests/agent_media_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Agent\Context\MessagePart;
use Ai\Agent\Media\Attachment;
use Ai\Agent\Media\FileMediaStore;
use Ai\Agent\Media\MediaException;
use Ai\Agent\Media\MediaManager;
use Ai\Agent\Media\MediaReference;
use Ai\Agent\Media\MediaResolver;
use Ai\Agent\Storage\AgentHome;

$passed = 0;
$failed = 0;

function test($name, $ok)
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "✓ {$name}\n"; }
    else { $failed++; echo "✗ {$name}\n"; }
}
function assert_eq($name, $expected, $actual)
{
    if ($expected !== $actual) {
        echo "  期望: " . var_export($expected, true) . "\n  实际: " . var_export($actual, true) . "\n";
    }
    test($name, $expected === $actual);
}
/** 断言某段代码抛出 MediaException，并且消息里包含关键词 */
function assert_throws($name, callable $fn, $needle = '')
{
    try {
        $fn();
    } catch (MediaException $e) {
        if ($needle !== '' && strpos($e->getMessage(), $needle) === false) {
            echo "  异常消息里没有「{$needle}」：" . $e->getMessage() . "\n";
            test($name, false);
            return;
        }
        test($name, true);
        return;
    } catch (\Throwable $e) {
        echo "  抛的是 " . get_class($e) . ": " . $e->getMessage() . "\n";
        test($name, false);
        return;
    }
    echo "  没有抛异常\n";
    test($name, false);
}
function rrmdir($dir)
{
    if (!is_dir($dir)) { return; }
    $items = scandir($dir);
    if ($items === false) { return; }
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') { continue; }
        $p = $dir . '/' . $it;
        if (is_dir($p) && !is_link($p)) { rrmdir($p); } else { @unlink($p); }
    }
    @rmdir($dir);
}

$tmp = sys_get_temp_dir() . '/php-ai-media-test_' . getmypid();
rrmdir($tmp);
@mkdir($tmp . '/uploads', 0700, true);
@mkdir($tmp . '/store', 0700, true);

// 造几个真实的小文件
$PNG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$GIF = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
$PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
file_put_contents($tmp . '/uploads/a.png', $PNG);
file_put_contents($tmp . '/uploads/b.gif', $GIF);
file_put_contents($tmp . '/uploads/c.pdf', $PDF);
file_put_contents($tmp . '/uploads/notes.txt', 'hello world');
file_put_contents($tmp . '/uploads/fake.png', '<?php echo "pwned";');   // 改了后缀的伪装文件

// ===================================================================
echo "\n=== 1. MediaReference ===\n";
// ===================================================================

$ref = new MediaReference([
    'id' => 'abc123def456', 'mime' => 'image/png', 'name' => 'a.png', 'bytes' => 100,
]);
assert_eq('uri 格式', 'media://abc123def456', $ref->uri());
assert_eq('从 mime 推断媒体大类', MediaReference::IMAGE, $ref->getMedia());
assert_eq('PDF 大类', MediaReference::PDF, MediaReference::mediaOfMime('application/pdf'));
assert_eq('认不出的 mime 返回空串', '', MediaReference::mediaOfMime('text/plain'));
assert_eq('idOfUri', 'abc123def456', MediaReference::idOfUri('media://abc123def456'));
assert_eq('idOfUri 对非法 uri 返回空串', '', MediaReference::idOfUri('https://x/y.png'));

$block = $ref->toBlock();
assert_eq('块类型', 'agent_media', $block['type']);
assert_eq('块里带 ref', 'media://abc123def456', $block['ref']);
test('块里没有 base64 —— 这是核心约定', !isset($block['data']) && !isset($block['base64']));

$back = MediaReference::fromBlock($block);
test('fromBlock 还原', $back !== null && $back->getId() === 'abc123def456');
assert_eq('fromBlock 对非媒体块返回 null', null, MediaReference::fromBlock(['type' => 'text', 'text' => 'x']));
test('isBlock 识别', MediaReference::isBlock($block) && !MediaReference::isBlock(['type' => 'text']));

// ===================================================================
echo "\n=== 2. Attachment 来源与嗅探 ===\n";
// ===================================================================

$limits = ['max_bytes' => 5242880];

$a = Attachment::fromPath($tmp . '/uploads/a.png');
assert_eq('fromPath 取文件名', 'a.png', $a->getName());
assert_eq('fromPath 来源类型', 'path', $a->getSource());
$bytes = $a->bytes($limits);
assert_eq('fromPath 读到正确字节', $PNG, $bytes);
assert_eq('嗅探出 PNG', 'image/png', $a->detectMime($bytes));

$g = Attachment::fromPath($tmp . '/uploads/b.gif');
assert_eq('嗅探出 GIF', 'image/gif', $g->detectMime($g->bytes($limits)));

$p = Attachment::fromPath($tmp . '/uploads/c.pdf');
assert_eq('嗅探出 PDF', 'application/pdf', $p->detectMime($p->bytes($limits)));

$b64 = Attachment::fromBase64(base64_encode($PNG), 'image/png', 'inline.png');
assert_eq('fromBase64 解码正确', $PNG, $b64->bytes($limits));

$raw = Attachment::fromBase64($PNG, 'image/png', 'raw.png');
assert_eq('fromBase64 也接受原始二进制', $PNG, $raw->bytes($limits));

$dataUri = Attachment::fromBase64('data:image/png;base64,' . base64_encode($PNG));
assert_eq('fromBase64 接受完整 data URI', $PNG, $dataUri->bytes($limits));
assert_eq('data URI 里的 mime 被提取', 'image/png', $dataUri->getDeclaredMime());

// 安全：类型与路径
assert_throws('非图片非 PDF 被拒', function () use ($tmp, $limits) {
    $t = Attachment::fromPath($tmp . '/uploads/notes.txt');
    $t->detectMime($t->bytes($limits));
}, '不支持');

assert_throws('改了后缀的伪装文件被拒', function () use ($tmp, $limits) {
    $f = Attachment::fromPath($tmp . '/uploads/fake.png');
    $f->detectMime($f->bytes($limits));
});

assert_throws('/etc/passwd 这类文件过不了类型校验', function () use ($limits) {
    $f = Attachment::fromPath('/etc/passwd');
    $f->detectMime($f->bytes($limits));
});

assert_throws('文件不存在', function () use ($tmp, $limits) {
    Attachment::fromPath($tmp . '/uploads/nope.png')->bytes($limits);
}, '不存在');

assert_throws('空路径', function () {
    Attachment::fromPath('   ');
}, '为空');

assert_throws('单文件超限', function () use ($tmp) {
    Attachment::fromPath($tmp . '/uploads/a.png')->bytes(['max_bytes' => 10]);
}, '超过单文件上限');

// 目录白名单
$allowed = ['allowed_paths' => [$tmp . '/uploads'], 'max_bytes' => 5242880];
test('白名单内的文件放行', Attachment::fromPath($tmp . '/uploads/a.png')->bytes($allowed) === $PNG);

@mkdir($tmp . '/outside', 0700, true);
file_put_contents($tmp . '/outside/x.png', $PNG);
assert_throws('白名单外的文件被拒', function () use ($tmp, $allowed) {
    Attachment::fromPath($tmp . '/outside/x.png')->bytes($allowed);
}, '不在允许的目录');

// symlink 逃逸：软链在白名单里，目标在外面
if (@symlink($tmp . '/outside/x.png', $tmp . '/uploads/link.png')) {
    assert_throws('symlink 指向白名单外也被拒（realpath 解开了软链）', function () use ($tmp, $allowed) {
        Attachment::fromPath($tmp . '/uploads/link.png')->bytes($allowed);
    }, '不在允许的目录');
} else {
    echo "  （当前环境不支持 symlink，跳过软链逃逸用例）\n";
}

// SSRF：fromUrl 必须走 HttpFetch，内网地址应被拦
assert_throws('fromUrl 拦截回环地址', function () {
    Attachment::fromUrl('http://127.0.0.1/x.png')->bytes(['max_bytes' => 1024]);
}, '下载失败');
assert_throws('fromUrl 拦截云元数据地址', function () {
    Attachment::fromUrl('http://169.254.169.254/latest/meta-data/')->bytes(['max_bytes' => 1024]);
}, '下载失败');
assert_throws('fromUrl 拒绝 file:// 协议', function () {
    Attachment::fromUrl('file:///etc/passwd')->bytes(['max_bytes' => 1024]);
}, '下载失败');

assert_eq('humanBytes', '1 KB', Attachment::humanBytes(1024));

// ===================================================================
echo "\n=== 3. FileMediaStore ===\n";
// ===================================================================

$store = new FileMediaStore($tmp . '/store');
$r1 = $store->put($PNG, ['mime' => 'image/png', 'name' => 'a.png']);
test('put 返回引用', $r1 instanceof MediaReference && $r1->getId() !== '');
assert_eq('put 记录字节数', strlen($PNG), $r1->getBytes());
assert_eq('get 取回原样字节', $PNG, $store->get($r1->getId()));
test('has', $store->has($r1->getId()));
assert_eq('未知 id get 返回 null', null, $store->get('deadbeefdeadbeef'));
test('文件按 mime 取了正确扩展名', is_file($tmp . '/store/' . $r1->getId() . '.png'));
test('sidecar 元数据存在', is_file($tmp . '/store/' . $r1->getId() . '.png.json'));

$stat = $store->stat($r1->getId());
assert_eq('stat 里的 mime', 'image/png', $stat['mime']);
assert_eq('stat 里的 name', 'a.png', $stat['name']);

$r2 = $store->put($PDF, ['mime' => 'application/pdf', 'name' => 'c.pdf']);
test('两次 put 的 id 不同', $r1->getId() !== $r2->getId());
assert_eq('allIds 两条', 2, count($store->allIds()));

// 路径穿越：伪造的 ref 不能读到存储目录外的文件
assert_eq('id 含 ../ 时当作不存在', null, $store->get('../../../etc/passwd'));
assert_eq('id 含非法字符时当作不存在', null, $store->get('abc/../def'));
assert_eq('空 id', null, $store->get(''));

test('delete', $store->delete($r2->getId()));
test('delete 后 sidecar 也没了', !is_file($tmp . '/store/' . $r2->getId() . '.pdf.json'));
assert_eq('delete 后只剩一条', 1, count($store->allIds()));

// gc：不在名单里的删掉
$r3 = $store->put($GIF, ['mime' => 'image/gif', 'name' => 'b.gif']);
assert_eq('gc 删掉未被引用的', 1, $store->gc([$r1->getId()]));
test('被引用的还在', $store->has($r1->getId()));
test('未被引用的没了', !$store->has($r3->getId()));

// prune：按时间
$r4 = $store->put($PNG, ['mime' => 'image/png', 'name' => 'old.png']);
assert_eq('prune 不删今天的', 0, $store->prune(1));
test('今天的还在', $store->has($r4->getId()));

assert_throws('put 空内容被拒', function () use ($store) {
    $store->put('', []);
}, '空的媒体内容');

// ===================================================================
echo "\n=== 4. MediaResolver ===\n";
// ===================================================================

$store2 = new FileMediaStore($tmp . '/store2');
$m1 = $store2->put($PNG, ['mime' => 'image/png', 'name' => 'a.png']);
$m2 = $store2->put($GIF, ['mime' => 'image/gif', 'name' => 'b.gif']);

$resolver = new MediaResolver($store2);
$got = $resolver->resolve($m1->toBlock());
test('resolve 返回结构', is_array($got) && isset($got['base64'], $got['mime'], $got['bytes']));
assert_eq('resolve 的 base64 正确', base64_encode($PNG), $got['base64']);
assert_eq('resolve 的 mime', 'image/png', $got['mime']);
assert_eq('resolve 的 media 大类', 'image', $got['media']);

assert_eq('已用字节累计', strlen($PNG), $resolver->used());
$resolver->resolve($m1->toBlock());
assert_eq('同一媒体重复解析不重复计费', strlen($PNG), $resolver->used());

$resolver->resolve($m2->toBlock());
assert_eq('不同媒体累加', strlen($PNG) + strlen($GIF), $resolver->used());

$resolver->reset();
assert_eq('reset 清零', 0, $resolver->used());

assert_eq('媒体不存在时返回 null 而不是抛', null, $resolver->resolve([
    'type' => 'agent_media', 'ref' => 'media://ffffffffffffffff', 'mime' => 'image/png',
]));
assert_eq('非媒体块返回 null', null, $resolver->resolve(['type' => 'text', 'text' => 'x']));

$tight = new MediaResolver($store2, ['max_request_bytes' => 10]);
assert_throws('单次请求媒体总量超限时抛出', function () use ($tight, $m1) {
    $tight->resolve($m1->toBlock());
}, '媒体总量超限');

$msgs = [
    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'x'], $m1->toBlock()]],
    ['role' => 'user', 'content' => [$m2->toBlock()]],
    ['role' => 'user', 'content' => '纯文本'],
];
assert_eq('idsInMessages 收齐', 2, count(MediaResolver::idsInMessages($msgs)));

// ===================================================================
echo "\n=== 5. MediaManager 三级限额 ===\n";
// ===================================================================

$store3  = new FileMediaStore($tmp . '/store3');
$manager = new MediaManager($store3);

$blocks = $manager->ingest([Attachment::fromPath($tmp . '/uploads/a.png')]);
assert_eq('ingest 产出一个块', 1, count($blocks));
assert_eq('产出的是 agent_media', 'agent_media', $blocks[0]['type']);
test('产出的块不含 base64', !isset($blocks[0]['data']));
assert_eq('落库后能取回', $PNG, $store3->get(MediaReference::idOfUri($blocks[0]['ref'])));

assert_eq('空数组返回空', [], $manager->ingest([]));

$blocks2 = $manager->ingest([
    $tmp . '/uploads/a.png',                       // 字符串路径自动识别
    Attachment::fromPath($tmp . '/uploads/b.gif'),
    ['path' => $tmp . '/uploads/c.pdf'],           // 数组写法
]);
assert_eq('三种写法都能 ingest', 3, count($blocks2));

$countLimited = new MediaManager($store3, ['max_attachments_per_message' => 2]);
assert_throws('单条消息附件个数超限', function () use ($countLimited, $tmp) {
    $countLimited->ingest([
        $tmp . '/uploads/a.png', $tmp . '/uploads/b.gif', $tmp . '/uploads/c.pdf',
    ]);
}, '最多 2 个附件');

$msgLimited = new MediaManager($store3, ['max_message_bytes' => 100]);
assert_throws('单条消息附件总量超限', function () use ($msgLimited, $tmp) {
    $msgLimited->ingest([$tmp . '/uploads/a.png', $tmp . '/uploads/c.pdf']);
}, '总量');

$fileLimited = new MediaManager($store3, ['max_attachment_bytes' => 10]);
assert_throws('单文件超限', function () use ($fileLimited, $tmp) {
    $fileLimited->ingest([$tmp . '/uploads/a.png']);
}, '超过单文件上限');

// 校验失败时不留孤儿文件
$before = count($store3->allIds());
try {
    $manager->ingest([$tmp . '/uploads/a.png', $tmp . '/uploads/notes.txt']);
} catch (MediaException $e) {
    // 预期
}
assert_eq('一个附件不合法时整体失败，不留孤儿文件', $before, count($store3->allIds()));

assert_throws('无法识别的附件写法', function () use ($manager) {
    $manager->ingest([123]);
}, '无法识别');

// ===================================================================
echo "\n=== 6. MessagePart ===\n";
// ===================================================================

$mediaBlock = $blocks[0];

assert_eq('compose 无媒体时返回字符串', '你好', MessagePart::compose('你好', []));
$composed = MessagePart::compose('看图', [$mediaBlock]);
test('compose 有媒体时返回块数组', is_array($composed) && count($composed) === 2);
assert_eq('第一块是文本', 'text', $composed[0]['type']);
assert_eq('第二块是媒体', 'agent_media', $composed[1]['type']);

$composedNoText = MessagePart::compose('', [$mediaBlock]);
assert_eq('正文为空时只有媒体块', 1, count($composedNoText));

// 追加语义：不覆盖已有内容（坑③的修复原则）
$toolContent = [
    ['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => '读取完成'],
];
$appended = MessagePart::append($toolContent, [$mediaBlock]);
assert_eq('追加后仍有两块', 2, count($appended));
assert_eq('原 tool_result 没被覆盖', 'tool_result', $appended[0]['type']);
assert_eq('媒体被追加在后面', 'agent_media', $appended[1]['type']);

$appendedStr = MessagePart::append('原文本', [$mediaBlock]);
test('对字符串 content 追加会转成块数组', is_array($appendedStr) && count($appendedStr) === 2);
assert_eq('无媒体时 append 原样返回', '原文本', MessagePart::append('原文本', []));

test('contentHasMedia', MessagePart::contentHasMedia($composed));
test('纯文本 content 没有媒体', !MessagePart::contentHasMedia('你好'));
test('messagesHaveMedia', MessagePart::messagesHaveMedia([['role' => 'user', 'content' => $composed]]));
assert_eq('mediaBlocksIn 收齐', 1, count(MessagePart::mediaBlocksIn([['role' => 'user', 'content' => $composed]])));

assert_eq('toText 纯文本', '你好', MessagePart::toText('你好'));
test('toText 把媒体渲染成占位说明', strpos(MessagePart::toText($composed), '[image:') !== false);

// ===================================================================
echo "\n=== 7. AgentHome::mediaDir 隔离规则 ===\n";
// ===================================================================

$home = new AgentHome($tmp, ['home' => $tmp . '/home']);
$projectMedia = $home->mediaDir();
test('项目级 media 目录在 projects/ 下', strpos($projectMedia, '/projects/') !== false);
test('media 与 sessions 同级', dirname($projectMedia) === dirname($home->sessionDir('s1')));
assert_eq('media 目录名', 'media', basename($projectMedia));

$userMedia = $home->mediaDir('user-42');
test('用户级 media 目录在 users/ 下', strpos($userMedia, '/users/') !== false);
test('不同用户的 media 目录不同', $home->mediaDir('user-42') !== $home->mediaDir('user-99'));
test('原始 userId 不出现在路径里', strpos($userMedia, 'user-42') === false);
test('用户级 media 与用户级 sessions 同级',
    dirname($userMedia) === dirname($home->sessionDir('s1', 'user-42')));

echo "\n=== 清理 ===\n";
rrmdir($tmp);
test('临时目录已清理', !is_dir($tmp));

echo "\n========================================\n";
echo "通过: {$passed}  失败: {$failed}\n";
echo "========================================\n";
exit($failed > 0 ? 1 : 0);
