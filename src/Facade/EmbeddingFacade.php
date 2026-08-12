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
 * count($res);             // 2
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
     * 单条与批量走同一个方法：传字符串是单条，传数组是批量，
     * 返回的向量顺序**始终**与输入顺序一致。
     *
     * 文本条数超过单次上限时会自动分批发送再合并，对调用方透明。
     * 上限取值优先级：`$options['batch_size']` > 协议声明 > 不分批。
     *
     * @param string|array<int, string> $input   待向量化的文本
     * @param array<string, mixed>      $options 平台参数，另支持 batch_size 控制分批
     */
    public function create($input, array $options = []): EmbeddingResponse
    {
        $texts = is_array($input) ? array_values($input) : [$input];
        $texts = array_map('strval', $texts);

        if (!$texts || implode('', $texts) === '') {
            throw new RequestException('待向量化的文本为空', '', 'empty_input', []);
        }

        $batchSize = $this->resolveBatchSize($options);
        unset($options['batch_size']);

        if ($batchSize <= 0 || count($texts) <= $batchSize) {
            return $this->createOnce($texts, $options);
        }

        return $this->createBatched(array_chunk($texts, $batchSize), $options);
    }

    /**
     * 单次请求可提交的文本条数上限，0 表示不分批
     *
     * @param array<string, mixed> $options
     */
    protected function resolveBatchSize(array $options): int
    {
        if (isset($options['batch_size'])) {
            return (int) $options['batch_size'];
        }

        $protocol = $this->protocol();
        if (method_exists($protocol, 'embeddingBatchLimit')) {
            return (int) $protocol->embeddingBatchLimit();
        }
        return 0;
    }

    /**
     * 发一次请求
     *
     * @param array<int, string>   $texts
     * @param array<string, mixed> $options
     */
    protected function createOnce(array $texts, array $options): EmbeddingResponse
    {
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

    /**
     * 分批发送并合并
     *
     * 合并有两处必须做对：
     *   1) 向量按批次顺序首尾相接，**不能乱序**——错位不会报错，
     *      只会让后续检索莫名其妙地不准
     *   2) 各批的 usage 逐字段累加，否则用量统计只剩最后一批的数字
     *
     * @param array<int, array<int, string>> $chunks
     * @param array<string, mixed>           $options
     */
    protected function createBatched(array $chunks, array $options): EmbeddingResponse
    {
        $vectors = [];
        $usage   = [];
        $model   = '';
        $raws    = [];

        foreach ($chunks as $i => $chunk) {
            $res = $this->createOnce($chunk, $options);

            if (!$res->isSuccess()) {
                throw new RequestException(
                    sprintf('第 %d/%d 批向量化失败：%s', $i + 1, count($chunks), $res->getError()),
                    '',
                    'embedding_batch_failed',
                    $res->getRaw()
                );
            }

            $got = $res->getVectors();
            if (count($got) !== count($chunk)) {
                // 少了几条却不报错，是最危险的情况：向量数与文本数对不上，
                // 后面所有下标都会错位。宁可当场失败
                throw new RequestException(
                    sprintf(
                        '第 %d/%d 批返回的向量数（%d）与提交的文本数（%d）不一致，已中止以免向量与原文错位',
                        $i + 1,
                        count($chunks),
                        count($got),
                        count($chunk)
                    ),
                    '',
                    'embedding_count_mismatch',
                    $res->getRaw()
                );
            }

            foreach ($got as $vector) {
                $vectors[] = $vector;
            }
            $usage = $this->mergeUsage($usage, $res->getUsage());
            $model = $model !== '' ? $model : $res->getModel();
            $raws[] = $res->getRaw();
        }

        return new EmbeddingResponse(
            $vectors,
            ['batched' => true, 'batches' => count($chunks), 'responses' => $raws],
            $model,
            $usage
        );
    }

    /**
     * 逐字段累加用量，非数值字段以先到的为准
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $add
     * @return array<string, mixed>
     */
    protected function mergeUsage(array $base, array $add): array
    {
        foreach ($add as $key => $value) {
            if (is_numeric($value)) {
                $base[$key] = (isset($base[$key]) && is_numeric($base[$key]) ? $base[$key] : 0) + $value;
            } elseif (!isset($base[$key])) {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
