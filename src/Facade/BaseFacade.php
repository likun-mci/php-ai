<?php
namespace Ai\Facade;

use Ai\AI;
use Ai\Contracts\CapabilityResponseInterface;
use Ai\Contracts\ProtocolInterface;
use Ai\Exceptions\ConfigException;
use Ai\Exceptions\UnsupportedCapabilityException;
use Ai\Helpers\Capabilities;
use Ai\Helpers\Endpoint;

/**
 * 扩展能力子门面基类
 *
 * 每个子门面共享同一个 AI 实例的配置、传输层、协议与回调——
 * 也就是说 setProxy() / setRetry() / setTimeout() / setLogger() 这些
 * 在对话上配好的东西，对图像、语音、视频自动生效，不需要再配一遍。
 *
 * 门面本身不认识任何平台的字段，只负责把「能力名 + 载荷」交给协议层，
 * 平台差异全部收敛在协议类里。
 */
abstract class BaseFacade
{
    /** @var AI */
    protected $ai;

    public function __construct(AI $ai)
    {
        $this->ai = $ai;
    }

    /**
     * 本门面对应的能力标识
     */
    abstract protected function capability(): string;

    /**
     * 取协议实例并确认它支持本能力
     *
     * @throws ConfigException              尚未设置模型
     * @throws UnsupportedCapabilityException 协议不支持本能力
     */
    protected function protocol(string $capability = ''): ProtocolInterface
    {
        $capability = $capability !== '' ? $capability : $this->capability();

        $protocol = $this->ai->protocolInstance();
        if ($protocol === null) {
            throw new ConfigException('尚未设置模型，请先调用 setModel() 或在构造时传入 model');
        }

        if (!in_array($capability, $protocol->capabilities(), true)) {
            $supported = $protocol->capabilities();
            throw new UnsupportedCapabilityException(sprintf(
                '当前模型使用的协议不支持「%s」能力。%s',
                Capabilities::label($capability),
                $supported
                    ? '本协议支持：' . implode('、', array_map([Capabilities::class, 'label'], $supported))
                    : '本协议目前只支持对话（chat）'
            ));
        }

        return $protocol;
    }

    /**
     * 解析本能力的请求端点
     *
     * 由**实际对话端点**推导，而不是回落到协议的官方地址——
     * 用户把 base_url 指向自建网关或中转服务时，图像/语音请求必须走同一个网关。
     * 回落官方地址意味着把用户的数据发到了他没有指定的服务器上，是安全问题。
     */
    protected function endpoint(string $capability = ''): string
    {
        $capability = $capability !== '' ? $capability : $this->capability();
        $protocol   = $this->protocol($capability);

        $path = $protocol->capabilityPath($capability);
        if ($path === '') {
            throw new UnsupportedCapabilityException(sprintf(
                '协议未提供「%s」能力的接口路径',
                Capabilities::label($capability)
            ));
        }

        // 用户显式配了整条能力端点时优先（配置键形如 image_endpoint / tts_endpoint）
        $config = $this->ai->getConfig();
        $key    = $capability . '_endpoint';
        if (!empty($config[$key]) && is_string($config[$key])) {
            return Endpoint::withScheme($config[$key]);
        }

        $chatEndpoint = $this->ai->resolveEndpoint();

        // 优先用协议自己声明的对话路径来剥离——它知道自家路径长什么样。
        // deriveFromChat() 靠猜一组常见后缀，遇到 MiniMax 的
        // /v1/text/chatcompletion_v2 会剥不掉，拼出叠加路径且不报错，
        // 只表现为请求 404，很难反推到端点推导这一层
        $derived = '';
        if (method_exists($protocol, 'chatPath')) {
            $derived = Endpoint::deriveByChatPath($chatEndpoint, $protocol->chatPath(), $path);
        }
        if ($derived === '') {
            $derived = Endpoint::deriveFromChat($chatEndpoint, $path);
        }
        if ($derived === '') {
            throw new ConfigException(sprintf(
                '无法由对话端点「%s」推导出「%s」能力的地址，请在配置中显式指定 %s',
                $chatEndpoint,
                Capabilities::label($capability),
                $key
            ));
        }
        return $derived;
    }

    /**
     * 走一次完整请求：构建 → 发送 → 解析
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $extraHeaders 额外请求头，如 multipart 的 Content-Type
     */
    protected function send(array $payload, array $extraHeaders = [], string $capability = ''): CapabilityResponseInterface
    {
        $capability = $capability !== '' ? $capability : $this->capability();
        $protocol   = $this->protocol($capability);

        $response = $this->dispatch($payload, $extraHeaders, $capability);

        return $protocol->parseCapabilityResponse($capability, $response);
    }

    /**
     * 只发请求、拿原始响应，不做能力级解析
     *
     * 异步任务（视频生成）要的是提交回执里的 task_id，走不了
     * parseCapabilityResponse() 那条路，所以把发送这一段单独抽出来共用
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $extraHeaders
     * @return array<string, mixed>
     */
    protected function dispatch(array $payload, array $extraHeaders = [], string $capability = ''): array
    {
        $capability = $capability !== '' ? $capability : $this->capability();
        $protocol   = $this->protocol($capability);

        $request  = $protocol->buildCapabilityRequest($capability, $payload);
        $headers  = array_merge($protocol->buildHeaders($this->ai->getConfig()), $extraHeaders);
        $endpoint = $this->endpoint($capability);

        // 扩展能力一律非流式，清掉可能残留在传输层上的对话流式回调，
        // 否则上一次 chat(stream) 设的回调会把图像/音频的字节当成 SSE 去解析
        $this->ai->transport()->setStreamCallback(null);

        return $this->ai->transport()->post($endpoint, $request, $headers);
    }

    /**
     * 当前模型名，供各门面填进请求体
     */
    protected function modelName(): string
    {
        $model = $this->ai->model();
        return $model !== null ? $model->getName() : '';
    }
}
