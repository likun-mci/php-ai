<?php
namespace Ai\Helpers;

/**
 * 日志出口
 *
 * 库内部原先散落着 error_log()，把日志硬编码进了 PHP 的错误日志：
 * 用 Monolog / CodeIgniter Log / Laravel Log 的项目收不到这些信息，
 * 排查线上问题时只能去翻 php_errors.log。
 *
 * 这里提供一个可注入的出口。**不引入 psr/log 依赖**（本库坚持零硬依赖），
 * 而是鸭子类型地兼容 PSR-3：任何带 log($level, $message, $context) 方法的
 * 对象都能直接塞进来，Monolog、Laravel、CI 的 Logger 均满足。
 *
 * ```php
 * // 1) 直接给 PSR-3 logger（Monolog 等）
 * Ai\Helpers\Log::setLogger($monolog);
 *
 * // 2) 给闭包，自己决定往哪写
 * Ai\Helpers\Log::setLogger(function ($level, $message, array $context) {
 *     log_message($level, '[AI] ' . $message . ' ' . json_encode($context));
 * });
 *
 * // 3) 传 null 恢复默认（error_log）
 * Ai\Helpers\Log::setLogger(null);
 * ```
 */
class Log
{
    /** @var callable|object|null */
    protected static $logger = null;

    /**
     * 注入日志器
     *
     * @param callable|object|null $logger PSR-3 风格对象、callable，或 null 恢复默认
     */
    public static function setLogger($logger = null): void
    {
        if ($logger !== null && !is_callable($logger) && !(is_object($logger) && method_exists($logger, 'log'))) {
            throw new \InvalidArgumentException(
                'Logger 必须是 callable，或带 log($level, $message, $context) 方法的对象（PSR-3 兼容）'
            );
        }
        self::$logger = $logger;
    }

    /**
     * 当前是否注入了自定义日志器
     */
    public static function hasLogger(): bool
    {
        return self::$logger !== null;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    /**
     * 写日志。未注入日志器时退回 error_log()，与改造前行为一致
     * @param array<string, mixed> $context
     */
    protected static function write(string $level, string $message, array $context): void
    {
        $logger = self::$logger;

        if ($logger === null) {
            $suffix = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
            error_log('[Ai] ' . $message . $suffix);
            return;
        }

        try {
            if (is_object($logger) && method_exists($logger, 'log')) {
                $logger->log($level, $message, $context);   // PSR-3
            } elseif (is_callable($logger)) {
                call_user_func($logger, $level, $message, $context);
            }
        } catch (\Throwable $e) {
            // 日志器自身出错绝不能影响主流程，退回最原始的方式
            error_log('[Ai] logger failed: ' . $e->getMessage() . ' | original: ' . $message);
        }
    }
}
