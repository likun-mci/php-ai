<?php
namespace Ai\Agent\Task;

/**
 * 任务状态枚举常量
 *
 * 定义 AgentTask 的完整生命周期状态。
 * 状态流转：
 *   queued -> running -> waiting_permission/waiting_user -> paused -> completed/failed/cancelled
 *   running -> paused -> running
 *   waiting_permission -> running (approve) / failed (deny)
 *   waiting_user -> running (answer)
 */
class TaskStatus
{
    /** 已排队，等待调度 */
    const QUEUED = 'queued';

    /** 正在执行 */
    const RUNNING = 'running';

    /** 等待权限审批（工具调用需用户授权） */
    const WAITING_PERMISSION = 'waiting_permission';

    /** 等待用户输入（Agent 需要用户回答） */
    const WAITING_USER = 'waiting_user';

    /** 已暂停（可恢复） */
    const PAUSED = 'paused';

    /** 已完成（正常结束） */
    const COMPLETED = 'completed';

    /** 已失败（执行出错） */
    const FAILED = 'failed';

    /** 已取消（用户手动取消） */
    const CANCELLED = 'cancelled';

    /**
     * 获取所有状态
     * @return string[]
     */
    public static function all()
    {
        return [
            self::QUEUED,
            self::RUNNING,
            self::WAITING_PERMISSION,
            self::WAITING_USER,
            self::PAUSED,
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
        ];
    }

    /**
     * 获取最终状态（不可再流转）
     * @return string[]
     */
    public static function terminal()
    {
        return [
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
        ];
    }

    /**
     * 判断是否为最终状态
     * @param string $status
     * @return bool
     */
    public static function isTerminal($status)
    {
        return in_array((string) $status, self::terminal(), true);
    }

    /**
     * 获取活跃状态（可继续执行）
     * @return string[]
     */
    public static function active()
    {
        return [
            self::QUEUED,
            self::RUNNING,
            self::WAITING_PERMISSION,
            self::WAITING_USER,
            self::PAUSED,
        ];
    }

    /**
     * 判断是否为活跃状态
     * @param string $status
     * @return bool
     */
    public static function isActive($status)
    {
        return in_array((string) $status, self::active(), true);
    }

    /**
     * 校验状态值是否合法
     * @param string $status
     * @return bool
     */
    public static function isValid($status)
    {
        return in_array((string) $status, self::all(), true);
    }
}