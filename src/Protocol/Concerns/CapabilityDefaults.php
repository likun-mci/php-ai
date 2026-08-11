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
     * 用方法而不是属性来承载，是因为 trait 里的属性一旦被使用它的类
     * 以不同初始值重新声明，PHP 会直接抛致命错误；方法则可以自由覆写。
     *
     * @return array<string, string>
     */
    public function capabilityPathMap(): array
    {
        return [];
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
        return $payload;
    }

    /**
     * @param array<string, mixed> $response
     */
    public function parseCapabilityResponse(string $capability, array $response): CapabilityResponseInterface
    {
        $this->guardCapability($capability);

        // 能声明支持、却没实现解析，属于库自身的实现遗漏，不是用户用错了。
        // 说清楚是哪个协议缺哪个方法，免得排查时怀疑到调用方去
        throw new UnsupportedCapabilityException(sprintf(
            '协议 %s 声明支持「%s」但未实现响应解析，请在该协议类中覆写 parseCapabilityResponse()',
            $this->protocolLabel(),
            Capabilities::label($capability)
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
     * 协议在错误信息里的显示名，优先用注册表里的短标识（如 qwen），拿不到就用类名
     */
    protected function protocolLabel(): string
    {
        $key = Protocols::keyOfClass(get_class($this));
        return $key !== null ? $key : get_class($this);
    }
}
