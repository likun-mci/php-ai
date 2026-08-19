<?php
namespace Ai\Protocol;

/**
 * 百度千帆 / 文心一言（OpenAI 兼容）
 *
 * api_key 用千帆的应用 API Key（bce-v3/ALTAK-xxx/xxx）或 IAM Bearer Token。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'ernie-5.0-turbo-32k',
 *     'protocol' => 'ernie',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://cloud.baidu.com/doc/qianfan-api/s/Fm2vrveyu
 */
class Ernie extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://qianfan.baidubce.com';
    }

    /**
     * 协议对话路径
     */
    public function chatPath(): string
    {
        return '/v2/chat/completions';
    }

    /**
     * 协议模型列表路径
     */
    public function modelsPath(): string
    {
        return '/v2/models';
    }

    /**
     * 千帆支持由请求参数开启联网搜索
     */
    public function supportsWebSearch(): bool
    {
        return true;
    }

    /**
     * 翻译成千帆的顶层 web_search 对象
     *
     * @see https://cloud.baidu.com/doc/qianfan-docs/s/Wm8r4sw29
     * @param array<string, mixed> $request
     * @param array<string, mixed> $search
     * @return array<string, mixed>
     */
    public function applyWebSearch(array $request, array $search): array
    {
        $ws = ['enable' => true];

        $citation = \Ai\Helpers\WebSearch::opt($search, 'citation');
        if ($citation !== null) {
            $ws['enable_citation'] = (bool) $citation;
        }
        $sources = \Ai\Helpers\WebSearch::opt($search, 'sources');
        if ($sources !== null) {
            // enable_trace 返回的是搜索过程与来源列表
            $ws['enable_trace'] = (bool) $sources;
        }
        $count = \Ai\Helpers\WebSearch::opt($search, 'count');
        if ($count !== null) {
            // 官方上限 10
            $ws['search_number'] = min(10, (int) $count);
        }

        // max_uses / recency / forced / query / 域名过滤在千帆没有对应参数
        $request['web_search'] = $ws;
        return $request;
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'ernie-5.0-turbo-32k'  => '文心 5.0 Turbo 32K',
            'ernie-4.5-turbo-128k' => '文心 4.5 Turbo 128K',
            'ernie-4.0-turbo-8k'   => '文心 4.0 Turbo 8K',
            'ernie-3.5-8k'         => '文心 3.5 8K',
            'ernie-speed-128k'     => '文心 Speed 128K',
            'ernie-x1-turbo-32k'   => '文心 X1 Turbo（推理）',
        ];
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
}
