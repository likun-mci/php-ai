<?php
namespace Ai\Agent\Hooks;

use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;

/**
 * Agent 钩子容器
 *
 * 提供完整的生命周期钩子，让业务层可以在 Agent 执行链的各个关键节点注入逻辑：
 *
 *   工具钩子：
 *     before_tool      工具执行前，可短路返回 ToolResult 跳过执行
 *     after_tool       工具执行后，可修改/包装结果
 *     tool_error       工具执行出错后
 *     after_tool_batch 一批工具全部执行完成后、下一次模型调用前
 *
 *   模型钩子：
 *     before_model 模型调用前，可修改请求参数
 *     after_model  模型调用后，可修改/记录响应
 *
 *   权限钩子：
 *     permission_request 权限请求创建时
 *
 *   任务钩子：
 *     task_start / task_complete / task_failed
 *
 *   子 Agent 钩子：
 *     subagent_start / subagent_stop
 *
 *   上下文钩子：
 *     before_compact / after_compact
 *
 *   会话钩子：
 *     agent_start / agent_stop
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
 * $hooks->onAfterToolBatch(function ($results) {
 *     // 整批工具完成后统一处理
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
    protected $toolError = [];

    /** @var callable[] */
    protected $afterToolBatch = [];

    /** @var callable[] */
    protected $beforeModel = [];

    /** @var callable[] */
    protected $afterModel = [];

    /** @var callable[] */
    protected $permissionRequest = [];

    /** @var callable[] */
    protected $taskStart = [];

    /** @var callable[] */
    protected $taskComplete = [];

    /** @var callable[] */
    protected $taskFailed = [];

    /** @var callable[] */
    protected $subagentStart = [];

    /** @var callable[] */
    protected $subagentStop = [];

    /** @var callable[] */
    protected $beforeCompact = [];

    /** @var callable[] */
    protected $afterCompact = [];

    /** @var callable[] */
    protected $agentStart = [];

    /** @var callable[] */
    protected $agentStop = [];

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
     * 注册 tool_error 钩子（工具执行出错时触发）
     *
     * 签名：function (string $name, ToolResult $result): void
     *
     * @param callable $cb
     * @return $this
     */
    public function onToolError($cb)
    {
        $this->toolError[] = $cb;
        return $this;
    }

    /**
     * 注册 after_tool_batch 钩子（一批工具全部执行完成后、下一次模型调用前触发）
     *
     * 签名：function (array $results): array
     * 接收并返回 array<int, array{type: string, tool_use_id: string, content: string, is_error: bool}>
     *
     * @param callable $cb
     * @return $this
     */
    public function onAfterToolBatch($cb)
    {
        $this->afterToolBatch[] = $cb;
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
     * 注册 permission_request 钩子（权限请求创建时触发）
     *
     * 签名：function (string $toolName, array $input, string $requestId): HookResult
     *
     * @param callable $cb
     * @return $this
     */
    public function onPermissionRequest($cb)
    {
        $this->permissionRequest[] = $cb;
        return $this;
    }

    /**
     * 注册 task_start 钩子
     *
     * 签名：function (string $taskId, string $goal): void
     *
     * @param callable $cb
     * @return $this
     */
    public function onTaskStart($cb)
    {
        $this->taskStart[] = $cb;
        return $this;
    }

    /**
     * 注册 task_complete 钩子
     *
     * 签名：function (string $taskId, string $result): void
     *
     * @param callable $cb
     * @return $this
     */
    public function onTaskComplete($cb)
    {
        $this->taskComplete[] = $cb;
        return $this;
    }

    /**
     * 注册 task_failed 钩子
     *
     * 签名：function (string $taskId, string $error): void
     *
     * @param callable $cb
     * @return $this
     */
    public function onTaskFailed($cb)
    {
        $this->taskFailed[] = $cb;
        return $this;
    }

    /**
     * 注册 subagent_start 钩子
     *
     * 签名：function (string $agentName, string $task): void
     *
     * @param callable $cb
     * @return $this
     */
    public function onSubagentStart($cb)
    {
        $this->subagentStart[] = $cb;
        return $this;
    }

    /**
     * 注册 subagent_stop 钩子
     *
     * 签名：function (string $agentName, string $result): void
     *
     * @param callable $cb
     * @return $this
     */
    public function onSubagentStop($cb)
    {
        $this->subagentStop[] = $cb;
        return $this;
    }

    /**
     * 注册 before_compact 钩子（上下文压缩前触发）
     *
     * 签名：function (int $tokenCount, int $messageCount): void
     *
     * @param callable $cb
     * @return $this
     */
    public function onBeforeCompact($cb)
    {
        $this->beforeCompact[] = $cb;
        return $this;
    }

    /**
     * 注册 after_compact 钩子（上下文压缩后触发）
     *
     * 签名：function (int $messageCount): void
     *
     * @param callable $cb
     * @return $this
     */
    public function onAfterCompact($cb)
    {
        $this->afterCompact[] = $cb;
        return $this;
    }

    /**
     * 注册 agent_start 钩子
     *
     * 签名：function (): void
     *
     * @param callable $cb
     * @return $this
     */
    public function onAgentStart($cb)
    {
        $this->agentStart[] = $cb;
        return $this;
    }

    /**
     * 注册 agent_stop 钩子
     *
     * 签名：function (string $stopReason): void
     *
     * @param callable $cb
     * @return $this
     */
    public function onAgentStop($cb)
    {
        $this->agentStop[] = $cb;
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
    public function hasToolError()
    {
        return (bool) $this->toolError;
    }

    /** @return bool */
    public function hasAfterToolBatch()
    {
        return (bool) $this->afterToolBatch;
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

    /** @return bool */
    public function hasPermissionRequest()
    {
        return (bool) $this->permissionRequest;
    }

    /** @return bool */
    public function hasTaskStart()
    {
        return (bool) $this->taskStart;
    }

    /** @return bool */
    public function hasTaskComplete()
    {
        return (bool) $this->taskComplete;
    }

    /** @return bool */
    public function hasTaskFailed()
    {
        return (bool) $this->taskFailed;
    }

    /** @return bool */
    public function hasSubagentStart()
    {
        return (bool) $this->subagentStart;
    }

    /** @return bool */
    public function hasSubagentStop()
    {
        return (bool) $this->subagentStop;
    }

    /** @return bool */
    public function hasBeforeCompact()
    {
        return (bool) $this->beforeCompact;
    }

    /** @return bool */
    public function hasAfterCompact()
    {
        return (bool) $this->afterCompact;
    }

    /** @return bool */
    public function hasAgentStart()
    {
        return (bool) $this->agentStart;
    }

    /** @return bool */
    public function hasAgentStop()
    {
        return (bool) $this->agentStop;
    }

    /* ---------- 触发器 ---------- */

    /**
     * 触发 after_tool_batch 钩子链
     *
     * @param array<int, array{type: string, tool_use_id: string, content: string, is_error: bool}> $results
     * @return array<int, array{type: string, tool_use_id: string, content: string, is_error: bool}>
     */
    public function triggerAfterToolBatch(array $results)
    {
        foreach ($this->afterToolBatch as $cb) {
            $results = call_user_func($cb, $results);
            if (!is_array($results)) {
                $results = [];
            }
        }
        return $results;
    }

    /**
     * 触发 tool_error 钩子链
     *
     * @param string $name
     * @param ToolResult $result
     * @return void
     */
    public function triggerToolError($name, ToolResult $result)
    {
        foreach ($this->toolError as $cb) {
            call_user_func($cb, $name, $result);
        }
    }

    /**
     * 触发 permission_request 钩子链
     *
     * @param string $toolName
     * @param array<string, mixed> $input
     * @param string $requestId
     * @return HookResult|null
     */
    public function triggerPermissionRequest($toolName, array $input, $requestId)
    {
        foreach ($this->permissionRequest as $cb) {
            $result = call_user_func($cb, $toolName, $input, $requestId);
            if ($result instanceof HookResult) {
                return $result;
            }
        }
        return null;
    }

    /**
     * 触发 task_start 钩子链
     *
     * @param string $taskId
     * @param string $goal
     * @return void
     */
    public function triggerTaskStart($taskId, $goal)
    {
        foreach ($this->taskStart as $cb) {
            call_user_func($cb, $taskId, $goal);
        }
    }

    /**
     * 触发 task_complete 钩子链
     *
     * @param string $taskId
     * @param string $result
     * @return void
     */
    public function triggerTaskComplete($taskId, $result)
    {
        foreach ($this->taskComplete as $cb) {
            call_user_func($cb, $taskId, $result);
        }
    }

    /**
     * 触发 task_failed 钩子链
     *
     * @param string $taskId
     * @param string $error
     * @return void
     */
    public function triggerTaskFailed($taskId, $error)
    {
        foreach ($this->taskFailed as $cb) {
            call_user_func($cb, $taskId, $error);
        }
    }

    /**
     * 触发 subagent_start 钩子链
     *
     * @param string $agentName
     * @param string $task
     * @return void
     */
    public function triggerSubagentStart($agentName, $task)
    {
        foreach ($this->subagentStart as $cb) {
            call_user_func($cb, $agentName, $task);
        }
    }

    /**
     * 触发 subagent_stop 钩子链
     *
     * @param string $agentName
     * @param string $result
     * @return void
     */
    public function triggerSubagentStop($agentName, $result)
    {
        foreach ($this->subagentStop as $cb) {
            call_user_func($cb, $agentName, $result);
        }
    }

    /**
     * 触发 before_compact 钩子链
     *
     * @param int $tokenCount
     * @param int $messageCount
     * @return void
     */
    public function triggerBeforeCompact($tokenCount, $messageCount)
    {
        foreach ($this->beforeCompact as $cb) {
            call_user_func($cb, $tokenCount, $messageCount);
        }
    }

    /**
     * 触发 after_compact 钩子链
     *
     * @param int $messageCount
     * @return void
     */
    public function triggerAfterCompact($messageCount)
    {
        foreach ($this->afterCompact as $cb) {
            call_user_func($cb, $messageCount);
        }
    }

    /**
     * 触发 agent_start 钩子链
     *
     * @return void
     */
    public function triggerAgentStart()
    {
        foreach ($this->agentStart as $cb) {
            call_user_func($cb);
        }
    }

    /**
     * 触发 agent_stop 钩子链
     *
     * @param string $stopReason
     * @return void
     */
    public function triggerAgentStop($stopReason)
    {
        foreach ($this->agentStop as $cb) {
            call_user_func($cb, $stopReason);
        }
    }
}