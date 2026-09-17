<?php
namespace Ai\Helpers;

/**
 * 联网搜索的「来源」与「引用」归一化
 *
 * 平台界面上那一串带角标、可点开的引用，API 其实也发回来了，只是散落在各家
 * 自己的字段里，而且结构完全不同：
 *
 *   Claude      web_search_tool_result 块（来源）+ text 块上的 citations[]（引用）
 *   OpenAI 系   choices[0].message.annotations[]（url_citation，OpenRouter 同）
 *   Perplexity  顶层 search_results[]（来源）+ citations[]（同序的 URL 列表）+ 正文 [1] 角标
 *   xAI         顶层 citations[]（URL 列表）+ 正文 [[1]](url) 行内引用
 *   Gemini      candidates[0].groundingMetadata.groundingChunks / groundingSupports（原生结构）
 *   通义千问     output.search_info.search_results[]（仅 DashScope 原生协议）+ 正文 [1] / [ref_1] 角标
 *   智谱 GLM     顶层 web_search[]（来源，refer 为 ref_1）
 *   文心一言     顶层 search_results[]（来源）+ 正文 ^[1]^ / ^[1][2]^ 角标
 *
 * 本类把它们统一成两份列表。
 *
 * **来源（source）**——平台检索到的网页：
 *
 * ```php
 * [
 *     'index'        => 1,          // 来源编号，与正文角标 [1] 对应；平台没给就按顺序从 1 编
 *     'url'          => 'https://…',
 *     'title'        => '',
 *     'snippet'      => '',         // 摘要 / 正文片段，平台没给为空串
 *     'site_name'    => '',
 *     'published_at' => '',         // 平台原样给的发布时间字符串，不做解析
 *     'cited'        => true,       // 是否被至少一条引用指向
 *     'raw'          => [...],      // 平台原始条目
 * ]
 * ```
 *
 * **引用（citation）**——回答里某段文字对应的出处：
 *
 * ```php
 * [
 *     'url'          => 'https://…',
 *     'title'        => '',
 *     'cited_text'   => '',         // 来源里被引用的原文，平台没给为空串
 *     'start'        => 12,         // 在 getContent() 里的起止位置，按 UTF-8 字符计（可直接喂 mb_substr）
 *     'end'          => 48,         //   未知时为 null
 *     'source_index' => 0,          // 对应 sources 数组的下标（从 0 起），对不上时为 null
 *     'type'         => 'url_citation', // 平台原始类型；由正文角标解析出的为 'marker'
 *     'raw'          => [...],
 * ]
 * ```
 *
 * 两份列表的关系：
 *
 * - 平台单独给了检索结果（Claude / Perplexity / Gemini / 通义 / 智谱 / 文心），sources 就是它，
 *   其中 `cited` 为 false 的是「搜到了但回答没用上」的。
 * - 平台只给引用（OpenAI / OpenRouter 的 annotations），sources 由被引用的 URL 去重得到。
 *   annotations 里区间为空、正文里也找不到对应链接的条目，只算来源（`cited => false`），不算引用。
 * - 只有角标、没有结构化引用的平台，按正文里的角标反查来源，`start` / `end` 指向角标本身：
 *   `[[1]](url)`（xAI）直接取链接里的 URL；`[1]` `[ref_1]` `^[1]^` `【1】` 按编号找来源，
 *   编号在 sources 里找不到的一律不认，避免把正文里的普通方括号误当引用。
 */
class Citations
{
    /**
     * 正文引用角标：[1] / [ref_1] / ^[1]^ / 【1】 / 【1†source】
     *
     * 前面紧挨字母数字或右括号的不认（`$arr[1]`、`f(x)[2]` 这类代码）。
     */
    const MARKER_PATTERN = '/\^?(?<![A-Za-z0-9_)])[\[【](?:ref_)?(\d{1,3})(?:†[^\]】]*)?[\]】]\^?/u';

    /**
     * 带链接的行内引用：[[1]](https://…)
     *
     * xAI 的编号是「第几个被引用的来源」而不是 citations 列表下标，只有链接可信，所以单独识别。
     */
    const LINK_MARKER_PATTERN = '/\[\[(\d{1,3})\]\]\((https?:\/\/[^\s)]+)\)/u';

    /**
     * 解析 OpenAI 兼容结构的响应（含 Perplexity / 通义 / 智谱 / 文心 / OpenRouter / xAI 的扩展字段）
     *
     * 这些扩展字段在各家之间互不重名，所以不按平台分支，出现哪个认哪个——
     * 走聚合网关（如 OpenRouter 转发到 Perplexity）时也能拿到。
     *
     * @param array<string, mixed> $response 平台原始响应
     * @param string               $content  已解析出的正文
     * @return array{sources: array<int, array<string, mixed>>, citations: array<int, array<string, mixed>>}
     */
    public static function fromOpenAi(array $response, string $content): array
    {
        $sources = [];

        // Perplexity / 文心：顶层 search_results
        foreach (self::listOf($response['search_results'] ?? null) as $item) {
            $sources[] = self::source([
                'index'        => $item['index'] ?? null,
                'url'          => $item['url'] ?? '',
                'title'        => $item['title'] ?? '',
                'snippet'      => $item['snippet'] ?? ($item['content'] ?? ''),
                'site_name'    => $item['site_name'] ?? '',
                'published_at' => $item['date'] ?? ($item['last_updated'] ?? ''),
            ], $item);
        }

        // 通义千问：DashScope 原生协议的 output.search_info.search_results。
        // 兼容端点（本库 qwen 协议走的那个）官方明确不返回来源，这里认的是用户自己指向原生端点的情况
        $qwen = $response['output']['search_info']['search_results']
            ?? ($response['search_info']['search_results'] ?? null);
        foreach (self::listOf($qwen) as $item) {
            $sources[] = self::source([
                'index'     => $item['index'] ?? null,
                'url'       => $item['url'] ?? '',
                'title'     => $item['title'] ?? '',
                'site_name' => $item['site_name'] ?? '',
            ], $item);
        }

        // 智谱：顶层 web_search，编号在 refer（"ref_1"）
        foreach (self::listOf($response['web_search'] ?? null) as $item) {
            $index = null;
            if (isset($item['refer']) && preg_match('/(\d+)/', (string) $item['refer'], $m)) {
                $index = (int) $m[1];
            }
            $sources[] = self::source([
                'index'        => $index,
                'url'          => $item['link'] ?? ($item['url'] ?? ''),
                'title'        => $item['title'] ?? '',
                'snippet'      => $item['content'] ?? '',
                'site_name'    => $item['media'] ?? '',
                'published_at' => $item['publish_date'] ?? '',
            ], $item);
        }

        // Perplexity / xAI：顶层 citations 是纯 URL 列表，顺序即角标编号。
        // 已有 search_results 时两者指向同一批网页，只补漏掉的 URL
        if (isset($response['citations']) && is_array($response['citations'])) {
            $known = [];
            foreach ($sources as $s) {
                $known[$s['url']] = true;
            }
            foreach (array_values($response['citations']) as $i => $url) {
                if (is_string($url) && $url !== '' && !isset($known[$url])) {
                    $sources[] = self::source(['index' => $sources ? null : $i + 1, 'url' => $url], ['url' => $url]);
                    $known[$url] = true;
                }
            }
        }

        // OpenAI / OpenRouter：message.annotations
        $citations = [];
        $message   = $response['choices'][0]['message'] ?? [];
        foreach (self::listOf($message['annotations'] ?? null) as $ann) {
            $type = (string) ($ann['type'] ?? '');
            if ($type !== '' && $type !== 'url_citation') {
                continue;
            }
            // 官方结构把字段包在 url_citation 里，个别兼容实现是平铺的
            $c = isset($ann['url_citation']) && is_array($ann['url_citation']) ? $ann['url_citation'] : $ann;
            $citations[] = self::citation([
                'url'        => $c['url'] ?? '',
                'title'      => $c['title'] ?? '',
                'cited_text' => $c['content'] ?? '',
                'start'      => $c['start_index'] ?? null,
                'end'        => $c['end_index'] ?? null,
                'type'       => 'url_citation',
            ], $ann);
        }

        $sources = self::numberSources(self::dedupeSources($sources));
        if (!$citations) {
            $citations = self::fromMarkers($content, $sources);
        } else {
            $located   = self::locateLinks($content, $citations);
            $citations = $located['citations'];
            // 定位不到的只能算「检索到了」，不能算「引用了」
            foreach ($located['unplaced'] as $c) {
                $sources[] = self::source(['url' => $c['url'], 'title' => $c['title'], 'snippet' => $c['cited_text']], $c['raw']);
            }
            $sources = self::numberSources(self::dedupeSources($sources));
        }
        return self::finalize($sources, $citations);
    }

    /**
     * 解析 Anthropic Messages 结构的 content 块数组
     *
     * 引用挂在 text 块上，`start` / `end` 是该 text 块在拼接后正文里的区间——
     * 与 Claude 协议 parseResponse() 的正文拼法（只拼 text 块、按序）保持一致。
     *
     * @param array<int, mixed> $blocks 响应的 content 数组
     * @return array{sources: array<int, array<string, mixed>>, citations: array<int, array<string, mixed>>}
     */
    public static function fromClaude(array $blocks): array
    {
        $sources   = [];
        $citations = [];
        $offset    = 0;

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? '';

            if ($type === 'web_search_tool_result') {
                // 搜索失败时 content 是一个 error 对象而不是列表，listOf() 会跳过它
                foreach (self::listOf($block['content'] ?? null) as $item) {
                    if (($item['type'] ?? '') !== 'web_search_result') {
                        continue;
                    }
                    $sources[] = self::source([
                        'url'          => $item['url'] ?? '',
                        'title'        => $item['title'] ?? '',
                        'published_at' => $item['page_age'] ?? '',
                    ], $item);
                }
                continue;
            }

            if (($type === 'text' || $type === '') && isset($block['text'])) {
                $text  = (string) $block['text'];
                $start = $offset;
                $offset += mb_strlen($text, 'UTF-8');

                foreach (self::listOf($block['citations'] ?? null) as $c) {
                    $citations[] = self::citation([
                        // search_result_location（自带检索结果）把地址放在 source 里
                        'url'        => $c['url'] ?? ($c['source'] ?? ''),
                        'title'      => $c['title'] ?? ($c['document_title'] ?? ''),
                        'cited_text' => $c['cited_text'] ?? '',
                        'start'      => $start,
                        'end'        => $offset,
                        'type'       => $c['type'] ?? '',
                    ], $c);
                }
            }
        }

        return self::finalize(self::numberSources(self::dedupeSources($sources)), $citations);
    }

    /**
     * 解析 Gemini 原生结构（generateContent）的 groundingMetadata
     *
     * segment 的 startIndex / endIndex 按官方 API 参考是**所属 part 内的 UTF-8 字节偏移**
     * （endIndex 不含）。这里按 partIndex 找到那个 part，换算成在拼接正文里的字符偏移，
     * 与其它平台的 `start` / `end` 口径一致。一条 support 指向多个 chunk 时拆成多条引用。
     *
     * 注意 groundingChunks 的 web.uri 是 vertexaisearch 的跳转地址，不是网页真实 URL，
     * title 是域名——这是平台给的原样，不做改写。
     *
     * @param array<string, mixed> $response 平台原始响应
     * @param string               $content  已解析出的正文（全部 text part 按序拼接）
     * @return array{sources: array<int, array<string, mixed>>, citations: array<int, array<string, mixed>>}
     */
    public static function fromGemini(array $response, string $content): array
    {
        $meta = $response['candidates'][0]['groundingMetadata'] ?? null;
        if (!is_array($meta)) {
            return ['sources' => [], 'citations' => []];
        }

        // chunk 下标 => 未去重前的来源，供 support 反查
        $chunks = [];
        foreach (self::listOf($meta['groundingChunks'] ?? null) as $i => $chunk) {
            $ref = $chunk['web'] ?? ($chunk['retrievedContext'] ?? null);
            if (!is_array($ref)) {
                continue;
            }
            $chunks[$i] = self::source([
                'url'     => $ref['uri'] ?? '',
                'title'   => $ref['title'] ?? '',
                'snippet' => $ref['text'] ?? '',
            ], $chunk);
        }

        // 各 text part 在拼接正文里的起始字节；没有 parts（如流式拼回的结构）时整段正文视为第 0 个 part
        $partBytes = [];
        $parts     = $response['candidates'][0]['content']['parts'] ?? null;
        if (is_array($parts) && $parts) {
            $byte = 0;
            foreach ($parts as $pi => $part) {
                if (is_array($part) && isset($part['text']) && empty($part['thought'])) {
                    $partBytes[$pi] = $byte;
                    $byte += strlen((string) $part['text']);
                }
            }
        } else {
            $partBytes[0] = 0;
        }

        $citations = [];
        foreach (self::listOf($meta['groundingSupports'] ?? null) as $support) {
            $seg  = isset($support['segment']) && is_array($support['segment']) ? $support['segment'] : [];
            $pi   = (int) ($seg['partIndex'] ?? 0);
            $base = $partBytes[$pi] ?? null;
            $start = $end = null;
            if ($base !== null) {
                $start = self::byteToChar($content, $base + (int) ($seg['startIndex'] ?? 0));
                $end   = isset($seg['endIndex']) ? self::byteToChar($content, $base + (int) $seg['endIndex']) : null;
            }
            foreach ((array) ($support['groundingChunkIndices'] ?? []) as $ci) {
                if (!isset($chunks[$ci])) {
                    continue;
                }
                $citations[] = self::citation([
                    'url'        => $chunks[$ci]['url'],
                    'title'      => $chunks[$ci]['title'],
                    'cited_text' => $seg['text'] ?? '',
                    'start'      => $start,
                    'end'        => $end,
                    'type'       => 'grounding_support',
                ], $support);
            }
        }

        return self::finalize(self::numberSources(self::dedupeSources(array_values($chunks))), $citations);
    }

    /**
     * 构造一条统一格式的来源
     *
     * @param array<string, mixed> $fields
     * @param array<mixed>         $raw
     * @return array<string, mixed>
     */
    public static function source(array $fields, array $raw = []): array
    {
        return [
            'index'        => isset($fields['index']) && is_numeric($fields['index']) ? (int) $fields['index'] : null,
            'url'          => self::str($fields['url'] ?? ''),
            'title'        => self::str($fields['title'] ?? ''),
            'snippet'      => self::str($fields['snippet'] ?? ''),
            'site_name'    => self::str($fields['site_name'] ?? ''),
            'published_at' => self::str($fields['published_at'] ?? ''),
            'cited'        => false,
            'raw'          => $raw,
        ];
    }

    /**
     * 构造一条统一格式的引用
     *
     * @param array<string, mixed> $fields
     * @param array<mixed>         $raw
     * @return array<string, mixed>
     */
    public static function citation(array $fields, array $raw = []): array
    {
        return [
            'url'          => self::str($fields['url'] ?? ''),
            'title'        => self::str($fields['title'] ?? ''),
            'cited_text'   => self::str($fields['cited_text'] ?? ''),
            'start'        => isset($fields['start']) && is_numeric($fields['start']) ? (int) $fields['start'] : null,
            'end'          => isset($fields['end']) && is_numeric($fields['end']) ? (int) $fields['end'] : null,
            'source_index' => null,
            'type'         => self::str($fields['type'] ?? ''),
            'raw'          => $raw,
        ];
    }

    /**
     * 给没有有效区间的引用，在正文里找指向同一 URL 的 markdown 链接作为区间
     *
     * 实测（2026-09，OpenRouter web 插件）：多数模型的 annotations 区间全是 0-0，出处实际以
     * `[域名](url)` 的形式写在正文里；转发 perplexity/sonar 时更是把全部检索结果（20 条）都当 annotations
     * 下发，而正文只引用了其中两三条。所以：
     *
     * - 区间有效的原样保留（OpenAI / 经 OpenRouter 的 xAI 实测为字符偏移、end 不含）
     * - 空区间的在正文里找指向同一 URL 的 markdown 链接，同一 URL 出现多次时依次取用
     * - 仍定位不到的放进 unplaced，由调用方降级为「未被引用的来源」——
     *   不留一个误导人的 0-0，也不把「搜到了」冒充成「引用了」
     *
     * @param array<int, array<string, mixed>> $citations
     * @return array{citations: array<int, array<string, mixed>>, unplaced: array<int, array<string, mixed>>}
     */
    protected static function locateLinks(string $content, array $citations): array
    {
        $used     = [];
        $placed   = [];
        $unplaced = [];
        foreach ($citations as $c) {
            if ($c['start'] !== null && $c['end'] !== null && $c['end'] > $c['start']) {
                $placed[] = $c;
                continue;
            }
            $c['start'] = null;
            $c['end']   = null;
            $pattern = '/\[[^\[\]\n]*\]\(' . preg_quote($c['url'], '/') . '\)/u';
            if ($c['url'] !== '' && $content !== '' && preg_match_all($pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $hit) {
                    if (isset($used[$hit[1]])) {
                        continue;
                    }
                    $used[$hit[1]] = true;
                    $c['start'] = self::byteToChar($content, $hit[1]);
                    $c['end']   = $c['start'] + mb_strlen($hit[0], 'UTF-8');
                    break;
                }
            }
            if ($c['start'] !== null) {
                $placed[] = $c;
            } else {
                $unplaced[] = $c;
            }
        }
        return ['citations' => $placed, 'unplaced' => $unplaced];
    }

    /**
     * 按正文角标反查来源
     *
     * @param array<int, array<string, mixed>> $sources 已编号的来源
     * @return array<int, array<string, mixed>>
     */
    protected static function fromMarkers(string $content, array $sources): array
    {
        if ($content === '') {
            return [];
        }

        // [[1]](url)：链接本身就是出处，不依赖 sources；占用的字节区间记下，避免下面再按编号认一遍
        $citations = [];
        $taken     = [];
        if (preg_match_all(self::LINK_MARKER_PATTERN, $content, $links, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($links as $m) {
                $start = self::byteToChar($content, $m[0][1]);
                $citations[] = self::citation([
                    'url'   => $m[2][0],
                    'start' => $start,
                    'end'   => $start + mb_strlen($m[0][0], 'UTF-8'),
                    'type'  => 'marker',
                ], ['marker' => $m[0][0], 'index' => (int) $m[1][0]]);
                $taken[] = [$m[0][1], $m[0][1] + strlen($m[0][0])];
            }
        }
        if (!$sources) {
            return $citations;
        }
        $byIndex = [];
        foreach ($sources as $s) {
            if ($s['index'] !== null && !isset($byIndex[$s['index']])) {
                $byIndex[$s['index']] = $s;
            }
        }
        if (!preg_match_all(self::MARKER_PATTERN, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $citations;
        }

        foreach ($matches as $m) {
            $n = (int) $m[1][0];
            if (!isset($byIndex[$n]) || self::overlaps($m[0][1], $taken)) {
                continue;
            }
            // PREG_OFFSET_CAPTURE 给的是字节偏移
            $start = self::byteToChar($content, $m[0][1]);
            $citations[] = self::citation([
                'url'   => $byIndex[$n]['url'],
                'title' => $byIndex[$n]['title'],
                'start' => $start,
                'end'   => $start + mb_strlen($m[0][0], 'UTF-8'),
                'type'  => 'marker',
            ], ['marker' => $m[0][0], 'index' => $n]);
        }
        usort($citations, function ($a, $b) {
            return $a['start'] - $b['start'];
        });
        return $citations;
    }

    /**
     * 字节位置是否落在已被占用的区间内
     *
     * @param array<int, array{0: int, 1: int}> $ranges
     */
    protected static function overlaps(int $byte, array $ranges): bool
    {
        foreach ($ranges as $r) {
            if ($byte >= $r[0] && $byte < $r[1]) {
                return true;
            }
        }
        return false;
    }

    /**
     * 按 URL 去重来源（没有 URL 的条目原样保留），保留首次出现的那条
     *
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, array<string, mixed>>
     */
    protected static function dedupeSources(array $sources): array
    {
        $out  = [];
        $seen = [];
        foreach ($sources as $s) {
            if ($s['url'] !== '') {
                if (isset($seen[$s['url']])) {
                    continue;
                }
                $seen[$s['url']] = true;
            }
            $out[] = $s;
        }
        return $out;
    }

    /**
     * 给没有平台编号的来源补编号：从已用最大编号之后顺延
     *
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, array<string, mixed>>
     */
    protected static function numberSources(array $sources): array
    {
        $used = [];
        foreach ($sources as $s) {
            if ($s['index'] !== null) {
                $used[$s['index']] = true;
            }
        }
        $next = 1;
        foreach ($sources as $k => $s) {
            if ($s['index'] !== null) {
                continue;
            }
            while (isset($used[$next])) {
                $next++;
            }
            $sources[$k]['index'] = $next;
            $used[$next] = true;
        }
        return $sources;
    }

    /**
     * 关联引用与来源：回填 source_index 与 cited，引用了列表外的 URL 时补进来源
     *
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, array<string, mixed>> $citations
     * @return array{sources: array<int, array<string, mixed>>, citations: array<int, array<string, mixed>>}
     */
    protected static function finalize(array $sources, array $citations): array
    {
        $sources = array_values($sources);
        $byUrl   = [];
        foreach ($sources as $i => $s) {
            if ($s['url'] !== '' && !isset($byUrl[$s['url']])) {
                $byUrl[$s['url']] = $i;
            }
        }

        foreach ($citations as $k => $c) {
            if ($c['url'] === '') {
                continue;                       // 文档引用（char_location 等）没有网页来源
            }
            if (!isset($byUrl[$c['url']])) {
                $derived = self::source(['url' => $c['url'], 'title' => $c['title']], ['url' => $c['url']]);
                $sources[] = $derived;
                $byUrl[$c['url']] = count($sources) - 1;
                $sources = self::numberSources($sources);
            }
            $i = $byUrl[$c['url']];
            $citations[$k]['source_index'] = $i;
            $sources[$i]['cited'] = true;
            if ($c['title'] === '' && $sources[$i]['title'] !== '') {
                $citations[$k]['title'] = $sources[$i]['title'];
            }
        }

        return ['sources' => $sources, 'citations' => array_values($citations)];
    }

    /**
     * 取值为列表时返回其中的数组条目，否则返回空数组
     *
     * @param mixed $value
     * @return array<int, array<mixed>>
     */
    protected static function listOf($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }
        // 关联数组（如 Claude 搜索失败时的 error 对象）不是列表
        return array_keys($value) === range(0, count($value) - 1) ? $out : [];
    }

    /**
     * UTF-8 字节偏移换算为字符偏移
     */
    protected static function byteToChar(string $content, int $byte): int
    {
        if ($byte <= 0) {
            return 0;
        }
        return mb_strlen(substr($content, 0, $byte), 'UTF-8');
    }

    /**
     * @param mixed $value
     */
    protected static function str($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
