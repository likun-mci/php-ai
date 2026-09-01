<?php
namespace Ai\Agent\Hooks;

use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;

/**
 * Agent 钩子容器
 *
 * 提供四类钩子注册点，让业务层可以在 Agent 执行链的关键节点注入逻辑：
 *
 *   before_tool  — 工具执行前，可短路返回 ToolResult 跳过执行
 *   after_tool   — 工具执行后，可修改/包装结果
 *   before_model — 模型调用前，可修改请求参数
 *   after_model  — 模型调用后，可修改/记录响应
 *
 * 用法：
 * ```php
 * $hooks = new AgentHooks();
 * $hooks->onBeforeTool(function ($name, $input, $ctx) {
 *     return null; // 返回 null 继续执行，返回 ToolResult 则短路
 * });
 * $hooks->onAfterTool(function ($name, $result) {
 *     return $result; // 可修改结果
 * });
 * ```
 */
class AgentHooks
{
    /** @var callable[] */
    protected $beforeTool = [];

    /** @var callable[] */
    protected $afterTool = [];

    /** @var callable[] */
    protected $beforeModel = [];

    /** @var callable[] */
    protected $afterModel = [];

    /**
     * 注册 before_tool 钩子
     *
     * 签名：function (string $name, array $input, ToolContext $ctx): ?ToolResult
     * 返回 ToolResult 则短路执行（不执行实际工具）；返回 null 则继续。
     *
     * @param callable $cb
     * @return $this
     */
    public function onBeforeTool($cb)
    {
        $this->beforeTool[] = $cb;
        return $this;
    }

    /**
     * 注册 after_tool 钩子
     *
     * 签名：function (string $name, ToolResult $result): ToolResult
     * 可修改/包装结果后返回。
     *
     * @param callable $cb
     * @return $this
     */
    public function onAfterTool($cb)
    {
        $this->afterTool[] = $cb;
        return $this;
    }

    /**
     * 注册 before_model 钩子
     *
     * 签名：function (array $messages, array $tools): array
     * 返回 ['messages' => [...], 'tools' => [...]] 可修改请求参数。
     *
     * @param callable $cb
     * @return $this
     */
    public function onBeforeModel($cb)
    {
        $this->beforeModel[] = $cb;
        return $this;
    }

    /**
     * 注册 after_model 钩子
     *
     * 签名：function ($response): $response
     * 可修改/记录响应后返回。
     *
     * @param callable $cb
     * @return $this
     */
    public function onAfterModel($cb)
    {
        $this->afterModel[] = $cb;
        return $this;
    }

    /**
     * 触发 before_tool 钩子链
     *
     * @param string $name
     * @param array<string, mixed> $input
     * @param ToolContext $ctx
     * @return ToolResult|null 返回 ToolResult 表示短路，null 表示继续
     */
    public function triggerBeforeTool($name, array $input, ToolContext $ctx)
    {
        foreach ($this->beforeTool as $cb) {
            $result = call_user_func($cb, $name, $input, $ctx);
            if ($result instanceof ToolResult) {
                return $result;
            }
        }
        return null;
    }

    /**
     * 触发 after_tool 钩子链
     *
     * @param string $name
     * @param ToolResult $result
     * @return ToolResult
     */
    public function triggerAfterTool($name, ToolResult $result)
    {
        foreach ($this->afterTool as $cb) {
            $result = call_user_func($cb, $name, $result);
            if (!$result instanceof ToolResult) {
                $result = ToolResult::error('钩子返回类型错误');
            }
        }
        return $result;
    }

    /**
     * 触发 before_model 钩子链
     *
     * @param array<string, mixed> $params chat() 参数数组 ['system'=>..., 'messages'=>..., 'tools'=>...]
     * @return array<string, mixed> 修改后的参数
     */
    public function triggerBeforeModel(array $params)
    {
        $messages = isset($params['messages']) ? $params['messages'] : [];
        $tools = isset($params['tools']) ? $params['tools'] : [];
        foreach ($this->beforeModel as $cb) {
            $result = call_user_func($cb, $messages, $tools);
            if (is_array($result)) {
                if (isset($result['messages'])) {
                    $messages = $result['messages'];
                }
                if (isset($result['tools'])) {
                    $tools = $result['tools'];
                }
            }
        }
        $params['messages'] = $messages;
        $params['tools'] = $tools;
        return $params;
    }

    /**
     * 触发 after_model 钩子链
     *
     * @param mixed $response chat() 返回的响应
     * @return mixed
     */
    public function triggerAfterModel($response)
    {
        foreach ($this->afterModel as $cb) {
            $response = call_user_func($cb, $response);
        }
        return $response;
    }

    /** @return bool */
    public function hasBeforeTool()
    {
        return (bool) $this->beforeTool;
    }

    /** @return bool */
    public function hasAfterTool()
    {
        return (bool) $this->afterTool;
    }

    /** @return bool */
    public function hasBeforeModel()
    {
        return (bool) $this->beforeModel;
    }

    /** @return bool */
    public function hasAfterModel()
    {
        return (bool) $this->afterModel;
    }
}