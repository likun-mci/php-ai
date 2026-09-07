<?php
namespace Ai\Agent\Capability;

use Ai\Helpers\MediaTranslator;

/**
 * 能力解析器 —— 回答「这个模型能不能吃图片」
 *
 * 三层来源合并，后者覆盖前者（设计文档 §12）：
 *
 * ```text
 * 协议家族推导（只用于 tool_result 这类**硬**协议约束）
 *        ↓
 * CapabilityRegistry（内置表 / 平台声明 / 用户 override）
 *        ↓
 * 调用方显式传入的 capabilities
 * ```
 *
 * 产出统一结构：
 *
 * ```php
 * ['input'       => ['text' => true, 'image' => true|false|null, 'pdf' => …],
 *  'tools'       => ['function_calling' => true|false|null],
 *  'tool_result' => ['image' => true|false]]
 * ```
 *
 * `null` = 不知道，`false` = 确定不支持。两者绝不能混（设计文档 §13）。
 *
 * 关于 `tool_result.image`：这一项**可以**按协议家族推导，因为它是硬性的
 * 接口约束而非模型能力——OpenAI 的 `role:tool` 消息 schema 只接受字符串
 * content，无论后面接的是哪个模型都塞不进图片；Anthropic 的 `tool_result`
 * 块则允许嵌图片。这与 §18 说的「不要按家族推断**模型**能力」不冲突。
 *
 * 注：不用类型化属性，保持 PHP 7.1 兼容（库的版本下限）。
 */
class CapabilityResolver
{
    /** 未知时保守：不主动往这个模型上路由 */
    const UNKNOWN_CONSERVATIVE = 'conservative';

    /** 未知时乐观：当作支持，发出去让平台自己说不行 */
    const UNKNOWN_OPTIMISTIC = 'optimistic';

    /** @var CapabilityRegistry */
    protected $registry;

    /** @var string 未知能力的处理策略 */
    protected $unknownPolicy = self::UNKNOWN_CONSERVATIVE;

    /**
     * @param CapabilityRegistry|null $registry
     * @param array<string, mixed> $options unknown_policy
     */
    public function __construct($registry = null, array $options = [])
    {
        $this->registry = $registry instanceof CapabilityRegistry ? $registry : new CapabilityRegistry();
        if (isset($options['unknown_policy'])) {
            $this->setUnknownPolicy($options['unknown_policy']);
        }
    }

    /** @return CapabilityRegistry */
    public function registry()
    {
        return $this->registry;
    }

    /**
     * @param string $policy
     * @return $this
     */
    public function setUnknownPolicy($policy)
    {
        $policy = (string) $policy;
        $this->unknownPolicy = $policy === self::UNKNOWN_OPTIMISTIC
            ? self::UNKNOWN_OPTIMISTIC
            : self::UNKNOWN_CONSERVATIVE;
        return $this;
    }

    /** @return string */
    public function unknownPolicy()
    {
        return $this->unknownPolicy;
    }

    /**
     * 解析一个模型的完整能力
     *
     * @param string $model 模型名
     * @param array<string, mixed> $options platform / family / capabilities
     * @return array<string, mixed>
     */
    public function resolve($model, array $options = [])
    {
        $platform = isset($options['platform']) ? (string) $options['platform'] : '';
        $family   = MediaTranslator::normalizeFamily(
            isset($options['family']) ? $options['family'] : MediaTranslator::FAMILY_OPENAI
        );

        // 底座：输入模态一律未知，tool_result 按协议家族的硬约束定
        $caps = [
            'input' => [
                Modalities::TEXT  => true,
                Modalities::IMAGE => null,
                Modalities::PDF   => null,
            ],
            'tools' => ['function_calling' => null],
            'tool_result' => [
                // OpenAI 的 role:tool 消息只接受字符串 content，塞不进图片；
                // Anthropic 的 tool_result 块可以嵌 image
                'image' => $family === MediaTranslator::FAMILY_ANTHROPIC,
            ],
        ];

        $found = $this->registry->lookup($model, $platform);
        if (is_array($found)) {
            $caps = CapabilityRegistry::mergeCaps($caps, $found);
        }

        if (isset($options['capabilities']) && is_array($options['capabilities'])) {
            $caps = CapabilityRegistry::mergeCaps($caps, $options['capabilities']);
        }

        return $caps;
    }

    /**
     * 某个模型支不支持某个输入模态
     *
     * @param string $model
     * @param string $modality
     * @param array<string, mixed> $options
     * @return bool|null true 支持 / false 不支持 / null 不知道
     */
    public function supports($model, $modality, array $options = [])
    {
        $caps = $this->resolve($model, $options);
        if (!isset($caps['input']) || !array_key_exists($modality, $caps['input'])) {
            return null;
        }
        $value = $caps['input'][$modality];
        return $value === null ? null : (bool) $value;
    }

    /**
     * 发请求时用的模态支持标志
     *
     * 与 `supports()` 的区别：这里必须给出**确定**的 true/false，
     * 因为 MediaTranslator 只能二选一。未知怎么处理由 `$optimisticOnUnknown` 决定：
     *
     * - `true`（当前模型）——照发不误。这保持了库既有的行为，而且失败会在
     *   平台侧明确报出来；谎称看不到反而会让模型编造内容。
     * - `false`（候选的视觉服务方）——不选它。把图片发给一个不确定能不能看图的
     *   模型，换回来的往往是难懂的 400。
     *
     * @param string $model
     * @param array<string, mixed> $options
     * @param bool $optimisticOnUnknown 未知时是否当作支持
     * @return array<string, bool>
     */
    public function supportFlags($model, array $options = [], $optimisticOnUnknown = true)
    {
        $caps = $this->resolve($model, $options);
        $out  = [];
        foreach (Modalities::rich() as $modality) {
            $value = isset($caps['input'][$modality]) ? $caps['input'][$modality] : null;
            if ($value === null) {
                $out[$modality] = (bool) $optimisticOnUnknown;
            } else {
                $out[$modality] = (bool) $value;
            }
        }
        return $out;
    }

    /**
     * 这个模型能不能作为某模态的**服务方**被路由过去
     *
     * 未知时按 `unknown_policy` 决定；默认保守，即不选它。
     *
     * @param string $model
     * @param string $modality
     * @param array<string, mixed> $options
     * @return bool
     */
    public function canServe($model, $modality, array $options = [])
    {
        $value = $this->supports($model, $modality, $options);
        if ($value === null) {
            return $this->unknownPolicy === self::UNKNOWN_OPTIMISTIC;
        }
        return $value;
    }

    /**
     * tool_result 里能不能直接带图片
     *
     * @param string $model
     * @param array<string, mixed> $options
     * @return bool
     */
    public function toolResultSupportsImage($model, array $options = [])
    {
        $caps = $this->resolve($model, $options);
        return !empty($caps['tool_result']['image']);
    }
}
