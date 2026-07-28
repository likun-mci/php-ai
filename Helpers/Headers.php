<?php
namespace Ai\Helpers;

/**
 * 请求头合并：让业务层可以为「自定义/第三方接口」追加或覆盖任意请求头。
 *
 * 用法（config）：
 * ```php
 * 'headers' => [
 *     'X-Token'       => 'abc',    // 追加
 *     'Authorization' => null,     // 删除协议默认写入的鉴权头
 *     'anthropic-version' => '2023-06-01',
 * ]
 * ```
 * 同名头以用户配置为准（不区分大小写）。
 */
class Headers
{
    /**
     * @param array $headers 协议构建出的默认请求头
     * @param array $config  运行时配置，读取 headers
     * @return array
     */
    public static function apply(array $headers, array $config): array
    {
        $extra = $config['headers'] ?? [];
        if (!is_array($extra) || !$extra) {
            return $headers;
        }

        foreach ($extra as $name => $value) {
            if (!is_string($name) || trim($name) === '') {
                continue;
            }
            $name = trim($name);

            // 先移除同名（忽略大小写）的默认头，保证用户配置生效
            foreach (array_keys($headers) as $exists) {
                if (strcasecmp($exists, $name) === 0) {
                    unset($headers[$exists]);
                }
            }

            // 值为 null / false 表示删除该头
            if ($value === null || $value === false) {
                continue;
            }
            $headers[$name] = is_scalar($value) ? (string)$value : json_encode($value);
        }

        return $headers;
    }
}
