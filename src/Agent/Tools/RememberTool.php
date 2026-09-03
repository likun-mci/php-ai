<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;
use Ai\Agent\Memory\MemoryManager;

/**
 * remember 工具——让模型主动把值得长期保留的结论写进记忆
 *
 * 记忆写入是持久副作用：默认经 PermissionManager 把关（dont_ask / bypass 放行），
 * 且必须有 MemoryManager 才注册——不注册一个必然失败的工具（见 dev.md 14.2）。
 *
 * 同样内容重复 remember 天然幂等（内容散列 id，见 dev.md 14.3），模型多轮里
 * 重复记同一件事不会把 AGENT.md 撑满。
 *
 * 与 forget 配对使用（见 {@see ForgetTool}）。
 */
class RememberTool implements AgentToolInterface
{
    /** @var MemoryManager */
    protected $memory;

    /**
     * @param MemoryManager $memory
     */
    public function __construct(MemoryManager $memory)
    {
        $this->memory = $memory;
    }

    /** @return string */
    public function name()
    {
        return 'remember';
    }

    /** @return string */
    public function description()
    {
        return '把一条值得长期保留的事实/结论写入记忆。scope=project 记项目共识，'
            . 'user 记用户偏好，agent 记自身经验；session/task 是当前会话/任务的临时便签。'
            . '同一条内容重复写不会产生重复条目。';
    }

    /** @return array<string, mixed> */
    public function schema()
    {
        return [
            'type' => 'object',
            'properties' => [
                'scope' => [
                    'type' => 'string',
                    'enum' => MemoryManager::validScopes(),
                    'description' => '记忆作用域：user / project / agent（长期）或 session / task（短期）',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => '要记住的内容，一句话讲清一件事',
                ],
            ],
            'required' => ['scope', 'content'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param ToolContext $context
     * @return ToolResult
     */
    public function execute(array $input, ToolContext $context)
    {
        $scope = isset($input['scope']) ? (string) $input['scope'] : '';
        $content = isset($input['content']) ? (string) $input['content'] : '';

        if (!MemoryManager::isValidScope($scope)) {
            return ToolResult::error('无效的 scope：' . $scope . '，可选 ' . implode(' / ', MemoryManager::validScopes()));
        }
        if (trim($content) === '') {
            return ToolResult::error('content 不能为空');
        }

        $ok = $this->memory->remember($scope, $content);
        if (!$ok) {
            return ToolResult::error('记忆写入失败（作用域未配置存储文件？）');
        }
        return ToolResult::success('已记住（' . $scope . '）：' . $content);
    }
}
