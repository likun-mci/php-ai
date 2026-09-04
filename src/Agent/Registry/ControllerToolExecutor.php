<?php
namespace Ai\Agent\Registry;

use Ai\Agent\Tool\ToolDefinition;
use Ai\Agent\Tool\ToolResult;

/**
 * Tool 执行器 —— Agent Tool Call 落到应用现有 Controller 入口
 *
 * 执行链（规范 §30.2）：
 *
 * ```text
 * Agent Tool Call
 *   ↓ Registry 取定义（不存在 / 禁用 → 拒绝）
 *   ↓ controllerPath 为空 → 拒绝（不允许拿 class/method 反射直调，§31.6）
 *   ↓ ArgumentValidator（必填、类型、枚举；未声明的参数丢弃）
 *   ↓ RiskPolicy（high/critical 未确认 → 要求确认）
 *   ↓ ControllerGatewayInterface::dispatch()
 *       ↓ 应用现有 Controller 入口权限校验  ← 真正的安全边界
 *       ↓ Controller / Service → 业务数据库
 *   ↓ ToolResult
 * ```
 *
 * ```php
 * $executor = new ControllerToolExecutor($registry, $gateway);
 * $result = $executor->execute('article.update', ['id' => 12, 'title' => '新标题'], [
 *     'user_id'   => 7,
 *     'confirmed' => false,
 * ]);
 *
 * if (!$result->isSuccess()) {
 *     $meta = $result->getMetadata();
 *     if (!empty($meta['requires_confirmation'])) {
 *         // 提示用户确认后，带 confirmed => true 重新调用
 *     }
 * }
 * ```
 *
 * 返回值复用 `Ai\Agent\Tool\ToolResult`，可以直接回填给模型。
 */
class ControllerToolExecutor
{
    /** @var ToolRegistryInterface */
    protected $registry;

    /** @var ControllerGatewayInterface */
    protected $gateway;

    /** @var RiskPolicy */
    protected $riskPolicy;

    /** @var callable|null 审计钩子 function(array $record) */
    protected $onExecuted = null;

    /** @var bool 未声明的参数是否报错（默认只丢弃并在 metadata 里说明） */
    protected $strictArguments = false;

    /**
     * @param ToolRegistryInterface $registry
     * @param ControllerGatewayInterface $gateway
     * @param array<string, mixed> $options risk_policy / strict_arguments
     */
    public function __construct(
        ToolRegistryInterface $registry,
        ControllerGatewayInterface $gateway,
        array $options = []
    ) {
        $this->registry = $registry;
        $this->gateway  = $gateway;

        $this->riskPolicy = isset($options['risk_policy']) && $options['risk_policy'] instanceof RiskPolicy
            ? $options['risk_policy']
            : new RiskPolicy();

        if (isset($options['strict_arguments'])) {
            $this->strictArguments = (bool) $options['strict_arguments'];
        }
    }

    /** @return RiskPolicy */
    public function riskPolicy()
    {
        return $this->riskPolicy;
    }

    /**
     * @param RiskPolicy $policy
     * @return $this
     */
    public function setRiskPolicy(RiskPolicy $policy)
    {
        $this->riskPolicy = $policy;
        return $this;
    }

    /**
     * 审计钩子：每次执行（成功 / 失败 / 被拒）都回调一次
     *
     * @param callable|null $cb function(array $record)
     * @return $this
     */
    public function onExecuted($cb)
    {
        $this->onExecuted = is_callable($cb) ? $cb : null;
        return $this;
    }

    /**
     * 执行一个 Tool
     *
     * @param string $name Tool 名
     * @param array<string, mixed> $arguments 模型给的原始参数
     * @param array<string, mixed> $context user_id / tenant_id / permissions / confirmed 等
     * @return ToolResult
     */
    public function execute($name, array $arguments = [], array $context = [])
    {
        $started = microtime(true);
        $name    = (string) $name;

        $tool = $this->registry->get($name);
        if ($tool === null) {
            return $this->fail($name, null, '未知 Tool: ' . $name, ['reason' => 'not_found'], $started, $context);
        }

        if (!$tool->isEnabled()) {
            return $this->fail(
                $name,
                $tool,
                'Tool 已禁用: ' . $name,
                ['reason' => 'disabled'],
                $started,
                $context
            );
        }

        $controllerPath = $tool->getControllerPath();
        if ($controllerPath === '') {
            // 没有 Controller 入口就没有权限边界。宁可不执行，也不退回反射直调。
            return $this->fail(
                $name,
                $tool,
                'Tool ' . $name . ' 未声明 @agent-controller，拒绝执行',
                ['reason' => 'no_controller'],
                $started,
                $context
            );
        }

        $validated = ArgumentValidator::validate($tool, $arguments);
        if (!$validated['ok']) {
            return $this->fail(
                $name,
                $tool,
                '参数校验失败: ' . implode('; ', $validated['errors']),
                ['reason' => 'invalid_arguments', 'errors' => $validated['errors']],
                $started,
                $context
            );
        }
        if ($this->strictArguments && $validated['dropped'] !== []) {
            return $this->fail(
                $name,
                $tool,
                '包含未声明的参数: ' . implode(', ', $validated['dropped']),
                ['reason' => 'unknown_arguments', 'dropped' => $validated['dropped']],
                $started,
                $context
            );
        }

        $confirmed = !empty($context['confirmed']);
        if (!$confirmed && $this->riskPolicy->needsConfirmation($tool)) {
            return $this->fail(
                $name,
                $tool,
                '该操作风险等级为 ' . $tool->getRisk() . '，需要用户确认后才能执行',
                [
                    'reason'                => 'requires_confirmation',
                    'requires_confirmation' => true,
                    'forced'                => $this->riskPolicy->isForced($tool),
                    'risk'                  => $tool->getRisk(),
                    'controller'            => $controllerPath,
                    'arguments'             => $validated['arguments'],
                ],
                $started,
                $context
            );
        }

        try {
            $value = $this->gateway->dispatch($controllerPath, $validated['arguments'], $context);
        } catch (\Exception $e) {
            return $this->fail(
                $name,
                $tool,
                $e->getMessage(),
                ['reason' => 'exception', 'exception' => get_class($e)],
                $started,
                $context
            );
        } catch (\Throwable $e) {
            // PHP 7 的 Error（TypeError 之类）也要兜住，否则一个参数不对就把整个 Agent 打断
            return $this->fail(
                $name,
                $tool,
                $e->getMessage(),
                ['reason' => 'error', 'exception' => get_class($e)],
                $started,
                $context
            );
        }

        $metadata = [
            'tool'        => $name,
            'controller'  => $controllerPath,
            'risk'        => $tool->getRisk(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
        if ($validated['dropped'] !== []) {
            $metadata['dropped_arguments'] = $validated['dropped'];
        }

        $result = ToolResult::success($this->stringify($value), $metadata);
        $this->audit($name, $tool, true, '', $metadata, $context);
        return $result;
    }

    /**
     * 业务返回值 → 回填给模型的文本
     *
     * 数组/对象转 JSON（不转义中文与斜杠，模型读得懂也省 token）；标量原样转字符串。
     *
     * @param mixed $value
     * @return string
     */
    protected function stringify($value)
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '' : $json;
    }

    /**
     * @param string $name
     * @param ToolDefinition|null $tool
     * @param string $error
     * @param array<string, mixed> $metadata
     * @param float $started
     * @param array<string, mixed> $context
     * @return ToolResult
     */
    protected function fail($name, $tool, $error, array $metadata, $started, array $context)
    {
        $metadata['tool']        = $name;
        $metadata['risk']        = $tool !== null ? $tool->getRisk() : '';
        $metadata['duration_ms'] = (int) round((microtime(true) - $started) * 1000);

        $this->audit($name, $tool, false, $error, $metadata, $context);
        return ToolResult::error($error, $metadata);
    }

    /**
     * @param string $name
     * @param ToolDefinition|null $tool
     * @param bool $success
     * @param string $error
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $context
     * @return void
     */
    protected function audit($name, $tool, $success, $error, array $metadata, array $context)
    {
        if ($this->onExecuted === null) {
            return;
        }
        call_user_func($this->onExecuted, [
            'tool'       => $name,
            'controller' => $tool !== null ? $tool->getControllerPath() : '',
            'risk'       => $tool !== null ? $tool->getRisk() : '',
            'success'    => $success,
            'error'      => $error,
            'metadata'   => $metadata,
            'user_id'    => isset($context['user_id']) ? $context['user_id'] : null,
            'time'       => time(),
        ]);
    }
}
