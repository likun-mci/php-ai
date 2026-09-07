<?php
namespace Ai\Agent\Media;

/**
 * 媒体门面 —— 校验、存储、解析三件事的统一入口
 *
 * Agent 只跟它打交道，不直接碰 Store / Resolver：
 *
 * ```php
 * $manager = new MediaManager($store);
 * $blocks  = $manager->ingest([Attachment::fromPath('/uploads/a.png')]);
 * // → [['type' => 'agent_media', 'ref' => 'media://…', …]]
 * ```
 *
 * 三级体积限制（设计文档 §19），默认值：
 *
 * | 层级 | 默认 | 作用 |
 * |---|---|---|
 * | `max_attachment_bytes` | 5 MB | 单个文件 |
 * | `max_message_bytes` | 10 MB | 单条消息的全部附件之和 |
 * | `max_attachments_per_message` | 8 | 单条消息的附件个数 |
 * | `max_request_bytes` | 20 MB | 单次模型请求的媒体总量（交给 MediaResolver 把关） |
 *
 * 只有一个总限制是不够的：5 MB 单文件很合理，但十个一起发就是必然失败的请求，
 * 而且失败发生在平台侧、错误信息通常很难懂。
 *
 * 注：不用类型化属性，保持 PHP 7.1 兼容（库的版本下限）。
 */
class MediaManager
{
    /** @var MediaStoreInterface */
    protected $store;

    /** @var MediaResolver */
    protected $resolver;

    /** @var int 单文件上限 */
    protected $maxAttachmentBytes = 5242880;

    /** @var int 单条消息全部附件之和 */
    protected $maxMessageBytes = 10485760;

    /** @var int 单条消息附件个数 */
    protected $maxAttachmentsPerMessage = 8;

    /** @var string[] fromPath 的目录白名单；空表示不限制（仍有 MIME 校验兜底） */
    protected $allowedPaths = [];

    /**
     * @param MediaStoreInterface $store
     * @param array<string, mixed> $options
     */
    public function __construct(MediaStoreInterface $store, array $options = [])
    {
        $this->store = $store;

        if (isset($options['max_attachment_bytes'])) {
            $this->maxAttachmentBytes = max(1, (int) $options['max_attachment_bytes']);
        }
        if (isset($options['max_message_bytes'])) {
            $this->maxMessageBytes = max(1, (int) $options['max_message_bytes']);
        }
        if (isset($options['max_attachments_per_message'])) {
            $this->maxAttachmentsPerMessage = max(1, (int) $options['max_attachments_per_message']);
        }
        if (isset($options['allowed_paths']) && is_array($options['allowed_paths'])) {
            $this->allowedPaths = array_values(array_map('strval', $options['allowed_paths']));
        }

        $resolverOptions = [];
        if (isset($options['max_request_bytes'])) {
            $resolverOptions['max_request_bytes'] = (int) $options['max_request_bytes'];
        }
        $this->resolver = new MediaResolver($store, $resolverOptions);
    }

    /** @return MediaStoreInterface */
    public function store()
    {
        return $this->store;
    }

    /** @return MediaResolver */
    public function resolver()
    {
        return $this->resolver;
    }

    /**
     * 把一批附件校验、落库，产出可以放进 Conversation 的块
     *
     * @param array<int, Attachment|array<string, mixed>|string> $attachments
     *        Attachment 对象，或路径字符串（自动 fromPath）
     * @return array<int, array<string, mixed>> agent_media 块
     * @throws MediaException 任一附件不合法即整体失败——部分成功会让用户以为全传上去了
     */
    public function ingest(array $attachments)
    {
        if ($attachments === []) {
            return [];
        }
        if (count($attachments) > $this->maxAttachmentsPerMessage) {
            throw new MediaException(sprintf(
                '一条消息最多 %d 个附件，收到 %d 个',
                $this->maxAttachmentsPerMessage,
                count($attachments)
            ));
        }

        $limits = [
            'max_bytes'     => $this->maxAttachmentBytes,
            'allowed_paths' => $this->allowedPaths,
        ];

        // 先全部校验通过再落库：一半落库一半失败会留下永远没人引用的孤儿文件
        $prepared = [];
        $total    = 0;
        foreach ($attachments as $item) {
            $attachment = $this->toAttachment($item);
            $bytes      = $attachment->bytes($limits);
            $mime       = $attachment->detectMime($bytes);

            $total += strlen($bytes);
            if ($total > $this->maxMessageBytes) {
                throw new MediaException(sprintf(
                    '本条消息的附件总量 %s 超过上限 %s',
                    Attachment::humanBytes($total),
                    Attachment::humanBytes($this->maxMessageBytes)
                ));
            }

            $prepared[] = ['bytes' => $bytes, 'mime' => $mime, 'name' => $attachment->getName()];
        }

        $blocks = [];
        foreach ($prepared as $item) {
            $ref = $this->store->put($item['bytes'], [
                'mime' => $item['mime'],
                'name' => $item['name'],
            ]);
            $blocks[] = $ref->toBlock();
        }
        return $blocks;
    }

    /**
     * @param Attachment|array<string, mixed>|string $item
     * @return Attachment
     */
    protected function toAttachment($item)
    {
        if ($item instanceof Attachment) {
            return $item;
        }
        if (is_string($item)) {
            // 字符串按来源自动判断：像 URL 就当 URL，否则当本地路径
            return preg_match('#^https?://#i', $item) === 1
                ? Attachment::fromUrl($item)
                : Attachment::fromPath($item);
        }
        if (is_array($item)) {
            if (isset($item['path'])) {
                return Attachment::fromPath((string) $item['path'], $item);
            }
            if (isset($item['url'])) {
                return Attachment::fromUrl((string) $item['url'], $item);
            }
            if (isset($item['data'])) {
                return Attachment::fromBase64(
                    (string) $item['data'],
                    isset($item['mime']) ? (string) $item['mime'] : '',
                    isset($item['name']) ? (string) $item['name'] : 'attachment'
                );
            }
        }
        throw new MediaException('无法识别的附件写法，请用 Attachment::fromPath() / fromUrl() / fromBase64()');
    }

    /**
     * 清理早于 N 天的媒体（显式调用，库不自动跑）
     *
     * @param int $olderThanDays
     * @return int
     */
    public function prune($olderThanDays)
    {
        return $this->store->prune($olderThanDays);
    }

    /**
     * 按会话里还活着的引用回收
     *
     * @param array<int, array<string, mixed>> $messages 仍然存在的全部消息
     * @return int
     */
    public function gcByMessages(array $messages)
    {
        return $this->store->gc(MediaResolver::idsInMessages($messages));
    }
}
