<?php
namespace Ai\Models\OpenAI;

use Ai\Models\BaseModel;

/**
 * GPT-4.1 模型
 */
class GPT41 extends BaseModel
{
    protected $name = 'gpt-4.1';
    protected $platform = 'openai';
    protected $protocol = 'Ai\\Protocol\\OpenAI';
    protected $endpoint = 'https://api.openai.com/v1/chat/completions';
    protected $features = ['chat', 'stream', 'function_calling'];
    protected $config = [
        'max_tokens' => 8192,
        'temperature' => 0.7,    ];
}
