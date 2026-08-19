<?php
namespace Ai\Protocol;

/**
 * Perplexity Sonar（联网搜索，OpenAI 兼容）
 *
 * 对话路径没有 /v1 前缀，库已内置。
 * 该平台没有模型列表接口，listModels() 会回退到内置常用列表。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'sonar',
 *     'protocol' => 'perplexity',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://docs.perplexity.ai/api-reference/chat-completions-post
 */
class Perplexity extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.perplexity.ai';
    }

    /**
     * 协议对话路径
     */
    public function chatPath(): string
    {
        return '/chat/completions';
    }

    /**
     * 协议模型列表路径
     */
    public function modelsPath(): string
    {
        return '/models';
    }

    /**
     * Perplexity 支持搜索参数
     *
     * 注意语义与其它平台不同：Sonar 系模型**本来就是联网的**，不存在「开不开」。
     * 这里声明支持，是为了让统一配置里的过滤项（时效、域名）能落到实处，
     * 而不是让用户在唯一一个天生联网的平台上反而收到「不支持搜索」的报错。
     */
    public function supportsWebSearch(): bool
    {
        return true;
    }

    /**
     * 翻译成 Perplexity 的顶层搜索过滤参数
     *
     * 域名过滤用同一个 search_domain_filter 表达黑白名单：
     * 前面加减号是排除，不加是限定。
     *
     * @see https://docs.perplexity.ai/api-reference/chat-completions-post
     * @param array<string, mixed> $request
     * @param array<string, mixed> $search
     * @return array<string, mixed>
     */
    public function applyWebSearch(array $request, array $search): array
    {
        $recency = \Ai\Helpers\WebSearch::opt($search, 'recency');
        if ($recency !== null) {
            // 取值 hour / day / week / month / year，与统一配置完全一致
            $request['search_recency_filter'] = $recency;
        }

        $allowed = \Ai\Helpers\WebSearch::opt($search, 'allowed_domains');
        if ($allowed) {
            $request['search_domain_filter'] = $allowed;
        }
        $blocked = \Ai\Helpers\WebSearch::opt($search, 'blocked_domains');
        if ($blocked) {
            $request['search_domain_filter'] = array_map(function ($domain) {
                return '-' . ltrim($domain, '-');
            }, $blocked);
        }

        $sources = \Ai\Helpers\WebSearch::opt($search, 'sources');
        if ($sources !== null) {
            // 相关追问同样基于搜索结果生成，是这里最接近「返回来源信息」的开关
            $request['return_related_questions'] = (bool) $sources;
        }

        // max_uses / count / forced / citation / query 没有对应参数；
        // enable 本身也无需落字段——Sonar 天生联网
        return $request;
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'sonar'               => 'Sonar',
            'sonar-pro'           => 'Sonar Pro',
            'sonar-reasoning'     => 'Sonar Reasoning',
            'sonar-reasoning-pro' => 'Sonar Reasoning Pro',
            'sonar-deep-research' => 'Sonar Deep Research',
        ];
    }

    /**
     * 本平台没有图像生成接口
     *
     * 实测 2026-08-12：带 Authorization 头 POST 该路径返回 404，
     * 同前缀假路径同样 404、而对话路径返回 401，可确认此路由不存在。
     * 此前是从 OpenAI 基线继承来的声明，与事实不符。
     */
    public function imagePath(): string
    {
        return '';
    }

    /**
     * 本平台没有图像编辑接口
     *
     * 实测 2026-08-12：带 Authorization 头 POST 该路径返回 404，
     * 同前缀假路径同样 404、而对话路径返回 401，可确认此路由不存在。
     * 此前是从 OpenAI 基线继承来的声明，与事实不符。
     */
    public function imageEditPath(): string
    {
        return '';
    }

    /**
     * 本平台没有语音合成接口
     *
     * 实测 2026-08-12：带 Authorization 头 POST 该路径返回 404，
     * 同前缀假路径同样 404、而对话路径返回 401，可确认此路由不存在。
     * 此前是从 OpenAI 基线继承来的声明，与事实不符。
     */
    public function ttsPath(): string
    {
        return '';
    }

    /**
     * 本平台没有语音识别接口
     *
     * 实测 2026-08-12：带 Authorization 头 POST 该路径返回 404，
     * 同前缀假路径同样 404、而对话路径返回 401，可确认此路由不存在。
     * 此前是从 OpenAI 基线继承来的声明，与事实不符。
     */
    public function asrPath(): string
    {
        return '';
    }

    /**
     * 本平台没有文本向量化接口
     *
     * 实测 2026-08-12：带 Authorization 头 POST 该路径返回 404，
     * 同前缀假路径同样 404、而对话路径返回 401，可确认此路由不存在。
     * 此前是从 OpenAI 基线继承来的声明，与事实不符。
     */
    public function embeddingPath(): string
    {
        return '';
    }
}
