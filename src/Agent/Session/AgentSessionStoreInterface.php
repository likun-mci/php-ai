<?php
namespace Ai\Agent\Session;

/**
 * 会话存储接口
 *
 * 支持多种存储后端（文件 / 数据库 / Redis），
 * 让 PHP-FPM 每次请求都能恢复 Agent 会话。
 */
interface AgentSessionStoreInterface
{
    /**
     * 加载会话
     *
     * @param string $id
     * @return AgentSession|null 不存在返回 null
     */
    public function load($id);

    /**
     * 保存会话
     *
     * @param AgentSession $session
     * @return void
     */
    public function save(AgentSession $session);

    /**
     * 删除会话
     *
     * @param string $id
     * @return void
     */
    public function delete($id);
}