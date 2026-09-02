<?php
namespace Ai\Agent\Approval;

use Ai\Helpers\Text;

/**
 * ApprovalRequest——一次人工审批请求
 *
 * 记录 AI 提交了什么改动、谁在什么时候批的、批还是驳、驳的理由是什么。
 * 企业环境里这份记录本身就是要留档的东西，所以它可以完整 JSON 序列化。
 *
 * ```php
 * $request = new ApprovalRequest('req_1', [
 *     'summary' => '修改登录逻辑',
 *     'diff'    => $diff,
 *     'files'   => ['src/Auth.php'],
 * ]);
 * $request->approve('张三');
 * echo $request->getStatus();   // 'approved'
 * ```
 */
class ApprovalRequest
{
    const STATUS_PENDING  = 'pending_review';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_EXPIRED  = 'expired';

    /** @var string 请求 ID */
    protected $id = '';

    /** @var string 改动摘要 */
    protected $summary = '';

    /** @var string 完整 diff */
    protected $diff = '';

    /** @var string[] 涉及的文件 */
    protected $files = [];

    /** @var string 状态 */
    protected $status = self::STATUS_PENDING;

    /** @var string 审批人 */
    protected $reviewer = '';

    /** @var string 驳回理由 */
    protected $reason = '';

    /** @var array<string, mixed> 上下文（任务 ID、Agent 角色、工具调用等） */
    protected $context = [];

    /** @var int 创建时间 */
    protected $createdAt = 0;

    /** @var int 处理时间，未处理为 0 */
    protected $decidedAt = 0;

    /** @var int 过期时间戳，0 表示不过期 */
    protected $expiresAt = 0;

    /**
     * @param string $id
     * @param array<string, mixed> $data summary / diff / files / context / expiresAt / status / …
     */
    public function __construct($id, array $data = [])
    {
        $this->id = (string) $id;
        $this->createdAt = time();

        foreach (['summary', 'diff', 'status', 'reviewer', 'reason'] as $key) {
            if (isset($data[$key])) {
                $this->$key = (string) $data[$key];
            }
        }
        if (isset($data['files']) && is_array($data['files'])) {
            $this->files = array_values(array_map('strval', $data['files']));
        }
        if (isset($data['context']) && is_array($data['context'])) {
            $this->context = $data['context'];
        }
        foreach (['createdAt', 'decidedAt', 'expiresAt'] as $key) {
            if (isset($data[$key])) {
                $this->$key = (int) $data[$key];
            }
        }
    }

    /** @return string */
    public function getId()
    {
        return $this->id;
    }

    /** @return string */
    public function getSummary()
    {
        return $this->summary;
    }

    /** @return string */
    public function getDiff()
    {
        return $this->diff;
    }

    /** @return string[] */
    public function getFiles()
    {
        return $this->files;
    }

    /**
     * 当前状态
     *
     * 设了过期时间且已过期的请求，读到的状态就是 expired——不必等谁来"清理"，
     * 一个三天前提的审批不该还能被批准。
     *
     * @return string
     */
    public function getStatus()
    {
        if ($this->status === self::STATUS_PENDING && $this->isExpired()) {
            return self::STATUS_EXPIRED;
        }
        return $this->status;
    }

    /** @return string */
    public function getReviewer()
    {
        return $this->reviewer;
    }

    /** @return string */
    public function getReason()
    {
        return $this->reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext()
    {
        return $this->context;
    }

    /** @return int */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /** @return int */
    public function getDecidedAt()
    {
        return $this->decidedAt;
    }

    /** @return int */
    public function getExpiresAt()
    {
        return $this->expiresAt;
    }

    /**
     * @param int $timestamp 0 表示不过期
     * @return $this
     */
    public function setExpiresAt($timestamp)
    {
        $this->expiresAt = (int) $timestamp;
        return $this;
    }

    /**
     * 是否已过期
     *
     * @return bool
     */
    public function isExpired()
    {
        return $this->expiresAt > 0 && time() > $this->expiresAt;
    }

    /**
     * 是否还在等审批
     *
     * @return bool
     */
    public function isPending()
    {
        return $this->getStatus() === self::STATUS_PENDING;
    }

    /**
     * 是否已批准
     *
     * @return bool
     */
    public function isApproved()
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * 是否被驳回
     *
     * @return bool
     */
    public function isRejected()
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * 批准
     *
     * 已经处理过或已过期的请求不能再批——审批结果只能出一次。
     *
     * @param string $reviewer
     * @return bool 状态变更成功返回 true
     */
    public function approve($reviewer = '')
    {
        if (!$this->isPending()) {
            return false;
        }
        $this->status = self::STATUS_APPROVED;
        $this->reviewer = (string) $reviewer;
        $this->decidedAt = time();
        return true;
    }

    /**
     * 驳回
     *
     * @param string $reason 驳回理由——退回去让 AI 改的时候，这是它唯一的输入
     * @param string $reviewer
     * @return bool
     */
    public function reject($reason = '', $reviewer = '')
    {
        if (!$this->isPending()) {
            return false;
        }
        $this->status = self::STATUS_REJECTED;
        $this->reason = (string) $reason;
        $this->reviewer = (string) $reviewer;
        $this->decidedAt = time();
        return true;
    }

    /**
     * 生成给人看的审批摘要
     *
     * @param int $diffLimit diff 最多显示多少字符，0 表示不显示 diff
     * @return string
     */
    public function toSummary($diffLimit = 2000)
    {
        $lines = [];
        $lines[] = '审批请求 #' . $this->id . '（' . $this->getStatus() . '）';
        if ($this->summary !== '') {
            $lines[] = '摘要：' . $this->summary;
        }
        if ($this->files) {
            $lines[] = '涉及文件：' . implode(', ', $this->files);
        }
        $lines[] = '提交时间：' . date('Y-m-d H:i:s', $this->createdAt);
        if ($this->reviewer !== '') {
            $lines[] = '处理人：' . $this->reviewer;
        }
        if ($this->reason !== '') {
            $lines[] = '驳回理由：' . $this->reason;
        }

        $diffLimit = (int) $diffLimit;
        if ($diffLimit > 0 && $this->diff !== '') {
            $diff = strlen($this->diff) > $diffLimit
                ? Text::cutBytes($this->diff, $diffLimit) . "\n…（diff 已截断）"
                : $this->diff;
            $lines[] = "改动：\n" . $diff;
        }
        return implode("\n", $lines);
    }

    /**
     * 驳回后交回给 AI 的提示文本
     *
     * @return string 未被驳回时返回空串
     */
    public function toRejectionPrompt()
    {
        if (!$this->isRejected()) {
            return '';
        }
        $text = '你提交的改动被驳回了。';
        if ($this->reason !== '') {
            $text .= "\n驳回理由：" . $this->reason;
        }
        if ($this->files) {
            $text .= "\n涉及文件：" . implode(', ', $this->files);
        }
        $text .= "\n请按理由修改后重新提交。";
        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'id'         => $this->id,
            'summary'    => $this->summary,
            'diff'       => $this->diff,
            'files'      => $this->files,
            'status'     => $this->getStatus(),
            'reviewer'   => $this->reviewer,
            'reason'     => $this->reason,
            'context'    => $this->context,
            'createdAt'  => $this->createdAt,
            'decidedAt'  => $this->decidedAt,
            'expiresAt'  => $this->expiresAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data)
    {
        $id = isset($data['id']) ? (string) $data['id'] : '';
        return new self($id, $data);
    }
}
