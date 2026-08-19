<?php
namespace Ai\Protocol\Concerns;

/**
 * 协议层的联网搜索支持
 *
 * 与图像/语音那套「扩展能力」不同，联网搜索**不是独立端点**，
 * 它只是对话请求体里的一组字段，所以不进 Capabilities 那张表，
 * 而是在 buildRequest() 里就地翻译。
 *
 * 默认实现是「不支持」——这是刻意的保守取值。库里 41 个协议类，
 * 逐个去查证哪家真有联网搜索、参数叫什么，本身就是件容易做错的事；
 * 一旦默认声明「支持」，没查证过的平台就会拿着一个平台根本不认的字段发请求，
 * 用户收到的是平台的 400 而不是库的明确提示。
 * 反过来默认「不支持」，最坏情况只是用户被挡下来、按提示改用 extra_body，
 * 而这条路本来就是通的。
 *
 * 协议类支持搜索时覆写两个方法：
 *
 * ```php
 * public function supportsWebSearch(): bool
 * {
 *     return true;
 * }
 *
 * public function applyWebSearch(array $request, array $search): array
 * {
 *     $request['enable_search'] = true;
 *     return $request;
 * }
 * ```
 *
 * @see \Ai\Helpers\WebSearch 统一配置的归一化与公共转换
 */
trait WebSearchSupport
{
    /**
     * 本协议是否支持由请求参数开启联网搜索
     *
     * 返回 false 时，用户配了 `search` 会拿到一条明确的报错（而不是被静默忽略），
     * 报错里会指向 `extra_body` 逃生口。
     */
    public function supportsWebSearch(): bool
    {
        return false;
    }

    /**
     * 把归一化后的搜索配置翻译进请求体
     *
     * @param array<string, mixed> $request 已构建好的平台请求体
     * @param array<string, mixed> $search  归一化后的搜索配置，见 \Ai\Helpers\WebSearch::normalize()
     * @return array<string, mixed>
     */
    public function applyWebSearch(array $request, array $search): array
    {
        return $request;
    }

    /**
     * 往请求体的 tools 数组里追加一个平台服务端工具
     *
     * 好几家（Claude / 智谱 / Kimi / xAI）都把联网搜索做成「tools 里的一个内置工具」，
     * 追加时要留住用户自己定义的函数工具，不能直接覆盖。
     *
     * @param array<string, mixed>          $request
     * @param array<string, mixed>          $tool
     * @return array<string, mixed>
     */
    protected function appendServerTool(array $request, array $tool): array
    {
        $tools = isset($request['tools']) && is_array($request['tools']) ? $request['tools'] : [];
        $tools[] = $tool;
        $request['tools'] = $tools;
        return $request;
    }
}
