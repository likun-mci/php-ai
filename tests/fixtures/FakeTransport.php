<?php
namespace Tests\Fixtures;

use Ai\Contracts\TransportInterface;

/**
 * 可预置响应的假传输层
 *
 * 多模态测试离不开它：图像/语音/视频接口按次计费且单价不低，
 * 让测试去打真实接口既慢又费钱，还会因为网络波动变成随机失败的测试。
 *
 * 支持按顺序排队多个响应，用来模拟异步任务的状态流转
 * （第一次查询 running、第三次 succeeded）。
 */
class FakeTransport implements TransportInterface
{
    /** @var array<int, array<string, mixed>> 预置的 POST 响应队列 */
    protected $postQueue = [];
    /** @var array<int, array<string, mixed>> 预置的 GET 响应队列 */
    protected $getQueue = [];
    /** @var array<int, array{url: string, data: array<string, mixed>, headers: array<string, string>}> */
    protected $requests = [];
    /** @var callable|null */
    protected $streamCallback = null;
    /** @var int */
    protected $timeout = 120;
    /** @var string */
    protected $proxy = '';

    /**
     * 排入一个 POST 响应。可多次调用，按调用顺序返回
     * @param array<string, mixed> $response
     */
    public function queuePost(array $response): self
    {
        $this->postQueue[] = $response;
        return $this;
    }

    /**
     * 排入一个 GET 响应（任务轮询用）
     * @param array<string, mixed> $response
     */
    public function queueGet(array $response): self
    {
        $this->getQueue[] = $response;
        return $this;
    }

    /**
     * 全部收到过的请求，用于断言请求体是否正确
     * @return array<int, array{url: string, data: array<string, mixed>, headers: array<string, string>}>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    /**
     * 最后一次请求
     * @return array{url: string, data: array<string, mixed>, headers: array<string, string>}|null
     */
    public function lastRequest(): ?array
    {
        return $this->requests ? $this->requests[count($this->requests) - 1] : null;
    }

    public function reset(): self
    {
        $this->postQueue = [];
        $this->getQueue  = [];
        $this->requests  = [];
        return $this;
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function post(string $url, array $data, array $headers = []): array
    {
        $this->requests[] = ['url' => $url, 'data' => $data, 'headers' => $headers];
        return $this->postQueue ? array_shift($this->postQueue) : [];
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function get(string $url, array $params = [], array $headers = []): array
    {
        $this->requests[] = ['url' => $url, 'data' => $params, 'headers' => $headers];
        return $this->getQueue ? array_shift($this->getQueue) : [];
    }

    public function setTimeout(int $timeout): TransportInterface
    {
        $this->timeout = $timeout;
        return $this;
    }

    public function setProxy(string $proxy): TransportInterface
    {
        $this->proxy = $proxy;
        return $this;
    }

    public function setStreamCallback(?callable $callback): TransportInterface
    {
        $this->streamCallback = $callback;
        return $this;
    }

    /**
     * 当前是否挂着流式回调。用于断言扩展能力请求前已清空对话的流式回调
     */
    public function hasStreamCallback(): bool
    {
        return $this->streamCallback !== null;
    }
}
