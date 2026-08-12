<?php
namespace Ai\Protocol\Concerns;

use Ai\Contracts\CapabilityResponseInterface;
use Ai\Exceptions\UnsupportedCapabilityException;
use Ai\Helpers\Capabilities;
use Ai\Helpers\Protocols;

/**
 * ProtocolInterface 扩展能力方法的默认实现
 *
 * 存在的唯一目的是**让扩展接口不成为破坏性变更**。
 *
 * 库内 41 个协议类里只有 3 个直接 implements ProtocolInterface
 * （OpenAI / Claude / Gemini），其余 38 个都是 extends 它们——
 * 这也正是 README 教用户接入自定义平台的方式。只要这三个基类用上本 trait，
 * 所有 extends 的代码（含用户自己写的）零改动即可通过。
 *
 * 极少数裸写 `implements ProtocolInterface` 的用户，加一行即可恢复：
 *
 * ```php
 * class MyProtocol implements ProtocolInterface
 * {
 *     use \Ai\Protocol\Concerns\CapabilityDefaults;
 *     // ……原有 6 个方法不动……
 * }
 * ```
 *
 * 协议类要支持某项能力时，覆写 capabilityPathMap() 声明路径，
 * 并覆写 parseCapabilityResponse() 给出解析逻辑。请求体默认原样透传，
 * 需要改写字段名的平台再覆写 buildCapabilityRequest()。
 */
trait CapabilityDefaults
{
    /**
     * 能力标识 => 接口相对路径
     *
     * 默认按**约定**装配：协议类只要定义 `{能力}Path()` 方法并返回非空路径，
     * 就自动被视为支持该能力。例如定义了 embeddingPath() 就支持向量化，
     * 定义了 imagePath() 就支持图像生成。
     *
     * 这样每新增一种能力，协议类不用再维护一份映射表，也不用覆写本方法——
     * 少一处需要同步的地方，就少一类「声明了却没实现」的错位。
     *
     * 用方法而不是属性来承载，是因为 trait 里的属性一旦被使用它的类
     * 以不同初始值重新声明，PHP 会直接抛致命错误；方法则可以自由覆写。
     *
     * @return array<string, string>
     */
    public function capabilityPathMap(): array
    {
        $map = [];
        foreach (Capabilities::all() as $capability) {
            $method = $this->capabilityMethodBase($capability) . 'Path';   // imagePath / imageEditPath ...
            if (!method_exists($this, $method)) {
                continue;
            }
            $path = $this->{$method}();
            if (is_string($path) && $path !== '') {
                $map[$capability] = $path;
            }
        }
        return $map;
    }

    /**
     * @return array<int, string>
     */
    public function capabilities(): array
    {
        return array_keys($this->capabilityPathMap());
    }

    public function capabilityPath(string $capability): string
    {
        $map = $this->capabilityPathMap();
        return isset($map[$capability]) ? $map[$capability] : '';
    }

    /**
     * 默认原样透传。多数 OpenAI 兼容平台的字段名本就一致，无需改写
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function buildCapabilityRequest(string $capability, array $payload): array
    {
        $this->guardCapability($capability);

        // 同样按约定派发：定义了 buildEmbeddingRequest() 就用它，
        // 没定义就原样透传——多数 OpenAI 兼容平台的字段名本就一致
        $method = 'build' . ucfirst($this->capabilityMethodBase($capability)) . 'Request';
        if (method_exists($this, $method)) {
            $built = $this->{$method}($payload);
            return is_array($built) ? $built : $payload;
        }
        return $payload;
    }

    /**
     * @param array<string, mixed> $response
     */
    public function parseCapabilityResponse(string $capability, array $response): CapabilityResponseInterface
    {
        $this->guardCapability($capability);

        $method = 'parse' . ucfirst($this->capabilityMethodBase($capability)) . 'Response';
        if (method_exists($this, $method)) {
            return $this->{$method}($response);
        }

        // 能声明支持、却没实现解析，属于库自身的实现遗漏，不是用户用错了。
        // 说清楚是哪个协议缺哪个方法，免得排查时怀疑到调用方去
        throw new UnsupportedCapabilityException(sprintf(
            '协议 %s 声明支持「%s」但未实现响应解析，请在该协议类中补上 %s() 方法',
            $this->protocolLabel(),
            Capabilities::label($capability),
            $method
        ));
    }

    /**
     * 能力未被本协议声明时抛出，绝不静默返回空结果
     */
    protected function guardCapability(string $capability): void
    {
        if (in_array($capability, $this->capabilities(), true)) {
            return;
        }

        $supported = $this->capabilities();
        $hint = $supported
            ? '本协议目前支持：' . implode('、', array_map([Capabilities::class, 'label'], $supported))
            : '本协议目前只支持对话（chat），未实现任何扩展能力';

        throw new UnsupportedCapabilityException(sprintf(
            '协议 %s 不支持「%s」能力。%s',
            $this->protocolLabel(),
            Capabilities::label($capability),
            $hint
        ));
    }

    /**
     * 某项能力需要的额外请求头
     *
     * 少数平台的扩展能力要求对话接口没有的头，比如通义万相的异步视频生成
     * 必须带 X-DashScope-Async: enable，不带会退化成同步调用然后超时。
     *
     * 不改 buildHeaders()，是因为那条路是对话链路在走的，动它波及面太大。
     *
     * @return array<string, string>
     */
    public function capabilityHeaders(string $capability): array
    {
        return [];
    }

    /**
     * 能力标识 → 方法名词干
     *
     * 能力标识用下划线（image_edit），方法名用驼峰（imageEditPath），
     * 两种写法各自符合所在处的惯例，这里做一次转换。
     * 单段的标识（image / tts / asr）转换后不变。
     */
    protected function capabilityMethodBase(string $capability): string
    {
        if (strpos($capability, '_') === false) {
            return $capability;
        }
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $capability))));
    }

    /**
     * 把对话路径的动作后缀换成另一个动作，得到同级路径
     *
     * /v1/chat/completions      + 'embeddings'        → /v1/embeddings
     * /api/v3/chat/completions  + 'images/generations' → /api/v3/images/generations
     *
     * 各能力的路径都靠它从对话路径推出来，于是带前缀的网关、Azure、
     * 各家自定的版本号前缀全都自动正确，不必 40 个协议类各写一遍。
     *
     * @return string 对话路径不是标准 .../chat/completions 形态时推不出来，返回空串
     */
    protected function siblingCapabilityPath(string $action): string
    {
        if (!method_exists($this, 'chatPath')) {
            return '';
        }
        $chat   = $this->chatPath();
        $suffix = '/chat/completions';
        $len    = strlen($suffix);

        if (strlen($chat) < $len || substr($chat, -$len) !== $suffix) {
            return '';
        }
        return substr($chat, 0, -$len) . '/' . $action;
    }

    /**
     * 协议在错误信息里的显示名，优先用注册表里的短标识（如 qwen），拿不到就用类名
     */
    protected function protocolLabel(): string
    {
        $key = Protocols::keyOfClass(get_class($this));
        return $key !== null ? $key : get_class($this);
    }
}
