<?php
namespace Ai\Agent\Capability;

/**
 * 输入模态标识
 *
 * 与 `Ai\Helpers\Capabilities` 的区别要分清：那个登记的是**生成类**能力
 * （文生图、TTS、ASR、视频、向量），由协议类的 `capabilities()` 声明，
 * 走的是各自独立的端点。这里登记的是**输入**模态——同一个对话端点能不能
 * 吃下图片/PDF，属于模型本身的能力，两者不是一回事。
 *
 * 为什么不复用 `BaseModel::$features`：那份数据不可用。40 个平台里 39 个走
 * `CustomModel`，它的默认值是乐观的 `['chat','stream','function_calling','vision','attachments']`
 * ——`supports('vision')` 几乎恒真；而真正多模态的 `Gemini25Pro` 只声明了
 * `['chat']`，`GPT41` 也没声明 vision。既有假阳性又有假阴性，拿来做路由判断
 * 会把图片发给看不了图的模型，或者反过来放着能用的模型不用。
 *
 * 注：不用 enum（PHP 8.1 才有），常量即可，保持 PHP 7.1 兼容。
 */
class Modalities
{
    /** 文本输入——所有对话模型都支持，列在这里是为了让结构完整 */
    const TEXT = 'text';

    /** 图片输入（视觉） */
    const IMAGE = 'image';

    /** PDF 原生输入。与图片分开：多数平台走的是不同的块类型，能力也不同（设计文档 §15） */
    const PDF = 'pdf';

    /**
     * 全部输入模态
     *
     * @return string[]
     */
    public static function all()
    {
        return [self::TEXT, self::IMAGE, self::PDF];
    }

    /**
     * 除文本外的模态（真正需要判断能力的那些）
     *
     * @return string[]
     */
    public static function rich()
    {
        return [self::IMAGE, self::PDF];
    }

    /**
     * @param string $modality
     * @return bool
     */
    public static function isValid($modality)
    {
        return in_array((string) $modality, self::all(), true);
    }

    /**
     * 中文名（给报错信息和日志用）
     *
     * @param string $modality
     * @return string
     */
    public static function label($modality)
    {
        switch ((string) $modality) {
            case self::TEXT:
                return '文本';
            case self::IMAGE:
                return '图片';
            case self::PDF:
                return 'PDF';
            default:
                return (string) $modality;
        }
    }
}
