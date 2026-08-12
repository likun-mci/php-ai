<?php
namespace Ai\Protocol;

/**
 * 火山方舟 / 豆包（OpenAI 兼容）
 *
 * model 可传模型名（doubao-seed-1-6），也可传方舟推理接入点 ID（ep-xxxxxx）。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'doubao-seed-1-6',
 *     'protocol' => 'doubao',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://www.volcengine.com/docs/82379/1330626
 */
class Doubao extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://ark.cn-beijing.volces.com';
    }

    /**
     * 协议对话路径
     */
    public function chatPath(): string
    {
        return '/api/v3/chat/completions';
    }

    /**
     * 协议模型列表路径
     */
    public function modelsPath(): string
    {
        return '/api/v3/models';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'doubao-seed-1-6'          => '豆包 Seed 1.6',
            'doubao-seed-1-6-flash'    => '豆包 Seed 1.6 Flash',
            'doubao-seed-1-6-thinking' => '豆包 Seed 1.6 Thinking',
            'doubao-1-5-pro-32k'       => '豆包 1.5 Pro 32K',
            'doubao-1-5-lite-32k'      => '豆包 1.5 Lite 32K',
        ];
    }

    /**
     * 方舟的 response_format 取值是 "base64"，不是 OpenAI 的 "b64_json"
     *
     * 据官方文档（2026-08）：response_format 取 url / base64，另有
     * watermark（是否加水印）、size（可为 "2K" 这类档位而非 WxH）等参数。
     * 传错取值不会被忽略，会直接判为非法参数。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function buildImageRequest(array $payload): array
    {
        if (isset($payload['response_format']) && $payload['response_format'] === 'b64_json') {
            $payload['response_format'] = 'base64';
        }
        return $payload;
    }

    use \Ai\Protocol\Concerns\AsyncVideoTask;

    /**
     * 火山方舟视频生成（异步任务式）
     *
     * 据官方文档（2026-08）：提交 POST {origin}/api/v3/contents/generations/tasks，
     * 返回 {id}；查询 GET {origin}/api/v3/contents/generations/tasks/{id}，
     * 状态字段 status（succeeded / failed 等）；
     * 成功时视频在 content.video_url，用量在 usage。
     */
    public function videoPath(): string
    {
        return '/api/v3/contents/generations/tasks';
    }

    /**
     * 方舟的请求体用 content 数组表达多模态输入
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function buildVideoRequest(array $payload): array
    {
        if (isset($payload['content'])) {
            return $payload;              // 调用方已按方舟结构写好，不动
        }

        $content = [];
        if (!empty($payload['prompt'])) {
            $content[] = ['type' => 'text', 'text' => (string) $payload['prompt']];
        }
        // 图生视频：首帧图
        foreach (['image_url', 'image', 'first_frame_image'] as $key) {
            if (!empty($payload[$key]) && is_string($payload[$key])) {
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => $payload[$key]]];
                break;
            }
        }

        $request = ['model' => isset($payload['model']) ? $payload['model'] : '', 'content' => $content];
        foreach (['ratio', 'duration', 'resolution', 'watermark', 'seed', 'camerafixed'] as $key) {
            if (isset($payload[$key])) {
                $request[$key] = $payload[$key];
            }
        }
        return $request;
    }

    /**
     * @param array<string, mixed> $response
     * @return array{id: string, query_url: string}
     */
    public function parseTaskSubmit(string $capability, array $response, string $submitUrl = ''): array
    {
        $id = isset($response['id']) ? (string) $response['id'] : '';
        return [
            'id'        => $id,
            'query_url' => ($id !== '' && $submitUrl !== '')
                ? $this->taskUrlFrom($submitUrl, '/' . rawurlencode($id))
                : '',
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array{status: string, error: string, result: \Ai\Contracts\CapabilityResponseInterface|null}
     */
    public function parseTaskStatus(string $capability, array $response): array
    {
        $status = isset($response['status']) ? (string) $response['status'] : '';
        $result = null;
        $error  = '';

        if (strtolower($status) === 'succeeded') {
            $url = (string) $this->dig($response, 'content.video_url');
            $result = new \Ai\Response\VideoResponse(
                $url,
                '',
                0.0,
                $response,
                isset($response['model']) ? (string) $response['model'] : '',
                isset($response['usage']) && is_array($response['usage']) ? $response['usage'] : [],
                $url === '' ? '任务成功但响应里没有 content.video_url' : ''
            );
        } elseif (strtolower($status) === 'failed') {
            $error = $this->taskError($response);
        }

        return ['status' => $status, 'error' => $error, 'result' => $result];
    }

    /**
     * 火山方舟图片生成模型（据官方文档，2026-08-12）
     *
     * 只有 4.0 / 4.5 / 5.0-lite 支持一次生成多张（参考图 + 生成图 ≤ 15 张）。
     *
     * @return array<int, string>
     */
    public function knownImageModels(): array
    {
        return [
            'doubao-seedream-5.0-lite',
            'doubao-seedream-4.5',
            'doubao-seedream-4.0',
            'doubao-seedream-3.0-t2i',
        ];
    }
}
