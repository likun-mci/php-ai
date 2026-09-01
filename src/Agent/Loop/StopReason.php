<?php
namespace Ai\Agent\Loop;

/**
 * Agent 停止原因枚举
 *
 * 将停止原因从「隐式字符串」升级为显式常量，便于运行时判断与扩展。
 * 后续 Phase 会逐步加入更多停止条件。
 */
class StopReason
{
    /** 模型给出最终回答，没有工具调用 */
    const END_TURN = 'end_turn';

    /** 已达最大迭代次数上限 */
    const MAX_ITER = 'max_iter';

    /** 模型重复调用同一工具 + 相同参数，判断为无进展 */
    const NO_PROGRESS = 'no_progress';

    /** 工具执行出错 */
    const TOOL_ERROR = 'tool_error';

    /** 用户取消 */
    const USER_CANCEL = 'user_cancel';

    /** 预算超限（Phase 7 实现） */
    const BUDGET_EXCEEDED = 'budget_exceeded';

    /** 超时（Phase 7 实现） */
    const TIMEOUT = 'timeout';

    /** 权限被拒绝 */
    const PERMISSION_DENIED = 'permission_denied';

    /** 等待用户授权（Agent 暂停，需 resume） */
    const WAITING_PERMISSION = 'waiting_permission';

    /** 模型返回错误 */
    const MODEL_ERROR = 'model_error';

    /**
     * 获取所有停止原因
     * @return string[]
     */
    public static function all()
    {
        return [
            self::END_TURN,
            self::MAX_ITER,
            self::NO_PROGRESS,
            self::TOOL_ERROR,
            self::USER_CANCEL,
            self::BUDGET_EXCEEDED,
            self::TIMEOUT,
            self::PERMISSION_DENIED,
            self::MODEL_ERROR,
        ];
    }
}