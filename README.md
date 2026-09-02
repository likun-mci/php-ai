# PHP AI 标准库

一个框架无关的 PHP AI 调用标准库，用统一接口屏蔽 **40 个国内外主流 AI 平台**在**鉴权方式、请求协议、返回格式、流式协议**上的差异——国内的通义千问、豆包、文心一言、智谱 GLM、Kimi、腾讯混元、讯飞星火、DeepSeek……，海外的 OpenAI、Claude、Gemini、Grok、Mistral……，以及 OpenRouter、硅基流动等聚合中转与 Ollama 等本地部署。切换平台只需换一个配置项。

除对话能力外，还内置了 **Agent 工具调用循环**、**AI 代码编辑协议**、**带 SSRF 防护的网页抓取工具**与 **Agent 长期记忆**，可直接用于构建后台 AI 助手、代码编辑助手、内容翻译等实际业务。

本文档中的示例均来自真实业务系统（一套 CodeIgniter 3 的建站系统）中的实际用法，非伪代码。

> 🌏 English documentation: [README-en.md](README-en.md)

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
- 🔌 **常驻双工会话**：`Ai\Cli\ClaudeCodeSession` 复刻官方 IDE 插件的进程模式，长驻进程多轮对话 + 工具权限实时回调 PHP 决策 + 优雅中断 + 处理过程中继续提需求
- 📝 **代码编辑协议**：结构化编辑上下文 + 可校验的编辑动作，支持规划/审核/自动执行三种模式
- 🛡️ **安全抓取**：`HttpFetch` 内置 SSRF、DNS rebinding、内网地址、协议逃逸防护
- 📄 **多模态附件**：图片等附件按各平台格式自动适配
- 🪶 **零硬依赖**：仅需 PHP 与 cURL，可 Composer 安装，也可单文件 autoload 引入

---

## 环境要求

| 项目 | 要求 |
|------|------|
| PHP | >= 7.1 |
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
    'search'       => false,         // 联网搜索，true 或细化数组，见「联网搜索」
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

### 进程行为：环境变量 / 信号 / 协程

以下三项对 `ClaudeCode` 与 `ClaudeCodeSession` 同时生效。

**子进程默认继承当前进程的环境变量**（`setInheritEnv(false)` 关闭）

`proc_open` 收到非 null 的 env 数组时会**整体替换**子进程环境，不是叠加。因此不继承时子进程连 `HOME` 都没有，claude 只能靠 `/etc/passwd` 兜底才找得到 `~/.claude` 下的登录凭据 —— 容器里 `/etc/passwd` 常常没有对应条目，表现就是"登录态莫名丢失"；`PATH` 也只剩 shell 的内置默认值，nvm 装的 node 不在其中。

继承是全量的：父进程若设了 `ANTHROPIC_API_KEY`、`CLAUDE_CODE_*` 之类变量，子进程一样读得到（可能改变 claude 的计费与行为）。需要干净环境时关掉继承，再用 `setEnv()` 精确给定。

**claude 直接取代 shell 进程**（`setExecReplace(false)` 关闭）

命令里给 claude 加了 `exec` 前缀。`proc_open` 传字符串走的是 `sh -c "<cmd>"`，实测 dash 不做 exec 优化，进程树是 `sh → claude` 两层，`proc_terminate()` 的信号只打到中间那层 sh —— 超时抛完异常、`kill()` 返回之后，claude 其实还在后台跑到把整轮写完，额度照烧。加上 `exec` 后信号直达 claude 本人。

只有一种情况需要关掉：自定义执行器拿到命令串后还要往**后面**接东西（如 `$cmd . '; echo done'`，exec 之后 shell 已被替换，后面的命令不会执行），或者调用方自己已经插过 `exec`（`exec exec cmd` 在 dash 下直接报 127）。

配套的 `setKillGrace(2)`：结束进程时先发 SIGTERM 并留出这段宽限期，让 claude 自己收尾、把 session 落盘（之后还能 `--resume`），期限内没退出才 SIGKILL。设 0 表示直接强杀。

**协程环境把内部等待换掉**（`setSleeper()`）

Swoole / Workerman 这类常驻协程环境里，库内部的 `usleep` 与 `stream_select` 会把整个 worker 钉死，同 worker 上的其它请求全部排队：

```php
$cli->setSleeper(function ($sec) { \Swoole\Coroutine::sleep($sec); });
```

设置后，本地执行的轮询间隔、双工会话的事件泵与关闭流程都改走它，会话类的 `stream_select` 一并改成"轮询 + 让出"，不再阻塞整个线程。

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
| 处理中继续提需求 | 不支持 | `post()` 轮内插入 |
| 事件循环 | 库内阻塞 | `tick()` 可由宿主驱动 |
| 受限 PHP 环境 | 支持自定义执行器（SSH） | 仅本地 `proc_open` |

**处理过程中继续提需求（非阻塞事件泵）**

`send()` 一直阻塞到本轮结束，所以"claude 正在跑的时候用户又提了个新需求"在它身上做不到 —— 调用方唯一的执行流正卡在等待里。`post()` 把"投递"与"等待"拆开，事件循环交给宿主自己驱动，对齐官方 IDE 插件里的交互：

```php
$s = ClaudeCodeSession::create(['workdir' => '/var/www'])
        ->setSleeper(function ($sec) { \Swoole\Coroutine::sleep($sec); });   // 协程环境必设

$s->post('把 src 下的注释补全');           // 立即返回，不等本轮结束

while ($clientAlive()) {
    while ($msg = $queue->pop()) {
        $s->post($msg);                     // 轮内轮外一样调：轮内 = 插入当前轮
    }

    $active = $s->tick($onEvent);           // 泵一批事件后立即返回

    if (!$active && ($res = $s->takeResult())) {
        $saveAnswer($res);                  // 本轮收口，进程留着等下一轮
    }
    $s->isTurnActive() ? $pause(0.02) : $pause(0.1);
}
$s->close();
```

| 方法 | 作用 |
|---|---|
| `post($text)` / `postMessage($blocks)` | 非阻塞投递一条用户消息，返回本地消息 ID |
| `tick($onEvent)` | 处理当前已可读的输出并派发事件，随即返回；返回值 = 本轮是否仍在进行 |
| `isTurnActive()` | 当前是否处在一轮之中（UI 据此决定输入框与停止按钮的状态） |
| `takeResult()` | 取走最近一轮的结果，取走即清空；结构与 `send()` 的返回完全一致 |

轮内插入的语义（claude CLI 2.1.207 实测）：

- 生效时机是**当前这次工具调用执行完之后**，不是立即打断正在跑的工具；
- 整轮仍然只产生**一个** `result` 事件，`num_turns` 累计 —— 按 result 落库的话，这一条记录对应的是"多条用户消息 + 一个回复"；
- 每轮 CLI 都会重发一个 `system/init` 事件（第二轮起几乎无耗时），`getInit()` 被覆盖是预期行为；
- 常驻双工比"每轮起一个进程 + `--resume`"每轮省掉 3~6 秒冷启动。

比 `send()` 多出两个事件：`posted`（本地已投递，`['id','content','injected']`，`injected` 标记是否为轮内插入）与 `delivered`（CLI 已收下并回显，`['id','event']`）。UI 上建议 `post()` 返回后先标"已排队"，收到 `delivered` 再改"已送达"。其余事件与 `send()` 完全一致。

`send()` 内部就是 `post()` + 循环 `tick()`，两套 API 可以混用，落库代码也可以共用。

**中断与运行时控制**

`interrupt()` 就是 UI 上的"停止"按钮 —— 非阻塞化之后它才真正好用（此前唯一的调用时机是 `send()` 的事件回调里）。它让 claude 自己收尾、落 session 记录，进程保活可继续下一轮；`kill()` 则是直接收掉进程（默认先 SIGTERM、宽限 2 秒再 SIGKILL，见 `setKillGrace()`），claude 没有收尾机会。

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

其余会话方法：`start()` / `isRunning()` / `close()` / `kill()` / `sendMessage($contentBlocks)` / `getInit()` / `getAvailableTools()` / `getCommand()`。`ClaudeCode` 的全部参数方法在会话类上同样可用（需在首次 `send()` 前设置），包括 `setSleeper()` / `setInheritEnv()` / `setExecReplace()` / `setKillGrace()`（见「进程行为」一节）。

### 环境要求

- 已安装 Claude Code CLI（`npm install -g @anthropic-ai/claude-code` 或原生安装）
- 本地执行需要 `proc_open`；`shell_exec` 仅用于路径兜底探测，缺失时自动跳过
- `ClaudeCodeSession` 需要 `proc_open` 的双向管道，不支持自定义执行器（协程环境用 `setSleeper()` 让出即可，不必接管进程）

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
composer compat             # PHP 7.1 兼容性扫描（PHPCompatibility）
composer check              # 上面三样一起跑
```

CI 会在 **PHP 7.1 / 7.2 / 7.4 / 8.0 / 8.2 / 8.4** 上跑同样的检查，并在 8.2 上额外跑一次静态分析
与 PHP 7.1 兼容性扫描。最低版本 7.1 是真的把整套测试跑一遍，不是只做语法检查。

> 库内所有公开方法都带完整的 phpdoc 类型（PHP 7.1 不支持类型化属性，类型只能写在注释里），
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

流式下 `getStopReason()` 与 `getToolCalls()` 同样可用：

```php
$resp = $ai->setStream(true)->chat(['messages' => $msgs, 'tools' => $toolDefs]);

$resp->getStopReason();   // end_turn / max_tokens / tool_use …（可据此判断是否被截断）
$resp->getToolCalls();    // 分片已自动重组，格式与非流式完全一致
```

> **自定义协议**可选实现四个流式钩子（都不实现也能正常流式，只是拿不到对应信息）：
>
> | 钩子 | 作用 |
> |------|------|
> | `parseStreamUsage(array $chunk): ?array` | 该帧的 token 用量，AI 层逐帧合并 |
> | `parseStreamError(array $chunk): ?string` | 该帧的错误信息，非空即抛异常 |
> | `parseStreamStopReason(array $chunk): ?string` | 该帧的结束原因（已归一） |
> | `parseStreamToolCalls(array $chunk): ?array` | 该帧的工具调用分片，返回 `[索引 => ['id'=>, 'name'=>, 'arguments'=>片段]]`，AI 层按索引拼接 |

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

`Ai\Agent\Agent` 实现完整的 agentic 循环：模型决定调用哪个工具 → 库执行工具 → 结果回填给模型 → 继续，直到模型给出最终答复或达到迭代上限。v2.0 起内部采用 `AgentRuntime` 架构，新增面向对象的工具接口与防死循环守卫。

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

**流式跑 Agent**：`setStream(true)` 后每轮正文实时吐给回调，工具调用照常工作——
库会把各平台分片下发的 `tool_calls` 重组回来（OpenAI 系按 `delta.tool_calls[].index`
拼 `arguments` 字符串，Anthropic 系按 `content_block_start` + `input_json_delta` 拼）。
适合聊天界面：用户能一边看模型说话，一边看它去调工具。

```php
$ai->setStreamCallback(function ($event) use ($response) {
    if ($event['type'] === 'stream_chunk' && $event['content'] !== null) {
        $response->write('data: ' . json_encode(['content' => $event['content']]) . "\n\n");
    }
});

(new Agent($ai))
    ->setStream(true)          // 默认关闭，与旧版本一致
    ->setTools($tools)
    ->run([['role' => 'user', 'content' => '北京天气怎么样']]);
```

Agent 只是**临时借用**你的 AI 实例：跑完会把 `setStream()` 恢复原状（异常路径也会），
不会影响后续无关的 `chat()`。

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
| `tool_error` | `name`、`message` | 工具执行出错（已回填给模型） |
| `tool_permission` | `name`、`input`、`request_id` | 需要用户授权 |
| `context_compact` | `tokens`、`messages` | 上下文压缩开始 |
| `context_compact_done` | `messages` | 压缩完成 |
| `done` | — | 正常结束 |
| `error` | `message` | 出错或达到最大迭代步数 |

每个事件统一携带以下字段，便于 SSE / WebSocket 断线重连：

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | `string` | 自增事件 ID（`evt_1_xxx`） |
| `session_id` | `string` | 会话 ID |
| `agent_id` | `string` | Agent 标识 |
| `turn_id` | `string` | 当前迭代轮次 |
| `timestamp` | `float` | Unix 时间戳（微秒精度） |

工具内部的细粒度事件（如 diff、进度）由各 `handler` 自行通过闭包发出，库不做假设。

### 执行结果（AgentResult）

`Agent::run()` 仍保持向后兼容返回 `void`，但通过 `$agent->getRuntime()->run()` 可获得完整的 `AgentResult` 对象：

```php
// 兼容旧版：直接取最终文本
$agent->run([['role' => 'user', 'content' => '北京天气']]);
echo $agent->lastText();

// 新版：通过 getRuntime() 获取结构化结果
$result = $agent->getRuntime()->run($messages);

echo $result->getText();           // 模型最终回答
echo $result->getStopReason();     // end_turn / max_iter / no_progress / ...
echo $result->getIterations();     // 迭代次数
print_r($result->getUsage());      // token 用量

if ($result->isDone()) {
    echo '正常结束';
} elseif ($result->isError()) {
    echo '异常停止：' . $result->getStopReason();
}
```

| 方法 | 返回 | 说明 |
|------|------|------|
| `getText()` | `string` | 模型最终自然语言回复 |
| `getStopReason()` | `string` | 停止原因（见 StopReason） |
| `getToolCalls()` | `array` | 本轮工具调用序列 |
| `getUsage()` | `array` | 累计 token 用量 |
| `getIterations()` | `int` | 实际迭代次数 |
| `isDone()` | `bool` | 是否正常结束 |
| `isError()` | `bool` | 是否异常停止 |

### AgentToolInterface：面向对象的工具定义

v2.0 起工具可以写成对象，实现 `Ai\Agent\Tool\AgentToolInterface`：

```php
use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;

class ReadFileTool implements AgentToolInterface
{
    public function name()        { return 'read_file'; }
    public function description() { return '读取工作区内的文件内容'; }
    public function schema()      { return ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]]; }
    public function execute(array $input, ToolContext $context): ToolResult
    {
        return ToolResult::success('文件内容');
    }
}

$agent->setTools([new ReadFileTool()]);
```

对象工具与旧格式闭包工具可混用：

```php
$agent->setTools([
    new ReadFileTool(),                    // 对象
    'get_weather' => [                     // 闭包（旧格式）
        'description'  => '查询天气',
        'input_schema' => [...],
        'handler'      => function (array $in) { return '晴'; },
    ],
]);
```

#### 并行安全标记

实现 `ParallelSafeToolInterface` 可标记工具为并行安全，只读工具（read_file / grep / glob）可并行执行：

```php
use Ai\Agent\Tool\ParallelSafeToolInterface;

class ReadFileTool implements AgentToolInterface, ParallelSafeToolInterface
{
    public function isParallelSafe() { return true; }
}
```

不实现此接口的工具默认按「不可并行」处理，安全优先。

### ClaudeCodeTools：内置工具工厂

`Ai\Agent\Tools\ClaudeCodeTools` 提供一键创建 Agent 完整内置工具集的能力，与 Claude Code CLI 默认工具集对齐：

```php
use Ai\Agent\Tools\ClaudeCodeTools;

// 全部工具：Read / Write / Edit / Glob / Grep / Bash
$agent->setTools(ClaudeCodeTools::all([
    'workdir' => '/var/www/project',
]));

// 只读工具集（适合 plan 模式）：Read / Glob / Grep
$agent->setTools(ClaudeCodeTools::readOnly([
    'workdir' => '/var/www/project',
]));
```

六个内置工具详情：

| 工具 | 说明 | 并行安全 |
|------|------|---------|
| `read_file` | 读取文件，支持 offset/limit 分页 | ✅ |
| `write_file` | 创建或覆盖文件，自动创建目录 | ❌ |
| `edit_file` | 精确局部替换，str_replace 语义 | ❌ |
| `glob` | 按 glob 模式匹配文件路径 | ✅ |
| `grep` | 按模式搜索文件内容 | ✅ |
| `bash` | 执行 shell 命令，超时自动终止 | ❌ |

所有文件工具都受 `PathSafety` 沙箱保护，无法逃逸 workdir 范围。

#### 输出截断：按字节切，但不切开字符

工具输出、命令回显、diff 正文都要限长，否则一条 `bash` 就能把上下文撑爆。但截断有个容易踩的坑：

```php
substr($output, 0, 1024);      // 按字节切 → 可能把一个汉字劈成两半
mb_substr($output, 0, 1024);   // 按字符切 → 中文实际放行 3072 字节，上限形同虚设
```

**劈开汉字的后果不是显示乱码那么轻**：非法 UTF-8 会让 `json_encode()` 返回 `false`，于是下一次模型请求根本发不出去，整个 Agent 运行中断——而这段坏字节是库自己截出来的。

所有内置工具都走 `Ai\Helpers\Text`，用 `mb_strcut()` 按字节切且不切开字符：

```php
use Ai\Helpers\Text;

Text::cutBytes($output, 1024);      // 限字节，不劈字符（工具输出、diff 用这个）
Text::cutChars($summary, 200);      // 限字符（给人看的摘要用这个）
Text::ellipsis($text, 200);         // 限字符并补省略号，未超长时不加
Text::isValidUtf8($text);           // 校验
Text::length($text);                // 字符数
```

自己写工具时，凡是会回填给模型的内容都该用 `cutBytes()` 而不是 `substr()`。

### 权限系统

Agent 内置 6 种权限模式，控制工具调用的放行策略：

| 模式 | 说明 |
|------|------|
| `manual` | 只读工具自动放行，危险工具（bash）询问用户 |
| `auto` | 全部自动放行 |
| `plan` | 仅允许只读工具，其余拒绝 |
| `accept_edits` | 允许文件修改，bash 等高风险操作询问 |
| `dont_ask` | 自动放行（不询问） |
| `bypass` | 全部放行（⚠️ 不安全，不要用于不可信输入） |

```php
$agent->setPermissionMode('plan');      // 只读模式
$agent->setPermissionMode('manual');    // 危险工具询问（默认）
$agent->setPermissionMode('bypass');    // 全部放行
```

#### 规则匹配

在模式基础上，可以用 `allowTool` / `denyTool` 添加细化规则，优先级：deny > allow > 模式默认：

```php
use Ai\Agent\Permission\PermissionManager;

$pm = new PermissionManager('manual');
$pm->allowTool('read_file');                           // 全部放行 read_file
$pm->allowTool('write_file', ['path' => '/var/www/*']); // 只允许写指定目录
$pm->denyTool('bash', ['command' => 'rm -rf *']);       // 拒绝危险命令

$agent->getRuntime()->setPermission($pm);
```

也可通过快捷方式设置：

```php
$agent->setPermissionMode('manual');
```

#### 用户授权流程

当权限管理器返回 `ask` 时，Agent 暂停并发出 `PERMISSION_DENIED` 停止信号，等待业务层通过 `approve()` / `deny()` 响应：

```php
$messages = [['role' => 'user', 'content' => '列出文件']];  // 业务层保存消息副本

$agent->onEvent(function ($event) {
    if ($event['type'] === 'tool_permission') {
        // 前端展示授权对话框
        echo "请求授权：{$event['name']}(" . json_encode($event['input']) . ")";
        $requestId = $event['request_id'];
    }
});

// 用 getRuntime()->run() 返回 AgentResult
$result = $agent->getRuntime()->run($messages);

// 在外部通过 approve() / deny() 恢复执行
if ($result->getStopReason() === 'permission_denied') {
    $requestId = $result->getExtra()['request_id'] ?? '';

    // 批准：传入之前保存的消息副本，Agent 继续执行
    $result = $agent->approve($requestId, $messages);
    // 或
    $result = $agent->deny($requestId, '不需要', $messages);
}
```

### 上下文压缩

Agent 自动检测上下文长度，超过阈值时用 AI 自身将历史消息压缩为摘要，避免 token 超限。压缩以 Agent Turn（一轮完整交互）为单位，保证 `tool_use` 与对应的 `tool_result` 永远不会被拆散：

```php
use Ai\Agent\Context\ContextManager;

$agent->getRuntime()->setContextManager(new ContextManager(
    $messages,
    [
        'maxTokens'  => 100000,   // 压缩阈值（默认 100k）
        'threshold'  => 0.8,      // 超过 80% 时触发
        'keepRecent' => 10,       // 保留最近 10 条消息
    ]
));
```

压缩过程自动触发 `context_compact` / `context_compact_done` 事件，前端可展示进度。

### 会话持久化

Agent 会话可保存到文件系统，支持跨请求恢复（适用于 PHP-FPM 场景）：

```php
use Ai\Agent\Session\FileSessionStore;
use Ai\Agent\Session\SessionManager;

$store = new FileSessionStore('/tmp/agent_sessions');

$agent
    ->setSessionId('user-abc-123')
    ->setSessionManager(new SessionManager($store));

$agent->run($messages);

// 下次请求恢复会话
$session = $store->load('user-abc-123');
if ($session) {
    $agent->run($session->getMessages());
}
```

会话状态流转：`running` → `paused`（等待授权）→ `running`（恢复）→ `completed` / `interrupted`。

### 子 Agent

通过 `SubAgentManager` 注册子 Agent，主 Agent 可通过 `spawn_agent` 工具派生子 Agent 执行独立任务，子 Agent 拥有隔离的上下文，不会导致主 Agent 上下文膨胀：

```php
use Ai\Agent\SubAgent\SubAgentManager;
use Ai\Agent\Tools\ReadFileTool;
use Ai\Agent\Tools\PathSafety;

$sam = new SubAgentManager($ai);
$sam->register('code-reviewer', [
    'description' => '审查代码质量',
    'prompt'      => '你是代码审查专家，找出安全漏洞和性能问题。',
    'tools'       => [new ReadFileTool(new PathSafety('/var/www'))],
    'max_iter'    => 10,
]);

$agent->getRuntime()->setSubAgentManager($sam);
```

子 Agent 权限继承自父 Agent，且不能超越父 Agent 的权限范围。

#### 后台模式（Background SubAgent）

`spawn_agent` 工具支持 `background` 参数。设为 `true` 时，主 Agent 不阻塞等待子 Agent 完成，立即返回 `task_id` 继续执行自己的任务：

```php
$sam->setBackgroundRunner(function ($task) {
    // 在 Swoole / Workerman 协程或队列 Worker 中异步执行
    return [
        'status'     => 'completed',
        'summary'    => '子任务完成',
        'iterations' => 5,
    ];
});
```

- 已注入 `backgroundRunner`（协程/队列环境）→ 非阻塞执行，工具立即返回 `task_id`
- 未注入 runner → 降级为同步执行（仍记录完整 transcript）

#### 子 Agent Transcript（P0-5）

每次子 Agent 运行的完整消息历史、迭代次数、停止原因、最终结果都被记录，与主 Agent 的 transcript 分离保存：

```php
// 获取某次运行的完整 transcript
$transcript = $sam->getTranscript('sub_1_...');
// $transcript = [
//     'task_id'    => 'sub_1_...',
//     'agent'      => 'code-reviewer',
//     'task'       => '审查 Auth.php',
//     'status'     => 'completed',
//     'reason'     => 'end_turn',
//     'summary'    => '发现了 3 个问题...',
//     'messages'   => [...],           // 完整消息历史
//     'iterations' => 8,
//     'duration_ms'=> 12500.3,
//     'created_at' => 1700000000,
// ];

// 查询最近运行记录
$recent = $sam->recentRuns(10);

// 获取结果摘要（不含完整消息历史，适合反馈给主 Agent）
$result = $sam->getResult('sub_1_...');
```

主 Agent 看到的只是 `spawn_agent` 工具返回的结构化摘要（含 `transcript_id`），完整 transcript 通过 `getTranscript()` 单独查询，不会导致主 Agent 上下文膨胀。

#### Worktree 隔离模式

`spawn_agent` 的 `isolation` 参数设为 `worktree` 时，子 Agent 在一个独立的 git worktree 中执行，改动不会碰到主工作区；执行完毕捕获 diff 并清理 worktree：

```php
$agent->setWorkdir('/var/www/project');   // 必须是 git 仓库根目录
// 模型调用：spawn_agent(agent="refactorer", task="重构 Auth.php", isolation="worktree")

$result = $sam->getResult('sub_1_...');
echo $result['diff'];        // 完整 unified diff，主工作区文件未被改动
echo $result['diff_stat'];   // ' src/Auth.php | 42 +++++-----'
```

适合让子 Agent 试探性地改代码：拿到 diff 后由主 Agent 或人工决定是否应用。工作目录不是 git 仓库时该次运行返回 `status = failed`、`reason = no_git_repo`，不会退化成直接改主工作区。

### 预算控制

`BudgetManager` 跟踪 token 用量与估算成本，超过预算自动停止：

```php
// 快捷方式
$agent->setMaxBudget(5.0);                          // 预算 5 美元
$agent->getRuntime()->setMaxTokens(500000);          // 或按 token 数限制

// 精细配置
use Ai\Agent\Budget\BudgetManager;

$bm = new BudgetManager([
    'maxBudget'  => 5.0,                              // 最大预算（美元）
    'maxTokens'  => 500000,                           // 最大 token 数
    'pricing'    => ['prompt' => 5.0, 'completion' => 25.0, 'cached' => 0.5],
    'perMillion' => true,                             // 按每百万 token 计（官网价）
]);

$agent->getRuntime()->setBudget($bm);
```

超出预算时 Agent 停止，停止原因为 `budget_exceeded`。

### 并行工具执行

当模型一次返回多个工具调用时，并行安全的工具（read_file / grep / glob）可同时执行，其余工具按顺序执行。默认关闭，需显式开启：

```php
$agent->setParallelTools(true);     // 启用并行
```

并行执行器默认按顺序执行（语义正确），在 Swoole / Workerman 协程环境可注入并行运行器实现真正并发：

```php
$agent->getRuntime()->setParallelRunner(function (array $tasks) {
    return \Swoole\Coroutine\parallel($tasks);
});
```

### 工具执行超时

一次工具调用（含重试等待）超过指定秒数后自动标记为超时，不再继续重试：

```php
$agent->setToolTimeout(60);               // 全局超时 60 秒
$agent->getRuntime()->setToolTimeout(30);  // 或通过 Runtime 设置
```

超时判断时间点在每次执行完成和每次重试等待前，因此不会在工具执行中途强行中断（同步 PHP 无法打断用户态代码），但能阻止后续重试。框架内置的 BashTool 自带超时终止能力，可与全局超时配合使用。

超时工具的 `StopReason` 为 `timeout`，结果中的 `error` 包含超时详情。

### 模型降级

主模型服务级错误时，Agent 自动按序切换降级模型，保持上下文和工具状态不变：

```php
$agent->setFallbackModels(['claude-sonnet', 'claude-haiku']);
```

降级发生后会发出 `model_fallback` 事件，包含当前使用的模型名与原始错误信息。所有降级模型均失败后，Agent 才以 `MODEL_ERROR` 停止。

### 验证管理（VerificationManager）

工具执行后自动运行验证命令（如 `php -l`），确保代码改动真实可用，而不是让模型"记得自己测试"。验证失败的错误信息会回填给模型，让它自行修复。

```php
$agent->setVerification([
    'edit_file'  => ['php -l {file}'],
    'write_file' => ['php -l {file}'],
    'test'       => ['vendor/bin/phpunit'],
]);
```

验证策略按工具名配置，支持多条命令；命令中的 `{file}` 占位符会替换为工具输入里的 `file_path`。验证通过 `exec()` 同步执行，退出码 0 视为通过，非 0 的错误输出回填给模型。

单独使用 `VerificationManager`：

```php
use Ai\Agent\Verification\VerificationManager;

$vm = new VerificationManager([
    'edit_file' => ['php -l {file}'],
]);
$results = $vm->verify('edit_file', ['file_path' => 'src/Auth.php']);
// $results[0]->isPassed()  => true / false
// $results[0]->getError()  => 错误信息
```

#### 验证器框架（VerifierInterface）

命令式的 `addRule()` 适合「跑一条命令看退出码」。需要解析工具输出、定位到具体文件行号时，实现 `VerifierInterface` 写一个验证器：

```php
use Ai\Agent\Verification\PhpSyntaxVerifier;

$verifier = new PhpSyntaxVerifier();
$verifier->supports('write_file');   // true——只处理写文件类工具

$result = $verifier->verify([
    'tool_name' => 'write_file',
    'file_path' => 'src/Auth.php',
]);

if (!$result->isPassed()) {
    echo $result->getVerifierName();   // 'php_syntax'
    print_r($result->getErrors());
    // [['file' => 'src/Auth.php', 'line' => 42, 'message' => 'syntax error, unexpected token "{"']]
}
```

`PhpSyntaxVerifier` 对写入的 `.php` 文件跑 `php -l`，并把 `Parse error / Fatal error` 输出解析成带行号的结构化错误。非 PHP 文件、文件不存在、验证器被禁用时直接返回通过，不阻断流程。

`VerificationResult` 同时保留原有的 `isPassed()` / `getCommand()` / `getOutput()` / `getError()`，新增 `getVerifierName()`、`getErrors()`、`addError()`、`toArray()`，旧代码不受影响。

#### 内置验证器

除 `PhpSyntaxVerifier` 外还有三个开箱即用的验证器，都实现 `VerifierInterface`：

| 验证器 | 名称 | 作用 |
|--------|------|------|
| `PhpSyntaxVerifier` | `php_syntax` | 对写入的 `.php` 跑 `php -l`，解析出带行号的语法错误 |
| `SecurityVerifier` | `security` | 扫描危险函数（`eval` / `exec` / `system` …）与硬编码凭据 |
| `UnitTestVerifier` | `unit_test` | 改动后跑测试命令，解析失败用例名 |
| `GitDiffVerifier` | `git_diff` | 统计改动规模，超出上限或碰了受保护路径即拦下 |

```php
use Ai\Agent\Verification\GitDiffVerifier;
use Ai\Agent\Verification\SecurityVerifier;
use Ai\Agent\Verification\UnitTestVerifier;

$agent->addVerifier(new SecurityVerifier());
$agent->addVerifier(new UnitTestVerifier([
    'command' => 'composer test',
    'workdir' => '/var/www/project',
]));
$agent->addVerifier(new GitDiffVerifier([
    'workdir'      => '/var/www/project',
    'maxFiles'     => 10,     // 一次改超过 10 个文件 → 拦下
    'maxLines'     => 500,    // 一次改超过 500 行 → 拦下
    'protectPaths' => ['composer.json', '.github/'],
]));
```

或者一次挂全部：

```php
$agent->useDefaultVerifiers([
    'test'     => 'composer test',      // 给了才挂 UnitTestVerifier
    'workdir'  => '/var/www/project',   // 给了才挂 GitDiffVerifier
    'maxFiles' => 10,
]);
```

`SecurityVerifier` 基于 `token_get_all()` 扫描，注释和字符串里的 `eval` 不会误报，
`$db->exec()` 这类方法调用也不算内置危险函数。确实需要某个函数时用 `allow()` 放行：

```php
$sec = new SecurityVerifier();
$sec->allow('exec');   // 比关掉整个验证器精确
```

`GitDiffVerifier` 的用途是给「放手让 Agent 改代码」加护栏——一次动了 40 个文件、
几千行，通常不是任务要求的，而是模型跑偏了。目录不是 git 仓库时直接放行，不阻断流程。

### 工作区管理（WorkspaceManager）

跟踪 Agent 当前工作区的 Git 状态，让模型了解 cwd、分支、已修改文件等。状态按需刷新（默认 5 秒缓存），不会每轮都跑 git 命令。

```php
$agent->setWorkspaceDir('/var/www/project');

// 或手动创建
use Ai\Agent\Workspace\WorkspaceManager;

$wm = new WorkspaceManager('/var/www/project');
$wm->refresh();
echo $wm->getBranch();        // 'main'
echo $wm->getProjectName();   // 'project'
print_r($wm->getModified());  // ['src/Auth.php']
```

工作区状态被格式化为 `<workspace>` 块，在每次迭代时自动注入系统提示词，让模型知道当前工作目录、分支、已修改文件等上下文。

**注意**：`setWorkdir()` 也会自动创建 `WorkspaceManager`（如果未显式设置），因此已有的 `setWorkdir()` 调用会自动获得工作区状态跟踪能力。

### 技能系统（Skills）

技能（Skill）是某项能力或工作流程的详细指令，按目录组织。默认只把「名称 + 描述」注入系统提示词（节省 Context），模型需要时通过 `use_skill` 工具加载完整正文。

```php
$agent->loadSkills('/path/to/skills');

// 或手动创建
use Ai\Agent\Skill\SkillManager;

$sm = new SkillManager();
$sm->register('deploy', [
    'description'  => '部署项目到生产环境',
    'content'      => "# 部署流程\n1. 构建...",
    'allowedTools' => ['Bash(git *)'],
]);
$agent->setSkillManager($sm);
```

#### SKILL.md 目录格式

Skills 目录约定为 `{dir}/{skill-name}/SKILL.md`，支持 frontmatter：

```markdown
---
name: deploy
description: 部署项目到生产环境
allowed-tools:
  - Bash(git *)
  - Bash(docker *)
---
# 部署流程

1. 执行构建
2. 上传到服务器
3. 重启服务
```

`use_skill` 工具被自动注册到 Agent 工具注册表，模型调用后加载完整技能正文，同时收集 `allowed-tools` 中的工具限制（不能突破全局权限）。

#### 按需发现与场景匹配（Skill 2.0）

技能多起来之后，`loadFromDir()` 会把每份正文都读进内存。`discover()` 只解析 frontmatter 登记技能，正文等模型真正 `use_skill` 时再读盘：

```php
$found = $agent->discoverSkills('/path/to/skills');   // ['wordpress', 'deploy', 'nginx']
```

frontmatter 多认两个字段：

```markdown
---
name: wordpress
description: WordPress 插件开发
files:
  - wp-content
  - "*.wp.php"
knowledge: |
  WordPress 通过 hooks（action / filter）扩展。
  插件入口在 wp-content/plugins/。
---
# WordPress 插件开发

（完整正文，模型 use_skill 后才加载）
```

- `knowledge` 是几行要点，随技能描述一起注入系统提示词（`<skill-knowledge>` 块），不是完整正文
- `files` 是触发该技能的文件通配符，用来按场景自动匹配

```php
// 按文件路径找匹配的技能
$sm->forFile('/var/www/wp-content/plugins/foo.php');   // ['wordpress' => SkillDefinition]

// 直接激活——省掉模型自己判断该不该 use_skill 这一步
$agent->activateSkillsForFile('/var/www/wp-content/plugins/foo.php');   // ['wordpress']

// 只加载正文、不激活（不合并 allowed-tools）
$sm->loadByName('wordpress');
```

**没配 `files` 的技能永远匹配不到**——按技能名去猜文件路径太容易误伤，宁可要求显式声明。

### 项目指令（InstructionManager）

加载项目级指令文件（CLAUDE.md / AGENTS.md），这些是项目必须遵守的长期规则，与 Skill 不同：

- **CLAUDE.md / AGENTS.md** = 项目必须遵守的长期规则
- **Skill** = 某项能力 / 工作流程
- **Tool** = 实际执行动作

```php
$agent->loadInstructions('/var/www/project');

// 或手动创建
use Ai\Agent\Instruction\InstructionManager;

$im = new InstructionManager();
$im->loadFromTree('/var/www/project');         // 加载 .claude/ .ai/ 根目录的指令
$im->loadFromDir('/var/www/project/src');      // 子目录级指令（优先级更高）
$agent->setInstructionManager($im);
```

加载优先级（后加载的优先级更高）：Global → Project → Subdirectory → Task。指令内容被格式化为 `<instructions>` 块注入系统提示词。

```php
// 自定义文件名
$im->setFilenames(['CLAUDE.md', 'AGENTS.md', '.ai/AGENTS.md']);
```

### MCP Runtime（McpManager）

MCP（Model Context Protocol）服务器管理，通过 stdio 子进程运行 MCP 服务器，将 MCP 工具自动注册到 Agent 工具注册表。

```php
$agent->setMcpServers([
    'filesystem' => [
        'command' => 'npx',
        'args'    => ['-y', '@modelcontextprotocol/server-fs', '/tmp'],
    ],
]);

// 或手动创建
use Ai\Agent\Mcp\McpManager;

$mm = new McpManager();
$mm->addServer('filesystem', 'npx', [
    '-y', '@modelcontextprotocol/server-fs', '/tmp',
]);
$agent->setMcpManager($mm);
```

每个 MCP 服务器通过独立的子进程运行，通过 JSON-RPC 2.0 over stdio 通信。工具名格式为 `{serverName}__{toolName}`，避免不同服务器间的工具名冲突。

```php
// 批量配置
$mm->addServers([
    'fs'   => ['command' => 'npx', 'args' => ['-y', '@modelcontextprotocol/server-fs', '/tmp']],
    'db'   => ['command' => 'npx', 'args' => ['-y', '@modelcontextprotocol/server-sqlite', './data']],
]);

// 关闭所有 MCP 服务器
$mm->shutdown();
```

#### 传输协议：stdio / HTTP / SSE / WebSocket

MCP 是 JSON-RPC 2.0 协议，传输方式可以换。本地工具走 stdio 子进程，远程服务走 HTTP，需要长连接走 WebSocket：

| 协议 | 传输方式 | 适用场景 |
|------|---------|---------|
| stdio | 子进程 stdin/stdout | 本地工具（文件系统、Git、数据库客户端） |
| HTTP / SSE | HTTP POST，响应可为 `text/event-stream` | 远程服务、团队共享的 MCP 服务器 |
| WebSocket | 长连接双向通信 | 需要服务端推送、或一次会话里连续调很多次工具 |

```php
$mm = new McpManager();

// stdio（默认）
$mm->registerServer('fs', ['command' => 'npx', 'args' => ['-y', '@modelcontextprotocol/server-fs', '/tmp']]);

// HTTP / SSE
$mm->registerServer('remote', [
    'transport' => 'http',
    'url'       => 'https://mcp.example.com/rpc',
    'headers'   => ['Authorization: Bearer xxx'],
]);

// WebSocket
$mm->registerServer('live', ['transport' => 'websocket', 'url' => 'wss://mcp.example.com/ws']);
```

**连接管理**——不再是「一次性全起、全关」：

```php
$mm->connect('remote');            // 单独连一个，失败返回 false 而不抛异常
$mm->isConnected('remote');        // true
$mm->discoverTools('remote');      // 动态发现工具列表，未连接时自动先连
$mm->disconnect('remote');         // 单独断开
print_r($mm->status());
// ['fs' => ['connected' => false, 'transport' => 'stdio'],
//  'remote' => ['connected' => true, 'transport' => 'http']]
echo $mm->getLastError();          // 最近一次连接失败的原因
```

连接失败返回 `false` 而不是抛异常——一个 MCP 服务器不可用不该让整个 Agent 停下来，具体原因从 `getLastError()` 取。

HTTP 传输会记住服务端返回的 `Mcp-Session-Id` 并自动带在后续请求上；SSE 响应里穿插的通知会被跳过，只取 id 对得上的那条。`wss://` 需要 openssl 扩展，CDP 与本地 `ws://` 不需要。

### 作用域记忆（MemoryManager）

分作用域的长期记忆管理，支持 `user` / `project` / `session` / `task` / `agent` 五个作用域，各有独立的记忆文件。注入系统提示词时按作用域顺序合并，让模型感知不同层级的记忆上下文。

```php
$agent->setMemoryDir('/tmp/agent_memory');

// 或手动创建
use Ai\Agent\Memory\MemoryManager;

$mm = new MemoryManager('/tmp/agent_memory');
$mm->remember('user', '用户喜欢 PHP');
$mm->remember('project', '项目使用 CodeIgniter 3');
$mm->remember('session', '当前正在修登录');
$mm->remember('task', '正在修改 Auth.php');
$mm->remember('agent', '上次尝试方案 A 失败');
$agent->setMemoryManager($mm);
```

记忆文件持久化在 `{baseDir}/{scope}.md`，支持追加、覆盖、清空、读取：

```php
$mm->read('user');        // 读取
$mm->write('user', '仅此一条');  // 覆盖
$mm->forget('user');      // 清空
$mm->clearAll();          // 清空全部作用域
$mm->forPrompt();         // 生成注入系统提示词的 <memory> 块
```

#### 记忆检索（MemoryRetriever）

`forPrompt()` 会把所有作用域的记忆整段注入。记忆攒多了之后这就成了负担：几千字的历史里
可能只有两行和当前任务有关，其余全在挤占上下文。检索器把记忆按行拆成条目，
只注入与当前任务最相关的几条：

```php
$retriever = $mm->retriever();

$hits = $retriever->retrieve('登录接口报 401');
// [['scope' => 'project', 'line' => 3, 'text' => '登录走 JWT，密钥在 config/jwt.php', 'score' => 62.5], ...]

echo $retriever->forPrompt('登录接口报 401');
// <memory-relevant query="登录接口报 401">
// [project] 登录走 JWT，密钥在 config/jwt.php
// </memory-relevant>

$mm->retrieve('登录 401');              // 等价快捷方法
$mm->forPromptRelevant('登录 401');     // 等价快捷方法
$retriever->search('JWT', 'project');   // 纯关键词搜索，不打分
```

设置了 `setGoal()` 的 Agent 会自动走检索注入；没设目标时退回注入全部记忆，行为与升级前一致。

相关性是纯本地计算，不调模型：英文按词、中文按二元组切分，命中越多、覆盖查询的比例越高分越高。
这套打分**认字面不认语义**——问「鉴权」匹配不到只写了「登录」的记忆。需要语义检索时换掉打分器：

```php
$retriever->setScorer(function ($query, $text) use ($ai) {
    return cosineSimilarity($ai->embed($query), $ai->embed($text)) * 100;
});
$retriever->setTopK(3)->setMinScore(20.0);
```

**压缩与过期**——长跑任务里记忆会无限增长，定期清理比等到超出 `maxInject` 被截断更可控：

```php
$retriever->compress('session', 20);   // 只保留最近 20 条，返回删除条数
$retriever->expire('agent', 30);       // 删除 30 天前的条目
```

`expire()` 只处理带日期前缀的条目（`[2026-09-02] ...`），没有日期的一律保留——
分不清写入时间就不该替用户决定它过期了。

### 检查点（CheckpointManager）

每轮迭代结束时自动保存检查点（Checkpoint），Runtime 崩溃后可从最新检查点恢复。每个检查点以 JSON 文件持久化，按任务 ID 分组。

```php
$agent->setCheckpointDir('/tmp/checkpoints');

// 或手动创建
use Ai\Agent\Checkpoint\CheckpointManager;

$cm = new CheckpointManager('/tmp/checkpoints');
$cm->save('task_1', 5, $messages, ['extra' => 'data']);
$latest = $cm->loadLatest('task_1');
echo $latest->getIteration();  // 5
```

默认保留最近 5 个检查点，超出的旧检查点自动清理。支持过期清理：

```php
// 自定义保留数
$cm = new CheckpointManager('/tmp/checkpoints', [
    'maxCheckpoints' => 10,
]);

// 清理超过 7 天的检查点
$cm->cleanExpired('task_1', 7);
```

### 崩溃恢复（Crash Recovery）

从崩溃中恢复——加载最新检查点，恢复消息上下文，继续执行。

```php
$agent->setCheckpointDir('/tmp/checkpoints');

$messages = $agent->recoverFromCrash('task_1');
if ($messages !== null) {
    // 恢复成功，继续执行
    $result = $agent->run($messages);
} else {
    // 无可用检查点，从头开始
    $agent->run([['role' => 'user', 'content' => '请检查项目']]);
}
```

`recoverFromCrash()` 内部调用 `AgentRuntime::recover()`，自动加载最新检查点、恢复迭代计数、设置任务 ID。异常时也会自动保存崩溃时的检查点，防止数据丢失。

#### 长任务恢复：不只是消息历史

小时级甚至天级的任务恢复回来，光有消息历史不够——得知道计划走到第几步、目标是什么，否则模型只能从消息里重新推断。检查点因此会一并保存运行时状态：

```php
$agent->setCheckpointDir('/var/data/checkpoints');
$agent->setPlanDir('/var/data/plans');
$plan = $agent->plan('迁移数据库', ['备份', '改表', '校验']);

$agent->run([['role' => 'user', 'content' => '开始迁移']]);
// 中途崩了……

// 新进程恢复
$runtime = $agent->getRuntime();
$messages = $runtime->recover('task_1');
echo $runtime->getGoal();                        // '迁移数据库' —— 已还原
echo $runtime->getPlan()->progress();            // 计划状态与各步骤状态原样还原
print_r($runtime->getLastCheckpoint()->getExtra()['workspace']);
// ['dir' => '/var/www/project', 'branch' => 'main', 'modified' => ['db/schema.sql']]
```

检查点里存的内容：消息历史、迭代计数、任务目标、执行计划快照（含每一步的状态）、崩溃时的工作区状态（目录 / 分支 / 已修改文件）、记忆目录。

**工作区不会被自动"恢复"**：检查点存的是崩溃那一刻工作区长什么样，而磁盘上的文件此刻可能已经不同了，照着旧快照改回去是危险操作。它作为信息交给你比对，要不要动由你决定。

### 任务队列（AgentQueue）

将任务（Task）+ 运行时（AgentRuntime）按入队顺序排队执行，适用于 PHP-FPM 下需要后台执行的场景。

```php
use Ai\Agent\Queue\AgentQueue;

$queue = new AgentQueue();
$task = $queue->dispatch('检查并修复项目', $runtime, $messages, 'sess_1');

// 逐个处理待执行任务
while ($queue->hasPending()) {
    $result = $queue->processNext();
    // $result->getText() 获取最终回复
}

// 或处理指定任务
$result = $queue->process('task_xxx');

// 任务控制
$queue->cancel('task_xxx');    // 取消
$queue->resume('task_xxx');    // 恢复暂停的任务

echo $task->getStatus();  // queued / running / completed / failed / cancelled
```

`AgentQueue` 内部使用 `TaskManager` 管理任务生命周期，自动创建或复用外部传入的 `TaskManager`。

### 执行计划（PlanManager）

面对复杂任务时，Agent 先把目标拆成有序步骤再动手，而不是「想一步做一步」。计划带状态、依赖与修订历史，可持久化到磁盘，崩溃后继续执行。

```php
use Ai\Agent\Planning\PlanManager;

$pm = new PlanManager('/var/data/plans');   // 传空字符串则只放内存

$plan = $pm->createPlan('为 Auth 模块补充单元测试', [
    'steps' => [
        '阅读 src/Auth.php 理清分支',
        '编写 tests/AuthTest.php',
        '运行 phpunit 并修复失败用例',
    ],
    'risks' => ['依赖外部 Redis，测试需要 mock'],
]);

$pm->start($plan->getId());
$step = $pm->getCurrentStep($plan->getId());   // PlanStep：第 1 步
$pm->completeStep($plan->getId(), $step->getId(), '已梳理出 4 个分支');
$pm->advance($plan->getId());                  // 推进到下一步

echo $plan->progress();    // 33（百分比）
echo $plan->toSummary();   // 注入系统提示词用的简明摘要
```

步骤状态为 `pending → running → completed / failed / skipped`；计划状态为 `pending → running → completed / failed`。

**按依赖图执行**——步骤可声明依赖，`PlanExecutor` 只执行依赖已满足的步骤：

```php
use Ai\Agent\Planning\PlanExecutor;

$executor = new PlanExecutor($pm, ['mode' => PlanExecutor::MODE_DEPENDENCY]);
$result = $executor->executeAll($plan->getId(), function ($step, $plan) {
    // 在这里让 Agent 真正执行这一步，返回字符串结果或抛异常表示失败
    return $agent->ask($step->getAction())->getText();
});
// $result = ['completed' => 2, 'failed' => 1, 'skipped' => 0, 'status' => 'failed']
```

**计划审查**——执行过程中偏离预期时，`PlanReview` 给出问题与建议，并可直接改计划：

```php
use Ai\Agent\Planning\PlanReview;

$review = new PlanReview($pm);
$r = $review->review($plan->getId());
// $r = ['status' => 'affected', 'progress' => 33, 'issues' => [...], 'suggestions' => [...], ...]

// 审查后追加/插入/移除步骤
$review->reviewAndAdjust($plan->getId(), [
    'append' => ['补充 README 中的测试说明'],
], '发现文档未同步');

$review->detectDependencyCycle($plan);   // 返回环上的步骤 ID，无环则为空数组
```

#### 接入 Agent 运行时

`PlanManager` 挂到 Agent 上之后，计划摘要会在每轮迭代注入系统提示词（`<plan>` 块），
模型据此知道整体走到第几步：

```php
$agent->setPlanDir('/var/data/plans');            // 可选，不设则只放内存
$plan = $agent->plan('重构支付模块', [
    '读懂现有 Pay.php',
    '拆出 PaymentGateway 接口',
    '跑测试确认行为不变',
]);

$agent->run([['role' => 'user', 'content' => '开始重构']]);
echo $agent->getPlan()->progress();   // 执行到哪一步了
```

`plan()` 同时把目标写进 `setGoal()`——反思和记忆检索都用这个目标。

计划可以随任务状态一起落盘，崩溃恢复后接着执行：

```php
$state = new \Ai\Agent\Task\TaskState(['goal' => '重构支付模块']);
$state->setPlan($plan);
file_put_contents('/var/data/task.json', $state->toJson());

// 恢复
$restored = \Ai\Agent\Task\TaskState::fromJson(file_get_contents('/var/data/task.json'));
$plan = $restored->restorePlan();   // Plan 对象，步骤状态原样还原
```

### 自我反思（ReflectionManager）

工具执行完不等于目标达成。`ReflectionManager` 在每轮工具结果回填后判断「目标是否真的完成」，未完成则给出下一步行动建议，形成「执行 → 检查 → 未完成 → 继续」的闭环。

```php
use Ai\Agent\Reflection\ReflectionManager;

$rm = new ReflectionManager(['maxRounds' => 5]);
$result = $rm->reflect($messages, '让 phpunit 全部通过');

if ($rm->shouldContinue($result)) {
    echo $result->getReason();       // '最近一次工具执行报错：Fatal error ...'
    echo $result->getNextAction();   // '分析上述错误并修复后重新运行测试'
    echo $result->toPrompt();        // 直接注入下一轮 prompt 的文本
}
```

默认使用基于规则的判断（检查工具结果里的错误标记、完成标记、迭代轮次）。需要模型驱动的反思时注入自定义策略：

```php
$rm->setStrategy(function (array $messages, $goal) use ($ai) {
    $verdict = $ai->ask("目标：{$goal}\n以上执行是否已完成？回答 DONE 或说明缺什么");
    return strpos($verdict, 'DONE') !== false
        ? \Ai\Agent\Reflection\ReflectionResult::completed('模型判定已完成')
        : \Ai\Agent\Reflection\ReflectionResult::continuing($verdict, '按上述说明继续');
});
```

`maxRounds` 是兜底：反思轮次达到上限后强制结束，避免模型在「还差一点」的判断里空转。

#### 接入 Agent 循环

挂到 Agent 上之后，反思发生在「模型不再调用工具、准备收工」的那一刻：目标没达成就把
下一步建议作为 user 消息回填，驱动模型继续干，而不是就此结束。

```php
$agent->enableReflection(['maxRounds' => 5]);
$agent->setGoal('让 composer test 全部通过');

$agent->onEvent(function ($e) {
    if ($e['type'] === 'reflection') {
        echo $e['success'] ? "反思：目标已达成\n" : "反思：{$e['reason']}\n";
    }
});

$agent->run([['role' => 'user', 'content' => '修一下失败的测试']]);
```

没调用 `enableReflection()` 时循环行为与升级前完全一致——不会平白多跑几轮。
`setGoal()` 未设置时，目标退回取首条用户消息。

### 开发者 API：三样东西就能开工

用户不需要理解 LoopController / ContextManager / PermissionManager / SubAgentManager——提供 AI、workdir、Prompt 三样东西即可：

```php
use Ai\Agent\Agent;

$agent = Agent::create($ai)
    ->workdir('/var/www/project')
    ->codeAgent();

$result = $agent->task('把这个项目的登录系统改造成支持 Google OAuth，并完成测试');

print_r($result->toContract());
// [
//   'status'        => 'completed',
//   'summary'       => '已接入 Google OAuth 并补充测试',
//   'files_changed' => ['src/Auth.php', 'tests/AuthTest.php'],
//   'tests'         => ['passed' => true],
//   'verification'  => ['passed' => true],
//   'artifacts'     => ['artifact://task_1/test-report.json'],
//   'cost' => 0.042, 'iterations' => 14, 'duration_ms' => 68210.5,
// ]
```

链式配置：

```php
$agent = Agent::create($ai)
    ->workdir(__DIR__)
    ->codeAgent(['test' => 'composer test'])
    ->tools(['my_tool' => $myTool])              // 追加工具（不覆盖已有的）
    ->skills('/path/to/skills')                  // 装载技能
    ->agents(['dba' => [                         // 注册自定义子 Agent
        'description' => '数据库结构与索引优化',
        'tools'       => ['read_file', 'bash'],  // 写工具名即可，从父工具集里挑
    ]]);

$result = $agent->task('修复登录 Bug');          // 编排层自己选策略
$handle = $agent->dispatch('扫描整个项目');      // 后台执行，立即返回 task_id
$agent->resume($handle['task_id']);              // 查后台句柄，或从检查点恢复
```

`AgentResult` 的结构化契约：`getStatus()` / `getSummary()` / `getFilesChanged()` / `getTests()` / `getVerification()` / `getArtifacts()` / `getSubtasks()` / `getCost()` / `getDuration()`，`toContract()` 一次拿全，可直接 JSON 返回给调用方。字段由调用方或 `WorkspaceSnapshot` 填入——`AgentResult` 自己不去扫工作区，那是 Workspace 的职责。

### 工具分组与按需发现

工具多了不该一股脑全发给模型：几十个工具定义塞进每一轮请求，既占 token 又让模型更难选对。

```php
$agent->toolGroups()->disable(\Ai\Agent\Tool\ToolGroup::DEPLOYMENT);   // 这次任务不许碰部署
$agent->toolGroups()->only([\Ai\Agent\Tool\ToolGroup::FILESYSTEM]);    // 只开文件操作
```

内置分组：filesystem / git / database / network / browser / cloud / testing / deployment。**没归过组的工具默认可用**——分组用来收窄，不该因为忘了归类就让工具消失。工具属于多个分组时，任一分组启用即可用。

按需发现则反过来：初始只给一小撮常用工具 + 一个 `search_tools`，模型需要别的能力时自己搜：

```php
$agent->useToolDiscovery(['read_file', 'grep', 'glob']);

// 模型调用：search_tools(query: "database")
//   → 找到 sql_query / db_schema，自动启用，之后可直接调用
```

搜索是纯本地的关键词匹配（工具名 + 描述），不调模型——为了省 token 而多花一次模型调用不划算。

### 分层权限策略

权限来自四个层面，关系是**取交集**而不是取并集，并且 **DENY 优先**：

```text
最终权限 = Global AND Agent AND Skill AND Task
```

```php
$policy = $agent->permissionPolicy();
$policy->layer('global')
    ->allow('Bash(git *)')
    ->deny('Bash(rm -rf *)')
    ->deny('Write(.env)');
$policy->layer('task')->allow('Bash(php *)');

$agent->applyPermissionPolicy();

$policy->check('Bash', 'git status');    // 'allow'
$policy->check('Bash', 'rm -rf /');      // 'deny'
$policy->check('Bash', 'curl x.com');    // 'ask' —— 没人明确允许
$policy->explain('Bash', 'rm -rf /');    // ['decision' => 'deny', 'layer' => 'global', 'rule' => 'Bash(rm -rf *)']
```

顺序不能反过来：如果允许下层放宽上层，那么一个被 Skill 声明允许的 `Bash(rm -rf *)` 就能绕过全局禁令。任务结束时 `clearLayer('task')`，层与层之间不该互相污染。

### 事件回放（EventLog）

前端断线重连时要的是「从我收到的最后一条之后继续发」，**只重发事件，不重新执行 Agent**——重跑会产生新的副作用（再改一遍文件、再跑一遍命令），而客户端要的只是补上漏掉的那几条。

```php
$log = $agent->eventLog('/var/data/events');   // 创建后自动接上事件回调

// 客户端带着 Last-Event-ID 重连
$missed = $log->sinceId($lastEventId);
echo EventLog::toSse($missed);                 // 直接输出 SSE 帧

$log->since(42);                // 按 sequence 补发
$log->ofTask('task_1');         // 某个任务的全部事件
$log->ofType('tool_call');      // 按类型过滤
$log->lastSequence();           // 当前最新序号
```

事件 ID 找不到时返回全部——宁可重发也不要漏发，客户端自己去重比丢消息强。不传目录则只在内存里；**断线重连场景必须配目录**，重连时进程可能已经换了。

### 调度与并发上限

```php
$scheduler = $agent->scheduler([
    'max_tasks'             => 50,
    'max_concurrent'        => 4,
    'max_subagents'         => 4,
    'max_background_agents' => 2,
    'max_parallel_tools'    => 8,
    'maxRetries'            => 2,
]);

$scheduler->submit('task_1', '扫描安全问题', AgentScheduler::PRIORITY_HIGH);
$scheduler->submit('task_2', '更新文档', AgentScheduler::PRIORITY_LOW);

$next = $scheduler->next();          // 'task_1' —— 高优先级先跑
$scheduler->start($next);
$scheduler->finish($next, false);    // 失败 → 自动重新入队（还有重试次数时）
```

优先级：critical / high / normal / low，同优先级按提交顺序。**一个 PHP 请求不该产生几十个 Agent**——每个子 Agent 都是独立的模型调用与上下文，失控的并发既烧钱又会把内存吃满，所以默认上限都很保守。

挂上 `TaskGraph` 之后只调度依赖已满足的任务。失败自动重试是因为瞬时故障（网络抖动、模型超时）重试一次多半就好了，让人手动重投没有意义。

### 模型路由与产物管理

让 explorer 用最强的模型去 grep 代码是浪费，让 coder 用最便宜的模型去改架构是省小钱花大钱：

```php
$router = $agent->modelRouter([
    'cheap'    => 'claude-haiku-4-5-20251001',
    'standard' => 'claude-sonnet-5',
    'premium'  => 'claude-opus-5',
]);

$router->route(['agent' => 'explorer']);                     // cheap
$router->route(['agent' => 'coder']);                        // premium
$router->route(['task' => '重构整个认证系统']);               // premium（复杂度高）
$router->route(['agent' => 'coder', 'budget_left' => 0.05]);  // cheap（预算见底，先把任务跑完）
```

**不配置模型名就不路由**：返回空串，调用方沿用当前模型。硬塞一个猜的模型名，换来的是运行时报「模型不存在」。

产物（测试报告、补丁、日志、分析结果）不该全文塞进上下文——一份 5000 行的报告扔进去，剩下的对话就没地方了：

```php
$artifacts = $agent->artifacts('/var/data/artifacts');

$ref = $artifacts->put('task_123', 'test-report.json', $reportJson);
// 'artifact://task_123/test-report.json'

$artifacts->preview($ref, 500);      // 只给模型看开头
$artifacts->get($ref);               // 需要细节时再取全文
$artifacts->listFor('task_123');     // 这个任务产出了什么
$artifacts->summarize('task_1', 'out.txt', $output, 5);
// '已保存到 artifact://task_1/out.txt（12.4 KB）：\nFAILURES!\n…'
```

产物名会做路径穿越防护，`../../../etc/passwd` 这类写法进不来。

### 编排层（AgentOrchestrator）

`AgentRuntime` 回答的是「怎么跑一轮循环」，编排层回答的是**「这活该怎么干」**——直接调工具、先拆计划、派给子 Agent、并行铺开，还是丢后台。

```php
$agent = (new Agent($ai))->setWorkdir('/var/www/project')->codeAgent();

$result = $agent->task('分析项目中的认证、支付、SEO');
// 自动识别成三路并行 → 派 explorer 分头调查 → 汇总摘要

echo $agent->orchestrator()->lastDecision()->toSummary();
// 策略：parallel —— 识别到 3 个互不相关的子任务
```

`codeAgent()` 一次装齐：内置工具、六个专职子 Agent、默认验证器、执行计划、反思、项目指令。之后一句 `task()` 就能开工。

七种策略：

| 策略 | 何时选中 | 典型任务 |
|---|---|---|
| `direct` | 范围明确 | 「读一下 README」 |
| `plan` | 范围大、含重构/迁移类动词、或带编号步骤 | 「重构整个用户认证系统」 |
| `delegate` | 与某个子 Agent 的职责匹配 | 「审查 Auth.php 的安全问题」→ reviewer |
| `parallel` | 识别到并列的多个子任务 | 「分析认证、支付、SEO」 |
| `background` | 描述里明确要求后台 | 「后台扫描整个项目」 |
| `ask_user` | 任务描述为空或无法判断 | —— |
| `verify` | 只是要确认既有改动 | 「跑一下测试确认没问题」 |

**决策一定会进事件流**（`strategy_decision` 事件，带 `reason`）。Agent 自主选策略之后，使用者必须能回答「它为什么这么干」，否则出了问题只能靠猜：

```php
$agent->onEvent(function ($e) {
    if ($e['type'] === 'strategy_decision') {
        echo "{$e['strategy']}：{$e['reason']}\n";
    }
});
```

决策与执行是分开的，可以先看再决定要不要照做：

```php
$decision = $agent->orchestrator()->decide('重构认证系统');
if ($decision->is(\Ai\Agent\Orchestrator\ExecutionStrategy::PLAN)) {
    $result = $agent->orchestrator()->execute('重构认证系统', $decision);
}
```

默认选择器是**基于规则**的，不额外调模型——多花一次模型调用去决定「要不要多花模型调用」不划算，而且规则版的决策可复现、出问题能查。拿不准时一律退回 `direct`：保守执行的代价是多跑几轮工具，错误委派的代价是一个子 Agent 带着错误上下文跑十几轮。

需要模型驱动时换掉它：

```php
$agent->orchestrator()->selector()->setResolver(function ($task, $context) use ($ai) {
    // 返回 StrategyDecision；返回 null 则退回规则判断
});

// 或直接关掉某类自动行为
$agent->orchestrator()->selector()->setAutoDelegate(false)->setAutoPlan(false);
```

**不挂子 Agent 与计划管理器时，所有策略都退回 `direct`**，跑出来跟直接调 `run()` 一样——编排层是加法，不改变既有行为。

### 内置子 Agent（BuiltinAgents）

六个开箱即用的专职角色。它们的价值主要不在提示词，而在**工具集是收窄的**：explorer 拿不到写文件的工具，所以它不可能在「调查代码」的过程中顺手改掉什么。这比在提示词里写「请不要修改代码」可靠得多。

| 角色 | 用途 | 工具 |
|---|---|---|
| `explorer` | 代码搜索、阅读、调查、依赖分析 | read_file / grep / glob |
| `planner` | 执行计划生成与任务拆解 | read_file / grep / glob |
| `coder` | 代码修改 | read_file / write_file / edit_file / grep / glob / bash |
| `tester` | 运行测试、分析失败 | read_file / grep / glob / bash |
| `reviewer` | 代码审查、安全审查 | read_file / grep / glob / bash |
| `debugger` | 错误分析、问题定位 | read_file / grep / glob / bash |

```php
use Ai\Agent\SubAgent\BuiltinAgents;

BuiltinAgents::registerAll($sam);                    // 六个全装
BuiltinAgents::register($sam, ['explorer', 'tester']); // 只装两个
BuiltinAgents::isReadOnly('explorer');               // true
```

### 子 Agent 完整配置

```php
$sam->register('reviewer', [
    'description'     => '代码审查与安全审查',   // 也是自动委派的匹配依据
    'prompt'          => '你是代码审查者……',
    'tools'           => [...],
    'disallowedTools' => ['write_file', 'edit_file'],
    'model'           => 'claude-sonnet-5',      // 该角色单独用的模型
    'permissionMode'  => 'manual',
    'maxTurns'        => 15,
    'skills'          => ['php-development'],
    'mcpServers'      => ['fs'],
    'hooks'           => $hooks,
    'memory'          => '/var/data/memory/reviewer',
    'background'      => false,
    'isolation'       => 'worktree',
]);
```

**子 Agent 的能力永远是父 Agent 的子集。** 这条是硬约束，不是建议：

```php
$sam->setParentTools($parentTools);   // 父 Agent 只有 read_file / grep / glob

$sam->register('greedy', ['tools' => ['read_file' => …, 'write_file' => …, 'bash' => …]]);
$sam->resolveTools($sam->get('greedy'));   // 只剩 read_file —— 父没有的拿不到
```

三步收窄，每一步都只会让工具变少：起点是子 Agent 声明的 `tools`（没声明则用父全集）→ 与父工具集求交 → 去掉 `disallowedTools`。`permissionMode` 同理，只能往严格方向调：`bypass` 的父 Agent 下面可以挂 `manual` 的子 Agent，反过来不行。

`model` 是临时切换再切回来的——AI 实例父子共享，子 Agent 跑完不该把父 Agent 的模型悄悄改掉。

定义里声明的 `background` / `isolation` 是**下限**：工具入参可以额外开启，但不能关闭。一个被配置成必须 worktree 隔离的子 Agent，不该因为模型没传参就直接改到父工作区。

### 任务依赖图（TaskGraph）

父子关系（`parentTaskId`）表达的是从属，依赖表达的是**顺序**——两个兄弟任务可以同属一个父任务，却一个必须等另一个跑完。这两件事必须分开表达，否则「B 与 C 并行、D 等 C」这种结构根本写不出来。

```php
use Ai\Agent\Task\TaskGraph;

$graph = new TaskGraph();
$graph->addTask('a')->addTask('b')->addTask('c')->addTask('d');
$graph->dependsOn('b', 'a');
$graph->dependsOn('c', 'a');
$graph->dependsOn('d', 'c');

$graph->ready();              // ['a']
$graph->markCompleted('a');
$graph->ready();              // ['b', 'c'] —— 可并行
$graph->markCompleted('c');
$graph->ready();              // ['b', 'd']

$graph->layers();             // 按依赖深度分层，同层可并行
$graph->dependentsOf('c');    // ['d'] —— 改 c 会影响谁
```

**失败会向下游传播成 `blocked`**：让一个注定跑不起来的任务留在队列里等，只会浪费一次调度。

```php
$graph->markFailed('x');
$graph->getStatus('y');       // 'blocked'（y 硬依赖 x）
$graph->blocked();            // ['y', 'z'] —— 传递阻塞
```

软依赖只约束顺序，上游失败也照跑：

```php
$graph->dependsOn('q', 'p', \Ai\Agent\Task\TaskDependency::TYPE_SOFT);
```

**会成环的依赖直接被拒绝**（`dependsOn()` 返回 `false`）——环意味着谁都跑不起来，建图时报错比运行时死锁好查得多。自依赖同理。

与 `TaskManager` 打通：

```php
$graph->syncFrom($taskManager);   // 同步任务与状态，图结构不变
```

### 后台与并行执行

PHP 没有内置事件循环，「后台执行」在不同部署环境里能做到的程度差别很大。本库把三档路径收在一处，并且**如实告知走的是哪一档**——悄悄退化成同步执行、却让调用方以为是异步的，比直接说做不到危险得多。

```php
$handle = $agent->dispatch('扫描整个项目的安全问题');
// ['task_id' => 'task_1_ab12cd34', 'status' => 'running', 'mode' => 'fork', 'background' => true]

$agent->taskStatus($handle['task_id']);
```

| 档位 | 条件 | 行为 |
|---|---|---|
| `runner` | 注入了 runner（Swoole / Workerman 协程、队列 Worker） | 真异步，立即返回 |
| `fork` | `pcntl_fork` 可用且未被 `disable_functions` 禁用 | fork 子进程，父进程立即返回 |
| `sync` | 都不可用 | 同步跑完再返回，但仍返回 task_id，状态机一致 |

```php
use Ai\Agent\Orchestrator\BackgroundDispatcher;

$dispatcher = new BackgroundDispatcher([
    'resultDir' => '/var/data/bg',      // fork 档必须配：子进程改的内存父进程看不到
    'runner'    => function (array $payload) { /* 投递到队列 */ return null; },
]);
$agent->orchestrator()->setDispatcher($dispatcher);
```

fork 档的结果只能靠落盘传回来，所以 `resultDir` 不配就取不到结果。runner 如果同步返回了结果，句柄会如实标成 `completed` 而不是假装还在跑。

**并行子 Agent** 同样是三档降级（`runner` / `fork` / `sequential`）：

```php
use Ai\Agent\Orchestrator\ParallelAgentExecutor;

$executor = new ParallelAgentExecutor($sam, ['maxConcurrency' => 4]);
$results = $executor->run([
    ['agent' => 'explorer', 'task' => '分析认证模块'],
    ['agent' => 'explorer', 'task' => '分析支付模块'],
    ['agent' => 'explorer', 'task' => '分析 SEO 相关代码'],
]);
echo $executor->mode();   // 'runner' | 'fork' | 'sequential'
```

顺序降级档的结果与并行档完全一致，只是不省时间。注入的 runner 返回条数对不上时会退回顺序执行——宁可慢，也不能把结果与任务错位对应。

`maxConcurrency` 默认 4：一个 PHP 请求不该产生几十个 Agent。

### 结果聚合（ResultAggregator）

并行派出去三个 explorer，回来三份几千字的报告。**主 Agent 默认只该收到摘要**，完整内容留在各自的 transcript 里按需查——否则并行省下的时间，全赔在被污染的上下文里了。

```php
$summary = $agent->orchestrator()->aggregator()->aggregate($results);

$summary['summary'];          // 合并摘要（给主 Agent 的就是这个）
$summary['findings'];         // 各路结论（已按长度截断）
$summary['files'];            // 提到的文件，去重
$summary['errors'];           // 未完成的那几路，带原因
$summary['recommendations'];  // 抽出来的建议
$summary['transcripts'];      // task_id 列表，要看细节按这个查
$summary['stats'];            // ['total' => 3, 'completed' => 2, 'failed' => 1]
```

默认是规则聚合（截断 + 归类 + 去重），不调模型。需要更好的摘要时注入模型摘要器——但那要多花一次调用，默认不替使用者做这个决定：

```php
$aggregator->setSummarizer(function (array $results) use ($ai) {
    return $ai->ask('把以下多路调查结果合并成一段摘要：' . json_encode($results));
});
```

摘要器抛异常时会退回规则拼接，不会因为摘要挂了把结果整个丢掉。

### 子 Agent transcript 落盘与续跑

```php
$sam->setTranscriptDir('/var/data/transcripts');

$runId = $sam->runSync('explorer', '调查登录流程');
// 另一个进程也读得到
$sam2->setTranscriptDir('/var/data/transcripts');
$sam2->getTranscript($runId);

// 子 Agent 跑到一半被截断（迭代上限、权限被拒）时接着干，而不是从头再来
$newRunId = $sam->resume($runId, '继续看看权限校验');
$sam->getTranscript($newRunId)['resumed_from'];   // 原 runId
```

不配 `transcriptDir` 时 transcript 只在内存里，进程结束即丢——后台任务与崩溃恢复都需要它能被另一个进程读到，所以长任务场景必须配。

### 验证闸门与完成判据

**不能因为模型说「完成了」就算完成。** 模型判断自己是否达成目标是出了名的乐观：测试还红着、计划还剩三步、上一次工具调用还在报错，它照样会说「已完成」。

```php
$agent->useVerificationGate();                      // 按任务类型自动选验证链
$agent->setCompletionCriteria([                     // 完成 = 一组可检查的条件
    \Ai\Agent\Orchestrator\CompletionCriteria::VERIFICATION_PASSED,
    \Ai\Agent\Orchestrator\CompletionCriteria::NO_PENDING_STEPS,
    \Ai\Agent\Orchestrator\CompletionCriteria::NO_PENDING_ERRORS,
]);

$result = $agent->task('修复登录 401');
$outcome = $agent->checkCompletion($result, '修复登录 401');

if (!$outcome['completed']) {
    echo $outcome['prompt'];
    // 任务尚未达成完成条件：
    // - 验证未通过
    // - 计划里还有 2 个步骤未完成：跑测试、更新文档
    $agent->ask($outcome['prompt']);   // 带着原因继续干
}
```

闸门按任务类型选不同的验证链——一套验证跑遍所有任务，要么太松（漏掉该验的）要么太紧（改个错别字也跑全量集成测试）：

| 任务类型 | 验证链 | 识别关键词 |
|---|---|---|
| `bug_fix` | 语法 → 单元测试 | 修复 / 报错 / bug / fix |
| `feature` | 语法 → 安全 → 单元测试 | 新增 / 实现 / 支持 / implement |
| `refactor` | 语法 → 测试 → 改动规模检查 | 重构 / 重写 / refactor |
| `security` | 语法 → **安全（必过）** → 测试 | 安全 / 漏洞 / 注入 / injection |
| `default` | 语法 + 安全 | 认不出来时用它 |

```php
use Ai\Agent\Verification\VerificationGate;
use Ai\Agent\Verification\VerificationPolicy;

$gate = new VerificationGate($vm, VerificationPolicy::security());
$outcome = $gate->check(['file_path' => 'src/Auth.php']);

$outcome['passed'];    // 闸门放行了吗
$outcome['failed'];    // 哪几步没过，required 标明是不是必过项
$outcome['skipped'];   // 策略里写了但没挂验证器的步骤
$outcome['prompt'];    // 可直接回填给模型的失败说明，带文件与行号
```

策略里写了但没挂对应验证器的步骤会被**跳过并记录**，不会让整条链卡住。`failFast` 默认开启：必过项一失败就停，不再白跑后面的。

四条内置判据：`verification_passed`（**没跑过验证 = 未满足**，说「没验证所以算通过」就等于没有这道闸门）、`no_pending_steps`、`no_pending_errors`、`model_claims_done`。也可以注册自定义判据：

```php
$criteria->addCriterion('has_changelog', function (array $context) {
    return ['met' => !empty($context['changelog']), 'reason' => '还没写 changelog'];
});
```

预设：`CompletionCriteria::lenient()`（只看有没有报错，适合没测试没计划的轻量任务）、`::strict()`（四条全要）。

### 任务交接（AgentHandoff）

Coder 改到一半发现是数据库结构的问题，把任务转给 DBA；后者处理完再转回来。**交接必须留痕**——否则一个任务在几个角色之间转了几圈之后，没人说得清它经历过什么。

```php
$handoff = $team->handoff('developer', 'dba', '慢查询定位到索引缺失', [
    'task_id'         => 'task_9',
    'context_summary' => '已定位到 UserRepo::findByEmail，全表扫描 12 万行',
]);

// DBA 处理完交回去
$team->handoffBack($handoff, '索引已补，慢查询从 3s 降到 20ms');

$team->handoffChain('task_9');   // ['developer → dba', 'dba → developer']
```

交接会自动向接手方投递一条 `handoff` 消息，对方下次被分派任务时，收件箱里就带着「谁交给我的、为什么、进展到哪」。

消息类型也补齐到九种：原有的 `task` / `bug` / `review` / `status` / `result`，新增 `request` / `response` / `error` / `handoff`。回应会自动带上 `reply_to`——一个角色同时问了三件事时，没有这个字段就分不清答复对应哪个问题：

```php
$req  = AgentMessage::request('coder', 'dba', '这张表有索引吗');
$resp = AgentMessage::respondTo($req, '没有，需要补');
$resp->getReplyTo();   // $req 的 ID
```

### 跨 Session 消息（SessionBus）

`AgentCommunication` 管的是一个团队内部、同一个进程里的消息。跨 Session 是另一回事：后台 Agent 在另一个进程（甚至另一次 PHP 请求）里跑完，得让主 Session 知道。**那条消息必须落盘才传得过去**——内存里的队列，另一个进程根本看不见。

```php
// 后台 Agent 那边
$bus = new \Ai\Agent\Session\SessionBus('/var/data/session-bus');
$bus->send('session_main', AgentMessage::status('background', '安全扫描完成，发现 3 个问题'));

// 主 Session 这边
$bus = $agent->sessionBus('/var/data/session-bus');
$bus->pendingCount('session_main');           // 1
echo $bus->toPrompt('session_main');          // <session-messages> 块，可直接注入
$bus->receive('session_main');                // 收完即清空
```

订阅回调只在**本进程内** `send()` 时触发——跨进程投递的消息，另一端得靠 `receive()` 主动取。PHP 没有常驻的进程间推送通道，这一点无法回避。

不传目录时退化成纯内存模式，只在同进程内可用；**后台任务通知必须配目录**，否则消息发出去没人收得到。

### 计划版本链

`modifyPlan()` 不再原地覆盖，而是产生新版本。**「原计划是什么、为什么改成现在这样」是排查 Agent 走偏的关键线索**，直接覆盖就查不到了。

```php
$plan = $pm->createPlan('迁移数据库', ['steps' => ['备份', '改表']]);
$plan->getVersionLabel();   // 'plan_v1'

$pm->modifyPlan($plan->getId(), ['append' => ['校验数据']], '发现漏了校验');
$pm->getPlan($plan->getId())->getVersion();   // 2

$pm->versions($plan->getId());          // 历史版本快照
$pm->getVersion($plan->getId(), 1);     // 取回 v1，仍然是两步
$pm->diffVersions($plan->getId(), 1, 2);
// ['added' => ['校验数据'], 'removed' => [], 'reason' => '发现漏了校验']
```

### 工作区快照（WorkspaceSnapshot）

任务开始拍一张，结束再拍一张，两张一比就知道这个任务到底改了什么——而不是听模型自己报「我改了 Auth.php」。模型漏报和多报都很常见，尤其在它中途改了主意又改回去的时候。

```php
use Ai\Agent\Workspace\WorkspaceSnapshot;

$before = WorkspaceSnapshot::capture('/var/www/project');
// …Agent 干活…
$after = WorkspaceSnapshot::capture('/var/www/project');

$diff = WorkspaceSnapshot::diff($before, $after);
$diff['added'];            // 新增的文件
$diff['modified'];         // 改动的文件
$diff['deleted'];          // 消失的文件
$diff['branch_changed'];   // 分支变了没有
$diff['content_changed'];  // diff 哈希变了没有
```

快照内容：cwd、分支、commit、已修改文件、未跟踪文件、工作区 diff 哈希。不是 git 仓库时快照仍然可用，只是 branch / commit / diff_hash 为空——能记的记下来，记不了的留空，不猜。

### Worktree 收尾：合入或丢弃

子 Agent 在隔离的 worktree 里改完、diff 拿回来了，接下来要么合入要么丢弃：

```php
$samW->mergeWorktreeRun($runId, true);   // 先试打（git apply --check）
$samW->mergeWorktreeRun($runId);         // 真打
// ['applied' => true, 'reason' => 'applied']

$samW->discardWorktreeRun($runId, '方案不对');   // 留痕：这份 diff 被看过并否决了
```

用 `git apply` 打补丁而不是 merge 分支——worktree 里的改动通常没提交，没有 commit 可合。

### Skill 生命周期与依赖

```php
$sm->onEvent(function ($e) { echo $e['type'], "\n"; });
// skill_discovered / skill_loaded / skill_activated / skill_deactivated

$sm->deactivate('deploy');   // 停用：后续轮次不再注入，allowed-tools 一并重算
```

SKILL.md 多认两个字段：

```markdown
---
name: deploy
required-tools:
  - bash
  - ssh
dependencies:
  - docker
---
```

```php
$sm->checkRequirements('deploy', ['bash']);
// ['satisfied' => false, 'missing' => ['ssh', 'skill:docker']]
```

`required_tools` 里的工具当前拿不到时，这个技能加载了也用不了——与其让模型按技能指示去调一个不存在的工具，不如提前说清楚。

### 指令就近发现

```php
$im->setProjectRoot('/var/www/project');
$im->discoverFor('/var/www/project/src/Admin/User.php');
// 依次加载：project/CLAUDE.md → project/src/AGENTS.md → project/src/Admin/AI.md
```

从文件所在目录向上找到项目根，沿途的指令文件按「远的在前、近的在后」加载——**离当前文件最近的规则优先级最高**，因为它最具体。识别 `CLAUDE.md` / `AGENTS.md` / `AI.md` / `.ai/AGENTS.md`。

`projectRoot` 是向上查找的边界：没有边界的话会一路找到文件系统根，把别的项目甚至用户主目录的规则也拉进来。已加载过的路径会跳过——同一份规则注入两遍不会让模型更遵守它，只会白占上下文。

### 记忆整理（MemoryConsolidator）

**不要让所有工具结果自动进记忆。** 那样记忆很快会变成一堆噪音：读过的每个文件、跑过的每条命令都在里面，真正重要的两三条反而被淹没。

```text
Events → Task Result → Reflection → Memory Candidate → Consolidation → Memory
```

```php
$consolidator = $agent->memoryConsolidator();

$consolidator->propose('project', '登录走 JWT，密钥在 config/jwt.php', ['confidence' => 0.9]);
$consolidator->proposeFromReflection($reflectionResult);   // 反思结论值得记
$consolidator->proposeFromResult($agentResult, 'task');    // 只取首段，不整段塞

$written = $consolidator->consolidate();   // 去重 + 滤低置信度 + 按置信度排序 + 截断
```

候选不会立刻写盘——`consolidate()` 之前它们只在内存里排队。这一步存在的意义就是「攒一批再筛」：单条判断谁重要很难，一批放一起比较就容易多了。

去重用检索器的相关性打分做近似判断，说的是同一件事就不重复写。敏感内容用筛选器挡掉：

```php
$consolidator->setFilter(function (array $candidate) {
    return strpos($candidate['content'], '密码') === false;
});
```

### 多角色团队（AgentTeam）

从「父 Agent 派生子 Agent」升级为「一组各司其职的角色协作」。区别在于成员是长期存在的：Developer 改完代码，Tester 拿到的是同一轮任务的上下文，Reviewer 能看到前两者的结论。

```php
use Ai\Agent\Team\AgentRole;

$team = $agent->team([
    AgentRole::developer(),
    AgentRole::tester(),
    AgentRole::reviewer(),
]);

// 单独分派
$result = $team->assign('developer', '实现登录接口');
echo $result['status'];   // 'completed'
echo $result['text'];

// 流水线：前一环的输出作为后一环的输入
$results = $team->pipeline('给 Auth 模块补测试', ['developer', 'tester', 'reviewer']);

echo $team->toSummary();
// [developer] 给 Auth 模块补测试（completed，4 轮）：已补充 3 个测试用例…
// [tester] …
```

内置五个角色：`manager` / `developer` / `tester` / `security` / `reviewer`，各自的系统提示词写明了职责边界——比如 Tester 的提示词明确「发现问题时给出复现步骤，不要自己去改实现代码」，否则多角色协作很容易退化成每个角色都在改代码。

自定义角色，并按角色收窄工具：

```php
$team->addMember(new AgentRole('dba', [
    'description' => '数据库结构与查询优化',
    'prompt'      => '你是 DBA，只负责表结构与索引，不改业务代码。',
    'tools'       => ['read_file', 'bash'],   // 只给这两个工具
    'maxIter'     => 8,
]));
```

成员共享团队统一的工具集与权限配置，但**各自持有独立的 AgentRuntime 与上下文**——这正是多角色的意义：Tester 的上下文里不该塞满 Developer 的思考过程。

某个成员抛异常时记为 `status = failed` 继续往下走，不会中断整条流水线——Reviewer 知道 Tester 挂了，比什么都不知道有用。

### Agent 间通信（AgentCommunication）

成员之间的消息在总线上投递与留存。纯文本传递会丢类型与来源，收到的一方分不清这是任务分派还是结果汇报，`type` 字段就是为此存在的。

```php
use Ai\Agent\Team\AgentMessage;

$team->send(AgentMessage::bug('tester', 'developer', 'AuthTest::testLogin 失败：期望 true 实际 false', [
    'file' => 'tests/AuthTest.php',
    'line' => 42,
]));

$team->broadcast('需求已冻结，不要再改接口签名');

$bus = $team->communication();
$bus->unreadCount('developer');            // 未读条数
$bus->peek('developer');                   // 看但不取走
$bus->inbox('developer');                  // 取走（标记已读）
$bus->history(AgentMessage::TYPE_BUG);     // 全量历史，可按类型过滤
$bus->between('tester', 'developer');      // 两个角色之间的往来
```

消息类型：`task`（任务分派）/ `bug`（缺陷反馈）/ `review`（审查意见）/ `status`（状态同步，默认广播）/ `result`（执行结果）。

**分派任务时收件箱里的未读消息会自动拼在任务描述前面**——上一环留给他的话，不该等他自己去问。全量历史留在 `history()` 里供事后复盘：多角色协作出问题时，光看最终结果判断不出是哪一环传歪了。

### 人工审批（ApprovalWorkflow）

AI 改完代码不直接算数：先提交审核（附 diff），等人批准才继续，驳回则带着理由退回去改。企业环境里这是硬要求——没有人签字的自动改动进不了生产。

```php
$workflow = $agent->enableApproval('/var/data/approvals');

// AI 侧：改完提交
$request = $agent->submitForApproval($diff, [
    'summary' => '修复登录 401',
    'files'   => ['src/Auth.php'],
]);

// 人工侧：另一个进程 / 后台页面
foreach ($workflow->getPendingRequests() as $req) {
    echo $req->toSummary();      // 摘要 + 涉及文件 + diff
}
$workflow->approve($request->getId(), '张三');
// 或：$workflow->reject($request->getId(), '缺少输入校验', '李四');

// AI 侧：拿结果
$status = $workflow->getStatus($request->getId());   // approved / rejected / pending_review / expired
if ($status === \Ai\Agent\Approval\ApprovalRequest::STATUS_REJECTED) {
    $agent->ask($workflow->getRequest($request->getId())->toRejectionPrompt());
}
```

**审批天然跨进程**：提交的是 Agent，批准的是人，中间可能隔几小时。所以传了目录时请求会落盘，`getStatus()` 每次都从磁盘重读——只看内存副本会一直看到 `pending`，Agent 崩了重启也能接着等。不传目录则只放内存，适合同进程内的交互式确认。

其它能力：

```php
$workflow->waitFor($id, 300);              // 阻塞等待，超时返回当前状态而不是无限期挂着
$workflow->onSubmit(function ($req) { … }); // 提交时发邮件 / 飞书 / 建工单
$workflow->purgeDecided();                  // 清掉已处理与已过期的
new ApprovalWorkflow('', ['ttl' => 3600]);  // 请求 1 小时后过期
new ApprovalWorkflow('', ['autoApprove' => true]);  // 本地调试用，自动过审
```

已处理或已过期的请求不能再批——审批结果只能出一次。一个三天前提的审批不该还能被批准，所以设了 `ttl` 的请求过期后状态直接变成 `expired`，不必等谁来清理。

### 浏览器工具（BrowserTool）

让 Agent 操作真实浏览器：打开页面、点击、填表、截图、提取内容。跟 HTTP 抓取的区别是这里跑的是 Chrome——JS 渲染出来的内容、登录后的状态、前端路由，抓 HTML 拿不到的这里都拿得到。

```php
use Ai\Agent\Tools\BrowserTool;

$agent->addTool(new BrowserTool(['headless' => true]));

// 模型调用：
//   browser(action: "open", url: "https://example.com")
//   browser(action: "wait", selector: "#results")
//   browser(action: "type", selector: "#q", text: "php")
//   browser(action: "click", selector: "button[type=submit]")
//   browser(action: "extract", selector: ".result")
//   browser(action: "screenshot", path: "shot.png", full_page: true)
//   browser(action: "close")
```

会话是复用的：一次 `open` 之后，后续 `click` / `type` 都在同一个页面上，登录态和页面状态都还在。

直接用 `BrowserSession`：

```php
use Ai\Agent\Tools\BrowserSession;

if (!BrowserSession::isAvailable()) {
    echo '本机没装 Chrome / Chromium';
}

$session = new BrowserSession(['headless' => true, 'timeout' => 30]);
$session->launch();
$session->navigate('https://example.com');
echo $session->title();
echo $session->text('h1');
print_r($session->extractAll('.item', 20));
$session->waitFor('#ready', 5000);
$session->evaluate('document.querySelectorAll("a").length');
$session->screenshot('/tmp/shot.png', true);
$session->close();
```

实现走 Chrome DevTools Protocol：`--remote-debugging-port` 起一个常驻 headless 实例，用 CDP 的 HTTP 端点拿页面目标，用 WebSocket 发命令。点击与输入通过页面内 JS 完成（输入后会派发 `input` / `change` 事件，否则 Vue / React 收不到值变化），不是模拟鼠标坐标——坐标点击遇到滚动和浮层就不准了。

**前置条件**：本机装了 Chrome / Chromium，且允许 `proc_open`。没装时工具返回明确的错误信息而不是抛异常——模型看到「没有浏览器」可以换个办法，看到崩溃则只能重试。CDP 走本地 `ws://`，不需要 openssl 扩展。

传入 `PathSafety` 后截图路径受工作区限制：

```php
$agent->addTool(new BrowserTool([], new \Ai\Agent\Tools\PathSafety('/var/www/project')));
```

### 代码理解（CodeAnalyzer）

Agent 改代码前得先看懂代码。`Ai\Code` 扫描项目建立类索引与两张关系图——谁调用了谁、谁依赖了谁——之后回答「改这个方法会影响谁」不用再 grep 整个项目。

```php
use Ai\Code\CodeAnalyzer;

$analyzer = new CodeAnalyzer();
$analyzer->scan('/var/www/project/src');

print_r($analyzer->stats());
// ['files' => 189, 'classes' => 189, 'methods' => 2107, 'callEdges' => 3604, 'dependencyEdges' => 422]

$analyzer->findCallers('login');                       // 谁调用了 login
$analyzer->findDependencies('App\Auth');               // Auth 依赖谁
$analyzer->findDependents('App\Auth', true);           // 谁（间接）依赖 Auth —— 改动影响面
$analyzer->findRelatedFiles('src/Auth.php');           // 该一起看的文件
$analyzer->findSymbol('login');                        // 符号定义在哪个文件第几行
```

`explain()` 生成一段可直接注入提示词的类说明，比把整个类的源码塞进上下文省得多，而且带上了单看源码看不到的「谁依赖我」：

```php
echo $analyzer->explain('App\Service\Auth');
// class App\Service\Auth extends App\Service\BaseAuth implements App\Contract\AuthInterface
//   file: src/Service/Auth.php:23
//   public function login($name, $password = ...): bool   # line 41
//   protected function verify($user, $password): bool     # line 47
//   依赖: App\Model\User, App\Support\Token
//   被依赖: App\Http\LoginController
//   继承链: App\Service\BaseAuth
//   子类: App\Service\AdminAuth
```

**单文件分析**——不建索引也能用：

```php
use Ai\Code\FileAnalyzer;

$analysis = (new FileAnalyzer())->analyze('src/Auth.php');
echo $analysis->getNamespace();                    // 'App\Service'
print_r($analysis->getImports());                  // ['User' => 'App\Model\User', ...]
$class = $analysis->getMainClass();
echo $class->getParent();                          // 父类完整名（已按 import 解析）
print_r($class->getMethods());                     // 方法名 => FunctionAnalysis
echo $class->getMethod('login')->getSignature();   // 'public function login($name, $password = ...): bool'
```

**两张图**——`CallGraph` 与 `DependencyGraph` 也能单独用：

```php
$analyzer->callGraph()->impactOf('App\Auth::login');      // 直接间接会调到 login 的函数
$analyzer->callGraph()->unreferenced();                   // 没有调用方的函数（候选死代码）
$analyzer->dependencyGraph()->detectCycles();             // 循环依赖
$analyzer->dependencyGraph()->mostDepended(10);           // 被依赖最多的类 —— 改它风险最高
$analyzer->dependencyGraph()->layers();                   // 按依赖深度分层
$analyzer->classAnalyzer()->allMethods('App\Auth', $analyzer->index());  // 含继承与 trait 的方法表
```

**精度说明**：解析基于 `token_get_all()`，不依赖任何第三方 parser。能认命名空间、import（含成组导入与别名）、继承实现、方法签名（参数类型、可空、可变参数、返回类型）、属性可见性、类常量、函数体内的调用。

认不了的：变量的实际类型。`$obj->save()` 拿不到 `$obj` 是什么类，图里记的是 `->save`，查 `save` 的调用方时所有类的同名方法会一起命中。**结果是「可能的调用方」，用来缩小排查范围可以，当作重构的唯一依据不行。** 动态调用（`$class::$method()`）与 `eval` 里的代码同理，静态扫描看不见。

### 代码索引工具（CodeIndexTool）

把 `Ai\Code\CodeAnalyzer` 的能力交给 Agent：**一次扫描，反复查询**。explorer 调查一个类时不必每次都 grep 全项目，也不必把整个类的源码读进上下文。

```php
$agent->addTool(new \Ai\Agent\Tools\CodeIndexTool('/var/www/project/src'));

// 模型调用：
//   code_index(action: "explain", target: "App\Auth")        类结构 + 继承链 + 谁依赖它
//   code_index(action: "callers", target: "login")           谁调用了它
//   code_index(action: "dependents", target: "App\Auth")     改动影响面
//   code_index(action: "related", target: "src/Auth.php")    该一起看的文件
//   code_index(action: "symbol", target: "login")            定义在哪一行
//   code_index(action: "stats")                              索引规模
//   code_index(action: "refresh")                            改过文件后重建索引
```

`codeAgent()` 会自动装上它，explorer / planner / reviewer / debugger 四个内置角色也都带着它——它们的活就是「先看懂再说」。

索引是惰性建立的（首次调用时扫描），之后常驻内存。**改过文件要 `refresh`**，否则查到的还是旧结构。

工具返回里会带上精度提醒：`$obj->save()` 拿不到接收者的真实类型，查 `callers` 时同名方法会一起命中。这是静态扫描的固有限制，结果是「可能的调用方」。

### 项目索引（RepositoryIndexer）

首次进入一个项目时扫一遍结构，认出框架、入口、控制器 / 模型 / 服务 / 配置在哪，存成 `project.index.json`。之后 Agent 直接读索引，不用每次都把项目结构重新摸一遍。

```php
use Ai\Agent\Code\RepositoryIndexer;

$indexer = new RepositoryIndexer();
$index = $indexer->ensureIndex('/var/www/project');   // 有且没过期就复用，否则重建

echo $index->getFramework();        // 'Laravel'
echo $index->getEntry();            // 'public/index.php'
print_r($index->getFiles('controllers'));
print_r($index->getNamespaces());   // ['App\' => 'app/']
echo $index->toSummary();           // 注入提示词的 <project> 块
```

框架识别先看 composer 依赖，再看目录特征，覆盖 Laravel / Symfony / CodeIgniter / ThinkPHP / Yii / CakePHP / Laminas / Slim / WordPress。**认不出来时返回空串而不是猜**——猜错会让模型按错误的约定去找文件，比不知道更糟。

索引过期的判据是 ttl（默认 1 天）或 composer.json 变新，不逐个文件比 mtime——大项目上那样太慢。文件分类是按路径与文件名的启发式判断（`app/Http/Controllers/Auth.php` → controllers），不读文件内容。

### 用户交互（AskUser）

当任务描述不明确、存在多个合理执行方案或涉及关键选择时，Agent 可以向用户提问，而不是自行猜测。这与 Permission 有本质区别：

- **Permission**：回答「能不能执行？」（权限检查）
- **AskUser**：回答「应该怎么做？」（Agent 主动向用户提问）

```php
use Ai\Agent\Interaction\UserInteractionManager;

$uim = new UserInteractionManager();

$agent
    ->setUserInteractionManager($uim)
    ->setTools([...]);

$result = $agent->getRuntime()->run($messages);

// 暂停后等待用户回答
if ($result->getStopReason() === 'waiting_user') {
    $questionId = $result->getExtra()['question_id'] ?? '';
    $answer = 'main';  // 用户的选择

    // 回答后恢复执行
    $result = $agent->answerUser($questionId, $answer, $messages);
    echo $result->getText();
}
```

Agent 会在需要时调用 `ask_user` 工具，暂停并等待用户回答。`answerUser()` 将答案回填给模型继续执行。

### 钩子系统（Hooks）

在 Agent 执行链的关键节点注入自定义逻辑，无需修改核心工具。钩子覆盖完整的生命周期：工具执行前后、模型调用前后、权限请求、任务、子 Agent、上下文压缩、会话启停。

```php
$agent
    // 工具执行前调用：返回 ToolResult 可短路执行（跳过实际工具）
    ->onBeforeTool(function ($name, $input, $ctx) {
        if ($name === 'bash') {
            // 记录所有 bash 命令到审计日志
            return null; // 返回 null 继续执行
        }
    })
    // 工具执行后调用：可修改/包装结果
    ->onAfterTool(function ($name, $result) {
        return $result;
    })
    // 模型调用前调用：可修改请求参数
    ->onBeforeModel(function ($messages, $tools) {
        // 注入额外的系统消息或过滤敏感数据
        return ['messages' => $messages, 'tools' => $tools];
    })
    // 模型调用后调用：可记录响应或注入额外数据
    ->onAfterModel(function ($resp) {
        return $resp;
    });
```

完整钩子及签名：

| 钩子 | 注册方法 | 签名 | 触发时机 |
|------|---------|------|---------|
| `before_tool` | `onBeforeTool` | `(string $name, array $input, ToolContext $ctx): ?ToolResult` | 工具执行前；返回 `ToolResult` 则短路，不执行实际工具 |
| `after_tool` | `onAfterTool` | `(string $name, ToolResult $result): ToolResult` | 工具执行后；可修改/包装结果 |
| `tool_error` | `onToolError` | `(string $name, ToolResult $result): void` | 工具执行出错后（或权限拒绝时） |
| `after_tool_batch` | `onAfterToolBatch` | `(array $results): array` | 一批工具全部执行完成后、下一次模型调用前；可统一审计/刷新状态 |
| `before_model` | `onBeforeModel` | `(array $messages, array $tools): array` | 模型调用前；返回 `['messages'=>..., 'tools'=>...]` 修改请求参数 |
| `after_model` | `onAfterModel` | `($response): $response` | 模型调用后；可修改/记录响应 |
| `permission_request` | `onPermissionRequest` | `(string $toolName, array $input, string $requestId): HookResult` | 权限请求创建时；返回 `HookResult` 可表达处理意见 |
| `task_start` | `onTaskStart` | `(string $taskId, string $goal): void` | 任务开始执行 |
| `task_complete` | `onTaskComplete` | `(string $taskId, string $result): void` | 任务正常完成 |
| `task_failed` | `onTaskFailed` | `(string $taskId, string $error): void` | 任务执行失败 |
| `subagent_start` | `onSubagentStart` | `(string $agentName, string $task): void` | 子 Agent 启动 |
| `subagent_stop` | `onSubagentStop` | `(string $agentName, string $result): void` | 子 Agent 结束 |
| `before_compact` | `onBeforeCompact` | `(int $tokenCount, int $messageCount): void` | 上下文压缩前 |
| `after_compact` | `onAfterCompact` | `(int $messageCount): void` | 上下文压缩后 |
| `agent_start` | `onAgentStart` | `(): void` | Agent 循环开始 |
| `agent_stop` | `onAgentStop` | `(string $stopReason): void` | Agent 循环结束（携带停止原因） |

`onTaskFailed` / `onAgentStart` / `onAgentStop` 三个钩子不在 `Agent` 的快捷方法里，需要直接注入钩子容器：

```php
use Ai\Agent\Hooks\AgentHooks;

$hooks = new AgentHooks();
$hooks->onTaskFailed(function ($taskId, $error) {
    log_message('error', "任务 {$taskId} 失败：{$error}");
});
$hooks->onAgentStart(function () {
    echo 'Agent 开始执行';
});
$hooks->onAgentStop(function ($stopReason) {
    echo "Agent 停止：{$stopReason}";
});

$agent->getRuntime()->setHooks($hooks);
```

#### HookResult：钩子统一返回值

`Ai\Agent\Hooks\HookResult` 是钩子的统一返回类型，支持五种动作：

| 动作 | 工厂方法 | 说明 |
|------|---------|------|
| `CONTINUE` | `HookResult::go()` | 继续执行（默认） |
| `ALLOW` | `HookResult::allow()` | 放行 |
| `DENY` | `HookResult::deny($reason)` | 拒绝，附理由 |
| `MODIFY` | `HookResult::modify($data)` | 修改输入后继续 |
| `STOP` | `HookResult::stop($reason)` | 停止 Agent |

```php
use Ai\Agent\Hooks\HookResult;

$hooks->onPermissionRequest(function ($toolName, $input, $requestId) {
    if ($toolName === 'bash' && strpos($input['command'], 'DROP TABLE') !== false) {
        return HookResult::deny('禁止执行 DROP TABLE');  // 拒绝并说明理由
    }
    return HookResult::go();                              // 继续默认流程
});
```

`HookResult` 提供 `getAction()` / `getReason()` / `getData()` 与 `isContinue()` / `isAllow()` / `isDeny()` / `isModify()` / `isStop()` 判断方法。

#### 执行顺序

钩子按固定顺序接入执行链：

```text
Model
 ↓
Tool Call
 ↓
before_tool（可短路）
 ↓
Permission
 ↓
Tool 执行
 ↓
tool_error（出错时）/ after_tool
 ↓
after_tool_batch（整批完成后）
 ↓
Tool Result 回填
 ↓
Model
```

注意：**钩子的放行不能绕过硬性权限拒绝**。权限优先级保持 `Deny 规则 → Permission Deny → Allow → Ask`，`before_tool` 只能短路「将要执行的工具」，不能解除权限系统已做出的拒绝。

### 任务系统（Task）

Agent 任务系统将「整个用户目标」与「每一轮模型交互」分离。一个 Task 包含多个 Turn，支持完整的生命周期管理。

核心组件：

- `AgentTask` — 任务值对象，包含 id、goal、status、parentTaskId、sessionId
- `TaskState` — 任务状态记录，包含 completed、pending、blocked、importantFacts、modifiedFiles、errors、subtasks
- `TaskManager` — 任务管理器，管理任务生命周期（queued → running → waiting_permission/waiting_user → paused → completed/failed/cancelled）
- `TaskStatus` — 状态枚举常量

```php
use Ai\Agent\Task\TaskManager;

$tm = new TaskManager();

// 创建任务
$task = $tm->create('修复登录问题', 'sess_abc');

// 通过 AgentRuntime 执行任务
$result = $tm->start($task->getId(), $runtime, $messages);

// 生命周期控制
$tm->pause($task->getId());
$tm->resume($task->getId());
$tm->cancel($task->getId());
```

#### TaskState 进度记录

TaskState 记录详细执行进度，适合注入 Context Compaction 后的系统提示词，让 Agent 在上下文压缩后仍知道任务状态：

```php
use Ai\Agent\Task\TaskState;

$state = new TaskState(['goal' => '修复登录问题']);
$state->addCompleted('找到 Auth.php');
$state->addPending('运行 PHPUnit');
$state->addModifiedFile('Auth.php');
$state->addImportantFact('session 过期时间设置错误');

// 生成进度摘要
echo $state->toSummary();
// # 任务状态
// 目标：修复登录问题
//
// ## 已完成
// - 找到 Auth.php
//
// ## 待处理
// - 运行 PHPUnit
//
// ## 修改的文件
// - Auth.php
// ...
```

#### 通过 Agent 注入 TaskManager

```php
$agent = (new Agent($ai))
    ->setSystem('助手')
    ->setTaskManager($tm)        // 注入任务管理器
    ->setTaskId($task->getId());  // 关联当前任务

$agent->run($messages);
// 任务完成后自动标记为 completed 或 failed
// 同时发出 task_start / task_complete / task_failed 事件
```

任务生命周期事件可通过 `onEvent()` 接收：

| 事件 | 说明 |
|------|------|
| `task_start` | 任务开始执行 |
| `task_complete` | 任务正常完成 |
| `task_failed` | 任务执行失败（含异常/错误） |

### 运行时架构

v2.0 内部结构：

```
Agent（对外 API）
  ├── setTools() / setSystem() / onEvent() / setMaxIter() / run()
  ├── setPermissionMode() / setSessionId() / setMaxBudget()
  ├── setFallbackModels() / setToolTimeout()
  ├── setTaskManager() / setTaskId()                   ← 任务系统
  ├── setUserInteractionManager()                      ← 用户交互
  ├── setVerification()                                ← 验证管理
  ├── setWorkspaceDir()                                ← 工作区管理
  ├── loadSkills() / setSkillManager()                 ← 技能系统
  ├── loadInstructions() / setInstructionManager()     ← 项目指令
  ├── setMcpServers() / setMcpManager()                ← MCP Runtime
  ├── setMemoryDir() / setMemoryManager()              ← 作用域记忆
  ├── setCheckpointDir() / recoverFromCrash()          ← 检查点 / 崩溃恢复
  ├── approve() / deny()                              ← 用户授权
  ├── answerUser()                                    ← 回答用户提问
  ├── onBeforeTool() / onAfterTool()                  ← 工具钩子
  ├── onToolError() / onAfterToolBatch()              ← 工具错误 & 批量后置钩子
  ├── onBeforeModel() / onAfterModel()                ← 模型钩子
  ├── onPermissionRequest()                           ← 权限钩子
  ├── onTaskStart() / onTaskComplete()                ← 任务钩子
  ├── onSubagentStart() / onSubagentStop()            ← 子 Agent 钩子
  ├── onBeforeCompact() / onAfterCompact()            ← 上下文压缩钩子
  └── getRuntime() ─────────────────────────────────→  AgentRuntime（执行引擎）
        ├── ToolRegistry（工具注册表）                  ← AgentToolInterface 注册
        ├── ToolExecutor（工具执行器）                  ← 重试 / 超时 / 输出截断
        ├── LoopController（自循环控制器）              ← 驱动主循环（含降级模型）
        ├── LoopGuard（防死循环守卫）                   ← 重复调用检测 + 结果级进展检测
        ├── ParallelToolExecutor（并行执行器）           ← 并行安全工具
        ├── PermissionManager（权限管理器）              ← 6 种模式 + 规则匹配
        ├── ContextManager（上下文管理器）               ← Turn-aware 自动压缩
        ├── SessionManager（会话管理器）                 ← 持久化 / 暂停 / 恢复
        ├── BudgetManager（预算管理器）                  ← token / 成本控制
        ├── VerificationManager（验证管理器）            ← 工具执行后自动验证
        ├── WorkspaceManager（工作区管理器）             ← Git 状态跟踪
        ├── SkillManager（技能管理器）                   ← 技能目录 + use_skill 工具
        ├── InstructionManager（项目指令管理器）          ← CLAUDE.md / AGENTS.md
        ├── McpManager（MCP 管理器）                    ← stdio JSON-RPC 工具
        ├── MemoryManager（记忆管理器）                  ← 分作用域长期记忆
        ├── CheckpointManager（检查点管理器）            ← 每轮 checkpoint 自动保存
        ├── SubAgentManager（子 Agent 管理器）           ← spawn_agent 工具
        ├── TaskManager（任务管理器）                   ← 任务生命周期（AgentTask / TaskState）
        ├── UserInteractionManager（用户交互管理器）      ← ask_user 工具
        └── AgentHooks（钩子系统）                      ← 完整生命周期钩子：before/after_tool、tool_error、
                                                         after_tool_batch、before/after_model、
                                                         permission_request、task_start/complete/failed、
                                                         subagent_start/stop、before/after_compact、
                                                         agent_start/stop
```

通过 `$agent->getRuntime()` 可访问全部内部组件，实现高级定制。

### 停止原因（StopReason）

Agent 停止时可通过 `$result->getStopReason()` 获取原因：

| 常量 | 值 | 说明 |
|------|------|------|
| `END_TURN` | `end_turn` | 模型给出最终回答，正常结束 |
| `MAX_ITER` | `max_iter` | 已达最大迭代次数上限 |
| `NO_PROGRESS` | `no_progress` | 连续重复调用同一工具，无进展 |
| `TOOL_ERROR` | `tool_error` | 工具执行出错 |
| `USER_CANCEL` | `user_cancel` | 用户取消 |
| `BUDGET_EXCEEDED` | `budget_exceeded` | 预算超限 |
| `TIMEOUT` | `timeout` | 超时 |
| `PERMISSION_DENIED` | `permission_denied` | 权限被拒绝或等待用户授权 |
| `WAITING_USER` | `waiting_user` | 等待用户回答问题（ask_user） |
| `MODEL_ERROR` | `model_error` | 模型返回错误 |

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

## 联网搜索

不少平台的模型能自己上网查资料再回答。各家的开关五花八门——有的是请求体顶层的一个布尔值，
有的要往 `tools` 里塞一个内置工具，还有的走插件系统。本库把它们归一成一个 `search` 配置：

```php
$ai = AI::create([
    'model'   => 'qwen-plus',
    'api_key' => 'sk-xxx',
    'search'  => true,          // 开启联网搜索
]);

echo $ai->chat('今天有哪些重要新闻？')->getContent();
```

换个平台只改 `model`，`search` 这行不动：

```php
$ai->setConfig(['model' => 'claude-sonnet-4-20250514', 'api_key' => 'sk-ant-xxx']);
$ai->setConfig(['model' => 'glm-4-plus',               'api_key' => 'xxx']);
```

### 细化配置

`search` 传数组可以调细节。省略 `enable` 即视为开启：

```php
$ai->setConfig([
    'search' => [
        'enable'          => true,
        'max_uses'        => 5,               // 单次请求最多搜几次
        'count'           => 10,              // 返回结果条数
        'query'           => 'PHP 8.5 新特性', // 强制指定搜索词，不指定则由模型自己拟
        'recency'         => 'week',          // 时效：hour / day / week / month / year
        'forced'          => true,            // 强制搜索，不让模型自行判断要不要搜
        'citation'        => true,            // 正文里带引用角标
        'sources'         => true,            // 返回搜索来源列表
        'allowed_domains' => ['wikipedia.org'], // 只搜这些域名
        'blocked_domains' => ['spam.com'],      // 不搜这些域名（与上一项互斥）
    ],
]);
```

**平台不支持的细项会被静默忽略**，不影响搜索本身开启。这是刻意的：统一层承诺的是
「搜索会开」，不是「每个细节都能在每个平台生效」。下表是各项的实际落点：

| 统一配置 | Claude | 通义千问 | 智谱 GLM | Kimi | 文心一言 | OpenRouter | Perplexity |
|---------|--------|---------|---------|------|---------|-----------|-----------|
| `max_uses` | `max_uses` | — | — | — | — | — | — |
| `count` | — | — | `count` | — | `search_number` | `max_results` | — |
| `query` | — | — | `search_query` | — | — | — | — |
| `recency` | — | — | `search_recency_filter` | — | — | — | `search_recency_filter` |
| `forced` | — | `forced_search` | `require_search` | — | — | — | — |
| `citation` | 总是开 | `enable_citation` | — | — | `enable_citation` | — | — |
| `sources` | — | `enable_source` | `search_result` | — | `enable_trace` | — | `return_related_questions` |
| `allowed_domains` | ✅ | — | 仅首个 | — | — | `include_domains` | ✅ |
| `blocked_domains` | ✅ | — | — | — | — | `exclude_domains` | 加 `-` 前缀 |

几处需要留意的差异：

- **Claude** 的引用是常开的，没有开关；`allowed_domains` 与 `blocked_domains`
  同时传会被平台判为 400，本库在发请求前就会拦下并报错。
- **智谱** 的 `search_domain_filter` 官方类型是字符串而非数组，多个域名只会取第一个。
- **智谱** 没有「一小时内」这一档，`recency => 'hour'` 会并到 `oneDay`。
- **Perplexity** 的 Sonar 系模型**本来就是联网的**，不存在开不开；`search` 在这里
  只用来传过滤条件。
- **Kimi** 的内置搜索走的是 tool_calls 流程——模型只生成搜索参数，需要客户端回填结果
  对话才会继续。所以它必须配合 Agent 循环使用，单发一次 `chat()` 只会拿到一个工具调用：

  ```php
  $ai->setConfig(['model' => 'kimi-k2-0905-preview', 'search' => true]);

  $agent = new \Ai\Agent\Agent($ai);                                  // ✅ 用 Agent
  $agent->run([['role' => 'user', 'content' => '今天有哪些重要新闻？']]);
  echo $agent->lastText();
  ```

### 哪些平台支持

```php
print_r(\Ai\Helpers\Protocols::withWebSearch());
// ['claude', 'qwen', 'ernie', 'zhipu', 'moonshot', 'perplexity', 'openrouter']

\Ai\Helpers\Protocols::supportsWebSearch('deepseek');   // false
```

**没列进来的平台，配了 `search` 会直接抛 `ConfigException`**，而不是静默忽略。
静默忽略在这里是最糟的选择：用户拿到的是一个「答得挺像样、但其实没上网」的回复，
内容陈旧却毫无征兆，往往要等到发现模型说的是去年的事才察觉。

需要特别说明的两个：

- **OpenAI** 的 Chat Completions 端点没有联网开关，联网搜索只在 Responses API
  或 `gpt-5-search-api` 这类专用搜索模型上提供。本库走的是 Chat Completions，
  所以 `openai` 协议不声明支持。
- **通义千问 / 智谱 / Kimi 的 Anthropic 兼容端点**（`qwen-anthropic` 等）同样不支持。
  那些网关只翻译 Anthropic 的**请求格式**，Anthropic 的 web_search 是 Anthropic 自己的
  服务端能力，不会随协议格式一起过来。要在这些平台上联网，请改用它们的 OpenAI 兼容协议。

### 平台私有参数：用 `extra_body`

统一配置只收各家都有的语义，平台独有的参数（通义的 `search_strategy`、
智谱的 `search_engine`、OpenRouter 的 `engine` 等）不进 `search`，用 `extra_body` 直接写：

```php
$ai->setConfig([
    'search'     => ['forced' => true],
    'extra_body' => ['search_options' => ['search_strategy' => 'max']],
]);
```

`extra_body` 在请求体顶层做合并，**同名字段会整体覆盖** `search` 生成的结果——
上例中最终发出的 `search_options` 只有 `search_strategy`，`forced_search` 会被顶掉。
要同时用两者，把所有子字段都写进 `extra_body`。

库对某平台的判断有误或过时时，`extra_body` 也是逃生口：它绕过全部声明检查，
可以直接发平台原生的搜索参数。

### 与 `Ai\Tools\HttpFetch` 的区别

两者都能让模型用上网页内容，但不是一回事：

| | `search` 配置 | `Ai\Tools\HttpFetch`（见下一节） |
|---|---|---|
| 谁在联网 | 平台的服务器 | 你的 PHP 进程 |
| 计费 | 平台按次收搜索费 | 只有 token 费用，流量走你的服务器 |
| 能力 | 搜索引擎检索 | 抓取你指定的 URL |
| 可控性 | 只能给过滤条件 | 完全可控，含 SSRF 防护 |
| 支持范围 | 仅上表 7 个平台 | 所有平台 |

需要「模型自己决定搜什么」用 `search`；需要「读这几个我指定的页面」用 `HttpFetch`。
两者可以同时开。

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

## 扩展能力：图像 / 语音 / 视频 / 向量

> **当前进度**
>
> | 能力 | 状态 |
> |------|------|
> | 文本向量化 `embeddings()` | ✅ v1.15.0，35 个平台 |
> | 图像生成 `images()` | ✅ v1.16.0 同步 / v1.20.0 异步与编辑 |
> | 语音合成 / 识别 `audio()` | ✅ v1.17.0，二进制与 JSON-hex 两种形态已归一 |
> | WebSocket `realtime()` | ✅ v1.18.0，讯飞语音（RFC 6455 纯 PHP 实现） |
> | 视频生成 `video()` | ✅ v1.19.0，4 个平台异步任务已归一 |
>
> 未交付的能力调用时会抛 `UnsupportedCapabilityException` 并说明原因，
> 不会静默返回空结果。对话（`chat()`）功能完全不受影响。

### 入口

对话之外的能力都走子门面，与 `chat()` 共享同一份配置：
`setProxy()` / `setRetry()` / `setTimeout()` / `setLogger()` 配一次，各能力自动生效。

```php
$ai = new AI(['api_key' => '...', 'model' => '...']);

$ai->embeddings();   // 文本向量化
$ai->images();       // 图像生成
$ai->audio();        // 语音合成 / 识别（HTTP）
$ai->video();        // 视频生成（异步任务）
$ai->realtime();     // WebSocket 通道，默认关闭
```

### 先判断再调用

```php
use Ai\Helpers\Capabilities;

if ($ai->supports(Capabilities::IMAGE)) {
    $img = $ai->images()->generate('一只在看书的猫', ['size' => '1024x1024']);
    $paths = $img->saveTo('/var/www/uploads');   // 返回实际写入的绝对路径
}

$ai->capabilities();   // 当前模型支持的能力清单，如 ['embedding', 'image']
```

不判断直接调也可以，不支持时会抛出带原因的异常，而不是返回空值让你猜：

```
当前模型使用的协议不支持「图像生成」能力。本协议目前只支持对话（chat）
```

### 文本向量化（已可用）

```php
$ai = new AI(['api_key' => '...', 'model' => 'text-embedding-3-small']);

// 单条
$vec = $ai->embeddings()->create('这是一段文本')->getVector(0);

// 批量：返回顺序**始终**与输入顺序一致
$res = $ai->embeddings()->create(['第一段', '第二段', '第三段']);
$res->getVectors();      // [[...], [...], [...]]
$res->getVector(1);      // 第二段的向量
$res->getDimensions();   // 1536
count($res);             // 3
$res->getUsage();        // ['prompt_tokens' => .., 'total_tokens' => ..]
```

**顺序一致是有代价换来的**：多数平台不保证响应里 `data` 数组的顺序与输入一致，
库会按每条返回的 `index` 归位。这类错位不会报任何错，只会让后续检索莫名其妙地不准。

#### 平台参数直接透传

```php
$ai->embeddings()->create($texts, [
    'dimensions'      => 512,      // OpenAI text-embedding-3-* 支持降维
    'encoding_format' => 'base64', // 返回 base64 时库会自动解回 float 数组
]);
```

只归一 `model` / `input` 两个字段，其余原样透传给平台。
`dimensions` 这类参数各平台支持度不同，以对方文档为准——库不替你猜，
不支持的平台会直接返回它自己的错误信息。

#### 超长批量自动分批

各平台单次可提交的条数上限不同（OpenAI 上千条，部分平台只有一二十条），
且官方文档未必写明。默认**不分批**（一次发完，不无谓拆分请求），
需要时用 `batch_size` 指定：

```php
$res = $ai->embeddings()->create($tenThousandTexts, ['batch_size' => 25]);
// 自动分成 400 个请求，结果按原顺序合并，usage 逐批累加
```

分批时若某一批返回的向量数与提交的文本数对不上，会**当场抛异常**而不是继续——
数量对不上意味着后面所有下标都会错位，静默继续比失败危险得多。

#### 支持情况

31 个协议声明支持向量化，路径由各自的对话路径同级推导
（`/v1/chat/completions` → `/v1/embeddings`，`/v4/chat/completions` → `/v4/embeddings`），
所以带前缀的网关、Azure、Gemini 兼容端点都自动正确。

Anthropic Messages 协议家族（`claude` / `qwen-anthropic` / `zhipu-anthropic` /
`moonshot-anthropic` / `deepseek-anthropic`）**不支持**——Anthropic 没有向量化接口。
用这些协议调用会得到明确报错。

> 平台审计（v1.21.0 ~ v1.24.0）已逐个核对官方文档与端点实测。
> DeepSeek、商汤、Llama、Perplexity、Cerebras 经确认**没有**向量化接口，不再声明。
> 若你确知某平台可用，配 `embedding_endpoint` 即可绕过库的判断。

### 图像生成（已可用）

```php
$ai = new AI(['api_key' => '...', 'model' => 'gpt-image-1']);

$img = $ai->images()->generate('一只在看书的猫', ['size' => '1024x1024', 'n' => 2]);

$img->getUrls();            // ['https://...', 'https://...']
$img->getBase64();          // 平台返回 base64 时在这里
$img->getRevisedPrompt();   // 部分平台会改写提示词，原样回传
count($img);                // 2

// ⚠️ 及时落地：各平台的图片 URL 都有有效期
$paths = $img->saveTo('/var/www/uploads', 'cat');
// ['/var/www/uploads/cat_1.png', '/var/www/uploads/cat_2.png']
```

**URL 有效期是这里最容易踩的坑**：万相约 24 小时、硅基流动**只有 1 小时**。
把 URL 存进数据库，用户过一阵回来看就全是坏图。要长期保留必须调 `saveTo()` 落盘。

`saveTo()` 的目录**必须已存在**，不会自动创建——多模态接口常在循环里落盘，
路径拼错时自动 `mkdir` 会在磁盘上散落一堆空目录，等发现时已经很难收拾。
下载走库内的 `HttpFetch`（带完整 SSRF 防护），不是裸 `file_get_contents()`。

#### 各平台字段差异已归一

图像接口远没有对话那么统一。下面这些差异（据各平台官方文档 2026-08 核对）
库内已经处理掉，**调用方在所有平台上写法一致**：

| 平台 | 实际字段 | 你仍然写 |
|------|---------|---------|
| 硅基流动 | `image_size` / `batch_size`，响应是 `images[]` | `size` / `n` |
| xAI | `aspect_ratio` + `resolution`，没有 `size` | `size`（自动换算成最接近的比例档） |
| 火山方舟（豆包） | `response_format: "base64"` | `response_format: "b64_json"` |
| OpenAI `gpt-image-*` | 只返回 `b64_json`，不支持 `url` | 取 `getUrls()` 或 `getBase64()` 都行 |

平台私有参数（`seed`、`guidance_scale`、`watermark`、`negative_prompt` 等）原样透传，
不做映射——把所有平台的所有参数都归一，归一层会厚到没法维护。

#### 支持的平台与模型

模型清单**据各平台官方文档**登记（不是靠端点探测猜的）：

| 平台 | 模型 |
|------|------|
| OpenAI | `gpt-image-1.5`、`gpt-image-1`、`gpt-image-1-mini`、`dall-e-3`、`dall-e-2` |
| **Gemini** | `gemini-3.1-flash-image`、`gemini-3.1-flash-lite-image`、`gemini-3-pro-image`、`gemini-2.5-flash-image` |
| **讯飞星火** | 走独立的 TTI 接口（`maas-api.cn-huabei-1.xf-yun.com/v2.1/tti`），需 `app_id` |
| 智谱 | `glm-image`、`cogview-4-250304`、`cogview-4`、`cogview-3-flash` |
| xAI | `grok-imagine-image-quality`、`grok-imagine-image-2.0` |
| 硅基流动 | `Kwai-Kolors/Kolors`、`Qwen/Qwen-Image-Edit`、`Qwen/Qwen-Image-Edit-2509` |
| 阶跃星辰 | `step-1x-medium` |
| 火山方舟（豆包） | `doubao-seedream-5.0-lite`、`doubao-seedream-4.5`、`doubao-seedream-4.0`、`doubao-seedream-3.0-t2i` |
| 通义万相 | 异步任务式，见下节 |

```php
$ai->images();  // 子门面
(new \Ai\Protocol\Zhipu())->knownImageModels();   // 取某协议的图像模型清单
```

**通义（qwen）走异步接口**：其 OpenAI 兼容模式没有同步文生图（实测 404），
万相是「提交任务再轮询」，用 `generateAsync()`，见下节。

> 某个平台是否真的开通了这个接口以对方文档为准——未开通时你会收到该平台自己的 404，
> 而不是库的猜测。库的判断有误或过时时，配 `image_endpoint` 即可绕过。

#### 异步文生图（通义万相）

通义万相的文生图是**提交任务再轮询**，一次请求拿不到图。这类平台上
`generate()` 会明确报错并指向 `generateAsync()`——返回一个「成功但没有图」的
响应是最坏的做法，调用方拿到空结果完全不知道去哪找原因。

```php
$ai = AI::create(['protocol' => 'qwen', 'model' => 'wan2.2-t2i-flash', 'api_key' => '...']);

$task = $ai->images()->generateAsync('一只在看书的猫', ['size' => '1024x1024', 'n' => 2]);
$db->save(['task' => json_encode($task->toArray())]);

// ……稍后
$task = AsyncTask::fromArray(json_decode($row['task'], true), $ai);
if ($task->refresh()->isSucceeded()) {
    $task->getResult()->saveTo('/var/www/uploads');
}
```

和视频任务共用同一套 `AsyncTask`：不阻塞、可跨请求恢复、超时不算失败。

> 库内会把统一写法的 `size: "1024x1024"` 转成万相要的 `"1024*1024"`（星号）。
> 分隔符传错不会被容错，平台直接判为非法参数。

#### 图像编辑（图生图 / 局部重绘）

```php
// 整图改写
$ai->images()->edit('/path/cat.png', '把背景换成星空')->saveTo('/var/www/uploads');

// 局部重绘：只改蒙版覆盖的区域
$ai->images()->edit('/path/cat.png', '去掉这只手', ['mask' => '/path/mask.png']);
```

走 multipart 上传，与文生图**不是同一个端点**。只接受**本地文件**——
远端图片请先用 `Ai\Helpers\Media::download()` 取回落盘，库不会顺手替你下载，
因为那需要 SSRF 防护，不该由上传逻辑代劳。

支持：OpenAI、阶跃星辰、xAI、智谱。
**硅基流动不支持**（实测无 `/images/edits` 路由，它把图生图并进了
`images/generations`，靠传 `image` 参数区分）；**通义也不支持**（兼容模式实测 404）。

### 语音合成与识别（已可用）

```php
// 文本 → 音频
$ai = new AI(['api_key' => '...', 'model' => 'gpt-4o-mini-tts']);
$ai->audio()->speech('你好世界')->saveTo('/tmp/hello.mp3');

// 带参数
$audio = $ai->audio()->speech('你好世界', [
    'voice'  => 'sage',    // 音色
    'format' => 'wav',     // 库内统一写 format，各平台字段名不同
    'speed'  => 1.2,
]);
$audio->getBytes();    // 原始音频字节
$audio->getFormat();   // 'wav'
$audio->getSize();     // 字节数

// 音频 → 文本
$text = $ai->audio()->transcribe('/tmp/record.wav', ['language' => 'zh'])->getText();
```

#### 两种完全不同的响应形态，库内已归一

| 平台 | 实际返回 |
|------|---------|
| OpenAI / 硅基流动 / 阶跃星辰 / 智谱 | **二进制音频字节**（`Content-Type: audio/*`） |
| MiniMax | **JSON**，音频在 `data.audio`，**hex 编码**（不是 base64） |

调用方两边都写 `speech()->saveTo()`，拿到的都是可以直接播放的文件。

**这里有两个不会报错的坑，库内都堵上了：**

1. **平台出错时回的是 JSON，不是音频。** 不判别就会写出一堆扩展名 `.mp3`、
   内容是错误信息的文件,全程无报错。库内按响应的实际 `Content-Type` 判别，
   拿到 JSON 一律当错误处理，`saveTo()` 会直接抛异常而不是写出坏文件。
2. **MiniMax 的 hex 不是 base64。** 两者都是可打印字符，用 `base64_decode` 去解
   不报错，只表现为「文件存下来了但放不出声」。另外它的错误不体现在 HTTP 状态码上——
   `base_resp.status_code` 非 0 才是失败，此时 HTTP 仍是 200，库内会检查这个字段。

#### 音色缺省

OpenAI 的 `voice` 是**必填**参数，不传直接 400。库内会补一个默认音色，
让 `speech('你好')` 开箱可用；你显式传了就以你的为准。

```php
(new \Ai\Protocol\OpenAI())->knownVoices();
// ['alloy','ash','ballad','coral','echo','sage','shimmer','verse','marin','cedar']
```

> 这份音色清单据 OpenAI 官方 OpenAPI 规范（2026-08），**与早年文档已经不同**：
> `fable` / `nova` / `onyx` 已不在枚举里，新增了 `marin` / `cedar`。
> 照着旧印象写音色名会直接 400。

#### 语音识别走 multipart 上传

`transcribe()` 只接受**本地文件**（路径字符串或 `AIFile` 实例）。
远端音频请先用 `Ai\Helpers\Media::download()` 取回落盘——库不会顺手替你下载，
因为那需要 SSRF 防护，不该由上传逻辑代劳。

#### 支持的平台与模型

模型清单据各平台官方文档登记：

| 平台 | TTS 模型 | ASR |
|------|---------|-----|
| OpenAI | `gpt-4o-mini-tts`、`tts-1-hd`、`tts-1` | `gpt-4o-transcribe`、`whisper-1` 等 |
| 硅基流动 | `FunAudioLLM/CosyVoice2-0.5B`、`fnlp/MOSS-TTSD-v0.5` | `FunAudioLLM/SenseVoiceSmall`、`TeleAI/TeleSpeechASR` |
| 阶跃星辰 | `step-tts-mini`、`step-tts-2`、`step-tts-vivid` | ✅ |
| 智谱 | `glm-tts` | `glm-asr-2512` |
| MiniMax | `speech-2.8-hd`、`speech-2.8-turbo` 等 8 个 | ✖（形态不同，未接入） |
| Cohere | ✖ | `cohere-transcribe-03-2026`（须显式传 `language`） |
| Mistral / Groq / Together / Fireworks / OpenRouter / DeepInfra | ✅ | ✅ |

**通义（qwen）暂不支持**：其 OpenAI 兼容模式没有语音接口（实测 404），
原生 DashScope 语音接口形态差异很大，暂未接入。

**讯飞只提供 WebSocket**，走 `$ai->realtime()`。

### WebSocket 实时通道（已可用）

讯飞的语音能力**只提供 WebSocket**，没有等价的 HTTP 接口。本库把它纳了进来，
省得你为一个平台单独写一套 WS 对接。

```php
$ai = AI::create([
    'protocol' => 'spark',
    'app_id'   => '<控制台的 APPID>',
    'api_key'  => '<APIKey>:<APISecret>',   // 冒号拼接
]);

// 必须显式启用 WebSocket 通道
$ai->realtime()->useWebSocket()->speech('你好世界')->saveTo('/tmp/hello.mp3');

$text = $ai->realtime()->useWebSocket()->transcribe('/tmp/record.wav')->getText();
```

#### 为什么必须显式调用 `useWebSocket()`

通道默认是 `null`，不显式启用就不会建立任何连接。这不是啰嗦——
WebSocket 是长连接，超时语义、错误形态都和普通 HTTP 请求不同，
**握手成功之后**仍可能因帧格式问题静默挂死（服务端既不回数据也不发 close）。
这种行为差异不该在你不知情时自动发生。

不启用直接调用会得到明确提示，而不是含糊的失败：

```
未指定实时通道协议。当前平台的语音能力只能通过 WebSocket 访问，
请显式调用 ->useWebSocket() 启用。……
```

#### 零新增依赖

RFC 6455 客户端是纯 PHP 实现的，只用到 `stream_socket_client` /
`stream_socket_enable_crypto` / `random_bytes` / `pack` 这些**核心函数**，
不需要 `ext-sockets`，也不引入任何 composer 包。

`wss://` 需要 `ext-openssl`（绝大多数环境都有）。缺失时会给出明确提示，
不会变成一个难懂的连接错误。已在 `composer.json` 的 `suggest` 里声明。

#### 凭据与 HTTP 接口不同

讯飞语音的鉴权**不走请求头**：用 APIKey/APISecret 算 HMAC-SHA256 签名后拼进 URL 查询串。
签名带时间戳且有效期很短，**每次连接都要重算**，不能缓存 URL。另外还需要 `app_id`，
是 APIKey/APISecret 之外的第三个凭据。这些库内都处理好了，你只要把三个值配上。

#### 语音识别要的是裸 PCM

讯飞听写接受 16k / 16bit / 单声道的裸 PCM。传 `.wav` 时库会自动剥掉文件头取出
`data` 块——直接把整个 wav 灌进去，头部那几十字节会被当成音频采样，
表现为开头一小段噪音或识别结果异常，**不会报错**，很难往文件格式上想。

其它容器格式（mp3 / m4a 等）请自行转码后再传。本库不做转码，
那需要引入 ffmpeg 之类的外部依赖。

#### 讯飞的 HTTP 语音接口不存在

`$ai->audio()->speech()` 在讯飞协议上会明确报「不支持」，并在错误信息里列出
本协议支持的能力（含「实时通道」），把你导向 `$ai->realtime()`。
比让请求打到一个不存在的 HTTP 路径、再拿一个含糊的 404 要好。

### 视频生成 / 异步任务（已可用）

视频接口**无一例外都是异步任务式**，所以 `generate()` 返回的是任务而不是视频。

```php
// Web 请求里：提交后存库就结束，不阻塞
$task = $ai->video()->generate('日落的海边', ['duration' => 5, 'ratio' => '16:9']);
$db->save(['task' => json_encode($task->toArray())]);

// 定时任务 / 队列 worker 里：恢复并查询
$task = AsyncTask::fromArray(json_decode($row['task'], true), $ai);
if ($task->refresh()->isSucceeded()) {
    $task->getResult()->saveTo('/var/www/videos/x.mp4');
}
```

#### 为什么不直接等结果

视频生成动辄几分钟。`wait()` 存在，但**不要在 Web 请求里调用**——
它会占死一个 PHP-FPM worker，并发一上来整站就挂。那个方法是给 CLI 脚本和队列 worker 用的。

```php
// 只在 CLI / worker 里这么用
$task->wait(300, 3);   // 最多等 300 秒，起始间隔 3 秒后指数退避
```

#### 超时不是失败

`wait()` 超时**不抛异常**。任务在平台侧仍然在跑，抛异常会诱导你 `catch` 之后当失败处理，
白白丢掉一次已经付费的生成。超时后：

```php
$task->isTimeout();   // true
$task->isDone();      // false —— 所以 if ($task->isDone()) 的写法天然安全
$task->isFailed();    // false —— 不会被误当失败
$task->getMessage();  // 「任务仍在平台侧处理中……请保存 task_id「xxx」，稍后恢复后再查询」
```

#### 四家平台的状态取值差异已归一

| 平台 | 状态字段 | 取值 |
|------|---------|------|
| 通义万相 | `task_status` | PENDING / RUNNING / SUCCEEDED / FAILED / CANCELED |
| 智谱 CogVideoX | `task_status` | PROCESSING / SUCCESS / FAIL |
| 火山方舟 Seedance | `status` | queued / running / succeeded / failed |
| MiniMax 海螺 | `status` | Preparing / Queueing / Processing / Success / Fail |
| Gemini Veo | `status` | queued / in_progress / completed / failed |

库内统一成 `pending` / `running` / `succeeded` / `failed` / `timeout` 五种。
**平台新增了库里没见过的状态值时，按「处理中」对待而不是失败**——
让用户的任务因为平台加了个新状态就全变失败，是最糟的降级方式。

#### MiniMax 是三段流程

其余三家是「提交 → 轮询」两步，MiniMax 多一步:

```
提交 POST /v1/video_generation           → task_id
查询 GET  /v1/query/video_generation     → status=Success，但只给 file_id
再取 GET  /v1/files/retrieve             → 真正的下载地址（有效期 9 小时）
```

库内自动走完三步，调用方感知不到差别。另外 MiniMax 的失败**不体现在 HTTP 状态码上**——
`base_resp.status_code` 非 0 才是失败，此时 HTTP 仍是 200，库内会检查这个字段。

#### 支持的平台与模型

| 平台 | 模型（据官方文档） |
|------|------|
| 通义万相 | `wan2.7-t2v`、`wan2.7-t2v-2026-06-12` |
| 智谱 | `cogvideox-3`、`cogvideox-2`、`cogvideox-flash`、`viduq1-*`、`vidu2-*` |
| 火山方舟 | Seedance 系列 |
| MiniMax | `MiniMax-Hailuo-2.3`、`MiniMax-Hailuo-02`、`T2V-01-Director`、`T2V-01` |
| Gemini | `veo-3.1-generate-preview`、`veo-3.1-lite-generate-preview`、`gemini-omni-flash` |
| Z.ai | 视频生成（据 z.ai 文档） |

⚠️ **结果 URL 都有有效期**（万相约 24 小时、MiniMax 仅 9 小时），
存 URL 进库很快就会失效,必须及时 `saveTo()` 落地。

### 媒体结果要及时落地

多数平台返回的图片/视频 URL **有效期只有几小时到 24 小时**，
只把 URL 存进库，第二天就会全部失效。用 `saveTo()` 及时取回：

```php
$img->saveTo('/var/www/uploads');          // 图片，返回路径数组
$audio->saveTo('/tmp/hello.mp3');          // 音频
$video->saveTo('/var/www/v.mp4');          // 视频，默认上限 64MB
```

下载走库内带 SSRF 防护的抓取器（IP 钉死、逐跳重校验），不是裸 `file_get_contents()`。
目标目录**必须已存在**——库不会自动创建，避免路径写错时在磁盘上散落一堆空目录。

### WebSocket 通道默认关闭

讯飞等平台的语音能力只提供 WebSocket。本库已集成，
但**必须显式启用**，因为 WS 是长连接，超时与错误语义都和普通 HTTP 请求不同：

```php
$ai->realtime()->useWebSocket()->speech('你好世界');
```

不启用直接调用会得到明确提示，而不是含糊的连接失败。

### 自定义网关

把 `base_url` 指向自建网关或中转服务时，图像/语音端点会**自动跟着走同一个网关**，
不会回落到官方地址（那意味着把数据发到你没指定的服务器上）。
需要单独指定某个能力的完整地址时，用 `<能力名>_endpoint` 配置项：

```php
$ai = new AI([
    'api_key'        => '...',
    'base_url'       => 'https://my-gateway.com/v1',
    'image_endpoint' => 'https://another-host.com/v1/images/generations',  // 可选
]);
```

这个配置项同时是**逃生口**：库对某平台能力的判断有误或过时时，配上
`<能力名>_endpoint` 就能绕过声明检查。若该协议族本身没有对应形态的解析器
（比如 Claude 之于图像），仍会报错，但会点明是哪一层挡住的，并建议改用
`protocol` + `base_url`。

### 给自定义协议类的迁移说明

`ProtocolInterface` 在 v1.14.0 新增了 4 个能力方法。

- **继承内置协议的（`extends OpenAI` / `extends Claude` 等）：无需任何改动。**
  README「扩展开发」一节教的就是这种写法，库内 38 个厂商协议类也都是这么写的。
- **裸实现接口的（`implements ProtocolInterface`）：加一行即可。**

```php
class MyProtocol implements ProtocolInterface
{
    use \Ai\Protocol\Concerns\CapabilityDefaults;   // ← 只需加这一行
    // ……原有 6 个方法一字不用改……
}
```

---

## 已知限制

- 会话历史存在内存里，进程退出即失，跨请求需用 `exportHistory()` / `importHistory()` 自行落库；
- 流式下的工具调用已支持（分片会自动重组）。若模型声明本轮要调工具、却一个都重组不出来（说明该平台用了本库尚未覆盖的分片结构），会抛 `stream_tool_calls_unassembled` 异常而非静默返回空响应；
- 流式输出只提取正文增量，推理模型的思维链（`reasoning_content` / `thinking` 块）不计入 `getContent()`，需要时从 `stream_chunk` 事件的 `raw` 字段自取；
- 各平台的 `knownModels()` 常用模型清单是库内维护的静态快照，仅用于离线渲染下拉框与拉取失败兜底，最新可用模型请以 `listModels()` 的实时结果为准；
- Azure OpenAI 只覆盖了新版 `/openai/v1` 路由，旧版「部署名 + api-version」路由需自行用 `endpoint` 配置完整 URL；AWS Bedrock、Google Vertex AI 因需要 SigV4 / OAuth 签名，暂未内置；
- 自定义模型的 `supports()` 能力是乐观默认值（对方接口实际支持什么库无从得知），需要准确值时用 `features` 配置项自行声明；
- `Ai\Protocol\Gemini::convertMessages()` 未被调用——Gemini 走的是 OpenAI 兼容端点，消息直接透传；
- `chatBatch()` 并发批量不支持流式，也不走 `setAttachments()`（附件请写在各自 payload 里）；
- `cost()` 需自行传入价格表，库不内置各平台价格（价格变动频繁，内置必然过期）；
- `Ai\Cli\ClaudeCode` 依赖本机已安装 claude 程序；`proc_open` / `shell_exec` 被禁用的受限 PHP 环境需改用自定义执行器（如 SSH/SFTP）。
- 视频生成只覆盖**文生视频与首帧图生视频**；
- 图像编辑只覆盖走 `/images/edits` 的平台；硅基流动与通义的图生图形态不同，未接入；
- `wait()` 是阻塞的，只适合 CLI 与队列 worker；Web 请求里请用「提交存库 + 定时任务轮询」的写法；
- WebSocket 通道只做「一次会话、发完收完就关」这一种模式，够覆盖讯飞 TTS/ASR；不支持并发多连接、自动重连、服务端模式与 permessage-deflate 压缩扩展；
- MiniMax 的语音识别形态与 OpenAI 差异较大，暂未接入；
- 向量化默认不分批，超出平台单次上限时需自行传 `batch_size`——各平台上限差异大且文档未必写明，库不预设一个「保险的小值」，那会让本可一次发完的平台白白多发几十个请求；
- 视频生成一律异步，`AsyncTask::wait()` 会阻塞，不可在 Web 请求中使用；跨请求恢复需自行把 `toArray()` 的结果落库；
- 火山方舟的视频模型清单与阶跃星辰的 ASR/音色清单**刻意留空**：其文档站是 JS 渲染的，未能查证。留空会回退到平台自己的模型列表接口，填错则会让用户拿着不存在的模型名去调。

---

## 许可证

MIT License
