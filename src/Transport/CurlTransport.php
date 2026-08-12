<?php
namespace Ai\Transport;

use Ai\Contracts\TransportInterface;
use Ai\Exceptions\RequestException;
use Ai\Helpers\AIFile;
use Ai\Helpers\Media;

/**
 * HTTP 传输层实现（使用 cURL）
 */
class CurlTransport implements TransportInterface
{
    /**
     * 超时时间（秒）
     *
     * 默认 120：推理类模型（o3 / DeepSeek-Reasoner / GLM 思考模式 / Claude 思考）
     * 单次响应经常跑到一两分钟，60 秒会在正常场景下误杀。
     * @var int
     */
    protected $timeout = 120;

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
     * @var array<mixed>
     */
    protected $streamLastUsage = [];

    /**
     * 流式过程中平台回传的错误信息（部分平台出错时仍返回 HTTP 200）
     * @var string
     */
    protected $streamError = '';

    /**
     * 最近一次请求的 cURL info（调试用）
     * @var array<mixed>
     */
    protected $lastInfo = [];

    /**
     * 可重试的 HTTP 状态码：429 限流，5xx 服务端临时故障
     * 529 是 Anthropic 的过载码，一并纳入
     * @var array<mixed>
     */
    protected $retryStatuses = [408, 409, 429, 500, 502, 503, 504, 529];

    /**
     * 最大重试次数（不含首次请求）。设为 0 关闭重试
     * @var int
     */
    protected $maxRetries = 2;

    /**
     * 退避基数（毫秒），实际等待 = base * 2^(次数-1) + 抖动
     * @var int
     */
    protected $retryBaseMs = 500;

    /**
     * 单次退避的等待上限（毫秒），避免 Retry-After 给出超长值时把进程挂死
     * @var int
     */
    protected $retryMaxDelayMs = 20000;

    /**
     * 发送 POST 请求
     * @param array<string, string> $headers * @return array<mixed>
     * @return array<string, mixed>
     */
    public function post(string $url, array $data, array $headers = []): array
    {
        // 重置流式数据
        $this->streamBuffer = '';
        $this->streamFullContent = '';
        $this->streamLastUsage = [];
        $this->streamError = '';

        // 请求体的编码方式由 headers 里的 Content-Type 决定。
        // 没有显式声明、或声明为 application/json 时走原来那条路径——
        // 这覆盖了全部对话请求，行为与改造前逐字节等价。
        // 注意 $headers 是引用传入：multipart 需要把调用方写的 Content-Type 摘掉
        $payload = $this->encodeRequestBody($data, $headers);

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
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

        // 自动重试：连接级瞬时错误 + 可重试的 HTTP 状态码（429 限流、5xx 临时故障）。
        // 流式请求已经把分片吐给调用方了，重试会造成重复输出，因此只发一次。
        $maxTries = ($this->streamCallback !== null) ? 1 : ($this->maxRetries + 1);
        $response = false; $errno = 0; $error = ''; $httpCode = 0;
        $respHeaders = '';

        for ($try = 1; $try <= $maxTries; $try++) {
            $respHeaders = '';
            if ($maxTries > 1) {
                // 只在需要读 Retry-After 时才收响应头
                curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $line) use (&$respHeaders) {
                    $respHeaders .= $line;
                    return strlen($line);
                });
            }

            $response = curl_exec($ch);
            $errno    = curl_errno($ch);
            $error    = curl_error($ch);
            $info     = curl_getinfo($ch);
            $httpCode = $info['http_code'] ?? 0;

            if ($try >= $maxTries) {
                break;
            }

            // 连接级瞬时错误：35=SSL connect, 52=empty reply, 55=send error, 56=recv error(含 unexpected eof)
            $retryableError  = ($response === false && in_array($errno, [35, 52, 55, 56], true));
            // 服务端明确表示「稍后再来」
            $retryableStatus = ($response !== false && in_array((int)$httpCode, $this->retryStatuses, true));

            if (!$retryableError && !$retryableStatus) {
                break;
            }

            usleep($this->retryDelayMs($try, $respHeaders) * 1000);
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

        // 响应解码：**只有**明确属于二进制媒体（audio/* image/* video/*
        // application/octet-stream）时才跳过 json_decode 并原样带回字节。
        // 刻意用白名单而非「不是 JSON 就当二进制」——text/html 等异常响应
        // 仍走原来的 json_decode 路径返回空数组，对话链路的行为一点没变
        $respType = (string) ($this->lastInfo['content_type'] ?? '');
        if (is_string($response) && Media::isBinaryContentType($respType)) {
            return [
                '_raw'          => $response,
                '_content_type' => $respType,
                '_status'       => (int) $httpCode,
            ];
        }

        // curl_exec() 未开 RETURNTRANSFER 时会返回 true；本类始终开启，
        // 但静态类型仍是 string|bool，取值前显式收窄，避免把 bool 喂给 json_decode()
        $decoded = is_string($response) ? json_decode($response, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 按 Content-Type 编码请求体
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers 引用传入，multipart 时会摘掉 Content-Type
     * @return string|array<string, mixed> 数组形式交给 curl 即触发 multipart
     */
    protected function encodeRequestBody(array $data, array &$headers)
    {
        $contentType = $this->headerValue($headers, 'Content-Type');

        // —— 默认路径：无声明或声明 JSON ——
        if ($contentType === '' || stripos($contentType, 'application/json') === 0) {
            // 先编码再发：json_encode 失败（最常见是内容含非 UTF-8 字节，
            // 如从 GBK 库表里读出来的旧数据）会返回 false，直接塞给 CURLOPT_POSTFIELDS
            // 会被当成空 body 发出去，拿到一个语义完全错误的响应且全程无报错
            $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                throw new RequestException(
                    '请求体 JSON 编码失败：' . json_last_error_msg()
                    . '（常见原因：消息内容含非 UTF-8 字节，请先转成 UTF-8）',
                    '',
                    'json_encode_failed',
                    []
                );
            }
            return $payload;
        }

        // —— 表单上传（语音识别传音频文件用）——
        if (stripos($contentType, 'multipart/form-data') === 0) {
            // 必须把调用方写的 Content-Type 摘掉，交给 curl 自己生成。
            // multipart 的 Content-Type 里带一个随机 boundary，手写的那句
            // "multipart/form-data" 没有 boundary，服务端会拿到一个无法解析的 body，
            // 而请求本身往往仍返回 200——**静默失败**，是这条路径上最难查的坑
            $headers = $this->removeHeader($headers, 'Content-Type');
            return $this->buildMultipartFields($data);
        }

        // —— 其它类型（x-www-form-urlencoded、text/plain 等）——
        return $this->buildRawBody($data);
    }

    /**
     * 组装 multipart 字段，把文件类值转成 CURLFile
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function buildMultipartFields(array $data): array
    {
        $fields = [];
        foreach ($data as $key => $value) {
            if ($value instanceof \CURLFile) {
                $fields[$key] = $value;
                continue;
            }
            if ($value instanceof AIFile) {
                $fields[$key] = $this->toCurlFile($value);
                continue;
            }
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $fields[$key] = $value ? 'true' : 'false';
                continue;
            }
            if (is_array($value) || is_object($value)) {
                // multipart 表达不了嵌套结构，转成 JSON 字符串——多数平台接受这种写法
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
                $fields[$key] = $encoded === false ? '' : $encoded;
                continue;
            }
            $fields[$key] = (string) $value;
        }
        return $fields;
    }

    /**
     * AIFile 转 CURLFile
     *
     * 只接受本地路径。远端 URL 需要调用方先取回本地——下载应当走带 SSRF 防护的
     * Helpers\Media::download()，不该由传输层顺手代劳
     */
    protected function toCurlFile(AIFile $file): \CURLFile
    {
        if ($file->getType() !== 'path') {
            throw new RequestException(
                '表单上传只接受本地文件；远端 URL 请先用 Ai\\Helpers\\Media::download() 取回并落盘后再传',
                '',
                'multipart_needs_local_file',
                []
            );
        }
        $path = $file->getSource();
        $mime = $file->getMimeType();
        return new \CURLFile($path, $mime !== '' ? $mime : 'application/octet-stream', basename($path));
    }

    /**
     * 组装非 JSON、非 multipart 的请求体
     *
     * 约定 _body 键可直接给出原始字符串请求体，用于平台要求特殊编码的少数场景；
     * 否则按表单编码
     *
     * @param array<string, mixed> $data
     */
    protected function buildRawBody(array $data): string
    {
        if (isset($data['_body']) && is_string($data['_body'])) {
            return $data['_body'];
        }
        return http_build_query($data);
    }

    /**
     * 大小写不敏感地取请求头的值，取不到返回空串
     *
     * HTTP 头名本就大小写不敏感，调用方写 content-type / Content-Type 都得认
     *
     * @param array<string, string> $headers
     */
    protected function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return (string) $value;
            }
        }
        return '';
    }

    /**
     * 大小写不敏感地移除某个请求头
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    protected function removeHeader(array $headers, string $name): array
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp((string) $key, $name) === 0) {
                unset($headers[$key]);
            }
        }
        return $headers;
    }

    /**
     * 计算第 $attempt 次失败后的退避等待时长（毫秒）
     *
     * 优先采纳服务端 Retry-After（秒数或 HTTP 日期两种写法都支持），
     * 没有则用指数退避 + 抖动——抖动是为了避免多个进程同时被限流后
     * 又在同一时刻齐刷刷重试，把服务端再打垮一次。
     *
     * @param int    $attempt     已失败的次数，从 1 开始
     * @param string $respHeaders 原始响应头文本
     */
    protected function retryDelayMs(int $attempt, string $respHeaders): int
    {
        if ($respHeaders !== '' && preg_match('/^retry-after:\s*(.+)$/im', $respHeaders, $m)) {
            $value = trim($m[1]);
            if (is_numeric($value)) {
                $ms = (int) round((float) $value * 1000);
            } else {
                $ts = strtotime($value);
                $ms = ($ts !== false) ? (int) max(0, ($ts - time()) * 1000) : 0;
            }
            if ($ms > 0) {
                return min($ms, $this->retryMaxDelayMs);
            }
        }

        $ms = $this->retryBaseMs * (1 << ($attempt - 1));   // 指数退避
        $ms += random_int(0, (int) ($this->retryBaseMs / 2)); // 抖动
        return min($ms, $this->retryMaxDelayMs);
    }

    /**
     * 配置重试策略
     *
     * @param int      $maxRetries 最大重试次数（不含首次），0 表示关闭
     * @param int|null $baseMs     退避基数（毫秒），null 保持不变
     * @param int|null $maxDelayMs 单次等待上限（毫秒），null 保持不变
     */
    public function setRetry(int $maxRetries, ?int $baseMs = null, ?int $maxDelayMs = null): TransportInterface
    {
        $this->maxRetries = max(0, $maxRetries);
        if ($baseMs !== null) {
            $this->retryBaseMs = max(0, $baseMs);
        }
        if ($maxDelayMs !== null) {
            $this->retryMaxDelayMs = max(0, $maxDelayMs);
        }
        return $this;
    }

    /**
     * 自定义哪些 HTTP 状态码需要重试
     * @param int[] $statuses 状态码数组，传空数组表示只重试连接级错误
     */
    public function setRetryStatuses(array $statuses): TransportInterface
    {
        $this->retryStatuses = array_values(array_map('intval', $statuses));
        return $this;
    }

    /**
     * 并发发送多个 POST 请求（curl_multi）
     *
     * 批量场景（翻译、摘要、打标签……）串行跑，耗时是「单条 × 条数」，
     * 且每条都要重做一次 TLS 握手。这里用 curl_multi 并发，总耗时约等于
     * 「最慢的一条 × 批次数」。
     *
     * 与 post() 的差异：
     *   - 单条失败不影响其它条，失败项在返回值里以 error 字段呈现，不抛异常
     *   - 不支持流式（并发流式没有意义，回调会互相穿插）
     *   - 重试按条独立进行
     *
     * @param array<string|int, array{url: string, data: array<string, mixed>, headers: array<string, string>}> $requests [
     *     ['url'=>..., 'data'=>[...], 'headers'=>[...]],   // 键可自定义，返回时原样对应
     *     ...
     * ]
     * @param int   $concurrency 同时在途的最大请求数，默认 5；过大易触发平台限流
     * @return array<string|int, array{ok: bool, status: int, error: string, response: array<string, mixed>}> 与入参同键的结果数组，每项为：
     *     ['ok'=>true,  'status'=>200, 'response'=>[...]]
     *     ['ok'=>false, 'status'=>429, 'error'=>'...', 'response'=>[原始错误体]]
     */
    public function postConcurrent(array $requests, int $concurrency = 5): array
    {
        if (!$requests) {
            return [];
        }
        $concurrency = max(1, $concurrency);
        $results     = [];
        $queue       = $requests;          // 待发送队列（保留原始键）
        $keys        = array_keys($queue);
        $cursor      = 0;
        $active      = [];                 // curl 句柄 => ['key'=>..., 'tries'=>..., 'req'=>...]

        $mh = curl_multi_init();

        // 把队列里的下一条塞进 multi 句柄
        $push = function () use (&$cursor, &$keys, &$queue, &$active, $mh, &$results) {
            while ($cursor < count($keys)) {
                $key = $keys[$cursor];
                $cursor++;
                $req = $queue[$key];
                $ch  = $this->buildHandle($req, $results, $key);
                if ($ch === null) {
                    continue;              // 编码失败等，已写入 $results，跳过
                }
                curl_multi_add_handle($mh, $ch);
                $active[(int) $ch] = ['key' => $key, 'tries' => 1, 'req' => $req, 'handle' => $ch];
                return true;
            }
            return false;
        };

        for ($i = 0; $i < $concurrency; $i++) {
            if (!$push()) {
                break;
            }
        }

        do {
            do {
                $status = curl_multi_exec($mh, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            // 阻塞等待，避免空转把 CPU 打满
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }

            while ($done = curl_multi_info_read($mh)) {
                $ch   = $done['handle'];
                $id   = (int) $ch;
                $meta = $active[$id] ?? null;
                unset($active[$id]);

                $body     = curl_multi_getcontent($ch);
                $errno    = curl_errno($ch);
                $error    = curl_error($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($mh, $ch);

                if ($meta === null) {
                    continue;
                }

                // 可重试：连接级瞬时错误，或 429/5xx
                // 注意：curl_multi_getcontent() 失败时返回 null 或空串，**不会返回 false**，
                // 所以这里必须看 errno，不能像单条 post() 那样判 $body === false
                $retryable = (in_array($errno, [35, 52, 55, 56], true))
                          || in_array($httpCode, $this->retryStatuses, true);

                if ($retryable && $meta['tries'] <= $this->maxRetries) {
                    // 并发场景不读 Retry-After（拿不到单条响应头），直接指数退避
                    usleep($this->retryDelayMs($meta['tries'], '') * 1000);
                    $newCh = $this->buildHandle($meta['req'], $results, $meta['key']);
                    if ($newCh !== null) {
                        curl_multi_add_handle($mh, $newCh);
                        $active[(int) $newCh] = [
                            'key'    => $meta['key'],
                            'tries'  => $meta['tries'] + 1,
                            'req'    => $meta['req'],
                            'handle' => $newCh,
                        ];
                        continue;
                    }
                }

                $results[$meta['key']] = $this->buildConcurrentResult($body, $errno, $error, $httpCode);
                $push();               // 补位，保持在途数量
            }
        } while ($running > 0 || $active);

        curl_multi_close($mh);

        // 按入参顺序返回，调用方可直接与原数组对齐
        $ordered = [];
        foreach (array_keys($requests) as $k) {
            $ordered[$k] = $results[$k] ?? [
                'ok' => false, 'status' => 0, 'error' => '未执行', 'response' => [],
            ];
        }
        return $ordered;
    }

    /**
     * 为并发请求构造单个 curl 句柄；请求体编码失败时直接写结果并返回 null
     * @param array<string, mixed> $results
     * @param int|string $key
     * @return \CurlHandle|resource|null 编码失败时返回 null
     * @param array<mixed> $req
     */
    protected function buildHandle(array $req, array &$results, $key)
    {
        $payload = json_encode($req['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $results[$key] = [
                'ok'       => false,
                'status'   => 0,
                'error'    => '请求体 JSON 编码失败：' . json_last_error_msg(),
                'response' => [],
            ];
            return null;
        }

        $ch = curl_init($req['url'] ?? '');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
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
        if (!empty($this->proxy)) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
            curl_setopt($ch, CURLOPT_PROXYTYPE, $this->proxyType);
        }

        $curlHeaders = [];
        foreach (($req['headers'] ?? []) as $k => $v) {
            $curlHeaders[] = "{$k}: {$v}";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        if (defined('CURL_HTTP_VERSION_1_1')) {
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        }
        return $ch;
    }

    /**
     * 把单条并发请求的原始结果整理成统一结构
     * @return array<mixed>
     * @param string|null $body
     */
    protected function buildConcurrentResult($body, int $errno, string $error, int $httpCode): array
    {
        // errno 非 0 或拿不到状态码都算失败。
        // 不能只判 $body === false —— curl_multi_getcontent() 失败时返回 null/空串，
        // 那样连不上目标端口会被当成「成功但响应为空」返回给调用方
        if ($errno !== 0 || $httpCode === 0 || $httpCode >= 400) {
            $decoded = is_string($body) ? (json_decode($body, true) ?: []) : [];
            $message = $error !== '' ? $error
                : ($httpCode === 0 ? '请求未完成（连接失败或被中断）' : "HTTP Error: {$httpCode}");
            if (isset($decoded['error']['message'])) {
                $message .= ': ' . $decoded['error']['message'];
            } elseif (isset($decoded['message'])) {
                $message .= ': ' . $decoded['message'];
            }
            return ['ok' => false, 'status' => $httpCode, 'error' => $message, 'response' => $decoded];
        }
        return [
            'ok'       => true,
            'status'   => $httpCode,
            'error'    => '',
            'response' => json_decode((string) $body, true) ?: [],
        ];
    }

    /**
     * 发送 GET 请求
     * @param array<string, string> $headers * @param array<string, scalar> $params * @return array<mixed>
     * @return array<string, mixed>
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

        // 与 post() 一致：只有明确属于二进制媒体时才跳过 json_decode 并原样带回字节。
        // GET 也需要这条路径——异步任务取结果时，部分平台（如 Gemini 的
        // /videos/{id}/content）直接回视频字节而不是一个下载地址，
        // 少了这里就会被 json_decode 成空数组，表现为「任务成功但没有内容」
        $respType = (string) ($this->lastInfo['content_type'] ?? '');
        if (is_string($response) && Media::isBinaryContentType($respType)) {
            return [
                '_raw'          => $response,
                '_content_type' => $respType,
                '_status'       => (int) $httpCode,
            ];
        }

        // curl_exec() 未开 RETURNTRANSFER 时会返回 true；本类始终开启，
        // 但静态类型仍是 string|bool，取值前显式收窄，避免把 bool 喂给 json_decode()
        $decoded = is_string($response) ? json_decode($response, true) : null;
        return is_array($decoded) ? $decoded : [];
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
     * @return array<mixed>
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
     * @return array<string, mixed>
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

        // 保留最后一行（可能不完整）。explode 至少返回一个元素，
        // array_pop 理论上不会是 null，仍兜一下以免属性被写成 null
        $this->streamBuffer = array_pop($lines) ?? '';

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
        } catch (\Throwable $e) {
            // 回调是调用方的代码，抛什么都不能影响主流程
            \Ai\Helpers\Log::warning('流式回调抛出异常（已忽略，不影响主流程）', ['error' => $e->getMessage()]);
        }
    }
}
