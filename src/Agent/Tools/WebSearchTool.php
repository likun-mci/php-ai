<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;
use Ai\Tools\HttpFetch;

/**
 * web_search 工具——免费网页搜索（DuckDuckGo Lite，无需 API key）
 *
 * 经 Ai\Tools\HttpFetch 抓取 DuckDuckGo 的轻量结果页并解析标题/链接/摘要。
 * 无第三方依赖、无密钥；DDG 页面结构变动时优雅失败（返回空结果，不抛异常）。
 * 需要更稳的搜索可换成带 key 的搜索 API（自行扩展）。
 *
 * 属网络读取，默认经 PermissionManager 把关；**不默认装配**，需显式启用
 *（见 dev.md v2.1 §1.3）。
 */
class WebSearchTool implements AgentToolInterface
{
    /** @var HttpFetch|null 可注入（测试用） */
    protected $fetch;

    /** @var string 搜索结果页基址（DDG Lite） */
    protected $endpoint = 'https://lite.duckduckgo.com/lite/';

    /**
     * @param HttpFetch|null $fetch
     */
    public function __construct($fetch = null)
    {
        $this->fetch = $fetch instanceof HttpFetch ? $fetch : null;
    }

    public function name()
    {
        return 'web_search';
    }

    public function description()
    {
        return '用关键词做网页搜索，返回标题/链接/摘要列表（免费 DuckDuckGo，无需密钥）。'
            . '适合查资料、找文档、了解报错。拿到链接后可用 web_fetch 读正文。';
    }

    public function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => '搜索关键词',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => '最多返回几条，默认 5',
                    'default'     => 5,
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $input, ToolContext $context)
    {
        $query = isset($input['query']) ? trim((string) $input['query']) : '';
        if ($query === '') {
            return ToolResult::error('参数 query 不能为空');
        }
        $limit = isset($input['limit']) ? max(1, (int) $input['limit']) : 5;

        $fetch = $this->fetch !== null ? $this->fetch : new HttpFetch(['timeout' => 15]);
        $url = $this->endpoint . '?q=' . rawurlencode($query);
        $res = $fetch->fetch($url);
        if (empty($res['ok'])) {
            $err = isset($res['error']) && $res['error'] !== '' ? $res['error'] : '搜索请求失败';
            return ToolResult::error('web_search 失败：' . $err);
        }

        $results = $this->parse((string) (isset($res['body']) ? $res['body'] : ''), $limit);
        if (!$results) {
            return ToolResult::success('未找到「' . $query . '」的搜索结果', [
                'query'   => $query,
                'results' => 0,
            ]);
        }

        $lines = [];
        $n = 0;
        foreach ($results as $r) {
            $n++;
            $lines[] = $n . '. ' . $r['title'] . "\n   " . $r['url']
                . ($r['snippet'] !== '' ? "\n   " . $r['snippet'] : '');
        }

        return new ToolResult([
            'success'  => true,
            'content'  => "「{$query}」搜索结果（" . count($results) . "）：\n" . implode("\n", $lines),
            'metadata' => ['query' => $query, 'results' => count($results)],
            'display'  => 'web_search ' . $query . ': ' . count($results) . ' 条',
        ]);
    }

    /**
     * 解析 DuckDuckGo Lite 结果页
     *
     * @param string $html
     * @param int $limit
     * @return array<int, array{title: string, url: string, snippet: string}>
     */
    protected function parse($html, $limit)
    {
        $out = [];
        if ($html === '') {
            return $out;
        }
        // 结果链接：匹配所有 <a ...>...</a>，再筛出 class 含 result-link 的（属性顺序无关）
        if (!preg_match_all('#<a\b([^>]*)>(.*?)</a>#is', $html, $m, PREG_SET_ORDER)) {
            return $out;
        }
        // 摘要：<td ... class="result-snippet">摘要</td>（与结果一一对应，尽力匹配）
        $snips = [];
        if (preg_match_all('#<td[^>]*class="[^"]*result-snippet[^"]*"[^>]*>(.*?)</td>#is', $html, $sm)) {
            $snips = $sm[1];
        }

        $idx = -1;
        foreach ($m as $one) {
            if (count($out) >= $limit) {
                break;
            }
            $attrs = $one[1];
            if (strpos($attrs, 'result-link') === false) {
                continue;   // 只要结果链接
            }
            $idx++;
            if (!preg_match('#href="([^"]+)"#i', $attrs, $hm)) {
                continue;
            }
            $url = html_entity_decode($hm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $url = $this->unwrapDdg($url);
            $title = $this->clean($one[2]);
            $i = $idx;
            $snippet = isset($snips[$i]) ? $this->clean($snips[$i]) : '';
            if ($url === '' || $title === '') {
                continue;
            }
            $out[] = ['title' => $title, 'url' => $url, 'snippet' => $snippet];
        }
        return $out;
    }

    /**
     * DDG 有时把真实链接包在 /l/?uddg=<encoded> 里，解出来
     *
     * @param string $url
     * @return string
     */
    protected function unwrapDdg($url)
    {
        if (strpos($url, 'uddg=') !== false) {
            $q = parse_url($url, PHP_URL_QUERY);
            if (is_string($q)) {
                parse_str($q, $params);
                if (isset($params['uddg']) && is_string($params['uddg']) && $params['uddg'] !== '') {
                    return $params['uddg'];
                }
            }
        }
        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }
        return $url;
    }

    /**
     * 去标签 + 解实体 + 压空白
     *
     * @param string $s
     * @return string
     */
    protected function clean($s)
    {
        $s = strip_tags((string) $s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\s+/', ' ', $s);
        return is_string($s) ? trim($s) : '';
    }
}
