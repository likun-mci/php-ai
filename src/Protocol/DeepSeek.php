<?php
namespace Ai\Protocol;

/**
 * DeepSeek 协议实现
 * DeepSeek 兼容 OpenAI Chat Completions 格式，仅端点和鉴权头不同
 */
class DeepSeek extends OpenAI
{
    /**
     * 协议官方默认接口根地址
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.deepseek.com';
    }

    /**
     * 常用模型（供后台离线渲染下拉框；拉取失败时作为兜底）
     */
    public function knownModels(): array
    {
        return [
            'deepseek-chat'     => 'DeepSeek Chat',
            'deepseek-reasoner' => 'DeepSeek Reasoner（推理）',
        ];
    }
}
