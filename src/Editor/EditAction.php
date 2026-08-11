<?php
namespace Ai\Editor;

/**
 * 单条 AI 编辑动作（值对象）
 *
 * 支持的动作类型（action）：
 *  - str_replace            锚点替换：old_string -> new_string（AI 自定位首选，唯一匹配）
 *  - replace_selection      替换当前选区（位置由 CMS 提供，AI 只给 replacement）
 *  - insert_before_cursor   光标前插入
 *  - insert_after_cursor    光标后插入
 *  - replace_range          按字符偏移替换（start_offset/end_offset，AI 给偏移，谨慎）
 *  - replace_file           覆盖整个文件（content）
 *  - create_file            新建文件（target_file + content）
 *  - delete_file            删除文件（target_file）
 */
class EditAction
{
    /** @var string 动作类型 */
    public $action = '';

    /** @var string|null 目标文件（模板相对路径）；null 表示当前文件 */
    public $targetFile = null;

    /** @var string 改动理由（展示用） */
    public $reason = '';

    // str_replace
    /**
     * @var string|null
     */
    public $oldString = null;
    /**
     * @var string|null
     */
    public $newString = null;
    /**
     * @var bool
     */
    public $replaceAll = false;

    // replace_selection / insert_* / replace_range
    /**
     * @var string|null
     */
    public $replacement = null;

    // replace_range
    /**
     * @var int|null
     */
    public $startOffset = null;
    /**
     * @var int|null
     */
    public $endOffset = null;

    // replace_file / create_file
    /**
     * @var string|null
     */
    public $content = null;

    /** 全部合法动作类型
     * @return string[] 合法的 action 取值
     */
    public static function types()
    {
        return [
            'str_replace', 'replace_selection',
            'insert_before_cursor', 'insert_after_cursor',
            'replace_range', 'replace_file', 'create_file', 'delete_file',
        ];
    }

    /**
     * 从 AI 返回的数组构造（非法返回 null）
     * @param array<string, mixed> $a * @return self
     * @return self|null 非法输入返回 null
     */
    public static function fromArray($a)
    {
        if (!is_array($a) || empty($a['action']) || !in_array($a['action'], self::types(), true)) {
            return null;
        }

        $o = new self();
        $o->action     = (string) $a['action'];
        $o->targetFile = isset($a['target_file']) && $a['target_file'] !== '' ? (string) $a['target_file'] : null;
        $o->reason     = isset($a['reason']) ? (string) $a['reason'] : '';

        $o->oldString   = array_key_exists('old_string', $a) ? (string) $a['old_string'] : null;
        $o->newString   = array_key_exists('new_string', $a) ? (string) $a['new_string'] : null;
        $o->replaceAll  = !empty($a['replace_all']);
        $o->replacement = array_key_exists('replacement', $a) ? (string) $a['replacement'] : null;
        $o->content     = array_key_exists('content', $a) ? (string) $a['content'] : null;
        $o->startOffset = isset($a['start_offset']) ? (int) $a['start_offset'] : null;
        $o->endOffset   = isset($a['end_offset']) ? (int) $a['end_offset'] : null;

        return $o;
    }

    /**
     * 基础字段合法性校验，返回错误信息（空串表示通过）
     * @return string 校验通过返回空串，否则返回错误说明
     */
    public function validate()
    {
        switch ($this->action) {
            case 'str_replace':
                if ($this->oldString === null || $this->oldString === '') return 'str_replace 缺少 old_string';
                if ($this->newString === null) return 'str_replace 缺少 new_string';
                break;
            case 'replace_selection':
            case 'insert_before_cursor':
            case 'insert_after_cursor':
                if ($this->replacement === null) return "{$this->action} 缺少 replacement";
                break;
            case 'replace_range':
                if ($this->startOffset === null || $this->endOffset === null) return 'replace_range 缺少 start_offset/end_offset';
                if ($this->replacement === null) return 'replace_range 缺少 replacement';
                break;
            case 'replace_file':
            case 'create_file':
                if ($this->content === null) return "{$this->action} 缺少 content";
                break;
            case 'delete_file':
                if ($this->targetFile === null) return 'delete_file 缺少 target_file';
                break;
        }
        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        $out = ['action' => $this->action, 'reason' => $this->reason];
        if ($this->targetFile !== null) $out['target_file'] = $this->targetFile;
        if ($this->oldString !== null)  $out['old_string'] = $this->oldString;
        if ($this->newString !== null)  $out['new_string'] = $this->newString;
        if ($this->replaceAll)          $out['replace_all'] = true;
        if ($this->replacement !== null) $out['replacement'] = $this->replacement;
        if ($this->startOffset !== null) $out['start_offset'] = $this->startOffset;
        if ($this->endOffset !== null)   $out['end_offset'] = $this->endOffset;
        if ($this->content !== null)     $out['content'] = $this->content;
        return $out;
    }
}
