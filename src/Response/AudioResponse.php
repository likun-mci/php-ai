<?php
namespace Ai\Response;

use Ai\Contracts\CapabilityResponseInterface;
use Ai\Helpers\Capabilities;
use Ai\Helpers\Media;
use Ai\Response\Concerns\HasRawPayload;

/**
 * 语音响应（TTS 与 ASR 共用）
 *
 * 两个方向共用一个类，是因为它们的载荷是同一件事的两面：
 * TTS 产出音频字节（getBytes / saveTo），ASR 产出文本（getText）。
 * 用 getCapability() 区分方向，避免造两个八成相同的类。
 */
class AudioResponse implements CapabilityResponseInterface
{
    use HasRawPayload;

    /** @var string 音频原始字节（TTS） */
    protected $bytes = '';
    /** @var string 识别出的文本（ASR） */
    protected $text = '';
    /** @var string 音频格式，如 mp3 / wav */
    protected $format = '';
    /** @var string tts 或 asr */
    protected $capability = Capabilities::TTS;

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $usage
     */
    public function __construct(
        string $capability = Capabilities::TTS,
        string $bytes = '',
        string $text = '',
        string $format = '',
        array $raw = [],
        string $model = '',
        array $usage = [],
        string $error = ''
    ) {
        $this->capability = $capability;
        $this->bytes      = $bytes;
        $this->text       = $text;
        $this->format     = $format;
        $this->fillCommon($raw, $model, $usage, $error);
    }

    public function getCapability(): string
    {
        return $this->capability;
    }

    /**
     * 音频原始字节（TTS 方向）
     */
    public function getBytes(): string
    {
        return $this->bytes;
    }

    /**
     * 识别文本（ASR 方向）
     */
    public function getText(): string
    {
        return $this->text;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * 音频字节数，ASR 方向为 0
     */
    public function getSize(): int
    {
        return strlen($this->bytes);
    }

    /**
     * 保存音频到文件，返回绝对路径
     *
     * @param string $path 目标文件路径，所在目录必须已存在
     * @throws \Ai\Exceptions\RequestException 无音频数据、目录不存在或写入失败
     */
    public function saveTo(string $path): string
    {
        if ($this->bytes === '') {
            throw new \Ai\Exceptions\RequestException(
                '没有音频数据可保存'
                . ($this->capability === Capabilities::ASR
                    ? '（当前是语音识别响应，请用 getText() 取文本）'
                    : '（TTS 返回为空，请检查 getError()）'),
                '',
                'audio_empty',
                []
            );
        }
        return Media::write($path, $this->bytes);
    }
}
