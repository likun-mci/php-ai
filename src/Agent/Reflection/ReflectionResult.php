<?php
namespace Ai\Agent\Reflection;

/**
 * ReflectionResult——反思结果值对象
 *
 * Agent 在工具执行后自我检查的产物。描述目标是否达成、原因与下一步行动建议。
 *
 * ```php
 * $result = ReflectionResult::continuing('测试失败，需要继续修复', '分析错误日志');
 * $result->isSuccess();        // false
 * $result->getNextAction();    // '分析错误日志'
 * ```
 */
class ReflectionResult
{
    /** @var bool 目标是否完成 */
    protected $success;

    /** @var string 原因说明 */
    protected $reason;

    /** @var string|null 下一步行动建议 */
    protected $nextAction = null;

    /** @var array<string, mixed> 额外信息（如失败详情、建议执行的工具等） */
    protected $metadata = [];

    /**
     * @param bool $success
     * @param string $reason
     * @param string|null $nextAction
     * @param array<string, mixed> $metadata
     */
    public function __construct($success, $reason = '', $nextAction = null, array $metadata = [])
    {
        $this->success = (bool) $success;
        $this->reason = (string) $reason;
        $this->nextAction = $nextAction;
        $this->metadata = $metadata;
    }

    /**
     * 目标已完成
     *
     * @param string|null $reason
     * @param array<string, mixed> $metadata
     * @return self
     */
    public static function completed($reason = '目标已完成', array $metadata = [])
    {
        return new self(true, (string) $reason, null, $metadata);
    }

    /**
     * 目标未完成，需要继续
     *
     * @param string $reason
     * @param string|null $nextAction
     * @param array<string, mixed> $metadata
     * @return self
     */
    public static function continuing($reason, $nextAction = null, array $metadata = [])
    {
        return new self(false, $reason, $nextAction, $metadata);
    }

    /**
     * @return bool
     */
    public function isSuccess()
    {
        return $this->success;
    }

    /**
     * @return bool
     */
    public function shouldContinue()
    {
        return !$this->success;
    }

    /**
     * @return string
     */
    public function getReason()
    {
        return $this->reason;
    }

    /**
     * @return string|null
     */
    public function getNextAction()
    {
        return $this->nextAction;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata()
    {
        return $this->metadata;
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function meta($key, $default = null)
    {
        return array_key_exists($key, $this->metadata) ? $this->metadata[$key] : $default;
    }

    /**
     * 生成注入下一轮 prompt 的反思文本
     *
     * @return string
     */
    public function toPrompt()
    {
        $lines = ['反思结果：'];
        $lines[] = $this->success
            ? '  状态: 目标已完成'
            : '  状态: 目标未完成，需要继续';
        if ($this->reason !== '') {
            $lines[] = '  原因: ' . $this->reason;
        }
        if ($this->nextAction !== null) {
            $lines[] = '  下一步: ' . $this->nextAction;
        }
        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'success' => $this->success,
            'reason' => $this->reason,
            'next_action' => $this->nextAction,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data)
    {
        return new self(
            isset($data['success']) ? (bool) $data['success'] : false,
            isset($data['reason']) ? $data['reason'] : '',
            isset($data['next_action']) ? $data['next_action'] : null,
            isset($data['metadata']) ? $data['metadata'] : []
        );
    }
}