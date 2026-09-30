<?php
namespace Ai\Agent\Tools;

use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;

/**
 * browser_authorize 工具——取回「驱动用户浏览器」的授权码
 *
 * 场景：Agent 手里没有凭据，却需要操作用户本机那台真实浏览器（登录态、扩展、
 * 前端路由都在里面）。凭据不能凭空要，得让用户在自己的浏览器里点一次确认。
 * 这就是 mci browser 插件的匿名授权票据流程（`/Browser/Grant/*`，v1.5.0）：
 *
 *   start  向站点申请一张票据 → 拿到 `authorize_url`（给用户）与 `secret`（自己留着）
 *   poll   带 ticket + secret 轮询 → 用户确认后取回 device 与会员密钥明文（只给一次）
 *
 * 工具把它包成三步，与人的动作对齐：
 *
 *   action=start      申请票据，把 authorize_url 经事件交给宿主（前端新标签页打开），
 *                     票据与 secret 落到本次运行的私有目录
 *   action=status     读授权状态；确认了就取回 device + 密钥并落盘（这就是「授权码」）
 *   action=credential 只看本地已存的凭据（device / 密钥前缀 / api_base），不发请求
 *   action=forget     清掉本地的待确认票据与凭据
 *
 * ```php
 * $agent->addTool(new BrowserGrantTool('https://likun.work', ['app' => 'MCI AI Agent']));
 * // 模型调用：
 * //   browser_authorize(action: "start")            → 弹出授权页
 * //   browser_authorize(action: "status")           → 用户确认后取回凭据
 * //   browser_authorize(action: "status", wait_seconds: 60)   // 等一会儿，别让模型空转多次
 * ```
 *
 * ── 为什么要把状态放在磁盘上 ──
 * 授权天然跨「两次工具调用」乃至「两轮对话」：用户可能在下一轮才去点确认。
 * 状态留在进程内存里，模型换个回合来问就什么都没了；落到会话私有目录里，
 * 第二天回来问也还在。secret 能换出驱动用户浏览器的密钥，属敏感值——因此
 * 必须落在**按身份隔离**的目录（`ToolContext::storageDir()`），权限 0600；
 * storageDir 为空说明调用方没给身份，此时拒绝写盘并说明原因，而不是随便找个
 * 地方将就——两个用户共用一个凭据文件，比没有凭据更糟。
 *
 * ── 与 BrowserTool 的区别 ──
 * BrowserTool 在本机拉起 headless Chrome，是「服务端自己的浏览器」；
 * 本工具取的是「用户那台浏览器」的驱动权，页面在他眼前，登录态是他的。
 * 两者可以并存。
 *
 * 属联网 + 写盘工具，默认经 PermissionManager 把关（manual 询问）。
 */
class BrowserGrantTool implements AgentToolInterface
{
    /** 票据/凭据在身份目录下的子目录 */
    const DIR = 'browser';

    /** 待确认票据文件名（单份：同一身份同时只应有一次进行中的授权） */
    const GRANT_FILE = 'grant.json';

    /** 已取得凭据文件名（含密钥明文，0600） */
    const CRED_FILE = 'credential.json';

    /** @var string 站点根（如 https://likun.work），拼 start / poll 用 */
    protected $siteBase;

    /** @var string 调用方自报的名字，显示在确认页上给用户看 */
    protected $app;

    /** @var int 单次 HTTP 超时秒数 */
    protected $timeout;

    /** @var int status 最长在服务端等多久（秒），0 = 只查一次 */
    protected $maxWait;

    /** @var callable|null 注入的 HTTP 客户端 function(string $method, string $url, array $form): array */
    protected $http;

    /**
     * @param string $siteBase 站点根，如 https://likun.work（不带尾斜杠）
     * @param array<string, mixed> $options app / timeout / max_wait
     * @param callable|null $http 可注入（测试）；生产留空走 cURL
     */
    public function __construct($siteBase, array $options = [], $http = null)
    {
        $this->siteBase = rtrim(trim((string) $siteBase), '/');
        $this->app      = isset($options['app']) ? (string) $options['app'] : 'MCI AI Agent';
        if ($this->app === '') {
            $this->app = 'MCI AI Agent';
        }
        $this->timeout = max(3, (int) ($options['timeout'] ?? 15));
        $this->maxWait = max(0, min(300, (int) ($options['max_wait'] ?? 120)));
        $this->http    = is_callable($http) ? $http : null;
    }

    public function name()
    {
        return 'browser_authorize';
    }

    public function description()
    {
        return '取回操作用户本机真实浏览器所需的授权码（device + 会员密钥），走「弹授权链接 → 用户在自己浏览器里确认 → 轮询取回」这套流程。'
            . '需要驱动用户那台带着登录态的浏览器时先用它拿授权；只是取静态页面用 HTTP 抓取、服务端自己开浏览器用 browser。'
            . 'action=start 申请并弹出授权页，action=status 查授权状态（确认后取回凭据），action=credential 看已存凭据，action=forget 清本地凭据。';
    }

    public function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type'        => 'string',
                    'description' => 'start 申请授权并弹出确认页；status 查状态/取回凭据；credential 看本地已有凭据；forget 清掉本地凭据',
                    'enum'        => ['start', 'status', 'credential', 'forget'],
                ],
                'app' => [
                    'type'        => 'string',
                    'description' => 'start 时自报的名字，用户在确认页上看到它（可省略，用部署方默认值）',
                ],
                'wait_seconds' => [
                    'type'        => 'integer',
                    'description' => 'status 用：在服务端等待用户确认的秒数（0 = 只查一次，默认 0，上限 300）',
                    'default'     => 0,
                ],
            ],
            'required'   => ['action'],
        ];
    }

    public function execute(array $input, ToolContext $context)
    {
        if ($this->siteBase === '') {
            return ToolResult::error('未配置浏览器服务站点，无法申请授权');
        }

        $action = isset($input['action']) ? (string) $input['action'] : '';
        switch ($action) {
            case 'start':
                return $this->start($input, $context);
            case 'status':
                return $this->status($input, $context);
            case 'credential':
                return $this->credential($context);
            case 'forget':
                return $this->forget($context);
            default:
                return ToolResult::error('action 必须是 start / status / credential / forget');
        }
    }

    // ==================== 动作 ====================

    /**
     * 申请票据并请用户确认
     *
     * @param array<string, mixed> $input
     * @param ToolContext $context
     * @return ToolResult
     */
    protected function start(array $input, ToolContext $context)
    {
        $path = $this->resolvePath($context);
        if ($path === null) {
            return ToolResult::error($this->noIdentityHint());
        }

        $app = isset($input['app']) ? trim((string) $input['app']) : '';
        if ($app === '') {
            $app = $this->app;
        }

        $res = $this->request('POST', $this->siteBase . '/Browser/Grant/start', ['app' => $app]);
        if (!$res['ok']) {
            return ToolResult::error('申请授权失败：' . $res['error']);
        }
        $data = $this->decode($res['body']);
        if ($data === null) {
            return ToolResult::error('申请授权失败：服务端返回了无法解析的内容');
        }
        if ((int) $res['status'] >= 400) {
            return ToolResult::error('申请授权失败：' . $this->messageOf($data, 'HTTP ' . $res['status']));
        }

        $r       = is_array($data['result'] ?? null) ? $data['result'] : [];
        $ticket  = trim((string) ($r['ticket'] ?? ''));
        $secret  = trim((string) ($r['secret'] ?? ''));
        $authUrl = trim((string) ($r['authorize_url'] ?? ''));
        $pollUrl = trim((string) ($r['poll_url'] ?? ''));
        if ($ticket === '' || $secret === '' || $authUrl === '') {
            return ToolResult::error('申请授权失败：服务端响应缺少 ticket / secret / authorize_url');
        }

        $ttl = (int) ($r['expires_in'] ?? 0);
        $grant = [
            'ticket'        => $ticket,
            'secret'        => $secret,
            'app'           => $app,
            'authorize_url' => $authUrl,
            'poll_url'      => $pollUrl !== '' ? $pollUrl : $this->siteBase . '/Browser/Grant/poll',
            'interval'      => max(1, (int) ($r['interval'] ?? 3)),
            'expires_at'    => $ttl > 0 ? time() + $ttl : 0,
        ];
        if (!$this->writeJson($path['grant'], $grant)) {
            return ToolResult::error('授权已申请，但票据写入本地失败（检查存储目录权限）：' . $path['grant']);
        }

        // 交给宿主：前端据此新标签页打开 authorize_url（后端开不了浏览器）
        $context->emit('browser_authorize', [
            'authorize_url' => $authUrl,
            'app'           => $app,
            'expires_at'    => (int) $grant['expires_at'],
            'expires_in'    => $ttl,
        ]);

        $left = $ttl > 0 ? ('，' . round($ttl / 60) . ' 分钟内有效') : '';
        return ToolResult::success(
            "已申请授权，确认页已在浏览器新标签页打开（链接：{$authUrl}{$left}）。\n"
            . "请让用户在该页面上选择要用哪台浏览器、并确认把密钥交给「{$app}」。\n"
            . '用户确认后，调用 browser_authorize(action: "status") 取回凭据；'
            . '若用户还没操作，可以带 wait_seconds 稍等一下再问。'
        );
    }

    /**
     * 查状态；已确认则取回 device 与密钥
     *
     * @param array<string, mixed> $input
     * @param ToolContext $context
     * @return ToolResult
     */
    protected function status(array $input, ToolContext $context)
    {
        $path = $this->resolvePath($context);
        if ($path === null) {
            return ToolResult::error($this->noIdentityHint());
        }
        $grant = $this->readJson($path['grant']);
        if ($grant === null) {
            $cred = $this->readJson($path['cred']);
            if ($cred !== null && (string) ($cred['key'] ?? '') !== '') {
                return ToolResult::success(
                    '本地已有一份浏览器凭据（device ' . (string) ($cred['device'] ?? '') . '），'
                    . '无需重新授权；查看详情用 action=credential，换一份用 action=start。'
                );
            }
            return ToolResult::success('当前没有进行中的授权申请。要授权请先调用 browser_authorize(action: "start")。');
        }

        $expiresAt = (int) ($grant['expires_at'] ?? 0);
        if ($expiresAt > 0 && time() >= $expiresAt) {
            @unlink($path['grant']);
            return ToolResult::success('上一张授权票据已过期，用户没有在有效期内确认。请重新调用 action=start 申请一张。');
        }

        $wait     = max(0, min(300, (int) ($input['wait_seconds'] ?? 0)));
        $wait     = min($wait, $this->maxWait);
        $deadline = time() + $wait;
        $interval = max(1, (int) ($grant['interval'] ?? 3));

        while (true) {
            $res  = $this->request('GET', (string) $grant['poll_url'], [
                'ticket' => (string) $grant['ticket'],
                'secret' => (string) $grant['secret'],
            ]);
            if (!$res['ok']) {
                return ToolResult::error('查询授权状态失败：' . $res['error']);
            }
            $data = $this->decode($res['body']);
            if ($data === null) {
                return ToolResult::error('查询授权状态失败：服务端返回了无法解析的内容');
            }

            $code = (int) $res['status'];
            // 票据被清理 / 手工用过 / secret 不对：这份本地票据已经废了，清掉重来
            if ($code === 409 || $code === 403) {
                @unlink($path['grant']);
                return ToolResult::success(
                    '授权票据已失效（' . $this->messageOf($data, 'HTTP ' . $code) . '），本地记录已清除。'
                    . '如需继续，请重新调用 action=start。'
                );
            }
            if ($code >= 400) {
                return ToolResult::error('查询授权状态失败：' . $this->messageOf($data, 'HTTP ' . $code));
            }

            $r = is_array($data['result'] ?? null) ? $data['result'] : [];
            if (!empty($r['chosen'])) {
                return $this->storeCredential($r, $path, $context);
            }

            if (time() >= $deadline || $context->isCancelled()) {
                return ToolResult::success(
                    '用户还没有确认授权。确认页可能还开着——请提醒用户完成确认，'
                    . '或稍后再次调用 browser_authorize(action: "status", wait_seconds: 60)。'
                );
            }
            sleep(min($interval, max(1, $deadline - time())));
        }
    }

    /**
     * 已存凭据一览（不回密钥明文）
     *
     * @param ToolContext $context
     * @return ToolResult
     */
    protected function credential(ToolContext $context)
    {
        $path = $this->resolvePath($context);
        if ($path === null) {
            return ToolResult::error($this->noIdentityHint());
        }
        $cred = $this->readJson($path['cred']);
        if ($cred === null || (string) ($cred['device'] ?? '') === '') {
            return ToolResult::success('本地还没有浏览器凭据。调用 browser_authorize(action: "start") 让用户授权。');
        }
        $lines = [
            '本地已存浏览器凭据：',
            '- 浏览器：' . (string) ($cred['device'] ?? '') . ((string) ($cred['name'] ?? '') !== '' ? '（' . (string) $cred['name'] . '）' : ''),
            '- 密钥前缀：' . (string) ($cred['key_prefix'] ?? '') . '…（明文只在取回时给过一次，本地留档）',
            '- 接口基址：' . (string) ($cred['api_base'] ?? ''),
            '- 取得时间：' . date('Y-m-d H:i:s', (int) ($cred['obtained_at'] ?? 0)),
        ];
        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * 清掉本地凭据与待确认票据
     *
     * @param ToolContext $context
     * @return ToolResult
     */
    protected function forget(ToolContext $context)
    {
        $path = $this->resolvePath($context);
        if ($path === null) {
            return ToolResult::error($this->noIdentityHint());
        }
        $had = false;
        foreach ([$path['grant'], $path['cred']] as $f) {
            if (is_file($f)) {
                @unlink($f);
                $had = true;
            }
        }
        return ToolResult::success(
            $had
                ? '本地凭据与待确认票据已清除。注意：这只删了本机存的副本，'
                    . '服务端那把密钥仍有效，要作废得在站点的「API 密钥」页吊销。'
                : '本地本来就没有浏览器凭据。'
        );
    }

    // ==================== 凭据落盘 ====================

    /**
     * 把 poll 取回的 device / 密钥存下来（0600）
     *
     * 密钥明文只在这一次返回给模型（站点的设计：只给一次），本地留档是为了
     * 后续操作还能用；同时删掉已用掉的票据。
     *
     * @param array<string, mixed> $r poll 返回的 result
     * @param array<string, string> $path resolvePath() 的结果
     * @param ToolContext $context
     * @return ToolResult
     */
    protected function storeCredential(array $r, array $path, ToolContext $context)
    {
        $key = trim((string) ($r['key'] ?? ''));
        $cred = [
            'device'      => (string) ($r['device'] ?? ''),
            'name'        => (string) ($r['name'] ?? ''),
            'online'      => !empty($r['online']),
            'key'         => $key,
            'key_prefix'  => (string) ($r['key_prefix'] ?? ''),
            'api_base'    => (string) ($r['api_base'] ?? ''),
            'obtained_at' => time(),
        ];
        @unlink($path['grant']);
        if (!$this->writeJson($path['cred'], $cred)) {
            return ToolResult::error('授权已确认，但凭据写入本地失败（检查存储目录权限）：' . $path['cred']);
        }

        // 事件里不带密钥明文：它已经交给模型与本地留档，不必再经前端走一圈
        $context->emit('browser_credential', [
            'device'     => $cred['device'],
            'name'       => $cred['name'],
            'online'     => $cred['online'],
            'key_prefix' => $cred['key_prefix'],
            'api_base'   => $cred['api_base'],
        ]);

        $lines = [
            '授权已确认，已取回浏览器凭据：',
            '- 浏览器：' . $cred['device'] . ($cred['name'] !== '' ? '（' . $cred['name'] . '）' : ''),
            '- 在线：' . ($cred['online'] ? '是' : '否（浏览器扩展可能没启动）'),
            '- 接口基址：' . $cred['api_base'],
            '- 密钥（明文只此一次）：' . ($key !== '' ? $key : '（服务端未返回明文，请重新授权）'),
            '',
            '凭据已存在本会话私有目录，后续步骤可用；用户随时可在站点的「API 密钥」页吊销它。',
        ];
        return ToolResult::success(implode("\n", $lines));
    }

    // ==================== 本地文件 ====================

    /**
     * 解析本身份的存储路径；身份不明（没 userId 也没 sessionId）时返回 null
     *
     * @return array{dir: string, grant: string, cred: string}|null
     */
    protected function resolvePath(ToolContext $context)
    {
        $root = trim((string) $context->storageDir());
        if ($root === '') {
            return null;
        }
        $dir = rtrim($root, '/') . '/' . self::DIR;
        return [
            'dir'   => $dir,
            'grant' => $dir . '/' . self::GRANT_FILE,
            'cred'  => $dir . '/' . self::CRED_FILE,
        ];
    }

    /** @return string */
    protected function noIdentityHint()
    {
        return '本会话没有身份（既没 userId 也没 sessionId），无处安全存放授权凭据。'
            . '浏览器凭据能把用户的浏览器交给调用方驱动，不能落到两个人共用的位置——'
            . '请让调用方先 setUserId() 或 setSessionId()。';
    }

    /**
     * @param string $file
     * @return array<string, mixed>|null
     */
    protected function readJson($file)
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param string $file
     * @param array<string, mixed> $data
     * @return bool
     */
    protected function writeJson($file, array $data)
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        @chmod($dir, 0700);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }
        if (@file_put_contents($file, $json) === false) {
            return false;
        }
        @chmod($file, 0600);   // 里面有能驱动用户浏览器的密钥
        return true;
    }

    // ==================== HTTP ====================

    /**
     * 发一次请求
     *
     * @param string $method GET / POST
     * @param string $url
     * @param array<string, string> $form POST 用表单、GET 用查询串
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    protected function request($method, $url, array $form = [])
    {
        if ($this->http !== null) {
            $r = call_user_func($this->http, $method, $url, $form);
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

        $ch = curl_init();
        $headers = ['Accept: application/json'];
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            if ($form !== []) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($form);
            }
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(8, $this->timeout));
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
}
