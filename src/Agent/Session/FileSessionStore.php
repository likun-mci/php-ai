<?php
namespace Ai\Agent\Session;

/**
 * 文件会话存储
 *
 * 将会话保存到文件系统，每个会话一个 JSON 文件。
 * 适合单机部署、PHP-FPM 场景。
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
     * @param string $dir 存储目录，会在构造函数中自动创建
     */
    public function __construct($dir)
    {
        $this->dir = rtrim((string) $dir, '/') . '/';
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            \Ai\Helpers\Log::error('会话存储目录创建失败', ['dir' => $this->dir]);
        }
    }

    /**
     * @param string $id
     * @return string
     */
    protected function filePath($id)
    {
        // 安全文件名：只保留字母、数字、连字符、下划线
        $safe = preg_replace('/[^a-zA-Z0-9\-_]/', '_', (string) $id);
        return $this->dir . $safe . '.json';
    }

    /**
     * 加载会话
     *
     * @param string $id
     * @return AgentSession|null
     */
    public function load($id)
    {
        $path = $this->filePath($id);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $json = @file_get_contents($path);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        return new AgentSession($id, $data);
    }

    /**
     * 保存会话
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
        @file_put_contents($path, $json, LOCK_EX);
    }

    /**
     * 删除会话
     *
     * @param string $id
     * @return void
     */
    public function delete($id)
    {
        $path = $this->filePath($id);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}