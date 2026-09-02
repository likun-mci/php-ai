<?php
namespace Ai\Agent\Orchestrator;

/**
 * ExecutionStrategy——执行策略常量
 *
 * Agent 拿到一个任务后有多种干法：直接调工具、先拆计划、委派给子 Agent、
 * 并行铺开、丢后台跑、先问用户、只做验证。选哪种由 `StrategySelector` 决定，
 * 这个类只负责把七种策略与它们的元信息集中在一处。
 *
 * ```php
 * ExecutionStrategy::isValid('delegate');       // true
 * ExecutionStrategy::describe('plan');          // '先把任务拆成有序步骤再执行'
 * ExecutionStrategy::all();                     // 七种策略
 * ```
 */
class ExecutionStrategy
{
    /** 直接执行：模型自己调工具解决 */
    const DIRECT = 'direct';

    /** 先规划：任务复杂，先拆成有序步骤 */
    const PLAN = 'plan';

    /** 委派：交给某个专职子 Agent */
    const DELEGATE = 'delegate';

    /** 并行：拆成互不相关的几路同时跑 */
    const PARALLEL = 'parallel';

    /** 后台：耗时长，丢后台异步执行 */
    const BACKGROUND = 'background';

    /** 询问用户：需求不清楚，先问再干 */
    const ASK_USER = 'ask_user';

    /** 验证：只跑验证，不改东西 */
    const VERIFY = 'verify';

    /** @var array<string, string> 策略 => 说明 */
    protected static $descriptions = [
        self::DIRECT     => '直接调用工具解决',
        self::PLAN       => '先把任务拆成有序步骤再执行',
        self::DELEGATE   => '委派给专职子 Agent',
        self::PARALLEL   => '拆成互不相关的几路并行执行',
        self::BACKGROUND => '丢到后台异步执行，立即返回 task_id',
        self::ASK_USER   => '需求不明确，先向用户确认',
        self::VERIFY     => '只执行验证，不做改动',
    ];

    /**
     * 全部策略
     *
     * @return string[]
     */
    public static function all()
    {
        return array_keys(self::$descriptions);
    }

    /**
     * @param string $strategy
     * @return bool
     */
    public static function isValid($strategy)
    {
        return isset(self::$descriptions[(string) $strategy]);
    }

    /**
     * 策略说明
     *
     * @param string $strategy
     * @return string 未知策略返回空串
     */
    public static function describe($strategy)
    {
        $strategy = (string) $strategy;
        return isset(self::$descriptions[$strategy]) ? self::$descriptions[$strategy] : '';
    }

    /**
     * 该策略是否会把活交给别的 Agent 干
     *
     * @param string $strategy
     * @return bool
     */
    public static function isDelegating($strategy)
    {
        return in_array((string) $strategy, [self::DELEGATE, self::PARALLEL], true);
    }

    /**
     * 该策略是否需要在主循环之外执行
     *
     * 后台与询问用户都不会在当前这轮跑完：前者交给调度，后者要等人回话。
     *
     * @param string $strategy
     * @return bool
     */
    public static function isDeferred($strategy)
    {
        return in_array((string) $strategy, [self::BACKGROUND, self::ASK_USER], true);
    }
}
