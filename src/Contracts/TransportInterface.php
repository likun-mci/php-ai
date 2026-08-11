<?php
namespace Ai\Contracts;

/**
 * HTTP 传输层接口
 */
interface TransportInterface
{
    /**
     * 发送 POST 请求
     * @param string $url 请求 URL
     * @param array $data 请求数据
     * @param array $headers 请求头
     * @return array 响应数据
     */
    public function post(string $url, array $data, array $headers = []): array;
    
    /**
     * 发送 GET 请求
     * @param string $url 请求 URL
     * @param array $params 请求参数
     * @param array $headers 请求头
     * @return array 响应数据
     */
    public function get(string $url, array $params = [], array $headers = []): array;
    
    /**
     * 设置超时时间
     * @param int $timeout 超时秒数
     * @return self
     */
    public function setTimeout(int $timeout): self;
    
    /**
     * 设置网络代理
     * 支持格式：
     * - http://host:port
     * - https://host:port
     * - socks5://host:port
     * - socks5h://host:port
     * @param string $proxy 代理地址
     * @return self
     */
    public function setProxy(string $proxy): self;
    
    /**
     * 设置流式输出回调函数
     * @param callable|null $callback 回调函数，接收流式数据块
     * @return self
     */
    public function setStreamCallback(?callable $callback): self;
}
