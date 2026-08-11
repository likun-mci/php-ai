<?php
namespace Ai\Response;

use Ai\Contracts\CapabilityResponseInterface;
use Ai\Helpers\Capabilities;
use Ai\Helpers\Media;
use Ai\Response\Concerns\HasRawPayload;

/**
 * 图像生成响应
 *
 * 各平台返回形态不统一：有的给 URL，有的给 base64，少数两者都给。
 * 本类把两种都收下，saveTo() 会自动选可用的那种，调用方不必分情况处理。
 */
class ImageResponse implements CapabilityResponseInterface, \Countable
{
    use HasRawPayload;

    /** @var array<int, string> */
    protected $urls = [];
    /** @var array<int, string> */
    protected $base64 = [];
    /** @var string 部分平台（如 DALL·E 3）会改写提示词，原样回传便于排查效果差异 */
    protected $revisedPrompt = '';

    /**
     * @param array<int, string>   $urls
     * @param array<int, string>   $base64
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $usage
     */
    public function __construct(
        array $urls = [],
        array $base64 = [],
        array $raw = [],
        string $model = '',
        array $usage = [],
        string $revisedPrompt = '',
        string $error = ''
    ) {
        $this->urls          = array_values($urls);
        $this->base64        = array_values($base64);
        $this->revisedPrompt = $revisedPrompt;
        $this->fillCommon($raw, $model, $usage, $error);
    }

    public function getCapability(): string
    {
        return Capabilities::IMAGE;
    }

    /**
     * @return array<int, string>
     */
    public function getUrls(): array
    {
        return $this->urls;
    }

    public function getUrl(int $index = 0): string
    {
        return isset($this->urls[$index]) ? $this->urls[$index] : '';
    }

    /**
     * base64 编码的图像数据（不含 data: 前缀）
     * @return array<int, string>
     */
    public function getBase64(): array
    {
        return $this->base64;
    }

    public function getRevisedPrompt(): string
    {
        return $this->revisedPrompt;
    }

    /**
     * 图片张数
     */
    public function count(): int
    {
        return max(count($this->urls), count($this->base64));
    }

    /**
     * 保存全部图片到目录，返回写入的绝对路径列表
     *
     * ⚠️ 多数平台返回的 URL **有效期只有几小时到 24 小时**，
     * 存 URL 进库第二天就会全部失效。要长期保留必须及时调用本方法落地。
     *
     * @param string $dir       目标目录，必须已存在
     * @param string $prefix    文件名前缀，默认按时间戳生成
     * @param string $extension 扩展名（不含点），留空则由 URL 或内容推断
     * @return array<int, string> 实际写入的绝对路径
     */
    public function saveTo(string $dir, string $prefix = '', string $extension = ''): array
    {
        $dir = rtrim($dir, "/\\");
        if ($prefix === '') {
            $prefix = 'img_' . date('YmdHis');
        }

        $paths = [];
        $total = $this->count();
        for ($i = 0; $i < $total; $i++) {
            // base64 优先：已经在手里，不必再发一次网络请求，也不受 URL 过期影响
            if (isset($this->base64[$i]) && $this->base64[$i] !== '') {
                $bytes = base64_decode($this->base64[$i], true);
                if ($bytes === false) {
                    continue;
                }
                $ext = $extension !== '' ? $extension : 'png';
            } elseif (isset($this->urls[$i]) && $this->urls[$i] !== '') {
                $bytes = Media::download($this->urls[$i]);
                $ext = $extension !== '' ? $extension : $this->guessExtFromUrl($this->urls[$i]);
            } else {
                continue;
            }

            $name = $total > 1 ? sprintf('%s_%d.%s', $prefix, $i + 1, $ext) : sprintf('%s.%s', $prefix, $ext);
            $paths[] = Media::write($dir . DIRECTORY_SEPARATOR . $name, $bytes);
        }

        return $paths;
    }

    /**
     * 从 URL 路径部分猜扩展名，猜不到默认 png
     */
    protected function guessExtFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp'], true) ? $ext : 'png';
    }
}
