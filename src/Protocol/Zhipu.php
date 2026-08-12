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

    use \Ai\Protocol\Concerns\AsyncVideoTask;

    /**
     * 智谱视频生成（异步任务式）
     *
     * 据官方文档（2026-08）：提交 POST {origin}/api/paas/v4/videos/generations，
     * 返回 {id, task_status}；查询 GET {origin}/api/paas/v4/async-result/{id}，
     * task_status 取值 PROCESSING / SUCCESS / FAIL；
     * 成功时视频在 video_result[].url，封面在 video_result[].cover_image_url。
     */
    public function videoPath(): string
    {
        return '/v4/videos/generations';
    }

    /**
     * 智谱已登记的视频生成模型（据官方文档，2026-08）
     * @return array<int, string>
     */
    public function knownVideoModels(): array
    {
        return [
            'cogvideox-3', 'cogvideox-2', 'cogvideox-flash',
            'viduq1-text', 'viduq1-image', 'viduq1-start-end',
            'vidu2-image', 'vidu2-start-end', 'vidu2-reference',
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array{id: string, query_url: string}
     */
    public function parseTaskSubmit(string $capability, array $response, string $submitUrl = ''): array
    {
        $id = isset($response['id']) ? (string) $response['id'] : '';

        // 查询走 /async-result/{id}，与提交同前缀，把最后两段换掉
        $queryUrl = '';
        if ($id !== '' && $submitUrl !== '') {
            $base = preg_replace('#/videos/generations/?$#', '', $submitUrl);
            $queryUrl = rtrim((string) $base, '/') . '/async-result/' . rawurlencode($id);
        }

        return ['id' => $id, 'query_url' => $queryUrl];
    }

    /**
     * @param array<string, mixed> $response
     * @return array{status: string, error: string, result: \Ai\Contracts\CapabilityResponseInterface|null}
     */
    public function parseTaskStatus(string $capability, array $response): array
    {
        $status = isset($response['task_status']) ? (string) $response['task_status'] : '';
        $result = null;
        $error  = '';

        if (strtoupper($status) === 'SUCCESS') {
            $first = isset($response['video_result'][0]) && is_array($response['video_result'][0])
                ? $response['video_result'][0]
                : [];
            $url = isset($first['url']) ? (string) $first['url'] : '';
            $result = new \Ai\Response\VideoResponse(
                $url,
                isset($first['cover_image_url']) ? (string) $first['cover_image_url'] : '',
                0.0,
                $response,
                isset($response['model']) ? (string) $response['model'] : '',
                isset($response['usage']) && is_array($response['usage']) ? $response['usage'] : [],
                $url === '' ? '任务成功但 video_result 里没有 url' : ''
            );
        } elseif (strtoupper($status) === 'FAIL') {
            $error = $this->taskError($response);
        }

        return ['status' => $status, 'error' => $error, 'result' => $result];
    }

    /**
     * 智谱语音识别模型（据官方文档，2026-08-12）
     *
     * 端点 /api/paas/v4/audio/transcriptions，multipart 上传，
     * 音频限 .wav/.mp3、≤25MB、≤30 秒；响应 {id, created, request_id, model, text}。
     * 另支持 hotwords 热词表（最多 100 项）提升特定领域识别率。
     *
     * @return array<int, string>
     */
    public function knownAsrModels(): array
    {
        return ['glm-asr-2512'];
    }
}
