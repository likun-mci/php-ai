<?php
namespace Ai\Agent\Registry;

/**
 * 闭包实现的 Controller 网关
 *
 * 小应用、原型和测试里不必为了两个方法专门写一个类：
 *
 * ```php
 * $gateway = new CallableControllerGateway(
 *     function ($path, array $args, array $ctx) use ($router) {
 *         // ⚠️ 这里同样必须自己做权限校验
 *         if (!$auth->check($ctx['user_id'], $path)) {
 *             throw new \RuntimeException('无权访问 ' . $path);
 *         }
 *         return $router->dispatch($path, $args);
 *     },
 *     function ($path, array $ctx) use ($auth) {
 *         return $auth->check($ctx['user_id'], $path);   // 可选，缺省一律返回 true
 *     }
 * );
 * ```
 *
 * 省略 `$can` 时 Discovery 不做网关级过滤（仍受 ToolSearchContext 的权限列表约束）。
 */
class CallableControllerGateway implements ControllerGatewayInterface
{
    /** @var callable function(string $path, array $arguments, array $context): mixed */
    protected $dispatcher;

    /** @var callable|null function(string $path, array $context): bool */
    protected $checker;

    /**
     * @param callable $dispatcher
     * @param callable|null $checker
     */
    public function __construct($dispatcher, $checker = null)
    {
        if (!is_callable($dispatcher)) {
            throw new RegistryException('CallableControllerGateway 需要一个可调用的 dispatcher');
        }
        $this->dispatcher = $dispatcher;
        $this->checker    = is_callable($checker) ? $checker : null;
    }

    /**
     * @param string $controllerPath
     * @param array<string, mixed> $context
     * @return bool
     */
    public function can($controllerPath, array $context)
    {
        if ($this->checker === null) {
            return true;
        }
        return (bool) call_user_func($this->checker, $controllerPath, $context);
    }

    /**
     * @param string $controllerPath
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     * @return mixed
     */
    public function dispatch($controllerPath, array $arguments, array $context)
    {
        return call_user_func($this->dispatcher, $controllerPath, $arguments, $context);
    }
}
