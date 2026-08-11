<?php
namespace Ai\Protocol;

/**
 * Azure OpenAI（需自填资源地址）
 *
 * 没有公共域名，必须配置 base_url 为自己的资源地址，如
 * https://<resource>.openai.azure.com；鉴权头是 api-key 而非 Bearer。
 * 走旧版「部署名 + api-version」路由时，用 endpoint 配置完整 URL：
 * https://<resource>.openai.azure.com/openai/deployments/<部署名>/chat/completions?api-version=2024-10-21
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => '模型名',
 *     'protocol' => 'azure',
 *     'base_url' => 'https://<resource>.openai.azure.com',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://learn.microsoft.com/azure/ai-foundry/openai/reference
 */
class Azure extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     * 返回空串表示没有公共地址，必须由用户配置 base_url / endpoint
     */
    public function defaultBaseUrl(): string
    {
        return '';
    }

    /**
     * 协议对话路径
     */
    public function chatPath(): string
    {
        return '/openai/v1/chat/completions';
    }

    /**
     * 协议模型列表路径
     */
    public function modelsPath(): string
    {
        return '/openai/v1/models';
    }

    /**
     * 构建请求头
     *
     * Azure OpenAI 用 api-key 头鉴权，而非 OpenAI 的 Authorization: Bearer。
     * 用 Microsoft Entra ID 的访问令牌时，改用 headers 配置项自行写 Authorization。
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    public function buildHeaders(array $config): array
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if (isset($config['api_key'])) {
            $headers['api-key'] = $config['api_key'];
        }

        return \Ai\Helpers\Headers::apply($headers, $config);
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [];
    }
}
