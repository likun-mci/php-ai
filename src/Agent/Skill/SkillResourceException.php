<?php
namespace Ai\Agent\Skill;

/**
 * 技能附件访问异常
 *
 * `SkillManager::readResource()` 的所有失败路径（技能不存在、技能没有目录、
 * 路径越界、附件不存在、体积超限、不是文本文件）都抛它，调用方 catch 一个
 * 类型即可。消息是给人看的中文，工具层直接拿去当 `ERROR: ...` 返回。
 */
class SkillResourceException extends \RuntimeException
{
}
