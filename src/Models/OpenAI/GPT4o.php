<?php
namespace Ai\Models\OpenAI;

use Ai\Models\BaseModel;

/**
 * GPT-4o 模型
 */
class GPT4o extends BaseModel
{
    protected $name = 'gpt-4o';
    protected $platform = 'openai';
    protected $protocol = 'Ai\\Protocol\\OpenAI';
    protected $endpoint = 'https://api.openai.com/v1/chat/completions';
    protected $features = ['chat', 'stream', 'function_calling', 'vision', 'attachments'];
    protected $config = [
        'max_tokens' => 16384,
        'temperature' => 0.7,
    ];
}
