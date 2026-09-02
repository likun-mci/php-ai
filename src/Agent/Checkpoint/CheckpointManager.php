<?php
namespace Ai\Agent\Checkpoint;

/**
 * CheckpointManager——检查点管理器
 *
 * 每轮迭代结束时自动保存 checkpoint，Runtime 崩溃后可从最新 checkpoint 恢复。
 * 每个 checkpoint 以 JSON 文件持久化，按任务 ID 分组。
 *
 * 存储结构：
 *   {baseDir}/
 *   ├── {taskId}_checkpoint_1.json
 *   ├── {taskId}_checkpoint_2.json
 *   └── {taskId}_checkpoint_3.json
 *
 * Checkpoint 保存的内容：
 *   - iteration（当前轮次）
 *   - messages（完整消息历史）
 *   - extra（附加状态，如 budget、pendingPermission）
 *
 * 用法：
 * ```php
 * $cm = new CheckpointManager('/tmp/checkpoints');
 * $cm->save('task_1', 5, $messages, ['budget' => [...]]);
 * $latest = $cm->loadLatest('task_1');
 * // $latest->getIteration() => 5
 * ```
 */
class CheckpointManager
{
    /** @var string */
    protected $baseDir = '';

    /** @var bool */
    protected $enabled = true;

    /** @var int 每个任务保留的最大 checkpoint 数（默认 5，超出的旧 checkpoint 自动清理） */
    protected $maxCheckpoints = 5;

    /**
     * @param string $baseDir 存储目录
     * @param array<string, mixed> $options enabled, maxCheckpoints
     */
    public function __construct($baseDir = '', array $options = [])
    {
        if ($baseDir !== '') {
            $this->setBaseDir((string) $baseDir);
        }
        if (isset($options['enabled'])) {
            $this->enabled = (bool) $options['enabled'];
        }
        if (isset($options['maxCheckpoints'])) {
            $this->maxCheckpoints = (int) $options['maxCheckpoints'];
        }
    }

    /**
     * 设置存储目录
     *
     * @param string $dir
     * @return $this
     */
    public function setBaseDir($dir)
    {
        $this->baseDir = rtrim(str_replace('\\', '/', (string) $dir), '/');
        if (!is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0755, true);
        }
        return $this;
    }

    /** @return string */
    public function getBaseDir()
    {
        return $this->baseDir;
    }

    /**
     * 启用/停用
     *
     * @param bool $enabled
     * @return $this
     */
    public function setEnabled($enabled)
    {
        $this->enabled = (bool) $enabled;
        return $this;
    }

    /** @return bool */
    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * 保存检查点
     *
     * @param string $taskId 关联的任务 ID 或会话 ID
     * @param int $iteration 当前轮次
     * @param array<int, array<string, mixed>> $messages 消息历史
     * @param array<string, mixed> $extra 附加状态
     * @return string checkpoint ID
     */
    public function save($taskId, $iteration, array $messages, array $extra = [])
    {
        if (!$this->enabled || $this->baseDir === '') {
            return '';
        }

        $cp = new Checkpoint((string) $taskId, [
            'iteration' => $iteration,
            'messages'  => $messages,
            'extra'     => $extra,
        ]);

        $file = $this->filePath($cp->getId(), $cp->getIteration());
        $json = json_encode($cp->toArray(), JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            @file_put_contents($file, $json, LOCK_EX);
        }

        // 清理旧 checkpoint
        $this->cleanup($cp->getId());

        return $cp->getId() . '_' . $cp->getIteration();
    }

    /**
     * 加载最新检查点
     *
     * @param string $taskId
     * @return Checkpoint|null
     */
    public function loadLatest($taskId)
    {
        $taskId = (string) $taskId;
        if ($this->baseDir === '') {
            return null;
        }

        $files = $this->listCheckpoints($taskId);
        if (!$files) {
            return null;
        }

        // 取最新的（最后一个）
        $latest = end($files);
        $raw = @file_get_contents($latest);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        return new Checkpoint($taskId, $data);
    }

    /**
     * 加载指定轮次的检查点
     *
     * @param string $taskId
     * @param int $iteration
     * @return Checkpoint|null
     */
    public function load($taskId, $iteration)
    {
        $file = $this->filePath((string) $taskId, $iteration);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        return new Checkpoint((string) $taskId, $data);
    }

    /**
     * 删除指定任务的全部 checkpoint
     *
     * @param string $taskId
     * @return $this
     */
    public function delete($taskId)
    {
        $files = $this->listCheckpoints((string) $taskId);
        foreach ($files as $file) {
            @unlink($file);
        }
        return $this;
    }

    /**
     * 列出指定任务的所有 checkpoint 文件
     *
     * @param string $taskId
     * @return string[] 按修改时间排序
     */
    protected function listCheckpoints($taskId)
    {
        if ($this->baseDir === '' || !is_dir($this->baseDir)) {
            return [];
        }

        $prefix = $this->safeFileName((string) $taskId) . '_checkpoint_';
        $files = @glob($this->baseDir . '/' . $prefix . '*.json');
        if ($files === false) {
            return [];
        }
        sort($files, SORT_NATURAL);
        return $files;
    }

    /**
     * 清理旧 checkpoint，只保留最新的 maxCheckpoints 个
     *
     * @param string $taskId
     * @return void
     */
    protected function cleanup($taskId)
    {
        $files = $this->listCheckpoints($taskId);
        $count = count($files);
        if ($count <= $this->maxCheckpoints) {
            return;
        }
        $toDelete = array_slice($files, 0, $count - $this->maxCheckpoints);
        foreach ($toDelete as $file) {
            @unlink($file);
        }
    }

    /**
     * 生成 checkpoint 文件路径
     *
     * @param string $taskId
     * @param int $iteration
     * @return string
     */
    protected function filePath($taskId, $iteration)
    {
        return $this->baseDir . '/' . $this->safeFileName($taskId) . '_checkpoint_' . $iteration . '.json';
    }

    /**
     * 安全文件名
     *
     * @param string $name
     * @return string
     */
    protected function safeFileName($name)
    {
        $result = preg_replace('/[^a-zA-Z0-9\-_]/', '_', (string) $name);
        return $result !== null ? $result : (string) $name;
    }

    /**
     * 清理过期 checkpoint（超过指定天数）
     *
     * @param string $taskId
     * @param int $days
     * @return $this
     */
    public function cleanExpired($taskId, $days = 7)
    {
        $files = $this->listCheckpoints((string) $taskId);
        $expire = time() - ($days * 86400);
        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $expire) {
                @unlink($file);
            }
        }
        return $this;
    }
}