<?php
namespace Ai\Agent\Team;

/**
 * AgentCommunication——团队消息总线
 *
 * 成员之间的消息在这里投递与留存。每个角色有自己的收件箱，`inbox()` 取走未读，
 * 全量历史留在 `history()` 里供事后复盘——多角色协作出问题时，光看最终结果
 * 判断不出是哪一环传歪了。
 *
 * ```php
 * $bus = new AgentCommunication();
 * $bus->send(AgentMessage::task('manager', 'developer', '实现登录接口'));
 * $bus->broadcast(AgentMessage::status('manager', '需求已冻结'));
 *
 * foreach ($bus->inbox('developer') as $msg) {   // 取走后标记为已读
 *     echo $msg->toPrompt();
 * }
 * ```
 */
class AgentCommunication
{
    /** @var array<string, AgentMessage[]> 角色 => 未读消息 */
    protected $inboxes = [];

    /** @var AgentMessage[] 全量历史 */
    protected $history = [];

    /** @var string[] 已知成员角色名，用于广播 */
    protected $members = [];

    /** @var int 历史保留上限，超出后丢最早的 */
    protected $maxHistory = 500;

    /** @var callable|null 消息投递时的钩子 function(AgentMessage): void */
    protected $listener = null;

    /**
     * @param array<string, mixed> $options maxHistory / listener
     */
    public function __construct(array $options = [])
    {
        if (isset($options['maxHistory'])) {
            $this->maxHistory = max(1, (int) $options['maxHistory']);
        }
        if (isset($options['listener'])) {
            $this->listener = $options['listener'];
        }
    }

    /**
     * 登记一个成员（建立收件箱）
     *
     * @param string $role
     * @return $this
     */
    public function addMember($role)
    {
        $role = (string) $role;
        if ($role !== '' && !in_array($role, $this->members, true)) {
            $this->members[] = $role;
            $this->inboxes[$role] = [];
        }
        return $this;
    }

    /**
     * 移除成员（连同未读消息）
     *
     * @param string $role
     * @return $this
     */
    public function removeMember($role)
    {
        $role = (string) $role;
        $this->members = array_values(array_filter($this->members, function ($m) use ($role) {
            return $m !== $role;
        }));
        unset($this->inboxes[$role]);
        return $this;
    }

    /**
     * 投递一条消息
     *
     * `to` 为空的消息按广播处理，进每个成员（除发送者自己）的收件箱。
     *
     * @param AgentMessage $message
     * @return $this
     */
    public function send(AgentMessage $message)
    {
        $this->record($message);

        if ($message->isBroadcast()) {
            foreach ($this->members as $role) {
                if ($role === $message->getFrom()) {
                    continue;
                }
                $this->inboxes[$role][] = $message;
            }
            return $this;
        }

        $to = $message->getTo();
        if (!isset($this->inboxes[$to])) {
            // 给未登记的角色发消息：先建收件箱，消息不该因为登记顺序丢掉
            $this->addMember($to);
        }
        $this->inboxes[$to][] = $message;
        return $this;
    }

    /**
     * 广播一条消息
     *
     * @param AgentMessage $message
     * @return $this
     */
    public function broadcast(AgentMessage $message)
    {
        if (!$message->isBroadcast()) {
            $message = new AgentMessage(
                $message->getFrom(),
                '',
                $message->getType(),
                $message->getContent(),
                $message->getMetadata()
            );
        }
        return $this->send($message);
    }

    /**
     * 取走某个角色的未读消息（取走即清空）
     *
     * @param string $role
     * @return AgentMessage[]
     */
    public function inbox($role)
    {
        $role = (string) $role;
        if (!isset($this->inboxes[$role])) {
            return [];
        }
        $messages = $this->inboxes[$role];
        $this->inboxes[$role] = [];
        return $messages;
    }

    /**
     * 查看未读消息但不取走
     *
     * @param string $role
     * @return AgentMessage[]
     */
    public function peek($role)
    {
        $role = (string) $role;
        return isset($this->inboxes[$role]) ? $this->inboxes[$role] : [];
    }

    /**
     * 某个角色有几条未读
     *
     * @param string $role
     * @return int
     */
    public function unreadCount($role)
    {
        return count($this->peek($role));
    }

    /**
     * 把未读消息拼成可注入的提示词文本
     *
     * @param string $role
     * @param bool $consume 是否同时标记为已读
     * @return string 没有未读时返回空串
     */
    public function inboxPrompt($role, $consume = true)
    {
        $messages = $consume ? $this->inbox($role) : $this->peek($role);
        if (!$messages) {
            return '';
        }
        $parts = [];
        foreach ($messages as $message) {
            $parts[] = $message->toPrompt();
        }
        return "<team-messages>\n" . implode("\n\n", $parts) . "\n</team-messages>";
    }

    /**
     * 全量历史
     *
     * @param string $type 按类型过滤，空串表示全部
     * @return AgentMessage[]
     */
    public function history($type = '')
    {
        $type = (string) $type;
        if ($type === '') {
            return $this->history;
        }
        return array_values(array_filter($this->history, function (AgentMessage $m) use ($type) {
            return $m->getType() === $type;
        }));
    }

    /**
     * 某两个角色之间的往来消息
     *
     * @param string $roleA
     * @param string $roleB
     * @return AgentMessage[]
     */
    public function between($roleA, $roleB)
    {
        $roleA = (string) $roleA;
        $roleB = (string) $roleB;
        return array_values(array_filter($this->history, function (AgentMessage $m) use ($roleA, $roleB) {
            return ($m->getFrom() === $roleA && $m->getTo() === $roleB)
                || ($m->getFrom() === $roleB && $m->getTo() === $roleA);
        }));
    }

    /**
     * 已登记成员
     *
     * @return string[]
     */
    public function members()
    {
        return $this->members;
    }

    /**
     * 设置消息投递钩子（用于日志、事件转发）
     *
     * @param callable|null $listener function(AgentMessage $message): void
     * @return $this
     */
    public function onMessage($listener)
    {
        $this->listener = $listener;
        return $this;
    }

    /**
     * 清空收件箱与历史
     *
     * @return $this
     */
    public function clear()
    {
        foreach (array_keys($this->inboxes) as $role) {
            $this->inboxes[$role] = [];
        }
        $this->history = [];
        return $this;
    }

    /**
     * 导出全部消息
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArray()
    {
        $items = [];
        foreach ($this->history as $message) {
            $items[] = $message->toArray();
        }
        return $items;
    }

    /**
     * 记入历史并触发钩子
     *
     * @param AgentMessage $message
     * @return void
     */
    protected function record(AgentMessage $message)
    {
        $this->history[] = $message;
        if (count($this->history) > $this->maxHistory) {
            $this->history = array_slice($this->history, -$this->maxHistory);
        }
        if ($this->listener !== null) {
            call_user_func($this->listener, $message);
        }
    }
}
