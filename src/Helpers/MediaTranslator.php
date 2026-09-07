<?php
namespace Ai\Helpers;

use Ai\Agent\Context\MessagePart;
use Ai\Agent\Media\MediaResolver;

/**
 * 内部媒体块 → 各协议的图片块
 *
 * Conversation 里存的是协议无关的 `agent_media`（只有引用，没有字节）。
 * 发请求前必须翻译成目标协议认识的格式,这一步就发生在这里。
 *
 * 为什么不在 Conversation 里直接存某一家的格式：同一份会话可能先用 Claude 跑、
 * 后换 OpenAI 跑(降级模型、模态路由都会导致换协议),存成任何一家的格式
 * 都会在换协议的那一刻失效。
 *
 * 两个家族（已核实只有这两个——`Gemini::convertMessages()` 是死代码,
 * Gemini 实际走 OpenAI 兼容端点）：
 *
 * | 家族 | 图片块 |
 * |---|---|
 * | `anthropic` | `{"type":"image","source":{"type":"base64","media_type":..,"data":..}}` |
 * | `openai` | `{"type":"image_url","image_url":{"url":"data:..;base64,.."}}` |
 *
 * **不支持时不静默降级**（设计文档 §8）：媒体块被替换成一句**事实陈述**
 * （「用户上传了图片 x.png,但当前模型无法查看图片内容」）,而不是
 * `[图片: x.png]` 这种看起来像「已附上」的占位符——后者会诱导模型写出
 * 「从图片可以看出……」,而它根本没看到。
 *
 * 注：方法不写 PHP 类型声明,保持 PHP 7.1 兼容（库的版本下限）。
 */
class MediaTranslator
{
    /** Anthropic / Claude 家族 */
    const FAMILY_ANTHROPIC = 'anthropic';

    /** OpenAI 及全部 OpenAI 兼容端点（含 Gemini） */
    const FAMILY_OPENAI = 'openai';

    /** @var MediaResolver|null 当前请求的解析器；为 null 时媒体块一律降级 */
    protected static $resolver = null;

    /** @var array<string, bool> 本次请求支持哪些模态,如 ['image' => true, 'pdf' => false] */
    protected static $supported = ['image' => false, 'pdf' => false];

    /** @var array<int, array<string, mixed>> 本次请求里被降级掉的媒体,供调用方读取状态 */
    protected static $skipped = [];

    /**
     * 装配本次请求的翻译上下文
     *
     * 由 AgentRuntime 在发请求前调用。之所以用静态而不是把 resolver 一路传进
     * 协议层：协议的 `buildRequest()` 签名是公开接口,给它加参数会是破坏性变更,
     * 而这条链路（Runtime → AI::chat → Protocol）中间隔着好几层不该关心媒体的代码。
     *
     * @param MediaResolver|null $resolver
     * @param array<string, bool> $supported
     * @return void
     */
    public static function begin($resolver, array $supported = [])
    {
        self::$resolver  = $resolver instanceof MediaResolver ? $resolver : null;
        self::$supported = [
            'image' => !empty($supported['image']),
            'pdf'   => !empty($supported['pdf']),
        ];
        self::$skipped = [];
        if (self::$resolver !== null) {
            self::$resolver->reset();
        }
    }

    /**
     * 清空翻译上下文
     *
     * 不清的话,下一次**没有**装配就发起的请求会沿用上一次的 resolver,
     * 把不该带的媒体带进去。
     *
     * @return void
     */
    public static function end()
    {
        self::$resolver  = null;
        self::$supported = ['image' => false, 'pdf' => false];
    }

    /** 当前是否装配了解析器
     * @return bool
     */
    public static function active()
    {
        return self::$resolver !== null;
    }

    /**
     * 本次请求里被降级掉的媒体
     *
     * 结构：`[['name' => 'a.png', 'media' => 'image', 'reason' => 'vision_not_supported'], …]`
     *
     * @return array<int, array<string, mixed>>
     */
    public static function skipped()
    {
        return self::$skipped;
    }

    /**
     * 翻译一条消息的 content
     *
     * 非数组、或数组里没有媒体块时原样返回——绝大多数消息不含媒体,
     * 这条快速路径保证零开销。
     *
     * @param mixed $content
     * @param string $family
     * @return mixed
     */
    public static function translateContent($content, $family)
    {
        if (!is_array($content) || !MessagePart::contentHasMedia($content)) {
            return $content;
        }

        $out = [];
        foreach ($content as $block) {
            if (!MessagePart::isMedia($block)) {
                $out[] = $block;
                continue;
            }
            $translated = self::translateBlock($block, $family);
            if ($translated !== null) {
                $out[] = $translated;
            }
        }

        // 不能返回空数组——那会让请求变成一条没有内容的消息,多数平台直接 400。
        // 走到这里只可能是传进来的 content 本身就是空数组
        if ($out === []) {
            $out[] = MessagePart::text('');
        }
        return $out;
    }

    /**
     * 翻译一批消息
     *
     * @param array<int, array<string, mixed>> $messages
     * @param string $family
     * @return array<int, array<string, mixed>>
     */
    public static function translateMessages(array $messages, $family)
    {
        $out = [];
        foreach ($messages as $msg) {
            if (is_array($msg) && isset($msg['content'])) {
                $msg['content'] = self::translateContent($msg['content'], $family);
            }
            $out[] = $msg;
        }
        return $out;
    }

    /**
     * 翻译单个媒体块
     *
     * @param array<string, mixed> $block
     * @param string $family
     * @return array<string, mixed>|null 无法翻译时返回一个说明用的 text 块
     */
    protected static function translateBlock(array $block, $family)
    {
        $media = isset($block['media']) ? (string) $block['media'] : 'image';

        // 模型不支持这个模态
        if (empty(self::$supported[$media])) {
            // 已经由视觉模型转成文字了（ModalityRouter 的 describe）——
            // 那就把描述给它，并**注明来源**：这不是主模型自己看到的
            $described = self::describedText($block);
            if ($described !== '') {
                self::$skipped[] = [
                    'name'   => isset($block['name']) ? (string) $block['name'] : '',
                    'media'  => $media,
                    'ref'    => isset($block['ref']) ? (string) $block['ref'] : '',
                    'reason' => 'described',
                ];
                return MessagePart::text($described);
            }
            self::$skipped[] = [
                'name'   => isset($block['name']) ? (string) $block['name'] : '',
                'media'  => $media,
                'ref'    => isset($block['ref']) ? (string) $block['ref'] : '',
                'reason' => $media . '_not_supported',
            ];
            return MessagePart::text(self::noticeFor($block));
        }

        if (self::$resolver === null) {
            self::$skipped[] = [
                'name'   => isset($block['name']) ? (string) $block['name'] : '',
                'media'  => $media,
                'ref'    => isset($block['ref']) ? (string) $block['ref'] : '',
                'reason' => 'no_resolver',
            ];
            return MessagePart::text(self::noticeFor($block));
        }

        $data = self::$resolver->resolve($block);
        if ($data === null) {
            // 媒体文件被清理掉了。这是正常情况（prune 过了),不该让整轮跑不下去,
            // 但也必须让模型知道它看不到这张图
            self::$skipped[] = [
                'name'   => isset($block['name']) ? (string) $block['name'] : '',
                'media'  => $media,
                'ref'    => isset($block['ref']) ? (string) $block['ref'] : '',
                'reason' => 'media_missing',
            ];
            return MessagePart::text(self::noticeFor($block, '（该文件已不在存储中）'));
        }

        return self::encode($data, $family);
    }

    /**
     * 已解析的媒体 → 目标协议的块
     *
     * @param array<string, mixed> $data resolve() 的返回
     * @param string $family
     * @return array<string, mixed>
     */
    public static function encode(array $data, $family)
    {
        $mime   = isset($data['mime']) ? (string) $data['mime'] : 'image/png';
        $base64 = isset($data['base64']) ? (string) $data['base64'] : '';
        $media  = isset($data['media']) ? (string) $data['media'] : 'image';

        if (self::normalizeFamily($family) === self::FAMILY_ANTHROPIC) {
            return [
                'type'   => $media === 'pdf' ? 'document' : 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => $mime,
                    'data'       => $base64,
                ],
            ];
        }

        // OpenAI 家族：图片走 image_url 的 data URI
        return [
            'type'      => 'image_url',
            'image_url' => ['url' => 'data:' . $mime . ';base64,' . $base64],
        ];
    }

    /**
     * 媒体块里带的视觉模型描述 → 给主模型看的文字
     *
     * 措辞必须**注明来源**：这是另一个模型看图后写的描述，不是主模型自己看到的。
     * 混淆这一点会让主模型把二手描述当作一手观察，在描述有偏差时给出过度自信的结论。
     *
     * @param array<string, mixed> $block
     * @return string 没有描述时返回空串
     */
    protected static function describedText(array $block)
    {
        $text = isset($block['description']) ? trim((string) $block['description']) : '';
        if ($text === '') {
            return '';
        }
        $name  = isset($block['name']) && $block['name'] !== '' ? (string) $block['name'] : '未命名文件';
        $media = isset($block['media']) ? (string) $block['media'] : 'image';
        $label = $media === 'pdf' ? 'PDF 文件' : '图片';
        $by    = isset($block['described_by']) && $block['described_by'] !== ''
            ? (string) $block['described_by']
            : '视觉模型';

        return '[' . $label . '「' . $name . '」的内容描述 —— 由 ' . $by
            . ' 查看后生成，当前模型无法直接查看该文件]' . "\n" . $text;
    }

    /**
     * 给模型看的降级说明
     *
     * 措辞是刻意的：只陈述「上传了什么」和「我看不到」,不给任何暗示模型
     * 已经看到内容的措辞。
     *
     * @param array<string, mixed> $block
     * @param string $extra
     * @return string
     */
    protected static function noticeFor(array $block, $extra = '')
    {
        $name  = isset($block['name']) && $block['name'] !== '' ? (string) $block['name'] : '未命名文件';
        $media = isset($block['media']) ? (string) $block['media'] : 'image';
        $label = $media === 'pdf' ? 'PDF 文件' : '图片';

        return '[系统提示] 用户上传了' . $label . '「' . $name . '」,'
            . '但当前模型无法查看' . $label . '内容' . $extra . '。'
            . '请不要臆测其内容;如果需要,请告诉用户换用支持该类型的模型。';
    }

    /**
     * 协议家族归一化
     *
     * @param string $family
     * @return string
     */
    public static function normalizeFamily($family)
    {
        $family = strtolower(trim((string) $family));
        if ($family === 'anthropic' || $family === 'claude') {
            return self::FAMILY_ANTHROPIC;
        }
        return self::FAMILY_OPENAI;
    }
}
