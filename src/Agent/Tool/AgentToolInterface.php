<?php
namespace Ai\Agent\Tool;

/**
 * Agent 工具接口
 *
 * 把一个工具从「闭包」升级为「一等对象」，让工具拥有稳定的元数据与执行入口，
 * 权限系统（Phase 3）与工具发现（Phase 7）都建立在这个接口之上。
 *
 * 用法：
 * ```php
 * class ReadFileTool implements AgentToolInterface
 * {
 *     public function name(): string        { return 'read_file'; }
 *     public function description(): string { return '读取工作区内的文件内容'; }
 *     public function schema(): array       { return ['type' => 'object', ...]; }
 *
 *     public function execute(array $input, ToolContext $context): ToolResult
 *     {
 *         return ToolResult::success('文件内容');
 *     }
 * }
 * ```
 *
 * 旧格式的闭包工具（['description'=>..., 'handler'=>function(){}]）由 ToolRegistry
 * 自动包装成本接口的匿名实现，两种写法可混用。
 *
 * 注：本接口方法不写 PHP 类型声明，保持 PHP 7.1 兼容（库的版本下限）。
 */
interface AgentToolInterface
{
    /** 工具名（模型调用时使用的标识，如 read_file）
     * @return string
     */
    public function name();

    /** 工具用途说明（注入给模型的 description）
     * @return string
     */
    public function description();

    /** 参数 JSON Schema（input_schema 结构）
     * @return array<string, mixed>
     */
    public function schema();

    /** 执行工具
     * @param array<string, mixed> $input 模型传入的参数
     * @param ToolContext $context 运行时上下文（工作目录 / 事件发射 / 取消标志）
     * @return ToolResult
     */
    public function execute(array $input, ToolContext $context);
}
