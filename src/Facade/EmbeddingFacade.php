<?php
namespace Ai\Facade;

use Ai\Exceptions\RequestException;
use Ai\Helpers\Capabilities;
use Ai\Response\EmbeddingResponse;

/**
 * 文本向量化
 *
 * ```php
 * $res = $ai->embeddings()->create(['第一段文本', '第二段文本']);
 * $res->getVector(0);      // [0.013, -0.221, ...]
 * $res->getDimensions();   // 1536
 * ```
 */
class EmbeddingFacade extends BaseFacade
{
    protected function capability(): string
    {
        return Capabilities::EMBEDDING;
    }

    /**
     * 向量化一段或多段文本
     *
     * 单条与批量走同一个方法：传字符串就是单条，传数组就是批量，
     * 返回的 EmbeddingResponse 都按数组组织，顺序与输入一致。
     *
     * @param string|array<int, string> $input   待向量化的文本
     * @param array<string, mixed>      $options 平台参数，如 ['dimensions' => 512]
     */
    public function create($input, array $options = []): EmbeddingResponse
    {
        $texts = is_array($input) ? array_values($input) : [$input];
        $texts = array_map('strval', $texts);

        if (!$texts || implode('', $texts) === '') {
            throw new RequestException('待向量化的文本为空', '', 'empty_input', []);
        }

        $payload = array_merge($options, [
            'model' => isset($options['model']) ? $options['model'] : $this->modelName(),
            'input' => $texts,
        ]);

        $response = $this->send($payload);
        if (!$response instanceof EmbeddingResponse) {
            throw new RequestException(
                '协议返回了非预期的响应类型：' . get_class($response),
                '',
                'unexpected_response_type',
                []
            );
        }
        return $response;
    }
}
