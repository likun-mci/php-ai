<?php
namespace Ai\Response;

use Ai\Contracts\CapabilityResponseInterface;
use Ai\Helpers\Capabilities;
use Ai\Response\Concerns\HasRawPayload;

/**
 * 文本向量化响应
 */
class EmbeddingResponse implements CapabilityResponseInterface, \Countable
{
    use HasRawPayload;

    /**
     * 向量列表，顺序与输入文本一一对应
     * @var array<int, array<int, float>>
     */
    protected $vectors = [];

    /**
     * @param array<int, array<int, float>> $vectors
     * @param array<string, mixed>          $raw
     * @param array<string, mixed>          $usage
     */
    public function __construct(array $vectors, array $raw = [], string $model = '', array $usage = [], string $error = '')
    {
        $this->vectors = array_values($vectors);
        $this->fillCommon($raw, $model, $usage, $error);
    }

    public function getCapability(): string
    {
        return Capabilities::EMBEDDING;
    }

    /**
     * 全部向量，顺序与输入一致
     * @return array<int, array<int, float>>
     */
    public function getVectors(): array
    {
        return $this->vectors;
    }

    /**
     * 取第 N 条向量，越界返回空数组
     * @return array<int, float>
     */
    public function getVector(int $index = 0): array
    {
        return isset($this->vectors[$index]) ? $this->vectors[$index] : [];
    }

    /**
     * 向量维度。取第一条的长度，无向量时返回 0
     */
    public function getDimensions(): int
    {
        return isset($this->vectors[0]) ? count($this->vectors[0]) : 0;
    }

    /**
     * 向量条数
     */
    public function count(): int
    {
        return count($this->vectors);
    }
}
