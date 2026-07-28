<?php
namespace Ai\Tools;

/**
 * 安全的 HTTP(S) 抓取器（含 SSRF / 本地文件逃逸防护）
 *
 * 仅服务「让 AI 读取公网网页/接口」这一用途，与模型 API 的 CurlTransport 分离。
 * 纵深防御链（按执行顺序）：
 *   1) 仅 http/https 协议；拒绝带 user:pass@ 的 URL
 *   2) 端口白名单（默认 80/443）
 *   3) 解析主机所有 A/AAAA → 每个 IP 必须为公网（拒私有/保留/回环/链路本地/云元数据）
 *      任一解析 IP 为内网即整体拒绝，避免「一公一私」绕过
 *   4) CURLOPT_RESOLVE 把连接钉死到已校验 IP（防 DNS rebinding 的 TOCTOU）
 *   5) CURLOPT_PROTOCOLS / REDIR_PROTOCOLS = HTTP|HTTPS（根本上禁 file:// 等）
 *   6) 不自动跟随重定向；手动逐跳，每跳回到第 1 步重新校验
 *   7) 写回调超过 max_bytes 立即中断；HEADER 回调取状态/类型
 *   8) 校验 TLS；不带 Cookie；自定义 UA；不走站点代理（保证 IP 钉死生效）
 *
 * 只负责「安全拿到原始 body + content-type」，内容格式化交给 WebContent。
 */
class HttpFetch
{
    protected $maxBytes;
    protected $connectTimeout;
    protected $timeout;
    protected $maxRedirects;
    protected $allowedPorts;
    protected $userAgent;

    public function __construct(array $opts = [])
    {
        $this->maxBytes       = (int)   ($opts['max_bytes']       ?? 1500 * 1024);
        $this->connectTimeout = (int)   ($opts['connect_timeout'] ?? 8);
        $this->timeout        = (int)   ($opts['timeout']         ?? 15);
        $this->maxRedirects   = (int)   ($opts['max_redirects']   ?? 4);
        $this->allowedPorts   = (array) ($opts['allowed_ports']   ?? [80, 443]);
        $this->userAgent      = (string)($opts['user_agent']      ?? 'Mozilla/5.0 (compatible; CMS-AI-Fetch/1.0)');
    }

    /**
     * 安全抓取一个 URL。
     * @return array { ok, status, content_type, final_url, bytes, body, error }
     */
    public function fetch($url)
    {
        if (!function_exists('curl_init')) {
            return $this->err('服务器未启用 cURL');
        }
        $url  = trim((string) $url);
        $hops = 0;
        $seen = [];

        while (true) {
            $check = $this->validateUrl($url);
            if (!$check['ok']) {
                return $this->err($check['error']);
            }

            $res = $this->curlOnce($url, $check['parts'], $check['ip']);
            if (!$res['ok']) {
                return $this->err($res['error'], isset($res['status']) ? $res['status'] : 0);
            }

            // 重定向：手动跟随，每跳重新校验（防绕过到内网）
            if ($res['status'] >= 300 && $res['status'] < 400 && $res['location'] !== '') {
                if ($hops >= $this->maxRedirects) {
                    return $this->err('重定向次数过多');
                }
                $next = $this->resolveLocation($url, $res['location']);
                if ($next === null) {
                    return $this->err('无效的重定向地址');
                }
                if (isset($seen[$next])) {
                    return $this->err('检测到重定向循环');
                }
                $seen[$next] = true;
                $url = $next;
                $hops++;
                continue;
            }

            return [
                'ok'           => true,
                'status'       => $res['status'],
                'content_type' => $res['content_type'],
                'final_url'    => $url,
                'bytes'        => strlen($res['body']),
                'body'         => $res['body'],
                'error'        => '',
            ];
        }
    }

    protected function err($msg, $status = 0)
    {
        return ['ok' => false, 'status' => $status, 'content_type' => '', 'final_url' => '', 'bytes' => 0, 'body' => '', 'error' => $msg];
    }

    /** 校验协议/端口/userinfo，并确保所有解析 IP 均为公网 */
    protected function validateUrl($url)
    {
        $p = @parse_url($url);
        if ($p === false || empty($p['scheme']) || empty($p['host'])) {
            return ['ok' => false, 'error' => 'URL 解析失败'];
        }
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return ['ok' => false, 'error' => '仅允许 http/https 协议'];
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return ['ok' => false, 'error' => '不允许带用户名/密码的 URL'];
        }
        $host = trim($p['host'], '[]'); // 去 IPv6 方括号
        $port = isset($p['port']) ? (int) $p['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, $this->allowedPorts, true)) {
            return ['ok' => false, 'error' => '仅允许访问 ' . implode('/', $this->allowedPorts) . ' 端口'];
        }

        $ips = $this->resolveHost($host);
        if (empty($ips)) {
            return ['ok' => false, 'error' => '域名无法解析'];
        }
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                return ['ok' => false, 'error' => '目标解析到非公网地址（已拦截）'];
            }
        }
        return ['ok' => true, 'parts' => ['scheme' => $scheme, 'host' => $host, 'port' => $port], 'ip' => $ips[0]];
    }

    /** 解析主机的所有 IPv4/IPv6（字面量 IP 原样返回） */
    protected function resolveHost($host)
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = [];
        $a = @gethostbynamel($host);
        if (is_array($a)) {
            $ips = array_merge($ips, $a);
        }
        $recs = @dns_get_record($host, DNS_AAAA);
        if (is_array($recs)) {
            foreach ($recs as $r) {
                if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
            }
        }
        return array_values(array_unique($ips));
    }

    /** 公网 = 既非私有也非保留（覆盖 127/10/172/192/169.254、::1、fc00::/7、fe80::/10 等） */
    protected function isPublicIp($ip)
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /** 单次请求：钉死已校验 IP、协议白名单、不自动跟随重定向、超限中断 */
    protected function curlOnce($url, $parts, $ip)
    {
        $ch = curl_init();
        $headerData = '';
        $body = '';
        $overflow = false;
        $maxBytes = $this->maxBytes;

        curl_setopt_array($ch, [
            CURLOPT_URL             => $url,
            CURLOPT_CONNECTTIMEOUT  => $this->connectTimeout,
            CURLOPT_TIMEOUT         => $this->timeout,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_USERAGENT       => $this->userAgent,
            CURLOPT_ACCEPT_ENCODING => '', // 允许 gzip/deflate 自动解压
        ]);

        // 协议白名单（根本上禁 file://、gopher:// 等）
        if (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        // IP 钉死，curl 不再重新解析（防 DNS rebinding）
        curl_setopt($ch, CURLOPT_RESOLVE, [$parts['host'] . ':' . $parts['port'] . ':' . $ip]);

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $line) use (&$headerData) {
            $headerData .= $line;
            return strlen($line);
        });
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($c, $data) use (&$body, &$overflow, $maxBytes) {
            $body .= $data;
            if (strlen($body) > $maxBytes) { $overflow = true; return -1; } // 返回非长度即中断 curl
            return strlen($data);
        });

        $ok     = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        if (function_exists('curl_close') && version_compare(PHP_VERSION, '8.0.0', '<')) {
            curl_close($ch);
        }

        // 主动截断触发的写错误（errno 23）视为成功；其它失败才报错
        if ($ok === false && !$overflow) {
            return ['ok' => false, 'status' => $status, 'error' => '抓取失败：' . ($error !== '' ? $error : ('errno ' . $errno))];
        }

        if (strlen($body) > $maxBytes) {
            $body = substr($body, 0, $maxBytes);
        }

        $location = '';
        if ($status >= 300 && $status < 400 && preg_match('/^location:\s*(.+)$/im', $headerData, $m)) {
            $location = trim($m[1]);
        }

        return ['ok' => true, 'status' => $status, 'content_type' => $ctype, 'body' => $body, 'location' => $location];
    }

    /** 将重定向 Location 解析为绝对 URL */
    protected function resolveLocation($base, $location)
    {
        $location = trim($location);
        if ($location === '') return null;
        if (preg_match('#^https?://#i', $location)) return $location;

        $bp = @parse_url($base);
        if (!$bp || empty($bp['scheme']) || empty($bp['host'])) return null;
        $origin = $bp['scheme'] . '://' . $bp['host'] . (isset($bp['port']) ? ':' . $bp['port'] : '');

        if (strpos($location, '//') === 0) return $bp['scheme'] . ':' . $location; // 协议相对
        if (strpos($location, '/') === 0)  return $origin . $location;              // 绝对路径
        $dir = isset($bp['path']) ? preg_replace('#/[^/]*$#', '/', $bp['path']) : '/';
        return $origin . $dir . $location;                                          // 相对路径
    }
}
