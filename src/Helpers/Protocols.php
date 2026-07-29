<?php
namespace Ai\Helpers;

use Ai\Exceptions\ConfigException;

/**
 * 协议注册表
 *
 * 负责三件事：
 *   1) 协议标识（openai / claude / gemini / deepseek，含别名）→ 协议类名
 *   2) 提供协议的默认接口根地址与路径（用于自定义模型自动组装端点）
 *   3) 由模型名称推断所属协议家族（gpt-* => openai、claude-* => claude ……）
 *
 * 业务层可用 all() 渲染「协议格式」下拉框，让用户手选协议后接入任意兼容接口。
 */
class Protocols
{
    /** 内置协议表：标识 => [类名, 显示名, 平台名] */
    protected static $map = [
        'openai' => [
            'class'    => 'Ai\\Protocol\\OpenAI',
            'label'    => 'OpenAI 兼容（Chat Completions）',
            'platform' => 'openai',
        ],
        'claude' => [
            'class'    => 'Ai\\Protocol\\Claude',
            'label'    => 'Claude / Anthropic（Messages）',
            'platform' => 'claude',
        ],
        'gemini' => [
            'class'    => 'Ai\\Protocol\\Gemini',
            'label'    => 'Gemini（OpenAI 兼容端点）',
            'platform' => 'gemini',
        ],
        'deepseek' => [
            'class'    => 'Ai\\Protocol\\DeepSeek',
            'label'    => 'DeepSeek（OpenAI 兼容）',
            'platform' => 'deepseek',
        ],
        'openrouter' => [
            'class'    => 'Ai\\Protocol\\OpenRouter',
            'label'    => 'OpenRouter（聚合中转）',
            'platform' => 'openrouter',
        ],
    ];

    /** 协议标识别名 */
    protected static $alias = [
        'anthropic'         => 'claude',
        'claude-messages'   => 'claude',
        'messages'          => 'claude',
        'oai'               => 'openai',
        'openai-compatible' => 'openai',
        'compatible'        => 'openai',
        'chat_completions'  => 'openai',
        'chat-completions'  => 'openai',
        'google'            => 'gemini',
        'or'                => 'openrouter',
        'open-router'       => 'openrouter',
        'open_router'       => 'openrouter',
    ];

    /**
     * 由模型名称推断协议标识的正则（顺序敏感）
     * 命中后同时决定「未配置接口地址时」使用哪个官方地址
     */
    protected static $detect = [
        'deepseek' => '/^deepseek/i',
        'claude'   => '/^(claude|anthropic)/i',
        'gemini'   => '/^(gemini|models\/gemini)/i',
        'openai'   => '/^(gpt|chatgpt|o[1-9]|text-davinci|dall-e|omni)/i',
    ];

    /**
     * 列举内置协议（用于后台下拉框）
     * @return array ['openai' => 'OpenAI 兼容（Chat Completions）', ...]
     */
    public static function all(): array
    {
        $list = [];
        foreach (self::$map as $key => $item) {
            $list[$key] = $item['label'];
        }
        return $list;
    }

    /**
     * 归一化协议标识（处理别名与大小写），未知返回 null
     */
    public static function normalize(string $protocol): ?string
    {
        $key = strtolower(trim($protocol));
        if ($key === '') {
            return null;
        }
        if (isset(self::$alias[$key])) {
            $key = self::$alias[$key];
        }
        return isset(self::$map[$key]) ? $key : null;
    }

    /**
     * 协议标识（或完整类名）→ 协议类名
     *
     * @param string $protocol 'openai'、'anthropic'、或 'App\\Protocol\\MyApi' 这样的完整类名
     * @throws ConfigException 协议不存在或类未实现 ProtocolInterface
     */
    public static function resolveClass(string $protocol): string
    {
        $key = self::normalize($protocol);
        if ($key !== null) {
            return self::$map[$key]['class'];
        }

        // 允许直接传入自定义协议类名（便于业务层扩展私有协议）
        $class = ltrim(trim($protocol), '\\');
        if ($class !== '' && class_exists($class)) {
            if (!is_subclass_of($class, 'Ai\\Contracts\\ProtocolInterface')) {
                throw new ConfigException("Protocol class must implement Ai\\Contracts\\ProtocolInterface: {$class}");
            }
            return $class;
        }

        throw new ConfigException(
            "Unknown protocol: {$protocol}. Available: " . implode(', ', array_keys(self::$map))
        );
    }

    /**
     * 协议类名 → 协议标识（自定义类返回 null）
     */
    public static function keyOfClass(string $class): ?string
    {
        $class = ltrim($class, '\\');
        foreach (self::$map as $key => $item) {
            if (strcasecmp($item['class'], $class) === 0) {
                return $key;
            }
        }
        return null;
    }

    /**
     * 协议对应的默认平台名
     */
    public static function platformOf(string $protocol): string
    {
        $key = self::normalize($protocol) ?? self::keyOfClass($protocol);
        return $key !== null ? self::$map[$key]['platform'] : 'custom';
    }

    /**
     * 由模型名称推断协议标识，无法推断返回 null
     *
     * @param string $modelName 如 'gpt-4o'、'claude-sonnet-4-5'、'deepseek-chat'
     */
    public static function detect(string $modelName): ?string
    {
        $name = trim($modelName);
        if ($name === '') {
            return null;
        }
        // 网关常见的「厂商/模型」写法，如 openai/gpt-4o、anthropic/claude-3-5-sonnet
        if (strpos($name, '/') !== false) {
            $vendor = strtolower(substr($name, 0, strpos($name, '/')));
            $key    = self::normalize($vendor);
            if ($key !== null) {
                return $key;
            }
            $name = substr($name, strpos($name, '/') + 1);
        }
        foreach (self::$detect as $key => $pattern) {
            if (preg_match($pattern, $name)) {
                return $key;
            }
        }
        return null;
    }

    /**
     * 协议的默认接口根地址（官方地址）
     * @param string $protocol 协议标识或类名
     * @return string 无法确定时返回空串
     */
    public static function baseUrlOf(string $protocol): string
    {
        return self::callProtocol($protocol, 'defaultBaseUrl', '');
    }

    /**
     * 协议的对话路径，如 '/v1/chat/completions'
     */
    public static function chatPathOf(string $protocol): string
    {
        return self::callProtocol($protocol, 'chatPath', '/v1/chat/completions');
    }

    /**
     * 协议的模型列表路径，如 '/v1/models'
     */
    public static function modelsPathOf(string $protocol): string
    {
        return self::callProtocol($protocol, 'modelsPath', '/v1/models');
    }

    /**
     * 组装协议的默认对话端点
     *
     * @param string $protocol 协议标识或类名
     * @param string $baseUrl  接口根地址，留空则用协议官方地址
     * @return string 无法确定根地址时返回空串
     */
    public static function endpointOf(string $protocol, string $baseUrl = ''): string
    {
        $base = trim($baseUrl) !== '' ? trim($baseUrl) : self::baseUrlOf($protocol);
        if ($base === '') {
            return '';
        }
        return Endpoint::join($base, self::chatPathOf($protocol));
    }

    /**
     * 调用协议类上的可选元信息方法（自定义协议未实现时用默认值兜底）
     */
    protected static function callProtocol(string $protocol, string $method, string $fallback): string
    {
        try {
            $class = self::resolveClass($protocol);
        } catch (ConfigException $e) {
            return $fallback;
        }
        if (!method_exists($class, $method)) {
            return $fallback;
        }
        $value = (new $class())->{$method}();
        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
