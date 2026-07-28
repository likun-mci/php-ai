<?php
namespace Ai\Editor;

/**
 * AI 文件编辑协议：生成 system 指令 + 解析模型返回的编辑计划
 *
 * 设计目标：让模型返回「编辑指令(JSON)」而非「整文件内容」，由 CMS 执行局部修改。
 */
class EditProtocol
{
    /**
     * 生成 system 指令（含动作契约、安全规则；模板模式注入开发文档与文件清单约定）
     */
    public static function systemPrompt(EditContext $ctx)
    {
        $tpl = $ctx->hasWorkspace();

        $s  = "你是嵌入在 CMS 代码编辑器中的文件编辑助手。你的任务是根据用户需求与给定的【编辑上下文】，";
        $s .= "返回\"编辑指令\"而不是整文件内容，由 CMS 执行精确的局部修改。\n\n";

        $s .= "【输出格式 · 必须严格遵守】\n";
        $s .= "1) 先用一两句简短中文说明你的改动思路；\n";
        $s .= "2) 然后输出且仅输出一个 ```json 代码块，结构如下：\n";
        $s .= "```json\n";
        $s .= "{\n";
        $s .= "  \"summary\": \"本次改动总览\",\n";
        $s .= "  \"actions\": [ { \"action\": \"...\", \"reason\": \"...\", ... } ]\n";
        $s .= "}\n";
        $s .= "```\n";
        $s .= "不要在 json 块内写注释；不要返回整文件内容（除非确需 replace_file/create_file）。\n\n";

        $s .= "【支持的动作 action】\n";
        $s .= "- str_replace：精确替换。字段 old_string(必须是文件中能唯一定位的原文片段)、new_string、可选 replace_all。\n";
        $s .= "  这是\"你自己决定改哪里\"时的首选；务必逐字复制原文（含缩进/标签），保证唯一匹配。\n";
        $s .= "- replace_selection：替换用户当前选区。字段 replacement。位置由 CMS 提供，你只给替换后的内容。\n";
        $s .= "- insert_before_cursor / insert_after_cursor：在光标处插入。字段 replacement。\n";
        $s .= "  （replace_selection 与 insert_* 仅适用于\"当前文件\"，不要给它们带 target_file。）\n";
        $s .= "- replace_range：按字符偏移替换。字段 start_offset、end_offset、replacement。\n";
        $s .= "  （除非上下文明确给了可靠偏移，否则优先用 str_replace，不要凭空猜偏移。）\n";
        $s .= "- replace_file：覆盖整个文件。字段 content。仅当确需整体重构、且用户无选区时使用。\n";
        if ($tpl) {
            $s .= "- create_file：新建文件。字段 target_file(工作区内相对路径)、content。\n";
            $s .= "- delete_file：删除文件。字段 target_file。\n";
        }
        $s .= "\n";

        $s .= "【目标文件 target_file】\n";
        $s .= "- 省略 target_file 表示操作\"当前文件\"。\n";
        if ($tpl) {
            $s .= "- 跨文件操作时，target_file 只能是【workspace_files】清单中的相对路径（如 site/index.php），";
            $s .= "严禁写真实绝对路径，CMS 会自动拼接工作区根目录。\n";
        } else {
            $s .= "- 当前文件不属于任何可写工作区：禁止任何文件写入动作，只返回空 actions 并在 summary 说明，仅做解答。\n";
        }
        $s .= "\n";

        $s .= "【安全与质量】\n";
        $s .= "- 绝不返回服务器真实路径；只用相对路径。\n";
        $s .= "- 局部改动只动需要改的部分，不要重排无关代码。\n";
        $s .= "- old_string 必须与文件现有内容完全一致（逐字符），否则 CMS 无法定位。\n";

        if ($tpl && $ctx->getGuidelines() !== '') {
            $s .= "\n【工作区开发规范（务必遵守）】\n";
            $s .= $ctx->getGuidelines() . "\n";
        }

        return $s;
    }

    /**
     * 解析模型返回文本为 EditPlan
     */
    public static function parse($aiText)
    {
        $plan = new EditPlan();
        $plan->raw = (string) $aiText;

        $json = self::extractJson($plan->raw);
        if ($json === null) {
            // 没有结构化编辑：当作纯解答
            $plan->explanation = trim($plan->raw);
            return $plan;
        }

        // JSON 之前的自然语言作为解释
        $pos = strpos($plan->raw, $json);
        $plan->explanation = $pos !== false ? trim(substr($plan->raw, 0, $pos)) : '';
        $plan->explanation = preg_replace('/```json\s*$/', '', $plan->explanation);
        $plan->explanation = trim($plan->explanation, "` \n\r\t");

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $plan->error = 'JSON 解析失败';
            $plan->explanation = trim($plan->raw);
            return $plan;
        }

        $plan->summary = isset($data['summary']) ? (string) $data['summary'] : '';

        $rawActions = [];
        if (isset($data['actions']) && is_array($data['actions'])) {
            $rawActions = $data['actions'];
        } elseif (isset($data['action'])) {
            $rawActions = [$data]; // 兼容单动作对象
        }

        foreach ($rawActions as $ra) {
            $act = EditAction::fromArray($ra);
            if ($act === null) continue;
            $err = $act->validate();
            if ($err !== '') { $plan->error = $err; continue; }
            $plan->actions[] = $act;
        }

        return $plan;
    }

    /**
     * 从文本中提取 JSON 字符串：优先 ```json 围栏，其次裸 { ... }
     */
    protected static function extractJson($text)
    {
        if (preg_match('/```json\s*(\{[\s\S]*?\})\s*```/i', $text, $m)) {
            return $m[1];
        }
        if (preg_match('/```\s*(\{[\s\S]*?\})\s*```/', $text, $m)) {
            return $m[1];
        }
        // 裸 JSON：从第一个 { 起做括号配平
        $start = strpos($text, '{');
        if ($start === false) return null;
        $depth = 0; $inStr = false; $esc = false;
        for ($i = $start, $n = strlen($text); $i < $n; $i++) {
            $c = $text[$i];
            if ($inStr) {
                if ($esc) { $esc = false; }
                elseif ($c === '\\') { $esc = true; }
                elseif ($c === '"') { $inStr = false; }
            } else {
                if ($c === '"') { $inStr = true; }
                elseif ($c === '{') { $depth++; }
                elseif ($c === '}') { $depth--; if ($depth === 0) return substr($text, $start, $i - $start + 1); }
            }
        }
        return null;
    }
}
