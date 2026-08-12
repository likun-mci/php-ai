<?php
namespace Ai\Protocol;

/**
 * Ollama（本地，OpenAI 兼容）
 *
 * 本机默认端口 11434，无需 api_key；远程实例用 base_url 覆盖。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'llama3.2',
 *     'protocol' => 'ollama',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://github.com/ollama/ollama/blob/main/docs/openai.md
 */
class Ollama extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'http://localhost:11434';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'llama3.2'    => 'Llama 3.2',
            'qwen2.5'     => 'Qwen2.5',
            'deepseek-r1' => 'DeepSeek R1',
            'gemma3'      => 'Gemma 3',
        ];
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
