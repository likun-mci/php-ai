<?php
namespace Ai\Protocol\Concerns;

use Ai\Helpers\Log;
use Ai\Response\EmbeddingResponse;

/**
 * OpenAI 兼容格式的文本向量化
 *
 * 绝大多数平台的 embeddings 接口都照抄 OpenAI：
 * 请求 `{model, input}`，响应 `{data: [{index, embedding}], usage}`。
 * 差别只在**路径前缀**，而前缀可以从各协议自己的 chatPath() 推出来，
 * 不需要 40 个协议类各写一遍。
 *
 * 平台字段名确有出入时（比如把 dimensions 叫成别的），
 * 在该协议类里覆写 buildEmbeddingRequest() 即可，不必改本 trait。
 */
trait OpenAiEmbeddings
{
    /**
     * 向量化接口路径
     *
     * 由对话路径同位推导：/v1/chat/completions → /v1/embeddings，
     * /api/v3/chat/completions → /api/v3/embeddings。
     * 这样带前缀的网关、Azure、Gemini 兼容端点全都自动正确。
     *
     * 对话路径不是标准 `.../chat/completions` 形态的协议（如 MiniMax）
     * 推不出来，返回空串表示不支持，需要该协议自行覆写。
     */
    public function embeddingPath(): string
    {
        return $this->siblingCapabilityPath('embeddings');
    }

    /**
     * 本协议已登记的向量化模型（据官方文档）
     *
     * 审计时发现的空白：向量能力从 v1.15.0 起就支持 35 个平台，
     * 却一个模型清单都没有——用户在后台下拉框里选不到，只能手打模型名。
     *
     * @return array<int, string>
     */
    public function knownEmbeddingModels(): array
    {
        return [];
    }

    /**
     * 单次请求可提交的最大文本条数，0 表示不限制、不分批
     *
     * 各平台上限差异很大（OpenAI 上千条，部分国产平台只有一二十条），
     * 且官方文档未必写明。这里默认 0（不分批），需要时由调用方用
     * `['batch_size' => 25]` 指定，或由具体协议覆写本方法。
     *
     * 不设一个「保险的小值」当默认，是因为那会让 OpenAI 这类本可一次发完的平台
     * 白白多发几十个请求，慢且费钱。
     */
    public function embeddingBatchLimit(): int
    {
        return 0;
    }

    /**
     * 构建向量化请求
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function buildEmbeddingRequest(array $payload): array
    {
        $request = $payload;

        // 库内部使用的控制项不能发给平台
        unset($request['batch_size']);

        // 单条输入时还原成字符串：部分平台对数组形式的 input 支持不完整，
        // 而单条传字符串是所有平台都认的写法
        if (isset($request['input']) && is_array($request['input']) && count($request['input']) === 1) {
            $request['input'] = reset($request['input']);
        }

        return $request;
    }

    /**
     * 解析向量化响应
     *
     * @param array<string, mixed> $response
     */
    public function parseEmbeddingResponse(array $response): EmbeddingResponse
    {
        $model = isset($response['model']) ? (string) $response['model'] : '';
        $usage = isset($response['usage']) && is_array($response['usage']) ? $response['usage'] : [];

        // HTTP 200 但体内带 error 的情况（部分平台如此报错）
        $error = '';
        if (isset($response['error'])) {
            if (is_array($response['error'])) {
                $error = isset($response['error']['message'])
                    ? (string) $response['error']['message']
                    : (string) json_encode($response['error'], JSON_UNESCAPED_UNICODE);
            } else {
                $error = (string) $response['error'];
            }
        }

        $items = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];

        $vectors = [];
        foreach ($items as $pos => $item) {
            if (!is_array($item) || !isset($item['embedding'])) {
                continue;
            }
            // 按平台给出的 index 归位。OpenAI 明确说明返回顺序不保证与输入一致，
            // 直接按数组顺序收会导致向量与原文错位——这种错位不会报错，
            // 只会让检索结果莫名其妙地不准，极难排查
            $index = isset($item['index']) ? (int) $item['index'] : (int) $pos;
            $vectors[$index] = $this->decodeEmbedding($item['embedding']);
        }
        ksort($vectors);

        return new EmbeddingResponse(array_values($vectors), $response, $model, $usage, $error);
    }

    /**
     * 把一条 embedding 转成 float 数组
     *
     * 正常是数字数组；请求时指定 encoding_format=base64 的话平台会返回
     * base64 编码的 float32 紧凑串，这里一并解开，免得用户拿到一串乱码字符
     *
     * @param mixed $embedding
     * @return array<int, float>
     */
    protected function decodeEmbedding($embedding): array
    {
        if (is_array($embedding)) {
            return array_map('floatval', array_values($embedding));
        }

        if (is_string($embedding) && $embedding !== '') {
            $bytes = base64_decode($embedding, true);
            if ($bytes !== false && strlen($bytes) % 4 === 0) {
                // g = 32 位小端浮点，OpenAI base64 格式即为此。
                // 该格式符是 PHP 7.0.15 / 7.1.1 才加入的，恰好卡在本库支持的
                // 7.1 下限上：7.1.0 会告警并返回 false，整条向量静默变成空数组。
                // 退回 f（机器字节序浮点，各版本都有）——本库支持的平台
                // （x86 / ARM）全是小端，两者逐字节等价。
                $floats = @unpack('g*', $bytes);
                if ($floats === false) {
                    $floats = unpack('f*', $bytes);
                }
                if (is_array($floats)) {
                    return array_map('floatval', array_values($floats));
                }
            }
            Log::warning('无法解析 base64 编码的向量，已跳过该条', ['length' => strlen($embedding)]);
        }

        return [];
    }
}
