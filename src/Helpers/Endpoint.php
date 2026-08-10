<?php
namespace Ai\Helpers;

/**
 * 端点解析：把「模型默认端点」按用户配置解析为「实际请求端点」。
 *
 * 支持用户接入第三方 API 转发/中转服务（如 one-api、new-api、各类 OpenAI 兼容网关）。
 * 解析优先级（从高到低）：
 *
 *   1) config['endpoint']  —— 完整端点 URL，原样使用（最高优先级、最灵活）。
 *                             适用于转发服务路径结构与官方完全不同的场景。
 *                             例：'https://proxy.example.com/openai/deepseek/chat'
 *
 *   2) config['base_url']  —— 接口根地址，与协议/模型的官方路径智能拼接。
 *                             既支持只给域名，也支持带路径前缀的网关：
 *                               'https://proxy.example.com'         => /v1/chat/completions
 *                               'https://proxy.example.com/v1'      => /v1/chat/completions（重叠段自动去重）
 *                               'https://proxy.example.com/openai'  => /openai/v1/chat/completions
 *                               'https://proxy.example.com/v1/chat/completions' => 原样（已是完整端点）
 *
 *   3) 都未设置          —— 使用模型/协议的默认端点。
 */
class Endpoint
{
    /**
     * 解析实际请求端点
     *
     * @param string $default 模型默认端点（完整 URL）
     * @param array  $config  运行时配置，读取 endpoint / base_url
     * @return string 实际请求端点
     */
    public static function resolve(string $default, array $config): string
    {
        // 1) 完整端点覆盖
        $full = trim((string)($config['endpoint'] ?? ''));
        if ($full !== '') {
            return self::withScheme($full);
        }

        // 2) 接口根地址 + 官方路径智能拼接
        $base = trim((string)($config['base_url'] ?? ''));
        if ($base !== '') {
            $up    = parse_url($default);
            $path  = $up['path'] ?? '';
            $query = isset($up['query']) ? ('?' . $up['query']) : '';
            $url   = self::join($base, $path);
            if ($url !== '') {
                return $url . $query;
            }
        }

        // 3) 默认
        return $default;
    }

    /**
     * 解析「模型列表」端点
     *
     * 优先级：endpoint_models（完整覆盖） > base_url + $modelsPath > 由对话端点推导 > $default
     *
     * @param array  $config     运行时配置
     * @param string $default    官方默认模型列表端点
     * @param string $modelsPath 该协议的模型列表路径，如 '/v1/models'
     * @return string
     */
    public static function resolveModels(array $config, string $default, string $modelsPath = '/v1/models'): string
    {
        $full = trim((string)($config['endpoint_models'] ?? ''));
        if ($full !== '') {
            return self::withScheme($full);
        }

        $base = trim((string)($config['base_url'] ?? ''));
        if ($base !== '') {
            $url = self::join($base, $modelsPath);
            if ($url !== '') {
                return $url;
            }
        }

        // 只配置了完整对话端点时，从对话端点反推模型列表端点
        $chat = trim((string)($config['endpoint'] ?? ''));
        if ($chat !== '') {
            $url = self::deriveFromChat($chat, $modelsPath);
            if ($url !== '') {
                return $url;
            }
        }

        return $default;
    }

    /**
     * 由对话端点推导同源的其它端点（去掉对话动作后缀，再拼接目标路径）
     *
     * 例：https://proxy.com/api/v1/chat/completions + /v1/models => https://proxy.com/api/v1/models
     *
     * @param string $chatEndpoint 完整对话端点
     * @param string $path         目标路径，如 '/v1/models'
     * @return string 推导失败返回空串
     */
    public static function deriveFromChat(string $chatEndpoint, string $path): string
    {
        $up = parse_url(self::withScheme($chatEndpoint));
        if (empty($up['host'])) {
            return '';
        }

        $origin = ($up['scheme'] ?? 'https') . '://' . $up['host'] . (isset($up['port']) ? ':' . $up['port'] : '');
        $segs   = array_values(array_filter(explode('/', trim($up['path'] ?? '', '/')), 'strlen'));

        // 剥离各协议的对话动作后缀
        $suffixes = [
            ['chat', 'completions'],
            ['messages'],
            ['completions'],
            ['chat'],
        ];
        foreach ($suffixes as $suffix) {
            $len = count($suffix);
            if (count($segs) >= $len && array_slice($segs, -$len) === $suffix) {
                $segs = array_slice($segs, 0, -$len);
                break;
            }
        }

        $base = $origin . ($segs ? '/' . implode('/', $segs) : '');
        return self::join($base, $path);
    }

    /**
     * 智能拼接「接口根地址」与「路径」
     *
     * - $base 缺 scheme 时按 https 处理
     * - $base 自带路径时保留（支持带前缀的网关）
     * - $base 路径尾部与 $path 头部重叠的片段自动去重，避免 /v1/v1/chat/completions
     *
     * @param string $base 接口根地址，如 https://proxy.com、https://proxy.com/openai
     * @param string $path 官方路径，如 /v1/chat/completions
     * @return string 拼接结果；$base 非法时返回空串
     */
    public static function join(string $base, string $path): string
    {
        $bp = parse_url(self::withScheme(trim($base)));
        // base 非法或主机名不含有效字符：不冒险改写
        if (empty($bp['host']) || !preg_match('/[a-z0-9]/i', $bp['host'])) {
            return '';
        }

        $origin = ($bp['scheme'] ?? 'https') . '://' . $bp['host'];
        if (isset($bp['port'])) {
            $origin .= ':' . $bp['port'];
        }

        $baseSegs = array_values(array_filter(explode('/', trim($bp['path'] ?? '', '/')), 'strlen'));
        $pathSegs = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));

        if (!$baseSegs) {
            return $origin . ($pathSegs ? '/' . implode('/', $pathSegs) : '');
        }
        if (!$pathSegs) {
            return $origin . '/' . implode('/', $baseSegs);
        }

        // 找出 base 尾部与 path 头部最长的重叠段数
        $overlap = 0;
        $max     = min(count($baseSegs), count($pathSegs));
        for ($k = $max; $k >= 1; $k--) {
            if (array_slice($baseSegs, -$k) === array_slice($pathSegs, 0, $k)) {
                $overlap = $k;
                break;
            }
        }

        $merged = array_merge($baseSegs, array_slice($pathSegs, $overlap));
        return $origin . '/' . implode('/', $merged);
    }

    /**
     * 用 $base 的来源（scheme://host:port）替换 $url 的来源，保留 $url 的路径与查询串。
     *
     * 保留此方法用于兼容旧调用；新代码建议用 join()（支持带路径前缀的网关）。
     *
     * @param string $url  原始完整 URL
     * @param string $base 提供新来源的 URL（缺少 scheme 时按 https 处理；其路径被忽略）
     * @return string 替换来源后的完整 URL；$base 无法解析出主机时原样返回 $url
     */
    public static function replaceOrigin(string $url, string $base): string
    {
        $bp = parse_url(self::withScheme(trim($base)));
        if (empty($bp['host']) || !preg_match('/[a-z0-9]/i', $bp['host'])) {
            return $url;
        }

        $origin = ($bp['scheme'] ?? 'https') . '://' . $bp['host'];
        if (isset($bp['port'])) {
            $origin .= ':' . $bp['port'];
        }

        $up    = parse_url($url);
        $path  = $up['path']  ?? '';
        $query = isset($up['query']) ? ('?' . $up['query']) : '';

        return $origin . $path . $query;
    }

    /**
     * 判断两个地址是否同一主机（忽略大小写与端口以外的差异）
     *
     * 用于「实际请求端点是不是协议官方域名」这类判断。
     *
     * @param string $a 完整 URL 或 host
     * @param string $b 完整 URL 或 host
     * @return bool 任一方解析不出主机名时返回 false
     */
    public static function sameHost(string $a, string $b): bool
    {
        $hostA = parse_url(self::withScheme(trim($a)), PHP_URL_HOST);
        $hostB = parse_url(self::withScheme(trim($b)), PHP_URL_HOST);
        if (!$hostA || !$hostB) {
            return false;
        }
        return strcasecmp($hostA, $hostB) === 0;
    }

    /**
     * 补全 scheme（缺省按 https）
     */
    public static function withScheme(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url)) {
            return $url;
        }
        return 'https://' . ltrim($url, '/');
    }
}
