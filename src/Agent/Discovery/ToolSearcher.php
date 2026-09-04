<?php
namespace Ai\Agent\Discovery;

use Ai\Agent\Registry\ControllerGatewayInterface;
use Ai\Agent\Registry\ToolRegistryInterface;
use Ai\Agent\Registry\ToolSearchContext;
use Ai\Agent\Tool\ToolDefinition;

/**
 * Tool 搜索 —— Discovery 阶段的入口
 *
 * 大型应用有几千个 Tool，全塞进 prompt 既烧 token 又让模型更难选（规范 §12）。
 * 正确姿势是：模型先搜，拿到 Top N 候选摘要，选定后再拉完整 Schema。
 *
 * ```php
 * $searcher = new ToolSearcher($registry, $gateway);
 * $searcher->setContext(new ToolSearchContext(['permissions' => $userPerms]));
 *
 * $searcher->summaries('文章 修改');   // 候选摘要（省 token）
 * $searcher->get('article.update');    // 完整定义 + JSON Schema
 * ```
 *
 * ⚠️ **这里的权限过滤只是 Discovery 优化，不是安全边界**（规范 §14）。
 * 搜不到 ≠ 没权限，搜得到 ≠ 有权限。真正的判定在
 * `ControllerToolExecutor` → `ControllerGatewayInterface::dispatch()` → 应用现有校验。
 */
class ToolSearcher
{
    /** @var ToolRegistryInterface */
    protected $registry;

    /** @var ControllerGatewayInterface|null 有则参与候选过滤（乐观） */
    protected $gateway = null;

    /** @var ToolSearchContext 默认上下文 */
    protected $context;

    /**
     * @param ToolRegistryInterface $registry
     * @param ControllerGatewayInterface|null $gateway
     * @param ToolSearchContext|null $context
     */
    public function __construct(ToolRegistryInterface $registry, $gateway = null, $context = null)
    {
        $this->registry = $registry;
        if ($gateway instanceof ControllerGatewayInterface) {
            $this->gateway = $gateway;
        }
        $this->context = $context instanceof ToolSearchContext ? $context : new ToolSearchContext();
    }

    /**
     * @param ToolSearchContext $context
     * @return $this
     */
    public function setContext(ToolSearchContext $context)
    {
        $this->context = $context;
        return $this;
    }

    /** @return ToolSearchContext */
    public function context()
    {
        return $this->context;
    }

    /** @return ToolRegistryInterface */
    public function registry()
    {
        return $this->registry;
    }

    /**
     * 搜索候选 Tool
     *
     * @param string $query 自然语言 / 关键词；空串返回前 N 个
     * @param ToolSearchContext|null $context 不传则用默认上下文
     * @return ToolDefinition[]
     */
    public function search($query, $context = null)
    {
        $ctx  = $context instanceof ToolSearchContext ? $context : $this->context;
        $list = $this->registry->search($query, $ctx);

        if ($this->gateway === null) {
            return $list;
        }

        $gctx = $this->gatewayContext($ctx);
        $out  = [];
        foreach ($list as $tool) {
            $path = $tool->getControllerPath();
            // 没有 Controller 入口的 Tool 网关无从判断，留给 Executor 去拒
            if ($path !== '' && !$this->gateway->can($path, $gctx)) {
                continue;
            }
            $out[] = $tool;
        }
        return $out;
    }

    /**
     * 搜索并返回轻量摘要（给模型看的候选列表）
     *
     * @param string $query
     * @param ToolSearchContext|null $context
     * @return array<int, array<string, mixed>>
     */
    public function summaries($query, $context = null)
    {
        $out = [];
        foreach ($this->search($query, $context) as $tool) {
            $out[] = $tool->summary();
        }
        return $out;
    }

    /**
     * 取完整 Tool 定义
     *
     * 禁用的、或当前上下文过滤掉的，一律返回 null —— 让「拿不到 Schema」这件事
     * 与「搜不到」保持一致，不给模型制造「明明搜到了却拉不到」的困惑。
     *
     * @param string $name
     * @param ToolSearchContext|null $context
     * @return ToolDefinition|null
     */
    public function get($name, $context = null)
    {
        $ctx  = $context instanceof ToolSearchContext ? $context : $this->context;
        $tool = $this->registry->get($name);
        if ($tool === null) {
            return null;
        }
        if (!$tool->isEnabled() && !$ctx->includeDisabled()) {
            return null;
        }
        if (!$ctx->allows($tool->getControllerPath(), $tool->getPermissions())) {
            return null;
        }
        if ($this->gateway !== null && $tool->getControllerPath() !== ''
            && !$this->gateway->can($tool->getControllerPath(), $this->gatewayContext($ctx))
        ) {
            return null;
        }
        return $tool;
    }

    /**
     * 把搜索上下文摊平成网关能读的数组
     *
     * @param ToolSearchContext $ctx
     * @return array<string, mixed>
     */
    protected function gatewayContext(ToolSearchContext $ctx)
    {
        $out = $ctx->getExtra();
        $out['user_id']     = $ctx->getUserId();
        $out['tenant_id']   = $ctx->getTenantId();
        $out['permissions'] = $ctx->getPermissions();
        return $out;
    }
}
