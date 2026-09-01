<?php
namespace Ai\Agent\Tool;

/**
 * 工具执行结果（值对象）
 *
 * 将工具返回值从「裸字符串」升级为结构化结果，包含成功/失败状态、
 * 实际内容、错误信息、元数据与截断标志。
 *
 * 模型收到 tool_result 时看到的是 content 字段，UI 层可以同时读取
 * success / error 来决定如何展示，不再需要在字符串里 grep "ERROR:"。
 *
 * 用法：
 * ```php
 * // 成功
 * return ToolResult::success('文件内容', ['file' => 'a.php', 'lines' => 50]);
 *
 * // 失败
 * return ToolResult::error('文件不存在', ['path' => '/tmp/x']);
 * ```
 */
class ToolResult
{
    /** @var bool */
    protected $success = true;

    /** @var mixed 工具返回的数据（字符串或数组） */
    protected $content = '';

    /** @var string 错误描述（仅 success 为 false 时有意义） */
    protected $error = '';

    /** @var array<string, mixed> 元数据（文件大小、耗时等） */
    protected $metadata = [];

    /** @var bool 是否为部分结果（如大文件截断后返回前 N 行） */
    protected $isPartial = false;

    /** @var string|null 用于 UI 显示的文本（可能是截断后的摘要），null 则用 content */
    protected $display = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->success   = isset($data['success']) ? (bool) $data['success'] : true;
        $this->content   = array_key_exists('content', $data) ? $data['content'] : '';
        $this->error     = isset($data['error']) ? (string) $data['error'] : '';
        $this->metadata  = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [];
        $this->isPartial = isset($data['is_partial']) ? (bool) $data['is_partial'] : false;
        $this->display   = array_key_exists('display', $data) ? $data['display'] : null;
    }

    /** 创建成功结果
     * @param mixed $content 工具返回数据
     * @param array<string, mixed> $metadata
     * @return self
     */
    public static function success($content = '', array $metadata = [])
    {
        return new self([
            'success'  => true,
            'content'  => $content,
            'metadata' => $metadata,
        ]);
    }

    /** 创建失败结果
     * @param string $error 错误描述
     * @param array<string, mixed> $metadata
     * @return self
     */
    public static function error($error, array $metadata = [])
    {
        return new self([
            'success'  => false,
            'content'  => 'ERROR: ' . $error,
            'error'    => $error,
            'metadata' => $metadata,
        ]);
    }

    /** 是否成功
     * @return bool
     */
    public function isSuccess()
    {
        return $this->success;
    }

    /** 获取内容（回填给模型的正文）
     * @return mixed
     */
    public function getContent()
    {
        return $this->content;
    }

    /** 获取错误信息
     * @return string
     */
    public function getError()
    {
        return $this->error;
    }

    /** 获取元数据
     * @return array<string, mixed>
     */
    public function getMetadata()
    {
        return $this->metadata;
    }

    /** 是否为部分结果
     * @return bool
     */
    public function isPartial()
    {
        return $this->isPartial;
    }

    /** 获取 UI 展示文本
     * @return string
     */
    public function getDisplay()
    {
        return $this->display !== null ? $this->display : (is_string($this->content) ? $this->content : '');
    }

    /** 转为字符串（回填给模型时使用）
     * @return string
     */
    public function __toString()
    {
        if ($this->success) {
            return is_string($this->content) ? $this->content : (string) json_encode($this->content, JSON_UNESCAPED_UNICODE);
        }
        return 'ERROR: ' . $this->error;
    }

    /** 转为数组
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'success'    => $this->success,
            'content'    => $this->content,
            'error'      => $this->error,
            'metadata'   => $this->metadata,
            'is_partial' => $this->isPartial,
        ];
    }
}