<?php
/**
 * 假的 claude CLI：只实现双工 stream-json 协议里被 ClaudeCodeSession 用到的部分，
 * 供 tests/cli_session_test.php 在没有真 claude、不烧额度的前提下跑完整流程。
 *
 * 用法：php fake_claude.php [pid 文件]   —— 给了 pid 文件就把自身 PID 写进去，
 *      测试据此确认"被杀掉的是 CLI 本身"，而不是中间那层 sh。
 *
 * 输入：每行一条 JSON（type = user / control_request / control_response）
 * 输出：system/init → assistant → result 的事件序列
 *
 * 用户消息文本里的指令（测试用）：
 *   @hold   本轮先不出 result（模拟 claude 正在干活），等下一条消息再收口
 *   @perm   先发一条 can_use_tool 的 control_request，按宿主的决策继续
 *   @sleep  收到后一直挂着不退出（测 kill 是否真的杀得掉）
 *   @big    result 里回一段 200KB 的文本（测大 payload 的读写）
 */

if (isset($argv[1]) && $argv[1] !== '') {
    file_put_contents($argv[1], (string) getmypid());
}

$turnMsgs = [];      // 本轮累计的用户消息文本
$inTurn   = false;   // 是否处在一轮之中
$session  = 'fake-session-0001';

function out(array $ev)
{
    fwrite(STDOUT, json_encode($ev, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fflush(STDOUT);
}

function textOf(array $ev)
{
    $text = '';
    $blocks = isset($ev['message']['content']) && is_array($ev['message']['content'])
        ? $ev['message']['content'] : [];
    foreach ($blocks as $block) {
        if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
            $text .= $block['text'];
        }
    }
    return $text;
}

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $ev = json_decode($line, true);
    if (!is_array($ev)) {
        continue;
    }
    $type = isset($ev['type']) ? $ev['type'] : '';

    // ---- 宿主发来的控制指令 ----
    if ($type === 'control_request') {
        $req     = isset($ev['request']) ? $ev['request'] : [];
        $subtype = isset($req['subtype']) ? $req['subtype'] : '';
        out([
            'type'     => 'control_response',
            'response' => [
                'subtype'    => 'success',
                'request_id' => isset($ev['request_id']) ? $ev['request_id'] : '',
                'response'   => ['ok' => true, 'echo' => $subtype],
            ],
        ]);
        if ($subtype === 'interrupt' && $inTurn) {
            out([
                'type'       => 'result',
                'subtype'    => 'error_during_execution',
                'is_error'   => true,
                'session_id' => $session,
                'num_turns'  => count($turnMsgs),
                'result'     => '',
            ]);
            $inTurn = false;
            $turnMsgs = [];
        }
        continue;
    }

    // 宿主对 can_use_tool 的答复
    if ($type === 'control_response') {
        continue;
    }

    if ($type !== 'user') {
        continue;
    }

    // ---- 用户消息：先按 --replay-user-messages 回显 ----
    $ev['isReplay']   = true;
    $ev['session_id'] = $session;
    out($ev);

    $text = textOf($ev);
    $turnMsgs[] = $text;

    if (strpos($text, '@sleep') !== false) {
        while (true) {
            usleep(200000);   // 挂住不退出，等宿主来杀
        }
    }

    if (!$inTurn) {
        $inTurn = true;
        out([
            'type'       => 'system',
            'subtype'    => 'init',
            'session_id' => $session,
            'model'      => 'fake-model',
            'tools'      => ['Read', 'Write', 'Bash'],
            'cwd'        => getcwd(),
        ]);
    }

    if (strpos($text, '@perm') !== false) {
        out([
            'type'       => 'control_request',
            'request_id' => 'cli-req-1',
            'request'    => [
                'subtype'     => 'can_use_tool',
                'tool_name'   => 'Bash',
                'input'       => ['command' => 'ls'],
                'tool_use_id' => 'toolu_fake_1',
            ],
        ]);
    }

    out([
        'type'       => 'assistant',
        'session_id' => $session,
        'message'    => [
            'model'   => 'fake-model',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_fake_1', 'name' => 'Read', 'input' => ['file_path' => '/x']],
            ],
        ],
    ]);

    // @hold：本轮不收口，等下一条用户消息（模拟"处理中继续提需求"）
    if (strpos($text, '@hold') !== false) {
        continue;
    }

    $answer = '回答:' . implode('|', $turnMsgs);
    if (strpos($text, '@big') !== false) {
        $answer = str_repeat('大', 100000);
    }
    out([
        'type'       => 'assistant',
        'session_id' => $session,
        'message'    => ['model' => 'fake-model', 'content' => [['type' => 'text', 'text' => $answer]]],
    ]);
    out([
        'type'           => 'result',
        'subtype'        => 'success',
        'is_error'       => false,
        'session_id'     => $session,
        'num_turns'      => count($turnMsgs),
        'duration_ms'    => 12,
        'total_cost_usd' => 0.001,
        'usage'          => ['input_tokens' => 10, 'output_tokens' => 20],
        'result'         => $answer,
    ]);
    $inTurn = false;
    $turnMsgs = [];
}
