<?php
namespace Ai\Protocol\Concerns;

use Ai\Response\ImageResponse;

/**
 * OpenAI 兼容格式的图像生成
 *
 * 与 embeddings 不同，各家的图像接口**并没有那么统一**——虽然路径都叫
 * /v1/images/generations，字段却各有出入（实测自各家官方文档，2026-08）：
 *
 *   OpenAI     data[].url / data[].b64_json / revised_prompt
 *              GPT 图像模型（gpt-image-*）不支持 url，只会返回 b64_json
 *   智谱       data[].url，且**没有 n 参数**
 *   xAI        没有 size，用 aspect_ratio + resolution
 *   硅基流动   响应是 images[] 而不是 data[]；请求用 image_size / batch_size；
 *              返回的 URL **只有 1 小时有效期**
 *   豆包       response_format 取值是 "base64" 而不是 "b64_json"；size 可为 "2K"
 *
 * 本 trait 实现 OpenAI 那一套作为基线，偏差由各协议类覆写
 * buildImageRequest() 处理；响应解析则做成**兼容多种形态**，
 * 因为字段名分歧主要在少数几处，逐个平台写一份解析器不划算。
 */
trait OpenAiImages
{
    /**
     * 图像生成接口路径
     */
    public function imagePath(): string
    {
        return $this->siblingCapabilityPath('images/generations');
    }

    /**
     * 本协议已登记的图像生成模型
     *
     * 供后台下拉框离线渲染。清单一律以**各平台官方文档**为准，
     * 不靠端点探测推断——探测只能证明路由在不在，证明不了有哪些模型。
     * 未登记时返回空数组，调用方应回退到平台自己的模型列表接口。
     *
     * @return array<int, string>
     */
    public function knownImageModels(): array
    {
        return [];
    }

    /**
     * 构建图像生成请求。基线是 OpenAI 形态，原样透传
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function buildImageRequest(array $payload): array
    {
        return $payload;
    }

    /**
     * 解析图像生成响应
     *
     * 兼容三种容器形态：data[]（OpenAI 及多数平台）、images[]（硅基流动）、
     * output.results[]（部分异步平台的同步回执）。
     *
     * @param array<string, mixed> $response
     */
    public function parseImageResponse(array $response): ImageResponse
    {
        $items = $this->extractImageItems($response);

        $urls   = [];
        $base64 = [];
        $revised = '';

        foreach ($items as $item) {
            // 元素直接是字符串的情况：可能是 URL，也可能是裸 base64
            if (is_string($item)) {
                if ($this->looksLikeUrl($item)) {
                    $urls[] = $item;
                } elseif ($item !== '') {
                    $base64[] = $item;
                }
                continue;
            }
            if (!is_array($item)) {
                continue;
            }

            $url = '';
            foreach (['url', 'image_url', 'image'] as $k) {
                if (!empty($item[$k]) && is_string($item[$k]) && $this->looksLikeUrl($item[$k])) {
                    $url = $item[$k];
                    break;
                }
            }

            $b64 = '';
            foreach (['b64_json', 'base64', 'image_base64', 'image'] as $k) {
                if (!empty($item[$k]) && is_string($item[$k]) && !$this->looksLikeUrl($item[$k])) {
                    $b64 = $this->stripDataUri($item[$k]);
                    break;
                }
            }

            if ($url !== '') {
                $urls[] = $url;
            }
            if ($b64 !== '') {
                $base64[] = $b64;
            }
            if ($revised === '' && !empty($item['revised_prompt']) && is_string($item['revised_prompt'])) {
                $revised = $item['revised_prompt'];
            }
        }

        $error = '';
        if (isset($response['error'])) {
            if (is_array($response['error'])) {
                $error = isset($response['error']['message'])
                    ? (string) $response['error']['message']
                    : (string) json_encode($response['error'], JSON_UNESCAPED_UNICODE);
            } else {
                $error = (string) $response['error'];
            }
        } elseif (!$urls && !$base64) {
            // 没报错也没图，属于「平台改了字段名」这类情况。
            // 静默返回空响应会让调用方以为生成失败，实则是解析没跟上，
            // 说清楚才好排查
            $error = '响应中没有解析到任何图像。平台返回的字段可能与预期不同，原始响应见 getRaw()';
        }

        return new ImageResponse(
            $urls,
            $base64,
            $response,
            isset($response['model']) ? (string) $response['model'] : '',
            isset($response['usage']) && is_array($response['usage']) ? $response['usage'] : [],
            $revised,
            $error
        );
    }

    /**
     * 从响应里取出图像条目数组
     *
     * @param array<string, mixed> $response
     * @return array<int, mixed>
     */
    protected function extractImageItems(array $response): array
    {
        foreach (['data', 'images'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return array_values($response[$key]);
            }
        }
        // 异步平台的同步回执：output.results[]
        if (isset($response['output']['results']) && is_array($response['output']['results'])) {
            return array_values($response['output']['results']);
        }
        return [];
    }

    /**
     * 粗判是不是 URL。base64 串很长且不含 :// ，两者不会混淆
     */
    protected function looksLikeUrl(string $value): bool
    {
        return strpos($value, 'http://') === 0 || strpos($value, 'https://') === 0;
    }

    /**
     * 去掉 data:image/png;base64, 前缀，只留纯 base64
     */
    protected function stripDataUri(string $value): string
    {
        if (strpos($value, 'data:') === 0) {
            $pos = strpos($value, ',');
            if ($pos !== false) {
                return substr($value, $pos + 1);
            }
        }
        return $value;
    }

    /**
     * 把 "1024x768" 解析成 [宽, 高]，解析不了返回 null
     *
     * @return array{0: int, 1: int}|null
     */
    protected function parseSize(string $size): ?array
    {
        if (preg_match('/^\s*(\d+)\s*[x×]\s*(\d+)\s*$/i', $size, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        return null;
    }
}
