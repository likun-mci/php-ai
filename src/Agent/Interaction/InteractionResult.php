<?php
namespace Ai\Agent\Interaction;

/**
 * InteractionResult——交互结果值对象
 *
 * 封装用户交互的结果，包含交互状态（等待用户回答 / 已回答）、
 * 关联的问题 ID 和用户回答。
 *
 * 用法：
 * ```php
 * $result = InteractionResult::waiting('uq_xxx', '请选择推送分支');
 * echo $result->getStatus();    // 'waiting_user'
 * echo $result->getQuestionId(); // 'uq_xxx'
 *
 * $result = InteractionResult::answered('uq_xxx', 'main');
 * echo $result->getAnswer();    // 'main'
 * ```
 */
class InteractionResult
{
    /** @var string waiting_user|answered */
    protected $status;

    /** @var string|null */
    protected $questionId = null;

    /** @var string|null */
    protected $question = null;

    /** @var string|null */
    protected $answer = null;

    /** @var string[] */
    protected $options = [];

    /**
     * @param string $status
     * @param array<string, mixed> $data
     */
    public function __construct($status, array $data = [])
    {
        $this->status     = (string) $status;
        $this->questionId = isset($data['questionId']) ? (string) $data['questionId'] : null;
        $this->question   = isset($data['question']) ? (string) $data['question'] : null;
        $this->answer     = isset($data['answer']) ? (string) $data['answer'] : null;
        $this->options    = isset($data['options']) && is_array($data['options']) ? $data['options'] : [];
    }

    /**
     * 创建等待用户回答的结果
     *
     * @param string $questionId
     * @param string $question
     * @param string[] $options
     * @return self
     */
    public static function waiting($questionId, $question = '', array $options = [])
    {
        return new self('waiting_user', [
            'questionId' => $questionId,
            'question'   => $question,
            'options'    => $options,
        ]);
    }

    /**
     * 创建已回答的结果
     *
     * @param string $questionId
     * @param string $answer
     * @return self
     */
    public static function answered($questionId, $answer = '')
    {
        return new self('answered', [
            'questionId' => $questionId,
            'answer'     => $answer,
        ]);
    }

    /**
     * @return string
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @return bool 是否等待用户回答
     */
    public function isWaiting()
    {
        return $this->status === 'waiting_user';
    }

    /**
     * @return bool 是否已回答
     */
    public function isAnswered()
    {
        return $this->status === 'answered';
    }

    /**
     * @return string|null
     */
    public function getQuestionId()
    {
        return $this->questionId;
    }

    /**
     * @return string|null
     */
    public function getQuestion()
    {
        return $this->question;
    }

    /**
     * @return string|null
     */
    public function getAnswer()
    {
        return $this->answer;
    }

    /**
     * @return string[]
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * 转为数组
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'status'      => $this->status,
            'question_id' => $this->questionId,
            'question'    => $this->question,
            'answer'      => $this->answer,
            'options'     => $this->options,
        ];
    }
}