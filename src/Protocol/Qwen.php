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
     * 百炼支持由请求参数开启联网搜索
     */
    public function supportsWebSearch(): bool
    {
        return true;
    }

    /**
     * 翻译成百炼的 enable_search + search_options
     *
     * OpenAI 兼容模式下这两个字段就放在请求体顶层（官方 Python 示例里的
     * `extra_body={...}` 只是 SDK 的写法，落到 HTTP 上仍是顶层字段）。
     *
     * 平台独有的 search_strategy（turbo / max / agent / agent_max）与
     * citation_format 不进统一配置，要用就走 `extra_body`——
     * 顶层 array_merge 会整个替换掉这里生成的 search_options。
     *
     * @see https://help.aliyun.com/zh/model-studio/web-search
     * @param array<string, mixed> $request
     * @param array<string, mixed> $search
     * @return array<string, mixed>
     */
    public function applyWebSearch(array $request, array $search): array
    {
        $request['enable_search'] = true;

        $options = [];
        $forced = \Ai\Helpers\WebSearch::opt($search, 'forced');
        if ($forced !== null) {
            $options['forced_search'] = (bool) $forced;
        }
        $sources = \Ai\Helpers\WebSearch::opt($search, 'sources');
        if ($sources !== null) {
            $options['enable_source'] = (bool) $sources;
        }
        $citation = \Ai\Helpers\WebSearch::opt($search, 'citation');
        if ($citation !== null) {
            $options['enable_citation'] = (bool) $citation;
        }
        if ($options) {
            $request['search_options'] = $options;
        }

        // max_uses / count / recency / query / 域名过滤在百炼没有对应参数
        return $request;
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
     * 通义万相文生图（**异步任务式**）
     *
     * 实测 2026-08：OpenAI 兼容模式下的 /v1/images/generations 返回 404
     * （同前缀假路径也 404，该网关路由优先，可确认不存在）。
     * 万相走的是原生异步接口：提交后拿 task_id，再轮询 /api/v1/tasks/{id}。
     *
     * 因此本协议的图像生成必须用 $ai->images()->generateAsync()，
     * generate() 会给出明确报错并指向前者，而不是返回一个「成功但没有图」的响应。
     *
     * 注：万相 2.6 用的是另一个端点（/api/v1/services/aigc/image-generation/generation，
     * 请求体也改成了 input.messages 结构）。要用 2.6 请在配置里指定 image_endpoint，
     * 解析侧已同时兼容两种结果结构。
     */
    public function imagePath(): string
    {
        return '/api/v1/services/aigc/text2image/image-synthesis';
    }

    /**
     * 万相的图像生成是异步任务式
     */
    public function imageIsAsync(): bool
    {
        return true;
    }

    /**
     * 通义已登记的文生图模型（据官方文档，2026-08）
     *
     * wan2.6-t2i 走的是另一个端点，不在此列——列进来会让用户以为
     * 直接换个模型名就能用。
     *
     * @return array<int, string>
     */
    public function knownImageModels(): array
    {
        return [
            'wan2.5-t2i-preview',
            'wan2.2-t2i-flash',
            'wan2.2-t2i-plus',
            'wanx2.1-t2i-turbo',
            'wanx2.1-t2i-plus',
            'wanx-v1',
        ];
    }

    /**
     * 万相的请求体是 input / parameters 两段式
     *
     * 尺寸写法也不同：库内统一的 "1024x1024"（小写 x）要转成万相的
     * "1024*1024"（星号）。传错分隔符不会被容错，直接判为非法参数。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function buildImageRequest(array $payload): array
    {
        $input = ['prompt' => isset($payload['prompt']) ? $payload['prompt'] : ''];
        foreach (['negative_prompt', 'ref_image'] as $key) {
            if (!empty($payload[$key])) {
                $input[$key] = $payload[$key];
            }
        }
        // 库内统一用 image 表示参考图
        if (empty($input['ref_image']) && !empty($payload['image']) && is_string($payload['image'])) {
            $input['ref_image'] = $payload['image'];
        }

        $parameters = [];
        foreach (['n', 'style', 'seed', 'ref_strength', 'ref_mode', 'prompt_extend', 'watermark'] as $key) {
            if (isset($payload[$key])) {
                $parameters[$key] = $payload[$key];
            }
        }
        if (isset($payload['size']) && is_string($payload['size'])) {
            $parameters['size'] = str_replace(['x', 'X', '×'], '*', $payload['size']);
        }

        $request = ['model' => isset($payload['model']) ? $payload['model'] : '', 'input' => $input];
        if ($parameters) {
            $request['parameters'] = $parameters;
        }
        return $request;
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
        if (!in_array($capability, [\Ai\Helpers\Capabilities::VIDEO, \Ai\Helpers\Capabilities::IMAGE], true)) {
            return '';
        }
        $path = $capability === \Ai\Helpers\Capabilities::VIDEO ? $this->videoPath() : $this->imagePath();

        // 剥掉「/compatible-mode + 对话路径」拿到根地址，再接目标路径。
        // 只取 scheme+host 会丢掉网关的路径前缀（如 https://gw.internal/ds 里的 /ds）
        $suffix = '/compatible-mode' . $this->chatPath();
        if (substr($chatEndpoint, -strlen($suffix)) === $suffix) {
            return substr($chatEndpoint, 0, -strlen($suffix)) . $path;
        }

        $origin = $this->taskOrigin($chatEndpoint);
        return $origin === '' ? '' : $origin . $path;
    }

    /**
     * 异步视频必须带这个头，不带会退化成同步调用然后超时
     *
     * @return array<string, string>
     */
    public function capabilityHeaders(string $capability): array
    {
        if (in_array($capability, [\Ai\Helpers\Capabilities::VIDEO, \Ai\Helpers\Capabilities::IMAGE], true)) {
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
                ? $this->taskSiblingUrl(
                    $submitUrl,
                    $capability === \Ai\Helpers\Capabilities::IMAGE ? $this->imagePath() : $this->videoPath(),
                    '/api/v1/tasks/' . rawurlencode($id)
                )
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

        if (strtoupper($status) === 'SUCCEEDED' && $capability === \Ai\Helpers\Capabilities::IMAGE) {
            $result = $this->buildWanxImageResult($response);
        } elseif (strtoupper($status) === 'SUCCEEDED') {
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

    /**
     * 从万相的任务结果里取出图片
     *
     * 兼容两种结构：
     *   经典 image-synthesis 接口   output.results[].url
     *   万相 2.6 的新接口           output.choices[].message.content[] 里 type=image 的元素
     *
     * 同时兼容而不是二选一，是因为用户可能通过 image_endpoint 指向 2.6 的端点，
     * 那时结果结构会变，只认一种会静默解析不到图。
     *
     * @param array<string, mixed> $response
     */
    protected function buildWanxImageResult(array $response): \Ai\Response\ImageResponse
    {
        $urls = [];

        $results = $this->dig($response, 'output.results');
        if (is_array($results)) {
            foreach ($results as $item) {
                if (is_array($item) && !empty($item['url']) && is_string($item['url'])) {
                    $urls[] = $item['url'];
                }
            }
        }

        if (!$urls) {
            $choices = $this->dig($response, 'output.choices');
            if (is_array($choices)) {
                foreach ($choices as $choice) {
                    $content = isset($choice['message']['content']) ? $choice['message']['content'] : null;
                    if (!is_array($content)) {
                        continue;
                    }
                    foreach ($content as $part) {
                        if (is_array($part) && !empty($part['image']) && is_string($part['image'])) {
                            $urls[] = $part['image'];
                        }
                    }
                }
            }
        }

        return new \Ai\Response\ImageResponse(
            $urls,
            [],
            $response,
            isset($response['model']) ? (string) $response['model'] : '',
            isset($response['usage']) && is_array($response['usage']) ? $response['usage'] : [],
            '',
            $urls ? '' : '任务成功但响应里没有解析到图片地址，原始响应见 getRaw()'
        );
    }

    /**
     * 通义兼容模式没有图像编辑端点
     *
     * 实测 2026-08：POST {dashscope}/compatible-mode/v1/images/edits 返回 404，
     * 同前缀假路径同样 404（该网关路由优先，可确认不存在）。
     *
     * 万相的图像编辑走原生异步接口，形态与 OpenAI 的 multipart 上传完全不同，
     * 本期不接入。返回空串让调用方得到明确报错而不是往空路径发请求。
     */
    public function imageEditPath(): string
    {
        return '';
    }
}
