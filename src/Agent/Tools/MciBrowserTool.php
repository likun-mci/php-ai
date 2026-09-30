<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;

/**
 * mci_browser —— 操作用户本机那台真实浏览器（授权 + 操作一体的唯一入口）
 *
 * ── 为什么要有它 ──
 * 有些活只能在那台浏览器里干：他各站点的登录态、装着的扩展、前端路由都在那儿。
 * 服务端 headless Chrome（BrowserTool）拿不到这些。但服务端也**开不了**用户的
 * 浏览器 —— 得先请用户在自己的浏览器里点一次确认，把驱动权交出来。
 *
 * 所以这个工具是一个工具、两半职责：
 *
 *   授权（不需要凭据）：authorize → 弹确认页；grant → 取回凭据；
 *                        credential / forget → 查 / 清本地凭据
 *   操作（需要凭据）  ：devices / tabs / open / click / text / eval / screenshot …
 *                        一共 25 个动作，直通站点 `/Browser/Api/*`
 *
 * 模型只用记一个工具名，按需调：没凭据时 authorize 先，拿到凭据后直接操作。
 * 票据与凭据落在**按身份隔离**的目录（`ToolContext::storageDir()`，0600）——
 * 详见 BrowserGrantTool 的类注释，那条密钥能把用户的浏览器交出去，不能落到
 * 两个人共用的位置。
 *
 * ```php
 * $agent->addTool(new MciBrowserTool('https://likun.work', ['app' => 'MCI AI Agent']));
 * // 模型调用：
 * //   mci_browser(action: "authorize")                       → 弹授权页
 * //   mci_browser(action: "grant", wait_seconds: 60)          → 用户确认后取回凭据
 * //   mci_browser(action: "open", url: "https://example.com")
 * //   mci_browser(action: "text", selector: "h1")
 * //   mci_browser(action: "screenshot")                       → 图直接进上下文
 * ```
 *
 * ── 几条刻意的取舍 ──
 * · 授权那一半委托给 BrowserGrantTool：票据/轮询/落盘的规则只写一处。本工具在
 *   它之上加的是「拿凭据去操作」这一半，以及把两半收进同一个工具名。
 * · `device` 默认取凭据里授权的那台：站点在线的是什么设备不由模型猜，它授权时
 *   选的是哪台就一直用哪台（多台在线时尤其重要 —— 打错了就是别人的浏览器）。
 * · `open` 默认 `active=0`：AI 干活多在后台，抢走焦点等于打断用户手头的事。
 * · 截图走 `inline=1` 取回字节并交给媒体门面，由 Runtime 决定怎么进对话；模型
 *   看得到图，而不是拿到一个它读不了的服务器路径。落盘路径也一并回报，给人看。
 * · 这里只做透传与结果整理，不改站点的接口语义：限流、配额、白名单、审计都还在
 *   站点那侧（`/Browser/Api/*`），漏过这一层不会绕过任何一道。
 *
 * 属联网工具，默认经 PermissionManager 把关（manual 询问）。
 */
class MciBrowserTool implements AgentToolInterface
{
    /**
     * 授权类动作 → BrowserGrantTool 的动作名
     *
     * 站点的授权流程与操作接口是两套东西（`/Browser/Grant/*` 与 `/Browser/Api/*`），
     * 这里把它们收进同一个工具的 action 命名空间里。
     */
    const AUTH_MAP = [
        'authorize'  => 'start',
        'grant'      => 'status',
        'credential' => 'credential',
        'forget'     => 'forget',
    ];

    /**
     * 操作类动作 → 接口定义
     *
     * `[HTTP 方法, /Browser/Api/ 下的路径, 允许透传的参数, 超时秒数]`。
     * 超时 0 = 用默认；-1 = 由 timeout 参数算（wait 要等多久，命令就得挂多久，
     * 否则永远是传输层先超时，拿到的错误是「命令超时」而不是「元素没出现」）。
     *
     * 参数表是白名单：模型多给的键不会被原样转给站点。少了这层，工具就成了
     * 「把模型输入拼进请求」的通道，动作语义迟早被绕过去。
     *
     * @var array<string, array{0: string, 1: string, 2: string[], 3: int}>
     */
    protected static $actions = [
        'devices'    => ['GET',  'status',     [], 0],
        'tabs'       => ['GET',  'tabs',       ['all'], 0],
        'open'       => ['POST', 'open',       ['url', 'active'], 40],
        'close'      => ['POST', 'close',      ['tab'], 0],
        'url'        => ['GET',  'url',        ['tab'], 0],
        'text'       => ['GET',  'text',       ['tab', 'selector', 'all'], 0],
        'html'       => ['GET',  'html',       ['tab', 'selector', 'outer', 'limit'], 0],
        'form'       => ['GET',  'form',       ['tab', 'selector'], 0],
        'metrics'    => ['GET',  'metrics',    ['tab', 'selector', 'props', 'all'], 0],
        'eval'       => ['POST', 'eval',       ['tab', 'expression'], 0],
        'click'      => ['POST', 'click',      ['tab', 'selector', 'x', 'y', 'button', 'clicks'], 0],
        'type'       => ['POST', 'type',       ['tab', 'selector', 'text', 'clear', 'enter', 'delay'], 60],
        'press'      => ['POST', 'press',      ['tab', 'key'], 0],
        'scroll'     => ['POST', 'scroll',     ['tab', 'selector', 'x', 'y', 'dy'], 0],
        'select'     => ['POST', 'select',     ['tab', 'selector', 'value', 'label'], 0],
        'wait'       => ['POST', 'wait',       ['tab', 'selector', 'timeout', 'gone', 'visible'], -1],
        'screenshot' => ['POST', 'screenshot', ['tab', 'full', 'selector'], 60],
        'console'    => ['GET',  'console',    ['tab', 'limit', 'level', 'clear'], 0],
        'network'    => ['GET',  'network',    ['tab', 'limit', 'filter', 'failed', 'clear'], 0],
        'cookies'    => ['GET',  'cookies',    ['tab', 'url'], 0],
        'quota'      => ['GET',  'quota',      [], 0],
        'reload'     => ['POST', 'reload',     ['tab', 'cache'], 40],
        'back'       => ['POST', 'back',       ['tab'], 40],
        'forward'    => ['POST', 'forward',    ['tab'], 40],
        'activate'   => ['POST', 'activate',   ['tab'], 0],
    ];

    /** @var int 回给模型的正文上限（html / text 很容易上万字） */
    const MAX_TEXT = 40000;

    /** @var string 站点根（如 https://likun.work） */
    protected $siteBase;

    /** @var BrowserGrantTool 授权那一半（票据 / 轮询 / 凭据落盘） */
    protected $grant;

    /** @var int 单次 HTTP 超时秒数 */
    protected $timeout;

    /** @var bool open 是否把标签切到前台（默认否，不抢用户的焦点） */
    protected $openActive = false;

    /** @var callable|null 注入的 HTTP 客户端 function(string $method, string $url, array $form, int $timeout): array */
    protected $http;

    /**
     * @param string $siteBase 站点根，如 https://likun.work（不带尾斜杠）
     * @param array<string, mixed> $options app / timeout / max_wait / open_active
     * @param callable|null $http 可注入（测试）；生产留空走 cURL
     */
    public function __construct($siteBase, array $options = [], $http = null)
    {
        $this->siteBase = rtrim(trim((string) $siteBase), '/');
        $this->timeout  = max(3, (int) ($options['timeout'] ?? 15));
        $this->openActive = !empty($options['open_active']);
        // 注入的客户端按 ($method, $url, $form, $timeout, $key) 五点签名；授权那一半
        // 只传前三个，所以后两个参数要带默认值，两边共用同一个闭包（见测试）
        $this->grant    = new BrowserGrantTool($this->siteBase, $options, $http);
        $this->http     = is_callable($http) ? $http : null;
    }

    public function name()
    {
        return 'mci_browser';
    }

    public function description()
    {
        return '操作用户本机那台真实浏览器（带着他的登录态、扩展与前端路由）。'
            . '首次使用要先授权：action="authorize" 会弹确认页，用户在自己浏览器里点确认后，'
            . '用 action="grant" 取回凭据，之后就能直接操作，不必再授权。'
            . "\n查看类：devices（在线浏览器）/ tabs / url / text / html / form / metrics / console / network / cookies / quota。"
            . "\n操作类：open / click / type / press / scroll / select / wait / reload / back / forward / close / activate。"
            . "\n脚本类：eval 执行 JS 并取回结果；screenshot 截图（图片直接进上下文，你能看到页面）。"
            . "\n凭据管理：credential 看本地凭据，forget 清除。"
            . '需要 JS 渲染或登录态的页面用它；只是取静态 HTML 用 web_fetch 更快。';
    }

    public function schema()
    {
        $str = function ($desc) { return ['type' => 'string', 'description' => $desc]; };
        $int = function ($desc, $default = null) {
            $p = ['type' => 'integer', 'description' => $desc];
            if ($default !== null) { $p['default'] = $default; }
            return $p;
        };
        $bool = function ($desc, $default = null) {
            $p = ['type' => 'boolean', 'description' => $desc];
            if ($default !== null) { $p['default'] = $default; }
            return $p;
        };

        return [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type'        => 'string',
                    'description' => '要做的动作（授权：authorize / grant / credential / forget）',
                    'enum'        => array_merge(
                        array_keys(self::AUTH_MAP),
                        array_keys(self::$actions)
                    ),
                ],
                'url'        => $str('open / cookies 的地址'),
                'selector'   => $str('CSS 选择器（text / html / form / metrics / click / type / select / wait / screenshot 用）'),
                'expression' => $str('eval 要执行的 JS 表达式（支持 await）'),
                'text'       => $str('type 要输入的文本'),
                'key'        => $str('press 的键名，如 Enter / Escape / Tab / ArrowDown / Control+s'),
                'value'      => $str('select 选中的 value'),
                'label'      => $str('select 选中的可见文字（与 value 二选一）'),
                'tab'        => $int('标签页 id，省略用当前活动的那个（open / tabs 的结果里有）'),
                'x'          => $int('click / scroll 的横坐标'),
                'y'          => $int('click / scroll 的纵坐标'),
                'dy'         => $int('scroll 的相对滚动量'),
                'button'     => $str('click 的键：left / right / middle'),
                'clicks'     => $int('点击次数，2 为双击'),
                'clear'      => $bool('type 前先清空原有内容；console / network 里表示取完清空'),
                'enter'      => $bool('type 之后补一个回车'),
                'delay'      => $int('type 的按键间隔毫秒数'),
                'active'     => $bool('open 是否把新标签切到前台（默认否，不打扰用户）', false),
                'all'        => $bool('tabs 列全部标签（默认只列受控与当前活动的）'),
                'full'       => $bool('screenshot 是否整页（默认只截可见区）', false),
                'outer'      => $bool('html 取 outerHTML（默认）还是 innerHTML'),
                'limit'      => $int('html / console / network 的条数或长度上限'),
                'level'      => $str('console 的级别过滤：error / warning / info / log'),
                'filter'     => $str('network 只看 URL 含该子串的请求'),
                'failed'     => $bool('network 只看失败与 4xx/5xx'),
                'timeout'    => $int('wait 的最长等待毫秒数', 10000),
                'gone'       => $bool('wait 等元素消失'),
                'visible'    => $bool('wait 是否要求元素可见', true),
                'props'      => $str('metrics 要读的计算样式属性，逗号分隔'),
                'app'        => $str('authorize 时自报的名字，显示在确认页上'),
                'device'     => $str('目标浏览器 id，默认用凭据里授权的那台'),
                'wait_seconds' => $int('grant 时在服务端等待用户确认的秒数（0 = 只查一次，上限 300）', 0),
            ],
            'required'   => ['action'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param ToolContext $context
     * @return ToolResult
     */
    public function execute(array $input, ToolContext $context)
    {
        if ($this->siteBase === '') {
            return ToolResult::error('未配置站点地址，无法使用浏览器工具');
        }

        $action = isset($input['action']) ? strtolower(trim((string) $input['action'])) : '';

        // 授权那一半：原样交给 BrowserGrantTool（票据、轮询、落盘规则只写一处）
        if (isset(self::AUTH_MAP[$action])) {
            $sub = ['action' => self::AUTH_MAP[$action]];
            if ($action === 'authorize' && isset($input['app'])) {
                $sub['app'] = $input['app'];
            }
            if ($action === 'grant' && isset($input['wait_seconds'])) {
                $sub['wait_seconds'] = $input['wait_seconds'];
            }
            return $this->grant->execute($sub, $context);
        }

        if (!isset(self::$actions[$action])) {
            return ToolResult::error(
                '不支持的 action "' . $action . '"。授权用 authorize / grant / credential / forget，'
                . '操作用：' . implode(' / ', array_keys(self::$actions))
            );
        }

        return $this->operate($action, $input, $context);
    }

    // ==================== 操作 ====================

    /**
     * 调一次 `/Browser/Api/*`
     *
     * @param string $action
     * @param array<string, mixed> $input
     * @param ToolContext $context
     * @return ToolResult
     */
    protected function operate($action, array $input, ToolContext $context)
    {
        if (trim((string) $context->storageDir()) === '') {
            return ToolResult::error(
                '本会话没有身份（既没 userId 也没 sessionId），无法定位浏览器凭据的存放位置。'
                . '请让调用方先 setUserId() 或 setSessionId()。'
            );
        }

        $cred = $this->grant->storedCredential($context);
        if ($cred === null) {
            return ToolResult::error(
                '还没有浏览器授权，无法执行 ' . $action . '。请先调用 mci_browser(action: "authorize")：'
                . '确认页会发给用户，他在自己浏览器里选一台并确认；再用 mci_browser(action: "grant") 取回凭据。'
            );
        }

        $apiBase = trim((string) ($cred['api_base'] ?? ''));
        if ($apiBase === '') {
            $apiBase = $this->siteBase . '/Browser/Api/';
        }

        list($method, $path, $params, $timeout) = self::$actions[$action];
        // device 是所有动作通用的目标选择，不在逐个动作的参数表里，单独放行
        $form = $this->pick($input, array_merge(['device'], $params));

        // device 默认用授权时选的那台：多台在线时，猜错就是把命令打到别人的浏览器上
        if (!isset($form['device'])) {
            $device = trim((string) ($cred['device'] ?? ''));
            if ($device !== '') {
                $form['device'] = $device;
            }
        }
        // open 默认后台打开，不抢用户焦点
        if ($action === 'open' && !array_key_exists('active', $form)) {
            $form['active'] = $this->openActive ? 1 : 0;
        }
        // 截图默认要字节：模型看得到图才是「真的看见了页面」
        if ($action === 'screenshot') {
            $form['inline'] = 1;
        }

        if ($timeout === -1) {
            $ms = isset($input['timeout']) ? (int) $input['timeout'] : 10000;
            $timeout = (int) ceil(max(0, $ms) / 1000) + 10;
        }
        $timeout = $timeout > 0 ? $timeout : $this->timeout;

        $url = rtrim($apiBase, '/') . '/' . ltrim($path, '/');
        $res = $this->request($method, $url, $form, $timeout, (string) ($cred['key'] ?? ''));
        if (!$res['ok']) {
            return ToolResult::error('浏览器接口请求失败：' . $res['error']);
        }
        $data = $this->decode($res['body']);
        if ($data === null) {
            return ToolResult::error('浏览器接口返回了无法解析的内容（HTTP ' . $res['status'] . '）');
        }
        if ((int) $res['status'] >= 400) {
            return ToolResult::error($this->messageOf($data, 'HTTP ' . $res['status']));
        }

        $wrap    = is_array($data['result'] ?? null) ? $data['result'] : [];
        $payload = is_array($wrap['result'] ?? null) ? $wrap['result'] : $wrap;
        $meta    = [
            'action' => $action,
            'path'   => '/Browser/Api/' . $path,
            'device' => (string) ($wrap['device'] ?? ''),
            'ms'     => (int) ($wrap['ms'] ?? 0),
        ];

        if ($action === 'screenshot') {
            return $this->screenshot($wrap, $meta, $context);
        }

        $text = $this->summarize($action, $payload);
        if (strlen($text) > self::MAX_TEXT) {
            $text = substr($text, 0, self::MAX_TEXT) . "\n…（已截断，完整内容 " . strlen($text) . " 字节）";
            $meta['truncated'] = true;
        }
        return ToolResult::success($text, $meta);
    }

    /**
     * 截图：把字节交给媒体门面，图直接进上下文
     *
     * 站点把图落在自己的 shots 目录里并返回路径 —— 那个路径工具这边未必读得到
     * （调用方的工作区限制），所以走 `inline=1` 拿字节。拿字节失败才退回报路径，
     * 因为「有一张图但你看不到」必须说清楚，不能让模型以为自己看过了。
     *
     * @param array<string, mixed> $wrap
     * @param array<string, mixed> $meta
     * @param ToolContext $context
     * @return ToolResult
     */
    protected function screenshot(array $wrap, array $meta, ToolContext $context)
    {
        $payload = is_array($wrap['result'] ?? null) ? $wrap['result'] : $wrap;
        $file    = (string) ($payload['file'] ?? '');
        $url     = (string) ($payload['url'] ?? '');
        $width   = (int) ($payload['width'] ?? 0);
        $height  = (int) ($payload['height'] ?? 0);
        $bytes   = (int) ($payload['bytes'] ?? 0);

        $unread = "截图已生成，但没能附到上下文，这次看不到图。";
        $line   = '截图：' . ($file !== '' ? $file : '（站点未返回路径）')
            . ($width > 0 ? '（' . $width . '×' . $height . '，' . $bytes . ' 字节）' : '')
            . ($url !== '' ? "\n页面：" . $url : '');

        $b64 = (string) ($payload['data'] ?? '');
        $mm  = $context->mediaManager();
        if ($b64 === '' || $mm === null) {
            return ToolResult::success($line . "\n" . $unread . ($mm === null ? '（本次运行未挂媒体门面）' : ''), $meta);
        }

        $bin = base64_decode($b64, true);
        if ($bin === false || $bin === '') {
            return ToolResult::success($line . "\n" . $unread . '（图像数据无法解码）', $meta);
        }

        $name = $file !== '' ? basename($file) : 'screenshot.png';
        try {
            $media = $mm->ingest([['data' => $bin, 'mime' => $this->mimeOf($name), 'name' => $name]]);
        } catch (\Throwable $e) {
            // 超限或类型不支持：如实说明，别静默吞掉
            return ToolResult::success($line . "\n" . $unread . '（' . $e->getMessage() . '）', $meta);
        }

        $meta['file']  = $file;
        $meta['width'] = $width;
        $meta['height'] = $height;
        $result = new ToolResult([
            'success'  => true,
            'content'  => $line . "\n截图已作为媒体附加到本轮上下文；若当前模型支持视觉输入，你可以直接查看它。",
            'metadata' => $meta,
            'display'  => '截图（' . ($width > 0 ? $width . '×' . $height : 'browser') . '）',
            'media'    => $media,
        ]);
        return $result;
    }

    // ==================== 结果整理 ====================

    /**
     * 把站点返回的 result 整理成模型好读的一段话
     *
     * 常用动作给一句人话，其余原样给 JSON —— 硬要给每个动作都编一套措辞，
     * 只会让「站点返回了什么」这件事多一层可能出错的翻译。
     *
     * @param string $action
     * @param array<string, mixed> $payload
     * @return string
     */
    protected function summarize($action, array $payload)
    {
        switch ($action) {
            case 'text':
                return is_string($payload['text'] ?? null) ? $payload['text'] : $this->json($payload);

            case 'html':
                return is_string($payload['html'] ?? null) ? $payload['html'] : $this->json($payload);

            case 'eval':
                $v = $payload['value'] ?? null;
                if (is_string($v)) {
                    return $v;
                }
                return (is_scalar($v) || $v === null)
                    ? var_export($v, true)
                    : $this->json($v);

            case 'url':
                return '标签 ' . (string) ($payload['tab'] ?? '') . '：' . (string) ($payload['title'] ?? '')
                    . "\n" . (string) ($payload['url'] ?? '');

            case 'open':
                $note = trim((string) ($payload['note'] ?? ''));
                return '已打开：' . (string) ($payload['title'] ?? '') . '（' . (string) ($payload['url'] ?? '') . '）'
                    . "\n标签 id：" . (string) ($payload['tab'] ?? '') . '，加载状态：' . (string) ($payload['load'] ?? '')
                    . ($note !== '' ? "\n注意：" . $note : '');

            case 'devices':
                return $this->devices($payload);

            case 'tabs':
                return $this->tabs($payload);

            case 'quota':
                return $this->json($payload);
        }
        return $this->json($payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return string
     */
    protected function devices(array $payload)
    {
        $lines = [];
        foreach ((array) ($payload['online'] ?? []) as $d) {
            $lines[] = '- ' . (string) ($d['device_id'] ?? '') . '（在线'
                . (isset($d['version']) ? '，扩展 ' . $d['version'] : '')
                . (isset($d['ip']) ? '，' . $d['ip'] : '') . '）';
        }
        $out = $lines ? ('在线浏览器：' . "\n" . implode("\n", $lines)) : '当前没有浏览器在线（用户那台的扩展可能没启动）。';

        $off = [];
        foreach ((array) ($payload['devices'] ?? []) as $d) {
            if (!empty($d['online'])) { continue; }
            $off[] = '- ' . (string) ($d['id'] ?? '') . '（' . (string) ($d['name'] ?? '') . '，'
                . (string) ($d['last_seen'] ?? '从未') . '）';
        }
        if ($off) {
            $out .= "\n\n已登记但离线：\n" . implode("\n", $off);
        }
        if (isset($payload['settings']['allow_origins'])) {
            $out .= "\n\n可访问的站点白名单：" . $this->json($payload['settings']['allow_origins']);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     * @return string
     */
    protected function tabs(array $payload)
    {
        $lines = [];
        foreach ((array) ($payload['tabs'] ?? []) as $t) {
            $lines[] = '- [' . (string) ($t['id'] ?? '') . '] ' . (!empty($t['active']) ? '（当前）' : '')
                . (string) ($t['title'] ?? '') . ' — ' . (string) ($t['url'] ?? '');
        }
        if (!$lines) {
            return '没有受控或当前活动的标签页。';
        }
        $out = implode("\n", $lines);
        if (!empty($payload['truncated'])) {
            $out .= "\n（浏览器里共 " . (string) ($payload['total'] ?? '?') . " 个标签，这里只列了受控与当前活动的；要全部传 all=1）";
        }
        $out .= "\n后续操作传 tab=<id> 指定标签，省略则用当前活动的那个。";
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @param string $fallback
     * @return string
     */
    protected function messageOf(array $data, $fallback)
    {
        $msg = trim((string) ($data['message'] ?? ''));
        return $msg !== '' ? $msg : (string) $fallback;
    }

    /**
     * @param mixed $v
     * @return string
     */
    protected function json($v)
    {
        $s = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return $s === false ? '' : $s;
    }

    /**
     * @param string $name
     * @return string
     */
    protected function mimeOf($name)
    {
        $ext = strtolower((string) pathinfo((string) $name, PATHINFO_EXTENSION));
        if ($ext === 'jpg' || $ext === 'jpeg') { return 'image/jpeg'; }
        if ($ext === 'webp') { return 'image/webp'; }
        return 'image/png';
    }

    /**
     * 按白名单挑参数：模型多给的键不原样转给站点
     *
     * @param array<string, mixed> $input
     * @param string[] $params
     * @return array<string, mixed>
     */
    protected function pick(array $input, array $params)
    {
        $out = [];
        foreach ($params as $key) {
            if (!array_key_exists($key, $input)) { continue; }
            $v = $input[$key];
            if (is_bool($v)) { $v = $v ? 1 : 0; }
            if ($v === null || $v === '') { continue; }
            $out[$key] = $v;
        }
        return $out;
    }

    // ==================== HTTP ====================

    /**
     * 发一次请求
     *
     * @param string $method GET / POST
     * @param string $url
     * @param array<string, mixed> $form POST 用表单、GET 用查询串
     * @param int $timeout
     * @param string $key 会员密钥
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    protected function request($method, $url, array $form, $timeout, $key)
    {
        if ($this->http !== null) {
            $r = call_user_func($this->http, $method, $url, $form, $timeout, $key);
            return [
                'ok'     => !empty($r['ok']),
                'status' => (int) ($r['status'] ?? 0),
                'body'   => (string) ($r['body'] ?? ''),
                'error'  => (string) ($r['error'] ?? ''),
            ];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => '服务器未启用 cURL'];
        }

        $headers = ['Accept: application/json'];
        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        $ch = curl_init();
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif ($form !== []) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($form);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(8, $timeout));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        if (function_exists('curl_close') && version_compare(PHP_VERSION, '8.0.0', '<')) {
            curl_close($ch);
        }
        if ($body === false) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $err !== '' ? $err : '网络请求失败'];
        }
        return ['ok' => true, 'status' => $status, 'body' => (string) $body, 'error' => ''];
    }

    /**
     * @param string $body
     * @return array<string, mixed>|null
     */
    protected function decode($body)
    {
        $data = json_decode((string) $body, true);
        return is_array($data) ? $data : null;
    }
}
