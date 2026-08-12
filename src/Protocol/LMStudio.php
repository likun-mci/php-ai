<?php
namespace Ai\Protocol;

/**
 * LM Studio（本地，OpenAI 兼容）
 *
 * 本机默认端口 1234，无需 api_key。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => '模型名',
 *     'protocol' => 'lmstudio',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://lmstudio.ai/docs/app/api/endpoints/openai
 */
class LMStudio extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'http://localhost:1234';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [];
    }

    /**
     * 本地推理服务的能力取决于**用户加载了哪些模型**，不是平台固定的
     *
     * 因此这里刻意保留宽松声明（继承 OpenAI 基线的全部能力路径），
     * 与远程平台「查证优先」的策略不同：
     *
     *   - 远程平台的接口面是厂商定的，探测/查文档能得出确定结论，
     *     多声明就是在对用户撒谎
     *   - 本地服务的接口面由用户自己决定：装了 stable-diffusion 就有图像，
     *     装了 whisper 就有 ASR。库无从判断，替用户关掉才是错的
     *
     * 实际不支持时，本地服务会返回它自己的 404，信息同样清楚。
     *
     * @return array<string, string>
     */
    public function capabilityPathMap(): array
    {
        return parent::capabilityPathMap();
    }
}
