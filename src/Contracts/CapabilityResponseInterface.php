<?php
namespace Ai\Contracts;

/**
 * 扩展能力的响应契约（图像 / 语音 / 视频 / 向量）
 *
 * 刻意**不继承 AIResponseInterface**。后者要求 getContent(): string、
 * getToolCalls(): array、toAssistantMessage(): array——这些对一张图片、
 * 一段音频毫无意义，硬塞只会造出一堆恒返回空值的假方法，
 * 让调用方以为「调了没报错就是有结果」。
 *
 * 这里只保留各能力真正共有的部分，具体取内容的方法由各响应类自己定义
 * （ImageResponse::getUrls()、AudioResponse::getBytes() ……）。
 */
interface CapabilityResponseInterface
{
    /**
     * 本响应对应的能力标识，取值见 \Ai\Helpers\Capabilities
     */
    public function getCapability(): string;

    /**
     * 平台返回的原始数据
     * @return array<string, mixed>
     */
    public function getRaw(): array;

    /**
     * 用量统计。多数图像/语音平台不返回用量，此时是空数组
     * @return array<string, mixed>
     */
    public function getUsage(): array;

    /**
     * 实际使用的模型
     */
    public function getModel(): string;

    /**
     * 是否成功
     */
    public function isSuccess(): bool;

    /**
     * 错误信息，成功时为空串
     */
    public function getError(): string;
}
