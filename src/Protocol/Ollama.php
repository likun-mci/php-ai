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
}
