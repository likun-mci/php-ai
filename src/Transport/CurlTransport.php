<?php
namespace Ai\Transport;

use Ai\Contracts\TransportInterface;
use Ai\Exceptions\RequestException;

/**
 * HTTP 传输层实现（使用 cURL）
 */
class CurlTransport implements TransportInterface
{
    /**
     * 超时时间（秒）
     * @var int
     */
    protected $timeout = 60;

    /**
     * 连接超时时间（秒），null 表示与 $timeout 相同
     * @var int|null
     */
    protected $connectTimeout = null;

    /**
     * 自定义 User-Agent，空串表示不发送
     * @var string
     */
    protected $userAgent = '';

    /**
     * 是否校验 SSL（生产环境不应关闭，仅调试/内网自签场景使用）
     * @var bool
     */
    protected $sslVerify = true;

    /**
     * 代理地址
     * @var string
     */
    protected $proxy = '';

    /**
     * 代理类型
     * @var int
     */
    protected $proxyType = CURLPROXY_HTTP;

    /**
     * 流式输出回调函数
     * @var callable|null
     */
    protected $streamCallback = null;

    /**
     * 流式输出缓冲区
     * @var string
     */
    protected $streamBuffer = '';

    /**
     * 流式输出完整内容
     * @var string
     */
    protected $streamFullContent = '';

    /**
     * 流式输出中捕获到的最后一个 usage 数据
     * @var array
     */
    protected $streamLastUsage = [];

    /**
     * 流式过程中平台回传的错误信息（部分平台出错时仍返回 HTTP 200）
     * @var string
     */
    protected $streamError = '';

    /**
     * 最近一次请求的 cURL info（调试用）
     * @var array
     */
    protected $lastInfo = [];

    /**
     * 发送 POST 请求
     */
    public function post(string $url, array $data, array $headers = []): array
    {
        // 重置流式数据
        $this->streamBuffer = '';
        $this->streamFullContent = '';
        $this->streamLastUsage = [];
        $this->streamError = '';

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        if ($this->connectTimeout !== null) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        }
        if ($this->userAgent !== '') {
            curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        }
        if (!$this->sslVerify) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        // 设置代理
        if (!empty($this->proxy)) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
            curl_setopt($ch, CURLOPT_PROXYTYPE, $this->proxyType);
        }

        // 如果设置了流式回调，启用流式输出
        if ($this->streamCallback !== null) {
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
                return $this->handleStreamData($data);
            });
        }

        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        // 强制 HTTP/1.1，规避部分服务端/代理在 HTTP/2 下的 "SSL unexpected eof while reading"
        if (defined('CURL_HTTP_VERSION_1_1')) {
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        }
        curl_setopt($ch, CURLOPT_FORBID_REUSE, true);

        // 非流式：对连接级瞬时错误（SSL eof / recv 错误 / 空响应等）自动重试，提升多轮 Agent 稳定性
        $maxTries = ($this->streamCallback !== null) ? 1 : 3;
        $response = false; $errno = 0; $error = '';
        for ($try = 1; $try <= $maxTries; $try++) {
            $response = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            if ($response !== false) break;
            // 35=SSL connect, 52=empty reply, 55=send error, 56=recv error(含 unexpected eof)
            if (!in_array($errno, [35, 52, 55, 56], true) || $try >= $maxTries) break;
            usleep(400000 * $try);
        }
        $this->lastInfo = curl_getinfo($ch);
        $httpCode = $this->lastInfo['http_code'] ?? 0;
        if (function_exists('curl_close') && version_compare(PHP_VERSION, '8.0.0', '<')) {
            curl_close($ch);
        }

        // 如果使用了流式输出，response 已经在 streamFullContent 中
        if ($this->streamCallback !== null) {
            // 收尾：部分服务端最后一行不带换行符，缓冲区里可能还压着一条完整的 data
            // （通常正是带 usage 的收尾帧），不冲刷就会连内容带用量一起丢掉
            $this->flushStreamBuffer();
            if (!empty($this->streamFullContent)) {
                $response = $this->streamFullContent;
            }
        }

        if ($response === false || $httpCode >= 400) {
            // 尝试解析错误响应体
            $errorResponse = [];
            $errorMessage = $error ?: "HTTP Error: {$httpCode}";

            if ($response && is_string($response)) {
                $decoded = json_decode($response, true);
                if ($decoded) {
                    $errorResponse = $decoded;
                    if (isset($decoded['error']['message'])) {
                        $errorMessage .= ': ' . $decoded['error']['message'];
                    } elseif (isset($decoded['error'])) {
                        $errorMessage .= ': ' . json_encode($decoded['error']);
                    } elseif (isset($decoded['message'])) {
                        $errorMessage .= ': ' . $decoded['message'];
                    }
                } else {
                    $errorResponse['raw_response'] = $response;
                }
            }

            // 传输层元信息另存，不混入 $errorResponse（getRawResponse() 只返回平台原始错误）
            throw new RequestException(
                $errorMessage,
                '',
                (string)$httpCode,
                $errorResponse
            );
        }

        $decoded = json_decode($response, true);
        return $decoded ?: [];
    }

    /**
     * 发送 GET 请求
     */
    public function get(string $url, array $params = [], array $headers = []): array
    {
        if (!empty($params)) {
            $sep = (strpos($url, '?') === false) ? '?' : '&';
            $url .= $sep . http_build_query($params);
        }

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        if ($this->connectTimeout !== null) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        }
        if ($this->userAgent !== '') {
            curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        }
        if (!$this->sslVerify) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        // 设置代理
        if (!empty($this->proxy)) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
            curl_setopt($ch, CURLOPT_PROXYTYPE, $this->proxyType);
        }

        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

        $response = curl_exec($ch);
        $this->lastInfo = curl_getinfo($ch);
        $httpCode = $this->lastInfo['http_code'] ?? 0;
        $error = curl_error($ch);
        if (function_exists('curl_close') && version_compare(PHP_VERSION, '8.0.0', '<')) {
            curl_close($ch);
        }

        if ($response === false || $httpCode >= 400) {
            $errorResponse = [];
            $errorMessage = $error ?: "HTTP Error: {$httpCode}";

            if ($response && is_string($response)) {
                $decoded = json_decode($response, true);
                if ($decoded) {
                    $errorResponse = $decoded;
                    if (isset($decoded['error']['message'])) {
                        $errorMessage .= ': ' . $decoded['error']['message'];
                    } elseif (isset($decoded['error'])) {
                        $errorMessage .= ': ' . json_encode($decoded['error']);
                    } elseif (isset($decoded['message'])) {
                        $errorMessage .= ': ' . $decoded['message'];
                    }
                } else {
                    $errorResponse['raw_response'] = $response;
                }
            }

            throw new RequestException(
                $errorMessage,
                '',
                (string)$httpCode,
                $errorResponse
            );
        }

        $decoded = json_decode($response, true);
        return $decoded ?: [];
    }

    /**
     * 设置超时时间
     */
    public function setTimeout(int $timeout): TransportInterface
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * 设置连接超时时间（秒）。
     * 不调用则与总超时一致；传 0 表示不设连接超时。
     */
    public function setConnectTimeout(int $seconds): TransportInterface
    {
        $this->connectTimeout = $seconds;
        return $this;
    }

    /**
     * 设置 User-Agent 请求头。传空串（默认）不发送。
     */
    public function setUserAgent(string $userAgent): TransportInterface
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    /**
     * 设置是否校验 SSL 证书。
     * 生产环境不应关闭，仅调试/内网自签证书时使用。
     */
    public function setSslVerify(bool $verify): TransportInterface
    {
        $this->sslVerify = $verify;
        return $this;
    }

    /**
     * 获取最近一次请求的 cURL info（调试用）
     * @return array
     */
    public function getLastInfo(): array
    {
        return $this->lastInfo;
    }

    /**
     * 设置网络代理
     * 自动识别代理协议类型
     */
    public function setProxy(string $proxy): TransportInterface
    {
        if (empty($proxy)) {
            $this->proxy = '';
            return $this;
        }

        // 解析代理协议
        $parsed = parse_url($proxy);

        if (!$parsed || !isset($parsed['scheme'])) {
            throw new \InvalidArgumentException('Invalid proxy format. Expected: protocol://host:port');
        }

        $scheme = strtolower($parsed['scheme']);

        // 设置代理类型
        switch ($scheme) {
            case 'http':
            case 'https':
                $this->proxyType = CURLPROXY_HTTP;
                break;

            case 'socks5':
                $this->proxyType = CURLPROXY_SOCKS5;
                break;

            case 'socks5h':
                $this->proxyType = CURLPROXY_SOCKS5_HOSTNAME;
                break;

            case 'socks4':
                $this->proxyType = CURLPROXY_SOCKS4;
                break;

            case 'socks4a':
                $this->proxyType = CURLPROXY_SOCKS4A;
                break;

            default:
                throw new \InvalidArgumentException("Unsupported proxy protocol: {$scheme}");
        }

        $this->proxy = $proxy;
        return $this;
    }

    /**
     * 返回流式请求中捕获到的 usage 数据
     * @return array
     */
    public function getStreamUsage(): array
    {
        return $this->streamLastUsage;
    }

    /**
     * 返回流式过程中平台回传的错误信息，无错误时为空串
     * @return string
     */
    public function getStreamError(): string
    {
        return $this->streamError;
    }

    /**
     * 设置流式输出回调函数
     */
    public function setStreamCallback(?callable $callback): TransportInterface
    {
        $this->streamCallback = $callback;
        return $this;
    }

    /**
     * 处理流式数据
     * @param string $data 接收到的数据块
     * @return int 返回处理的字节数
     */
    protected function handleStreamData(string $data): int
    {
        $length = strlen($data);

        // 累加到完整内容中
        $this->streamFullContent .= $data;

        // 累加到缓冲区
        $this->streamBuffer .= $data;

        // 解析 SSE 格式的流式数据
        $lines = explode("\n", $this->streamBuffer);

        // 保留最后一行（可能不完整）
        $this->streamBuffer = array_pop($lines);

        foreach ($lines as $line) {
            $this->handleStreamLine($line);
        }

        return $length;
    }

    /**
     * 请求结束时冲刷缓冲区里残留的最后一行
     *
     * 服务端最后一帧不带换行符时，handleStreamData() 会把它当作「可能不完整」留在
     * 缓冲区里。收尾时必须再解析一次，否则最后一个分片（往往是带 usage 的收尾帧）会丢失。
     */
    protected function flushStreamBuffer(): void
    {
        $line = $this->streamBuffer;
        $this->streamBuffer = '';
        $this->handleStreamLine($line);
    }

    /**
     * 解析一行 SSE 数据
     *
     * 按 SSE 规范，字段名后的冒号与紧随其后的**一个**空格都是可选的，
     * 即 "data: {...}" 与 "data:{...}" 等价——讯飞星火等平台用的正是后者，
     * 只认带空格的写法会导致这些平台整个流式输出为空。
     */
    protected function handleStreamLine(string $line): void
    {
        $line = trim($line);
        if ($line === '' || strpos($line, 'data:') !== 0) {
            // 空行与 event: / id: / retry: 等其它 SSE 字段一律跳过
            return;
        }

        // 去掉 "data:" 前缀，再去掉紧随其后的一个可选空格
        $jsonData = substr($line, 5);
        if (isset($jsonData[0]) && $jsonData[0] === ' ') {
            $jsonData = substr($jsonData, 1);
        }

        // 结束标记
        if ($jsonData === '' || $jsonData === '[DONE]') {
            return;
        }

        $decoded = json_decode($jsonData, true);
        if ($decoded === null || !$this->streamCallback) {
            return;
        }

        // 捕获 usage（OpenAI 系开启 stream_options 后在末尾 chunk 返回）
        // 协议层可通过 parseStreamUsage() 提供更准确的解析，AI 层优先用那份
        if (!empty($decoded['usage']) && is_array($decoded['usage'])) {
            $this->streamLastUsage = $decoded['usage'];
        }

        // 捕获平台在流中回传的错误（此类响应 HTTP 状态码仍是 200）
        if (isset($decoded['error'])) {
            $err = $decoded['error'];
            $this->streamError = is_array($err)
                ? (string)($err['message'] ?? json_encode($err, JSON_UNESCAPED_UNICODE))
                : (string)$err;
        }

        try {
            // 直接传递原始数据给回调，不在这里提取内容
            // 内容提取由协议层负责
            call_user_func($this->streamCallback, $decoded);
        } catch (\Exception $e) {
            // 回调异常不影响主流程
            error_log('Stream callback error: ' . $e->getMessage());
        }
    }
}
