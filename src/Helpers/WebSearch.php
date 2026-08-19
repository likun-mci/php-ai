<?php
namespace Ai\Helpers;

use Ai\Exceptions\ConfigException;

/**
 * 联网搜索配置的归一化
 *
 * 「让模型自己上网查」这件事，各家给的是同一个能力、七套写法：
 *
 *   Claude      tools 里加一个服务端工具 {type:'web_search_20250305', name:'web_search'}
 *   通义千问     请求体顶层 enable_search + search_options{}
 *   智谱 GLM     tools 里加 {type:'web_search', web_search:{enable:true}}
 *   Kimi        tools 里加 {type:'builtin_function', function:{name:'$web_search'}}
 *   文心一言     请求体顶层 web_search{enable:true}
 *   OpenRouter  请求体顶层 plugins:[{id:'web'}]
 *   Perplexity  本来就联网，只有过滤参数（search_recency_filter 等）
 *
 * 本类把用户的一份统一配置翻译成上述各家的形态，翻译规则写在各协议类的
 * applyWebSearch() 里，这里只负责**归一化输入**与提供翻译时要用的公共转换。
 *
 * 设计上刻意只收各家**都有**的语义（开关、条数、时效、域名过滤……）。
 * 平台独有的参数（如通义的 search_strategy、智谱的 search_engine）不进统一配置，
 * 用 `extra_body` 直接写平台原生字段即可——统一层收得越窄，
 * 「声明支持却对不上」的错位就越少。
 */
class WebSearch
{
    /**
     * 统一配置项 => 说明
     *
     * 这些键都是**可选**的，平台不支持某项时静默忽略该项（而不是报错）——
     * 统一层的承诺是「搜索会开」，不是「每个细节都能在每个平台生效」。
     *
     * @var array<string, string>
     */
    const FIELDS = [
        'enable'          => '是否开启，默认 true',
        'max_uses'        => '单次请求最多搜几次',
        'count'           => '返回结果条数',
        'query'           => '强制指定搜索词（不指定则由模型自己拟）',
        'recency'         => '时效过滤：day / week / month / year',
        'forced'          => '强制搜索，不让模型自行判断要不要搜',
        'citation'        => '正文里带引用角标',
        'sources'         => '返回搜索来源列表',
        'allowed_domains' => '只搜这些域名',
        'blocked_domains' => '不搜这些域名',
    ];

    /**
     * 时效过滤的统一取值
     * @var string[]
     */
    const RECENCY = ['hour', 'day', 'week', 'month', 'year'];

    /**
     * 归一化用户传入的 search 配置
     *
     * 接受三种写法，都归一成同一个数组：
     *   true                        → ['enable' => true]
     *   ['count' => 5]              → ['enable' => true, 'count' => 5]（省略 enable 视为开）
     *   false / ['enable' => false] → null（表示不开）
     *
     * @param mixed $search
     * @return array<string, mixed>|null null 表示不开启搜索
     * @throws ConfigException 配置项互斥或取值非法
     */
    public static function normalize($search): ?array
    {
        if ($search === null || $search === false || $search === '' || $search === 0) {
            return null;
        }
        if (!is_array($search)) {
            // true / 1 / '1' 等真值都当作「按默认开启」
            return $search ? ['enable' => true] : null;
        }
        // 数组里显式关掉
        if (array_key_exists('enable', $search) && !$search['enable']) {
            return null;
        }

        $out = ['enable' => true];
        foreach ($search as $key => $value) {
            if ($key === 'enable' || $value === null) {
                continue;
            }
            if (!isset(self::FIELDS[$key])) {
                throw new ConfigException(
                    "Unknown search option '{$key}'. Available: " . implode(', ', array_keys(self::FIELDS))
                    . ". For platform-specific parameters use 'extra_body' instead."
                );
            }
            $out[$key] = $value;
        }

        // 域名黑白名单互斥：Claude 同时收到两者会直接返回 400，
        // 与其让用户对着平台的报错猜，不如在这里就说清楚
        if (!empty($out['allowed_domains']) && !empty($out['blocked_domains'])) {
            throw new ConfigException(
                "search: 'allowed_domains' and 'blocked_domains' are mutually exclusive, set only one."
            );
        }

        if (isset($out['recency'])) {
            $recency = strtolower((string) $out['recency']);
            if (!in_array($recency, self::RECENCY, true)) {
                throw new ConfigException(
                    "search: invalid 'recency' value '{$out['recency']}'. Allowed: " . implode(' / ', self::RECENCY)
                );
            }
            $out['recency'] = $recency;
        }

        foreach (['max_uses', 'count'] as $intKey) {
            if (isset($out[$intKey])) {
                $out[$intKey] = max(1, (int) $out[$intKey]);
            }
        }
        foreach (['allowed_domains', 'blocked_domains'] as $listKey) {
            if (isset($out[$listKey])) {
                $domains = [];
                foreach ((array) $out[$listKey] as $domain) {
                    $domain = trim((string) $domain);
                    if ($domain !== '') {
                        $domains[] = $domain;
                    }
                }
                $out[$listKey] = $domains;
            }
        }

        return $out;
    }

    /**
     * 取某项配置，没有则返回默认值
     *
     * @param array<string, mixed> $search
     * @param mixed                $default
     * @return mixed
     */
    public static function opt(array $search, string $key, $default = null)
    {
        return array_key_exists($key, $search) ? $search[$key] : $default;
    }

    /**
     * 时效过滤 → 智谱的驼峰写法（oneDay / oneWeek / oneMonth / oneYear）
     *
     * 智谱没有「一小时内」这一档，hour 只能并到 oneDay——
     * 宁可搜到的范围比要求的宽，也好过因为取值非法整个请求被拒。
     */
    public static function recencyToZhipu(string $recency): string
    {
        $map = [
            'hour'  => 'oneDay',
            'day'   => 'oneDay',
            'week'  => 'oneWeek',
            'month' => 'oneMonth',
            'year'  => 'oneYear',
        ];
        return isset($map[$recency]) ? $map[$recency] : 'noLimit';
    }
}
