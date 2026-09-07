<?php
namespace Ai\Agent\Media;

/**
 * 媒体存储接口
 *
 * Conversation 只存 `media://<id>`，真实字节归这里管。默认实现是本地文件
 * （`FileMediaStore`）；应用要接对象存储（S3/OSS）自己实现这个接口即可，
 * Agent 上层逻辑不用改。
 *
 * **回收责任在应用**：库不知道同一个 id 还被哪些会话引用，因此不做自动 GC。
 * `prune()` 与 `gc()` 提供显式回收手段，由应用在删会话、定期清理时调用。
 *
 * 注：接口方法不写 PHP 类型声明，保持 PHP 7.1 兼容（库的版本下限）。
 */
interface MediaStoreInterface
{
    /**
     * 存入一份媒体，返回引用
     *
     * @param string $bytes 原始字节
     * @param array<string, mixed> $meta mime / name
     * @return MediaReference
     */
    public function put($bytes, array $meta = []);

    /**
     * 取回原始字节
     *
     * @param string $id
     * @return string|null 不存在返回 null
     */
    public function get($id);

    /**
     * @param string $id
     * @return bool
     */
    public function has($id);

    /**
     * 取元数据（不读内容）
     *
     * @param string $id
     * @return array<string, mixed>|null
     */
    public function stat($id);

    /**
     * @param string $id
     * @return bool 是否真的删掉了
     */
    public function delete($id);

    /**
     * 清理早于 N 天的媒体
     *
     * @param int $olderThanDays
     * @return int 删除条数
     */
    public function prune($olderThanDays);

    /**
     * 按「还活着的引用」回收：不在名单里的一律删除
     *
     * @param string[] $liveIds 仍被引用的 id
     * @return int 删除条数
     */
    public function gc(array $liveIds);
}
