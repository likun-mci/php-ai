<?php
namespace Ai\Protocol;

/**
 * xAI Grok（OpenAI 兼容）
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'grok-4',
 *     'protocol' => 'grok',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://docs.x.ai/docs/api-reference
 */
class Grok extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.x.ai';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'grok-4'           => 'Grok 4',
            'grok-4-fast'      => 'Grok 4 Fast',
            'grok-3'           => 'Grok 3',
            'grok-3-mini'      => 'Grok 3 Mini',
            'grok-code-fast-1' => 'Grok Code Fast',
        ];
    }

    /**
     * xAI 已知的图像生成模型（据官方文档，2026-08）
     * @return array<int, string>
     */
    public function knownImageModels(): array
    {
        return [
            'grok-imagine-image-quality',
            'grok-imagine-image-2.0',
        ];
    }

    /**
     * xAI 的图像接口没有 size，改用 aspect_ratio + resolution
     *
     * 据官方文档（2026-08）：aspect_ratio 取 1:1 / 16:9 / 9:16 / 4:3 / 3:4 /
     * 3:2 / 2:3 / 2:1 / 1:2 / 19.5:9 / 9:19.5 / 20:9 / 9:20 / auto；
     * resolution 取 1k / 2k。
     *
     * 为了让调用方在各平台间用同一套写法，这里把库内统一的 size（如 "1024x1536"）
     * 换算成最接近的比例档；用户直接传 aspect_ratio 时以用户的为准，不覆盖。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function buildImageRequest(array $payload): array
    {
        if (isset($payload['size']) && is_string($payload['size']) && !isset($payload['aspect_ratio'])) {
            $wh = $this->parseSize($payload['size']);
            if ($wh !== null) {
                $payload['aspect_ratio'] = $this->nearestAspectRatio($wh[0], $wh[1]);
                if (!isset($payload['resolution'])) {
                    $payload['resolution'] = max($wh[0], $wh[1]) > 1536 ? '2k' : '1k';
                }
            }
        }
        unset($payload['size']);

        return $payload;
    }

    /**
     * 把宽高换算成 xAI 支持的最接近比例档
     */
    protected function nearestAspectRatio(int $width, int $height): string
    {
        if ($width <= 0 || $height <= 0) {
            return 'auto';
        }
        $target = $width / $height;

        $supported = [
            '1:1' => 1.0, '16:9' => 16 / 9, '9:16' => 9 / 16, '4:3' => 4 / 3, '3:4' => 3 / 4,
            '3:2' => 1.5, '2:3' => 2 / 3, '2:1' => 2.0, '1:2' => 0.5,
            '19.5:9' => 19.5 / 9, '9:19.5' => 9 / 19.5, '20:9' => 20 / 9, '9:20' => 9 / 20,
        ];

        $best = 'auto';
        $bestDiff = null;
        foreach ($supported as $label => $ratio) {
            $diff = abs($ratio - $target);
            if ($bestDiff === null || $diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $label;
            }
        }
        return $best;
    }
}
