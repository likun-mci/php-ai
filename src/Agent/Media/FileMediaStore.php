<?php
namespace Ai\Agent\Media;

/**
 * 文件媒体存储（默认实现）
 *
 * 落盘布局（与会话同级，沿用 `AgentHome` 的双根与身份隔离规则）：
 *
 * ```text
 * {home}/projects/{slug}/media/
 *     01j7x….png        原始字节
 *     01j7x….png.json   元数据（mime / name / bytes / created_at）
 * ```
 *
 * 为什么元数据用 sidecar 而不是数据库：媒体是**会话的附属物**，
 * 会话本身就是 JSONL 文件；为几十个附件引入一个 SQLite 不划算，
 * 而且 sidecar 让「手工删掉某个媒体」这件事符合直觉（连同 .json 一起删）。
 *
 * ```php
 * $store = new FileMediaStore($agentHome->mediaDir());
 * $ref   = $store->put($bytes, ['mime' => 'image/png', 'name' => 'a.png']);
 * $bytes = $store->get($ref->getId());
 * ```
 *
 * 注：不用类型化属性，保持 PHP 7.1 兼容（库的版本下限）。
 */
class FileMediaStore implements MediaStoreInterface
{
    /** @var string 存储目录 */
    protected $dir = '';

    /** @var int 目录权限 */
    protected $dirMode = 0700;

    /** @var array<string, string> MIME => 扩展名 */
    protected static $extByMime = [
        'image/png'       => 'png',
        'image/jpeg'      => 'jpg',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'image/bmp'       => 'bmp',
        'application/pdf' => 'pdf',
    ];

    /**
     * @param string $dir 存储目录
     * @param array<string, mixed> $options dir_mode
     */
    public function __construct($dir, array $options = [])
    {
        $this->dir = rtrim((string) $dir, '/\\');
        if ($this->dir === '') {
            throw new MediaException('FileMediaStore 需要一个存储目录');
        }
        if (isset($options['dir_mode'])) {
            $this->dirMode = (int) $options['dir_mode'];
        }
    }

    /** @return string */
    public function dir()
    {
        return $this->dir;
    }

    /**
     * @param string $bytes
     * @param array<string, mixed> $meta
     * @return MediaReference
     */
    public function put($bytes, array $meta = [])
    {
        $bytes = (string) $bytes;
        if ($bytes === '') {
            throw new MediaException('不能存入空的媒体内容');
        }

        $this->ensureDir();

        $mime = isset($meta['mime']) ? (string) $meta['mime'] : '';
        $name = isset($meta['name']) ? (string) $meta['name'] : '';
        $id   = $this->newId();
        $ext  = isset(self::$extByMime[$mime]) ? self::$extByMime[$mime] : 'bin';

        $file = $this->dir . '/' . $id . '.' . $ext;

        // 原子写：先写临时文件再 rename，避免读到写了一半的媒体
        $tmp = $file . '.tmp' . getmypid();
        if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) {
            throw new MediaException('媒体写入失败: ' . $file);
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new MediaException('媒体落盘失败: ' . $file);
        }
        @chmod($file, 0600);

        $record = [
            'id'         => $id,
            'file'       => basename($file),
            'mime'       => $mime,
            'name'       => $name,
            'bytes'      => strlen($bytes),
            'media'      => MediaReference::mediaOfMime($mime),
            'created_at' => time(),
        ];
        $json = json_encode($record, JSON_UNESCAPED_UNICODE);
        if (is_string($json)) {
            @file_put_contents($file . '.json', $json, LOCK_EX);
            @chmod($file . '.json', 0600);
        }

        return new MediaReference($record);
    }

    /**
     * @param string $id
     * @return string|null
     */
    public function get($id)
    {
        $file = $this->fileOf($id);
        if ($file === '') {
            return null;
        }
        $raw = @file_get_contents($file);
        return $raw === false ? null : $raw;
    }

    /**
     * @param string $id
     * @return bool
     */
    public function has($id)
    {
        return $this->fileOf($id) !== '';
    }

    /**
     * @param string $id
     * @return array<string, mixed>|null
     */
    public function stat($id)
    {
        $file = $this->fileOf($id);
        if ($file === '') {
            return null;
        }
        $raw = @file_get_contents($file . '.json');
        if ($raw === false) {
            // sidecar 丢了也要能用：退回从文件本身能问出来的信息
            return [
                'id'    => (string) $id,
                'file'  => basename($file),
                'bytes' => (int) @filesize($file),
                'mime'  => '',
                'name'  => '',
            ];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param string $id
     * @return bool
     */
    public function delete($id)
    {
        $file = $this->fileOf($id);
        if ($file === '') {
            return false;
        }
        @unlink($file . '.json');
        return @unlink($file);
    }

    /**
     * @param int $olderThanDays
     * @return int
     */
    public function prune($olderThanDays)
    {
        $cutoff  = time() - max(0, (int) $olderThanDays) * 86400;
        $removed = 0;
        foreach ($this->allIds() as $id) {
            $stat = $this->stat($id);
            $created = is_array($stat) && isset($stat['created_at']) ? (int) $stat['created_at'] : 0;
            if ($created === 0) {
                $file = $this->fileOf($id);
                $created = $file !== '' ? (int) @filemtime($file) : 0;
            }
            if ($created > 0 && $created < $cutoff && $this->delete($id)) {
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * @param string[] $liveIds
     * @return int
     */
    public function gc(array $liveIds)
    {
        $live = [];
        foreach ($liveIds as $id) {
            $live[(string) $id] = true;
        }
        $removed = 0;
        foreach ($this->allIds() as $id) {
            if (!isset($live[$id]) && $this->delete($id)) {
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * 目录里全部媒体 id
     *
     * @return string[]
     */
    public function allIds()
    {
        if (!is_dir($this->dir)) {
            return [];
        }
        $out    = [];
        $handle = @opendir($this->dir);
        if ($handle === false) {
            return [];
        }
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            // 只认媒体本体，跳过 .json sidecar 与写了一半的 .tmp
            if (substr($entry, -5) === '.json' || strpos($entry, '.tmp') !== false) {
                continue;
            }
            $dot = strrpos($entry, '.');
            $id  = $dot === false ? $entry : substr($entry, 0, $dot);
            if ($id !== '') {
                $out[] = $id;
            }
        }
        closedir($handle);
        sort($out);
        return $out;
    }

    /**
     * id => 实际文件路径
     *
     * @param string $id
     * @return string 不存在返回空串
     */
    protected function fileOf($id)
    {
        $id = (string) $id;
        // id 是自己生成的十六进制串；任何越界字符都当不存在处理，
        // 挡住 ../ 这类从 Conversation 里伪造 ref 的路径穿越
        if ($id === '' || preg_match('/^[0-9a-f]{8,64}$/', $id) !== 1) {
            return '';
        }
        foreach (self::$extByMime as $ext) {
            $file = $this->dir . '/' . $id . '.' . $ext;
            if (is_file($file)) {
                return $file;
            }
        }
        $file = $this->dir . '/' . $id . '.bin';
        return is_file($file) ? $file : '';
    }

    /** @return void */
    protected function ensureDir()
    {
        if (is_dir($this->dir)) {
            return;
        }
        if (!@mkdir($this->dir, $this->dirMode, true) && !is_dir($this->dir)) {
            throw new MediaException('无法创建媒体目录: ' . $this->dir);
        }
    }

    /**
     * 生成媒体 id：时间前缀 + 随机后缀
     *
     * 时间前缀让目录列表天然按时间有序（排查问题时有用），随机后缀防碰撞。
     * 不用 uniqid()——它基于微秒，同一进程里连续调用有可预测性。
     *
     * @return string
     */
    protected function newId()
    {
        $prefix = dechex(time());
        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (\Exception $e) {
            // random_bytes 在极端环境下会抛，退回一个仍然够用的组合
            $suffix = bin2hex(pack('N', mt_rand()) . pack('N', mt_rand()));
        }
        return $prefix . $suffix;
    }
}
