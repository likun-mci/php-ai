<?php
namespace Ai\Protocol;

/**
 * Z.ai（智谱国际站，OpenAI 兼容）
 *
 * 智谱面向海外的站点，模型标识与国内站一致，Key 不通用。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'glm-4.6',
 *     'protocol' => 'zai',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://docs.z.ai/
 */
class ZAI extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.z.ai/api/paas';
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
        ];
    }

    /**
     * 本平台没有图像编辑接口（实测 404）
     *
     * 探测方法：带 Authorization 头 POST 真实路径与同前缀假路径作对照；
     * 该平台假路径返回 404（路由优先），故结果可判定。2026-08-12 实测。
     *
     * 此前是从 OpenAI 基线继承来的声明，与事实不符——业务层用
     * capabilities() 渲染功能开关时会点亮一个点下去就 404 的按钮。
     */
    public function imageEditPath(): string
    {
        return '';
    }

    /**
     * 本平台没有语音合成接口（实测 404）
     *
     * 探测方法：带 Authorization 头 POST 真实路径与同前缀假路径作对照；
     * 该平台假路径返回 404（路由优先），故结果可判定。2026-08-12 实测。
     *
     * 此前是从 OpenAI 基线继承来的声明，与事实不符——业务层用
     * capabilities() 渲染功能开关时会点亮一个点下去就 404 的按钮。
     */
    public function ttsPath(): string
    {
        return '';
    }

    /**
     * 本平台没有语音识别接口（实测 404）
     *
     * 探测方法：带 Authorization 头 POST 真实路径与同前缀假路径作对照；
     * 该平台假路径返回 404（路由优先），故结果可判定。2026-08-12 实测。
     *
     * 此前是从 OpenAI 基线继承来的声明，与事实不符——业务层用
     * capabilities() 渲染功能开关时会点亮一个点下去就 404 的按钮。
     */
    public function asrPath(): string
    {
        return '';
    }

    use \Ai\Protocol\Concerns\AsyncVideoTask;

    /**
     * 智谱国际版（z.ai）的视频生成
     *
     * 官方文档列出平台提供图像生成与视频生成能力，接口结构与国内版智谱同构
     * （同为 /api/paas/v4 前缀）。z.ai 网关是鉴权优先的，探测给不出结论，
     * 因此以文档为准。
     */
    public function videoPath(): string
    {
        return '/v4/videos/generations';
    }

    /**
     * @param array<string, mixed> $response
     * @return array{id: string, query_url: string}
     */
    public function parseTaskSubmit(string $capability, array $response, string $submitUrl = ''): array
    {
        $id = isset($response['id']) ? (string) $response['id'] : '';
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
    public function parseTaskStatus(string $capability, array $response, string $queryUrl = ''): array
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
                [],
                $url === '' ? '任务成功但 video_result 里没有 url' : ''
            );
        } elseif (strtoupper($status) === 'FAIL') {
            $error = $this->taskError($response);
        }

        return ['status' => $status, 'error' => $error, 'result' => $result];
    }
}
