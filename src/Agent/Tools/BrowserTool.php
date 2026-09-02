<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;
use Ai\Helpers\Text;

/**
 * 浏览器工具（Browser）
 *
 * 让 Agent 操作真实浏览器：打开页面、点击、填表、截图、提取内容。
 * 跟 HTTP 抓取工具的区别是这里跑的是 Chrome——JS 渲染的内容、登录后的页面、
 * 前端路由，抓 HTML 拿不到的这里都拿得到。
 *
 * ```php
 * $agent->addTool(new BrowserTool());
 * // 模型调用：
 * //   browser(action: "open", url: "https://example.com")
 * //   browser(action: "extract", selector: "h1")
 * //   browser(action: "type", selector: "#q", text: "php")
 * //   browser(action: "click", selector: "button[type=submit]")
 * //   browser(action: "screenshot", path: "shot.png")
 * ```
 *
 * 会话是复用的：一次 `open` 之后，后续 `click` / `type` 都在同一个页面上，
 * 登录态和页面状态都还在。Agent 循环结束后调 `close()` 释放浏览器进程。
 *
 * 前置条件：本机装了 Chrome / Chromium。没装时工具返回明确的错误信息，
 * 而不是抛异常——模型看到"没有浏览器"可以换个办法，看到崩溃则只能重试。
 */
class BrowserTool implements AgentToolInterface
{
    /** @var BrowserSession|null */
    protected $session = null;

    /** @var array<string, mixed> 会话构造选项 */
    protected $options = [];

    /** @var PathSafety|null 截图落盘的路径限制 */
    protected $pathSafety = null;

    /** @var int 提取文本的最大长度 */
    protected $maxText = 20000;

    /**
     * @param array<string, mixed> $options binary / port / headless / timeout / maxText
     * @param PathSafety|null $pathSafety 传入后，截图路径受工作区限制
     */
    public function __construct(array $options = [], $pathSafety = null)
    {
        $this->options = $options;
        $this->pathSafety = $pathSafety instanceof PathSafety ? $pathSafety : null;
        if (isset($options['maxText'])) {
            $this->maxText = max(100, (int) $options['maxText']);
        }
    }

    /**
     * @return string
     */
    public function name()
    {
        return 'browser';
    }

    /**
     * @return string
     */
    public function description()
    {
        return '操作真实浏览器（Chrome headless）：打开网页、点击元素、填写表单、截图、提取内容。'
            . '适合需要 JS 渲染、登录态或表单交互的场景；只是取静态 HTML 时用 HTTP 抓取更快。'
            . '同一会话内页面状态保持，可连续操作。';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type'        => 'string',
                    'description' => '操作类型',
                    'enum'        => ['open', 'click', 'type', 'extract', 'screenshot', 'evaluate', 'wait', 'close'],
                ],
                'url' => [
                    'type'        => 'string',
                    'description' => 'open 时要打开的地址',
                ],
                'selector' => [
                    'type'        => 'string',
                    'description' => 'CSS 选择器（click / type / extract / wait 用）；extract 留空表示取整页文本',
                ],
                'text' => [
                    'type'        => 'string',
                    'description' => 'type 时要填入的文本',
                ],
                'path' => [
                    'type'        => 'string',
                    'description' => 'screenshot 的保存路径（.png）',
                ],
                'script' => [
                    'type'        => 'string',
                    'description' => 'evaluate 时要执行的 JS 表达式',
                ],
                'full_page' => [
                    'type'        => 'boolean',
                    'description' => 'screenshot 是否整页截图',
                    'default'     => false,
                ],
                'timeout_ms' => [
                    'type'        => 'integer',
                    'description' => 'wait 的超时毫秒数',
                    'default'     => 5000,
                ],
            ],
            'required' => ['action'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param ToolContext $context
     * @return ToolResult
     */
    public function execute(array $input, ToolContext $context)
    {
        $action = isset($input['action']) ? strtolower((string) $input['action']) : '';
        if ($action === '') {
            return ToolResult::error('缺少 action 参数');
        }
        if ($action === 'close') {
            $this->close();
            return ToolResult::success('浏览器已关闭');
        }

        if (!BrowserSession::isAvailable()) {
            return ToolResult::error(
                '当前环境没有可用的 Chrome / Chromium，或 proc_open 被禁用，无法使用浏览器工具'
            );
        }

        $session = $this->session();
        if ($session === null) {
            return ToolResult::error('浏览器启动失败');
        }

        switch ($action) {
            case 'open':
                return $this->doOpen($session, $input);
            case 'click':
                return $this->doClick($session, $input);
            case 'type':
                return $this->doType($session, $input);
            case 'extract':
                return $this->doExtract($session, $input);
            case 'screenshot':
                return $this->doScreenshot($session, $input, $context);
            case 'evaluate':
                return $this->doEvaluate($session, $input);
            case 'wait':
                return $this->doWait($session, $input);
        }
        return ToolResult::error('不支持的 action：' . $action);
    }

    /**
     * 当前会话（惰性启动）
     *
     * @return BrowserSession|null 启动失败返回 null
     */
    public function session()
    {
        if ($this->session === null) {
            $session = new BrowserSession($this->options);
            if (!$session->launch()) {
                return null;
            }
            $this->session = $session;
        }
        return $this->session;
    }

    /**
     * 关闭浏览器
     *
     * @return $this
     */
    public function close()
    {
        if ($this->session !== null) {
            $this->session->close();
            $this->session = null;
        }
        return $this;
    }

    /**
     * @param BrowserSession $session
     * @param array<string, mixed> $input
     * @return ToolResult
     */
    protected function doOpen(BrowserSession $session, array $input)
    {
        $url = isset($input['url']) ? trim((string) $input['url']) : '';
        if ($url === '') {
            return ToolResult::error('open 需要 url 参数');
        }
        if (!preg_match('#^https?://#i', $url)) {
            return ToolResult::error('只支持 http:// 或 https:// 地址');
        }
        if (!$session->navigate($url)) {
            return ToolResult::error('打开页面失败：' . $url);
        }
        return ToolResult::success(
            "已打开：{$session->url()}\n标题：{$session->title()}",
            ['url' => $session->url(), 'title' => $session->title()]
        );
    }

    /**
     * @param BrowserSession $session
     * @param array<string, mixed> $input
     * @return ToolResult
     */
    protected function doClick(BrowserSession $session, array $input)
    {
        $selector = isset($input['selector']) ? (string) $input['selector'] : '';
        if ($selector === '') {
            return ToolResult::error('click 需要 selector 参数');
        }
        if (!$session->click($selector)) {
            return ToolResult::error('页面上找不到元素：' . $selector);
        }
        return ToolResult::success("已点击 {$selector}\n当前地址：{$session->url()}");
    }

    /**
     * @param BrowserSession $session
     * @param array<string, mixed> $input
     * @return ToolResult
     */
    protected function doType(BrowserSession $session, array $input)
    {
        $selector = isset($input['selector']) ? (string) $input['selector'] : '';
        $text = isset($input['text']) ? (string) $input['text'] : '';
        if ($selector === '') {
            return ToolResult::error('type 需要 selector 参数');
        }
        if (!$session->type($selector, $text)) {
            return ToolResult::error('页面上找不到输入框：' . $selector);
        }
        return ToolResult::success("已填入 {$selector}");
    }

    /**
     * @param BrowserSession $session
     * @param array<string, mixed> $input
     * @return ToolResult
     */
    protected function doExtract(BrowserSession $session, array $input)
    {
        $selector = isset($input['selector']) ? (string) $input['selector'] : '';
        $text = $session->text($selector);
        if ($text === '' && $selector !== '') {
            return ToolResult::error('页面上找不到元素，或元素没有文本：' . $selector);
        }
        if (strlen($text) > $this->maxText) {
            $text = Text::cutBytes($text, $this->maxText) . "\n…（已截断）";
        }
        return ToolResult::success($text, ['url' => $session->url()]);
    }

    /**
     * @param BrowserSession $session
     * @param array<string, mixed> $input
     * @param ToolContext $context
     * @return ToolResult
     */
    protected function doScreenshot(BrowserSession $session, array $input, ToolContext $context)
    {
        $path = isset($input['path']) ? (string) $input['path'] : '';
        if ($path === '') {
            $path = 'screenshot_' . date('YmdHis') . '.png';
        }

        if ($this->pathSafety !== null) {
            try {
                $path = $this->pathSafety->resolve($path);
            } catch (\InvalidArgumentException $e) {
                return ToolResult::error('截图路径不可用：' . $e->getMessage());
            }
        } elseif (strpos($path, '/') !== 0) {
            $workdir = $context->workdir();
            $path = ($workdir !== '' ? rtrim($workdir, '/') . '/' : '') . $path;
        }

        $fullPage = !empty($input['full_page']);
        if (!$session->screenshot($path, $fullPage)) {
            return ToolResult::error('截图失败：' . $path);
        }
        return ToolResult::success('截图已保存：' . $path, ['path' => $path]);
    }

    /**
     * @param BrowserSession $session
     * @param array<string, mixed> $input
     * @return ToolResult
     */
    protected function doEvaluate(BrowserSession $session, array $input)
    {
        $script = isset($input['script']) ? (string) $input['script'] : '';
        if ($script === '') {
            return ToolResult::error('evaluate 需要 script 参数');
        }
        $value = $session->evaluate($script);
        if ($value === null) {
            return ToolResult::error('脚本执行失败或返回 null');
        }
        $text = is_scalar($value)
            ? (string) $value
            : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        if (strlen($text) > $this->maxText) {
            $text = Text::cutBytes($text, $this->maxText) . "\n…（已截断）";
        }
        return ToolResult::success($text);
    }

    /**
     * @param BrowserSession $session
     * @param array<string, mixed> $input
     * @return ToolResult
     */
    protected function doWait(BrowserSession $session, array $input)
    {
        $selector = isset($input['selector']) ? (string) $input['selector'] : '';
        if ($selector === '') {
            return ToolResult::error('wait 需要 selector 参数');
        }
        $timeout = isset($input['timeout_ms']) ? (int) $input['timeout_ms'] : 5000;
        if (!$session->waitFor($selector, $timeout)) {
            return ToolResult::error("等待超时（{$timeout}ms），元素未出现：" . $selector);
        }
        return ToolResult::success('元素已出现：' . $selector);
    }

    public function __destruct()
    {
        $this->close();
    }
}
