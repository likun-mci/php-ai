<?php
namespace Ai\Response;

use Ai\Contracts\CapabilityResponseInterface;
use Ai\Helpers\Capabilities;
use Ai\Helpers\Media;
use Ai\Response\Concerns\HasRawPayload;

/**
 * 视频生成响应
 *
 * 视频接口无一例外都是异步任务式，本类是任务完成后的结果载体，
 * 通常由 \Ai\Task\AsyncTask::getResult() 返回，不直接构造。
 */
class VideoResponse implements CapabilityResponseInterface
{
    use HasRawPayload;

    /** @var string */
    protected $url = '';
    /** @var string 封面图地址，部分平台提供 */
    protected $coverUrl = '';
    /** @var float 时长（秒），未知为 0 */
    protected $duration = 0.0;
    /** @var string 视频原始字节。部分平台直接回内容而不是下载地址 */
    protected $bytes = '';

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $usage
     */
    public function __construct(
        string $url = '',
        string $coverUrl = '',
        float $duration = 0.0,
        array $raw = [],
        string $model = '',
        array $usage = [],
        string $error = ''
    ) {
        $this->url      = $url;
        $this->coverUrl = $coverUrl;
        $this->duration = $duration;
        $this->fillCommon($raw, $model, $usage, $error);
    }

    public function getCapability(): string
    {
        return Capabilities::VIDEO;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getCoverUrl(): string
    {
        return $this->coverUrl;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    /**
     * 视频原始字节
     *
     * 部分平台的任务结果**不给下载地址、直接回字节**
     * （如 Gemini 的 /videos/{id}/content，属 Sora 兼容形态）。
     * 这类平台的内容还要带鉴权头才能取，走不了公开 URL 下载那条路。
     */
    public function getBytes(): string
    {
        return $this->bytes;
    }

    /**
     * 注入视频字节
     *
     * 用 setter 而不是加构造参数：构造函数已经发布，改参数表是本项目的红线
     * （v1.8.0 给 AIResponse::cost() 加参数导致子类 Fatal 的教训）。
     * 纯新增方法则零风险。
     */
    public function setBytes(string $bytes): self
    {
        $this->bytes = $bytes;
        return $this;
    }

    /**
     * 下载视频到本地，返回绝对路径
     *
     * ⚠️ 视频 URL 的有效期通常只有 24 小时，且文件较大。
     * 生成后应尽快落地，不要只把 URL 存进库。
     *
     * @param string $path     目标文件路径，所在目录必须已存在
     * @param int    $maxBytes 体积上限，默认 64MB。长视频需自行调大
     */
    public function saveTo(string $path, int $maxBytes = Media::DEFAULT_MAX_BYTES): string
    {
        // 已经拿到字节就直接落盘：这类平台（Sora 兼容形态）的内容端点
        // 要带鉴权头才能取，走不了 Media::download() 那条公开下载的路
        if ($this->bytes !== '') {
            return Media::write($path, $this->bytes);
        }

        if ($this->url === '') {
            throw new \Ai\Exceptions\RequestException(
                '没有视频地址也没有视频字节可保存（任务可能尚未完成，请先确认 AsyncTask::isDone()）',
                '',
                'video_url_empty',
                []
            );
        }
        return Media::write($path, Media::download($this->url, $maxBytes));
    }

    /**
     * 下载封面图到本地，返回绝对路径。无封面时返回空串
     */
    public function saveCoverTo(string $path): string
    {
        if ($this->coverUrl === '') {
            return '';
        }
        return Media::write($path, Media::download($this->coverUrl));
    }
}
