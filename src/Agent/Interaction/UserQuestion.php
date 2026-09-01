<?php
namespace Ai\Agent\Interaction;

/**
 * UserQuestion——用户问题值对象
 *
 * 封装模型向用户提出的问题，包含问题文本、可选选项、用户回答和状态。
 * 由 UserInteractionManager 创建和管理。
 *
 * 用法：
 * ```php
 * $question = new UserQuestion([
 *     'question' => '应该修改生产环境还是测试环境？',
 *     'options'  => ['生产', '测试'],
 * ]);
 * echo $question->getId();       // 'uq_xxx'
 * echo $question->getQuestion(); // '应该修改生产环境还是测试环境？'
 * ```
 */
class UserQuestion
{
    /** @var string */
    protected $id;

    /** @var string */
    protected $question = '';

    /** @var string[] */
    protected $options = [];

    /** @var string|null */
    protected $answer = null;

    /** @var string pending|answered */
    protected $status = 'pending';

    /** @var int */
    protected $createdAt;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->id        = isset($data['id']) ? (string) $data['id'] : self::generateId();
        $this->question  = isset($data['question']) ? (string) $data['question'] : '';
        $this->options   = isset($data['options']) && is_array($data['options']) ? $data['options'] : [];
        $this->answer    = isset($data['answer']) ? (string) $data['answer'] : null;
        $this->status    = isset($data['status']) ? (string) $data['status'] : 'pending';
        $this->createdAt = isset($data['createdAt']) ? (int) $data['createdAt'] : time();
    }

    /**
     * 生成唯一问题 ID
     * @return string
     */
    public static function generateId()
    {
        return 'uq_' . bin2hex(random_bytes(8));
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getQuestion()
    {
        return $this->question;
    }

    /**
     * @return string[]
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * @return string|null
     */
    public function getAnswer()
    {
        return $this->answer;
    }

    /**
     * @return string
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @return bool
     */
    public function isPending()
    {
        return $this->status === 'pending';
    }

    /**
     * @return bool
     */
    public function isAnswered()
    {
        return $this->status === 'answered';
    }

    /**
     * 回答问题
     *
     * @param string $answer
     * @return $this
     */
    public function answer($answer)
    {
        $this->answer = (string) $answer;
        $this->status = 'answered';
        return $this;
    }

    /**
     * @return int
     */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /**
     * 转为数组
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'id'        => $this->id,
            'question'  => $this->question,
            'options'   => $this->options,
            'answer'    => $this->answer,
            'status'    => $this->status,
            'createdAt' => $this->createdAt,
        ];
    }
}