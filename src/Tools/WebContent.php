<?php
namespace Ai\Tools;

/**
 * 抓取内容格式化：把原始响应体按 format 渲染为 text / md / source
 *
 *  - source：原始源码 / 接口原文（仅截断）
 *  - text  ：HTML 抽离为可读纯文本；非 HTML 等同 source
 *  - md    ：HTML 转 Markdown（优先 league/html-to-markdown，缺失则内置回退）；
 *            json 在 md 下美化并加围栏；其它非 HTML 等同 source
 *
 * 由模型在 fetch_url 工具里按需选择 format。
 */
class WebContent
{
    /**
     * @param string $body        原始响应体
     * @param string $contentType 响应 Content-Type
     * @param string $format      text|md|source（非法值回退 md）
     * @param int    $maxChars    注入上限（按字符截断）
     */
    public static function render($body, $contentType, $format, $maxChars = 16000)
    {
        $format = in_array($format, ['text', 'md', 'source'], true) ? $format : 'md';
        $ct     = strtolower((string) $contentType);
        $isHtml = strpos($ct, 'text/html') !== false || strpos($ct, 'application/xhtml') !== false;
        $isJson = strpos($ct, 'json') !== false;

        if ($format === 'source') {
            $out = (string) $body;
        } elseif (!$isHtml) {
            if ($format === 'md' && $isJson) {
                $out = "```json\n" . self::prettyJson($body) . "\n```";
            } else {
                $out = (string) $body;
            }
        } elseif ($format === 'text') {
            $out = self::htmlToText($body);
        } else { // md + HTML
            $out = self::htmlToMarkdown($body);
            if ($out === '') $out = self::htmlToText($body);
        }

        $out = trim($out);
        if ($maxChars > 0 && mb_strlen($out) > $maxChars) {
            $out = mb_substr($out, 0, $maxChars) . "\n\n…(内容过长已截断)";
        }
        return $out;
    }

    protected static function prettyJson($body)
    {
        $d = json_decode((string) $body, true);
        if ($d === null) return (string) $body;
        return json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** HTML → 纯文本：剥离噪声后取文本、压缩空白 */
    public static function htmlToText($html)
    {
        $html = self::stripNoise($html);
        $text = '';
        if (class_exists('DOMDocument')) {
            $doc = new \DOMDocument();
            libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
            libxml_clear_errors();
            $bodyNode = $doc->getElementsByTagName('body')->item(0);
            $text = $bodyNode ? $bodyNode->textContent : $doc->textContent;
        } else {
            $text = strip_tags($html);
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $text);
        return trim($text);
    }

    /** HTML → Markdown：优先 league/html-to-markdown，缺失则回退基础转换 */
    public static function htmlToMarkdown($html)
    {
        $html = self::stripNoise($html);
        if (class_exists('\League\HTMLToMarkdown\HtmlConverter')) {
            try {
                $conv = new \League\HTMLToMarkdown\HtmlConverter([
                    'strip_tags'   => true,
                    'remove_nodes' => 'script style',
                    'hard_break'   => true,
                ]);
                return trim($conv->convert($html));
            } catch (\Throwable $e) {
                // 转换失败回退
            }
        }
        return self::basicMarkdown($html);
    }

    /** 无依赖的轻量 HTML→Markdown 回退（best-effort） */
    protected static function basicMarkdown($html)
    {
        if (preg_match('#<body[^>]*>(.*)</body>#is', $html, $m)) {
            $html = $m[1];
        }
        $rules = [
            '#<h1[^>]*>(.*?)</h1>#is'                                  => "\n# $1\n",
            '#<h2[^>]*>(.*?)</h2>#is'                                  => "\n## $1\n",
            '#<h3[^>]*>(.*?)</h3>#is'                                  => "\n### $1\n",
            '#<h4[^>]*>(.*?)</h4>#is'                                  => "\n#### $1\n",
            '#<li[^>]*>(.*?)</li>#is'                                  => "- $1\n",
            '#<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is'       => '[$2]($1)',
            '#<(strong|b)[^>]*>(.*?)</\1>#is'                          => '**$2**',
            '#<(em|i)[^>]*>(.*?)</\1>#is'                              => '*$2*',
            '#<code[^>]*>(.*?)</code>#is'                              => '`$1`',
            '#<br\s*/?>#i'                                             => "\n",
            '#</p>#i'                                                  => "\n\n",
        ];
        $html = preg_replace(array_keys($rules), array_values($rules), $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $text);
        return trim($text);
    }

    /** 去掉 script/style/noscript/svg/iframe/注释/head 等噪声 */
    protected static function stripNoise($html)
    {
        $html = preg_replace('#<!--.*?-->#s', '', $html);
        $html = preg_replace('#<(script|style|noscript|svg|iframe)[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<head[^>]*>.*?</head>#is', '', $html);
        return $html;
    }
}
