<?php
namespace Ai\Agent\Session;

use Ai\Helpers\Path;

/**
 * JSONL 会话存储
 *
 * 每个会话三个文件（见 dev.md 第五节）：
 *   <sid>.jsonl        每行一个事件（message / compact / rewrite）
 *   <sid>.state.json   非 message 状态（status / iteration / budget / 归属 …）
 *   <sid>.lock         读改写事务的独占锁文件
 *
 * 为什么用 JSONL：会话消息通常只增不减，append 一行的成本是 O(新增)，
 * 而旧的整份 JSON 每次重写是 O(全部)。只有在消息数变少 / 已保存前缀被改
 * （compact、rollback、手工替换）时才退回整份重写（临时文件 + rename 原子替换）。
 *
 * 并发正确性（见 dev.md 6.3）：锁必须覆盖整个 load→计算→写 jsonl→写 state
 * 事务，而不是只锁单次 file_put_contents，否则两个 worker 各自 load-old 再 save
 * 会发生 lost update。
 *
 * 兼容与安全：
 *   - 旧 <sid>.json 原样可读，不自动迁移、不删除（见 dev.md 第七节）。
 *   - state.json 解析失败 → 改名保留残骸，绝不当成不存在（见 dev.md 第八节）。
 *   - jsonl 单行损坏（如崩溃留下的半行）→ 跳过该行并告警，不废掉整份会话。
 */
class JsonlSessionStore implements AgentSessionStoreInterface
{
    /** @var string 存储目录（带尾斜杠） */
    protected $dir;

    /**
     * @param string $dir 存储目录（惰性创建，构造零副作用）
     */
    public function __construct($dir)
    {
        $this->dir = rtrim(str_replace('\\', '/', (string) $dir), '/') . '/';
    }

    /**
     * @param string $id
     * @return string 文件名主干（安全名，无扩展名）
     */
    protected function base($id)
    {
        return $this->dir . Path::safeName((string) $id);
    }

    /**
     * 旧版（仅清洗、无散列后缀）主干，用于向后兼容读取
     *
     * @param string $id
     * @return string
     */
    protected function legacyBase($id)
    {
        $safe = preg_replace('/[^a-zA-Z0-9\-_]/', '_', (string) $id);
        if (!is_string($safe) || $safe === '') {
            $safe = 'id';
        }
        return $this->dir . $safe;
    }

    /**
     * 加载会话
     *
     * 读取顺序（见 dev.md 第七节）：jsonl + state → 旧 <sid>.json → null。
     *
     * @param string $id
     * @return AgentSession|null
     */
    public function load($id)
    {
        foreach ([$this->base($id), $this->legacyBase($id)] as $base) {
            $jsonl = $base . '.jsonl';
            $state = $base . '.state.json';
            if (is_file($jsonl) || is_file($state)) {
                return $this->loadJsonl($id, $jsonl, $state);
            }
        }
        // 回退：旧的整份 <sid>.json（新旧文件名都试）
        foreach ([$this->base($id) . '.json', $this->legacyBase($id) . '.json'] as $json) {
            if (is_file($json)) {
                return $this->loadLegacyJson($id, $json);
            }
        }
        return null;
    }

    /**
     * 从 jsonl + state 组装会话
     *
     * @param string $id
     * @param string $jsonl
     * @param string $stateFile
     * @return AgentSession|null
     */
    protected function loadJsonl($id, $jsonl, $stateFile)
    {
        $data = [];
        if (is_file($stateFile)) {
            $raw = @file_get_contents($stateFile);
            if ($raw === false) {
                \Ai\Helpers\Log::error('会话 state 读取失败', ['path' => $stateFile]);
                return null;
            }
            if (trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    // 存在但解析失败：保留残骸
                    $corrupt = $stateFile . '.corrupt.' . time();
                    @rename($stateFile, $corrupt);
                    \Ai\Helpers\Log::error('会话 state 解析失败，已改名保留残骸', [
                        'path' => $stateFile, 'corrupt' => $corrupt,
                    ]);
                    return null;
                }
                $data = $decoded;
            }
        }

        $data['messages'] = $this->readMessages($jsonl);
        return new AgentSession($id, $data);
    }

    /**
     * 逐行读取 jsonl，重建 message 列表（跳过损坏行与非 message 事件）
     *
     * @param string $jsonl
     * @return array<int, array<string, mixed>>
     */
    protected function readMessages($jsonl)
    {
        $messages = [];
        if (!is_file($jsonl)) {
            return $messages;
        }
        $fp = @fopen($jsonl, 'r');
        if ($fp === false) {
            \Ai\Helpers\Log::error('会话 jsonl 打开失败', ['path' => $jsonl]);
            return $messages;
        }
        $lineNo = 0;
        while (($line = fgets($fp)) !== false) {
            $lineNo++;
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $event = json_decode($line, true);
            if (!is_array($event)) {
                // 半行 / 损坏行：跳过而非废掉整份会话
                \Ai\Helpers\Log::warning('会话 jsonl 跳过损坏行', ['path' => $jsonl, 'line' => $lineNo]);
                continue;
            }
            $type = isset($event['type']) ? (string) $event['type'] : 'message';
            if ($type === 'message' && isset($event['message']) && is_array($event['message'])) {
                $messages[] = $event['message'];
            }
            // rewrite / compact 是信息性标记：重写时整份 jsonl 已反映最终 message，
            // 这里无需特殊处理
        }
        @fclose($fp);
        return $messages;
    }

    /**
     * 读取旧的整份 <sid>.json（原样，不迁移）
     *
     * @param string $id
     * @param string $json
     * @return AgentSession|null
     */
    protected function loadLegacyJson($id, $json)
    {
        $raw = @file_get_contents($json);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $corrupt = $json . '.corrupt.' . time();
            @rename($json, $corrupt);
            \Ai\Helpers\Log::error('旧会话 json 解析失败，已改名保留残骸', [
                'path' => $json, 'corrupt' => $corrupt,
            ]);
            return null;
        }
        return new AgentSession($id, $data);
    }

    /**
     * 保存会话——整个读改写事务在 .lock 独占锁下完成
     *
     * @param AgentSession $session
     * @return void
     */
    public function save(AgentSession $session)
    {
        $base = $this->base($session->getId());
        if (!is_dir($this->dir) && !Path::ensureDir($this->dir, 0700)) {
            \Ai\Helpers\Log::error('会话目录创建失败', ['dir' => $this->dir]);
            return;
        }

        $lockFile = $base . '.lock';
        $lock = @fopen($lockFile, 'c');
        if ($lock === false) {
            \Ai\Helpers\Log::error('会话锁文件打开失败，改用整份重写兜底', ['lock' => $lockFile]);
            $this->rewrite($base, $session, 'no_lock');
            return;
        }
        if (!@flock($lock, LOCK_EX)) {
            \Ai\Helpers\Log::error('会话加锁失败，改用整份重写兜底', ['lock' => $lockFile]);
            @fclose($lock);
            $this->rewrite($base, $session, 'no_lock');
            return;
        }

        try {
            $jsonl = $base . '.jsonl';
            $persisted = $this->readState($base . '.state.json');
            $messages = array_values($session->getMessages());
            $newCount = count($messages);

            // append 条件（见 dev.md 6.1）：消息数不减、且已保存前缀未变。
            // 前缀是否未变，dev.md 只要求校验末条散列；这里额外校验首条散列
            // （first_hash），成本 O(1)，可堵住「数量与末条都没变、只改了前缀」
            // 被误判成 append 而静默丢改动的漏洞——数据完整性优先于那点便利。
            $canAppend = false;
            if (is_file($jsonl) && isset($persisted['message_count'], $persisted['last_hash'])) {
                $pc = (int) $persisted['message_count'];
                if ($pc === 0) {
                    $canAppend = true;  // 之前存过 0 条，前缀空，直接 append
                } elseif ($newCount >= $pc
                    && $this->hashMessage($messages[$pc - 1]) === $persisted['last_hash']
                    && (!isset($persisted['first_hash'])
                        || $this->hashMessage($messages[0]) === $persisted['first_hash'])) {
                    $canAppend = true;
                }
            } elseif (!is_file($jsonl)) {
                $canAppend = true;  // 首次写：从空文件 append 全部
            }

            // startFrom / seq 必须以 jsonl 现状为准：jsonl 缺失（崩溃残留、被删、
            // 只恢复了 state）时即便 state 记着非 0 message_count，也要从 0 全量写，
            // 否则 append 会跳过前 N 条、产出静默残缺的会话
            $jsonlExists = is_file($jsonl);
            $seq = ($jsonlExists && isset($persisted['seq'])) ? (int) $persisted['seq'] : 0;
            $startFrom = ($canAppend && $jsonlExists && isset($persisted['message_count']))
                ? (int) $persisted['message_count'] : 0;

            if ($canAppend) {
                $this->appendMessages($jsonl, $messages, $startFrom, $seq);
                $seq += max(0, $newCount - $startFrom);
            } else {
                $seq = $this->rewriteJsonl($jsonl, $messages, 'compact');
            }

            $lastHash = $newCount > 0 ? $this->hashMessage($messages[$newCount - 1]) : '';
            $firstHash = $newCount > 0 ? $this->hashMessage($messages[0]) : '';
            $this->writeState($base . '.state.json', $session, $newCount, $lastHash, $seq, $firstHash);
        } catch (\Throwable $e) {
            \Ai\Helpers\Log::error('会话保存异常', ['id' => $session->getId(), 'error' => $e->getMessage()]);
        }

        @flock($lock, LOCK_UN);
        @fclose($lock);
    }

    /**
     * 读取 state.json（解析失败返回空数组，不在此处理残骸——load 负责）
     *
     * @param string $stateFile
     * @return array<string, mixed>
     */
    protected function readState($stateFile)
    {
        if (!is_file($stateFile)) {
            return [];
        }
        $raw = @file_get_contents($stateFile);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 追加新增 message 行（调用方已持锁）
     *
     * @param string $jsonl
     * @param array<int, array<string, mixed>> $messages
     * @param int $startFrom 从第几条开始追加
     * @param int $seq 当前已用到的 seq
     * @return void
     */
    protected function appendMessages($jsonl, array $messages, $startFrom, $seq)
    {
        $count = count($messages);
        if ($startFrom >= $count) {
            return;  // 无新增
        }
        $fp = @fopen($jsonl, 'a');
        if ($fp === false) {
            \Ai\Helpers\Log::error('会话 jsonl 追加打开失败', ['path' => $jsonl]);
            return;
        }
        $ts = time();
        $buf = '';
        for ($i = $startFrom; $i < $count; $i++) {
            $buf .= $this->encodeEvent(++$seq, $ts, 'message', ['message' => $messages[$i]]) . "\n";
        }
        @fwrite($fp, $buf);
        @fflush($fp);
        if (function_exists('fsync')) {
            @fsync($fp);
        }
        @fclose($fp);
    }

    /**
     * 整份重写 jsonl（临时文件 + fsync + rename），返回写入后的 seq
     *
     * @param string $jsonl
     * @param array<int, array<string, mixed>> $messages
     * @param string $reason
     * @return int
     */
    protected function rewriteJsonl($jsonl, array $messages, $reason)
    {
        $ts = time();
        $seq = 0;
        $lines = $this->encodeEvent(0, $ts, 'rewrite', ['reason' => (string) $reason, 'count' => count($messages)]) . "\n";
        foreach ($messages as $m) {
            $lines .= $this->encodeEvent(++$seq, $ts, 'message', ['message' => $m]) . "\n";
        }
        $tmp = $jsonl . '.tmp.' . getmypid() . '.' . mt_rand(1000, 9999);
        $fp = @fopen($tmp, 'w');
        if ($fp === false) {
            \Ai\Helpers\Log::error('会话 jsonl 重写临时文件失败', ['path' => $tmp]);
            return $seq;
        }
        @fwrite($fp, $lines);
        @fflush($fp);
        if (function_exists('fsync')) {
            @fsync($fp);
        }
        @fclose($fp);
        if (!@rename($tmp, $jsonl)) {
            @unlink($tmp);
            \Ai\Helpers\Log::error('会话 jsonl 重写 rename 失败', ['path' => $jsonl]);
        }
        return $seq;
    }

    /**
     * 全量重写兜底（拿不到锁时用）——直接重写 jsonl + state
     *
     * @param string $base
     * @param AgentSession $session
     * @param string $reason
     * @return void
     */
    protected function rewrite($base, AgentSession $session, $reason)
    {
        if (!is_dir($this->dir)) {
            Path::ensureDir($this->dir, 0700);
        }
        $messages = array_values($session->getMessages());
        $seq = $this->rewriteJsonl($base . '.jsonl', $messages, $reason);
        $count = count($messages);
        $lastHash = $count > 0 ? $this->hashMessage($messages[$count - 1]) : '';
        $firstHash = $count > 0 ? $this->hashMessage($messages[0]) : '';
        $this->writeState($base . '.state.json', $session, $count, $lastHash, $seq, $firstHash);
    }

    /**
     * 写 state.json（原子写）
     *
     * @param string $stateFile
     * @param AgentSession $session
     * @param int $messageCount
     * @param string $lastHash
     * @param int $seq
     * @param string $firstHash
     * @return void
     */
    protected function writeState($stateFile, AgentSession $session, $messageCount, $lastHash, $seq, $firstHash = '')
    {
        $state = $session->toArray();
        unset($state['messages']);  // message 存 jsonl，不进 state
        $state['message_count'] = $messageCount;
        $state['last_hash'] = $lastHash;
        $state['first_hash'] = $firstHash;
        $state['seq'] = $seq;

        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            \Ai\Helpers\Log::error('会话 state 序列化失败', ['id' => $session->getId()]);
            return;
        }
        Path::atomicWrite($stateFile, $json, 0700);
    }

    /**
     * 一个 message 的稳定散列（用于判断已保存前缀是否变化）
     *
     * @param array<string, mixed> $message
     * @return string
     */
    protected function hashMessage(array $message)
    {
        $json = json_encode($message, JSON_UNESCAPED_UNICODE);
        return hash('sha256', $json === false ? serialize($message) : $json);
    }

    /**
     * 编码一个 jsonl 事件行
     *
     * @param int $seq
     * @param int $ts
     * @param string $type
     * @param array<string, mixed> $payload
     * @return string
     */
    protected function encodeEvent($seq, $ts, $type, array $payload)
    {
        $event = array_merge(['seq' => $seq, 'ts' => $ts, 'type' => $type], $payload);
        $json = json_encode($event, JSON_UNESCAPED_UNICODE);
        return $json === false ? '{"type":"error"}' : $json;
    }

    /**
     * 删除会话（jsonl / state / lock / 新旧 json 一并删）
     *
     * @param string $id
     * @return void
     */
    public function delete($id)
    {
        foreach ([$this->base($id), $this->legacyBase($id)] as $base) {
            foreach (['.jsonl', '.state.json', '.lock', '.json'] as $ext) {
                $p = $base . $ext;
                if (is_file($p)) {
                    @unlink($p);
                }
            }
        }
    }
}
