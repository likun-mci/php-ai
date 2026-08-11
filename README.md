# PHP AI 标准库

一个框架无关的 PHP AI 调用标准库，用统一接口屏蔽 **40 个国内外主流 AI 平台**在**鉴权方式、请求协议、返回格式、流式协议**上的差异——国内的通义千问、豆包、文心一言、智谱 GLM、Kimi、腾讯混元、讯飞星火、DeepSeek……，海外的 OpenAI、Claude、Gemini、Grok、Mistral……，以及 OpenRouter、硅基流动等聚合中转与 Ollama 等本地部署。切换平台只需换一个配置项。

除对话能力外，还内置了 **Agent 工具调用循环**、**AI 代码编辑协议**、**带 SSRF 防护的网页抓取工具**与 **Agent 长期记忆**，可直接用于构建后台 AI 助手、代码编辑助手、内容翻译等实际业务。

本文档中的示例均来自真实业务系统（一套 CodeIgniter 3 的建站系统）中的实际用法，非伪代码。

---

## 特性

- 🔌 **多平台**：内置 40 个平台协议——国内 20 家（通义千问 / 豆包 / 文心一言 / 智谱 GLM / Kimi / 混元 / 星火 / MiniMax / 阶跃星辰 / 零一万物 / 百川 / 商汤 / 360 智脑 / 华为云 MaaS / DeepSeek 等）、海外 12 家（OpenAI / Claude / Gemini / Grok / Mistral / Cohere / Perplexity / Meta Llama / Azure OpenAI 等）、聚合中转 8 家（OpenRouter / 硅基流动 / 魔搭 / Groq / Together / Fireworks / DeepInfra / NVIDIA NIM 等）与本地部署（Ollama / LM Studio / vLLM）
- 🎯 **统一接口**：`AI::create()->chat()`，切换平台只需换 `protocol` 或 `model`，业务代码一行不用改
- 🧰 **工具调用全平台统一**：一套工具定义跑 40 个协议，库自动在 OpenAI 的 `tool_calls` 与 Anthropic 的 `tool_use` 块之间翻译，Agent 换平台只改一行配置
- 🛡️ **生产级健壮性**：429 限流与 5xx 自动退避重试（尊重 `Retry-After`）、请求体编码失败快速失败、SSRF 纵深防护
- ⚡ **并发批量**：`chatBatch()` 用 `curl_multi` 并发跑批，实测 10 条提速 3.3 倍，单条失败不拖垮整批
- 🧩 **任意模型 + 任意接口**：模型名不受内置清单限制，可手选协议格式（`protocol`）并指定自定义接口地址（`base_url` / `endpoint`），一套代码同时对接官方 API、第三方中转与自建网关
- 🌊 **流式输出**：一行 `setStream(true)`，自动按 SSE 协议实时吐出数据块；常驻内存框架用 `setStreamCallback()` 接管分片
- 🧰 **Agent 循环**：挂载工具（函数）后自动完成「模型决策 → 执行工具 → 回填结果」多轮循环
- 🤖 **Claude Code CLI**：直接调用本机 claude 程序（`Ai\Cli\ClaudeCode`），文件读写 / 工具执行 / 会话续接 / 结构化输出，路径自动检测并缓存
- 📊 **CLI 信息查询**：不发起对话即可读取版本、登录态、模型列表、额度用量与限流、生效设置、MCP 状态
- 🔌 **常驻双工会话**：`Ai\Cli\ClaudeCodeSession` 复刻官方 IDE 插件的进程模式，长驻进程多轮对话 + 工具权限实时回调 PHP 决策 + 优雅中断
- 📝 **代码编辑协议**：结构化编辑上下文 + 可校验的编辑动作，支持规划/审核/自动执行三种模式
- 🛡️ **安全抓取**：`HttpFetch` 内置 SSRF、DNS rebinding、内网地址、协议逃逸防护
- 📄 **多模态附件**：图片等附件按各平台格式自动适配
- 🪶 **零硬依赖**：仅需 PHP 与 cURL，可 Composer 安装，也可单文件 autoload 引入

---

## 环境要求

| 项目 | 要求 |
|------|------|
| PHP | >= 7.2 |
| 扩展 | `ext-curl`、`ext-json`、`ext-mbstring`、`ext-dom`（仅 HTML 转换用到） |
| 网络 | 可访问对应平台 API；中国环境通常需配置代理，见 [网络代理](#网络代理) |

---

## 安装

### Composer

```bash
composer require likun-mci/php-ai
```

```php
require __DIR__ . '/vendor/autoload.php';

use Ai\AI;
```

### 手动引入（不使用 Composer）

库自带 PSR-4 加载器，把整个目录放进项目后引入一次即可：

```php
require_once __DIR__ . '/autoload.php';

use Ai\AI;
```

---

## 快速开始

```php
use Ai\AI;

$ai = AI::create([
    'model'   => 'gpt-4o',
    'api_key' => 'sk-xxxxxxxxxxxxx',
]);

$response = $ai->chat('用一句话介绍人工智能');

echo $response->getContent();          // 回复文本
echo $response->tokens();              // 总消耗 tokens
```

换成国产平台，只需改配置——业务代码一行不用动：

```php
$ai = AI::create(['protocol' => 'qwen',     'model' => 'qwen-plus',  'api_key' => 'sk-xxx']);  // 通义千问
$ai = AI::create(['protocol' => 'zhipu',    'model' => 'glm-4.6',    'api_key' => 'xxx']);     // 智谱 GLM
$ai = AI::create(['protocol' => 'doubao',   'model' => 'doubao-seed-1-6', 'api_key' => 'xxx']);// 豆包
$ai = AI::create(['protocol' => 'moonshot', 'model' => 'kimi-latest','api_key' => 'sk-xxx']);  // Kimi

// 模型名能识别出平台时，protocol 也可以省略
$ai = AI::create(['model' => 'qwen-plus', 'api_key' => 'sk-xxx']);
```

完整的平台清单见 [支持的平台与模型](#支持的平台与模型)，可运行的示例见 `examples_platforms.php`。

`chat()` 接受字符串（自动包装为一条 user 消息）或完整 payload 数组：

```php
$response = $ai->chat([
    'messages' => [
        ['role' => 'system',    'content' => '你是一个有帮助的助手'],
        ['role' => 'user',      'content' => '介绍一下人工智能'],
        ['role' => 'assistant', 'content' => '人工智能是……'],
        ['role' => 'user',      'content' => '再简短一点'],
    ],
    'temperature' => 0.7,
    'max_tokens'  => 1000,
]);
```

> **多轮对话**：默认不保存历史（`messages` 由业务层完整传入）。若想让库自动维护上下文，设 `rounds` 即可，见 [多轮对话上下文](#多轮对话上下文)。

---

## 支持的平台与模型

### 平台一览

`protocol` 传下表中的取值，`api_key` 传该平台控制台的密钥，其余写法所有平台完全一致：

```php
// 换平台只改这两行，业务代码不动
$ai = AI::create([
    'protocol' => 'qwen',          // 见下表
    'model'    => 'qwen-plus',
    'api_key'  => 'sk-xxx',
]);
echo $ai->chat('你好')->getContent();
```

#### 中国大陆主流平台

| 平台 | `protocol` 取值 | 默认端点 | 模型名自动识别 |
|------|----------------|---------|---------------|
| DeepSeek 深度求索 | `deepseek` <br><sub>别名 `deepseek-ai`</sub> | `api.deepseek.com/v1/chat/completions` | `deepseek-*` |
| 阿里云百炼（通义千问） | `qwen` <br><sub>别名 `dashscope` / `bailian` / `tongyi` / `aliyun`</sub> | `dashscope.aliyuncs.com/compatible-mode/v1/chat/completions` | `qwen*`、`qwq*` |
| 阿里云百炼（通义千问） | `qwen-anthropic` <br><sub>别名 `qwen-claude` / `dashscope-anthropic`</sub> | `dashscope.aliyuncs.com/api/v2/apps/claude-code-proxy/v1/messages` | — |
| 火山方舟（豆包） | `doubao` <br><sub>别名 `ark` / `volcengine` / `volces` / `volc`</sub> | `ark.cn-beijing.volces.com/api/v3/chat/completions` | `doubao-*` |
| 百度千帆（文心一言） | `ernie` <br><sub>别名 `qianfan` / `baidu` / `wenxin` / `yiyan`</sub> | `qianfan.baidubce.com/v2/chat/completions` | `ernie-*` |
| 智谱 AI（GLM） | `zhipu` <br><sub>别名 `glm` / `bigmodel` / `chatglm` / `zhipuai`</sub> | `open.bigmodel.cn/api/paas/v4/chat/completions` | `glm-*`、`cogview-*` |
| 智谱 AI（GLM） | `zhipu-anthropic` <br><sub>别名 `glm-anthropic` / `zhipu-claude`</sub> | `open.bigmodel.cn/api/anthropic/v1/messages` | — |
| 月之暗面（Kimi） | `moonshot` <br><sub>别名 `kimi` / `yuezhianmian`</sub> | `api.moonshot.cn/v1/chat/completions` | `kimi-*`、`moonshot-*` |
| 月之暗面（Kimi） | `moonshot-anthropic` <br><sub>别名 `kimi-anthropic` / `moonshot-claude`</sub> | `api.moonshot.cn/anthropic/v1/messages` | — |
| 腾讯混元 | `hunyuan` <br><sub>别名 `tencent` / `tencent-hunyuan`</sub> | `api.hunyuan.cloud.tencent.com/v1/chat/completions` | `hunyuan-*` |
| 讯飞星火 | `spark` <br><sub>别名 `xunfei` / `iflytek` / `xfyun` / `xinghuo`</sub> | `spark-api-open.xf-yun.com/v1/chat/completions` | `spark*`、`4.0Ultra` |
| MiniMax（稀宇科技） | `minimax` <br><sub>别名 `xiyu` / `minimaxi`</sub> | `api.minimaxi.com/v1/text/chatcompletion_v2` | `MiniMax-*`、`abab*` |
| 阶跃星辰（Step） | `stepfun` <br><sub>别名 `step` / `jieyue`</sub> | `api.stepfun.com/v1/chat/completions` | `step-*` |
| 零一万物（Yi） | `yi` <br><sub>别名 `lingyiwanwu` / `01ai` / `01-ai` / `zeroone`</sub> | `api.lingyiwanwu.com/v1/chat/completions` | `yi-*` |
| 百川智能 | `baichuan` <br><sub>别名 `baichuan-inc`</sub> | `api.baichuan-ai.com/v1/chat/completions` | `Baichuan*` |
| 商汤日日新（SenseNova） | `sensenova` <br><sub>别名 `sensetime` / `sensechat` / `shangtang`</sub> | `api.sensenova.cn/compatible-mode/v1/chat/completions` | `SenseChat-*` |
| 360 智脑 | `zhinao` <br><sub>别名 `360` / `qihoo` / `360ai`</sub> | `api.360.cn/v1/chat/completions` | `360gpt*` |
| 华为云 ModelArts（盘古 / MaaS） | `modelarts` <br><sub>别名 `huawei` / `maas` / `pangu`</sub> | `api.modelarts-maas.com/v1/chat/completions` | — |

#### 海外主流平台

| 平台 | `protocol` 取值 | 默认端点 | 模型名自动识别 |
|------|----------------|---------|---------------|
| OpenAI | `openai` <br><sub>别名 `oai` / `openai-compatible` / `compatible` / `chat_completions`</sub> | `api.openai.com/v1/chat/completions` | `gpt-*`、`o3` |
| Anthropic Claude | `claude` <br><sub>别名 `anthropic` / `claude-messages` / `messages`</sub> | `api.anthropic.com/v1/messages` | `claude-*` |
| Google Gemini | `gemini` <br><sub>别名 `google`</sub> | `generativelanguage.googleapis.com/v1beta/openai/chat/completions` | `gemini-*` |
| Z.ai（智谱国际站） | `zai` <br><sub>别名 `z-ai` / `zhipu-global`</sub> | `api.z.ai/api/paas/v4/chat/completions` | — |
| xAI（Grok） | `grok` <br><sub>别名 `xai` / `x-ai` / `x.ai`</sub> | `api.x.ai/v1/chat/completions` | `grok-*` |
| Mistral AI | `mistral` <br><sub>别名 `mistralai`</sub> | `api.mistral.ai/v1/chat/completions` | `mistral-*`、`codestral-*` |
| Meta（Llama API） | `llama` <br><sub>别名 `meta` / `meta-llama`</sub> | `api.llama.com/compat/v1/chat/completions` | — |
| Cohere | `cohere` <br><sub>别名 `command`</sub> | `api.cohere.ai/compatibility/v1/chat/completions` | `command-*` |
| Perplexity | `perplexity` <br><sub>别名 `pplx` / `sonar`</sub> | `api.perplexity.ai/chat/completions` | `sonar*` |
| Azure OpenAI | `azure` <br><sub>别名 `azure-openai` / `azureopenai`</sub> | `需自填 `base_url`` | — |

#### 聚合中转平台

| 平台 | `protocol` 取值 | 默认端点 | 模型名自动识别 |
|------|----------------|---------|---------------|
| OpenRouter | `openrouter` <br><sub>别名 `or` / `open-router` / `open_router`</sub> | `openrouter.ai/api/v1/chat/completions` | — |
| 硅基流动（SiliconCloud） | `siliconflow` <br><sub>别名 `silicon` / `siliconcloud` / `guiji`</sub> | `api.siliconflow.cn/v1/chat/completions` | — |
| 魔搭社区（ModelScope） | `modelscope` <br><sub>别名 `moda` / `damo`</sub> | `api-inference.modelscope.cn/v1/chat/completions` | — |
| Groq | `groq` <br><sub>别名 `groqcloud`</sub> | `api.groq.com/openai/v1/chat/completions` | — |
| Together AI | `together` <br><sub>别名 `togetherai` / `together-ai`</sub> | `api.together.xyz/v1/chat/completions` | — |
| Fireworks AI | `fireworks` <br><sub>别名 `fireworksai`</sub> | `api.fireworks.ai/inference/v1/chat/completions` | — |
| DeepInfra | `deepinfra` | `api.deepinfra.com/v1/openai/chat/completions` | — |
| Cerebras | `cerebras` | `api.cerebras.ai/v1/chat/completions` | — |
| NVIDIA NIM | `nvidia` <br><sub>别名 `nim` / `nvidia-nim` / `build-nvidia`</sub> | `integrate.api.nvidia.com/v1/chat/completions` | — |

#### 本地 / 自建部署

| 平台 | `protocol` 取值 | 默认端点 | 模型名自动识别 |
|------|----------------|---------|---------------|
| Ollama（本地） | `ollama` | `localhost:11434/v1/chat/completions` | — |
| LM Studio（本地） | `lmstudio` <br><sub>别名 `lm-studio`</sub> | `localhost:1234/v1/chat/completions` | — |
| vLLM（自建） | `vllm` <br><sub>别名 `sglang` / `xinference`</sub> | `localhost:8000/v1/chat/completions` | — |

> 表格由 `$ai->listProtocols()` / `listProtocolGroups()` 提供程序化版本，可直接渲染后台下拉框；
> `$ai->listKnownModels('qwen')` 返回该平台的常用模型清单（不发请求）。
> **别名**只是同一协议的另一种写法，效果完全相同（`protocol => 'kimi'` 等价于 `protocol => 'moonshot'`）。

### 内置模型标识（快捷方式）

下列**模型标识**可直接传给 `model`，无需 `protocol`，库会解析出平台、协议与端点：

| 平台 | 模型标识 | 实际协议 | 端点 |
|------|---------|---------|------|
| OpenAI | `gpt-4.1` | OpenAI | api.openai.com |
| OpenAI | `gpt-4o` | OpenAI | api.openai.com |
| Claude | `claude-3-opus` | Claude | api.anthropic.com |
| Gemini | `gemini-2.5-pro` | Gemini（OpenAI 兼容端点） | generativelanguage.googleapis.com |
| DeepSeek | `deepseek-chat` | OpenAI | api.deepseek.com |
| DeepSeek | `deepseek-reasoner` | OpenAI | api.deepseek.com |
| DeepSeek | `deepseek-v4-pro` | OpenAI | api.deepseek.com |
| DeepSeek | `deepseek-v4-flash` | OpenAI | api.deepseek.com |
| DeepSeek | `deepseek-anthropic` | **Claude** | api.deepseek.com/anthropic |
| OpenRouter | `openai/gpt-4o` 等完整标识 | **OpenRouter**（OpenAI 兼容） | openrouter.ai/api |

`deepseek-anthropic` 是 DeepSeek 的 Anthropic 兼容端点，用 Claude 协议通信——**需要工具调用（Agent）时用它，可以用 DeepSeek 的价格跑 Anthropic 的 tools 协议**。

### 表外的模型也能直接用

上表只是「开箱即用的快捷标识」，**`model` 并不限于表内的值**：

- 官方新模型（如 `claude-opus-5`、`gpt-5.1`、`qwen3-max`、`glm-4.6`、`kimi-k2-turbo-preview`）：库按模型名识别出协议家族与官方端点，直接可用，不必等库更新；
- 被多家平台托管的开源模型（如 `llama3`、`mixtral`）或第三方中转/自建网关的模型：加上 `protocol` 或 `base_url` 即可。

```php
// 官方新模型：识别出 claude 家族，自动使用 api.anthropic.com/v1/messages
$ai = AI::create(['model' => 'claude-opus-5', 'api_key' => 'sk-ant-xxx']);

// 国产新模型：识别出 zhipu 家族，自动使用 open.bigmodel.cn/api/paas/v4/chat/completions
$ai = AI::create(['model' => 'glm-4.6', 'api_key' => 'xxx']);

// 开源模型 / 第三方接口：任意模型名 + 手选协议 + 自定义地址
$ai = AI::create([
    'model'    => 'llama3',
    'protocol' => 'openai',                    // 手选协议格式
    'base_url' => 'http://10.0.0.9:11434/v1',  // 自定义接口地址
]);
```

> 模型名无法归属官方平台、又没给 `base_url` / `endpoint` 时，请求前会抛 `ConfigException`，而不是把 Key 发到不相干的官方域名。

`protocol` 除了上面平台表里的取值，还可以传实现了 `Ai\Contracts\ProtocolInterface` 的**自定义协议类名**（见「扩展开发」）。

不传 `protocol` 时按模型名推断（见平台表「模型名自动识别」列），也识别 `厂商/模型` 写法（如 `qwen/qwen-max`）。推断不出时按 `openai` 协议处理，此时必须给 `base_url` 或 `endpoint`。

> **只对厂商自有模型名做推断**。`llama3`、`mixtral` 这类被多家平台托管的开源模型名不参与推断（无法判断你想连哪一家），需要显式给 `protocol` 或 `base_url`。

### 协议差异（重要）

各平台协议对 payload 键的支持并不一致，写业务代码前需要知道：

| payload 键 | OpenAI 协议 | Claude 协议 | Gemini 协议 |
|-----------|------------|------------|------------|
| `messages` | ✅ | ✅（`role: system` 会被丢弃） | ✅ |
| `system`（顶层） | ❌ 忽略 | ✅ | ❌ 忽略 |
| `tools` / `tool_choice` | ❌ 忽略 | ✅ | ❌ 忽略 |
| `temperature` / `max_tokens` | ✅ | ✅ | ✅ |
| `stream` | ✅（自动附带 usage 统计） | ✅ | ✅ |

结论：

- **OpenAI / Gemini** 的系统提示词要写进 `messages` 的 `role: system`；
- **Claude** 的系统提示词要写在顶层 `system` 键；
- **Agent / 工具调用只能用 Claude 协议**。除 Anthropic 官方外，以下国产平台提供了 Anthropic 兼容端点，可以用国产模型的价格跑 Agent：

| `protocol` | 平台 | 鉴权用的密钥 |
|-----------|------|------------|
| `deepseek-anthropic`（模型标识） | DeepSeek | `deepseek__api_key` |
| `zhipu-anthropic` | 智谱 GLM | `zhipu__api_key` |
| `moonshot-anthropic` | 月之暗面 Kimi | `moonshot__api_key` |
| `qwen-anthropic` | 阿里云百炼 | `qwen__api_key` |

```php
// 用 GLM-4.6 跑工具调用：协议是 Claude 的，价格与密钥是智谱的
$ai = AI::create([
    'protocol' => 'zhipu-anthropic',
    'model'    => 'glm-4.6',
    'api_key'  => $config['zhipu__api_key'],
]);
```

### 运行时查询平台与模型

```php
$ai = new AI();

// —— 不发请求的元信息查询（适合渲染后台下拉框） ——
$ai->listPlatforms();        // 37 个平台：['deepseek'=>'DeepSeek 深度求索','qwen'=>'阿里云百炼（通义千问）',...]
                             // 键即业务层约定的密钥前缀 {平台}__api_key
$ai->listProtocols();        // 40 个协议：['openai'=>'OpenAI 兼容（Chat Completions）','qwen'=>'阿里云百炼 / 通义千问（OpenAI 兼容）',...]
$ai->listProtocolGroups();   // 同上，但按「中国大陆 / 海外主流 / 聚合中转 / 本地部署」分组，可直接渲染 optgroup
$ai->listKnownModels('qwen');// 该平台的常用模型：['qwen3-max'=>'通义千问 3 Max','qwen-max'=>'通义千问 Max',...]
$ai->platformOfModel('qwen-max');   // 'qwen'（设置模型前即可安全调用，无法归属官方平台时返回 custom）

// —— 设置模型后的当前状态 ——
$ai->setConfig(['model' => 'glm-4.6', 'api_key' => 'xxx']);
$ai->getPlatform();     // 'zhipu'
$ai->getProtocolKey();  // 'zhipu'，当前实际使用的协议
$ai->resolveEndpoint(); // 'https://open.bigmodel.cn/api/paas/v4/chat/completions'，当前实际请求端点
$ai->listKnownModels(); // 不传参数时取当前协议的内置清单

// —— 实时调用平台接口拉取模型 ——
$ai->listModels();      // 端点跟随 base_url / endpoint 走，接第三方网关时列的就是网关的模型
                        // 拉取失败或平台无此接口时：若请求的是官方域名，回退到内置常用清单；
                        // 接的是第三方网关则返回 null（避免把官方清单误当成网关的能力）
$ai->listModels(true);  // 传入 true 返回完整模型数据（含 id / created / owned_by / pricing 等）
                        // 适用于 OpenRouter、硅基流动等需要展示模型价格/能力标签的场景
```

**真实用例**——按平台分组拉取模型列表并本地缓存一周，供后台下拉框渲染：

```php
$platforms = $this->ai->listPlatforms();
$result    = [];

foreach ($platforms as $platform => $platformName) {
    $apiKey = (string)($this->siteConfig["{$platform}__api_key"] ?? '');
    if (empty($apiKey)) continue;               // 未配置 Key 的平台直接跳过

    try {
        $ai = new AI();
        // 该平台任一模型即可（用于确定协议），直接取库内置清单的第一个
        $known = $ai->listKnownModels(\Ai\Helpers\Protocols::platformProtocols($platform)[0] ?? '');
        $ai->setConfig([
            'model'   => (string)array_key_first($known),
            'api_key' => $apiKey,
        ]);
        $models = $ai->listModels();
        if (is_array($models) && $models) $result[$platform] = $models;
    } catch (\Exception $e) {
        // 单平台失败不影响其它平台
    }
}
```

---

## 配置项

```php
$ai->setConfig([
    'model'        => 'gpt-4o',      // 模型标识（必填），内置标识或任意自定义模型名
    'api_key'      => 'sk-xxx',      // API 密钥（自建/内网接口可不填）
    'protocol'     => '',            // 手选协议格式：见「平台一览」，或自定义协议类名
    'tools'        => [],            // 工具定义（统一格式，见「Agent：工具调用循环」）
    'tool_choice'  => null,          // auto / any / none / ['type'=>'tool','name'=>'x']
    'base_url'     => '',            // 接口根地址，与协议官方路径智能拼接，见「自定义接口地址」
    'endpoint'     => '',            // 完整对话端点，原样使用，优先级高于 base_url
    'endpoint_models' => '',         // 完整模型列表端点（仅 listModels 生效）
    'platform'     => '',            // 平台名，仅供业务层标识，默认由模型名/协议决定
    'headers'      => [],            // 追加/覆盖请求头，值为 null 表示删除协议默认头
    'extra_body'   => [],            // 追加到请求体的私有参数
    'max_tokens'             => 1024 * 64,  // 最大输出 tokens
    'max_completion_tokens'  => 16384,      // 仅 OpenAI o1/o3 系列，覆盖 max_tokens
    'temperature'            => 0.7,        // 温度
    'organization' => 'org-xxx',     // 仅 OpenAI 企业账号
    'project_id'   => 'proj_xxx',    // 仅 OpenAI 企业账号
]);
```

`setConfig()` 是**增量合并**，可以分多次调用；每次传入 `model` 都会重建模型与协议实例。`base_url`、`protocol` 等既可以和 `model` 一起传，也可以在设置模型之后再补。

生成参数（`max_tokens`、`max_completion_tokens`、`temperature`、`top_p`、`top_k`、`stop`、`presence_penalty`、`frequency_penalty`、`seed`、`response_format`、`system`、`tools`、`tool_choice`、`reasoning_effort`、`thinking`）写在 `setConfig()` 里对所有请求生效，单次 `chat()` 的 payload 优先级更高；连接信息（`api_key`、`base_url` 等）不会进入请求体。

接口有私有参数时用 `extra_body`（直接并入请求体），需要特殊鉴权头时用 `headers`：

```php
$ai->setConfig([
    'headers'    => [
        'Authorization' => null,      // 删掉协议默认写入的 Bearer 头
        'X-Api-Token'   => 'abc123',  // 换成对方要求的鉴权头
    ],
    'extra_body' => ['enable_thinking' => false, 'safe_mode' => 1],
]);
```

链式接口：

```php
$ai->setModel('claude-3-opus')   // 单独切换模型
   ->setTimeout(300)             // 超时（秒），长文本生成务必调大
   ->setConnectTimeout(30)       // 连接超时（秒），独立于总超时
   ->setUserAgent('MyApp/1.0')   // 自定义 User-Agent（默认不发送）
   ->setSslVerify(false)         // 禁用 SSL 校验（仅调试/内网自签）
   ->setProxy('socks5h://127.0.0.1:1080')
   ->setStream(true)
   ->setStreamCallback($fn)      // 流式分片交给回调（常驻内存框架必用，见「流式输出」章节）
   ->setAttachments([$file])
   ->chat($prompt);
```

调试时可用 `$ai->getLastInfo()` 获取最近一次请求的 cURL 信息（http_code、总耗时等）。

### 网络代理

支持 `http://`、`https://`、`socks5://`、`socks5h://`（DNS 也走代理）、`socks4://`、`socks4a://`：

```php
if (!empty($config['PROXY_SOCKS5'])) {
    $ai->setProxy($config['PROXY_SOCKS5']);
} elseif (!empty($config['PROXY_HTTP'])) {
    $ai->setProxy($config['PROXY_HTTP']);
}
```

### 自定义接口地址（第三方转发 / 中转 / 自建网关）

默认端点是各平台的官方地址。要接入第三方转发、中转或自建网关，有两种配置方式，优先级从高到低：

**1. `base_url` —— 接口根地址，与协议官方路径智能拼接（最常用）**

只要给出根地址即可，库会补上协议对应的路径；根地址自带的路径前缀会保留，与官方路径重叠的片段自动去重：

```php
$ai = AI::create([
    'model'    => 'deepseek-chat',
    'api_key'  => 'sk-xxx',
    'base_url' => 'https://proxy.example.com',   // 或带端口 http://127.0.0.1:8080
]);
// 实际请求 => https://proxy.example.com/v1/chat/completions
```

| `base_url` | 协议路径 | 实际端点 |
|-----------|---------|---------|
| `https://proxy.com` | `/v1/chat/completions` | `https://proxy.com/v1/chat/completions` |
| `https://proxy.com/v1` | `/v1/chat/completions` | `https://proxy.com/v1/chat/completions`（重叠段去重） |
| `https://proxy.com/openai` | `/v1/chat/completions` | `https://proxy.com/openai/v1/chat/completions` |
| `https://proxy.com/v1/chat/completions` | `/v1/chat/completions` | 原样（已是完整端点） |
| `127.0.0.1:8080` | `/v1/chat/completions` | `https://127.0.0.1:8080/v1/chat/completions`（缺 scheme 按 https） |

**2. `endpoint` —— 完整端点覆盖，原样使用（最灵活）**

当接口路径结构与官方完全不同时使用，直接给出完整 URL：

```php
$ai = AI::create([
    'model'    => 'deepseek-chat',
    'api_key'  => 'sk-xxx',
    'endpoint' => 'https://proxy.example.com/openai/deepseek/chat',  // 原样使用
]);
```

`endpoint` 优先级高于 `base_url`；两者都不设置时使用官方默认端点，完全向后兼容。

---

### OpenRouter 聚合中转

[OpenRouter](https://openrouter.ai) 是一个 AI 模型聚合平台，通过统一的 OpenAI 兼容接口访问 OpenAI、Claude、Gemini、DeepSeek、Llama 等多种模型。库已内置 `openrouter` 协议，**开箱即用**：

```php
// 方式一（推荐）：手选 protocol = openrouter，自动使用 openrouter.ai/api
$ai = AI::create([
    'model'    => 'openai/gpt-4o',              // OpenRouter 上的完整模型标识
    'protocol' => 'openrouter',
    'api_key'  => 'sk-or-v1-xxxxxxxxx',         // OpenRouter API Key
    'referer'  => 'https://myapp.com',          // 可选，来源标识（OpenRouter 后台查看）
    'title'    => 'MyApp',                      // 可选，应用名称
]);

// 方式二：用 base_url 配置（不依赖 protocol=openrouter）
$ai = AI::create([
    'model'    => 'anthropic/claude-sonnet-4-20250514',
    'base_url' => 'https://openrouter.ai/api',
    'api_key'  => 'sk-or-v1-xxxxxxxxx',
]);
```

OpenRouter 上的模型名使用 `厂商/模型` 格式，协议推断规则会自动提取厂商前缀：
- `openai/gpt-4o` → 协议推断为 `openai`，自动使用 OpenAI 协议格式
- `anthropic/claude-sonnet-4-20250514` → 协议推断为 `claude`（但 OpenRouter 接口仍以 OpenAI 兼容格式应答，**协议以 `protocol` 配置为准**）
- `deepseek/deepseek-chat` → 协议推断为 `deepseek`
- `google/gemini-2.5-pro-exp-03-25` → 协议推断为 `gemini`

> **OpenRouter 返回的 usage 字段是 OpenRouter 统计而非原始模型统计**（可能包含缓存命中标记等扩展字段），`$response->getUsage()` 原样透传。

**通过 OpenRouter 查看实时模型状态：**

```php
$ai = AI::create([
    'protocol' => 'openrouter',
    'api_key'  => 'sk-or-v1-xxx',
]);
$models = $ai->setModel('openai/gpt-4o')->listModels(true);
// 返回含 id、pricing、context_length 等的完整模型信息
```

---

### 其他常见 AI 中转/聚合服务

OpenRouter、硅基流动、魔搭、Groq、Together、Fireworks、DeepInfra、NVIDIA NIM 已内置为协议（见「平台一览」），直接 `protocol` 指定即可。其余中转站与自建网关走 OpenAI 兼容协议（`protocol=openai`），配置 `base_url` 或 `endpoint`：

```php
// API2D（国内 OpenAI 中转）
AI::create(['model'=>'gpt-4o', 'protocol'=>'openai', 'api_key'=>'fkxxxxx',
            'base_url'=>'https://openapi.api2d.com']);

// Cloudflare AI Gateway（官方聚合网关）
AI::create(['model'=>'@cf/meta/llama-3-8b-instruct', 'protocol'=>'openai', 'api_key'=>'xxx',
            'base_url'=>'https://gateway.ai.cloudflare.com/v1/{account_id}/{gateway_id}');

// one-api / new-api（自建聚合网关，最通用）
AI::create(['model'=>'glm-4.6', 'protocol'=>'openai', 'api_key'=>'sk-xxx',
            'base_url'=>'https://gateway.example.com']);

// 自建 Anthropic 兼容网关（Agent 工具调用需要 claude 协议）
AI::create(['model'=>'my-agent-model', 'protocol'=>'anthropic', 'api_key'=>'k',
            'base_url'=>'http://127.0.0.1:8080/gw']);
// => http://127.0.0.1:8080/gw/v1/messages

// 内网自建服务（无需 Key，用私有鉴权头）
AI::create(['model'=>'llama3', 'protocol'=>'openai',
            'base_url'=>'http://10.0.0.9:11434/v1',
            'headers' =>['Authorization'=>null, 'X-Internal-Token'=>'t']]);

// 内置协议 + 自定义地址：走自己的代理，但仍按该平台的协议与鉴权方式通信
AI::create(['model'=>'qwen-plus', 'protocol'=>'qwen', 'api_key'=>'sk-xxx',
            'base_url'=>'https://my-proxy.example.com/dashscope']);
```

以上所有接入方式均支持流式输出、附件、回调等完整功能。

> **模型列表端点**：`listModels()` 会跟随 `base_url` 走同一个网关；只配了 `endpoint` 时，库按对话端点同源推导（如 `.../v1/chat/completions` → `.../v1/models`）。若网关的模型列表路径特殊，用 `endpoint_models` 单独完整覆盖（仅对 `listModels()` 生效）。OpenRouter 的模型列表接口返回完整定价数据，`listModels(true)` 可获取。

当前实际请求端点可随时查询：

```php
echo $ai->resolveEndpoint();   // 返回按配置解析后的实际端点
```

---

## Claude Code CLI 程序调用

`Ai\Cli\ClaudeCode` 直接调用本机安装的 **claude 可执行程序**（Claude Code CLI），与 `Ai\Protocol\Claude`（Anthropic HTTP API）互补。适合让 AI 直接操作工作区文件：读写文件、执行工具，配合 `acceptEdits` 权限模式实现"AI 改代码"。

### 快速开始

```php
use Ai\Cli\ClaudeCode;

$cli = ClaudeCode::create([
    'workdir' => '/var/www',   // 工作目录（AI 的文件操作都发生在这里）
]);

$response = $cli->chat('检查一下 index.php 的语法，有问题直接改');
echo $response->getContent();   // 最终文本
echo $response->getSessionId(); // 会话 ID
echo $response->getCostUsd();   // CLI 实测费用（USD）
```

默认参数（均经实测验证）：

```
claude --print --output-format stream-json --verbose
       --setting-sources user,project,local --no-chrome
       --allowedTools "Read Edit Write Grep Glob" --disallowedTools "Bash"
       --permission-mode acceptEdits  [--resume <会话ID>]  < prompt.txt
```

- `--print`：非交互模式，一次查询后退出
- `--setting-sources user,project,local`：加载全部设置来源。**print 模式下 CLI 默认不加载全部来源**，不显式指定会导致项目 `CLAUDE.md`、`.claude/settings.json` 的权限规则、自定义 agent/skill 全部失效——官方 IDE 插件同样显式传这个参数
- `--no-chrome`：关闭 Chrome 集成（服务端无浏览器）
- `--allowedTools Read Edit Write Grep Glob`：**免权限提示白名单**（不是"可用工具集"）
- `--disallowedTools Bash`：把 Bash 从工具集里摘掉，模型根本看不到（这才是硬性禁用）
- `--permission-mode acceptEdits`：文件编辑免确认
- 提示词经临时文件 + stdin 重定向传入，避开命令行长度上限（SSH exec 通道受限时尤为重要）

> `--allowedTools` 与 `--tools` 语义不同：前者决定"哪些工具不用问"，后者（`setTools()`）决定"模型有哪些工具可用"。要真正限制能力范围用 `setTools()` 或 `setDisallowedTools()`。

### claude 路径：自动检测 + 缓存 + 手动指定

```php
$cli = ClaudeCode::create();            // 自动检测（含缓存）
$cli->setBinary('/usr/local/bin/claude'); // 手动指定（优先级最高）

// 缓存控制
$cli->setBinaryCacheEnabled(true);      // 是否启用文件缓存（默认 true）
$cli->setBinaryCacheTtl(86400);         // 缓存有效期秒（默认 1 天）
$cli->clearBinaryCache();               // 清除缓存，强制重新探测
```

自动检测顺序：`command -v claude`（PATH）→ 常见安装路径（`~/.local/bin`、Homebrew、`/usr/bin`）→ nvm 最新版本 `~/.nvm/versions/node/*/bin/claude` → 登录 shell `command -v`。结果缓存到进程内 + 文件（默认 `sys_get_temp_dir()/ai_claude_binary_cache.json`），避免 PHP-FPM 下每次请求重复探测。

### 自定义 CLI 参数

所有 claude 参数均可覆盖/新增/删除：

```php
$cli
    ->setFlag('allowedTools', 'Read Grep Glob')   // 收紧工具权限
    ->setFlag('model', 'claude-sonnet-4-5')        // 指定模型
    ->setFlag('max-turns', 3)                      // 限制轮数
    ->setFlag('include-partial-messages', true)    // 逐 token 增量事件
    ->setFlag('max-budget-usd', 5)                 // 单轮费用上限（注意是 max-budget-usd）
    ->setFlag('system-prompt', '你是代码审查专家')  // 替换系统提示词
    ->removeFlag('verbose')                        // 删除默认参数
    ->resetFlags();                                // 恢复默认
```

参数名不区分下划线：`setFlag('permission_mode', 'plan')` 等价于 `--permission-mode plan`。

多值参数会按 CLI 各自的解析规则渲染，无需自己拼串：`setting-sources`/`tools`/`fallback-model` 逗号连接，`add-dir`/`mcp-config` 一个 flag 跟多个值，`plugin-dir`/`plugin-url` 重复 flag。

> **注意**：`claude` CLI 没有 `--working-dir`、`--max-budget`、`--proxy`、`--theme` 这些参数（实测会被拒绝）。工作目录用 `setWorkdir()`（内部转成 `cd ... &&`），代理走环境变量 `HTTP_PROXY`/`HTTPS_PROXY`。

### 常用参数的专用方法

除了通用的 `setFlag()`，常用选项都有带取值校验的专用方法：

```php
$cli
    // 权限与工具
    ->setPermissionMode('acceptEdits')      // acceptEdits/auto/bypassPermissions/manual/dontAsk/plan
    ->setAllowedTools(['Read', 'Bash(git *)'])  // 免提示白名单，支持细粒度写法
    ->setDisallowedTools(['Bash'])          // 硬性拒绝（工具从工具集移除）
    ->setTools(['Read', 'Grep', 'Glob'])    // 限定可用工具集，传 [] 禁用全部工具
    ->setAddDirs(['/data/shared'])          // 工作目录之外还允许访问的目录
    ->setSkipPermissions(false)             // --dangerously-skip-permissions

    // 模型与推理
    ->setModel('claude-sonnet-5')
    ->setFallbackModel(['sonnet', 'haiku']) // 主模型过载时按序降级
    ->setEffort('high')                     // low/medium/high/xhigh/max
    ->setThinkingTokens(31999)              // 思考预算（IDE 插件用的就是这个值）

    // 成本与产出
    ->setMaxBudgetUsd(0.5)                  // 超预算即终止，无人值守强烈建议设置
    ->setJsonSchema(['type' => 'object', 'properties' => [...]])  // 结构化输出

    // 提示词与扩展
    ->setSystemPrompt('你是代码审查专家')
    ->appendSystemPrompt('输出务必用中文')
    ->setAgent('reviewer')
    ->setAgents(['reviewer' => ['description' => '代码审查', 'prompt' => '...']])
    ->setMcpConfig(['mcpServers' => ['fs' => ['command' => 'npx']]])
    ->setStrictMcpConfig()

    // 会话与配置源
    ->setSettingSources(['user', 'project'])   // 传 [] 表示不加载任何设置文件
    ->setSettings('/path/settings.json')
    ->setFixedSessionId('550e8400-e29b-41d4-a716-446655440000')  // 指定新会话 ID
    ->setForkSession()                      // 续接时分叉，不污染原会话
    ->setContinueLast()                     // 续接当前目录最近一次会话
    ->setSessionPersistence(false)          // 会话不落盘

    // 输出与诊断
    ->setPartialMessages()                  // token 级增量事件
    ->setIncludeHookEvents()
    ->setForwardSubagentText()
    ->setDebug('api,hooks')                 // --debug + --debug-to-stderr，日志走 stderr 事件
    ->setBare()                             // 精简模式，跳过 hooks/LSP/CLAUDE.md 自动发现
    ->setSafeMode();                        // 禁用全部自定义配置，排查用
```

### 结构化输出

`setJsonSchema()` 约束模型最终必须输出符合 schema 的 JSON，`getStructured()` 直接拿数组：

```php
$cli->setJsonSchema([
    'type'       => 'object',
    'properties' => [
        'severity' => ['type' => 'string'],
        'issues'   => ['type' => 'array', 'items' => ['type' => 'string']],
    ],
    'required'   => ['severity', 'issues'],
]);

$res = $cli->chat('审查 src/User.php，按 schema 输出问题清单');
$data = $res->getStructured();   // ['severity' => 'high', 'issues' => [...]]，解析失败返回 null
```

### 流式调用（事件回调）

`runStream()` 逐事件回调，事件语义与官方 IDE 插件一致，可直接转发给 SSE：

```php
$cli->runStream('帮我重构这段代码', function ($event, $data) {
    switch ($event) {
        case 'start':          break;  // ['resume' => bool]
        case 'init':           break;  // 会话初始信息：cwd、session_id、可用工具、MCP 服务器
        case 'text':           break;  // 助手正文文本块（string）
        case 'thinking':       break;  // 助手思考内容（string）
        case 'tool_use':       break;  // ['id','name','input']
        case 'tool_result':    break;  // ['tool_use_id','content','is_error']
        case 'text_delta':     break;  // token 级正文增量（需 setPartialMessages()）
        case 'thinking_delta': break;  // token 级思考增量（需 setPartialMessages()）
        case 'rate_limit':     break;  // 限流状态
        case 'system':         break;  // 其它 system 子类型（thinking_tokens、compact_boundary…）
        case 'error':          break;  // 本轮标记 is_error
        case 'message':        break;  // 原始 stream-json 事件（所有事件都会先经过这里）
        case 'stderr':         break;  // 排查日志（开 setDebug() 后调试输出走这里）
        case 'result':         break;  // 最终汇总
        case 'done':           break;
    }
});
```

`result` 事件是最终汇总：文本取自 `result.result`，费用取自 `result.total_cost_usd`（CLI 实测值，无需价格表），`is_error:true` 时 `isSuccess()` 返回 `false`。

响应对象除 `getContent()` / `getSessionId()` / `getCostUsd()` 外还提供：`getStructured()`、`getThinking()`、`getToolUses()`、`getTools()`、`getPermissionDenials()`、`getSubtype()`、`getStopReason()`、`getInit()`、`getNumTurns()`、`getDurationMs()`、`getExitCode()`、`getCommand()`。

### 多轮会话续接

```php
$res = $cli->chat('第一问');
$cli->setSessionId($res->getSessionId());   // 下一轮自动 --resume 续接

$cli->chat('第二问', ['reset' => true]);    // 强制开启新会话
```

每次执行后若输出带新 `session_id`，会自动回写到内部，`getSessionId()` 始终是最新的。

### 自定义执行器（远程 / 容器化场景）

默认用 `proc_open` 本地执行。需要经 SSH/SFTP 在宿主机跑 claude 时（如 Docker 容器内 PHP），注入自定义执行器：

```php
$cli->setRunner(function ($cmd, $onChunk) {
    $stream = ssh2_exec($conn, $cmd);         // 在宿主机执行同一命令
    $err = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
    while ($buf = fread($stream, 8192))       { $onChunk($buf, 'out'); }
    while ($buf = fread($err, 8192))          { $onChunk($buf, 'err'); }
    return 0;
});
```

配套设置：
- `setShellPrefix('export LANG=en_US.UTF-8; ')`：注入环境（如 locale、nvm PATH）
- `setPromptDir('/data/ai_prompt_tmp')`：提示词临时文件目录，容器与宿主机 1:1 挂载时指向双方可见路径（命令里的 `stdin` 重定向会在宿主机侧读取该文件）
- 本地执行时默认自动把 nvm 下 claude 所在目录加入 PATH（`setAutoNvmPath(false)` 关闭）

### 信息查询（版本 / 登录态 / 模型列表 / 额度用量）

不需要发起对话就能读取 CLI 侧的各类信息，**不消耗模型额度**：

```php
$cli = ClaudeCode::create();

// —— 子命令类查询（毫秒级）——
$cli->getVersion();       // '2.1.222'（实例内缓存）
$cli->isLoggedIn();       // true
$cli->getAuthStatus();    // ['loggedIn'=>true,'authMethod'=>'claude.ai','apiProvider'=>'firstParty',
                          //  'email'=>'...','orgId'=>'...','orgName'=>'...','subscriptionType'=>'max']
$cli->doctor();           // 安装体检报告（原始文本）
$cli->runCommand(['mcp', 'list']);   // 任意子命令：['exit_code'=>0,'stdout'=>'...','stderr'=>'']

// —— 控制协议类查询（秒级，内部临时起一个 claude 进程问完即关）——
$cli->listModels();       // ['default','opus[1m]','claude-fable-5[1m]','sonnet','haiku','opus']
                          // 返回值可直接传给 setModel()
$cli->listModels(true);   // 完整条目，含 resolvedModel / displayName / description /
                          // supportsEffort / supportedEffortLevels / supportsFastMode 等
$cli->getUsage();         // 用量与限流全量数据
$cli->getRateLimits();    // 精简后的额度概览（见下）
$cli->getSettings();      // 合并 user/project/local 后实际生效的设置
$cli->getMcpServers();    // MCP 服务器状态列表
$cli->getBinaryVersion(); // ['version'=>'2.1.222','buildTime'=>'2026-08-04T01:24:05Z']
```

`listModels()` 与 `Ai\AI::listModels()` 保持同样的约定：默认返回可直接使用的模型标识数组，传 `true` 返回完整数据，适合后台下拉框渲染。

**额度查询**

```php
foreach ($cli->getRateLimits() as $limit) {
    printf("%-14s 已用 %5.1f%%  %s后重置\n",
        $limit['key'],                        // session / weekly_all / weekly_scoped
        $limit['percent'],                    // 已用百分比
        gmdate('H:i:s', $limit['resets_in'])  // 距重置剩余秒数
    );
}
// session        已用  16.0%  01:49:47后重置
// weekly_all     已用  17.0%  06:39:47后重置
// weekly_scoped  已用   0.0%  06:39:47后重置
```

每项还含 `severity`（`normal` / 告警级别）、`resets_at`（ISO8601）、`is_active`。按订阅计费时 `limit_dollars` 类字段为 `null`，只提供百分比；额度不可用时返回空数组。

`getUsage()` 的完整结构：

| 键 | 说明 |
|---|---|
| `session` | 当前进程的 `total_cost_usd`、耗时、代码增删行数、分模型用量 |
| `subscription_type` | 订阅类型，如 `max` / `pro` |
| `rate_limits` | 各限流窗口原始数据 + 归一化后的 `limits` 数组 + `extra_usage` 额度包信息 |
| `behaviors` | 近一天 / 一周的 `request_count`、`session_count` 等统计 |

**在会话中查询**：`ClaudeCodeSession` 上同名方法会复用已运行的进程，因此 `getUsage()` 拿到的 `session.total_cost_usd` 就是本会话的真实累计花费；另有 `getSessionCost()` 返回同交互式 `/cost` 的文本报告。

```php
$s = ClaudeCodeSession::create(['workdir' => '/var/www']);
$s->send('第一问');
$s->send('第二问');

$usage = $s->getUsage();
echo $usage['session']['total_cost_usd'];   // 两轮累计花费
echo $s->getSessionCost();
// Total cost:            $0.0067
// Total duration (API):  36s
// Total code changes:    0 lines added, 0 lines removed
// Usage by model:
//     claude-haiku-4-5:  10 input, 52 output, 14.1k cache read, 2.5k cache write ($0.0067)
```

> CLI 的 `get_context_usage`（上下文窗口占用）只在交互式 UI 下响应，headless 模式不回包，故本库未提供对应方法。

### 常驻双工会话（ClaudeCodeSession）

`Ai\Cli\ClaudeCodeSession` 复刻官方 IDE 插件（VSCode / JetBrains）的进程工作方式：claude 以**长驻进程**运行，stdin 持续接收 JSON 消息，stdout 持续吐事件，工具权限通过 stdio 上的 `control_request` 协议实时回调给 PHP 决策。

插件实测启动参数：

```
claude --output-format stream-json --verbose --input-format stream-json
       --max-thinking-tokens 31999 --permission-prompt-tool stdio
       --setting-sources=user,project,local --permission-mode auto
       --debug --debug-to-stderr --enable-auth-status --no-chrome
       --replay-user-messages
```

本类默认采用其中与服务端场景相符的部分（双工 + stdio 权限回调 + 全设置源 + 消息回显 + 禁用 Chrome）；权限模式保持更保守的 `acceptEdits`，思考预算和调试日志默认不开，需要时用 `setThinkingTokens(31999)` / `setDebug()` 打开。

```php
use Ai\Cli\ClaudeCodeSession;

$s = ClaudeCodeSession::create([
    'workdir'      => '/var/www',
    'turn_timeout' => 300,        // 单轮等待上限（秒）
]);

// 工具权限实时决策，等价于 IDE 里弹出的"是否允许执行"
$s->onPermission(function (array $req) {
    // $req: tool_name / display_name / input / description / tool_use_id / permission_suggestions
    if ($req['tool_name'] === 'Bash')  return '本环境禁止执行 shell 命令';   // 字符串 = 拒绝并说明理由
    if ($req['tool_name'] === 'Write' && strpos($req['input']['file_path'], '/etc/') === 0) {
        return false;                                                       // false = 拒绝
    }
    return true;                                                            // true = 放行
    // 也可返回 ['behavior' => 'allow', 'updatedInput' => [...]] 放行并改写入参
});

$a = $s->send('看一下 src 目录结构');
$b = $s->send('把刚才第一个文件的注释补全');   // 同一进程，上下文常驻，无需 --resume 重放
$s->close();
```

**与一次性模式的区别**

| | `ClaudeCode` | `ClaudeCodeSession` |
|---|---|---|
| 进程 | 每轮起停一次 | 长驻，多轮共用 |
| 多轮 | `--resume` 重放历史 | 上下文常驻内存 |
| 工具权限 | 靠 flag 静态配置 | 逐次回调 PHP 决策 |
| 中断 | 只能 kill 进程 | `interrupt()` 优雅中断 |
| 运行时改配置 | 不支持 | 热切权限模式 / 模型 / 思考预算 |
| 受限 PHP 环境 | 支持自定义执行器（SSH） | 仅本地 `proc_open` |

**中断与运行时控制**

```php
$fired = false;
$res = $s->send('全量重构整个项目', function ($ev, $d) use ($s, &$fired) {
    if ($ev === 'tool_use' && !$fired) {
        $fired = true;
        $s->interrupt();          // 相当于 IDE 里的"停止"按钮，进程保活可继续下一轮
    }
});
echo $res->getSubtype();          // error_during_execution

$s->setPermissionMode('plan');    // 已启动时热切换（未启动则只改启动参数）
$s->switchModel('claude-haiku-4-5-20251001');
$s->switchThinkingTokens(31999);
$s->control(['subtype' => 'set_cwd', 'cwd' => '/srv/app']);   // 发送任意 control_request
```

**权限回调的边界（重要）**

`onPermission()` 只会收到 CLI 认为"需要询问"的调用，它**不是一道完整的拦截层**。以下情况 CLI 自行放行、不问 PHP：

- 设置文件（`~/.claude/settings.json`、项目 `.claude/settings.json`）里已预授权的规则 —— 用 `setSettingSources([])` 不加载
- 当前 `permission-mode` 已自动放行的类别（如 `acceptEdits` 下的文件编辑）
- CLI 判定为只读、在沙箱中执行的安全命令（实测 `Bash(echo hi)` 即使 `permission-mode manual` 也不会询问）

要**硬性**禁止某个工具，用 `setDisallowedTools(['Bash'])`（工具从工具集移除，模型根本看不到）或 `setTools([...])` 限定可用集合，不要只依赖回调。

未注册 `onPermission()` 时，默认只自动放行 `Read / Edit / Write / Grep / Glob`，其余一律拒绝；`setAutoApproveTools([...])` 改名单，`allowAllTools()` 全部交回 CLI 自己判断。

其余会话方法：`start()` / `isRunning()` / `close()` / `kill()` / `sendMessage($contentBlocks)` / `getInit()` / `getAvailableTools()` / `getCommand()`。`ClaudeCode` 的全部参数方法在会话类上同样可用（需在首次 `send()` 前设置）。

### 环境要求

- 已安装 Claude Code CLI（`npm install -g @anthropic-ai/claude-code` 或原生安装）
- 本地执行需要 `proc_open`；`shell_exec` 仅用于路径兜底探测，缺失时自动跳过
- `ClaudeCodeSession` 需要 `proc_open` 的双向管道，不支持自定义执行器

---

## 流式输出（SSE）

调用 `setStream(true)` 后，`chat()` 会在接收模型数据的同时**直接向输出缓冲区写 SSE 数据**（自动设置响应头、关闭 Nginx 缓冲），无需注册回调：

```php
$ai = AI::create(['model' => 'deepseek-chat', 'api_key' => 'sk-xxx']);

$response = $ai->setStream(true)->chat('写一篇关于人工智能的文章');

// 流式结束后仍可拿到完整内容与 tokens
$full = $response->getContent();
$used = $response->tokens();
```

服务端实际输出的报文格式（固定协议，前端按此解析）：

```
data: {"type":"stream_chunk","content":"人工"}

data: {"type":"stream_chunk","content":"智能"}

data: {"type":"stream_end","data":{"content":"人工智能……","model":"deepseek-chat","usage":{"prompt_tokens":12,"completion_tokens":300,"total_tokens":312}}}

data: [DONE]
```

对应的前端消费代码：

```javascript
const res = await fetch('/ai/chat', { method: 'POST', body: formData });
const reader = res.body.getReader();
const decoder = new TextDecoder();
let buffer = '';

while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });

    const parts = buffer.split('\n\n');
    buffer = parts.pop();

    for (const part of parts) {
        const line = part.replace(/^data:\s*/, '').trim();
        if (line === '' || line === '[DONE]') continue;
        const ev = JSON.parse(line);
        if (ev.type === 'stream_chunk') output.innerHTML += ev.content;
        if (ev.type === 'stream_end')   console.log('tokens:', ev.data.usage.total_tokens);
    }
}
```

**真实用例**——后台聊天接口（含代理、附件、流式）：

```php
$ai = new \Ai\AI();

if (!empty($siteConfig['PROXY_SOCKS5'])) $ai->setProxy($siteConfig['PROXY_SOCKS5']);

try {
    $ai->setStream(true)
       ->setConfig(['model' => $model, 'api_key' => $apiKey])
       ->setAttachments($attachments)
       ->chat($message);
} catch (\Ai\Exceptions\AIException $e) {
    // 流式已开始输出时，异常信息也只能顺着流写出去
    echo "data: " . json_encode(['type' => 'error', 'message' => $e->getMessage()]) . "\n\n";
}
```

> **PHP 环境注意**：流式输出会清空并关闭所有输出缓冲。如果使用会话锁（`session_start()`），建议在流式开始前 `session_write_close()`，否则同一用户的其它请求会被阻塞。

### 常驻内存框架（Swoole / Workerman）：`setStreamCallback()`

上面的默认行为是 `echo` 到输出缓冲区，**只适用于 PHP-FPM / CLI**。在 Swoole、Workerman、RoadRunner 这类常驻内存框架里，`echo` 会落到进程标准输出（日志文件），永远送不到客户端。这种场景注册回调，由调用方自己下发：

```php
$ai->setStream(true)->setStreamCallback(function($event) use ($response) {
    if ($event['type'] === 'stream_chunk' && $event['content'] !== null) {
        // $response 是 Swoole\Http\Response，write() 分块下发
        $response->write('data: ' . json_encode(['content' => $event['content']]) . "\n\n");
    }
    if ($event['type'] === 'stream_end') {
        $response->write('data: ' . json_encode($event['data']) . "\n\n");
    }
});

$full = $ai->chat($messages)->getContent();   // 返回值不受影响，仍是拼好的完整文本
```

注册回调后库不再产生任何输出，SSE 帧格式完全由调用方决定。事件结构：

| 字段 | 说明 |
|------|------|
| `type` | `stream_chunk` 增量分片 / `stream_end` 结束 |
| `content` | `stream_chunk` 的增量文本，**已由协议层归一化**，跨平台通用；无正文的分片为 `null` |
| `raw` | `stream_chunk` 的平台原始分片数组，需要平台专有字段时才用 |
| `data` | `stream_end` 的汇总：`content` 完整正文、`model`、`usage` |

传 `null`（`setStreamCallback(null)`）恢复默认的直接输出。

### 平台兼容性：40 个协议均已覆盖

「普通对话 + 流式输出 + token 统计」是每个协议的基础能力，`tests/stream_test.php`
用各平台真实的 SSE 报文逐个回放校验。三套测试都**不联网、不需要 Key、不依赖 PHPUnit**：

```bash
php tests/smoke_test.php    # 全部类可加载/可实例化、继承链签名兼容
php tests/stream_test.php   # 40 个协议 × 普通对话 / 流式 / token 统计
php tests/tools_test.php    # 工具调用跨平台一致性
php tests/lib_test.php      # 并发批量 / Memory 并发安全 / 计价 / 日志注入
php tests/cli_test.php      # CLI 参数渲染与命令注入防护
php tests/ssrf_test.php     # SSRF 防护的全部已知绕过向量

composer test               # 依次跑上面全部六套
composer analyse            # PHPStan level 8 静态分析（全绿）
```

CI 会在 **PHP 7.2 / 7.4 / 8.0 / 8.2 / 8.4** 上跑同样的检查，并在 8.2 上额外跑一次静态分析。

> 库内所有公开方法都带完整的 phpdoc 类型（PHP 7.2 不支持类型化属性，类型只能写在注释里），
> IDE 能正确补全 `getToolCalls()` 这类方法的返回结构。

覆盖的报文差异（都踩过坑，已在传输层统一处理）：

| 差异 | 说明 |
|------|------|
| `data:` 后**不带空格** | SSE 规范里冒号后的空格是可选的，讯飞星火等平台就不带；只认带空格的写法会导致整个流为空 |
| CRLF 行尾 | 部分网关/代理会改写换行符 |
| 末尾无换行符 | 最后一帧（往往正是带 `usage` 的收尾帧）没有换行时不能被丢弃 |
| 夹杂 `event:` / `id:` 等字段 | Anthropic 协议每帧都带 `event:`，需正确跳过 |
| 用量分帧下发 | Anthropic 把 `input_tokens` 放在 `message_start`、`output_tokens` 放在 `message_delta`，需跨帧合并 |
| HTTP 200 但流里报错 | MiniMax（`base_resp`）、OpenAI 系（`error`）等出错时状态码仍是 200，需抛异常而不是返回空内容 |

`usage` 中的 `prompt_tokens` / `completion_tokens` / `total_tokens` 三个标准字段在所有平台一致可用
（Anthropic 系的 `input_tokens` / `output_tokens` 已自动映射），平台特有字段原样保留。

> **自定义协议**可选实现两个钩子来接入上述能力（不实现也能正常流式，只是拿不到用量/错误）：
> `parseStreamUsage(array $chunk): ?array` 返回该帧的用量（AI 层会逐帧合并）、
> `parseStreamError(array $chunk): ?string` 返回该帧的错误信息（非空即抛异常）。

---

## 附件（多模态）

```php
use Ai\Helpers\AIFile;

$image = AIFile::fromPath('/path/to/image.jpg');            // 本地文件，自动识别 MIME
$image = AIFile::fromPath($tmpName, $_FILES['x']['type']);  // 指定 MIME
$image = AIFile::fromUrl('https://example.com/image.jpg');  // 远程 URL

$response = $ai->setAttachments([$image])->chat('描述这张图片');
```

附件格式由**模型层**适配：视觉模型（如 `gpt-4o`）转成各平台的图片块；不支持多模态的模型（如 DeepSeek 系列）会把附件信息以文本形式追加到最后一条用户消息，避免请求直接报错。

附件在每次 `chat()` 后自动清空，不会影响下一轮对话。

---

## 多轮对话上下文

两种方式，按需选一：

### 方式一：设 `rounds`，库自动维护（推荐）

```php
$ai = AI::create([
    'protocol' => 'qwen',
    'model'    => 'qwen-plus',
    'api_key'  => 'sk-xxx',
    'rounds'   => 5,          // 保留最近 5 轮上下文；默认 0 = 不启用
]);

echo $ai->chat('我叫小明')->getContent();
echo $ai->chat('我叫什么名字？')->getContent();   // 模型能答出"小明"
```

`rounds` 默认为 **0（不启用）**，此时库完全不碰历史，行为与旧版本一致。

**多用户 / 常驻进程**下用 `setSessionId()` 隔离上下文，一个 AI 实例可服务多个会话：

```php
$ai->setSessionId('user-1001')->chat('我喜欢喝咖啡');
$ai->setSessionId('user-2002')->chat('我喜欢喝茶');      // 与上一个会话互不干扰

$ai->setSessionId('user-1001')->chat('我喜欢喝什么？');  // 答"咖啡"
```

历史存在内存里，进程退出即失。跨请求要持久化就自己落库：

```php
// 请求结束时存起来
$redis->set("ai:history:{$uid}", json_encode($ai->exportHistory()));

// 下次请求恢复
$ai->importHistory(json_decode($redis->get("ai:history:{$uid}"), true) ?: []);
```

| 方法 | 说明 |
|------|------|
| `setSessionId(string)` / `getSessionId()` | 切换/读取当前会话，不同会话各自独立一份历史 |
| `getHistory()` / `setHistory(array)` | 读写当前会话的历史消息 |
| `clearHistory(bool $all = false)` | 清空当前会话；传 `true` 清空全部会话 |
| `exportHistory()` / `importHistory(array)` | 导出/导入全部会话，用于持久化 |

裁剪按「轮」而非按条数：一轮从一次真正的用户提问开始，只含 `tool_result` 的消息
算作上一轮工具调用的一部分。这样不会把 `tool_use` 和对应的 `tool_result` 切散——
切散会让下一次请求直接被平台拒绝。

> `chatBatch()` 不读写历史：批量里每条都是独立请求，混进同一份上下文没有意义。

### 方式二：自己维护 `messages`

不设 `rounds` 时库完全不介入，业务层自行拼接：

```php
$messages = [];
$messages[] = ['role' => 'user', 'content' => '我叫小明'];
$resp = $ai->chat(['messages' => $messages]);

$messages[] = ['role' => 'assistant', 'content' => $resp->getContent()];
$messages[] = ['role' => 'user', 'content' => '我叫什么名字？'];
$resp = $ai->chat(['messages' => $messages]);
```

需要完全掌控裁剪策略（按 token 数、按重要性摘要等）时用这种方式。

## 请求前后回调

```php
$ai->onBefore(function (&$payload) {
    // 请求前：可审计、可改写 payload
    log_message('debug', json_encode($payload));
    $payload['temperature'] = 0.5;
});

$ai->onAfter(function ($response) {          // onResponse() 是它的别名
    // 请求后：统计 tokens、写入用量表
    log_usage($response->getUsage());
});
```

回调内抛出的异常会被捕获并记录到 `error_log`，不会中断主流程。

---

## 响应对象

`chat()` 返回 `Ai\Contracts\AIResponseInterface`：

| 方法 | 说明 |
|------|------|
| `getContent(): string` | 回复文本（流式模式下为累积后的完整文本） |
| `getRaw(): array` | 平台原始响应体（Agent 解析 `tool_use` 块靠它） |
| `getUsage(): array` | 完整用量对象，含 `prompt_tokens` / `completion_tokens` / `total_tokens` 及平台返回的扩展字段（`prompt_tokens_details.cached_tokens`、`cache_creation_input_tokens` 等，因平台而异） |
| `tokens(): int` | 总 tokens |
| `getModel(): string` | 实际返回的模型名 |
| `isSuccess(): bool` | 是否成功 |
| `cost(array $pricing): float` | 按价格表估算费用，默认**每千 tokens**（与旧版本一致，不改动已有代码的账） |
| `costPerMillion(array $pricing): float` | 同上但按**每百万 tokens**，可直接抄官网数字，如 `['prompt'=>5.0,'completion'=>25.0,'cached'=>0.5]`；`cached` 为命中缓存的输入价，两大家族的字段名都认 |
| `getToolCalls(): array` | 模型发起的工具调用，已归一：`[['id'=>..,'name'=>..,'input'=>[..]]]` |
| `hasToolCalls(): bool` | 本轮是否要求调用工具 |
| `getStopReason(): string` | 结束原因（已归一）：`end_turn` / `tool_use` / `max_tokens` / `content_filter` / `refusal` |
| `toAssistantMessage(): array` | 转成可回填进 `messages` 的 assistant 回合 |
| `getError(): string` | 失败原因（仅 `chatBatch()` 这类不抛异常的场景会填充） |
| `toArray()` / `__toString()` | 转数组 / 直接当字符串用 |

---

## 异常处理

```php
use Ai\Exceptions\AIException;
use Ai\Exceptions\ConfigException;
use Ai\Exceptions\RequestException;

try {
    $response = $ai->chat($prompt);
} catch (ConfigException $e) {
    // 配置错误：模型不存在、未设置模型、协议未初始化
} catch (AIException $e) {
    // 请求失败：网络、鉴权、限流、平台报错，均在 chat() 内被包装成 AIException
    echo $e->getMessage();
    echo $e->getPlatform();      // 出错平台，如 'openai'
    echo $e->getErrorCode();     // 平台错误码
    print_r($e->getRawResponse()); // 平台原始错误响应，排查问题的关键
}
```

`RequestException` 由传输层抛出，`chat()` 内部会将其转成携带平台信息的 `AIException`，业务层通常只需捕获 `AIException`。

---

## 健壮性：超时与自动重试

AI 接口最高频的失败不是「打不通」，而是 **429 限流**和 **5xx 临时故障**。库默认已处理：

```php
$ai->setTimeout(120);         // 默认 120 秒（推理模型单次经常跑一两分钟，60 秒会误杀）
$ai->setRetry(2);             // 默认重试 2 次；传 0 关闭
$ai->setRetry(3, 800, 30000); // 重试 3 次、退避基数 800ms、单次等待上限 30 秒
```

| 情形 | 行为 |
|------|------|
| 408 / 409 / 429 / 500 / 502 / 503 / 504 / 529 | 自动重试 |
| 响应带 `Retry-After`（秒数或 HTTP 日期） | 优先按服务端给的时间等待，并有上限保护 |
| 无 `Retry-After` | 指数退避 + 抖动（避免多进程被同时限流后又齐刷刷重试） |
| 4xx（除上表）如 400 / 401 / 403 | **不重试**，立即抛异常 |
| 流式请求 | **不重试**——分片已经吐给调用方，重试会造成重复输出 |
| 请求体含非 UTF-8 字节 | 立即抛 `RequestException` 并说明原因（此前会静默发出空请求体） |

需要接入连接池、换 HTTP 客户端或在单元测试里注入假传输层时，替换整个传输层：

```php
$ai->setTransport(new MyTransport());   // 实现 Ai\Contracts\TransportInterface 即可
```

### 并发批量：`chatBatch()`

批量翻译、摘要、打标签这类场景串行跑，总耗时是「单条 × 条数」，且每条都要重做一次
TLS 握手。`chatBatch()` 用 `curl_multi` 并发，总耗时约等于「最慢的一条 × 批次数」：

```php
$results = $ai->chatBatch([
    'title' => '把这句翻译成英文：你好',
    'intro' => '把这句翻译成英文：世界',
    'body'  => ['messages' => [['role' => 'user', 'content' => '翻译：再见']]],
], 5);   // 第二个参数是并发度，默认 5

foreach ($results as $key => $r) {
    if ($r->isSuccess()) {
        echo $key, ': ', $r->getContent(), "\n";
    } else {
        echo $key, ' 失败: ', $r->getError(), "\n";   // 单条失败不影响其它条
    }
}
```

- 返回结果与入参**键名一一对应且保序**，可直接与原数组对齐
- **单条失败不抛异常**，返回 `isSuccess()` 为 false 的响应，用 `getError()` 取原因——
  批量场景不该因为一条失败就丢掉其它已经跑完的结果
- 不支持流式（并发流式的分片会互相穿插）
- 并发度调大容易触发平台限流，配合 `setRetry()` 使用

### 日志：接到你自己的日志系统

库内不再硬编码 `error_log()`。注入后拉取模型列表失败、流式回调异常等都会走你的日志：

```php
use Ai\Helpers\Log;

Log::setLogger($monolog);                  // 任何 PSR-3 风格对象（不引入 psr/log 依赖）
Log::setLogger(function ($level, $message, array $context) {
    log_message($level, '[AI] ' . $message . ' ' . json_encode($context));
});
Log::setLogger(null);                      // 恢复默认的 error_log
```

---

## Agent：工具调用循环

`Ai\Agent\Agent` 实现完整的 agentic 循环：模型决定调用哪个工具 → 库执行工具 → 结果回填给模型 → 继续，直到模型给出最终答复或达到迭代上限。

**40 个协议全部可用**。各家把同一件事写成了两套结构，库在协议层吃掉了差异：

| | OpenAI 系（36 个协议） | Anthropic 系（4 个协议） |
|---|---|---|
| 工具定义 | `{type:'function', function:{name, parameters}}` | `{name, description, input_schema}` |
| 模型发起 | `message.tool_calls[]`，`arguments` 是 JSON **字符串** | content 里的 `tool_use` 块，`input` 是**数组** |
| 结果回填 | 独立的 `{role:'tool', tool_call_id}` 消息 | user 消息里的 `tool_result` 块 |
| 结束原因 | `finish_reason: 'tool_calls'` | `stop_reason: 'tool_use'` |
| 系统提示 | `messages` 首条 `role:'system'` | 顶层 `system` 字段 |

业务层只写**一套**（库的统一格式，采用 Anthropic 风格），换平台只改 `protocol`：

```php
$agent = (new Agent($ai))->setTools($tools)->onEvent($handler);
$agent->run([['role' => 'user', 'content' => '北京天气怎么样']]);

// 上面这段代码，$ai 换成下面任意一个都不用改：
AI::create(['protocol' => 'qwen',     'model' => 'qwen-plus',       'api_key' => '...']);
AI::create(['protocol' => 'zhipu',    'model' => 'glm-4.6',         'api_key' => '...']);
AI::create(['protocol' => 'doubao',   'model' => 'doubao-seed-1-6', 'api_key' => '...']);
AI::create(['protocol' => 'openai',   'model' => 'gpt-4o',          'api_key' => '...']);
AI::create(['protocol' => 'claude',   'model' => 'claude-opus-5',   'api_key' => '...']);
```

完整可运行示例见 `examples_agent.php`；跨平台一致性由 `tests/tools_test.php` 保证。

> 也可以直接写 OpenAI 原生格式（`{type:'function'}` 的工具定义、`role:'tool'` 的消息），
> 库会识别并转成目标平台的结构，不强制迁移已有代码。

### 不用 Agent，自己控制循环

`AIResponse` 提供了平台无关的取用接口：

```php
$resp = $ai->chat(['messages' => $messages, 'tools' => $toolDefs]);

if ($resp->hasToolCalls()) {                      // 各平台一致
    $messages[] = $resp->toAssistantMessage();    // 把模型这一轮接回上下文

    $results = [];
    foreach ($resp->getToolCalls() as $call) {    // ['id'=>.., 'name'=>.., 'input'=>[..]]
        $results[] = [
            'type'        => 'tool_result',
            'tool_use_id' => $call['id'],
            'content'     => myHandler($call['name'], $call['input']),
        ];
    }
    $messages[] = ['role' => 'user', 'content' => $results];

    $resp = $ai->chat(['messages' => $messages, 'tools' => $toolDefs]);
}

echo $resp->getContent();
echo $resp->getStopReason();   // end_turn / tool_use / max_tokens / content_filter / refusal
```

### 工具定义

```php
$tools = [
    'sql_query' => [
        'description'  => '执行只读 SQL 查询。仅支持 SELECT/SHOW/DESCRIBE/EXPLAIN，最多返回 200 行。',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'sql' => ['type' => 'string', 'description' => '要执行的 SQL'],
            ],
            'required'   => ['sql'],
        ],
        'handler' => function (array $input) {
            $sql = trim((string)($input['sql'] ?? ''));
            if (!is_readonly_sql($sql)) return 'ERROR: 只允许只读查询';
            return json_encode(db_query($sql), JSON_UNESCAPED_UNICODE);
        },
    ],
];
```

`handler` 返回的字符串会作为 `tool_result` 回填给模型；抛出的异常会被自动转成 `ERROR: 异常信息` 交给模型，不会中断循环。

### 运行

```php
$ai = new \Ai\AI();
$ai->setConfig([
    'model'      => 'deepseek-anthropic',
    'api_key'    => $apiKey,
    'max_tokens' => 1024 * 64,
]);

$emit = function ($ev) {
    // 把 Agent 过程实时推给前端
    echo "data: " . json_encode($ev, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
};

$agent = (new \Ai\Agent\Agent($ai))
    ->setSystem($systemPrompt)
    ->setTools($tools)
    ->setMaxIter(128)      // 需要逐页分析的任务要给足迭代次数，默认 25
    ->onEvent($emit);

$agent->run([['role' => 'user', 'content' => $message]]);

$reply = $agent->lastText();   // 最终自然语言回复
```

### 事件类型

`onEvent()` 回调会依次收到：

| 事件 | 字段 | 含义 |
|------|------|------|
| `thinking` | `iter` | 第几轮迭代开始 |
| `agent_text` | `text` | 模型输出的自然语言 |
| `tool_call` | `name`、`input` | 模型决定调用某工具 |
| `done` | — | 正常结束 |
| `error` | `message` | 出错或达到最大迭代步数 |

工具内部的细粒度事件（如 diff、进度）由各 `handler` 自行通过闭包发出，库不做假设。

---

## Editor：AI 代码编辑

`Ai\Editor\*` 提供一套「让 AI 改代码」的完整协议：把编辑器现场（当前文件、光标、选区、已打开文件、工作区规范）结构化后交给模型，模型返回可校验、可执行的编辑动作。

```php
use Ai\Editor\EditContext;
use Ai\Editor\EditProtocol;
use Ai\Editor\EditExecutor;
use Ai\Editor\EditAction;

// 1. 组装编辑上下文
$ctx = (new EditContext(FCPATH))
    ->setFile('templates/default/index.php')
    ->setLanguage('php')
    ->setContent($fileContent)
    ->setCursor(['line' => 42, 'column' => 8])
    ->setSelection(['start' => [...], 'end' => [...]], $selectedText)
    ->setOpenedFiles($openedFiles)
    ->setWorkspace($workspace);      // Ai\Editor\Workspace，限定可写根目录与编码规范

// 2. 生成系统提示词（内含编辑协议说明）+ 上下文 JSON
$system  = EditProtocol::systemPrompt($ctx);
$ctxJson = json_encode($ctx->toPromptJson(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$response = $ai->chat([
    'system'   => $system,
    'messages' => [['role' => 'user', 'content' => $message . "\n\n[CONTEXT]\n```json\n{$ctxJson}\n```"]],
]);

// 3. 解析模型返回的编辑计划
$plan = EditProtocol::parse($response->getContent());

// 4. 校验并执行
$executor = new EditExecutor($workspace->getRoot());   // 越界路径会被拒绝
foreach ($plan->toArray()['actions'] as $a) {
    $action = EditAction::fromArray($a);
    if (!$action->validate()) continue;
    $abs        = $executor->resolveAbsolute($action->file);   // 路径安全解析
    $newContent = $executor->computeContent(file_get_contents($abs), $action);
    file_put_contents($abs, $newContent);                      // 建议先备份
}
```

配合 Agent 可实现三种工作模式：`plan`（只读规划）、`approval`（产出待人工审核的建议）、`auto`（自动写入并备份）。

---

## Tools：安全网页抓取

让模型联网读网页时，最大的风险是 SSRF。`Ai\Tools\HttpFetch` 内置纵深防御：

- 仅允许 `http`/`https`，拒绝带 `user:pass@` 的 URL
- 端口白名单（默认 80/443）
- 解析主机的所有 A/AAAA 记录，**任一 IP 落在私有/保留/回环/链路本地/云元数据段即整体拒绝**
- 用 `CURLOPT_RESOLVE` 把连接钉死到已校验 IP，防 DNS rebinding
- 不自动跟随重定向，逐跳重新校验
- 超过 `max_bytes` 立即中断；校验 TLS；不带 Cookie；不走站点代理

```php
use Ai\Tools\HttpFetch;
use Ai\Tools\WebContent;

$fetcher = new HttpFetch(['max_bytes' => 1500 * 1024, 'timeout' => 15]);
$res     = $fetcher->fetch($url);
// $res = ['ok'=>bool, 'status'=>int, 'content_type'=>string, 'final_url'=>string, 'bytes'=>int, 'body'=>string, 'error'=>string]

if ($res['ok']) {
    // 按需渲染成模型友好的格式：text 纯文本 / md Markdown / source 原始源码
    $text = WebContent::render($res['body'], $res['content_type'], 'md', 16000);
}
```

把它包成 Agent 工具即可让模型自主上网：

```php
'fetch_url' => [
    'description'  => '抓取一个公网网页并返回正文，用于查证实时信息。',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'url'    => ['type' => 'string'],
            'format' => ['type' => 'string', 'enum' => ['text', 'md', 'source']],
        ],
        'required' => ['url'],
    ],
    'handler' => function ($input) use ($fetcher) {
        $r = $fetcher->fetch($input['url']);
        if (!$r['ok']) return 'ERROR: ' . $r['error'];
        return WebContent::render($r['body'], $r['content_type'], $input['format'] ?? 'md');
    },
],
```

---

## Memory：Agent 长期记忆

把一个 Markdown 文件当作 Agent 的持久记忆（类似 `CLAUDE.md`）。文件位置由业务层决定，库不认识任何具体路径。

```php
use Ai\Agent\Memory;

$mem = new Memory(FCPATH . 'writable/agent/memory.md', 20000);  // 第二参数：注入对话时的最大字符数

$block = $mem->forPrompt();          // 读取并截断，空记忆返回 ''
if ($block !== '') {
    $system .= "\n\n# 长期记忆\n" . $block;
}

$mem->append('用户偏好深色主题');    // 追加一条
$mem->write($fullContent);           // 覆盖整份
```

---

## 完整业务案例：批量 JSON 翻译

一个真实场景——把多语言词条按批交给 AI 翻译，要求模型严格返回 `{"记录ID":"译文"}` 的 JSON，并做失败重试、格式校验与结果回写。

```php
use Ai\AI;
use Ai\Exceptions\AIException;

// 1. 组装待翻译数据：{ 记录ID: 原文 }
$translateData = [];
foreach ($batch as $rec) {
    $translateData[(int)$rec['id']] = $rec['text_source'];
}

// JSON_UNESCAPED_SLASHES 很关键：否则 </p> 会被转义成 <\/p>，
// 模型会把 \/ 当字面字符照抄进译文，导致译文出现错误转义
$dataJson = json_encode($translateData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$prompt = "把下面 JSON 中每个值从 {$from} 翻译成 {$to}，"
        . "保持键不变、保留 HTML 标签，只返回 JSON：\n{$dataJson}";

// 2. 调用，带重试与 JSON 校验
$ai = new AI();
$ai->setConfig(['model' => $model, 'api_key' => $apiKey, 'max_tokens' => 1024 * 256])
   ->setTimeout(300);

$content = '';
for ($loop = 1; $loop <= 3; $loop++) {
    try {
        $content = trim($ai->chat($prompt)->getContent());
    } catch (AIException $e) {
        log_message('error', 'AI翻译异常: ' . $e->getMessage());
        break;
    }
    // 去掉模型可能包裹的 ```json ``` 围栏后校验
    $content = preg_replace('@^\s*```(json)?\s*(.+?)\s*```\s*$@is', '$2', $content);
    if ($content !== '' && json_decode($content, true) !== null) break;
    $content = '';
}

// 3. 回写
$result = json_decode($content, true);
if (is_array($result)) {
    foreach ($result as $id => $text) {
        if (!isset($translateData[(int)$id]) || trim($text) === '') continue;
        update_translation((int)$id, trim($text));
    }
}
```

实战经验：

- **务必带 `JSON_UNESCAPED_SLASHES`**，并在收到结果后兜底 `str_replace('\\/', '/', $text)`；
- 模型常把 JSON 包在 ```` ```json ```` 围栏里，解析前先剥离；
- 按**总字符数**而不是条数切批（例如单批 10 万字符上限），超长的单条 HTML 单独成批；
- 失败时把模型返回的原文一并记录下来，否则无从排查。

---

## 扩展开发

### 新增模型

```php
namespace Ai\Models\OpenAI;

use Ai\Models\BaseModel;

class GPT4Turbo extends BaseModel
{
    protected $name     = 'gpt-4-turbo';                        // 发给平台的真实模型名
    protected $platform = 'openai';
    protected $protocol = 'Ai\\Protocol\\OpenAI';               // 复用协议
    protected $endpoint = 'https://api.openai.com/v1/chat/completions';
    protected $features = ['chat', 'stream', 'vision'];
    protected $config   = ['max_tokens' => 4096, 'temperature' => 0.7];
}
```

然后在 `AI::$modelMap` 中注册 `'gpt-4-turbo' => 'Ai\Models\OpenAI\GPT4Turbo'`。

需要特殊附件处理的模型，重写 `processAttachments(array $payload, array $attachments): array` 即可。

> 只是想临时接一个新模型/新接口，**不必写模型类**——直接用 `model` + `protocol` + `base_url` 配置即可，库会在运行时构造 `Ai\Models\CustomModel`。也可以自己 `new CustomModel([...])` 传给 `setModel()`。

**绝大多数新平台都是 OpenAI 兼容的**，继承 `Ai\Protocol\OpenAI` 改一个地址即可——库内 30 多个平台协议都是这么实现的：

```php
namespace Ai\Protocol;

/**
 * 某某云（OpenAI 兼容）
 */
class MyCloud extends OpenAI
{
    public function defaultBaseUrl(): string
    {
        return 'https://api.mycloud.com';
    }

    // 路径与 OpenAI 官方不同时才需要覆盖
    public function chatPath(): string   { return '/v2/chat/completions'; }
    public function modelsPath(): string { return '/v2/models'; }

    // 鉴权方式不是 Authorization: Bearer 时覆盖 buildHeaders()

    /** 常用模型：供后台离线渲染下拉框，也是拉取失败时的兜底 */
    public function knownModels(): array
    {
        return ['my-model-pro' => 'MyCloud Pro'];
    }
}
```

然后在 `Ai\Helpers\Protocols::$map` 注册标识（顺带在 `$alias` / `$detect` 里加别名与模型名识别规则），`protocol => 'mycloud'` 就能用了。

**协议格式本身不同**（既不是 OpenAI 也不是 Anthropic / Gemini）时：

1. 实现 `Ai\Contracts\ProtocolInterface`：`buildRequest`、`parseResponse`、`buildHeaders`、`parseStreamChunk`、`isStreamEnd`、`listModels`；
   可选实现 `defaultBaseUrl()` / `chatPath()` / `modelsPath()` 供自动组装端点，`use ModelCatalog` 获得常用模型清单与兜底能力；
2. 创建该平台的模型类，`$protocol` 指向新协议；或直接把协议类名传给 `protocol` 配置项（无需改动本库）：
   ```php
   AI::create(['model'=>'x', 'protocol'=>'App\\Protocol\\MyApi', 'base_url'=>'https://api.my.com']);
   ```
3. 在 `AI::$modelMap` 注册模型标识（可选，仅为提供快捷标识）。

传输层 `Ai\Transport\CurlTransport` 与协议无关，通常无需改动。

---

## 架构

```
php-ai/
├── src/                    # 源代码（PSR-4 命名空间 Ai\）
│   ├── AI.php              # 主入口：配置、模型解析、对话、流式、回调
│   ├── Agent/              # Agent 循环 + 长期记忆
│   ├── Cli/                # ClaudeCode 一次性调用 / ClaudeCodeSession 常驻双工会话 + 响应对象
│   ├── Contracts/          # 接口定义：Model / Protocol / Transport / AIResponse
│   ├── Editor/             # AI 代码编辑：上下文 / 协议 / 动作 / 执行器 / 工作区
│   ├── Exceptions/         # AIException / ConfigException / RequestException / ProcessException
│   ├── Helpers/            # AIFile（附件封装）、Endpoint（端点解析）、Protocols（协议注册表）
│   │                       # Headers（请求头合并）、Tools（工具调用格式归一）、Log（可注入日志）
│   ├── Models/             # 模型层：各平台模型的名称、端点、能力、默认配置
│   │   ├── BaseModel.php
│   │   ├── CustomModel.php # 通用模型：任意模型名 + 手选协议 + 自定义接口地址
│   │   ├── OpenAI/  Claude/  Gemini/  DeepSeek/
│   ├── Protocol/           # 协议层：40 个平台协议
│   │                       #   ModelCatalog.php   常用模型清单 + 拉取失败兜底（trait）
│   │                       #   OpenAI / Claude / Gemini  三种基础协议格式
│   │                       #   国内：Qwen / Doubao / Ernie / Zhipu / Moonshot / Hunyuan / Spark /
│   │                       #        MiniMax / StepFun / Yi / Baichuan / SenseNova / Zhinao / ModelArts …
│   │                       #   海外：Grok / Mistral / Cohere / Perplexity / Llama / Azure …
│   │                       #   聚合：OpenRouter / SiliconFlow / ModelScope / Groq / Together / Fireworks …
│   │                       #   本地：Ollama / LMStudio / VLLM
│   ├── Response/           # 统一响应对象
│   ├── Tools/              # HttpFetch（SSRF 防护）、WebContent（格式化）
│   └── Transport/          # cURL 传输层（含 SSE 解析、代理、超时）
├── autoload.php            # PSR-4 加载器（不用 Composer 时引入）
├── composer.json
├── tests/                  # 回归测试（纯 PHP，无需 PHPUnit、无需网络、无需 API Key）
│   ├── smoke_test.php      # 全部类可加载/可实例化、继承链签名兼容
│   ├── stream_test.php     # 40 个协议 × 普通对话 / 流式 / token 统计
│   ├── tools_test.php      # 工具调用跨平台一致性（同一段代码跑两个协议家族）
│   ├── lib_test.php        # 并发批量 / Memory 并发安全 / 计价 / 日志注入
│   ├── cli_test.php        # CLI 参数渲染与命令注入防护
│   └── ssrf_test.php       # SSRF 防护的全部已知绕过向量
├── examples*.php           # 使用示例（examples_platforms.php 为多平台接入示例）
├── LICENSE
├── README.md
└── .gitignore
```

设计上分四层：**模型层**声明「是什么」（名称、端点、能力），**协议层**负责「怎么说」（请求/响应/流式格式），**传输层**负责「怎么发」（cURL、代理、超时、SSE），**主入口**负责编排。新增平台只动前两层。

---

## 已知限制

- 会话历史存在内存里，进程退出即失，跨请求需用 `exportHistory()` / `importHistory()` 自行落库；
- 工具调用仅支持**非流式**：流式响应里 OpenAI 系的 `tool_calls` 是按分片累积的，尚未做重组，`getToolCalls()` 在流式下返回空数组。Agent 内部已固定用非流式，不受影响；
- 流式输出只提取正文增量，推理模型的思维链（`reasoning_content` / `thinking` 块）不计入 `getContent()`，需要时从 `stream_chunk` 事件的 `raw` 字段自取；
- 各平台的 `knownModels()` 常用模型清单是库内维护的静态快照，仅用于离线渲染下拉框与拉取失败兜底，最新可用模型请以 `listModels()` 的实时结果为准；
- Azure OpenAI 只覆盖了新版 `/openai/v1` 路由，旧版「部署名 + api-version」路由需自行用 `endpoint` 配置完整 URL；AWS Bedrock、Google Vertex AI 因需要 SigV4 / OAuth 签名，暂未内置；
- 自定义模型的 `supports()` 能力是乐观默认值（对方接口实际支持什么库无从得知），需要准确值时用 `features` 配置项自行声明；
- `Ai\Protocol\Gemini::convertMessages()` 未被调用——Gemini 走的是 OpenAI 兼容端点，消息直接透传；
- `chatBatch()` 并发批量不支持流式，也不走 `setAttachments()`（附件请写在各自 payload 里）；
- `cost()` 需自行传入价格表，库不内置各平台价格（价格变动频繁，内置必然过期）；
- `Ai\Cli\ClaudeCode` 依赖本机已安装 claude 程序；`proc_open` / `shell_exec` 被禁用的受限 PHP 环境需改用自定义执行器（如 SSH/SFTP）。

---

## 许可证

MIT License
