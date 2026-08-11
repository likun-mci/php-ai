<?php
namespace Ai\Helpers;

use Ai\Exceptions\RequestException;
use Ai\Tools\HttpFetch;

/**
 * 媒体文件的下载与落盘
 *
 * 图像/语音/视频接口大量返回 URL 而非字节，本类负责把它们安全地取回来。
 *
 * 下载**一律走 HttpFetch**，不用 file_get_contents()——HttpFetch 带完整的
 * SSRF 防护链（IP 钉死、逐跳重校验、协议白名单）。平台返回的 URL 属于外部输入，
 * 被污染时可能指向内网地址，裸抓等于给自己开一个内网探测接口。
 *
 * 唯一需要调整的是体积上限：HttpFetch 默认 1.5MB 是给网页正文用的，
 * 对媒体文件远远不够，不放大会**静默截断**成一个损坏文件。
 */
class Media
{
    /** 默认下载上限 64MB，够覆盖图片与常规时长的音视频 */
    const DEFAULT_MAX_BYTES = 67108864;

    /**
     * 下载媒体文件，返回原始字节
     *
     * @param string $url
     * @param int    $maxBytes 体积上限，超过即视为失败（而不是截断）
     * @return string 原始字节
     * @throws RequestException 下载失败或超限
     */
    public static function download(string $url, int $maxBytes = self::DEFAULT_MAX_BYTES): string
    {
        $fetcher = new HttpFetch([
            'max_bytes' => $maxBytes,
            'timeout'   => 120,   // 视频文件可能较大，给足时间
        ]);
        $res = $fetcher->fetch($url);

        if (empty($res['ok'])) {
            throw new RequestException(
                '媒体文件下载失败：' . (string) $res['error'] . '（URL: ' . $url . '）',
                '',
                'media_download_failed',
                []
            );
        }

        $body = (string) $res['body'];
        if ($body === '') {
            throw new RequestException(
                '媒体文件下载结果为空（URL: ' . $url . '）',
                '',
                'media_download_empty',
                []
            );
        }

        // HttpFetch 到达上限时是截断返回而非报错，这里补一道判断：
        // 拿到一个刚好等于上限的文件，几乎可以肯定是被截断的残片，
        // 让它当场失败，好过用户存下一个打不开的文件
        if (strlen($body) >= $maxBytes) {
            throw new RequestException(
                sprintf('媒体文件超过 %d 字节上限，已中止下载以免存下残缺文件（URL: %s）', $maxBytes, $url),
                '',
                'media_too_large',
                []
            );
        }

        return $body;
    }

    /**
     * 把字节写入文件，返回绝对路径
     *
     * 目录不存在时**直接报错而不是自动创建**：多模态接口常在循环里落盘，
     * 路径拼错时自动 mkdir 会在磁盘上散落一堆空目录，且要等很久才被发现。
     *
     * @throws RequestException 目录不存在、不可写或写入失败
     */
    public static function write(string $path, string $bytes): string
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            throw new RequestException(
                "目录不存在：{$dir}（请先自行创建；本库不会自动创建目录，以免路径写错时散落空目录）",
                '',
                'dir_not_found',
                []
            );
        }
        if (!is_writable($dir)) {
            throw new RequestException("目录不可写：{$dir}", '', 'dir_not_writable', []);
        }

        $written = @file_put_contents($path, $bytes);
        if ($written === false || $written !== strlen($bytes)) {
            throw new RequestException(
                "文件写入失败或不完整：{$path}（预期 " . strlen($bytes) . ' 字节，实际写入 '
                . ($written === false ? '失败' : $written) . '）',
                '',
                'file_write_failed',
                []
            );
        }

        $real = realpath($path);
        return $real !== false ? $real : $path;
    }

    /**
     * 由 MIME 类型推断文件扩展名（不含点），推断不出返回空串
     */
    public static function extensionOf(string $contentType): string
    {
        $type = strtolower(trim($contentType));
        // 去掉 "; charset=utf-8" 之类的参数
        $pos = strpos($type, ';');
        if ($pos !== false) {
            $type = trim(substr($type, 0, $pos));
        }

        $map = [
            'audio/mpeg'      => 'mp3',
            'audio/mp3'       => 'mp3',
            'audio/wav'       => 'wav',
            'audio/x-wav'     => 'wav',
            'audio/wave'      => 'wav',
            'audio/ogg'       => 'ogg',
            'audio/opus'      => 'opus',
            'audio/flac'      => 'flac',
            'audio/aac'       => 'aac',
            'audio/pcm'       => 'pcm',
            'image/png'       => 'png',
            'image/jpeg'      => 'jpg',
            'image/jpg'       => 'jpg',
            'image/webp'      => 'webp',
            'image/gif'       => 'gif',
            'image/bmp'       => 'bmp',
            'video/mp4'       => 'mp4',
            'video/mpeg'      => 'mpeg',
            'video/webm'      => 'webm',
            'video/quicktime' => 'mov',
        ];
        return isset($map[$type]) ? $map[$type] : '';
    }

    /**
     * 判断 MIME 类型是否属于二进制媒体（音频 / 图像 / 视频 / 未知字节流）
     *
     * 传输层用它决定要不要跳过 json_decode。**刻意用白名单而非黑名单**：
     * 只有确定是媒体才走原始字节路径，text/html 之类一律维持原有的
     * json_decode 行为，确保既有对话链路逐字节不变。
     */
    public static function isBinaryContentType(string $contentType): bool
    {
        $type = strtolower(trim($contentType));
        $pos = strpos($type, ';');
        if ($pos !== false) {
            $type = trim(substr($type, 0, $pos));
        }
        if ($type === '') {
            return false;
        }

        if (strpos($type, 'audio/') === 0 || strpos($type, 'image/') === 0 || strpos($type, 'video/') === 0) {
            return true;
        }
        return $type === 'application/octet-stream';
    }
}
