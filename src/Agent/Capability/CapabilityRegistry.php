<?php
namespace Ai\Agent\Capability;

/**
 * 模型能力表
 *
 * 匹配优先级（先命中先用，不做合并）：
 *
 * ```text
 * 用户 override（精确模型名）
 *      ↓
 * 用户 override（模式：前缀 / wildcard）
 *      ↓
 * 内置表（精确模型名）
 *      ↓
 * 内置表（模式）
 *      ↓
 * 平台声明（platforms() 里的 modalities）
 *      ↓
 * null —— 未知
 * ```
 *
 * **`null` 与 `false` 是两回事**（设计文档 §13）：`null` 是「不知道」，
 * `false` 是「确定不支持」。混淆这两者会导致两种错误——把图片发给看不了图的
 * 模型（换回一个难懂的 400），或者放着能用的模型不用。所以查不到时返回 `null`，
 * 由调用方按 `unknown_policy` 决定怎么办。
 *
 * **内置表只填有把握的。** 拿不准的一律不写，让它是 `null`——「不知道」是
 * 诚实的答案，猜错则是会误导路由的错误答案。
 *
 * ⚠️ **不按协议家族推断能力**（设计文档 §18）。本库 40 个平台里大量是
 * 「OpenAI 兼容」的中转与自建网关，协议一样不代表模型能力一样：同一个
 * `/v1/chat/completions` 后面可能是 GPT-4o，也可能是一个纯文本的 7B 模型。
 * 所以匹配的是**模型名**，不是协议。
 *
 * 用户覆盖优先级最高：
 *
 * ```php
 * $registry->override('my-private-vl', ['input' => ['image' => true]]);
 * $registry->override('gemini-*', ['input' => ['image' => true, 'pdf' => true]]);
 * ```
 *
 * 注：不用类型化属性，保持 PHP 7.1 兼容（库的版本下限）。
 */
class CapabilityRegistry
{
    /**
     * 内置能力表
     *
     * 键是模型名或模式（`*` 通配）。值只写**确定**的项，没写的项保持未知。
     * 排在前面的先匹配，所以更具体的模式要放在更宽泛的前面。
     *
     * @var array<int, array{match: string, caps: array<string, mixed>}>
     */
    protected static $builtin = [
        // ===== OpenAI =====
        // gpt-4o / gpt-4.1 / o 系推理模型都支持图片；3.5 与 o1-mini 不支持
        ['match' => 'gpt-3.5-turbo*',  'caps' => ['input' => ['image' => false, 'pdf' => false]]],
        ['match' => 'gpt-4-turbo*',    'caps' => ['input' => ['image' => true,  'pdf' => false]]],
        ['match' => 'gpt-4-vision*',   'caps' => ['input' => ['image' => true,  'pdf' => false]]],
        ['match' => 'gpt-4o*',         'caps' => ['input' => ['image' => true,  'pdf' => false]]],
        ['match' => 'gpt-4.1*',        'caps' => ['input' => ['image' => true,  'pdf' => false]]],
        ['match' => 'gpt-5*',          'caps' => ['input' => ['image' => true,  'pdf' => false]]],
        ['match' => 'o1-mini*',        'caps' => ['input' => ['image' => false, 'pdf' => false]]],
        ['match' => 'o1*',             'caps' => ['input' => ['image' => true,  'pdf' => false]]],
        ['match' => 'o3*',             'caps' => ['input' => ['image' => true,  'pdf' => false]]],
        ['match' => 'o4-mini*',        'caps' => ['input' => ['image' => true,  'pdf' => false]]],

        // ===== Anthropic =====
        // Claude 3 起全系支持图片，且 tool_result 里可以带图片
        ['match' => 'claude-3*',       'caps' => [
            'input' => ['image' => true, 'pdf' => true], 'tool_result' => ['image' => true],
        ]],
        ['match' => 'claude-4*',       'caps' => [
            'input' => ['image' => true, 'pdf' => true], 'tool_result' => ['image' => true],
        ]],
        ['match' => 'claude-sonnet-*', 'caps' => [
            'input' => ['image' => true, 'pdf' => true], 'tool_result' => ['image' => true],
        ]],
        ['match' => 'claude-opus-*',   'caps' => [
            'input' => ['image' => true, 'pdf' => true], 'tool_result' => ['image' => true],
        ]],
        ['match' => 'claude-haiku-*',  'caps' => [
            'input' => ['image' => true, 'pdf' => true], 'tool_result' => ['image' => true],
        ]],

        // ===== Google Gemini =====
        ['match' => 'gemini-1.0*',     'caps' => ['input' => ['image' => true,  'pdf' => false]]],
        ['match' => 'gemini-*',        'caps' => ['input' => ['image' => true,  'pdf' => true]]],

        // ===== 通义千问 =====
        ['match' => 'qwen-vl*',        'caps' => ['input' => ['image' => true,  'pdf' => false]]],
        ['match' => 'qwen2-vl*',       'caps' => ['input' => ['image' => true,  'pdf' => false]]],
        ['match' => 'qwen2.5-vl*',     'caps' => ['input' => ['image' => true,  'pdf' => false]]],
        ['match' => 'qwen3-vl*',       'caps' => ['input' => ['image' => true,  'pdf' => false]]],
        ['match' => 'qwen-turbo*',     'caps' => ['input' => ['image' => false, 'pdf' => false]]],
        ['match' => 'qwen-plus*',      'caps' => ['input' => ['image' => false, 'pdf' => false]]],
        ['match' => 'qwen-max*',       'caps' => ['input' => ['image' => false, 'pdf' => false]]],

        // ===== 智谱 GLM =====
        ['match' => 'glm-4v*',         'caps' => ['input' => ['image' => true,  'pdf' => false]]],
        ['match' => 'glm-4.1v*',       'caps' => ['input' => ['image' => true,  'pdf' => false]]],

        // ===== 豆包 =====
        ['match' => 'doubao-*vision*', 'caps' => ['input' => ['image' => true,  'pdf' => false]]],

        // ===== DeepSeek（纯文本） =====
        ['match' => 'deepseek-chat*',     'caps' => ['input' => ['image' => false, 'pdf' => false]]],
        ['match' => 'deepseek-reasoner*', 'caps' => ['input' => ['image' => false, 'pdf' => false]]],
        ['match' => 'deepseek-v3*',       'caps' => ['input' => ['image' => false, 'pdf' => false]]],

        // ===== xAI Grok =====
        ['match' => 'grok-*vision*',   'caps' => ['input' => ['image' => true,  'pdf' => false]]],

        // ===== Mistral =====
        ['match' => 'pixtral*',        'caps' => ['input' => ['image' => true,  'pdf' => false]]],

        // ===== 月之暗面 =====
        ['match' => 'moonshot-*vision*', 'caps' => ['input' => ['image' => true,  'pdf' => false]]],
    ];

    /** @var array<int, array{match: string, caps: array<string, mixed>}> 用户覆盖，优先级最高 */
    protected $overrides = [];

    /** @var array<string, array<string, mixed>> 平台名 => 平台级声明 */
    protected $platforms = [];

    /**
     * @param array<string, mixed> $options overrides / platforms
     */
    public function __construct(array $options = [])
    {
        if (isset($options['overrides']) && is_array($options['overrides'])) {
            foreach ($options['overrides'] as $pattern => $caps) {
                if (is_array($caps)) {
                    $this->override((string) $pattern, $caps);
                }
            }
        }
        if (isset($options['platforms']) && is_array($options['platforms'])) {
            $this->setPlatforms($options['platforms']);
        }
    }

    /**
     * 覆盖某个模型（或模式）的能力
     *
     * @param string $pattern 精确模型名，或带 `*` 的模式
     * @param array<string, mixed> $caps 只写要覆盖的项，其余保持原判断
     * @return $this
     */
    public function override($pattern, array $caps)
    {
        // 后加的排前面：同一个模式被覆盖两次时，以最后一次为准
        array_unshift($this->overrides, ['match' => (string) $pattern, 'caps' => $caps]);
        return $this;
    }

    /**
     * 平台级声明（来自 `Agent::platforms()` 的 `modalities` 键）
     *
     * 模型名匹配不上时的兜底：用户明确说了「gemini 这个平台支持 vision」，
     * 那么这个平台下叫什么名字的模型都按支持算。
     *
     * @param array<string, array<string, mixed>> $configs 平台名 => 配置
     * @return $this
     */
    public function setPlatforms(array $configs)
    {
        foreach ($configs as $platform => $config) {
            if (!is_array($config)) {
                continue;
            }
            $caps = [];
            if (isset($config['modalities'])) {
                $caps = self::capsFromModalities($config['modalities']);
            }
            if (isset($config['capabilities']) && is_array($config['capabilities'])) {
                $caps = self::mergeCaps($caps, $config['capabilities']);
            }
            if ($caps !== []) {
                $this->platforms[strtolower((string) $platform)] = $caps;
            }
        }
        return $this;
    }

    /**
     * `['vision', 'pdf']` 这类简写 → 统一能力结构
     *
     * 声明了列表就意味着「列表之外的都不支持」——用户既然肯写这一行，
     * 说明他知道这个模型能干什么，不该再留成未知。
     *
     * @param mixed $modalities
     * @return array<string, mixed>
     */
    public static function capsFromModalities($modalities)
    {
        if (is_string($modalities)) {
            $modalities = [$modalities];
        }
        if (!is_array($modalities)) {
            return [];
        }
        $set = [];
        foreach ($modalities as $m) {
            $m = strtolower(trim((string) $m));
            // 'vision' 是常见叫法，等价于 image
            if ($m === 'vision') {
                $m = Modalities::IMAGE;
            }
            if (Modalities::isValid($m)) {
                $set[$m] = true;
            }
        }
        $input = [];
        foreach (Modalities::rich() as $m) {
            $input[$m] = isset($set[$m]);
        }
        return ['input' => $input];
    }

    /**
     * 查某个模型的能力
     *
     * @param string $model 模型名
     * @param string $platform 平台名（模型名匹配不上时的兜底）
     * @return array<string, mixed>|null 查不到返回 null（未知，不是「不支持」）
     */
    public function lookup($model, $platform = '')
    {
        $model = strtolower(trim((string) $model));
        if ($model === '') {
            return $this->platformCaps($platform);
        }

        foreach ($this->overrides as $entry) {
            if (self::matches($model, $entry['match'])) {
                return $entry['caps'];
            }
        }
        foreach (self::$builtin as $entry) {
            if (self::matches($model, $entry['match'])) {
                return $entry['caps'];
            }
        }
        return $this->platformCaps($platform);
    }

    /**
     * @param string $platform
     * @return array<string, mixed>|null
     */
    protected function platformCaps($platform)
    {
        $platform = strtolower(trim((string) $platform));
        return $platform !== '' && isset($this->platforms[$platform])
            ? $this->platforms[$platform]
            : null;
    }

    /**
     * 模型名是否匹配某个模式
     *
     * 支持三种写法：精确 `gpt-4o`、前缀 `gpt-4o*`、中缀 `doubao-*vision*`。
     * 用 fnmatch 会依赖平台差异（Windows 上行为不同），所以自己转正则。
     *
     * @param string $model
     * @param string $pattern
     * @return bool
     */
    public static function matches($model, $pattern)
    {
        // 自己归一化大小写：这是个 public static，不能指望调用方先转好
        $model   = strtolower(trim((string) $model));
        $pattern = strtolower(trim((string) $pattern));
        if ($pattern === '') {
            return false;
        }
        if (strpos($pattern, '*') === false) {
            return $model === $pattern;
        }
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
        return preg_match($regex, $model) === 1;
    }

    /**
     * 合并两份能力声明，后者优先
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overlay
     * @return array<string, mixed>
     */
    public static function mergeCaps(array $base, array $overlay)
    {
        foreach ($overlay as $group => $values) {
            if (!is_array($values)) {
                $base[$group] = $values;
                continue;
            }
            if (!isset($base[$group]) || !is_array($base[$group])) {
                $base[$group] = [];
            }
            foreach ($values as $k => $v) {
                $base[$group][$k] = $v;
            }
        }
        return $base;
    }

    /**
     * 内置表里登记的模式（供文档与调试）
     *
     * @return string[]
     */
    public static function knownPatterns()
    {
        $out = [];
        foreach (self::$builtin as $entry) {
            $out[] = $entry['match'];
        }
        return $out;
    }
}
