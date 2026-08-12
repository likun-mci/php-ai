<?php
namespace Ai\Protocol;

/**
 * Cohere（OpenAI 兼容）
 *
 * 走 Cohere 的 OpenAI 兼容端点（/compatibility/v1），原生 v2 接口未接入。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'command-a-03-2025',
 *     'protocol' => 'cohere',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://docs.cohere.com/docs/compatibility-api
 */
class Cohere extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.cohere.ai/compatibility';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'command-a-03-2025'      => 'Command A',
            'command-r-plus-08-2024' => 'Command R+',
            'command-r-08-2024'      => 'Command R',
            'command-r7b-12-2024'    => 'Command R7B',
        ];
    }

    /**
     * 本平台没有图像生成接口
     *
     * 据官方兼容层文档（2026-08-12）：只支持 chat/completions、embeddings、
     * audio/transcriptions 三个端点，**图像端点文档里明确写了不支持**。
     * 该平台网关是鉴权优先的（任何路径都回 401），探测给不出结论，以文档为准。
     */
    public function imagePath(): string
    {
        return '';
    }

    /**
     * 本平台没有图像编辑接口
     *
     * 据官方兼容层文档（2026-08-12）：只支持 chat/completions、embeddings、
     * audio/transcriptions 三个端点，**图像端点文档里明确写了不支持**。
     * 该平台网关是鉴权优先的（任何路径都回 401），探测给不出结论，以文档为准。
     */
    public function imageEditPath(): string
    {
        return '';
    }

    /**
     * 本平台没有语音合成接口
     *
     * 据官方兼容层文档（2026-08-12）：只支持 chat/completions、embeddings、
     * audio/transcriptions 三个端点，**图像端点文档里明确写了不支持**。
     * 该平台网关是鉴权优先的（任何路径都回 401），探测给不出结论，以文档为准。
     */
    public function ttsPath(): string
    {
        return '';
    }

    /**
     * Cohere 兼容层的向量化模型（据官方文档，2026-08-12）
     * @return array<int, string>
     */
    public function knownEmbeddingModels(): array
    {
        return ['embed-v4.0'];
    }

    /**
     * Cohere 兼容层的语音识别模型（据官方文档，2026-08-12）
     *
     * 注意：该接口**要求必须指定 language 参数**（与 OpenAI 不同），
     * 且不支持 MP4 / M4A / WEBM 等格式。
     *
     * @return array<int, string>
     */
    public function knownAsrModels(): array
    {
        return ['cohere-transcribe-03-2026'];
    }
}
