<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;
use Ai\Agent\Memory\MemoryManager;

/**
 * forget 工具——删除一条记忆
 *
 * 删除策略（见 dev.md 14.1 / 14.3）：优先按 memory_id 精确删；未命中再按
 * pattern 子串匹配，pattern **命中多条时返回候选列表让模型确认**，绝不直接
 * 批量删——pattern 批量删太容易误伤。
 *
 * 与 remember 一样属持久副作用，默认经 PermissionManager 把关，且必须有
 * MemoryManager 才注册（见 dev.md 14.2）。
 */
class ForgetTool implements AgentToolInterface
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
        return 'forget';
    }

    /** @return string */
    public function description()
    {
        return '删除一条记忆。优先传 memory_id 精确删除；也可传 pattern 按内容子串删除，'
            . '但 pattern 命中多条时会返回候选让你确认，不会批量删。';
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
                    'description' => '记忆作用域',
                ],
                'memory_id' => [
                    'type' => 'string',
                    'description' => '要删除的记忆 id（注入记忆里 (#xxxx) 括号内的值），优先用它',
                ],
                'pattern' => [
                    'type' => 'string',
                    'description' => '无 id 时按内容子串匹配删除；命中多条会返回候选而非批量删',
                ],
            ],
            'required' => ['scope'],
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
        $memoryId = isset($input['memory_id']) ? (string) $input['memory_id'] : '';
        $pattern = isset($input['pattern']) ? (string) $input['pattern'] : '';

        if (!MemoryManager::isValidScope($scope)) {
            return ToolResult::error('无效的 scope：' . $scope);
        }

        // 一、优先按 id 精确删
        if (trim($memoryId) !== '') {
            $n = $this->memory->forgetById($scope, $memoryId);
            if ($n > 0) {
                return ToolResult::success('已删除记忆 #' . ltrim(strtolower(trim($memoryId)), '#') . '（' . $scope . '）');
            }
            // id 未命中，若也没给 pattern 就报未找到；给了 pattern 则继续走 pattern
            if (trim($pattern) === '') {
                return ToolResult::error('未找到 id 为 ' . $memoryId . ' 的记忆');
            }
        }

        // 二、按 pattern 匹配
        if (trim($pattern) === '') {
            return ToolResult::error('请提供 memory_id 或 pattern');
        }
        $candidates = $this->memory->findByPattern($scope, $pattern);
        if (!$candidates) {
            return ToolResult::error('没有匹配「' . $pattern . '」的记忆');
        }
        if (count($candidates) === 1) {
            $c = $candidates[0];
            if ($c['id'] !== '') {
                $this->memory->forgetById($scope, $c['id']);
            } else {
                $this->memory->forgetByText($scope, $c['text']);
            }
            return ToolResult::success('已删除唯一匹配（' . $scope . '）：' . $c['text']);
        }

        // 命中多条：返回候选让模型确认，不批量删
        $lines = [];
        foreach ($candidates as $c) {
            $tag = $c['id'] !== '' ? '#' . $c['id'] : '(无 id)';
            $lines[] = $tag . '  ' . $c['text'];
        }
        return ToolResult::success(
            'pattern「' . $pattern . '」命中 ' . count($candidates) . " 条，未删除。请用 memory_id 指定要删哪条：\n"
            . implode("\n", $lines)
        );
    }
}
