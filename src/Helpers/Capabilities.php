<?php
namespace Ai\Helpers;

/**
 * 扩展能力标识
 *
 * 对话（chat）是本库的基础能力，不在此列——它由 ProtocolInterface 的
 * buildRequest() / parseResponse() 直接承载，无需声明。
 * 这里登记的是对话之外的能力，由协议类通过 capabilities() 自行声明支持哪些。
 *
 * 用常量而不是裸字符串，是为了让拼写错误在静态分析阶段就暴露，
 * 而不是等到运行时抛「协议不支持 imgae 能力」这种查半天的错。
 */
class Capabilities
{
    /** 文生图 / 图生图 */
    const IMAGE = 'image';

    /** 图像编辑（图生图 / 局部重绘），与 IMAGE 分开是因为多数平台走的是另一个端点 */
    const IMAGE_EDIT = 'image_edit';

    /** 语音合成（文本 → 音频） */
    const TTS = 'tts';

    /** 语音识别（音频 → 文本） */
    const ASR = 'asr';

    /** 文生视频 / 图生视频 */
    const VIDEO = 'video';

    /** 文本向量化 */
    const EMBEDDING = 'embedding';

    /** WebSocket 实时通道 */
    const REALTIME = 'realtime';

    /**
     * 全部已登记的能力
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::IMAGE,
            self::IMAGE_EDIT,
            self::TTS,
            self::ASR,
            self::VIDEO,
            self::EMBEDDING,
            self::REALTIME,
        ];
    }

    /**
     * 能力标识的中文名，用于错误提示
     */
    public static function label(string $capability): string
    {
        $labels = [
            self::IMAGE      => '图像生成',
            self::IMAGE_EDIT => '图像编辑',
            self::TTS       => '语音合成',
            self::ASR       => '语音识别',
            self::VIDEO     => '视频生成',
            self::EMBEDDING => '文本向量化',
            self::REALTIME  => '实时通道',
        ];
        return isset($labels[$capability]) ? $labels[$capability] : $capability;
    }

    /**
     * 是否是已登记的能力标识
     */
    public static function isValid(string $capability): bool
    {
        return in_array($capability, self::all(), true);
    }
}
