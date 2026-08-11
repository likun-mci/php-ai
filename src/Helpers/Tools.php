<?php
namespace Ai\Helpers;

/**
 * 工具调用（function calling）的格式归一
 *
 * 各家把同一件事写成了两套结构，业务层不该为此写两份代码：
 *
 *   Anthropic 系                        OpenAI 系
 *   ----------------------------------  ------------------------------------------
 *   工具定义   {name, description,       {type:'function', function:{name,
 *              input_schema}                description, parameters}}
 *   模型发起   content 块 {type:         message.tool_calls[] {id, type:'function',
 *              'tool_use', id, name,        function:{name, arguments:"JSON 字符串"}}
 *              input:数组}
 *   结果回填   user 消息里的 {type:      独立的 {role:'tool', tool_call_id, content}
 *              'tool_result',               消息，且必须紧跟在该 assistant 回合之后
 *              tool_use_id, content}
 *   结束原因   stop_reason:'tool_use'    finish_reason:'tool_calls'
 *
 * 本库对外统一采用 **Anthropic 风格**（结构更自洽：工具调用与文本同在 content 块里，
 * input 是数组而非字符串），由协议层在发出前转成目标平台的格式、在收到后转回来。
 * 用户也可以直接写 OpenAI 原生格式，这里会识别并原样放行，不强制迁移。
 */
class Tools
{
    /**
     * 是否为 OpenAI 原生的工具定义（{type:'function', function:{...}}）
     * @param array<string, mixed> $tool
     */
    public static function isOpenAiToolDef(array $tool): bool
    {
        return isset($tool['type'], $tool['function']) && $tool['type'] === 'function';
    }

    /**
     * 工具定义 → OpenAI 格式
     *
     * @param array<int, array<string, mixed>> $tools 统一格式或 OpenAI 原生格式的工具定义数组
     * @return array<int, array<string, mixed>> OpenAI 的 tools 数组
     */
    public static function toOpenAiDefs(array $tools): array
    {
        $out = [];
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            if (self::isOpenAiToolDef($tool)) {
                $out[] = $tool;                       // 已经是目标格式
                continue;
            }
            if (!isset($tool['name'])) {
                continue;
            }
            $out[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => (string) $tool['name'],
                    'description' => (string) ($tool['description'] ?? ''),
                    // OpenAI 叫 parameters，Anthropic 叫 input_schema，同为 JSON Schema
                    'parameters'  => $tool['input_schema']
                        ?? $tool['parameters']
                        ?? ['type' => 'object', 'properties' => new \stdClass()],
                ],
            ];
        }
        return $out;
    }

    /**
     * 工具定义 → Anthropic 格式
     *
     * @param array<int, array<string, mixed>> $tools 统一格式或 OpenAI 原生格式的工具定义数组
     * @return array<int, array<string, mixed>> Anthropic 的 tools 数组
     */
    public static function toClaudeDefs(array $tools): array
    {
        $out = [];
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            if (self::isOpenAiToolDef($tool)) {
                $fn = $tool['function'];
                $out[] = [
                    'name'         => (string) ($fn['name'] ?? ''),
                    'description'  => (string) ($fn['description'] ?? ''),
                    'input_schema' => $fn['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                ];
                continue;
            }
            if (isset($tool['name'])) {
                $out[] = $tool;                       // 已经是目标格式
            }
        }
        return $out;
    }

    /**
     * tool_choice → OpenAI 格式
     *
     * Anthropic: {type:'auto'|'any'|'tool', name?}
     * OpenAI:    'auto' | 'required' | 'none' | {type:'function', function:{name}}
     * @param mixed $choice
     * @return mixed
     */
    public static function toOpenAiToolChoice($choice)
    {
        if (is_string($choice)) {
            return $choice;
        }
        if (!is_array($choice) || !isset($choice['type'])) {
            return $choice;
        }
        switch ($choice['type']) {
            case 'auto':
                return 'auto';
            case 'any':
                return 'required';
            case 'none':
                return 'none';
            case 'tool':
                return isset($choice['name'])
                    ? ['type' => 'function', 'function' => ['name' => $choice['name']]]
                    : 'required';
            default:
                return $choice;
        }
    }

    /**
     * 消息数组 → OpenAI 格式
     *
     * 需要处理两处结构差异：
     *   1) assistant 的 content 块要拆成 content 文本 + tool_calls 数组
     *   2) user 里的 tool_result 块要拆成独立的 role:'tool' 消息，
     *      且必须紧跟在对应的 assistant 回合之后（OpenAI 会校验这个顺序）
     *
     * @param array<int, array<string, mixed>> $messages 统一格式（或已是 OpenAI 格式）的消息数组
     * @return array<int, array<string, mixed>> OpenAI 的 messages 数组
     */
    public static function toOpenAiMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $msg) {
            if (!is_array($msg) || !isset($msg['role'])) {
                continue;
            }
            $role    = $msg['role'];
            $content = $msg['content'] ?? '';

            // 已经是 OpenAI 原生写法：role:'tool' 或 assistant 带 tool_calls，原样放行
            if ($role === 'tool' || ($role === 'assistant' && isset($msg['tool_calls']))) {
                $out[] = $msg;
                continue;
            }
            // 纯字符串内容：无需转换
            if (!is_array($content)) {
                $out[] = $msg;
                continue;
            }

            if ($role === 'assistant') {
                $text      = '';
                $toolCalls = [];
                foreach ($content as $block) {
                    if (!is_array($block)) {
                        continue;
                    }
                    $type = $block['type'] ?? '';
                    if ($type === 'text') {
                        $text .= (string) ($block['text'] ?? '');
                    } elseif ($type === 'tool_use') {
                        $toolCalls[] = [
                            'id'       => (string) ($block['id'] ?? ''),
                            'type'     => 'function',
                            'function' => [
                                'name' => (string) ($block['name'] ?? ''),
                                // OpenAI 要求 arguments 是 JSON 字符串，不是对象
                                'arguments' => json_encode(
                                    $block['input'] ?? new \stdClass(),
                                    JSON_UNESCAPED_UNICODE
                                ),
                            ],
                        ];
                    }
                }
                $assistant = ['role' => 'assistant', 'content' => $text];
                if ($toolCalls) {
                    $assistant['tool_calls'] = $toolCalls;
                }
                $out[] = $assistant;
                continue;
            }

            // user / system：拆出 tool_result，其余部分保留为普通消息
            $toolMsgs = [];
            $rest     = [];
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'tool_result') {
                    $toolMsgs[] = [
                        'role'         => 'tool',
                        'tool_call_id' => (string) ($block['tool_use_id'] ?? ''),
                        'content'      => self::flattenToolResult($block['content'] ?? ''),
                    ];
                } else {
                    $rest[] = $block;
                }
            }
            // tool 消息必须紧跟 assistant 回合，因此先于剩余内容写入
            foreach ($toolMsgs as $tm) {
                $out[] = $tm;
            }
            if ($rest) {
                $out[] = ['role' => $role, 'content' => self::simplifyContent($rest)];
            }
        }
        return $out;
    }

    /**
     * 消息数组 → Anthropic 格式
     *
     * 反向处理 OpenAI 原生写法：role:'tool' 合并回 user 的 tool_result 块，
     * assistant.tool_calls 合并回 content 的 tool_use 块。
     * 已是统一格式时原样返回。
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    public static function toClaudeMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $msg) {
            if (!is_array($msg) || !isset($msg['role'])) {
                continue;
            }
            $role = $msg['role'];

            if ($role === 'tool') {
                $block = [
                    'type'        => 'tool_result',
                    'tool_use_id' => (string) ($msg['tool_call_id'] ?? ''),
                    'content'     => self::flattenToolResult($msg['content'] ?? ''),
                ];
                // 连续的 tool 消息合并进同一条 user 消息（Anthropic 要求如此）
                $last = count($out) - 1;
                if ($last >= 0 && $out[$last]['role'] === 'user' && is_array($out[$last]['content'])
                    && ($out[$last]['content'][0]['type'] ?? '') === 'tool_result') {
                    $out[$last]['content'][] = $block;
                } else {
                    $out[] = ['role' => 'user', 'content' => [$block]];
                }
                continue;
            }

            if ($role === 'assistant' && isset($msg['tool_calls']) && is_array($msg['tool_calls'])) {
                $blocks = [];
                $text   = (string) ($msg['content'] ?? '');
                if ($text !== '') {
                    $blocks[] = ['type' => 'text', 'text' => $text];
                }
                foreach ($msg['tool_calls'] as $tc) {
                    $fn   = $tc['function'] ?? [];
                    $args = $fn['arguments'] ?? '{}';
                    $blocks[] = [
                        'type'  => 'tool_use',
                        'id'    => (string) ($tc['id'] ?? ''),
                        'name'  => (string) ($fn['name'] ?? ''),
                        'input' => is_string($args) ? (json_decode($args, true) ?: []) : (array) $args,
                    ];
                }
                $out[] = ['role' => 'assistant', 'content' => $blocks];
                continue;
            }

            $out[] = $msg;
        }
        return $out;
    }

    /**
     * OpenAI 响应的 message → 统一格式的工具调用数组
     * @return array<int, array{id: string, name: string, input: array<string, mixed>}> [['id'=>..,'name'=>..,'input'=>array], ...]
     * @param array<string, mixed> $message
     */
    public static function fromOpenAiToolCalls(array $message): array
    {
        $calls = [];
        foreach (($message['tool_calls'] ?? []) as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            $fn   = $tc['function'] ?? [];
            $args = $fn['arguments'] ?? '';
            $calls[] = [
                'id'    => (string) ($tc['id'] ?? ''),
                'name'  => (string) ($fn['name'] ?? ''),
                // arguments 是 JSON 字符串；模型偶尔会给出不合法 JSON，此时降级为空数组
                'input' => is_string($args) ? (json_decode($args, true) ?: []) : (array) $args,
            ];
        }
        return $calls;
    }

    /**
     * Anthropic 响应的 content 块数组 → 统一格式的工具调用数组
     * @return array<int, array{id: string, name: string, input: array<string, mixed>}> [['id'=>..,'name'=>..,'input'=>array], ...]
     * @param array<int, array<string, mixed>> $content
     */
    public static function fromClaudeContent(array $content): array
    {
        $calls = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'tool_use') {
                $calls[] = [
                    'id'    => (string) ($block['id'] ?? ''),
                    'name'  => (string) ($block['name'] ?? ''),
                    'input' => (array) ($block['input'] ?? []),
                ];
            }
        }
        return $calls;
    }

    /**
     * 结束原因归一
     *
     * 统一取值：end_turn（正常结束）、tool_use（要调工具）、max_tokens（长度截断）、
     * stop_sequence（命中停止词）、content_filter（被内容审核拦下）、refusal（模型拒答）
     * @param mixed $reason
     */
    public static function normalizeStopReason($reason): string
    {
        $r = strtolower(trim((string) $reason));
        $map = [
            // OpenAI 系
            'stop'           => 'end_turn',
            'tool_calls'     => 'tool_use',
            'function_call'  => 'tool_use',
            'length'         => 'max_tokens',
            'content_filter' => 'content_filter',
            // Anthropic 系（原样保留）
            'end_turn'       => 'end_turn',
            'tool_use'       => 'tool_use',
            'max_tokens'     => 'max_tokens',
            'stop_sequence'  => 'stop_sequence',
            'refusal'        => 'refusal',
            'pause_turn'     => 'pause_turn',
        ];
        return $map[$r] ?? $r;
    }

    /**
     * 工具结果内容扁平化为字符串
     * Anthropic 允许 tool_result 的 content 是块数组，OpenAI 的 role:'tool' 只收字符串
     * @param mixed $content
     */
    protected static function flattenToolResult($content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return (string) $content;
        }
        $text = '';
        foreach ($content as $block) {
            if (is_string($block)) {
                $text .= $block;
            } elseif (is_array($block) && isset($block['text'])) {
                $text .= (string) $block['text'];
            }
        }
        // 结构化结果（如图片块）无法降级为纯文本时，原样序列化，避免丢信息
        if ($text !== '') {
            return $text;
        }
        $encoded = json_encode($content, JSON_UNESCAPED_UNICODE);
        return $encoded === false ? '' : $encoded;
    }

    /**
     * 只剩纯文本块时降级为字符串，否则保留数组（多模态附件仍需数组形式）
     * @param array<int, array<string, mixed>> $blocks
     * @return string|array<int, array<string, mixed>>
     */
    protected static function simplifyContent(array $blocks)
    {
        $text = '';
        foreach ($blocks as $b) {
            if (!is_array($b) || ($b['type'] ?? '') !== 'text') {
                return $blocks;
            }
            $text .= (string) ($b['text'] ?? '');
        }
        return $text;
    }
}
