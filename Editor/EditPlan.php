<?php
namespace Ai\Editor;

/**
 * 一次 AI 编辑计划：摘要 + 解释 + 动作列表
 */
class EditPlan
{
    /** @var string 改动总览 */
    public $summary = '';

    /** @var string JSON 之外的自然语言解释 */
    public $explanation = '';

    /** @var EditAction[] */
    public $actions = [];

    /** @var string 原始 AI 文本 */
    public $raw = '';

    /** @var string 解析/校验错误（空表示正常） */
    public $error = '';

    public function hasActions()
    {
        return !empty($this->actions);
    }

    public function toArray()
    {
        return [
            'summary'     => $this->summary,
            'explanation' => $this->explanation,
            'actions'     => array_map(function (EditAction $a) { return $a->toArray(); }, $this->actions),
            'error'       => $this->error,
        ];
    }
}
