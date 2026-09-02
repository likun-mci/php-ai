<?php
namespace Ai\Agent\Tools;

/**
 * BrowserSession——Chrome DevTools Protocol 会话
 *
 * 拉起一个 headless Chrome，通过 CDP 控制它：打开页面、点击、输入、截图、取内容。
 * 跟 curl 抓页面的区别是这里跑的是真浏览器——JS 渲染出来的内容、登录后的状态、
 * 前端路由，抓 HTML 拿不到的东西这里都拿得到。
 *
 * ```php
 * $session = new BrowserSession(['binary' => '/usr/bin/chromium']);
 * $session->launch();
 * $session->navigate('https://example.com');
 * echo $session->text('h1');
 * $session->type('#search', 'php');
 * $session->click('button[type=submit]');
 * $session->screenshot('/tmp/shot.png');
 * $session->close();
 * ```
 *
 * 实现方式：`--headless --dump-dom` 之类的一次性调用满足不了"点一下再看"的需求，
 * 所以这里用 `--remote-debugging-port` 起一个常驻实例，用 CDP 的 HTTP 端点
 * （`/json`、`/json/new`）拿目标页，用 WebSocket 发命令。
 *
 * 依赖：本机装了 Chrome / Chromium，且允许 `proc_open`；`wss` 不需要，
 * CDP 走本地 `ws://`，因此不依赖 openssl。
 */
class BrowserSession
{
    /** @var string chrome 可执行文件 */
    protected $binary = '';

    /** @var int 调试端口 */
    protected $port = 0;

    /** @var resource|null chrome 进程 */
    protected $process = null;

    /** @var string 用户数据目录（临时） */
    protected $userDataDir = '';

    /** @var \Ai\Realtime\WebSocketClient|null CDP 连接 */
    protected $ws = null;

    /** @var int CDP 命令 ID 自增 */
    protected $commandId = 0;

    /** @var int 超时秒数 */
    protected $timeout = 30;

    /** @var bool 是否 headless */
    protected $headless = true;

    /** @var string 当前页面的 WebSocket 调试地址 */
    protected $wsUrl = '';

    /** @var string[] 额外的 chrome 启动参数 */
    protected $extraArgs = [];

    /**
     * @param array<string, mixed> $options binary / port / timeout / headless / args / userDataDir
     */
    public function __construct(array $options = [])
    {
        $this->binary = isset($options['binary']) ? (string) $options['binary'] : '';
        $this->port = isset($options['port']) ? (int) $options['port'] : 0;
        $this->timeout = isset($options['timeout']) ? max(1, (int) $options['timeout']) : 30;
        $this->headless = isset($options['headless']) ? (bool) $options['headless'] : true;
        if (isset($options['args']) && is_array($options['args'])) {
            $this->extraArgs = array_values(array_map('strval', $options['args']));
        }
        if (isset($options['userDataDir'])) {
            $this->userDataDir = (string) $options['userDataDir'];
        }
    }

    /**
     * 找一个可用的 Chrome 可执行文件
     *
     * 按常见安装路径逐个试；找不到返回空串，由调用方决定怎么报错——
     * 让工具返回"没装浏览器"比抛异常中断整个 Agent 循环有用。
     *
     * @return string
     */
    public static function detectBinary()
    {
        $candidates = [
            'google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser',
            '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser',
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Chromium.app/Contents/MacOS/Chromium',
        ];
        foreach ($candidates as $candidate) {
            if (strpos($candidate, '/') === 0) {
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
                continue;
            }
            $found = @shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null');
            if (is_string($found) && trim($found) !== '') {
                return trim($found);
            }
        }
        return '';
    }

    /**
     * 浏览器是否可用（装了 Chrome 且能开子进程）
     *
     * @return bool
     */
    public static function isAvailable()
    {
        return self::detectBinary() !== '' && function_exists('proc_open');
    }

    /**
     * 启动浏览器
     *
     * @return bool 启动成功返回 true
     */
    public function launch()
    {
        if ($this->process !== null) {
            return true;
        }
        if ($this->binary === '') {
            $this->binary = self::detectBinary();
        }
        if ($this->binary === '' || !function_exists('proc_open')) {
            return false;
        }
        // 绝对路径先自己判一下：proc_open 对不存在的命令照样返回句柄，
        // 等 waitForDebugger 超时才发现太慢了
        if (strpos($this->binary, '/') === 0 && !is_executable($this->binary)) {
            return false;
        }

        if ($this->port <= 0) {
            $this->port = $this->findFreePort();
        }
        if ($this->userDataDir === '') {
            $this->userDataDir = sys_get_temp_dir() . '/php_ai_chrome_' . getmypid() . '_' . $this->port;
        }
        @mkdir($this->userDataDir, 0777, true);

        $args = [
            '--remote-debugging-port=' . $this->port,
            '--user-data-dir=' . $this->userDataDir,
            '--no-first-run',
            '--no-default-browser-check',
            '--disable-gpu',
            '--disable-dev-shm-usage',
        ];
        if ($this->headless) {
            $args[] = '--headless=new';
        }
        foreach ($this->extraArgs as $arg) {
            $args[] = $arg;
        }

        $cmd = escapeshellarg($this->binary);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if ($proc === false) {
            return false;
        }
        $this->process = $proc;
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                stream_set_blocking($pipe, false);
            }
        }

        if ($this->waitForDebugger()) {
            return true;
        }
        // 起来了但调试端口没通——留着一个半死的进程只会让后续调用一路超时
        $this->close();
        return false;
    }

    /**
     * 浏览器是否在跑
     *
     * @return bool
     */
    public function isRunning()
    {
        return $this->process !== null;
    }

    /**
     * 打开一个 URL
     *
     * @param string $url
     * @param int $waitMs 导航后等待渲染的毫秒数
     * @return bool
     */
    public function navigate($url, $waitMs = 1000)
    {
        if (!$this->ensureTarget()) {
            return false;
        }
        $result = $this->send('Page.navigate', ['url' => (string) $url]);
        if ($result === null) {
            return false;
        }
        usleep(max(0, (int) $waitMs) * 1000);
        return true;
    }

    /**
     * 当前页面 URL
     *
     * @return string
     */
    public function url()
    {
        $value = $this->evaluate('location.href');
        return is_string($value) ? $value : '';
    }

    /**
     * 当前页面标题
     *
     * @return string
     */
    public function title()
    {
        $value = $this->evaluate('document.title');
        return is_string($value) ? $value : '';
    }

    /**
     * 页面完整 HTML
     *
     * @return string
     */
    public function html()
    {
        $value = $this->evaluate('document.documentElement.outerHTML');
        return is_string($value) ? $value : '';
    }

    /**
     * 提取选择器匹配到的文本
     *
     * 选择器匹配不到时返回空串——页面结构变了是常态，抛异常会让 Agent
     * 卡在一次失败的提取上。
     *
     * @param string $selector CSS 选择器，空串表示整页
     * @return string
     */
    public function text($selector = '')
    {
        $selector = (string) $selector;
        if ($selector === '') {
            $value = $this->evaluate('document.body ? document.body.innerText : ""');
            return is_string($value) ? $value : '';
        }
        $expr = '(function(){var el=document.querySelector(' . json_encode($selector) . ');'
            . 'return el ? el.innerText : "";})()';
        $value = $this->evaluate($expr);
        return is_string($value) ? $value : '';
    }

    /**
     * 提取多个元素的文本
     *
     * @param string $selector
     * @param int $limit
     * @return string[]
     */
    public function extractAll($selector, $limit = 50)
    {
        $limit = max(1, (int) $limit);
        $expr = '(function(){return Array.prototype.slice.call('
            . 'document.querySelectorAll(' . json_encode((string) $selector) . '), 0, ' . $limit . ')'
            . '.map(function(e){return e.innerText;});})()';
        $value = $this->evaluate($expr);
        return is_array($value) ? array_map('strval', $value) : [];
    }

    /**
     * 点击一个元素
     *
     * 用 JS 触发 click，而不是模拟鼠标坐标——坐标点击要先算元素位置，
     * 遇到滚动和浮层就不准了。
     *
     * @param string $selector
     * @param int $waitMs 点击后等待的毫秒数
     * @return bool 元素不存在返回 false
     */
    public function click($selector, $waitMs = 500)
    {
        $expr = '(function(){var el=document.querySelector(' . json_encode((string) $selector) . ');'
            . 'if(!el){return false;} el.click(); return true;})()';
        $result = $this->evaluate($expr);
        if ($result === true) {
            usleep(max(0, (int) $waitMs) * 1000);
            return true;
        }
        return false;
    }

    /**
     * 往输入框填文本
     *
     * 填完派发 input 与 change 事件，否则 Vue / React 这类框架收不到值变化。
     *
     * @param string $selector
     * @param string $text
     * @return bool
     */
    public function type($selector, $text)
    {
        $expr = '(function(){var el=document.querySelector(' . json_encode((string) $selector) . ');'
            . 'if(!el){return false;} el.focus(); el.value=' . json_encode((string) $text) . ';'
            . 'el.dispatchEvent(new Event("input",{bubbles:true}));'
            . 'el.dispatchEvent(new Event("change",{bubbles:true})); return true;})()';
        return $this->evaluate($expr) === true;
    }

    /**
     * 截图
     *
     * @param string $path 保存路径（.png）
     * @param bool $fullPage 是否整页截图
     * @return bool
     */
    public function screenshot($path, $fullPage = false)
    {
        if (!$this->ensureTarget()) {
            return false;
        }
        $params = ['format' => 'png'];
        if ($fullPage) {
            $params['captureBeyondViewport'] = true;
        }
        $result = $this->send('Page.captureScreenshot', $params);
        if (!is_array($result) || !isset($result['data'])) {
            return false;
        }
        $binary = base64_decode((string) $result['data'], true);
        if ($binary === false) {
            return false;
        }
        return @file_put_contents((string) $path, $binary) !== false;
    }

    /**
     * 在页面里执行 JS 并取回结果
     *
     * @param string $expression
     * @return mixed 执行失败返回 null
     */
    public function evaluate($expression)
    {
        if (!$this->ensureTarget()) {
            return null;
        }
        $result = $this->send('Runtime.evaluate', [
            'expression'    => (string) $expression,
            'returnByValue' => true,
            'awaitPromise'  => true,
        ]);
        if (!is_array($result) || !isset($result['result'])) {
            return null;
        }
        if (isset($result['exceptionDetails'])) {
            return null;
        }
        return isset($result['result']['value']) ? $result['result']['value'] : null;
    }

    /**
     * 等待某个选择器出现
     *
     * @param string $selector
     * @param int $timeoutMs
     * @return bool
     */
    public function waitFor($selector, $timeoutMs = 5000)
    {
        $deadline = microtime(true) + max(0, (int) $timeoutMs) / 1000;
        $expr = 'document.querySelector(' . json_encode((string) $selector) . ') !== null';
        while (microtime(true) < $deadline) {
            if ($this->evaluate($expr) === true) {
                return true;
            }
            usleep(200000);
        }
        return false;
    }

    /**
     * 关闭浏览器并清理临时目录
     *
     * @return void
     */
    public function close()
    {
        if ($this->ws !== null) {
            try {
                $this->ws->close();
            } catch (\Throwable $e) {
                // 关闭阶段的异常无关紧要
            }
            $this->ws = null;
        }
        $this->wsUrl = '';

        if ($this->process !== null) {
            if (is_resource($this->process)) {
                if (function_exists('proc_terminate')) {
                    @proc_terminate($this->process, 15);
                }
                for ($i = 0; $i < 20; $i++) {
                    $status = @proc_get_status($this->process);
                    if (!is_array($status) || empty($status['running'])) {
                        break;
                    }
                    usleep(100000);
                }
                @proc_close($this->process);
            }
            $this->process = null;
        }

        if ($this->userDataDir !== '' && is_dir($this->userDataDir)) {
            @exec('rm -rf ' . escapeshellarg($this->userDataDir));
        }
    }

    /**
     * 调试端口
     *
     * @return int
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * 使用的 Chrome 路径
     *
     * @return string
     */
    public function getBinary()
    {
        return $this->binary;
    }

    /**
     * 等 CDP 端点起来
     *
     * @return bool
     */
    protected function waitForDebugger()
    {
        $deadline = microtime(true) + $this->timeout;
        while (microtime(true) < $deadline) {
            $version = $this->httpGet('/json/version');
            if ($version !== '' && strpos($version, 'webSocketDebuggerUrl') !== false) {
                return true;
            }
            usleep(200000);
        }
        return false;
    }

    /**
     * 确保有一个可操作的页面目标
     *
     * @return bool
     */
    protected function ensureTarget()
    {
        if ($this->ws !== null && $this->ws->isConnected()) {
            return true;
        }
        if ($this->process === null && !$this->launch()) {
            return false;
        }

        $wsUrl = $this->findPageTarget();
        if ($wsUrl === '') {
            return false;
        }

        try {
            $client = new \Ai\Realtime\WebSocketClient(['timeout' => $this->timeout]);
            $client->connect($wsUrl);
            $this->ws = $client;
            $this->wsUrl = $wsUrl;
        } catch (\Throwable $e) {
            $this->ws = null;
            return false;
        }

        $this->send('Page.enable', []);
        $this->send('Runtime.enable', []);
        return true;
    }

    /**
     * 找一个页面目标的 WebSocket 地址，没有就新建一个
     *
     * @return string
     */
    protected function findPageTarget()
    {
        $list = json_decode($this->httpGet('/json/list'), true);
        if (is_array($list)) {
            foreach ($list as $target) {
                if (is_array($target)
                    && isset($target['type'], $target['webSocketDebuggerUrl'])
                    && $target['type'] === 'page') {
                    return (string) $target['webSocketDebuggerUrl'];
                }
            }
        }

        $created = json_decode($this->httpGet('/json/new?about:blank'), true);
        if (is_array($created) && isset($created['webSocketDebuggerUrl'])) {
            return (string) $created['webSocketDebuggerUrl'];
        }
        return '';
    }

    /**
     * 发一条 CDP 命令
     *
     * @param string $method
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    protected function send($method, array $params = [])
    {
        if ($this->ws === null || !$this->ws->isConnected()) {
            return null;
        }
        $this->commandId++;
        $id = $this->commandId;

        $payload = json_encode([
            'id'     => $id,
            'method' => (string) $method,
            'params' => (object) $params,
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return null;
        }

        try {
            $this->ws->sendText($payload);
            $matched = null;
            // CDP 会穿插推事件，只认 id 对得上的那条响应
            $this->ws->receiveUntil(function (array $message) use ($id, &$matched) {
                $decoded = json_decode(isset($message['payload']) ? $message['payload'] : '', true);
                if (!is_array($decoded) || !isset($decoded['id'])) {
                    return false;
                }
                if ((int) $decoded['id'] !== $id) {
                    return false;
                }
                $matched = isset($decoded['result']) && is_array($decoded['result'])
                    ? $decoded['result']
                    : [];
                return true;
            });
            return $matched;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 请求 CDP 的 HTTP 端点
     *
     * @param string $path
     * @return string
     */
    protected function httpGet($path)
    {
        $url = 'http://127.0.0.1:' . $this->port . $path;
        $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context);
        return $body === false ? '' : (string) $body;
    }

    /**
     * 找一个空闲端口
     *
     * @return int
     */
    protected function findFreePort()
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            return 9222 + (getmypid() % 1000);
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $pos = strrpos((string) $name, ':');
        return $pos === false ? 9222 : (int) substr((string) $name, $pos + 1);
    }

    public function __destruct()
    {
        $this->close();
    }
}
