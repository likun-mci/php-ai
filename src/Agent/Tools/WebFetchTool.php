<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;
use Ai\Tools\HttpFetch;
use Ai\Helpers\Text;

/**
 * web_fetch 工具——抓取一个 URL 的正文
 *
 * 复用 Ai\Tools\HttpFetch 做**SSRF 安全**抓取（拒绝内网/回环/云元数据地址，
 * 逐跳重校验重定向）。HTML 自动抽成纯文本，超长按字节截断。
 *
 * 属网络读取，默认经 PermissionManager 把关（manual 询问；dont_ask/bypass/auto 放行）。
 * **不默认装配**进 ClaudeCodeTools::all()，需显式启用（见 dev.md v2.1 §1.3）。
 */
class WebFetchTool implements AgentToolInterface
{
    /** @var HttpFetch|null 注入的抓取器（测试用），为空则按请求参数现建 */
    protected $fetch;

    /** @var int 正文输出最大字节 */
    protected $maxOutputBytes;

    /**
     * @param HttpFetch|null $fetch 可注入（测试）；生产留空
     * @param int $maxOutputBytes
     */
    public function __construct($fetch = null, $maxOutputBytes = 100000)
    {
        $this->fetch = $fetch instanceof HttpFetch ? $fetch : null;
        $this->maxOutputBytes = max(1024, (int) $maxOutputBytes);
    }

    public function name()
    {
        return 'web_fetch';
    }

    public function description()
    {
        return '抓取一个网页/接口的正文（HTML 自动转纯文本）。用于查阅在线文档、错误信息、API 说明。'
            . '只允许公网地址（内网/localhost/云元数据地址会被拒绝）。';
    }

    public function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'url' => [
                    'type'        => 'string',
                    'description' => '要抓取的 http(s) URL',
                ],
                'timeout' => [
                    'type'        => 'integer',
                    'description' => '超时秒数，默认 15',
                    'default'     => 15,
                ],
                'max_bytes' => [
                    'type'        => 'integer',
                    'description' => '最多抓取多少字节，默认约 1.5MB',
                    'default'     => 1500 * 1024,
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function execute(array $input, ToolContext $context)
    {
        $url = isset($input['url']) ? trim((string) $input['url']) : '';
        if ($url === '') {
            return ToolResult::error('参数 url 不能为空');
        }

        $fetch = $this->fetch;
        if ($fetch === null) {
            $opts = [];
            if (isset($input['timeout'])) {
                $opts['timeout'] = max(1, (int) $input['timeout']);
            }
            if (isset($input['max_bytes'])) {
                $opts['max_bytes'] = max(1024, (int) $input['max_bytes']);
            }
            $fetch = new HttpFetch($opts);
        }

        $res = $fetch->fetch($url);
        if (empty($res['ok'])) {
            $err = isset($res['error']) && $res['error'] !== '' ? $res['error'] : '抓取失败';
            return ToolResult::error('web_fetch 失败：' . $err);
        }

        $body = isset($res['body']) ? (string) $res['body'] : '';
        $contentType = isset($res['content_type']) ? (string) $res['content_type'] : '';
        $finalUrl = isset($res['final_url']) ? (string) $res['final_url'] : $url;

        // HTML → 纯文本
        if (stripos($contentType, 'html') !== false || $this->looksLikeHtml($body)) {
            $body = $this->htmlToText($body);
        }
        $body = trim($body);

        $truncated = false;
        if (strlen($body) > $this->maxOutputBytes) {
            $body = Text::cutBytes($body, $this->maxOutputBytes);
            $truncated = true;
        }

        $content = $body;
        if ($truncated) {
            $content .= "\n\n[内容已按 {$this->maxOutputBytes} 字节截断]";
        }

        return new ToolResult([
            'success'  => true,
            'content'  => $content !== '' ? $content : '(空响应)',
            'metadata' => [
                'final_url'    => $finalUrl,
                'status'       => isset($res['status']) ? (int) $res['status'] : 0,
                'content_type' => $contentType,
                'bytes'        => isset($res['bytes']) ? (int) $res['bytes'] : strlen($body),
                'truncated'    => $truncated,
            ],
            'is_partial' => $truncated,
            'display'    => 'web_fetch ' . $finalUrl,
        ]);
    }

    /**
     * @param string $body
     * @return bool
     */
    protected function looksLikeHtml($body)
    {
        $head = substr($body, 0, 512);
        return stripos($head, '<html') !== false || stripos($head, '<!doctype html') !== false
            || stripos($head, '<body') !== false || stripos($head, '<div') !== false;
    }

    /**
     * 极简 HTML → 纯文本：去 script/style、转标签为空白、解码实体、压缩空白
     *
     * @param string $html
     * @return string
     */
    protected function htmlToText($html)
    {
        $html = (string) $html;
        // 去掉 script / style / head 里的噪声
        $html = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html);
        if (!is_string($html)) {
            $html = '';
        }
        // 块级标签转换行，便于阅读
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)\s*/?>#i', "\n", $html);
        if (!is_string($html)) {
            $html = '';
        }
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // 压缩多余空白
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = is_string($text) ? $text : '';
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return is_string($text) ? trim($text) : '';
    }
}
