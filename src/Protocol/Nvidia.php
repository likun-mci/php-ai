<?php
namespace Ai\Protocol;

/**
 * NVIDIA NIM（OpenAI 兼容）
 *
 * api_key 取 build.nvidia.com 的 nvapi-xxx。
 *
 * ```php
 * $ai = AI::create([
 *     'model'    => 'deepseek-ai/deepseek-r1',
 *     'protocol' => 'nvidia',
 *     'api_key'  => '<your-api-key>',
 * ]);
 * ```
 *
 * @see https://docs.api.nvidia.com/nim/reference/llm-apis
 */
class Nvidia extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://integrate.api.nvidia.com';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；平台无模型列表接口或拉取失败时作为兜底）
     * @return array<string, string> 模型 id => 显示名
     */
    public function knownModels(): array
    {
        return [
            'deepseek-ai/deepseek-r1'                => 'DeepSeek R1',
            'meta/llama-3.3-70b-instruct'            => 'Llama 3.3 70B',
            'qwen/qwen3-235b-a22b'                   => 'Qwen3 235B',
            'nvidia/llama-3.1-nemotron-70b-instruct' => 'Nemotron 70B',
        ];
    }

    /**
     * 本平台没有图像生成接口
     *
     * 未查证，按「查不到就不声明」处理：integrate.api.nvidia.com 连对话端点
     * 都返回 404（而库里那条路径对真实用户是可用的），说明探测方法对该平台
     * 不可靠；官方文档描述的图像/音频 NIM 是自托管容器，与托管端点未必一致。
     *
     * 确知可用时请配 {能力}_endpoint 逃生口。
     */
    public function imagePath(): string
    {
        return '';
    }

    /**
     * 本平台没有图像编辑接口
     *
     * 未查证，按「查不到就不声明」处理：integrate.api.nvidia.com 连对话端点
     * 都返回 404（而库里那条路径对真实用户是可用的），说明探测方法对该平台
     * 不可靠；官方文档描述的图像/音频 NIM 是自托管容器，与托管端点未必一致。
     *
     * 确知可用时请配 {能力}_endpoint 逃生口。
     */
    public function imageEditPath(): string
    {
        return '';
    }

    /**
     * 本平台没有语音合成接口
     *
     * 未查证，按「查不到就不声明」处理：integrate.api.nvidia.com 连对话端点
     * 都返回 404（而库里那条路径对真实用户是可用的），说明探测方法对该平台
     * 不可靠；官方文档描述的图像/音频 NIM 是自托管容器，与托管端点未必一致。
     *
     * 确知可用时请配 {能力}_endpoint 逃生口。
     */
    public function ttsPath(): string
    {
        return '';
    }

    /**
     * 本平台没有语音识别接口
     *
     * 未查证，按「查不到就不声明」处理：integrate.api.nvidia.com 连对话端点
     * 都返回 404（而库里那条路径对真实用户是可用的），说明探测方法对该平台
     * 不可靠；官方文档描述的图像/音频 NIM 是自托管容器，与托管端点未必一致。
     *
     * 确知可用时请配 {能力}_endpoint 逃生口。
     */
    public function asrPath(): string
    {
        return '';
    }
}
