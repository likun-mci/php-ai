<?php
namespace Ai\Agent\Indexer;

/**
 * 一次扫描的结果统计
 *
 * CLI 打印它，部署脚本按 `hasErrors()` 决定要不要中断发布，
 * `--check` 模式按 `isStale()` 返回退出码。
 */
class IndexResult
{
    /** @var int 遍历到的 PHP 文件数 */
    public $filesScanned = 0;

    /** @var int hash 未变、直接跳过的文件数（增量命中） */
    public $filesSkipped = 0;

    /** @var int 真正被解析（含 Reflection）的文件数 */
    public $filesParsed = 0;

    /** @var int 新增的 Tool 数 */
    public $toolsAdded = 0;

    /** @var int 更新的 Tool 数 */
    public $toolsUpdated = 0;

    /** @var int 删除的 Tool 数（源文件消失或标注被移除） */
    public $toolsRemoved = 0;

    /** @var string[] 本次涉及的 Tool 名 */
    public $tools = [];

    /** @var string[] 错误信息（重名、类载入失败等），不中断扫描 */
    public $errors = [];

    /** @var float 耗时（秒） */
    public $duration = 0.0;

    /**
     * @param string $message
     * @return void
     */
    public function addError($message)
    {
        $this->errors[] = (string) $message;
    }

    /** @return bool */
    public function hasErrors()
    {
        return $this->errors !== [];
    }

    /** 索引是否需要更新（`--check` 用）
     * @return bool
     */
    public function isStale()
    {
        return $this->filesParsed > 0 || $this->toolsRemoved > 0;
    }

    /** @return int */
    public function toolsChanged()
    {
        return $this->toolsAdded + $this->toolsUpdated + $this->toolsRemoved;
    }

    /** @return array<string, mixed> */
    public function toArray()
    {
        return [
            'files_scanned' => $this->filesScanned,
            'files_skipped' => $this->filesSkipped,
            'files_parsed'  => $this->filesParsed,
            'tools_added'   => $this->toolsAdded,
            'tools_updated' => $this->toolsUpdated,
            'tools_removed' => $this->toolsRemoved,
            'tools'         => $this->tools,
            'errors'        => $this->errors,
            'duration'      => round($this->duration, 4),
        ];
    }

    /** 人类可读的一行摘要
     * @return string
     */
    public function summary()
    {
        return sprintf(
            '扫描 %d 个文件（跳过 %d，解析 %d），Tool +%d ~%d -%d，耗时 %.3fs%s',
            $this->filesScanned,
            $this->filesSkipped,
            $this->filesParsed,
            $this->toolsAdded,
            $this->toolsUpdated,
            $this->toolsRemoved,
            $this->duration,
            $this->errors === [] ? '' : '，错误 ' . count($this->errors) . ' 条'
        );
    }
}
