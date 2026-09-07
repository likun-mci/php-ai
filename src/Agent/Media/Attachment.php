<?php
namespace Ai\Agent\Media;

use Ai\Tools\HttpFetch;

/**
 * 附件 —— 用户侧的入口对象
 *
 * 三种来源，安全策略各不相同：
 *
 * ```php
 * Attachment::fromPath('/uploads/a.png');                 // 本地文件
 * Attachment::fromUrl('https://example.com/b.jpg');       // 远程，强制走 SSRF 防护
 * Attachment::fromBase64($data, 'image/png', 'c.png');    // 已在内存里的字节
 * ```
 *
 * 字节是**惰性读取**的：`fromPath()` 只记路径，直到 `bytes()` 被调用（通常是
 * MediaManager 要落库的那一刻）才真正读盘。一次 chat 传十个附件时，不会因为
 * 构造对象就把十个文件全读进内存。
 *
 * 安全（设计文档 §21 §22）：
 *   - `fromUrl()` **必须**经 `Ai\Tools\HttpFetch`——它已有 SSRF / DNS rebinding /
 *     私网与云元数据地址 / 重定向逐跳校验 / 体积上限那一整套防护。这里绝不自己 curl。
 *   - `fromPath()` 解析 realpath（挡 symlink 逃逸），可选目录白名单，
 *     并做 MIME 嗅探 + 扩展名双重校验——`/etc/passwd` 过不了 MIME 这关。
 *
 * 注：不用类型化属性，保持 PHP 7.1 兼容（库的版本下限）。
 */
class Attachment
{
    /** @var string 来源类型 path / url / base64 */
    protected $source = 'base64';

    /** @var string 本地路径或 URL（source 为 base64 时为空） */
    protected $location = '';

    /** @var string|null 已读到的原始字节；null 表示还没读 */
    protected $raw = null;

    /** @var string 调用方声明的 MIME（可能为空，落库时会用嗅探结果覆盖） */
    protected $mime = '';

    /** @var string 显示用文件名 */
    protected $name = '';

    /** @var array<string, mixed> 来源级选项（如 fromUrl 的超时） */
    protected $options = [];

    /** @var string[] 允许的图片扩展名 */
    protected static $imageExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'];

    /**
     * 扩展名 => 期望的 MIME 前缀，用于交叉校验
     *
     * 改了扩展名的文件（把 .php 改成 .png）会在这里被拦下。
     *
     * @var array<string, string>
     */
    protected static $mimeByExtension = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'bmp'  => 'image/bmp',
        'pdf'  => 'application/pdf',
    ];

    /**
     * @param array<string, mixed> $data
     */
    protected function __construct(array $data)
    {
        $this->source   = isset($data['source']) ? (string) $data['source'] : 'base64';
        $this->location = isset($data['location']) ? (string) $data['location'] : '';
        $this->mime     = isset($data['mime']) ? (string) $data['mime'] : '';
        $this->name     = isset($data['name']) ? (string) $data['name'] : '';
        $this->raw      = isset($data['raw']) ? (string) $data['raw'] : null;
        $this->options  = isset($data['options']) && is_array($data['options']) ? $data['options'] : [];
    }

    /**
     * 本地文件
     *
     * @param string $path
     * @param array<string, mixed> $options name / mime
     * @return self
     */
    public static function fromPath($path, array $options = [])
    {
        $path = (string) $path;
        if (trim($path) === '') {
            throw new MediaException('附件路径为空');
        }
        return new self([
            'source'   => 'path',
            'location' => $path,
            'name'     => isset($options['name']) ? $options['name'] : basename($path),
            'mime'     => isset($options['mime']) ? $options['mime'] : '',
            'options'  => $options,
        ]);
    }

    /**
     * 远程 URL —— 下载发生在 `bytes()` 时，且强制走 HttpFetch 的 SSRF 防护
     *
     * @param string $url
     * @param array<string, mixed> $options name / mime / max_bytes / timeout
     * @return self
     */
    public static function fromUrl($url, array $options = [])
    {
        $url = trim((string) $url);
        if ($url === '') {
            throw new MediaException('附件 URL 为空');
        }
        $name = isset($options['name']) ? (string) $options['name'] : '';
        if ($name === '') {
            $parsed = parse_url($url, PHP_URL_PATH);
            $name   = is_string($parsed) && $parsed !== '' ? basename($parsed) : 'download';
        }
        return new self([
            'source'   => 'url',
            'location' => $url,
            'name'     => $name,
            'mime'     => isset($options['mime']) ? $options['mime'] : '',
            'options'  => $options,
        ]);
    }

    /**
     * 已在内存里的字节
     *
     * @param string $data 原始字节或 base64 字符串（自动识别）
     * @param string $mime
     * @param string $name
     * @return self
     */
    public static function fromBase64($data, $mime = '', $name = 'attachment')
    {
        $data = (string) $data;
        if ($data === '') {
            throw new MediaException('附件内容为空');
        }

        // data:image/png;base64,xxxx 这种整段 data URI 也接受
        if (strpos($data, 'data:') === 0 && strpos($data, ',') !== false) {
            $comma  = strpos($data, ',');
            $header = substr($data, 5, $comma - 5);
            $data   = substr($data, $comma + 1);
            if ($mime === '') {
                $semi = strpos($header, ';');
                $mime = $semi === false ? $header : substr($header, 0, $semi);
            }
        }

        $decoded = base64_decode($data, true);
        // 严格模式解出来、再编码回去能对上，才认定原文是 base64；
        // 否则当成原始二进制（PNG 的字节流恰好也可能通过宽松解码）
        $raw = ($decoded !== false && base64_encode($decoded) === preg_replace('/\s+/', '', $data))
            ? $decoded
            : $data;

        return new self([
            'source' => 'base64',
            'raw'    => $raw,
            'mime'   => (string) $mime,
            'name'   => (string) $name,
        ]);
    }

    /** @return string path / url / base64 */
    public function getSource()
    {
        return $this->source;
    }

    /** @return string */
    public function getLocation()
    {
        return $this->location;
    }

    /** @return string */
    public function getName()
    {
        return $this->name;
    }

    /** 调用方声明的 MIME（未必准，落库时以嗅探为准）
     * @return string
     */
    public function getDeclaredMime()
    {
        return $this->mime;
    }

    /**
     * 取得原始字节（惰性读取，读一次后缓存）
     *
     * @param array<string, mixed> $limits max_bytes / allowed_paths
     * @return string
     * @throws MediaException 读不到、超限、被安全策略拒绝
     */
    public function bytes(array $limits = [])
    {
        if ($this->raw !== null) {
            $this->assertSize(strlen($this->raw), $limits);
            return $this->raw;
        }

        if ($this->source === 'path') {
            $this->raw = $this->readPath($limits);
        } elseif ($this->source === 'url') {
            $this->raw = $this->readUrl($limits);
        } else {
            throw new MediaException('附件没有内容');
        }

        $this->assertSize(strlen($this->raw), $limits);
        return $this->raw;
    }

    /**
     * @param array<string, mixed> $limits
     * @return string
     */
    protected function readPath(array $limits)
    {
        $real = realpath($this->location);
        if ($real === false || !is_file($real)) {
            throw new MediaException('附件文件不存在: ' . $this->location);
        }
        if (!is_readable($real)) {
            throw new MediaException('附件文件不可读: ' . $this->location);
        }

        // 目录白名单（可选的纵深防御）。realpath 已经把 symlink 解开，
        // 所以用软链指到白名单外面这条路是通不了的
        if (isset($limits['allowed_paths']) && is_array($limits['allowed_paths']) && $limits['allowed_paths']) {
            $ok = false;
            foreach ($limits['allowed_paths'] as $allowed) {
                $allowedReal = realpath((string) $allowed);
                if ($allowedReal !== false && strpos($real, rtrim($allowedReal, '/') . '/') === 0) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                throw new MediaException('附件路径不在允许的目录内: ' . $this->location);
            }
        }

        // 先看文件大小再读，避免为了报「太大了」而先把 100 MB 读进内存
        $size = @filesize($real);
        if ($size !== false) {
            $this->assertSize((int) $size, $limits);
        }

        $raw = @file_get_contents($real);
        if ($raw === false) {
            throw new MediaException('附件读取失败: ' . $this->location);
        }
        return $raw;
    }

    /**
     * @param array<string, mixed> $limits
     * @return string
     */
    protected function readUrl(array $limits)
    {
        $maxBytes = isset($limits['max_bytes']) ? (int) $limits['max_bytes'] : 5242880;

        $opts = ['max_bytes' => $maxBytes];
        if (isset($this->options['timeout'])) {
            $opts['timeout'] = (int) $this->options['timeout'];
        }

        $fetch = new HttpFetch($opts);
        $res   = $fetch->fetch($this->location);

        if (empty($res['ok'])) {
            // HttpFetch 的错误信息已经说清了是协议不允许、内网地址还是重定向问题
            throw new MediaException('附件下载失败: ' . (isset($res['error']) ? $res['error'] : '未知错误'));
        }
        if ($this->mime === '' && !empty($res['content_type'])) {
            $ct = (string) $res['content_type'];
            $semi = strpos($ct, ';');
            $this->mime = trim($semi === false ? $ct : substr($ct, 0, $semi));
        }
        return (string) $res['body'];
    }

    /**
     * @param int $size
     * @param array<string, mixed> $limits
     * @return void
     */
    protected function assertSize($size, array $limits)
    {
        $max = isset($limits['max_bytes']) ? (int) $limits['max_bytes'] : 0;
        if ($max > 0 && $size > $max) {
            throw new MediaException(sprintf(
                '附件 %s 体积 %s 超过单文件上限 %s',
                $this->name !== '' ? $this->name : '(未命名)',
                self::humanBytes($size),
                self::humanBytes($max)
            ));
        }
    }

    /**
     * 嗅探真实 MIME，并与扩展名交叉校验
     *
     * 顺序是「先信内容、再看扩展名」：改后缀名骗不过内容嗅探。
     * finfo 不可用时退回魔数判断，两条路都走不通才用调用方声明的值。
     *
     * @param string $raw
     * @return string
     * @throws MediaException 内容与扩展名冲突，或不是支持的类型
     */
    public function detectMime($raw)
    {
        $sniffed = self::sniffMime($raw);

        if ($sniffed === '') {
            // 嗅探不出来时才退回声明值；仍然要求它是支持的类型
            $declared = strtolower(trim($this->mime));
            if ($declared !== '' && MediaReference::mediaOfMime($declared) !== '') {
                return $declared;
            }
            throw new MediaException(
                '无法识别附件类型: ' . ($this->name !== '' ? $this->name : '(未命名)')
                . '（仅支持图片与 PDF）'
            );
        }

        if (MediaReference::mediaOfMime($sniffed) === '') {
            throw new MediaException(
                '不支持的附件类型 ' . $sniffed . '（仅支持图片与 PDF）'
            );
        }

        // 扩展名与内容冲突时拒绝——把 .php 改名成 .png 传上来就是这条路径
        $ext = strtolower((string) pathinfo($this->name, PATHINFO_EXTENSION));
        if ($ext !== '' && isset(self::$mimeByExtension[$ext])) {
            $expected = self::$mimeByExtension[$ext];
            if ($expected !== $sniffed && !self::mimeCompatible($expected, $sniffed)) {
                throw new MediaException(
                    '附件内容与扩展名不符: ' . $this->name
                    . '（扩展名指向 ' . $expected . '，实际是 ' . $sniffed . '）'
                );
            }
        }

        return $sniffed;
    }

    /**
     * 内容嗅探：优先 finfo，退回魔数
     *
     * @param string $raw
     * @return string 认不出返回空串
     */
    public static function sniffMime($raw)
    {
        if ($raw === '') {
            return '';
        }

        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->buffer($raw);
            if (is_string($mime) && $mime !== '' && $mime !== 'application/octet-stream') {
                return strtolower($mime);
            }
        }

        // finfo 没装时的兜底：认几个常见格式的魔数
        if (strpos($raw, "\x89PNG\r\n\x1a\n") === 0) {
            return 'image/png';
        }
        if (strpos($raw, "\xFF\xD8\xFF") === 0) {
            return 'image/jpeg';
        }
        if (strpos($raw, 'GIF87a') === 0 || strpos($raw, 'GIF89a') === 0) {
            return 'image/gif';
        }
        if (strpos($raw, 'RIFF') === 0 && substr($raw, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (strpos($raw, 'BM') === 0) {
            return 'image/bmp';
        }
        if (strpos($raw, '%PDF-') === 0) {
            return 'application/pdf';
        }
        return '';
    }

    /**
     * 两个 MIME 是否算同一种（jpg/jpeg 之类的别名）
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    protected static function mimeCompatible($a, $b)
    {
        $alias = [
            'image/jpg'  => 'image/jpeg',
            'image/pjpeg' => 'image/jpeg',
            'image/x-ms-bmp' => 'image/bmp',
        ];
        $a = isset($alias[$a]) ? $alias[$a] : $a;
        $b = isset($alias[$b]) ? $alias[$b] : $b;
        return $a === $b;
    }

    /**
     * @param int $bytes
     * @return string
     */
    public static function humanBytes($bytes)
    {
        $bytes = (int) $bytes;
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1048576, 1) . ' MB';
    }

    /** 支持的图片扩展名
     * @return string[]
     */
    public static function imageExtensions()
    {
        return self::$imageExtensions;
    }
}
