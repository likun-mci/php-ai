<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;
use Ai\Helpers\Translate;

/**
 * translate 工具——把文本翻译成目标语言（Edge 免费接口，无需密钥）
 *
 * 让模型在需要时把外文文档/报错翻成中文再理解，或把内容翻成其他语言。
 * 走 Ai\Helpers\Translate（Edge 免费端点），失败返回错误。
 *
 * 属外呼，默认经 PermissionManager 把关；**不默认装配**，需显式启用
 *（见 dev.md v2.1 §1.5）。
 */
class TranslateTool implements AgentToolInterface
{
    public function name()
    {
        return 'translate';
    }

    public function description()
    {
        return '把文本翻译成目标语言（免费，无需密钥）。to 用 ISO 码，如 en / zh-Hans / ja。'
            . '代码标识符、URL、占位符会尽量保持原样。';
    }

    public function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'text' => [
                    'type'        => 'string',
                    'description' => '要翻译的文本',
                ],
                'to' => [
                    'type'        => 'string',
                    'description' => '目标语言 ISO 码，如 en / zh-Hans / ja',
                ],
                'from' => [
                    'type'        => 'string',
                    'description' => '源语言 ISO 码，留空自动识别',
                    'default'     => '',
                ],
            ],
            'required' => ['text', 'to'],
        ];
    }

    public function execute(array $input, ToolContext $context)
    {
        $text = isset($input['text']) ? (string) $input['text'] : '';
        $to   = isset($input['to']) ? (string) $input['to'] : '';
        $from = isset($input['from']) ? (string) $input['from'] : '';

        if (trim($text) === '') {
            return ToolResult::error('参数 text 不能为空');
        }
        if (trim($to) === '') {
            return ToolResult::error('参数 to 不能为空');
        }

        $result = Translate::to($text, $to, ['from' => $from, 'engine' => 'edge']);
        if (!is_string($result) || $result === $text) {
            return ToolResult::error('翻译失败（网络不可用或接口变动）');
        }

        return new ToolResult([
            'success'  => true,
            'content'  => $result,
            'metadata' => ['to' => $to, 'from' => $from],
            'display'  => 'translate → ' . $to,
        ]);
    }
}
