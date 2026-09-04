<?php
namespace Ai\Agent\Reflection;

use Ai\AI;

/**
 * 模型驱动的反思策略——替代关键词判据
 *
 * 默认反思靠关键词表判「任务完成没有」（`containsCompletionMarkers()` 是固定词表，
 * 错误检测认的是 error/failed/exception 这些英文词）：换个措辞、说中文，就判不出来。
 * 这里把判断交给模型本身，用严格的 JSON 输出契约拿回结构化结论。
 *
 * 装配：
 * ```php
 * $rm = new ReflectionManager();
 * // 强烈建议接上兜底：模型判不出来时退回内置判据，而不是直接停
 * $rm->setStrategy(new LlmReflectionStrategy($ai, ['fallback' => $rm->defaultStrategy()]));
 * ```
 *
 * 稳健性：模型请求异常、返回不是合法 JSON、字段缺失，一律走 `fallback`；
 * 没给 fallback 时保守判「完成」并写明原因——判官坏了就停下让人看到，
 * 好过在循环里空转烧钱（轮数上限只是最后一道闸，不该指望它兜底）。
 */
class LlmReflectionStrategy
{
    /** @var AI */
    protected $ai;

    /** @var callable|null 模型不可用时的兜底策略 */
    protected $fallback = null;

    /** @var int 送进提示词的最近消息条数 */
    protected $recent = 8;

    /** @var int 单条消息截断字符数 */
    protected $maxCharsPerMessage = 800;

    /**
     * @param AI $ai
     * @param array<string, mixed> $options fallback / recent / maxCharsPerMessage
     */
    public function __construct(AI $ai, array $options = [])
    {
        $this->ai = $ai;
        if (isset($options['fallback']) && is_callable($options['fallback'])) {
            $this->fallback = $options['fallback'];
        }
        if (isset($options['recent'])) {
            $this->recent = max(1, (int) $options['recent']);
        }
        if (isset($options['maxCharsPerMessage'])) {
            $this->maxCharsPerMessage = max(100, (int) $options['maxCharsPerMessage']);
        }
    }

    /**
     * ReflectionManager::setStrategy() 的契约
     *
     * @param array<int, array<string, mixed>> $messages
     * @param string $goal
     * @param array<string, mixed> $context
     * @return ReflectionResult
     */
    public function __invoke(array $messages, $goal, array $context = [])
    {
        $transcript = $this->transcript($messages);
        $sys = '你是任务完成度评审。根据【目标】与【最近对话】判断任务是否已经完成。'
            . '只输出一个 JSON 对象，不要任何解释或 markdown 围栏：'
            . '{"done": true|false, "reason": "一句话理由", "next": "若未完成，下一步该做什么"}。'
            . '判据：目标要求的产物是否已经拿到；工具是否还在报错；模型是否只是宣告将要做而尚未动手。';
        $user = "【目标】\n" . ($goal !== '' ? $goal : '(未提供明确目标)')
            . "\n\n【最近对话】\n" . $transcript;

        try {
            $reply = $this->ai->chat([
                'system'   => $sys,
                'messages' => [['role' => 'user', 'content' => $user]],
            ])->getContent();
        } catch (\Exception $e) {
            return $this->degrade($messages, $goal, $context, '模型反思请求失败：' . $e->getMessage());
        }

        $data = $this->extractJsonObject((string) $reply);
        if ($data === null || !array_key_exists('done', $data)) {
            return $this->degrade($messages, $goal, $context, '模型反思返回不可解析');
        }

        $done = (bool) $data['done'];
        $reason = isset($data['reason']) ? trim((string) $data['reason']) : '';
        $next = isset($data['next']) ? trim((string) $data['next']) : '';
        $meta = ['strategy' => 'llm'];

        if ($done) {
            return ReflectionResult::completed($reason !== '' ? $reason : '模型判定任务已完成', $meta);
        }
        return ReflectionResult::continuing(
            $reason !== '' ? $reason : '模型判定任务尚未完成',
            $next !== '' ? $next : null,
            $meta
        );
    }

    /**
     * 模型不可用时降级
     *
     * @param array<int, array<string, mixed>> $messages
     * @param string $goal
     * @param array<string, mixed> $context
     * @param string $why
     * @return ReflectionResult
     */
    protected function degrade(array $messages, $goal, array $context, $why)
    {
        \Ai\Helpers\Log::warning('LLM 反思降级', ['reason' => $why]);
        if ($this->fallback !== null) {
            $result = call_user_func($this->fallback, $messages, $goal, $context);
            if ($result instanceof ReflectionResult) {
                return $result;
            }
        }
        return ReflectionResult::completed($why . '，无兜底策略，按完成处理', ['strategy' => 'llm_degraded']);
    }

    /**
     * 把最近若干条消息压成可读文字（逐条截断，控制提示词体积）
     *
     * @param array<int, array<string, mixed>> $messages
     * @return string
     */
    protected function transcript(array $messages)
    {
        $slice = array_slice($messages, -$this->recent);
        $lines = [];
        foreach ($slice as $msg) {
            $role = isset($msg['role']) ? (string) $msg['role'] : '?';
            $text = $this->textOf(isset($msg['content']) ? $msg['content'] : '');
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) > $this->maxCharsPerMessage) {
                $text = mb_substr($text, 0, $this->maxCharsPerMessage) . '…';
            }
            $lines[] = strtoupper($role) . ': ' . $text;
        }
        return implode("\n", $lines);
    }

    /**
     * 从各种 content 形态里抽纯文本（含工具调用与结果的摘要）
     *
     * @param mixed $content
     * @return string
     */
    protected function textOf($content)
    {
        if (is_string($content)) {
            return trim($content);
        }
        if (!is_array($content)) {
            return '';
        }
        $parts = [];
        foreach ($content as $block) {
            if (is_string($block)) {
                $parts[] = $block;
                continue;
            }
            if (!is_array($block)) {
                continue;
            }
            $type = isset($block['type']) ? (string) $block['type'] : '';
            if ($type === 'text' && isset($block['text'])) {
                $parts[] = (string) $block['text'];
            } elseif ($type === 'tool_use') {
                $parts[] = '[调用工具 ' . (isset($block['name']) ? (string) $block['name'] : '?') . ']';
            } elseif ($type === 'tool_result') {
                $body = isset($block['content']) && is_string($block['content']) ? $block['content'] : '';
                $flag = !empty($block['is_error']) ? '[工具报错] ' : '[工具结果] ';
                $parts[] = $flag . $body;
            }
        }
        return trim(implode("\n", $parts));
    }

    /**
     * 从回复里抽出第一个 JSON 对象（容忍 ```json 围栏与前后废话）
     *
     * @param string $reply
     * @return array<string, mixed>|null
     */
    protected function extractJsonObject($reply)
    {
        $reply = trim($reply);
        $decoded = json_decode($reply, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($reply, '{');
        $end = strrpos($reply, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($reply, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }
}
