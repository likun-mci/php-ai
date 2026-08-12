<?php
namespace Ai\Protocol;

/**
 * 硅基流动 SiliconCloud（聚合，OpenAI 兼容）
 *
 * 国内可直连的开源模型聚合平台，model 用「组织/模型」格式。
 * 海外站点请把 base_url 换成 https://api.siliconflow.com。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'deepseek-ai/DeepSeek-V3.2-Exp',
 *     'protocol' => 'siliconflow',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://docs.siliconflow.cn/cn/api-reference/chat-completions/chat-completions
 */
class SiliconFlow extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.siliconflow.cn';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'deepseek-ai/DeepSeek-V3.2-Exp' => 'DeepSeek V3.2',
            'deepseek-ai/DeepSeek-R1'       => 'DeepSeek R1',
            'Qwen/Qwen3-235B-A22B'          => 'Qwen3 235B',
            'moonshotai/Kimi-K2-Instruct'   => 'Kimi K2',
            'zai-org/GLM-4.6'               => 'GLM-4.6',
        ];
    }

    /**
     * 硅基流动已知的图像生成模型（据官方文档，2026-08）
     * @return array<int, string>
     */
    public function knownImageModels(): array
    {
        return [
            'Kwai-Kolors/Kolors',
            'Qwen/Qwen-Image-Edit',
            'Qwen/Qwen-Image-Edit-2509',
        ];
    }

    /**
     * 硅基流动的图像参数名与 OpenAI 不同
     *
     * 据官方文档（2026-08）：尺寸叫 image_size，张数叫 batch_size（仅 Kolors 支持，1-4），
     * 另有 negative_prompt / seed / num_inference_steps / guidance_scale。
     *
     * 这里把库内统一的 size / n 映射过去，让调用方在各平台间用同一套写法；
     * 平台私有参数（seed 等）原样透传，不做映射。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function buildImageRequest(array $payload): array
    {
        if (isset($payload['size']) && !isset($payload['image_size'])) {
            $payload['image_size'] = $payload['size'];
        }
        unset($payload['size']);

        if (isset($payload['n']) && !isset($payload['batch_size'])) {
            $payload['batch_size'] = (int) $payload['n'];
        }
        unset($payload['n']);

        // 该平台不认这个参数，留着会被判为非法字段
        unset($payload['response_format']);

        return $payload;
    }

    /**
     * 硅基流动语音合成模型（据官方文档，2026-08）
     * @return array<int, string>
     */
    public function knownTtsModels(): array
    {
        return ['FunAudioLLM/CosyVoice2-0.5B', 'fnlp/MOSS-TTSD-v0.5'];
    }

    /**
     * 硅基流动音色（据官方文档，2026-08）
     *
     * 实际调用时通常要写成「模型:音色」的完整形式，如
     * FunAudioLLM/CosyVoice2-0.5B:alex，这里只列音色名本身。
     *
     * @return array<int, string>
     */
    public function knownVoices(): array
    {
        return ['alex', 'anna', 'bella', 'benjamin', 'charles', 'claire', 'david', 'diana'];
    }
}
