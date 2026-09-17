<?php
namespace Ai\Helpers;

/**
 * 流式响应里的来源与引用收集器
 *
 * 流式不走 parseResponse()，来源与引用散在各个分片里，不在逐帧回调时记下来，
 * 流结束后就再也拿不到了。本类只做「记」：把分片里与引用相关的数据攒起来，
 * 流结束时拼回非流式响应的结构，交给 Citations 的同一套解析——
 * 这样流式与非流式的结果由同一份代码产出，不会各说各话。
 *
 * 与 Citations::fromOpenAi() 同理，各家分片结构互不重名，不按协议分支：
 *
 *   Claude      content_block_start / citations_delta / content_block_stop
 *   OpenAI 系   choices[0].delta.annotations
 *   Perplexity  顶层 search_results / citations（每帧重复下发，取最后一份）
 *   通义 / 智谱  顶层 search_info / web_search
 *   Gemini      candidates[0].groundingMetadata（原生结构，chunks 逐帧增量）
 */
class StreamCitationCollector
{
    /**
     * 顶层扩展字段：出现即整份覆盖（平台每帧下发的是完整列表而非增量）
     */
    const TOP_LEVEL_KEYS = ['search_results', 'citations', 'search_info', 'web_search'];

    /** @var array<string, mixed> */
    protected $topLevel = [];

    /** @var array<int, array<mixed>> OpenAI 系逐帧下发的 annotations */
    protected $annotations = [];

    /**
     * Claude 的 content 块，按块 index 存
     *
     * text 块额外记 start / end（在已累积正文里的字符位置），流结束时据此从正文切回块文本
     *
     * @var array<int, array<string, mixed>>
     */
    protected $claudeBlocks = [];

    /** @var array<string, mixed>|null */
    protected $grounding = null;

    /** @var int 已累积正文的字符数 */
    protected $length = 0;

    /**
     * 喂入一帧
     *
     * @param array<string, mixed> $chunk 平台原始分片
     * @param string|null          $text  本帧被追加到正文里的文本（协议层 parseStreamChunk() 的结果）
     */
    public function feed(array $chunk, ?string $text): void
    {
        $type = isset($chunk['type']) && is_string($chunk['type']) ? $chunk['type'] : '';

        if ($type === 'content_block_start' && isset($chunk['content_block']) && is_array($chunk['content_block'])) {
            $index = (int) ($chunk['index'] ?? count($this->claudeBlocks));
            $block = $chunk['content_block'];
            if (($block['type'] ?? '') === 'text') {
                $block['citations'] = isset($block['citations']) && is_array($block['citations']) ? $block['citations'] : [];
                $block['_start'] = $this->length;
            }
            $this->claudeBlocks[$index] = $block;
        } elseif ($type === 'content_block_delta' && ($chunk['delta']['type'] ?? '') === 'citations_delta') {
            $index = (int) ($chunk['index'] ?? 0);
            if (isset($this->claudeBlocks[$index], $chunk['delta']['citation']) && is_array($chunk['delta']['citation'])) {
                $this->claudeBlocks[$index]['citations'][] = $chunk['delta']['citation'];
            }
        }

        if ($text !== null && $text !== '') {
            $this->length += mb_strlen($text, 'UTF-8');
        }

        if ($type === 'content_block_stop') {
            $index = (int) ($chunk['index'] ?? 0);
            if (isset($this->claudeBlocks[$index]['_start'])) {
                $this->claudeBlocks[$index]['_end'] = $this->length;
            }
        }

        foreach (self::TOP_LEVEL_KEYS as $key) {
            if (!empty($chunk[$key]) && is_array($chunk[$key])) {
                $this->topLevel[$key] = $chunk[$key];
            }
        }

        foreach (['delta', 'message'] as $part) {
            $anns = $chunk['choices'][0][$part]['annotations'] ?? null;
            if (is_array($anns)) {
                foreach ($anns as $ann) {
                    if (is_array($ann)) {
                        $this->annotations[] = $ann;
                    }
                }
            }
        }

        // Gemini 原生流式：官方说明 groundingChunks 每帧只下发新增的，
        // groundingChunkIndices 指向跨帧累积后的下标——两者都要按序追加
        $meta = $chunk['candidates'][0]['groundingMetadata'] ?? null;
        if (is_array($meta) && $meta) {
            if ($this->grounding === null) {
                $this->grounding = ['groundingChunks' => [], 'groundingSupports' => []];
            }
            foreach (['groundingChunks', 'groundingSupports'] as $key) {
                if (isset($meta[$key]) && is_array($meta[$key])) {
                    foreach ($meta[$key] as $item) {
                        $this->grounding[$key][] = $item;
                    }
                }
            }
        }
    }

    /**
     * 流结束时产出统一格式的来源与引用
     *
     * @param string $content 流式累积的完整正文
     * @return array{sources: array<int, array<string, mixed>>, citations: array<int, array<string, mixed>>}
     */
    public function result(string $content): array
    {
        if ($this->claudeBlocks) {
            ksort($this->claudeBlocks);
            $blocks = [];
            foreach ($this->claudeBlocks as $block) {
                if (isset($block['_start'])) {
                    $start = (int) $block['_start'];
                    $end   = isset($block['_end']) ? (int) $block['_end'] : $this->length;
                    $block['text'] = mb_substr($content, $start, $end - $start, 'UTF-8');
                    unset($block['_start'], $block['_end']);
                }
                $blocks[] = $block;
            }
            return Citations::fromClaude($blocks);
        }

        if ($this->grounding !== null) {
            return Citations::fromGemini(['candidates' => [['groundingMetadata' => $this->grounding]]], $content);
        }

        $response = $this->topLevel;
        $response['choices'] = [['message' => ['content' => $content, 'annotations' => $this->annotations]]];
        return Citations::fromOpenAi($response, $content);
    }
}
