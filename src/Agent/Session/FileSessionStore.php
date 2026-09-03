<?php
namespace Ai\Agent\Session;

use Ai\Helpers\Path;

/**
 * 文件会话存储
 *
 * 将会话保存到文件系统，每个会话一个 JSON 文件。
 * 适合单机部署、PHP-FPM 场景。
 *
 * 安全加固（见 dev.md 第四/八/十九节）：
 *  - 文件名用 Path::safeName()：非法字符清洗后再附原 id 的 SHA-256 短散列，
 *    避免 `a/b`、`a.b`、`a b` 清洗后落到同一文件名而串号 / 目录穿越。
 *  - 原子写：先写临时文件再 rename，读者不会看到半个 JSON。
 *  - 损坏文件严格区分「不存在」与「存在但解析失败」：后者改名保留残骸，
 *    绝不当成不存在而被下一次 save 覆盖（那是数据丢失）。
 *  - 旧文件名（无散列后缀）仍可读，既有用户不被强制迁移。
 *
 * 用法：
 * ```php
 * $store = new FileSessionStore('/tmp/agent_sessions');
 * $store->save($session);
 * $loaded = $store->load($sessionId);
 * ```
 */
class FileSessionStore implements AgentSessionStoreInterface
{
    /** @var string 存储目录 */
    protected $dir;

    /**
     * @param string $dir 存储目录
     *
     * 构造不再预建目录：保持只读零副作用（见 dev.md 第十一节）。目录在首次
     * save() 时由 Path::atomicWrite() 惰性创建（0700）；load 未命中返回 null。
     */
    public function __construct($dir)
    {
        $this->dir = rtrim(str_replace('\\', '/', (string) $dir), '/') . '/';
    }

    /**
     * 当前（安全）文件名路径
     *
     * @param string $id
     * @return string
     */
    protected function filePath($id)
    {
        return $this->dir . Path::safeName((string) $id) . '.json';
    }

    /**
     * 旧版文件名路径（仅清洗、无散列后缀），用于向后兼容读取
     *
     * @param string $id
     * @return string
     */
    protected function legacyPath($id)
    {
        $safe = preg_replace('/[^a-zA-Z0-9\-_]/', '_', (string) $id);
        if (!is_string($safe) || $safe === '') {
            $safe = 'id';
        }
        return $this->dir . $safe . '.json';
    }

    /**
     * 加载会话
     *
     * 优先新文件名，回退旧文件名。区分「文件不存在」（返回 null，正常）
     * 与「文件存在但 JSON 解析失败」（改名保留残骸后返回 null，不静默丢弃）。
     *
     * @param string $id
     * @return AgentSession|null
     */
    public function load($id)
    {
        $path = $this->filePath($id);
        if (!is_file($path)) {
            $legacy = $this->legacyPath($id);
            if ($legacy !== $path && is_file($legacy)) {
                $path = $legacy;
            } else {
                return null;  // 确实不存在
            }
        }
        if (!is_readable($path)) {
            \Ai\Helpers\Log::error('会话文件不可读', ['path' => $path]);
            return null;
        }
        $json = @file_get_contents($path);
        if ($json === false) {
            \Ai\Helpers\Log::error('会话文件读取失败', ['path' => $path]);
            return null;
        }
        if (trim($json) === '') {
            return null;  // 空文件视为不存在（尚未写入）
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            // 存在但解析失败：保留残骸，绝不当成不存在
            $corrupt = $path . '.corrupt.' . time();
            @rename($path, $corrupt);
            \Ai\Helpers\Log::error('会话文件解析失败，已改名保留残骸', [
                'path' => $path, 'corrupt' => $corrupt,
            ]);
            return null;
        }
        return new AgentSession($id, $data);
    }

    /**
     * 保存会话（原子写）
     *
     * @param AgentSession $session
     * @return void
     */
    public function save(AgentSession $session)
    {
        $path = $this->filePath($session->getId());
        $json = json_encode($session->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            \Ai\Helpers\Log::error('会话序列化失败', ['id' => $session->getId()]);
            return;
        }
        Path::atomicWrite($path, $json, 0700);
    }

    /**
     * 删除会话（新旧文件名一并删）
     *
     * @param string $id
     * @return void
     */
    public function delete($id)
    {
        foreach ([$this->filePath($id), $this->legacyPath($id)] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
