<?php
namespace Ai\Helpers;

use Ai\AI;

/**
 * 文本翻译——Edge 免费接口为主、库自带 LLM 兜底
 *
 * Edge 端点（无需鉴权，2026-09 实测可用，见 dev.md v2.1 §1.5）：
 *   POST https://edge.microsoft.com/translate/translatetext?from={from}&to={to}&isEnterpriseClient=false
 *   请求体：纯 JSON 字符串数组；必带浏览器 User-Agent（缺 UA 返回 400）
 *   响应：[{"translations":[{"text":"...","to":"en"}]}]，逐条按序对应
 *   限制：单次 ≤1000 条 / ≤50000 字符；本实现按 500 条 / 45000 字符分片
 *
 * Edge 不可用（被墙/改版）时用 `llm()` 兜底——本库本就是 AI SDK，翻译是顺手的事。
 * 平台名/模型名/端点 URL 等不该翻的内容由调用方（如 README 同步脚本）自行保护。
 */
class Translate
{
    /** @var string Edge 翻译端点 */
    const EDGE_ENDPOINT = 'https://edge.microsoft.com/translate/translatetext';

    /** @var string 必带的浏览器 UA */
    const EDGE_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0';

    /**
     * 门面：翻译文本。字符串入参返回字符串，数组入参返回数组。
     *
     * @param string|string[] $texts
     * @param string $to 目标语言（如 en / zh-Hans）
     * @param array<string, mixed> $opts from / engine(auto|edge|llm) / ai(AI 实例，llm 兜底用)
     * @return string|string[] 失败时原样返回输入
     */
    public static function to($texts, $to, array $opts = [])
    {
        $scalar = !is_array($texts);
        $list = array_values(array_map('strval', (array) $texts));
        if (!$list) {
            return $scalar ? '' : [];
        }
        $from   = isset($opts['from']) ? (string) $opts['from'] : '';
        $engine = isset($opts['engine']) ? (string) $opts['engine'] : 'auto';
        $ai     = isset($opts['ai']) && $opts['ai'] instanceof AI ? $opts['ai'] : null;

        $result = [];
        if ($engine === 'llm') {
            $result = $ai !== null ? self::llm($ai, $list, $to, $from) : [];
        } else {
            $result = self::edge($list, $to, $from, $opts);
            if ((count($result) !== count($list)) && $engine !== 'edge' && $ai !== null) {
                $result = self::llm($ai, $list, $to, $from);   // Edge 失败 → LLM 兜底
            }
        }

        if (count($result) !== count($list)) {
            return $texts;   // 彻底失败：原样返回，绝不吞成空
        }
        return $scalar ? $result[0] : $result;
    }

    /**
     * 走 Edge 免费接口翻译，返回与输入等长的译文数组；失败返回 []
     *
     * @param string[] $texts
     * @param string $to
     * @param string $from
     * @param array<string, mixed> $opts endpoint / ua / timeout
     * @return string[]
     */
    public static function edge(array $texts, $to, $from = '', array $opts = [])
    {
        $texts = array_values(array_map('strval', $texts));
        if (!$texts) {
            return [];
        }
        if (!function_exists('curl_init')) {
            return [];
        }
        $endpoint = isset($opts['endpoint']) ? (string) $opts['endpoint'] : self::EDGE_ENDPOINT;
        $ua       = isset($opts['ua']) ? (string) $opts['ua'] : self::EDGE_UA;
        $timeout  = isset($opts['timeout']) ? max(1, (int) $opts['timeout']) : 20;

        $out = [];
        foreach (self::chunk($texts) as $chunk) {
            $url = $endpoint . '?from=' . rawurlencode((string) $from)
                 . '&to=' . rawurlencode((string) $to) . '&isEnterpriseClient=false';
            $body = json_encode(array_values($chunk), JSON_UNESCAPED_UNICODE);
            if ($body === false) {
                return [];
            }
            $raw = self::httpPost($url, $body, $ua, $timeout);
            if ($raw === null) {
                return [];
            }
            $parsed = self::parseEdgeResponse($raw, count($chunk));
            if ($parsed === null) {
                return [];
            }
            foreach ($parsed as $t) {
                $out[] = $t;
            }
        }
        return count($out) === count($texts) ? $out : [];
    }

    /**
     * 用库自带 LLM 翻译（兜底）；失败返回 []
     *
     * @param AI $ai
     * @param string[] $texts
     * @param string $to
     * @param string $from
     * @return string[]
     */
    public static function llm(AI $ai, array $texts, $to, $from = '')
    {
        $texts = array_values(array_map('strval', $texts));
        if (!$texts) {
            return [];
        }
        $payload = json_encode($texts, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return [];
        }
        $fromDesc = $from !== '' ? "from {$from} " : '';
        $sys = "You are a translation engine. Translate each string in the given JSON array {$fromDesc}to \"{$to}\". "
            . 'Return ONLY a JSON array of the translations, same length and order as the input. '
            . 'Do NOT translate code identifiers, URLs, or placeholders; keep them verbatim. No explanations, no markdown.';
        try {
            $reply = $ai->chat([
                'system'   => $sys,
                'messages' => [['role' => 'user', 'content' => $payload]],
            ])->getContent();
        } catch (\Throwable $e) {
            \Ai\Helpers\Log::warning('LLM 翻译失败', ['error' => $e->getMessage()]);
            return [];
        }
        $arr = self::extractJsonArray($reply);
        if ($arr === null || count($arr) !== count($texts)) {
            return [];
        }
        return array_map('strval', $arr);
    }

    /**
     * 把文本数组按条数/字符数分片（Edge 接口有上限）
     *
     * @param string[] $texts
     * @param int $maxItems
     * @param int $maxChars
     * @return array<int, string[]>
     */
    public static function chunk(array $texts, $maxItems = 500, $maxChars = 45000)
    {
        $chunks = [];
        $cur = [];
        $curChars = 0;
        foreach ($texts as $t) {
            $t = (string) $t;
            $len = function_exists('mb_strlen') ? mb_strlen($t) : strlen($t);
            if ($cur && (count($cur) >= $maxItems || ($curChars + $len) > $maxChars)) {
                $chunks[] = $cur;
                $cur = [];
                $curChars = 0;
            }
            $cur[] = $t;
            $curChars += $len;
        }
        if ($cur) {
            $chunks[] = $cur;
        }
        return $chunks;
    }

    /**
     * 解析 Edge 响应，返回按序译文数组；结构不符/条数不符返回 null
     *
     * @param string $raw
     * @param int $expectCount
     * @return string[]|null
     */
    public static function parseEdgeResponse($raw, $expectCount)
    {
        $data = json_decode($raw, true);
        if (!is_array($data) || count($data) !== $expectCount) {
            return null;
        }
        $out = [];
        foreach ($data as $item) {
            if (!isset($item['translations'][0]['text'])) {
                return null;
            }
            $out[] = (string) $item['translations'][0]['text'];
        }
        return $out;
    }

    /**
     * 从模型回复里抽出第一个 JSON 数组（容忍 ```json 围栏与前后废话）
     *
     * @param string $reply
     * @return array<int, mixed>|null
     */
    protected static function extractJsonArray($reply)
    {
        $reply = trim((string) $reply);
        $decoded = json_decode($reply, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($reply, '[');
        $end = strrpos($reply, ']');
        if ($start !== false && $end !== false && $end > $start) {
            $slice = substr($reply, $start, $end - $start + 1);
            $decoded = json_decode($slice, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    /**
     * 最小 POST（原生 curl，ext-curl 已是本库依赖）；失败返回 null
     *
     * @param string $url
     * @param string $body
     * @param string $ua
     * @param int $timeout
     * @return string|null
     */
    protected static function httpPost($url, $body, $ua, $timeout)
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min($timeout, 10));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'User-Agent: ' . $ua,
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($res) || $code < 200 || $code >= 300) {
            return null;
        }
        return $res;
    }
}
