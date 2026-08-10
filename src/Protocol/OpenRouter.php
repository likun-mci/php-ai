<?php
namespace Ai\Protocol;

/**
 * OpenRouter 协议实现
 *
 * OpenRouter 是一个 AI 模型聚合/中转平台，使用 OpenAI Chat Completions 兼容格式，
 * 通过统一接口访问多种 AI 模型（OpenAI、Claude、Gemini、DeepSeek 等），
 * 适合作为国内访问海外 API 的聚合中转方案。
 *
 * 模型名直接使用 OpenRouter 上的完整标识：
 *   - openai/gpt-4o
 *   - anthropic/claude-sonnet-4-20250514
 *   - deepseek/deepseek-chat
 *   - google/gemini-2.5-pro-exp-03-25
 *   - meta-llama/llama-4-scout-17b-16e-instruct
 *
 * 使用方式（手选协议）：
 * ```php
 * $ai = AI::create([
 *     'model'    => 'openai/gpt-4o',
 *     'protocol' => 'openrouter',
 *     'api_key'  => 'sk-or-v1-xxx',
 *     'referer'  => 'https://myapp.com',   // 可选，来源标识
 *     'title'    => 'MyApp',               // 可选，应用名称
 * ]);
 * ```
 *
 * 也兼容通过 base_url 配置的传统方式：
 * ```php
 * $ai = AI::create([
 *     'model'    => 'openai/gpt-4o',
 *     'base_url' => 'https://openrouter.ai/api',
 *     'api_key'  => 'sk-or-v1-xxx',
 * ]);
 * ```
 */
class OpenRouter extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://openrouter.ai/api';
    }

    /**
     * 构建请求头
     *
     * OpenRouter 用标准的 Bearer token 鉴权（同 OpenAI），
     * 此外推荐携带 HTTP-Referer 和 X-Title 用于来源标识与后台排名。
     *
     * 可通过运行时配置设置：
     *   - referer: 来源站点 URL（自动写为 HTTP-Referer 头）
     *   - title:   应用名称（自动写为 X-Title 头）
     *   - headers:  数组形式覆盖或追加任意请求头（优先级最高）
     */
    public function buildHeaders(array $config): array
    {
        // 先构建 OpenAI 标准头（Authorization、Content-Type 等）
        $headers = parent::buildHeaders($config);

        // parent::buildHeaders 已调用 Headers::apply，
        // 但 OpenRouter 特有头应允许被 config['headers'] 覆盖，
        // 所以先加 OpenRouter 头，再调一次 apply

        if (isset($config['referer'])) {
            $headers['HTTP-Referer'] = $config['referer'];
        } elseif (!empty($_SERVER['HTTP_ORIGIN'])) {
            $headers['HTTP-Referer'] = $_SERVER['HTTP_ORIGIN'];
        }

        if (isset($config['title'])) {
            $headers['X-Title'] = $config['title'];
        }

        // 二次 apply，让 config['headers'] 中的值可以覆盖上述 OpenRouter 默认头
        return \Ai\Helpers\Headers::apply($headers, $config);
    }

    /**
     * 常用模型（供后台离线渲染下拉框；拉取失败时作为兜底）
     * OpenRouter 自身的 /v1/models 接口很完整，正常情况下不会用到这里
     */
    public function knownModels(): array
    {
        return [
            'openai/gpt-5.1'                    => 'GPT-5.1',
            'anthropic/claude-opus-5'           => 'Claude Opus 5',
            'anthropic/claude-sonnet-5'         => 'Claude Sonnet 5',
            'google/gemini-2.5-pro'             => 'Gemini 2.5 Pro',
            'deepseek/deepseek-chat'            => 'DeepSeek Chat',
            'meta-llama/llama-3.3-70b-instruct' => 'Llama 3.3 70B',
        ];
    }
}
