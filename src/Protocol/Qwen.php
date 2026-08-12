<?php
namespace Ai\Protocol;

/**
 * 阿里云百炼 / 通义千问（OpenAI 兼容）
 *
 * api_key 取百炼控制台的 sk-xxx，国际站请把 base_url 换成
 * https://dashscope-intl.aliyuncs.com/compatible-mode。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'qwen3-max',
 *     'protocol' => 'qwen',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://help.aliyun.com/zh/model-studio/compatibility-of-openai-with-dashscope
 */
class Qwen extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://dashscope.aliyuncs.com/compatible-mode';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'qwen3-max'   => '通义千问 3 Max',
            'qwen-max'    => '通义千问 Max',
            'qwen-plus'   => '通义千问 Plus',
            'qwen-turbo'  => '通义千问 Turbo',
            'qwen-long'   => '通义千问 Long（长文档）',
            'qwq-plus'    => 'QwQ Plus（推理）',
            'qwen-vl-max' => '通义千问 VL Max（视觉）',
        ];
    }

    /**
     * 通义不提供 OpenAI 兼容格式的同步文生图
     *
     * 实测 2026-08：POST {dashscope}/compatible-mode/v1/images/generations 返回 404，
     * 而同前缀下的假路径也返回 404（该网关是路由优先），可以确认此路由确实不存在。
     *
     * 通义万相走的是原生**异步任务**接口
     * （/api/v1/services/aigc/text2image/image-synthesis，提交后轮询 task_id），
     * 形态与同步接口完全不同，安排在异步任务那一期实现。
     *
     * 返回空串即表示本协议不声明图像能力，调用方会得到明确报错而不是 404。
     */
    public function imagePath(): string
    {
        return '';
    }

    /**
     * 通义兼容模式不提供语音合成
     *
     * 实测 2026-08：POST {dashscope}/compatible-mode/v1/audio/speech 返回 404，
     * 同前缀假路径同样 404（该网关路由优先），可确认此路由不存在。
     * 通义的语音走 DashScope 原生接口，形态与 OpenAI 差异很大，暂不接入。
     */
    public function ttsPath(): string
    {
        return '';
    }

    /**
     * 同上，兼容模式亦无语音识别接口
     */
    public function asrPath(): string
    {
        return '';
    }

    use \Ai\Protocol\Concerns\AsyncVideoTask;

    /**
     * 通义万相文生视频（异步任务式）
     *
     * 据官方文档（2026-08）：提交 POST {origin}/api/v1/services/aigc/video-generation/video-synthesis，
     * 必须带 X-DashScope-Async: enable，返回 output.task_id；
     * 查询 GET {origin}/api/v1/tasks/{task_id}，状态字段 task_status，
     * 取值 PENDING / RUNNING / SUCCEEDED / FAILED / CANCELED / UNKNOWN；
     * 成功时视频地址在 output.video_url（有效期 24 小时）。
     */
    public function videoPath(): string
    {
        return '/api/v1/services/aigc/video-generation/video-synthesis';
    }

    /**
     * 通义已登记的视频生成模型（据官方文档，2026-08）
     * @return array<int, string>
     */
    public function knownVideoModels(): array
    {
        return ['wan2.7-t2v', 'wan2.7-t2v-2026-06-12'];
    }

    /**
     * 万相的地址不在对话路径前缀之下
     *
     * 对话在 {origin}/compatible-mode/v1/chat/completions，
     * 视频在 {origin}/api/v1/services/...，无论怎么剥对话路径都推不出来，
     * 所以这里直接给出完整地址——但仍从**实际对话端点**取 origin，
     * 保证用户配了自建网关时视频请求也走同一个网关。
     *
     * @param array<string, mixed> $config
     */
    public function capabilityEndpoint(string $capability, string $chatEndpoint, array $config): string
    {
        if ($capability !== \Ai\Helpers\Capabilities::VIDEO) {
            return '';
        }
        // 剥掉「/compatible-mode + 对话路径」拿到根地址，再接视频路径。
        // 只取 scheme+host 会丢掉网关的路径前缀（如 https://gw.internal/ds 里的 /ds）
        $suffix = '/compatible-mode' . $this->chatPath();
        if (substr($chatEndpoint, -strlen($suffix)) === $suffix) {
            return substr($chatEndpoint, 0, -strlen($suffix)) . $this->videoPath();
        }

        $origin = $this->taskOrigin($chatEndpoint);
        return $origin === '' ? '' : $origin . $this->videoPath();
    }

    /**
     * 异步视频必须带这个头，不带会退化成同步调用然后超时
     *
     * @return array<string, string>
     */
    public function capabilityHeaders(string $capability): array
    {
        if ($capability === \Ai\Helpers\Capabilities::VIDEO) {
            return ['X-DashScope-Async' => 'enable'];
        }
        return [];
    }

    /**
     * 万相的请求体是 input / parameters 两段式，不是平铺的
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function buildVideoRequest(array $payload): array
    {
        $input = ['prompt' => isset($payload['prompt']) ? $payload['prompt'] : ''];
        foreach (['negative_prompt', 'audio_url', 'img_url', 'image_url'] as $key) {
            if (!empty($payload[$key])) {
                $input[$key === 'image_url' ? 'img_url' : $key] = $payload[$key];
            }
        }

        $parameters = [];
        foreach (['resolution', 'ratio', 'duration', 'prompt_extend', 'watermark', 'seed'] as $key) {
            if (isset($payload[$key])) {
                $parameters[$key] = $payload[$key];
            }
        }
        // 库内统一写 size（如 1280x720），万相用 resolution 档位
        if (isset($payload['size']) && !isset($parameters['resolution'])) {
            $wh = $this->parseSize((string) $payload['size']);
            if ($wh !== null) {
                $parameters['resolution'] = max($wh[0], $wh[1]) >= 1920 ? '1080P' : '720P';
            }
        }

        $request = ['model' => isset($payload['model']) ? $payload['model'] : '', 'input' => $input];
        if ($parameters) {
            $request['parameters'] = $parameters;
        }
        return $request;
    }

    /**
     * @param array<string, mixed> $response
     * @return array{id: string, query_url: string}
     */
    public function parseTaskSubmit(string $capability, array $response, string $submitUrl = ''): array
    {
        $id = (string) $this->dig($response, 'output.task_id');

        return [
            'id'        => $id,
            'query_url' => ($id !== '' && $submitUrl !== '')
                ? $this->taskSiblingUrl($submitUrl, $this->videoPath(), '/api/v1/tasks/' . rawurlencode($id))
                : '',
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array{status: string, error: string, result: \Ai\Contracts\CapabilityResponseInterface|null}
     */
    public function parseTaskStatus(string $capability, array $response): array
    {
        $status = (string) $this->dig($response, 'output.task_status');
        $result = null;
        $error  = '';

        if (strtoupper($status) === 'SUCCEEDED') {
            $url = (string) $this->dig($response, 'output.video_url');
            $result = new \Ai\Response\VideoResponse(
                $url,
                '',
                0.0,
                $response,
                isset($response['model']) ? (string) $response['model'] : '',
                isset($response['usage']) && is_array($response['usage']) ? $response['usage'] : [],
                $url === '' ? '任务成功但响应里没有 output.video_url' : ''
            );
        } elseif (in_array(strtoupper($status), ['FAILED', 'CANCELED'], true)) {
            $error = $this->taskError($response);
            if ($error === '') {
                $error = (string) $this->dig($response, 'output.message');
            }
        }

        return ['status' => $status, 'error' => $error, 'result' => $result];
    }
}
