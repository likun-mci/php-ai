<?php
namespace AgentAppPhp8Fixture;

use Ai\Agent\Attribute\AgentTool;

/**
 * PHP 8 Attribute 版的标注示例
 *
 * 注意每个 `#[AgentTool(...)]` 都写在**一行**里：PHP 7 把 `#` 开头的整行当注释，
 * 于是这个文件在 7.1 上照样能解析，只是不会产出任何 Tool（AttributeParser 在
 * PHP 8 以下直接返回空）。这正是「PHP 8 增强、PHP 7 不炸」的写法。
 */
class UserController
{
    #[AgentTool(name: 'user.read', description: '读取用户资料', controller: 'user/read', risk: 'low', keywords: ['用户', '资料'])]
    public function read($id)
    {
        return ['id' => $id, 'name' => 'demo'];
    }

    /**
     * Attribute 与 PHPDoc 同时存在时，Attribute 的字段优先
     *
     * @agent-tool user.update.docname
     * @agent-description 来自 PHPDoc 的描述
     * @agent-controller user/from-doc
     * @agent-risk low
     *
     * @param int $id 用户 ID
     * @param string|null $nickname 昵称
     * @return array 更新后的用户
     */
    #[AgentTool(name: 'user.update', description: '修改用户资料', controller: 'user/update', risk: 'medium')]
    public function update($id, $nickname = null)
    {
        return ['id' => $id, 'nickname' => $nickname];
    }

    /**
     * 没有 Attribute 也没有 @agent-tool，不该入库
     *
     * @param string $s
     * @return string
     */
    public function helper($s)
    {
        return $s;
    }
}
