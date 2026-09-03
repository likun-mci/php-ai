<?php
namespace Ai\Agent\Session;

/**
 * 归属校验会话存储（装饰器）
 *
 * 包裹任意 AgentSessionStoreInterface，在保存时盖上 userId / projectId 归属戳，
 * 在加载时校验当前身份是否与会话记录一致（见 dev.md 4.3 第三层）。
 *
 * 为什么用装饰器：AgentRuntime 内部有多处 save 会话的调用点，把归属逻辑做进
 * 装饰器可一次覆盖全部写入路径，而不必逐处改动。路径正确不代替授权校验——
 * 即便文件路径对得上，userId / project 不匹配也拒绝加载并 Log::error。
 *
 * 生效范围提醒（见 dev.md 4.3）：没传 userId 时会话落在 projects/<slug>/sessions/，
 * 路径已含 slug，本校验是冗余的；只有传了 userId（会话落 users/ 下、路径不含 slug）
 * 时它才真正起作用。跨目录 resume 会因 projectId 变化被拒——这是刻意选择，
 * 需要跨目录请显式 Agent::setProjectRoot() 固定项目身份，不提供开关。
 */
class OwnedSessionStore implements AgentSessionStoreInterface
{
    /** @var AgentSessionStoreInterface 被包裹的底层存储 */
    protected $inner;

    /** @var string 期望的 userId（空表示不限制该维度） */
    protected $userId;

    /** @var string 期望的项目身份 slug（空表示不限制该维度） */
    protected $projectId;

    /**
     * @param AgentSessionStoreInterface $inner
     * @param string $userId
     * @param string $projectId
     */
    public function __construct(AgentSessionStoreInterface $inner, $userId = '', $projectId = '')
    {
        $this->inner = $inner;
        $this->userId = (string) $userId;
        $this->projectId = (string) $projectId;
    }

    /** @return AgentSessionStoreInterface */
    public function inner()
    {
        return $this->inner;
    }

    /**
     * 加载并校验归属，不匹配则拒绝（返回 null）
     *
     * @param string $id
     * @return AgentSession|null
     */
    public function load($id)
    {
        $session = $this->inner->load($id);
        if ($session === null) {
            return null;
        }
        if (!$session->belongsTo($this->userId, $this->projectId)) {
            \Ai\Helpers\Log::error('会话归属校验失败，拒绝加载', [
                'id'               => (string) $id,
                'expected_user'    => $this->userId,
                'session_user'     => $session->getUserId(),
                'expected_project' => $this->projectId,
                'session_project'  => $session->getProjectId(),
            ]);
            return null;
        }
        return $session;
    }

    /**
     * 盖归属戳后保存
     *
     * @param AgentSession $session
     * @return void
     */
    public function save(AgentSession $session)
    {
        if ($this->userId !== '' && $session->getUserId() === '') {
            $session->setUserId($this->userId);
        }
        if ($this->projectId !== '' && $session->getProjectId() === '') {
            $session->setProjectId($this->projectId);
        }
        $this->inner->save($session);
    }

    /**
     * @param string $id
     * @return void
     */
    public function delete($id)
    {
        $this->inner->delete($id);
    }
}
