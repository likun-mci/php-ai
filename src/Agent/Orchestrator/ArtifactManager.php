<?php
namespace Ai\Agent\Orchestrator;

use Ai\Helpers\Text;

/**
 * ArtifactManager——产物管理
 *
 * Agent 干活会产出测试报告、补丁、日志、分析结果。**这些不该全文塞进上下文**：
 * 一份 5000 行的测试报告扔进去，剩下的对话就没地方了，而模型真正需要的
 * 往往只是「有几个失败、失败在哪」。
 *
 * 产物存在外面，上下文里只留一个引用：
 *
 * ```php
 * $artifacts = new ArtifactManager('/var/data/artifacts');
 *
 * $ref = $artifacts->put('task_123', 'test-report.json', $reportJson);
 * echo $ref;   // 'artifact://task_123/test-report.json'
 *
 * // 模型需要细节时再取
 * $artifacts->get($ref);
 * $artifacts->preview($ref, 500);   // 只取前 500 字符
 * ```
 *
 * 不配目录时退化成内存模式——同进程内可用，进程结束即丢。
 */
class ArtifactManager
{
    const SCHEME = 'artifact://';

    /** @var string 存储目录，空则纯内存 */
    protected $baseDir = '';

    /** @var array<string, string> 引用 => 内容（内存模式） */
    protected $memory = [];

    /** @var array<string, array<string, mixed>> 引用 => 元信息 */
    protected $meta = [];

    /** @var int 单个产物最大字节数 */
    protected $maxBytes = 10485760;

    /**
     * @param string $baseDir
     * @param array<string, mixed> $options maxBytes
     */
    public function __construct($baseDir = '', array $options = [])
    {
        $this->baseDir = rtrim(str_replace('\\', '/', (string) $baseDir), '/');
        if ($this->baseDir !== '' && !is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0777, true);
        }
        if (isset($options['maxBytes'])) {
            $this->maxBytes = max(1024, (int) $options['maxBytes']);
        }
    }

    /**
     * 存一份产物
     *
     * @param string $taskId 归属任务
     * @param string $name 产物名（含扩展名）
     * @param string $content
     * @param array<string, mixed> $meta 额外元信息（type / description）
     * @return string 引用 URI；存储失败返回空串
     */
    public function put($taskId, $name, $content, array $meta = [])
    {
        $taskId = $this->sanitize($taskId);
        $name = $this->sanitizeName($name);
        if ($taskId === '' || $name === '') {
            return '';
        }

        $content = (string) $content;
        if (strlen($content) > $this->maxBytes) {
            $content = substr($content, 0, $this->maxBytes) . "\n…（产物超出上限已截断）";
        }

        $ref = self::SCHEME . $taskId . '/' . $name;

        if ($this->baseDir === '') {
            $this->memory[$ref] = $content;
        } else {
            $dir = $this->baseDir . '/' . $taskId;
            if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
                return '';
            }
            if (@file_put_contents($dir . '/' . $name, $content, LOCK_EX) === false) {
                return '';
            }
        }

        $this->meta[$ref] = array_merge([
            'task_id'    => $taskId,
            'name'       => $name,
            'size'       => strlen($content),
            'created_at' => time(),
        ], $meta);

        return $ref;
    }

    /**
     * 取回产物内容
     *
     * @param string $ref
     * @return string|null 不存在返回 null
     */
    public function get($ref)
    {
        $ref = (string) $ref;

        if ($this->baseDir === '') {
            return isset($this->memory[$ref]) ? $this->memory[$ref] : null;
        }

        $path = $this->pathOf($ref);
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        return $content === false ? null : $content;
    }

    /**
     * 取产物的开头一段——给模型看摘要，不给全文
     *
     * @param string $ref
     * @param int $limit
     * @return string 不存在返回空串
     */
    public function preview($ref, $limit = 500)
    {
        $content = $this->get($ref);
        if ($content === null) {
            return '';
        }
        $limit = max(1, (int) $limit);
        if (strlen($content) <= $limit) {
            return $content;
        }
        return Text::cutBytes($content, $limit) . "\n…（完整内容见 " . $ref . '）';
    }

    /**
     * 产物是否存在
     *
     * @param string $ref
     * @return bool
     */
    public function has($ref)
    {
        return $this->get($ref) !== null;
    }

    /**
     * 删除一份产物
     *
     * @param string $ref
     * @return bool
     */
    public function delete($ref)
    {
        $ref = (string) $ref;
        unset($this->meta[$ref]);

        if ($this->baseDir === '') {
            $existed = isset($this->memory[$ref]);
            unset($this->memory[$ref]);
            return $existed;
        }
        $path = $this->pathOf($ref);
        return $path !== '' && is_file($path) ? @unlink($path) : false;
    }

    /**
     * 某个任务的全部产物引用
     *
     * @param string $taskId
     * @return string[]
     */
    public function listFor($taskId)
    {
        $taskId = $this->sanitize($taskId);
        if ($taskId === '') {
            return [];
        }

        if ($this->baseDir === '') {
            $prefix = self::SCHEME . $taskId . '/';
            return array_values(array_filter(array_keys($this->memory), function ($ref) use ($prefix) {
                return strpos($ref, $prefix) === 0;
            }));
        }

        $dir = $this->baseDir . '/' . $taskId;
        if (!is_dir($dir)) {
            return [];
        }
        $refs = [];
        foreach ((array) @scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === false) {
                continue;
            }
            if (is_file($dir . '/' . $entry)) {
                $refs[] = self::SCHEME . $taskId . '/' . $entry;
            }
        }
        return $refs;
    }

    /**
     * 产物元信息
     *
     * @param string $ref
     * @return array<string, mixed>|null
     */
    public function metaOf($ref)
    {
        $ref = (string) $ref;
        return isset($this->meta[$ref]) ? $this->meta[$ref] : null;
    }

    /**
     * 判断一个字符串是不是产物引用
     *
     * @param string $value
     * @return bool
     */
    public static function isRef($value)
    {
        return strpos((string) $value, self::SCHEME) === 0;
    }

    /**
     * 把内容存成产物并返回一句可以放进上下文的说明
     *
     * ```php
     * $note = $artifacts->summarize('task_1', 'test-report.txt', $report, 3);
     * // '测试报告已保存到 artifact://task_1/test-report.txt（12.4 KB）：\nFAILURES!\n…'
     * ```
     *
     * @param string $taskId
     * @param string $name
     * @param string $content
     * @param int $previewLines 摘要保留几行
     * @return string
     */
    public function summarize($taskId, $name, $content, $previewLines = 5)
    {
        $ref = $this->put($taskId, $name, $content);
        if ($ref === '') {
            return (string) $content;
        }

        $lines = preg_split('/\r?\n/', (string) $content);
        $head = implode("\n", array_slice($lines === false ? [] : $lines, 0, max(1, (int) $previewLines)));
        $size = number_format(strlen((string) $content) / 1024, 1);

        return sprintf("已保存到 %s（%s KB）：\n%s\n…", $ref, $size, $head);
    }

    /**
     * @return string
     */
    public function getBaseDir()
    {
        return $this->baseDir;
    }

    /**
     * 引用对应的磁盘路径
     *
     * @param string $ref
     * @return string
     */
    protected function pathOf($ref)
    {
        if ($this->baseDir === '' || !self::isRef($ref)) {
            return '';
        }
        $relative = substr((string) $ref, strlen(self::SCHEME));
        $parts = explode('/', $relative, 2);
        if (count($parts) !== 2) {
            return '';
        }
        $taskId = $this->sanitize($parts[0]);
        $name = $this->sanitizeName($parts[1]);
        if ($taskId === '' || $name === '') {
            return '';
        }
        return $this->baseDir . '/' . $taskId . '/' . $name;
    }

    /**
     * @param string $value
     * @return string
     */
    protected function sanitize($value)
    {
        return (string) preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $value);
    }

    /**
     * 产物名允许点号与扩展名，但不允许路径穿越
     *
     * @param string $value
     * @return string
     */
    protected function sanitizeName($value)
    {
        $value = basename(str_replace('\\', '/', (string) $value));
        $value = (string) preg_replace('/[^A-Za-z0-9_\-.]/', '', $value);
        return trim($value, '.') === '' ? '' : $value;
    }
}
