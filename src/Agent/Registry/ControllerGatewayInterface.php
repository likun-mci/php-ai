<?php
namespace Ai\Agent\Registry;

/**
 * Controller 入口网关 —— 应用唯一需要实现的适配点
 *
 * php-ai 负责「把 PHP 能力标准化成 Agent Tool」，应用负责「这个用户能不能执行」。
 * 两者的交接就是这个接口：Executor 拿到 Tool 的 `@agent-controller` 路径后，
 * 交给它去走应用**现有**的 Controller 入口权限校验与业务分发。
 *
 * ```php
 * class MyGateway implements ControllerGatewayInterface
 * {
 *     public function can($controllerPath, array $context)
 *     {
 *         return $this->auth->check($context['user_id'], $controllerPath);
 *     }
 *
 *     public function dispatch($controllerPath, array $arguments, array $context)
 *     {
 *         // ⚠️ 这里必须再跑一次权限校验，不能因为 can() 过了就直接执行
 *         if (!$this->auth->check($context['user_id'], $controllerPath)) {
 *             throw new \RuntimeException('无权访问 ' . $controllerPath);
 *         }
 *         return $this->router->dispatch($controllerPath, $arguments);
 *     }
 * }
 * ```
 *
 * ⚠️ **最容易犯的致命错误：`dispatch()` 里不做权限校验。**
 * `can()` 只是 Discovery 阶段的候选过滤，跑在「模型还没决定用哪个 Tool」的时候，
 * 结果可能被缓存、可能过期、也可能因为对象级规则（「只能改自己写的文章」）而不完整。
 * 真正的授权只有一个地方：`dispatch()` 里应用自己的那套校验（规范 §14 / §15 / §31.5）。
 *
 * 注：接口方法不写 PHP 类型声明，保持 PHP 7.1 兼容（库的版本下限）。
 */
interface ControllerGatewayInterface
{
    /**
     * Discovery 阶段的乐观过滤：这个 Controller 入口**可能**对当前用户可用吗
     *
     * 判断不了就返回 true —— 宁可多给候选也不要漏，反正执行时还要再校验一次。
     *
     * @param string $controllerPath Controller 入口路径
     * @param array<string, mixed> $context user_id / tenant_id / permissions 等
     * @return bool
     */
    public function can($controllerPath, array $context);

    /**
     * 执行：走应用现有的 Controller 入口（含其权限校验与业务规则）
     *
     * 权限不足时**抛异常**（Executor 会包成失败结果），不要返回 false 了事——
     * 「拒绝」和「业务返回了 false」必须能区分开。
     *
     * @param string $controllerPath
     * @param array<string, mixed> $arguments 已校验、已收敛的入参
     * @param array<string, mixed> $context
     * @return mixed 业务返回值
     */
    public function dispatch($controllerPath, array $arguments, array $context);
}
