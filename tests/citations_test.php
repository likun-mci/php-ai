<?php
/**
 * 联网搜索「来源与引用」归一化测试
 *
 * 全离线。报文结构取自各平台官方文档的原样示例（出处写在每段上方），
 * Gemini 的字节偏移另经 tests/live/citations_live_test.php 实网络核对过。
 *
 * 运行：php tests/citations_test.php
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/fixtures/FakeTransport.php';

use Ai\AI;
use Ai\Helpers\Citations;
use Ai\Helpers\StreamCitationCollector;
use Tests\Fixtures\FakeTransport;

$passed = 0;
$failed = 0;

/**
 * @param mixed $actual
 */
function check(bool $ok, string $name, $actual = null): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ✓ {$name}\n";
        return;
    }
    $failed++;
    echo "  ✗ {$name}";
    if ($actual !== null) {
        echo ' —— 实际: ' . (is_scalar($actual) ? $actual : json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    echo "\n";
}

/**
 * 按帧回放流式分片的假传输层：直接把解码后的分片数组交给流式回调
 */
class FrameTransport extends FakeTransport
{
    /** @var array<int, array<string, mixed>> */
    public $frames = [];

    public function post(string $url, array $data, array $headers = []): array
    {
        if ($this->streamCallback === null) {
            return parent::post($url, $data, $headers);
        }
        $this->requests[] = ['method' => 'POST', 'url' => $url, 'data' => $data, 'headers' => $headers];
        foreach ($this->frames as $frame) {
            call_user_func($this->streamCallback, $frame);
        }
        return [];
    }
}

/**
 * @param array<string, mixed> $config
 * @param array<string, mixed> $response
 */
function chatOnce(array $config, array $response): \Ai\Response\AIResponse
{
    $fake = new FakeTransport();
    $fake->queuePost($response);
    $ai = new AI(array_merge(['api_key' => 'sk-test'], $config));
    $ai->setTransport($fake);
    /** @var \Ai\Response\AIResponse $r */
    $r = $ai->chat('问题');
    return $r;
}

/**
 * @param array<string, mixed>             $config
 * @param array<int, array<string, mixed>> $frames
 * @param array<string, mixed>|null        $endData 引用传出 stream_end 事件的 data
 */
function chatStream(array $config, array $frames, &$endData = null): \Ai\Response\AIResponse
{
    $tr = new FrameTransport();
    $tr->frames = $frames;
    $ai = new AI(array_merge(['api_key' => 'sk-test'], $config));
    $ai->setTransport($tr);
    $ai->setStream(true)->setStreamCallback(function ($event) use (&$endData) {
        if ($event['type'] === 'stream_end') {
            $endData = $event['data'];
        }
    });
    /** @var \Ai\Response\AIResponse $r */
    $r = $ai->chat('问题');
    return $r;
}

/**
 * 去掉 raw 后比较，流式拼回的 raw 与非流式本来就不同源
 *
 * @param array<int, array<string, mixed>> $items
 * @return array<int, array<string, mixed>>
 */
function strip(array $items): array
{
    foreach ($items as $k => $v) {
        unset($items[$k]['raw']);
    }
    return $items;
}

function slice(string $content, array $c): string
{
    return mb_substr($content, (int) $c['start'], (int) $c['end'] - (int) $c['start'], 'UTF-8');
}

// ──────────────────────────────────────────────────────────────
echo "\n[Claude] 非流式\n";
// 出处：platform.claude.com/docs/en/agents-and-tools/tool-use/web-search-tool（响应示例 + 错误示例）
$claudeContent = [
    ['type' => 'text', 'text' => '我来查一下。'],
    ['type' => 'server_tool_use', 'id' => 'srvtoolu_01', 'name' => 'web_search', 'input' => ['query' => 'claude shannon birth date']],
    ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_01', 'content' => [
        ['type' => 'web_search_result', 'url' => 'https://en.wikipedia.org/wiki/Claude_Shannon',
         'title' => 'Claude Shannon - Wikipedia', 'encrypted_content' => 'EqgfCioIARgBIiQ3YTAw...', 'page_age' => 'April 30, 2025'],
        ['type' => 'web_search_result', 'url' => 'https://www.britannica.com/biography/Claude-Shannon',
         'title' => 'Claude Shannon | Britannica', 'encrypted_content' => 'Eo8B...', 'page_age' => null],
    ]],
    ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_02',
     'content' => ['type' => 'web_search_tool_result_error', 'error_code' => 'max_uses_exceeded']],
    ['type' => 'text', 'text' => '香农生于 1916 年 4 月 30 日，出生地是密歇根州佩托斯基',
     'citations' => [[
         'type' => 'web_search_result_location', 'url' => 'https://en.wikipedia.org/wiki/Claude_Shannon',
         'title' => 'Claude Shannon - Wikipedia', 'encrypted_index' => 'Eo8BCioIAhgBIiQyYjQ0OWJmZi1lNm..',
         'cited_text' => 'Claude Elwood Shannon (April 30, 1916 – February 24, 2001) was ...',
     ]]],
    ['type' => 'text', 'text' => '。'],
];
$r = chatOnce(['protocol' => 'claude', 'model' => 'claude-sonnet-5'], [
    'model' => 'claude-sonnet-5', 'content' => $claudeContent, 'stop_reason' => 'end_turn',
    'usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'server_tool_use' => ['web_search_requests' => 1]],
]);
$src = $r->getSources();
$cit = $r->getCitations();
check(count($src) === 2, '搜索失败的 error 块被跳过，拿到 2 条来源', count($src));
check($src[0]['index'] === 1 && $src[1]['index'] === 2, '来源按顺序编号');
check($src[0]['published_at'] === 'April 30, 2025' && $src[1]['published_at'] === '', 'page_age 为 null 时是空串');
check($src[0]['cited'] === true && $src[1]['cited'] === false, 'cited 区分「用上了」与「只搜到」');
check(count($cit) === 1, '一条引用', count($cit));
check($cit[0]['source_index'] === 0 && $cit[0]['type'] === 'web_search_result_location', 'source_index 与原始类型');
check(slice($r->getContent(), $cit[0]) === '香农生于 1916 年 4 月 30 日，出生地是密歇根州佩托斯基',
    '引用区间 = 所在 text 块在正文里的位置（中文按字符计）', slice($r->getContent(), $cit[0]));
check(strpos($cit[0]['cited_text'], 'Claude Elwood Shannon') === 0, 'cited_text 是来源原文');
check(isset($src[0]['raw']['encrypted_content']), 'raw 保留平台原始条目');
check(isset($r->toArray()['sources'], $r->toArray()['citations']), 'toArray() 带上 sources / citations');

echo "\n[Claude] 流式与非流式结果一致\n";
// 出处：platform.claude.com/docs/en/build-with-claude/streaming（web search 示例）
//      platform.claude.com/docs/en/build-with-claude/citations（citations_delta）
$frames = [
    ['type' => 'message_start', 'message' => ['model' => 'claude-sonnet-5', 'usage' => ['input_tokens' => 10, 'output_tokens' => 1]]],
    ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
    ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => '我来查一下。']],
    ['type' => 'content_block_stop', 'index' => 0],
    ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'server_tool_use', 'id' => 'srvtoolu_01', 'name' => 'web_search', 'input' => []]],
    ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"query":"claude shannon"}']],
    ['type' => 'content_block_stop', 'index' => 1],
    ['type' => 'content_block_start', 'index' => 2, 'content_block' => $claudeContent[2]],
    ['type' => 'content_block_stop', 'index' => 2],
    ['type' => 'content_block_start', 'index' => 3, 'content_block' => $claudeContent[3]],
    ['type' => 'content_block_stop', 'index' => 3],
    ['type' => 'content_block_start', 'index' => 4, 'content_block' => ['type' => 'text', 'text' => '']],
    ['type' => 'content_block_delta', 'index' => 4, 'delta' => ['type' => 'citations_delta', 'citation' => $claudeContent[4]['citations'][0]]],
    ['type' => 'content_block_delta', 'index' => 4, 'delta' => ['type' => 'text_delta', 'text' => '香农生于 1916 年 4 月 30 日，']],
    ['type' => 'content_block_delta', 'index' => 4, 'delta' => ['type' => 'text_delta', 'text' => '出生地是密歇根州佩托斯基']],
    ['type' => 'content_block_stop', 'index' => 4],
    ['type' => 'content_block_start', 'index' => 5, 'content_block' => ['type' => 'text', 'text' => '']],
    ['type' => 'content_block_delta', 'index' => 5, 'delta' => ['type' => 'text_delta', 'text' => '。']],
    ['type' => 'content_block_stop', 'index' => 5],
    ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 20]],
    ['type' => 'message_stop'],
];
$endData = null;
$s = chatStream(['protocol' => 'claude', 'model' => 'claude-sonnet-5'], $frames, $endData);
check($s->getContent() === $r->getContent(), '流式正文与非流式一致');
check(strip($s->getSources()) === strip($r->getSources()), '流式 sources 与非流式一致', strip($s->getSources()));
check(strip($s->getCitations()) === strip($r->getCitations()), '流式 citations 与非流式一致', strip($s->getCitations()));
check(isset($endData['sources'][0]['url']) && !isset($endData['sources'][0]['raw']), 'stream_end 事件带 sources，且不下发 raw');

// ──────────────────────────────────────────────────────────────
echo "\n[OpenAI / OpenRouter] annotations\n";
// 出处：developers.openai.com/api/docs/guides/tools-web-search?api-mode=chat（嵌套在 url_citation 下）
//      openrouter.ai/docs/features/web-search（嵌套 + content）、openrouter.ai/openapi.json URLCitation（平铺）
$text = 'OpenAI 发布了新模型。详情见官网公告。';
$r = chatOnce(['protocol' => 'openai', 'model' => 'gpt-5-search-api'], [
    'model' => 'gpt-5-search-api',
    'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => [
        'role' => 'assistant', 'content' => $text, 'refusal' => null,
        'annotations' => [
            ['type' => 'url_citation', 'url_citation' => ['start_index' => 0, 'end_index' => 13, 'title' => 'OpenAI News', 'url' => 'https://openai.com/news']],
            ['type' => 'url_citation', 'url' => 'https://example.com/a', 'title' => 'A', 'start_index' => 12, 'end_index' => 21, 'content' => '摘要'],
            ['type' => 'url_citation', 'url_citation' => ['start_index' => 12, 'end_index' => 21, 'title' => 'OpenAI News', 'url' => 'https://openai.com/news']],
        ],
    ]]],
]);
$cit = $r->getCitations();
$src = $r->getSources();
check(count($cit) === 3, '嵌套与平铺两种写法都认', count($cit));
check(count($src) === 2 && $src[0]['url'] === 'https://openai.com/news', '没有检索列表时，sources 由被引用 URL 去重得到', $src);
check($src[0]['cited'] && $src[1]['cited'], '此时每条来源都是 cited');
check($cit[2]['source_index'] === 0 && $cit[1]['source_index'] === 1, 'source_index 回填');
check($cit[1]['cited_text'] === '摘要', 'OpenRouter 的 content 作为 cited_text');
check(slice($text, $cit[0]) === 'OpenAI 发布了新模型', 'start / end 按字符截取', slice($text, $cit[0]));

echo "\n[OpenAI 系] 流式 annotations\n";
$endData = null;
$s = chatStream(['protocol' => 'openrouter', 'model' => 'openai/gpt-4o-mini'], [
    ['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'OpenAI 发布了新模型。']]]],
    ['choices' => [['index' => 0, 'delta' => ['content' => '详情见官网公告。']]]],
    ['choices' => [['index' => 0, 'delta' => ['content' => '', 'annotations' => [
        ['type' => 'url_citation', 'url_citation' => ['start_index' => 0, 'end_index' => 12, 'title' => 'OpenAI News', 'url' => 'https://openai.com/news']],
    ]], 'finish_reason' => 'stop']]],
], $endData);
check(count($s->getCitations()) === 1 && $s->getSources()[0]['url'] === 'https://openai.com/news', '分片里的 annotations 被收集');

echo "\n[OpenRouter] 空区间用正文里的 markdown 链接定位（实测结构）\n";
// 出处：2026-09 实测 OpenRouter（deepseek/deepseek-chat-v3.1 + web 插件）——start_index / end_index 全为 0
$text = '西班牙夺冠 [olympics.com](https://o.com/a)。另见 [bbc.com](https://b.com/x)，以及 [olympics.com](https://o.com/a)。';
$res = Citations::fromOpenAi(['choices' => [['message' => ['content' => $text, 'annotations' => [
    ['type' => 'url_citation', 'url_citation' => ['url' => 'https://o.com/a', 'title' => 'O', 'start_index' => 0, 'end_index' => 0]],
    ['type' => 'url_citation', 'url_citation' => ['url' => 'https://b.com/x', 'title' => 'B', 'start_index' => 0, 'end_index' => 0]],
    ['type' => 'url_citation', 'url_citation' => ['url' => 'https://o.com/a', 'title' => 'O', 'start_index' => 0, 'end_index' => 0]],
    ['type' => 'url_citation', 'url_citation' => ['url' => 'https://none.com', 'title' => 'N', 'start_index' => 0, 'end_index' => 0]],
]]]]], $text);
$c = $res['citations'];
check(slice($text, $c[0]) === '[olympics.com](https://o.com/a)' && slice($text, $c[1]) === '[bbc.com](https://b.com/x)', '0-0 区间改用正文里的链接位置');
check($c[2]['start'] > $c[0]['start'] && slice($text, $c[2]) === '[olympics.com](https://o.com/a)', '同一 URL 多次引用依次取用');
check(count($c) === 3, '正文里找不到链接的 annotation 不算引用', count($c));
$none = array_values(array_filter($res['sources'], function ($x) { return $x['url'] === 'https://none.com'; }));
check(count($none) === 1 && $none[0]['cited'] === false && count($res['sources']) === 3, '它降级为未被引用的来源');

echo "\n[没开搜索] 行为与旧版一致\n";
$endData = null;
$s = chatStream(['protocol' => 'openai', 'model' => 'gpt-4o'], [
    ['choices' => [['index' => 0, 'delta' => ['content' => '你好 [1]']]]],
], $endData);
check($s->getSources() === [] && $s->getCitations() === [], '无来源时两个列表都是空数组');
check(is_array($endData) && !array_key_exists('sources', $endData), 'stream_end 不多出 sources 键');

// ──────────────────────────────────────────────────────────────
echo "\n[Perplexity] search_results + citations + [n] 角标\n";
// 出处：docs.perplexity.ai/docs/sonar/features.md、docs.perplexity.ai/api-reference/chat-completions-post
$text = 'RAG 有多种架构[1][2]。其中检索增强最常见[2]。代码里的 $arr[1] 不是引用，[9] 也不是。';
$ppl = [
    'id' => 'x', 'model' => 'sonar', 'object' => 'chat.completion',
    'citations' => ['https://humanloop.com/blog/rag-architectures', 'https://learn.microsoft.com/rag'],
    'search_results' => [
        ['title' => '8 RAG Architectures', 'url' => 'https://humanloop.com/blog/rag-architectures',
         'date' => '2025-02-01', 'last_updated' => '2026-05-19', 'snippet' => 'Unlike traditional models ...', 'source' => 'web'],
        ['title' => 'RAG overview', 'url' => 'https://learn.microsoft.com/rag', 'date' => null, 'last_updated' => null, 'snippet' => '', 'source' => 'web'],
    ],
    'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $text]]],
];
$r = chatOnce(['protocol' => 'perplexity', 'model' => 'sonar'], $ppl);
$src = $r->getSources();
$cit = $r->getCitations();
check(count($src) === 2, 'search_results 与 citations 同批网页不重复计入', count($src));
check($src[0]['snippet'] === 'Unlike traditional models ...' && $src[0]['published_at'] === '2025-02-01', 'snippet / date 映射');
check(count($cit) === 3, '三个有效角标，$arr[1] 与越界的 [9] 不认', array_map(function ($c) { return $c['raw']['marker']; }, $cit));
check(slice($text, $cit[0]) === '[1]' && $cit[0]['source_index'] === 0, '角标区间截出的正是角标');
check($cit[2]['url'] === 'https://learn.microsoft.com/rag' && $cit[2]['type'] === 'marker', '角标反查到 URL');

// 流式：出处同上，「search results are delivered in the final chunk(s)」
$s = chatStream(['protocol' => 'perplexity', 'model' => 'sonar'], [
    ['id' => 'x', 'object' => 'chat.completion.chunk', 'choices' => [['index' => 0, 'delta' => ['content' => 'RAG 有多种架构[1][2]。']]]],
    ['id' => 'x', 'object' => 'chat.completion.chunk', 'choices' => [['index' => 0, 'delta' => ['content' => '其中检索增强最常见[2]。代码里的 $arr[1] 不是引用，[9] 也不是。'], 'finish_reason' => 'stop']],
     'citations' => $ppl['citations'], 'search_results' => $ppl['search_results']],
]);
check(strip($s->getSources()) === strip($r->getSources()) && strip($s->getCitations()) === strip($r->getCitations()), '流式结果与非流式一致');

// ──────────────────────────────────────────────────────────────
echo "\n[文心一言] search_results + ^[n]^ 角标\n";
// 出处：cloud.baidu.com/doc/qianfan-api/s/3m7of64lb、cloud.baidu.com/doc/qianfan-docs/s/Wm8r4sw29
$ernieResults = [
    ['index' => 1, 'url' => 'https://live.nowscore.com/analysis/2607195.html', 'title' => '足球让球分析'],
    ['index' => 2, 'url' => 'https://sports.163.com/world/', 'title' => '国际足球'],
];
$text = '都灵主场作战^[1]^，近期状态一般^[1][2]^。';
$r = chatOnce(['protocol' => 'ernie', 'model' => 'ernie-4.5-turbo-32k'], [
    'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $text]]],
    'search_results' => $ernieResults,
]);
$cit = $r->getCitations();
check(count($r->getSources()) === 2 && $r->getSources()[1]['index'] === 2, '沿用平台 index');
check(count($cit) === 3, '^[1]^ 与 ^[1][2]^ 共 3 个角标', count($cit));
check(slice($text, $cit[0]) === '^[1]^', '单角标区间含 ^ 包裹', slice($text, $cit[0]));
check($cit[2]['source_index'] === 1, '^[1][2]^ 的第二个角标指向第 2 条来源');

// 流式：首个内容帧与结束帧各带一次 search_results（官方流式示例）
$s = chatStream(['protocol' => 'ernie', 'model' => 'ernie-4.5-turbo-32k'], [
    ['object' => 'chat.completion.chunk', 'choices' => [['index' => 0, 'delta' => ['content' => '都灵主场作战^[1]^，', 'role' => 'assistant'], 'flag' => 0]], 'search_results' => $ernieResults],
    ['object' => 'chat.completion.chunk', 'choices' => [['index' => 0, 'delta' => ['content' => '近期状态一般^[1][2]^'], 'flag' => 0]]],
    ['object' => 'chat.completion.chunk', 'choices' => [['index' => 0, 'delta' => ['content' => '。'], 'finish_reason' => 'stop', 'flag' => 0]], 'search_results' => $ernieResults],
]);
check(count($s->getSources()) === 2 && strip($s->getCitations()) === strip($r->getCitations()), '重复下发的 search_results 不重复计入，结果与非流式一致');

// ──────────────────────────────────────────────────────────────
echo "\n[智谱] web_search\n";
// 出处：docs.bigmodel.cn/api-reference/模型-api/对话补全（WebSearch 字段说明），refer 取值见 Web Search API 示例
$r = chatOnce(['protocol' => 'zhipu', 'model' => 'glm-4-plus'], [
    'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '据报道，新品下月发布。']]],
    'web_search' => [
        ['icon' => 'https://x/icon.png', 'title' => '新品发布', 'link' => 'https://www.sohu.com/a/1', 'media' => '搜狐',
         'publish_date' => '2025-05-23', 'content' => '新品将于下月发布', 'refer' => 'ref_3'],
    ],
]);
$src = $r->getSources();
check(count($src) === 1 && $src[0]['url'] === 'https://www.sohu.com/a/1', 'link 映射为 url');
check($src[0]['index'] === 3 && $src[0]['site_name'] === '搜狐' && $src[0]['snippet'] === '新品将于下月发布', 'refer / media / content 映射');
check($r->getCitations() === [], '正文没有角标时不编造引用');

// ──────────────────────────────────────────────────────────────
echo "\n[通义千问] DashScope 原生结构\n";
// 出处：help.aliyun.com/zh/model-studio/web-search（output.search_info、[ref_1] 与 [ref_无]）
$text = '气温直降近10℃[ref_1][ref_2]，具体以当地预报为准[ref_无]。';
$res = Citations::fromOpenAi([
    'output' => [
        'choices' => [['message' => ['content' => $text, 'role' => 'assistant']]],
        'search_info' => ['extra_tool_info' => [], 'search_results' => [
            ['icon' => 'https://baijiahao.baidu.com/favicon.ico', 'site_name' => '百家号', 'index' => 1, 'title' => '降温', 'url' => 'https://baijiahao.baidu.com/s?id=1'],
            ['icon' => '', 'site_name' => '中国天气网', 'index' => 2, 'title' => '预报', 'url' => 'https://weather.com.cn/1'],
        ]],
    ],
], $text);
check(count($res['sources']) === 2 && $res['sources'][0]['site_name'] === '百家号', 'output.search_info 识别');
check(count($res['citations']) === 2 && slice($text, $res['citations'][1]) === '[ref_2]', '[ref_n] 角标识别，[ref_无] 不认');

// ──────────────────────────────────────────────────────────────
echo "\n[xAI] [[N]](url) 行内引用\n";
// 出处：docs.x.ai/developers/tools/citations.md —— N 是显示序号，不是 citations 列表下标
$text = 'xAI 成立于 2023 年[[1]](https://x.ai/company)，总部在旧金山[[2]](https://en.wikipedia.org/wiki/XAI)。';
$r = chatOnce(['protocol' => 'grok', 'model' => 'grok-4'], [
    'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $text]]],
    'citations' => ['https://news.example.com/x', 'https://en.wikipedia.org/wiki/XAI', 'https://x.ai/company'],
]);
$cit = $r->getCitations();
check(count($cit) === 2, '两条行内引用', count($cit));
check($cit[0]['url'] === 'https://x.ai/company', '[[1]] 取链接里的 URL，而不是 citations[0]', $cit[0]['url']);
check($r->getSources()[$cit[0]['source_index']]['url'] === 'https://x.ai/company', 'source_index 指向同 URL 来源');
check(slice($text, $cit[1]) === '[[2]](https://en.wikipedia.org/wiki/XAI)', '区间覆盖整个行内链接');
check($r->getSources()[0]['cited'] === false, '列表里未被引用的来源 cited=false');

// ──────────────────────────────────────────────────────────────
echo "\n[Gemini] groundingMetadata 字节偏移\n";
// 出处：ai.google.dev/gemini-api/docs/generate-content/google-search、ai.google.dev/api/generate-content#Segment
// 「Start index in the given Part, measured in bytes」——中文每字 3 字节，换算错了截出来的字会错位
$part0 = '西班牙夺得冠军。';
$part1 = '决赛在纽约举行。';
$gem = [
    'candidates' => [[
        'content' => ['role' => 'model', 'parts' => [
            ['text' => '先想想……', 'thought' => true],
            ['text' => $part0],
            ['text' => $part1],
        ]],
        'groundingMetadata' => [
            'webSearchQueries' => ['世界杯冠军'],
            'groundingChunks' => [
                ['web' => ['uri' => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/A', 'title' => 'fifa.com']],
                ['web' => ['uri' => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/B', 'title' => 'uefa.com']],
            ],
            'groundingSupports' => [
                ['segment' => ['partIndex' => 1, 'endIndex' => strlen('西班牙夺得冠军'), 'text' => '西班牙夺得冠军'], 'groundingChunkIndices' => [0, 1]],
                ['segment' => ['partIndex' => 2, 'startIndex' => strlen('决赛在'), 'endIndex' => strlen('决赛在纽约'), 'text' => '纽约'], 'groundingChunkIndices' => [1]],
            ],
        ],
    ]],
    'modelVersion' => 'gemini-2.5-flash',
];
$r = (new \Ai\Protocol\Gemini())->parseResponse($gem);
$cit = $r->getCitations();
check($r->getContent() === $part0 . $part1, '原生结构拼接全部 text part，跳过 thought', $r->getContent());
check(count($cit) === 3, '一条 support 指向多个 chunk 时拆开', count($cit));
check(slice($r->getContent(), $cit[0]) === '西班牙夺得冠军', 'startIndex 缺省为 0', slice($r->getContent(), $cit[0]));
check(slice($r->getContent(), $cit[2]) === '纽约' && $cit[2]['cited_text'] === '纽约', '按 partIndex 定位后字节换字符', slice($r->getContent(), $cit[2]));
check($r->getSources()[1]['title'] === 'uefa.com' && $cit[2]['source_index'] === 1, '来源与 source_index');

// 流式：groundingChunks 逐帧增量，下标跨帧累积
$c = new StreamCitationCollector();
$c->feed(['candidates' => [['content' => ['parts' => [['text' => $part0]]]]]], $part0);
$c->feed(['candidates' => [['content' => ['parts' => [['text' => $part1]]], 'groundingMetadata' => [
    'groundingChunks' => [$gem['candidates'][0]['groundingMetadata']['groundingChunks'][0]],
    'groundingSupports' => [['segment' => ['endIndex' => strlen('西班牙夺得冠军'), 'text' => '西班牙夺得冠军'], 'groundingChunkIndices' => [0]]],
]]]], $part1);
$c->feed(['candidates' => [['groundingMetadata' => [
    'groundingChunks' => [$gem['candidates'][0]['groundingMetadata']['groundingChunks'][1]],
    'groundingSupports' => [['segment' => ['startIndex' => strlen($part0 . '决赛在'), 'endIndex' => strlen($part0 . '决赛在纽约'), 'text' => '纽约'], 'groundingChunkIndices' => [1]]],
]]]], null);
$res = $c->result($part0 . $part1);
check(count($res['sources']) === 2 && count($res['citations']) === 2, '跨帧累积 chunks 与 supports');
check(slice($part0 . $part1, $res['citations'][1]) === '纽约' && $res['citations'][1]['url'] === $gem['candidates'][0]['groundingMetadata']['groundingChunks'][1]['web']['uri'],
    '后续帧的 chunk 下标指向累积后的位置');

// ──────────────────────────────────────────────────────────────
echo "\n[通用] 边界\n";
check(Citations::fromOpenAi(['choices' => [['message' => ['content' => '见 [1]']]]], '见 [1]') === ['sources' => [], 'citations' => []],
    '没有来源时不解析 [n] 角标');
check(Citations::fromGemini([], '') === ['sources' => [], 'citations' => []], 'Gemini 无 groundingMetadata 返回空');
$r = new \Ai\Response\AIResponse(['content' => 'x']);
check($r->getSources() === [] && $r->getCitations() === [], '直接构造的 AIResponse 默认空数组');

echo "\n通过 {$passed}，失败 {$failed}\n";
exit($failed > 0 ? 1 : 0);
