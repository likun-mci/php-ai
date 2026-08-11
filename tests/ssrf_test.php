<?php
/**
 * SSRF 防护回归测试
 *
 * HttpFetch 的整条纵深防御（协议白名单、端口白名单、IP 钉死、逐跳重校验）
 * 都建立在「isPublicIp() 判断正确」这一个前提上——这一层漏了，整条链就穿了。
 * 本测试把所有已知绕过向量固化下来。
 *
 * 运行：php tests/ssrf_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\Tools\HttpFetch;

$fetch  = new HttpFetch();
$isPub  = new ReflectionMethod($fetch, 'isPublicIp');
$isPub->setAccessible(true);

function pad(string $t, int $w): string
{
    $n = $w - mb_strwidth($t, 'UTF-8');
    return $t . ($n > 0 ? str_repeat(' ', $n) : '');
}

// [地址, 期望放行, 说明]
$cases = [
    // ---- 必须拦截：常规内网 ----
    ['127.0.0.1',              false, '回环'],
    ['10.0.0.1',               false, '私有 A 段'],
    ['172.16.0.1',             false, '私有 B 段'],
    ['192.168.1.1',            false, '私有 C 段'],
    ['169.254.169.254',        false, '云元数据'],
    ['0.0.0.0',                false, '全零'],
    ['::1',                    false, 'IPv6 回环'],
    ['fd00::1',                false, 'IPv6 ULA'],
    ['fe80::1',                false, 'IPv6 链路本地'],

    // ---- 必须拦截：IPv6 内嵌 IPv4（filter_var 判不出来）----
    ['::ffff:127.0.0.1',       false, 'IPv4-mapped 回环'],
    ['::ffff:169.254.169.254', false, 'IPv4-mapped 云元数据'],
    ['::ffff:10.0.0.1',        false, 'IPv4-mapped 私有'],
    ['::7f00:1',               false, 'IPv4-compatible 回环'],
    ['64:ff9b::7f00:1',        false, 'NAT64 回环'],
    ['64:ff9b::a9fe:a9fe',     false, 'NAT64 云元数据'],

    // ---- 必须拦截：filter_var 未覆盖的保留段 ----
    ['100.64.0.1',             false, 'CGNAT 共享地址空间'],
    ['100.127.255.255',        false, 'CGNAT 边界'],
    ['192.0.0.1',              false, 'IETF 协议专用'],
    ['192.0.2.1',              false, 'TEST-NET-1'],
    ['198.18.0.1',             false, '基准测试段'],
    ['198.51.100.1',           false, 'TEST-NET-2'],
    ['203.0.113.1',            false, 'TEST-NET-3'],
    ['224.0.0.1',              false, '组播'],
    ['240.0.0.1',              false, '保留段'],
    ['255.255.255.255',        false, '广播'],
    ['2001:db8::1',            false, 'IPv6 文档用地址'],
    ['100::1',                 false, 'IPv6 丢弃前缀'],
    ['3ffe::1',                false, '已废弃的 6bone'],

    // ---- 必须放行：真实公网 ----
    ['8.8.8.8',                true,  '公网 IPv4'],
    ['1.1.1.1',                true,  '公网 IPv4'],
    ['104.16.0.1',             true,  '公网 IPv4'],
    ['2606:4700::1111',        true,  '公网 IPv6'],
    ['2001:4860:4860::8888',   true,  '公网 IPv6'],
    ['::ffff:8.8.8.8',         true,  'IPv4-mapped 公网'],
];

$failures = [];
echo "=== isPublicIp() 判定 ===\n\n";
echo pad('地址', 26), pad('说明', 26), "判定\n", str_repeat('-', 64), "\n";
foreach ($cases as [$ip, $wantPublic, $desc]) {
    $got = (bool) $isPub->invoke($fetch, $ip);
    $ok  = ($got === $wantPublic);
    if (!$ok) {
        $failures[] = "{$ip}（{$desc}）：期望" . ($wantPublic ? '放行' : '拦截')
                    . '，实际' . ($got ? '放行' : '拦截');
    }
    echo pad($ip, 26), pad($desc, 26),
         ($ok ? ($wantPublic ? '放行 ✓' : '拦截 ✓') : '✗ 不符预期'), "\n";
}

// ---- URL 级校验：协议 / 端口 / userinfo ----
echo "\n=== validateUrl() 协议与端口 ===\n\n";
$validate = new ReflectionMethod($fetch, 'validateUrl');
$validate->setAccessible(true);

$urls = [
    ['file:///etc/passwd',            false, '本地文件协议'],
    ['gopher://example.com/',         false, 'gopher 协议'],
    ['ftp://example.com/',            false, 'ftp 协议'],
    ['http://user:pass@example.com/', false, '带用户名密码'],
    ['http://example.com:22/',        false, '非白名单端口'],
    ['http://127.0.0.1/',             false, '直连回环'],
    ['http://[::1]/',                 false, 'IPv6 回环字面量'],
    ['http://[::ffff:127.0.0.1]/',    false, 'IPv4-mapped 字面量'],
    ['http://100.64.0.1/',            false, 'CGNAT 字面量'],
];
foreach ($urls as [$url, $wantOk, $desc]) {
    $r  = $validate->invoke($fetch, $url);
    $ok = ((bool) $r['ok'] === $wantOk);
    if (!$ok) { $failures[] = "{$url}（{$desc}）未被拦截"; }
    echo pad($url, 34), pad($desc, 22), ($ok ? '拦截 ✓' : '✗ 未拦截'), "\n";
}

echo "\n", str_repeat('=', 64), "\n";
if ($failures) {
    echo count($failures) . " 项未通过：\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    exit(1);
}
echo "全部通过\n";
exit(0);
