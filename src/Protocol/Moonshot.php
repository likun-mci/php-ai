<?php
namespace Ai\Protocol;

/**
 * 月之暗面 Kimi（OpenAI 兼容）
 *
 * 国际站请把 base_url 换成 https://api.moonshot.ai。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'kimi-k2-turbo-preview',
 *     'protocol' => 'moonshot',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://platform.moonshot.cn/docs/api/chat
 */
class Moonshot extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.moonshot.cn';
    }

    /**
     * Kimi 支持由请求参数开启联网搜索
     */
    public function supportsWebSearch(): bool
    {
        return true;
    }

    /**
     * 翻译成 Kimi 的 builtin_function 写法
     *
     * ⚠️ 与其它几家不同，Kimi 的 $web_search **不是纯服务端工具**：
     * 模型只负责生成搜索参数，会以一次正常的 tool_calls 返回给客户端，
     * 客户端要把这次调用的 arguments 原样回填成 tool 消息，对话才会继续。
     * 也就是说单发一次 chat() 只会拿到一个 tool_call、拿不到最终答案，
     * 必须配合 Agent 循环（$ai->agent()）使用。
     *
     * 声明里只需要 type 与 function.name，不需要 description / parameters。
     *
     * @see https://platform.moonshot.cn/docs/guide/use-web-search
     * @param array<string, mixed> $request
     * @param array<string, mixed> $search
     * @return array<string, mixed>
     */
    public function applyWebSearch(array $request, array $search): array
    {
        // Kimi 的内置搜索没有任何可调参数，统一配置里的细项一概无处可放
        return $this->appendServerTool($request, [
            'type'     => 'builtin_function',
            'function' => ['name' => '$web_search'],
        ]);
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'kimi-k2-turbo-preview' => 'Kimi K2 Turbo',
            'kimi-k2-0905-preview'  => 'Kimi K2 0905',
            'kimi-latest'           => 'Kimi 最新版',
            'moonshot-v1-128k'      => 'Moonshot v1 128K',
            'moonshot-v1-32k'       => 'Moonshot v1 32K',
            'moonshot-v1-8k'        => 'Moonshot v1 8K',
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
}
