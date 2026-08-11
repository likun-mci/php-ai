<?php
namespace Ai\Helpers;

use Ai\Exceptions\ConfigException;

/**
 * 协议注册表
 *
 * 收录国内外主流 AI 平台，让用户用同一套接口访问不同厂商。负责四件事：
 *   1) 协议标识（openai / claude / qwen / doubao / glm ……，含别名）→ 协议类名
 *   2) 提供协议的默认接口根地址与路径（用于自定义模型自动组装端点）
 *   3) 由模型名称推断所属协议家族（gpt-* => openai、qwen-* => qwen、glm-* => zhipu ……）
 *   4) 提供平台清单与各平台常用模型清单，供后台下拉框离线渲染
 *
 * 业务层可用 all() / grouped() 渲染「协议格式」下拉框，让用户手选协议后接入任意兼容接口；
 * 绝大多数平台都是 OpenAI Chat Completions 兼容格式，差别只在接口地址与鉴权头。
 *
 * 分组（group）取值：
 *   cn         中国大陆主流平台
 *   global     海外主流平台
 *   aggregator 聚合中转平台（一个 Key 访问多家模型）
 *   local      本地/自建推理服务
 */
class Protocols
{
    /**
     * 协议表：标识 => [类名, 显示名, 平台显示名, 平台键, 分组, 文档地址]
     * @var array<string, array{class: class-string<\Ai\Contracts\ProtocolInterface>, label: string, vendor: string, platform: string, group: string, docs: string}>
     */
    protected static $map = [
        'openai' => [
            'class'    => 'Ai\\Protocol\\OpenAI',
            'label'    => 'OpenAI 兼容（Chat Completions）',
            'vendor'   => 'OpenAI',
            'platform' => 'openai',
            'group'    => 'global',
            'docs'     => 'https://platform.openai.com/docs/api-reference/chat',
        ],
        'claude' => [
            'class'    => 'Ai\\Protocol\\Claude',
            'label'    => 'Claude / Anthropic（Messages）',
            'vendor'   => 'Anthropic Claude',
            'platform' => 'claude',
            'group'    => 'global',
            'docs'     => 'https://platform.claude.com/docs/en/api/messages',
        ],
        'gemini' => [
            'class'    => 'Ai\\Protocol\\Gemini',
            'label'    => 'Gemini（OpenAI 兼容端点）',
            'vendor'   => 'Google Gemini',
            'platform' => 'gemini',
            'group'    => 'global',
            'docs'     => 'https://ai.google.dev/gemini-api/docs/openai',
        ],
        'deepseek' => [
            'class'    => 'Ai\\Protocol\\DeepSeek',
            'label'    => 'DeepSeek 深度求索（OpenAI 兼容）',
            'vendor'   => 'DeepSeek 深度求索',
            'platform' => 'deepseek',
            'group'    => 'cn',
            'docs'     => 'https://api-docs.deepseek.com/',
        ],
        'qwen' => [
            'class'    => 'Ai\\Protocol\\Qwen',
            'label'    => '阿里云百炼 / 通义千问（OpenAI 兼容）',
            'vendor'   => '阿里云百炼（通义千问）',
            'platform' => 'qwen',
            'group'    => 'cn',
            'docs'     => 'https://help.aliyun.com/zh/model-studio/compatibility-of-openai-with-dashscope',
        ],
        'qwen-anthropic' => [
            'class'    => 'Ai\\Protocol\\QwenAnthropic',
            'label'    => '阿里云百炼（Anthropic 兼容，支持工具调用）',
            'vendor'   => '阿里云百炼（通义千问）',
            'platform' => 'qwen',
            'group'    => 'cn',
            'docs'     => 'https://help.aliyun.com/zh/model-studio/claude-code',
        ],
        'doubao' => [
            'class'    => 'Ai\\Protocol\\Doubao',
            'label'    => '火山方舟 / 豆包（OpenAI 兼容）',
            'vendor'   => '火山方舟（豆包）',
            'platform' => 'doubao',
            'group'    => 'cn',
            'docs'     => 'https://www.volcengine.com/docs/82379/1330626',
        ],
        'ernie' => [
            'class'    => 'Ai\\Protocol\\Ernie',
            'label'    => '百度千帆 / 文心一言（OpenAI 兼容）',
            'vendor'   => '百度千帆（文心一言）',
            'platform' => 'ernie',
            'group'    => 'cn',
            'docs'     => 'https://cloud.baidu.com/doc/qianfan-api/s/Fm2vrveyu',
        ],
        'zhipu' => [
            'class'    => 'Ai\\Protocol\\Zhipu',
            'label'    => '智谱 GLM（OpenAI 兼容）',
            'vendor'   => '智谱 AI（GLM）',
            'platform' => 'zhipu',
            'group'    => 'cn',
            'docs'     => 'https://docs.bigmodel.cn/',
        ],
        'zhipu-anthropic' => [
            'class'    => 'Ai\\Protocol\\ZhipuAnthropic',
            'label'    => '智谱 GLM（Anthropic 兼容，支持工具调用）',
            'vendor'   => '智谱 AI（GLM）',
            'platform' => 'zhipu',
            'group'    => 'cn',
            'docs'     => 'https://docs.bigmodel.cn/cn/guide/develop/claude',
        ],
        'moonshot' => [
            'class'    => 'Ai\\Protocol\\Moonshot',
            'label'    => '月之暗面 Kimi（OpenAI 兼容）',
            'vendor'   => '月之暗面（Kimi）',
            'platform' => 'moonshot',
            'group'    => 'cn',
            'docs'     => 'https://platform.moonshot.cn/docs/api/chat',
        ],
        'moonshot-anthropic' => [
            'class'    => 'Ai\\Protocol\\MoonshotAnthropic',
            'label'    => '月之暗面 Kimi（Anthropic 兼容，支持工具调用）',
            'vendor'   => '月之暗面（Kimi）',
            'platform' => 'moonshot',
            'group'    => 'cn',
            'docs'     => 'https://platform.moonshot.cn/docs/guide/agent-support',
        ],
        'hunyuan' => [
            'class'    => 'Ai\\Protocol\\Hunyuan',
            'label'    => '腾讯混元（OpenAI 兼容）',
            'vendor'   => '腾讯混元',
            'platform' => 'hunyuan',
            'group'    => 'cn',
            'docs'     => 'https://cloud.tencent.com/document/product/1729/111007',
        ],
        'spark' => [
            'class'    => 'Ai\\Protocol\\Spark',
            'label'    => '讯飞星火（OpenAI 兼容）',
            'vendor'   => '讯飞星火',
            'platform' => 'spark',
            'group'    => 'cn',
            'docs'     => 'https://www.xfyun.cn/doc/spark/HTTP%E8%B0%83%E7%94%A8%E6%96%87%E6%A1%A3.html',
        ],
        'minimax' => [
            'class'    => 'Ai\\Protocol\\MiniMax',
            'label'    => 'MiniMax 稀宇（OpenAI 兼容）',
            'vendor'   => 'MiniMax（稀宇科技）',
            'platform' => 'minimax',
            'group'    => 'cn',
            'docs'     => 'https://platform.minimaxi.com/document/ChatCompletion',
        ],
        'stepfun' => [
            'class'    => 'Ai\\Protocol\\StepFun',
            'label'    => '阶跃星辰 Step（OpenAI 兼容）',
            'vendor'   => '阶跃星辰（Step）',
            'platform' => 'stepfun',
            'group'    => 'cn',
            'docs'     => 'https://platform.stepfun.com/docs/api-reference/chat',
        ],
        'yi' => [
            'class'    => 'Ai\\Protocol\\Yi',
            'label'    => '零一万物 Yi（OpenAI 兼容）',
            'vendor'   => '零一万物（Yi）',
            'platform' => 'yi',
            'group'    => 'cn',
            'docs'     => 'https://platform.lingyiwanwu.com/docs',
        ],
        'baichuan' => [
            'class'    => 'Ai\\Protocol\\Baichuan',
            'label'    => '百川智能（OpenAI 兼容）',
            'vendor'   => '百川智能',
            'platform' => 'baichuan',
            'group'    => 'cn',
            'docs'     => 'https://platform.baichuan-ai.com/docs/api',
        ],
        'sensenova' => [
            'class'    => 'Ai\\Protocol\\SenseNova',
            'label'    => '商汤日日新（OpenAI 兼容）',
            'vendor'   => '商汤日日新（SenseNova）',
            'platform' => 'sensenova',
            'group'    => 'cn',
            'docs'     => 'https://console.sensecore.cn/help/docs/model-as-a-service/nova/',
        ],
        'zhinao' => [
            'class'    => 'Ai\\Protocol\\Zhinao',
            'label'    => '360 智脑（OpenAI 兼容）',
            'vendor'   => '360 智脑',
            'platform' => 'zhinao',
            'group'    => 'cn',
            'docs'     => 'https://ai.360.com/open/docs/',
        ],
        'modelarts' => [
            'class'    => 'Ai\\Protocol\\ModelArts',
            'label'    => '华为云 ModelArts MaaS（OpenAI 兼容）',
            'vendor'   => '华为云 ModelArts（盘古 / MaaS）',
            'platform' => 'modelarts',
            'group'    => 'cn',
            'docs'     => 'https://support.huaweicloud.com/api-modelarts-maas/',
        ],
        'zai' => [
            'class'    => 'Ai\\Protocol\\ZAI',
            'label'    => 'Z.ai（智谱国际站，OpenAI 兼容）',
            'vendor'   => 'Z.ai（智谱国际站）',
            'platform' => 'zai',
            'group'    => 'global',
            'docs'     => 'https://docs.z.ai/',
        ],
        'grok' => [
            'class'    => 'Ai\\Protocol\\Grok',
            'label'    => 'xAI Grok（OpenAI 兼容）',
            'vendor'   => 'xAI（Grok）',
            'platform' => 'grok',
            'group'    => 'global',
            'docs'     => 'https://docs.x.ai/docs/api-reference',
        ],
        'mistral' => [
            'class'    => 'Ai\\Protocol\\Mistral',
            'label'    => 'Mistral AI（OpenAI 兼容）',
            'vendor'   => 'Mistral AI',
            'platform' => 'mistral',
            'group'    => 'global',
            'docs'     => 'https://docs.mistral.ai/api/',
        ],
        'llama' => [
            'class'    => 'Ai\\Protocol\\Llama',
            'label'    => 'Meta Llama API（OpenAI 兼容）',
            'vendor'   => 'Meta（Llama API）',
            'platform' => 'llama',
            'group'    => 'global',
            'docs'     => 'https://llama.developer.meta.com/docs/',
        ],
        'cohere' => [
            'class'    => 'Ai\\Protocol\\Cohere',
            'label'    => 'Cohere（OpenAI 兼容）',
            'vendor'   => 'Cohere',
            'platform' => 'cohere',
            'group'    => 'global',
            'docs'     => 'https://docs.cohere.com/docs/compatibility-api',
        ],
        'perplexity' => [
            'class'    => 'Ai\\Protocol\\Perplexity',
            'label'    => 'Perplexity Sonar（联网搜索，OpenAI 兼容）',
            'vendor'   => 'Perplexity',
            'platform' => 'perplexity',
            'group'    => 'global',
            'docs'     => 'https://docs.perplexity.ai/api-reference/chat-completions-post',
        ],
        'azure' => [
            'class'    => 'Ai\\Protocol\\Azure',
            'label'    => 'Azure OpenAI（需自填资源地址）',
            'vendor'   => 'Azure OpenAI',
            'platform' => 'azure',
            'group'    => 'global',
            'docs'     => 'https://learn.microsoft.com/azure/ai-foundry/openai/reference',
        ],
        'openrouter' => [
            'class'    => 'Ai\\Protocol\\OpenRouter',
            'label'    => 'OpenRouter（聚合中转）',
            'vendor'   => 'OpenRouter',
            'platform' => 'openrouter',
            'group'    => 'aggregator',
            'docs'     => 'https://openrouter.ai/docs',
        ],
        'siliconflow' => [
            'class'    => 'Ai\\Protocol\\SiliconFlow',
            'label'    => '硅基流动 SiliconCloud（聚合，OpenAI 兼容）',
            'vendor'   => '硅基流动（SiliconCloud）',
            'platform' => 'siliconflow',
            'group'    => 'aggregator',
            'docs'     => 'https://docs.siliconflow.cn/cn/api-reference/chat-completions/chat-completions',
        ],
        'modelscope' => [
            'class'    => 'Ai\\Protocol\\ModelScope',
            'label'    => '魔搭 ModelScope（聚合，OpenAI 兼容）',
            'vendor'   => '魔搭社区（ModelScope）',
            'platform' => 'modelscope',
            'group'    => 'aggregator',
            'docs'     => 'https://modelscope.cn/docs/model-service/API-Inference/intro',
        ],
        'groq' => [
            'class'    => 'Ai\\Protocol\\Groq',
            'label'    => 'Groq（高速推理，OpenAI 兼容）',
            'vendor'   => 'Groq',
            'platform' => 'groq',
            'group'    => 'aggregator',
            'docs'     => 'https://console.groq.com/docs/openai',
        ],
        'together' => [
            'class'    => 'Ai\\Protocol\\Together',
            'label'    => 'Together AI（聚合，OpenAI 兼容）',
            'vendor'   => 'Together AI',
            'platform' => 'together',
            'group'    => 'aggregator',
            'docs'     => 'https://docs.together.ai/reference/chat-completions-1',
        ],
        'fireworks' => [
            'class'    => 'Ai\\Protocol\\Fireworks',
            'label'    => 'Fireworks AI（聚合，OpenAI 兼容）',
            'vendor'   => 'Fireworks AI',
            'platform' => 'fireworks',
            'group'    => 'aggregator',
            'docs'     => 'https://docs.fireworks.ai/api-reference/post-chatcompletions',
        ],
        'deepinfra' => [
            'class'    => 'Ai\\Protocol\\DeepInfra',
            'label'    => 'DeepInfra（聚合，OpenAI 兼容）',
            'vendor'   => 'DeepInfra',
            'platform' => 'deepinfra',
            'group'    => 'aggregator',
            'docs'     => 'https://deepinfra.com/docs/openai_api',
        ],
        'cerebras' => [
            'class'    => 'Ai\\Protocol\\Cerebras',
            'label'    => 'Cerebras（高速推理，OpenAI 兼容）',
            'vendor'   => 'Cerebras',
            'platform' => 'cerebras',
            'group'    => 'aggregator',
            'docs'     => 'https://inference-docs.cerebras.ai/api-reference/chat-completions',
        ],
        'nvidia' => [
            'class'    => 'Ai\\Protocol\\Nvidia',
            'label'    => 'NVIDIA NIM（OpenAI 兼容）',
            'vendor'   => 'NVIDIA NIM',
            'platform' => 'nvidia',
            'group'    => 'aggregator',
            'docs'     => 'https://docs.api.nvidia.com/nim/reference/llm-apis',
        ],
        'ollama' => [
            'class'    => 'Ai\\Protocol\\Ollama',
            'label'    => 'Ollama（本地，OpenAI 兼容）',
            'vendor'   => 'Ollama（本地）',
            'platform' => 'ollama',
            'group'    => 'local',
            'docs'     => 'https://github.com/ollama/ollama/blob/main/docs/openai.md',
        ],
        'lmstudio' => [
            'class'    => 'Ai\\Protocol\\LMStudio',
            'label'    => 'LM Studio（本地，OpenAI 兼容）',
            'vendor'   => 'LM Studio（本地）',
            'platform' => 'lmstudio',
            'group'    => 'local',
            'docs'     => 'https://lmstudio.ai/docs/app/api/endpoints/openai',
        ],
        'vllm' => [
            'class'    => 'Ai\\Protocol\\VLLM',
            'label'    => 'vLLM（自建推理服务，OpenAI 兼容）',
            'vendor'   => 'vLLM（自建）',
            'platform' => 'vllm',
            'group'    => 'local',
            'docs'     => 'https://docs.vllm.ai/en/latest/serving/openai_compatible_server.html',
        ],
    ];

    /**
     * 协议标识别名
     *
     * 键类型写成 string|int 是因为 PHP 会把纯数字的字符串键静默转成 int
     * （如 '360' => 360）。查表时 isset(\$alias['360']) 同样会被转换，
     * 因此功能不受影响，但类型上必须如实标注。
     * @var array<string|int, string>
     */
    protected static $alias = [
        'oai'                 => 'openai',
        'openai-compatible'   => 'openai',
        'compatible'          => 'openai',
        'chat_completions'    => 'openai',
        'chat-completions'    => 'openai',
        'anthropic'           => 'claude',
        'claude-messages'     => 'claude',
        'messages'            => 'claude',
        'google'              => 'gemini',
        'deepseek-ai'         => 'deepseek',
        'dashscope'           => 'qwen',
        'bailian'             => 'qwen',
        'tongyi'              => 'qwen',
        'aliyun'              => 'qwen',
        'alibaba'             => 'qwen',
        'ali'                 => 'qwen',
        'qwen-claude'         => 'qwen-anthropic',
        'dashscope-anthropic' => 'qwen-anthropic',
        'ark'                 => 'doubao',
        'volcengine'          => 'doubao',
        'volces'              => 'doubao',
        'volc'                => 'doubao',
        'bytedance'           => 'doubao',
        'huoshan'             => 'doubao',
        'qianfan'             => 'ernie',
        'baidu'               => 'ernie',
        'wenxin'              => 'ernie',
        'yiyan'               => 'ernie',
        'glm'                 => 'zhipu',
        'bigmodel'            => 'zhipu',
        'chatglm'             => 'zhipu',
        'zhipuai'             => 'zhipu',
        'glm-anthropic'       => 'zhipu-anthropic',
        'zhipu-claude'        => 'zhipu-anthropic',
        'kimi'                => 'moonshot',
        'yuezhianmian'        => 'moonshot',
        'kimi-anthropic'      => 'moonshot-anthropic',
        'moonshot-claude'     => 'moonshot-anthropic',
        'tencent'             => 'hunyuan',
        'tencent-hunyuan'     => 'hunyuan',
        'xunfei'              => 'spark',
        'iflytek'             => 'spark',
        'xfyun'               => 'spark',
        'xinghuo'             => 'spark',
        'xiyu'                => 'minimax',
        'minimaxi'            => 'minimax',
        'step'                => 'stepfun',
        'jieyue'              => 'stepfun',
        'lingyiwanwu'         => 'yi',
        '01ai'                => 'yi',
        '01-ai'               => 'yi',
        'zeroone'             => 'yi',
        'baichuan-inc'        => 'baichuan',
        'sensetime'           => 'sensenova',
        'sensechat'           => 'sensenova',
        'shangtang'           => 'sensenova',
        '360'                 => 'zhinao',
        'qihoo'               => 'zhinao',
        '360ai'               => 'zhinao',
        'huawei'              => 'modelarts',
        'maas'                => 'modelarts',
        'pangu'               => 'modelarts',
        'z-ai'                => 'zai',
        'zhipu-global'        => 'zai',
        'xai'                 => 'grok',
        'x-ai'                => 'grok',
        'x.ai'                => 'grok',
        'mistralai'           => 'mistral',
        'meta'                => 'llama',
        'meta-llama'          => 'llama',
        'command'             => 'cohere',
        'pplx'                => 'perplexity',
        'sonar'               => 'perplexity',
        'azure-openai'        => 'azure',
        'azureopenai'         => 'azure',
        'or'                  => 'openrouter',
        'open-router'         => 'openrouter',
        'open_router'         => 'openrouter',
        'silicon'             => 'siliconflow',
        'siliconcloud'        => 'siliconflow',
        'guiji'               => 'siliconflow',
        'moda'                => 'modelscope',
        'damo'                => 'modelscope',
        'groqcloud'           => 'groq',
        'togetherai'          => 'together',
        'together-ai'         => 'together',
        'fireworksai'         => 'fireworks',
        'nim'                 => 'nvidia',
        'nvidia-nim'          => 'nvidia',
        'build-nvidia'        => 'nvidia',
        'lm-studio'           => 'lmstudio',
        'sglang'              => 'vllm',
        'xinference'          => 'vllm',
    ];

    /**
     * 由模型名称推断协议标识的正则（顺序敏感）
     * 命中后同时决定「未配置接口地址时」使用哪个官方地址
     * @var array<string, string>
     */
    protected static $detect = [
        'openai'     => '/^(gpt|chatgpt|o[1-9]|text-davinci|dall-e|omni)/i',
        'claude'     => '/^(claude|anthropic)/i',
        'gemini'     => '/^(gemini|models\\/gemini)/i',
        'deepseek'   => '/^deepseek/i',
        'qwen'       => '/^(qwen|qwq|qvq|tongyi|wanx)/i',
        'doubao'     => '/^(doubao|skylark)/i',
        'ernie'      => '/^(ernie|wenxin)/i',
        'zhipu'      => '/^(glm|chatglm|cogview|cogvideo|codegeex)/i',
        'moonshot'   => '/^(moonshot|kimi)/i',
        'hunyuan'    => '/^hunyuan/i',
        'spark'      => '/^(spark|generalv|4\\.0ultra)/i',
        'minimax'    => '/^(minimax|abab)/i',
        'stepfun'    => '/^step-/i',
        'yi'         => '/^yi-/i',
        'baichuan'   => '/^baichuan/i',
        'sensenova'  => '/^sensechat/i',
        'zhinao'     => '/^360gpt/i',
        'grok'       => '/^grok/i',
        'mistral'    => '/^(mistral|codestral|magistral|ministral|pixtral|devstral|open-mistral|open-mixtral)/i',
        'cohere'     => '/^command/i',
        'perplexity' => '/^sonar/i',
    ];

    /**
     * 列举内置协议（用于后台下拉框）
     * @return array<mixed> ['openai' => 'OpenAI 兼容（Chat Completions）', ...]
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
     * @return class-string<\Ai\Contracts\ProtocolInterface>
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
     * 协议某项扩展能力的接口相对路径
     *
     * @param string $protocol   协议标识或类名
     * @param string $capability 能力标识，见 Capabilities
     * @return string 不支持该能力时返回空串
     */
    public static function capabilityPathOf(string $protocol, string $capability): string
    {
        try {
            $class = self::resolveClass($protocol);
        } catch (ConfigException $e) {
            return '';
        }
        if (!method_exists($class, 'capabilityPath')) {
            return '';
        }
        $value = (new $class())->capabilityPath($capability);
        return is_string($value) ? $value : '';
    }

    /**
     * 协议支持的扩展能力清单
     *
     * @param string $protocol 协议标识或类名
     * @return array<int, string>
     */
    public static function capabilitiesOf(string $protocol): array
    {
        try {
            $class = self::resolveClass($protocol);
        } catch (ConfigException $e) {
            return [];
        }
        if (!method_exists($class, 'capabilities')) {
            return [];
        }
        $value = (new $class())->capabilities();
        return is_array($value) ? $value : [];
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
     * 按分组列举内置协议（用于后台下拉框分组渲染）
     *
     * @return array<mixed> ['中国大陆' => ['deepseek' => 'DeepSeek 深度求索（OpenAI 兼容）', ...], ...]
     */
    public static function grouped(): array
    {
        $titles = [
            'cn'         => '中国大陆',
            'global'     => '海外主流',
            'aggregator' => '聚合中转',
            'local'      => '本地部署',
        ];
        $list = [];
        foreach ($titles as $group => $title) {
            foreach (self::$map as $key => $item) {
                if (($item['group'] ?? '') === $group) {
                    $list[$title][$key] = $item['label'];
                }
            }
        }
        return $list;
    }

    /**
     * 列举所有平台（用于「平台」下拉框，以及按 {平台}__api_key 取密钥）
     *
     * 多个协议共用一个平台时（如 zhipu 与 zhipu-anthropic）只出现一次。
     *
     * @return array<mixed> ['deepseek' => 'DeepSeek 深度求索', 'qwen' => '阿里云百炼（通义千问）', ...]
     */
    public static function platforms(): array
    {
        $list = [];
        foreach (self::$map as $item) {
            $platform = $item['platform'];
            if (!isset($list[$platform])) {
                $list[$platform] = $item['vendor'];
            }
        }
        return $list;
    }

    /**
     * 协议对应的平台显示名
     */
    public static function vendorOf(string $protocol): string
    {
        $key = self::normalize($protocol) ?? self::keyOfClass($protocol);
        return $key !== null ? self::$map[$key]['vendor'] : 'Custom';
    }

    /**
     * 协议所属分组：cn / global / aggregator / local，未知返回 custom
     */
    public static function groupOf(string $protocol): string
    {
        $key = self::normalize($protocol) ?? self::keyOfClass($protocol);
        return $key !== null ? self::$map[$key]['group'] : 'custom';
    }

    /**
     * 协议的官方文档地址，未知返回空串
     */
    public static function docsOf(string $protocol): string
    {
        $key = self::normalize($protocol) ?? self::keyOfClass($protocol);
        return $key !== null ? (string)(self::$map[$key]['docs'] ?? '') : '';
    }

    /**
     * 某平台键对应的协议标识列表
     *
     * 例：platformProtocols('zhipu') => ['zhipu', 'zhipu-anthropic']
     *
     * @return array<mixed> 协议标识数组
     */
    public static function platformProtocols(string $platform): array
    {
        $platform = strtolower(trim($platform));
        $keys = [];
        foreach (self::$map as $key => $item) {
            if (strcasecmp($item['platform'], $platform) === 0) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * 协议内置的常用模型清单（不发请求，供后台离线渲染下拉框）
     *
     * @param string $protocol 协议标识或协议类名
     * @return array<mixed> ['模型 id' => '显示名']，协议未提供清单时返回空数组
     */
    public static function modelsOf(string $protocol): array
    {
        try {
            $class = self::resolveClass($protocol);
        } catch (ConfigException $e) {
            return [];
        }
        if (!method_exists($class, 'knownModels')) {
            return [];
        }
        $models = (new $class())->knownModels();
        return is_array($models) ? $models : [];
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
