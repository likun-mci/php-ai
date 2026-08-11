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
        if ($this->url === '') {
            throw new \Ai\Exceptions\RequestException(
                '没有视频地址可下载（任务可能尚未完成，请先确认 AsyncTask::isDone()）',
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
