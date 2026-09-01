<?php
namespace Ai\Agent\Interaction;

/**
 * UserInteractionManager——用户交互管理器
 *
 * 统一管理「Agent 向用户提问」的完整生命周期：
 *   ask          —— 创建问题并登记为待回答
 *   getQuestion  —— 获取问题详情
 *   answer       —— 用户回答后记录答案
 *   pending      —— 列出所有待回答的问题
 *   getHandler   —— 生成 ask_user 工具的 handler（注册到 ToolRegistry 用）
 *   getToolSchema —— ask_user 工具的模型元数据（工具定义）
 *
 * 与 Permission 的区别：
 *   Permission 回答「能不能执行？」（工具调用前做权限检查）
 *   AskUser 回答「我应该怎么做？」（Agent 主动向用户提问，模型自行决定何时问）
 * 两者互不替换、互不干扰。
 *
 * 用法：
 * ```php
 * $uim = new UserInteractionManager();
 *
 * // handler 内部自动创建问题
 * $handler = $uim->getHandler();
 * $result  = $handler(['questions' => [['question' => '要 push 到哪个分支？', 'options' => ['main', 'develop']]]]);
 *
 * echo $result->getStatus();    // 'waiting_user'
 * $questionId = $result->getQuestionId();
 *
 * // 用户回答后
 * $answered = $uim->answer($questionId, 'main');
 * ```
 */
class UserInteractionManager
{
    /** @var array<string, UserQuestion> 全部待回答/已回答的问题 */
    protected $questions = [];

    /**
     * 向用户提问，登记一个待回答的问题
     *
     * @param string $question 问题文本
     * @param string[] $options 可选选项（可空）
     * @param array<string, mixed> $extra 额外数据
     * @return InteractionResult
     */
    public function ask($question, array $options = [], array $extra = [])
    {
        $data = array_merge([
            'question' => (string) $question,
            'options'  => $options,
            'status'   => 'pending',
        ], $extra);
        $item = new UserQuestion($data);
        $this->questions[$item->getId()] = $item;
        return InteractionResult::waiting($item->getId(), $item->getQuestion(), $item->getOptions());
    }

    /**
     * 批量提问（ask_user 工具入口）
     *
     * @param array<int, array<string, mixed>> $questions [['question'=>..., 'options'=>...], ...]
     * @return array<int, InteractionResult>
     */
    public function askMany(array $questions)
    {
        $results = [];
        foreach ($questions as $q) {
            if (!is_array($q)) {
                continue;
            }
            $question = isset($q['question']) ? (string) $q['question'] : '';
            if ($question === '') {
                continue;
            }
            $options = isset($q['options']) && is_array($q['options']) ? $q['options'] : [];
            $results[] = $this->ask($question, array_map('strval', $options));
        }
        return $results;
    }

    /**
     * 获取问题
     *
     * @param string $questionId
     * @return UserQuestion|null
     */
    public function getQuestion($questionId)
    {
        return isset($this->questions[(string) $questionId]) ? $this->questions[(string) $questionId] : null;
    }

    /**
     * 用户回答问题
     *
     * @param string $questionId
     * @param string $answer
     * @return InteractionResult|null 问题不存在返回 null
     */
    public function answer($questionId, $answer)
    {
        $item = $this->getQuestion($questionId);
        if ($item === null) {
            return null;
        }
        if ($item->isPending()) {
            $item->answer($answer);
        }
        return InteractionResult::answered($item->getId(), $item->getAnswer() ? (string) $item->getAnswer() : '');
    }

    /**
     * 列出所有待回答的问题
     *
     * @return array<string, UserQuestion>
     */
    public function pendingQuestions()
    {
        $pending = [];
        foreach ($this->questions as $id => $q) {
            if ($q->isPending()) {
                $pending[$id] = $q;
            }
        }
        return $pending;
    }

    /**
     * 是否有待回答的问题
     *
     * @return bool
     */
    public function hasPending()
    {
        foreach ($this->questions as $q) {
            if ($q->isPending()) {
                return true;
            }
        }
        return false;
    }

    /**
     * 获取全部问题
     *
     * @return array<string, UserQuestion>
     */
    public function all()
    {
        return $this->questions;
    }

    /**
     * 清理已回答的问题（保留待回答的）
     *
     * @return $this
     */
    public function cleanAnswered()
    {
        foreach ($this->questions as $id => $q) {
            if ($q->isAnswered()) {
                unset($this->questions[$id]);
            }
        }
        return $this;
    }

    /**
     * 构造 ask_user 工具的可执行 handler
     *
     * handler 签名：function (array $input): string
     * 输入：['questions' => [['question'=>..., 'options'=>[...]], ...]]
     * 输出：JSON 字符串，包含新问题的 id / question / options
     *
     * @return callable
     */
    public function getHandler()
    {
        $self = $this;
        return function (array $input) use ($self) {
            $questions = isset($input['questions']) && is_array($input['questions']) ? $input['questions'] : [];
            $results = $self->askMany($questions);
            if (!$results) {
                return json_encode([
                    'error'      => true,
                    'message'    => '没有需要询问的问题（questions 为空）',
                    'questions'  => [],
                ], JSON_UNESCAPED_UNICODE);
            }
            $items = [];
            foreach ($results as $result) {
                $items[] = $result->toArray();
            }
            return json_encode([
                'error'      => false,
                'message'    => '已向用户提问，等待用户回答后继续执行。',
                'questions'  => $items,
            ], JSON_UNESCAPED_UNICODE);
        };
    }

    /**
     * 构造 ask_user 工具的模型元数据（工具定义）
     *
     * @return array<string, mixed>
     */
    public function getToolSchema()
    {
        return [
            'description'  => '向用户提问以澄清需求，当任务描述不明确、存在多个合理执行方案或涉及关键选择时使用。'
                . '调用后 Agent 会暂停，等待用户回答，收到答案后继续执行。'
                . '提问不是请求权限（Permission 解决「能不能执行」），而是询问「应该怎么做」。',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'questions' => [
                        'type'        => 'array',
                        'description' => '需要提问的问题列表，一次可问多个',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'question' => [
                                    'type'        => 'string',
                                    'description' => '问题文本',
                                ],
                                'options'  => [
                                    'type'        => 'array',
                                    'description' => '候选项，供用户快速选择（可选）',
                                    'items'       => ['type' => 'string'],
                                ],
                            ],
                            'required'   => ['question'],
                        ],
                    ],
                ],
                'required'   => ['questions'],
            ],
        ];
    }
}