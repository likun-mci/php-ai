<?php
namespace Ai\Agent\Media;

/**
 * 媒体引用（值对象）
 *
 * Conversation 里保存的是**引用**而不是二进制：一张 5 MB 的图 base64 后约 6.67 MB，
 * 直接写进会话 JSONL 会让每次 load/save 都搬运这几 MB，多图长会话很快就不可用了。
 * 所以消息里只留 `media://<id>`，真实字节交给 MediaStore，请求边界上才解析。
 *
 * ```php
 * $ref = new MediaReference([
 *     'id'    => '01j7x…',
 *     'media' => MediaReference::IMAGE,
 *     'mime'  => 'image/png',
 *     'name'  => 'screenshot.png',
 *     'bytes' => 20480,
 * ]);
 * $ref->uri();      // media://01j7x…
 * $ref->toBlock();  // ['type' => 'agent_media', …]
 * ```
 *
 * 注：不用类型化属性，保持 PHP 7.1 兼容（库的版本下限）。
 */
class MediaReference
{
    /** 图片 */
    const IMAGE = 'image';

    /** PDF —— 与图片分开：多数平台走的是不同的块类型，能力也不同（设计文档 §15） */
    const PDF = 'pdf';

    /** 内部块类型名。发给模型前必被 MediaTranslator 翻译掉，不会原样出现在请求里 */
    const BLOCK_TYPE = 'agent_media';

    /** URI 前缀 */
    const SCHEME = 'media://';

    /** @var string 存储 id */
    protected $id = '';

    /** @var string 媒体大类 image / pdf */
    protected $media = self::IMAGE;

    /** @var string MIME */
    protected $mime = '';

    /** @var string 原始文件名（供 UI 与日志显示） */
    protected $name = '';

    /** @var int 字节数 */
    protected $bytes = 0;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->id    = isset($data['id']) ? (string) $data['id'] : '';
        $this->mime  = isset($data['mime']) ? (string) $data['mime'] : '';
        $this->name  = isset($data['name']) ? (string) $data['name'] : '';
        $this->bytes = isset($data['bytes']) ? (int) $data['bytes'] : 0;

        $media = isset($data['media']) ? (string) $data['media'] : '';
        if ($media === '') {
            $media = self::mediaOfMime($this->mime);
        }
        $this->media = $media;
    }

    /**
     * 从 MIME 推断媒体大类
     *
     * @param string $mime
     * @return string image / pdf；认不出返回空串
     */
    public static function mediaOfMime($mime)
    {
        $mime = strtolower(trim((string) $mime));
        if ($mime === 'application/pdf') {
            return self::PDF;
        }
        if (strpos($mime, 'image/') === 0) {
            return self::IMAGE;
        }
        return '';
    }

    /** @return string */
    public function getId()
    {
        return $this->id;
    }

    /** @return string */
    public function getMedia()
    {
        return $this->media;
    }

    /** @return string */
    public function getMime()
    {
        return $this->mime;
    }

    /** @return string */
    public function getName()
    {
        return $this->name;
    }

    /** @return int */
    public function getBytes()
    {
        return $this->bytes;
    }

    /** `media://<id>`
     * @return string
     */
    public function uri()
    {
        return self::SCHEME . $this->id;
    }

    /**
     * 从 `media://<id>` 取出 id
     *
     * @param string $uri
     * @return string 不是合法 media URI 时返回空串
     */
    public static function idOfUri($uri)
    {
        $uri = (string) $uri;
        if (strpos($uri, self::SCHEME) !== 0) {
            return '';
        }
        return substr($uri, strlen(self::SCHEME));
    }

    /**
     * 转成放进 Conversation 的内部块
     *
     * **不含 base64** —— 这是整个设计的核心约定（设计文档 §3）。
     *
     * @return array<string, mixed>
     */
    public function toBlock()
    {
        return [
            'type'  => self::BLOCK_TYPE,
            'media' => $this->media,
            'mime'  => $this->mime,
            'name'  => $this->name,
            'bytes' => $this->bytes,
            'ref'   => $this->uri(),
        ];
    }

    /**
     * 从 Conversation 里的块还原
     *
     * @param array<string, mixed> $block
     * @return self|null 不是合法 agent_media 块时返回 null
     */
    public static function fromBlock($block)
    {
        if (!is_array($block) || !isset($block['type']) || $block['type'] !== self::BLOCK_TYPE) {
            return null;
        }
        $id = isset($block['ref']) ? self::idOfUri($block['ref']) : '';
        if ($id === '') {
            return null;
        }
        return new self([
            'id'    => $id,
            'media' => isset($block['media']) ? $block['media'] : '',
            'mime'  => isset($block['mime']) ? $block['mime'] : '',
            'name'  => isset($block['name']) ? $block['name'] : '',
            'bytes' => isset($block['bytes']) ? $block['bytes'] : 0,
        ]);
    }

    /**
     * 某个块是不是 agent_media
     *
     * @param mixed $block
     * @return bool
     */
    public static function isBlock($block)
    {
        return is_array($block) && isset($block['type']) && $block['type'] === self::BLOCK_TYPE;
    }

    /** @return array<string, mixed> */
    public function toArray()
    {
        return [
            'id'    => $this->id,
            'media' => $this->media,
            'mime'  => $this->mime,
            'name'  => $this->name,
            'bytes' => $this->bytes,
        ];
    }
}
