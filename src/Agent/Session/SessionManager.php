<?php
namespace Ai\Agent\Session;

/**
 * 会话管理器
 *
 * 协调 Agent 会话的创建、恢复、保存、暂停/恢复/中断。
 * 在 AgentRuntime 的 run() 前后自动保存/恢复会话状态。
 *
 * 用法：
 * ```php
 * $sm = new SessionManager(new FileSessionStore('/tmp/sessions'));
 *
 * // 恢复会话
 * $session = $sm->resume('abc123');
 * if ($session) {
 *     $agent->run($session->getMessages());
 * }
 *
 * // 保存会话
 * $sm->save($sessionId, $messages, $system);
 * ```
 */
class SessionManager
{
    /** @var AgentSessionStoreInterface */
    protected $store;

    /**
     * @param AgentSessionStoreInterface $store
     */
    public function __construct(AgentSessionStoreInterface $store)
    {
        $this->store = $store;
    }

    /** @return AgentSessionStoreInterface */
    public function getStore()
    {
        return $this->store;
    }

    /**
     * 创建新会话
     *
     * @param string $id
     * @param array<string, mixed> $data
     * @return AgentSession
     */
    public function create($id, array $data = [])
    {
        $session = new AgentSession($id, $data);
        $this->store->save($session);
        return $session;
    }

    /**
     * 恢复会话（加载并标记为 running）
     *
     * @param string $id
     * @return AgentSession|null
     */
    public function resume($id)
    {
        $session = $this->store->load($id);
        if ($session === null) {
            return null;
        }
        if ($session->isPaused() || $session->isRunning()) {
            $session->resume();
            $this->store->save($session);
        }
        return $session;
    }

    /**
     * 暂停会话
     *
     * @param string $id
     * @return void
     */
    public function pause($id)
    {
        $session = $this->store->load($id);
        if ($session) {
            $session->pause();
            $this->store->save($session);
        }
    }

    /**
     * 中断会话
     *
     * @param string $id
     * @return void
     */
    public function interrupt($id)
    {
        $session = $this->store->load($id);
        if ($session) {
            $session->interrupt();
            $this->store->save($session);
        }
    }

    /**
     * 标记会话为完成
     *
     * @param string $id
     * @return void
     */
    public function complete($id)
    {
        $session = $this->store->load($id);
        if ($session) {
            $session->complete();
            $this->store->save($session);
        }
    }

    /**
     * 保存会话进度
     *
     * @param string $id 会话 ID
     * @param array<int, array<string, mixed>> $messages 当前消息
     * @param string $system 系统提示词
     * @param array<string, mixed> $extra 额外数据
     * @return void
     */
    public function save($id, array $messages, $system = '', array $extra = [])
    {
        $session = $this->store->load($id);
        if ($session === null) {
            $session = new AgentSession($id, [
                'messages' => $messages,
                'system'   => $system,
                'extra'    => $extra,
            ]);
        } else {
            $session->setMessages($messages);
            // 修复：既有会话此前 system/extra 未被真正写回（见 dev.md 第二十节）
            if ($system !== '') {
                $session->setSystem($system);
            }
            if ($extra) {
                $session->setExtra($extra);
            }
        }
        $this->store->save($session);
    }

    /**
     * 删除会话
     *
     * @param string $id
     * @return void
     */
    public function delete($id)
    {
        $this->store->delete($id);
    }
}