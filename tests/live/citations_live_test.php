<?php
/**
 * 联网搜索「来源与引用」实网络测试（默认跳过）
 *
 * 对每个有密钥的平台各发一次非流式、一次流式的联网搜索请求，校验：
 *
 *   1. getSources() / getCitations() 解析出了数据（平台确实返回时）
 *   2. 每条引用的 start / end 落在正文范围内，source_index 指向真实存在的来源
 *   3. 位置口径正确——能对照的都对照原文：
 *        Gemini  引用区间截出来的文字 === 平台给的 segment.text（验证字节→字符换算）
 *        角标类   引用区间截出来的恰好是一个角标
 *   4. 流式与非流式走同一套解析，流式同样拿得到
 *
 * 密钥从 .claude/secrets/<平台>.key 读，缺哪个跳哪个，全缺时退出码为 0。
 * 中国大陆以外的平台走 LIVE_PROXY（默认 http://127.0.0.1:8620），国内平台直连。
 * 原始响应落到 LIVE_DUMP_DIR（默认系统临时目录），方便对照平台真实结构。
 *
 * 运行：php tests/live/citations_live_test.php [平台名 ...]
 */

require __DIR__ . '/../../autoload.php';

use Ai\AI;
use Ai\Helpers\Citations;
use Ai\Helpers\StreamCitationCollector;
use Ai\Transport\CurlTransport;

$passed = 0;
$failed = 0;
$ran    = [];

function test($name, $ok, $detail = '')
{
    global $passed, $failed;
    if ($ok) { $passed++; echo "  ✓ {$name}\n"; }
    else { $failed++; echo "  ✗ {$name}" . ($detail !== '' ? " —— {$detail}" : '') . "\n"; }
}
function section($title)
{
    echo "\n" . str_repeat('=', 62) . "\n" . $title . "\n" . str_repeat('=', 62) . "\n";
}
function secret($name)
{
    $f = __DIR__ . '/../../.claude/secrets/' . $name . '.key';
    return is_file($f) ? trim((string) file_get_contents($f)) : '';
}
function dump_raw($name, $data)
{
    $dir = getenv('LIVE_DUMP_DIR') ?: sys_get_temp_dir();
    @mkdir($dir, 0700, true);
    $path = rtrim($dir, '/') . '/citations_' . $name . '.json';
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "  · 原始数据：{$path}\n";
}

/**
 * 通用结构校验，返回可读摘要
 *
 * @param array<int, array<string, mixed>> $sources
 * @param array<int, array<string, mixed>> $citations
 */
function check_shape($label, $content, array $sources, array $citations)
{
    $len = mb_strlen($content, 'UTF-8');
    echo "  · {$label}：正文 {$len} 字，来源 " . count($sources) . " 条（被引用 "
        . count(array_filter($sources, function ($s) { return $s['cited']; })) . "），引用 " . count($citations) . " 条\n";

    $badRange = [];
    $badIndex = [];
    foreach ($citations as $i => $c) {
        if ($c['start'] !== null && ($c['start'] < 0 || $c['end'] === null || $c['end'] > $len || $c['start'] > $c['end'])) {
            $badRange[] = $i . ':' . $c['start'] . '-' . $c['end'];
        }
        if ($c['url'] !== '' && ($c['source_index'] === null || !isset($sources[$c['source_index']])
            || $sources[$c['source_index']]['url'] !== $c['url'])) {
            $badIndex[] = $i;
        }
    }
    test("{$label} 引用区间都在正文范围内", !$badRange, implode(', ', array_slice($badRange, 0, 5)));
    test("{$label} source_index 都指向同 URL 的来源", !$badIndex, implode(', ', $badIndex));

    $markers = array_filter($citations, function ($c) { return $c['type'] === 'marker'; });
    if ($markers) {
        $bad = [];
        foreach ($markers as $c) {
            $piece = mb_substr($content, $c['start'], $c['end'] - $c['start'], 'UTF-8');
            if (!preg_match(Citations::MARKER_PATTERN, $piece) && !preg_match(Citations::LINK_MARKER_PATTERN, $piece)) {
                $bad[] = $piece;
            }
        }
        test("{$label} 角标区间截出来的都是角标", !$bad, implode(' | ', array_slice($bad, 0, 3)));
    }

    // 打几条样例，人工对照
    foreach (array_slice($citations, 0, 3) as $c) {
        $piece = $c['start'] !== null ? mb_substr($content, $c['start'], min(60, $c['end'] - $c['start']), 'UTF-8') : '';
        echo '    - [' . $c['type'] . '] ' . $c['url'] . "\n      正文「" . str_replace("\n", ' ', $piece) . "」\n";
    }
}

/**
 * 经本库 AI::chat() 跑一个平台：非流式 + 流式
 *
 * @param array<string, mixed> $config
 * @param bool $expectSources 平台按文档应返回来源时为 true
 */
function run_platform($name, array $config, $question, $expectSources, $expectCitations)
{
    global $ran;
    $ran[] = $name;
    section($name . '（' . $config['model'] . '）');

    try {
        $ai   = new AI($config);
        $resp = $ai->chat($question);
        // 是否搜索由模型自己决定，偶尔会凭旧知识直接答——没拿到来源就重试，最多 3 次
        for ($i = 0; $expectSources && !$resp->getSources() && $i < 2; $i++) {
            echo "  · 本次模型未返回来源，重试\n";
            $resp = (new AI($config))->chat($question);
        }
        dump_raw($name, $resp->getRaw());
        check_shape('非流式', $resp->getContent(), $resp->getSources(), $resp->getCitations());
        if ($expectSources) {
            test('非流式 拿到来源', count($resp->getSources()) > 0);
        }
        if ($expectCitations) {
            test('非流式 拿到引用', count($resp->getCitations()) > 0);
        }
    } catch (\Throwable $e) {
        test('非流式 请求成功', false, $e->getMessage());
    }

    try {
        $frames = [];
        $endData = null;
        $ai = new AI($config);
        $ai->setStream(true)->setStreamCallback(function ($event) use (&$frames, &$endData) {
            if ($event['type'] === 'stream_chunk') {
                $frames[] = $event['raw'];
            } elseif ($event['type'] === 'stream_end') {
                $endData = $event['data'];
            }
        });
        $resp = $ai->chat($question);
        for ($i = 0; $expectSources && !$resp->getSources() && $i < 2; $i++) {
            echo "  · 本次模型未返回来源，重试\n";
            $frames = [];
            $resp = $ai->chat($question);
        }
        dump_raw($name . '_stream', $frames);
        check_shape('流式', $resp->getContent(), $resp->getSources(), $resp->getCitations());
        if ($expectSources) {
            test('流式 拿到来源', count($resp->getSources()) > 0);
            test('流式 stream_end 事件带 sources', !empty($endData['sources']));
        }
        if ($expectCitations) {
            test('流式 拿到引用', count($resp->getCitations()) > 0);
        }
    } catch (\Throwable $e) {
        test('流式 请求成功', false, $e->getMessage());
    }
}

$only  = array_slice($argv, 1);
$want  = function ($name) use ($only) { return !$only || in_array($name, $only, true); };
$proxy = getenv('LIVE_PROXY') ?: 'http://127.0.0.1:8620';
$q     = '2026 年最近一次 FIFA 世界杯的冠军是哪支球队？请给出处。';

if ($want('claude') && ($k = secret('claude')) !== '') {
    run_platform('claude', [
        'api_key' => $k, 'protocol' => 'claude', 'model' => getenv('LIVE_CLAUDE_MODEL') ?: 'claude-haiku-4-5-20251001',
        'proxy' => $proxy, 'timeout' => 180, 'max_tokens' => 2000, 'search' => ['max_uses' => 2],
    ], $q, true, true);
}
if ($want('openrouter') && ($k = secret('openrouter')) !== '') {
    // 没有原厂 key 的平台经 OpenRouter 测：LIVE_OPENROUTER_MODELS=anthropic/claude-haiku-4.5,perplexity/sonar
    // 注意这测的是 OpenRouter 归一后的 annotations，不是原厂的原生结构
    $models = array_filter(array_map('trim', explode(',', getenv('LIVE_OPENROUTER_MODELS') ?: 'deepseek/deepseek-chat-v3.1')));
    foreach ($models as $m) {
        run_platform('openrouter_' . preg_replace('/[^a-z0-9.-]+/i', '_', $m), [
            'api_key' => $k, 'protocol' => 'openrouter', 'model' => $m,
            'proxy' => $proxy, 'timeout' => 240, 'max_tokens' => 2000, 'search' => ['count' => 3],
        ], $q, true, true);
    }
}
if ($want('openai') && ($k = secret('openai')) !== '') {
    // Chat Completions 的搜索模型总是先搜再答，不需要（也不能）传 search
    run_platform('openai', [
        'api_key' => $k, 'protocol' => 'openai', 'model' => getenv('LIVE_OPENAI_MODEL') ?: 'gpt-5-search-api',
        'proxy' => $proxy, 'timeout' => 180,
    ], $q, true, true);
}
if ($want('xai') && ($k = secret('xai')) !== '') {
    run_platform('xai', [
        'api_key' => $k, 'protocol' => 'grok', 'model' => getenv('LIVE_XAI_MODEL') ?: 'grok-4',
        'proxy' => $proxy, 'timeout' => 180, 'max_tokens' => 1500,
        'extra_body' => ['search_parameters' => ['mode' => 'on', 'return_citations' => true]],
    ], $q, true, false);
}
if ($want('perplexity') && ($k = secret('perplexity')) !== '') {
    run_platform('perplexity', [
        'api_key' => $k, 'protocol' => 'perplexity', 'model' => 'sonar',
        'proxy' => $proxy, 'timeout' => 180, 'max_tokens' => 1500,
    ], $q, true, true);
}
if ($want('zhipu') && ($k = secret('zhipu')) !== '') {
    run_platform('zhipu', [
        'api_key' => $k, 'protocol' => 'zhipu', 'model' => getenv('LIVE_ZHIPU_MODEL') ?: 'glm-4-flash',
        'timeout' => 180, 'max_tokens' => 1500, 'search' => ['sources' => true],
    ], $q, true, false);
}
if ($want('ernie') && ($k = secret('ernie')) !== '') {
    run_platform('ernie', [
        'api_key' => $k, 'protocol' => 'ernie', 'model' => getenv('LIVE_ERNIE_MODEL') ?: 'ernie-4.5-turbo-32k',
        'timeout' => 180, 'max_tokens' => 1500, 'search' => ['sources' => true, 'citation' => true],
    ], $q, true, false);
}
if ($want('qwen') && ($k = secret('qwen')) !== '') {
    // 兼容端点官方明确不返回来源：这里验证的是「不报错、不误报」
    run_platform('qwen', [
        'api_key' => $k, 'protocol' => 'qwen', 'model' => 'qwen-plus',
        'timeout' => 180, 'max_tokens' => 1500, 'search' => ['sources' => true, 'citation' => true],
    ], $q, false, false);
}

// ===== Gemini：本库对话走兼容端点（官方未提供搜索），原生 generateContent 直接验解析 =====
if ($want('gemini') && ($k = secret('gemini')) !== '') {
    $ran[] = 'gemini';
    section('gemini（原生 generateContent + google_search）');
    $model = getenv('LIVE_GEMINI_MODEL') ?: 'gemini-2.5-flash';
    $base  = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model;
    $body  = ['contents' => [['parts' => [['text' => $q]]]], 'tools' => [['google_search' => new \stdClass()]]];
    $hdrs  = ['Content-Type' => 'application/json', 'x-goog-api-key' => $k];
    $protocol = new \Ai\Protocol\Gemini();

    $verifySegments = function ($label, $content, array $citations) {
        $bad = [];
        $checked = 0;
        foreach ($citations as $c) {
            if ($c['type'] !== 'grounding_support' || $c['start'] === null || $c['cited_text'] === '') {
                continue;
            }
            $checked++;
            $piece = mb_substr($content, $c['start'], $c['end'] - $c['start'], 'UTF-8');
            if ($piece !== $c['cited_text']) {
                $bad[] = '「' . $piece . '」≠「' . $c['cited_text'] . '」';
            }
        }
        test("{$label} 引用区间截出的文字与 segment.text 一致（{$checked} 条）", $checked > 0 && !$bad,
            implode(' | ', array_slice($bad, 0, 2)));
    };

    try {
        $t = new CurlTransport();
        $t->setProxy($proxy)->setTimeout(180);
        $raw  = $t->post($base . ':generateContent', $body, $hdrs);
        for ($i = 0; empty($raw['candidates'][0]['groundingMetadata']['groundingChunks']) && $i < 2; $i++) {
            echo "  · 本次模型未触发搜索，重试\n";
            $raw = $t->post($base . ':generateContent', $body, $hdrs);
        }
        dump_raw('gemini', $raw);
        $resp = $protocol->parseResponse($raw);
        check_shape('非流式', $resp->getContent(), $resp->getSources(), $resp->getCitations());
        test('非流式 拿到来源', count($resp->getSources()) > 0);
        test('非流式 拿到引用', count($resp->getCitations()) > 0);
        $verifySegments('非流式', $resp->getContent(), $resp->getCitations());
    } catch (\Throwable $e) {
        test('非流式 请求成功', false, $e->getMessage());
    }

    try {
        $frames    = [];
        $content   = '';
        $collector = new StreamCitationCollector();
        $t = new CurlTransport();
        $t->setProxy($proxy)->setTimeout(180);
        $t->setStreamCallback(function ($data) use (&$frames, &$content, &$collector) {
            $frames[] = $data;
            $text = '';
            foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
                if (isset($part['text']) && empty($part['thought'])) {
                    $text .= $part['text'];
                }
            }
            $content .= $text;
            $collector->feed($data, $text);
        });
        $t->post($base . ':streamGenerateContent?alt=sse', $body, $hdrs);
        for ($i = 0; !$collector->result($content)['sources'] && $i < 2; $i++) {
            echo "  · 本次模型未触发搜索，重试\n";
            $frames = [];
            $content = '';
            $collector = new StreamCitationCollector();
            $t->post($base . ':streamGenerateContent?alt=sse', $body, $hdrs);
        }
        dump_raw('gemini_stream', $frames);
        $r = $collector->result($content);
        check_shape('流式', $content, $r['sources'], $r['citations']);
        test('流式 拿到来源', count($r['sources']) > 0);
        $verifySegments('流式', $content, $r['citations']);
    } catch (\Throwable $e) {
        test('流式 请求成功', false, $e->getMessage());
    }
}

section('汇总');
if (!$ran) {
    echo "跳过：.claude/secrets/ 下没有任何平台密钥（claude / openai / xai / openrouter / perplexity / zhipu / ernie / qwen / gemini）\n";
    exit(0);
}
echo "已测平台：" . implode(', ', $ran) . "\n通过 {$passed}，失败 {$failed}\n";
exit($failed > 0 ? 1 : 0);
