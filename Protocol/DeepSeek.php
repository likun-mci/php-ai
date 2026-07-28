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
}
