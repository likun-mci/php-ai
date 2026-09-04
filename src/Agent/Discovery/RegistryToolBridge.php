<?php
namespace Ai\Agent\Discovery;

use Ai\Agent\Registry\ControllerGatewayInterface;
use Ai\Agent\Registry\ControllerToolExecutor;
use Ai\Agent\Registry\RiskPolicy;
use Ai\Agent\Registry\ToolRegistryInterface;
use Ai\Agent\Registry\ToolSearchContext;
use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolResult;

/**
 * 把 Tool Registry 接进现有 Agent
 *
 * 产出三个运行时工具，模型初始只看到它们三个，而不是几千条业务能力：
 *
 * | 工具 | 作用 |
 * |---|---|
 * | `search_app_tools` | 用自然语言搜业务能力，返回候选摘要 |
 * | `get_app_tool` | 拉某个能力的完整 JSON Schema |
 * | `call_app_tool` | 调用它（经 Controller 入口 + 应用现有权限校验） |
 *
 * ```php
 * $bridge = new RegistryToolBridge($registry, $gateway);
 * $bridge->setContext(new ToolSearchContext(['user_id' => 7, 'permissions' => $perms]));
 *
 * $agent->tools($bridge->tools());   // 追加，不覆盖已有的运行时工具
 * ```
 *
 * 工具名刻意避开了现有 `ToolDiscovery` 的 `search_tools`：那个搜的是 Agent 自己的
 * 运行时工具（read_file / bash…），这里搜的是**应用的业务能力**，两者不是一回事，
 * 同名会让模型分不清。
 */
class RegistryToolBridge
{
    /** @var ToolSearcher */
    protected $searcher;

    /** @var ControllerToolExecutor */
    protected $executor;

    /** @var ToolSearchContext */
    protected $context;

    /** @var array<string, mixed> 传给 Gateway 的执行上下文 */
    protected $executionContext = [];

    /** @var int 一次搜索最多返回几个候选 */
    protected $maxResults = 8;

    /**
     * @param ToolRegistryInterface $registry
     * @param ControllerGatewayInterface $gateway
     * @param array<string, mixed> $options context / risk_policy / max_results / strict_arguments
     */
    public function __construct(
        ToolRegistryInterface $registry,
        ControllerGatewayInterface $gateway,
        array $options = []
    ) {
        $this->context = isset($options['context']) && $options['context'] instanceof ToolSearchContext
            ? $options['context']
            : new ToolSearchContext();

        if (isset($options['max_results'])) {
            $this->maxResults = max(1, (int) $options['max_results']);
        }

        $this->searcher = new ToolSearcher($registry, $gateway, $this->context);

        $execOptions = [];
        if (isset($options['risk_policy']) && $options['risk_policy'] instanceof RiskPolicy) {
            $execOptions['risk_policy'] = $options['risk_policy'];
        }
        if (isset($options['strict_arguments'])) {
            $execOptions['strict_arguments'] = $options['strict_arguments'];
        }
        $this->executor = new ControllerToolExecutor($registry, $gateway, $execOptions);
    }

    /**
     * 设置当前用户上下文（每个请求都该设一次）
     *
     * @param ToolSearchContext $context
     * @return $this
     */
    public function setContext(ToolSearchContext $context)
    {
        $this->context = $context;
        $this->searcher->setContext($context);
        return $this;
    }

    /**
     * 追加传给 Gateway 的执行上下文（会与搜索上下文合并）
     *
     * @param array<string, mixed> $context
     * @return $this
     */
    public function setExecutionContext(array $context)
    {
        $this->executionContext = $context;
        return $this;
    }

    /** @return ToolSearcher */
    public function searcher()
    {
        return $this->searcher;
    }

    /** @return ControllerToolExecutor */
    public function executor()
    {
        return $this->executor;
    }

    /**
     * 三个工具，可直接喂给 `Agent::tools()`
     *
     * @return array<string, AgentToolInterface>
     */
    public function tools()
    {
        return [
            'search_app_tools' => $this->searchTool(),
            'get_app_tool'     => $this->getTool(),
            'call_app_tool'    => $this->callTool(),
        ];
    }

    /**
     * 合并搜索上下文与执行上下文，交给 Gateway
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function buildExecutionContext(array $extra = [])
    {
        $ctx = $this->executionContext;
        $ctx['user_id']     = $this->context->getUserId();
        $ctx['tenant_id']   = $this->context->getTenantId();
        $ctx['permissions'] = $this->context->getPermissions();
        foreach ($this->context->getExtra() as $k => $v) {
            if (!array_key_exists($k, $ctx)) {
                $ctx[$k] = $v;
            }
        }
        foreach ($extra as $k => $v) {
            $ctx[$k] = $v;
        }
        return $ctx;
    }

    /** @return AgentToolInterface */
    protected function searchTool()
    {
        $searcher = $this->searcher;
        $max      = $this->maxResults;

        return new CallbackTool(
            'search_app_tools',
            '搜索本应用可用的业务能力（如「文章 修改」「订单 退款」）。返回候选能力的名称、'
            . '简介与风险等级；确定要用哪个之后，再用 get_app_tool 取它的完整参数说明。',
            [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => '描述你想做的事，中文关键词即可，如「修改文章标题」',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => '最多返回几个候选，默认 ' . $max,
                    ],
                ],
                'required'   => ['query'],
            ],
            function (array $input) use ($searcher, $max) {
                $query = isset($input['query']) ? (string) $input['query'] : '';
                $limit = isset($input['limit']) ? max(1, (int) $input['limit']) : $max;

                $ctx = clone $searcher->context();
                $ctx->setLimit($limit);

                $rows = $searcher->summaries($query, $ctx);
                if ($rows === []) {
                    return ToolResult::success(
                        '没有找到匹配的业务能力。可以换个说法再搜一次，或用更宽泛的关键词。',
                        ['query' => $query, 'count' => 0]
                    );
                }
                return ToolResult::success(
                    (string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ['query' => $query, 'count' => count($rows)]
                );
            }
        );
    }

    /** @return AgentToolInterface */
    protected function getTool()
    {
        $searcher = $this->searcher;

        return new CallbackTool(
            'get_app_tool',
            '取某个业务能力的完整定义：参数名、类型、是否必填、风险等级。'
            . '调用 call_app_tool 之前先用它确认参数怎么填。',
            [
                'type'       => 'object',
                'properties' => [
                    'name' => [
                        'type'        => 'string',
                        'description' => '业务能力名称，来自 search_app_tools 的结果，如 article.update',
                    ],
                ],
                'required'   => ['name'],
            ],
            function (array $input) use ($searcher) {
                $name = isset($input['name']) ? (string) $input['name'] : '';
                $tool = $searcher->get($name);
                if ($tool === null) {
                    return ToolResult::error(
                        '找不到业务能力: ' . $name . '（可能不存在、已禁用，或当前用户不可见）',
                        ['name' => $name]
                    );
                }
                $payload = [
                    'name'        => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'risk'        => $tool->getRisk(),
                    'parameters'  => $tool->schema(),
                    'returns'     => $tool->getReturns(),
                ];
                return ToolResult::success(
                    (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ['name' => $name, 'risk' => $tool->getRisk()]
                );
            }
        );
    }

    /** @return AgentToolInterface */
    protected function callTool()
    {
        $self = $this;

        return new CallbackTool(
            'call_app_tool',
            '调用一个业务能力。会走应用现有的权限校验，无权限时返回错误。'
            . '高风险操作需要用户确认：先不带 confirmed 调用一次，把返回的确认提示转达用户，'
            . '得到明确同意后再带 confirmed=true 重试。',
            [
                'type'       => 'object',
                'properties' => [
                    'name'      => [
                        'type'        => 'string',
                        'description' => '业务能力名称，如 article.update',
                    ],
                    'arguments' => [
                        'type'        => 'object',
                        'description' => '参数对象，键名与 get_app_tool 返回的 parameters 一致',
                    ],
                    'confirmed' => [
                        'type'        => 'boolean',
                        'description' => '用户是否已明确同意执行这个高风险操作，默认 false',
                    ],
                ],
                'required'   => ['name'],
            ],
            function (array $input) use ($self) {
                return $self->invoke($input);
            }
        );
    }

    /**
     * `call_app_tool` 的实际执行体（独立出来便于测试）
     *
     * @param array<string, mixed> $input
     * @return ToolResult
     */
    public function invoke(array $input)
    {
        $name = isset($input['name']) ? (string) $input['name'] : '';
        if ($name === '') {
            return ToolResult::error('call_app_tool 缺少 name 参数');
        }

        $args = isset($input['arguments']) ? $input['arguments'] : [];
        if (is_string($args)) {
            // 模型偶尔把对象序列化成 JSON 字符串再传
            $decoded = json_decode($args, true);
            $args    = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($args)) {
            $args = [];
        }

        // 先过一遍 Discovery 的可见性：当前上下文看不到的能力，不该能被直接点名调用。
        // 这只是少给一条路，真正的拒绝仍然发生在 Gateway 里。
        if ($this->searcher->get($name) === null) {
            return ToolResult::error(
                '找不到业务能力: ' . $name . '（可能不存在、已禁用，或当前用户不可见）',
                ['name' => $name, 'reason' => 'not_visible']
            );
        }

        $ctx = $this->buildExecutionContext([
            'confirmed' => !empty($input['confirmed']),
        ]);

        return $this->executor->execute($name, $args, $ctx);
    }
}
