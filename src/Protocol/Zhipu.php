<?php
namespace Ai\Protocol;

/**
 * 智谱 GLM（OpenAI 兼容）
 *
 * 国际站（Z.ai）请改用 protocol=zai。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'glm-4.6',
 *     'protocol' => 'zhipu',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://docs.bigmodel.cn/
 */
class Zhipu extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://open.bigmodel.cn/api/paas';
    }

    /**
     * 协议对话路径
     */
    public function chatPath(): string
    {
        return '/v4/chat/completions';
    }

    /**
     * 协议模型列表路径
     */
    public function modelsPath(): string
    {
        return '/v4/models';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'glm-4.6'     => 'GLM-4.6',
            'glm-4.5'     => 'GLM-4.5',
            'glm-4.5-air' => 'GLM-4.5-Air',
            'glm-4-plus'  => 'GLM-4-Plus',
            'glm-4-flash' => 'GLM-4-Flash（免费）',
            'glm-4v-plus' => 'GLM-4V-Plus（视觉）',
        ];
    }

    /**
     * 智谱已知的图像生成模型（据官方文档，2026-08）
     *
     * 注意：智谱的图像接口**没有 n 参数**，一次只生成一张。
     * @return array<int, string>
     */
    public function knownImageModels(): array
    {
        return ['glm-image', 'cogview-4-250304', 'cogview-4', 'cogview-3-flash'];
    }

    /**
     * 智谱语音合成模型（据官方文档，2026-08）
     *
     * 音色复刻（glm-tts-clone）走的是另一个端点 /api/paas/v4/voice/clone，
     * 不在语音合成这条路径上，故不列入。
     *
     * @return array<int, string>
     */
    public function knownTtsModels(): array
    {
        return ['glm-tts'];
    }
}
