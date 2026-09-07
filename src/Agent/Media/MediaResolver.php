<?php
namespace Ai\Agent\Media;

/**
 * 媒体解析器 —— 只在请求边界把引用换成真实字节
 *
 * Conversation 里存的是 `media://<id>`，发请求前才由它取出字节并 base64。
 * 这样多轮对话反复 load/save 会话时搬运的一直是短字符串，
 * 只有真正要发给模型的那一刻才付出内存代价。
 *
 * 还负责**单次请求的媒体总量上限**（设计文档 §19 的第三级）：
 * 单文件 5 MB 不算大，但十张一起发就是 50 MB，请求必然被平台拒。
 * 超限时抛异常而不是静默丢弃——静默丢弃会让模型以为自己看过了。
 *
 * ```php
 * $resolver = new MediaResolver($store, ['max_request_bytes' => 20971520]);
 * $data = $resolver->resolve($block);   // ['mime' => .., 'base64' => .., 'bytes' => ..]
 * $resolver->reset();                   // 每次请求开始时清零累计
 * ```
 *
 * 注：不用类型化属性，保持 PHP 7.1 兼容（库的版本下限）。
 */
class MediaResolver
{
    /** @var MediaStoreInterface */
    protected $store;

    /** @var int 单次请求的媒体字节上限（原始字节，非 base64 后） */
    protected $maxRequestBytes = 20971520;

    /** @var int 本次请求已累计的字节 */
    protected $used = 0;

    /** @var array<string, array<string, mixed>> id => 解析结果，同一请求内复用 */
    protected $cache = [];

    /**
     * @param MediaStoreInterface $store
     * @param array<string, mixed> $options max_request_bytes
     */
    public function __construct(MediaStoreInterface $store, array $options = [])
    {
        $this->store = $store;
        if (isset($options['max_request_bytes'])) {
            $this->maxRequestBytes = max(0, (int) $options['max_request_bytes']);
        }
    }

    /** @return MediaStoreInterface */
    public function store()
    {
        return $this->store;
    }

    /**
     * 每次请求开始时清零
     *
     * 不清零的话，一次 Agent 运行里跑 20 轮，第 20 轮会因为前 19 轮的累计而误报超限。
     *
     * @return $this
     */
    public function reset()
    {
        $this->used  = 0;
        $this->cache = [];
        return $this;
    }

    /** 本次请求已用字节
     * @return int
     */
    public function used()
    {
        return $this->used;
    }

    /**
     * 解析一个 agent_media 块
     *
     * @param array<string, mixed> $block
     * @return array{mime: string, media: string, name: string, bytes: int, base64: string}|null
     *         媒体不存在时返回 null（不抛——存储被清理过是正常情况，不该让整轮跑不下去）
     * @throws MediaException 超过单次请求上限
     */
    public function resolve(array $block)
    {
        $ref = MediaReference::fromBlock($block);
        if ($ref === null) {
            return null;
        }
        $id = $ref->getId();

        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        $raw = $this->store->get($id);
        if ($raw === null || $raw === '') {
            return null;
        }

        $size = strlen($raw);
        if ($this->maxRequestBytes > 0 && $this->used + $size > $this->maxRequestBytes) {
            throw new MediaException(sprintf(
                '本次请求的媒体总量超限：已用 %s，再加 %s 会超过上限 %s',
                Attachment::humanBytes($this->used),
                Attachment::humanBytes($size),
                Attachment::humanBytes($this->maxRequestBytes)
            ));
        }
        $this->used += $size;

        $mime = $ref->getMime();
        if ($mime === '') {
            $stat = $this->store->stat($id);
            if (is_array($stat) && isset($stat['mime'])) {
                $mime = (string) $stat['mime'];
            }
        }

        $out = [
            'mime'   => $mime,
            'media'  => $ref->getMedia() !== '' ? $ref->getMedia() : MediaReference::mediaOfMime($mime),
            'name'   => $ref->getName(),
            'bytes'  => $size,
            'base64' => base64_encode($raw),
        ];
        $this->cache[$id] = $out;
        return $out;
    }

    /**
     * 一批消息里出现的全部媒体 id（供 gc 统计「还活着的引用」）
     *
     * @param array<int, array<string, mixed>> $messages
     * @return string[]
     */
    public static function idsInMessages(array $messages)
    {
        $out = [];
        foreach ($messages as $msg) {
            if (!is_array($msg) || !isset($msg['content']) || !is_array($msg['content'])) {
                continue;
            }
            foreach ($msg['content'] as $block) {
                $ref = MediaReference::fromBlock($block);
                if ($ref !== null) {
                    $out[$ref->getId()] = true;
                }
            }
        }
        return array_keys($out);
    }
}
