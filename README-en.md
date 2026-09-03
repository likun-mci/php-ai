# PHP AI Standard Library

A framework-agnostic PHP library for calling AI APIs. It hides the differences in **authentication, request protocol, response format, and streaming protocol** across **40 mainstream AI platforms** behind one unified interface — Chinese platforms such as Qwen, Doubao, ERNIE, Zhipu GLM, Kimi, Tencent Hunyuan, iFlytek Spark and DeepSeek; international ones such as OpenAI, Claude, Gemini, Grok and Mistral; plus aggregators like OpenRouter and SiliconFlow, and local deployments like Ollama. Switching platforms means changing one config value.

Beyond chat, it ships an **Agent tool-calling loop**, an **AI code-editing protocol**, a **web fetcher with SSRF protection**, and **long-term Agent memory** — enough to build back-office AI assistants, code-editing assistants, content translation and similar real features.

Every example in this document is taken from a real production system (a CodeIgniter 3 site builder), not pseudo-code.

> 🌏 中文文档见 [README.md](README.md)。

---

## Features

- 🔌 **Many platforms** — 40 built-in protocols: 20 Chinese (Qwen / Doubao / ERNIE / Zhipu GLM / Kimi / Hunyuan / Spark / MiniMax / StepFun / 01.AI / Baichuan / SenseNova / 360 Zhinao / Huawei MaaS / DeepSeek …), 12 international (OpenAI / Claude / Gemini / Grok / Mistral / Cohere / Perplexity / Meta Llama / Azure OpenAI …), 8 aggregators (OpenRouter / SiliconFlow / ModelScope / Groq / Together / Fireworks / DeepInfra / NVIDIA NIM …) and local deployments (Ollama / LM Studio / vLLM)
- 🎯 **One interface** — `AI::create()->chat()`. Switching platforms means changing `protocol` or `model`; your business code stays untouched
- 🧰 **Tool calling unified across platforms** — one tool definition runs on all 40 protocols. The library translates between OpenAI's `tool_calls` and Anthropic's `tool_use` blocks, so moving an Agent to another platform is a one-line config change
- 🛡️ **Production-grade robustness** — automatic backoff retry on 429 rate limits and 5xx errors (honouring `Retry-After`), fail-fast on request-body encoding errors, defence-in-depth SSRF protection
- ⚡ **Concurrent batching** — `chatBatch()` uses `curl_multi`; measured 3.3× speed-up on 10 items, and one failure does not drag down the batch
- 🧩 **Any model, any endpoint** — model names are not restricted to the built-in list. Pick the protocol format (`protocol`) and point at a custom endpoint (`base_url` / `endpoint`), so one codebase talks to official APIs, third-party relays and self-hosted gateways alike
- 🌊 **Streaming** — one call to `setStream(true)` emits chunks in real time over SSE; in long-running frameworks use `setStreamCallback()` to take over the chunks
- 🧰 **Agent loop** — attach tools (functions) and the library drives the "model decides → run tool → feed result back" loop for you
- 🤖 **Claude Code CLI** — drive the local `claude` binary directly (`Ai\Cli\ClaudeCode`): file I/O, tool execution, session resumption, structured output, with automatic path detection and caching
- 📊 **CLI introspection** — read version, login state, model list, quota usage and rate limits, effective settings and MCP status without starting a conversation
- 🔌 **Persistent duplex session** — `Ai\Cli\ClaudeCodeSession` mirrors the official IDE plugin's process model: multi-turn conversation in a long-lived process, tool-permission callbacks decided in PHP, graceful interruption, and new requests accepted mid-turn
- 📝 **Code-editing protocol** — structured editing context plus verifiable edit actions, with plan / review / auto-apply modes
- 🛡️ **Safe fetching** — `HttpFetch` guards against SSRF, DNS rebinding, private addresses and protocol escapes
- 📄 **Multimodal attachments** — images and other attachments are adapted to each platform's format automatically
- 🪶 **Zero hard dependencies** — only PHP and cURL. Install via Composer, or drop in a single autoload file

---

## Requirements

| Item | Requirement |
|------|-------------|
| PHP | >= 7.1 |
| Extensions | `ext-curl`, `ext-json`, `ext-mbstring`, `ext-dom` (HTML conversion only) |
| Network | Access to the platform APIs. From mainland China a proxy is usually required — see [HTTP proxy](#http-proxy) |

---

## Installation

### Composer

```bash
composer require likun-mci/php-ai
```

```php
require __DIR__ . '/vendor/autoload.php';

use Ai\AI;
```

### Manual include (without Composer)

The library ships its own PSR-4 loader. Drop the directory into your project and include it once:

```php
require_once __DIR__ . '/autoload.php';

use Ai\AI;
```

---

## Quick start

```php
use Ai\AI;

$ai = AI::create([
    'model'   => 'gpt-4o',
    'api_key' => 'sk-xxxxxxxxxxxxx',
]);

$response = $ai->chat('Describe artificial intelligence in one sentence');

echo $response->getContent();          // reply text
echo $response->tokens();              // total tokens consumed
```

Switching to a Chinese platform is a config change — the business code does not move:

```php
$ai = AI::create(['protocol' => 'qwen',     'model' => 'qwen-plus',  'api_key' => 'sk-xxx']);  // Qwen
$ai = AI::create(['protocol' => 'zhipu',    'model' => 'glm-4.6',    'api_key' => 'xxx']);     // Zhipu GLM
$ai = AI::create(['protocol' => 'doubao',   'model' => 'doubao-seed-1-6', 'api_key' => 'xxx']);// Doubao
$ai = AI::create(['protocol' => 'moonshot', 'model' => 'kimi-latest','api_key' => 'sk-xxx']);  // Kimi

// When the model name identifies the platform, `protocol` can be omitted
$ai = AI::create(['model' => 'qwen-plus', 'api_key' => 'sk-xxx']);
```

See [Supported platforms and models](#supported-platforms-and-models) for the full list, and `examples_platforms.php` for a runnable example.

`chat()` accepts either a string (wrapped into a single user message) or a full payload array:

```php
$response = $ai->chat([
    'messages' => [
        ['role' => 'system',    'content' => 'You are a helpful assistant'],
        ['role' => 'user',      'content' => 'Tell me about artificial intelligence'],
        ['role' => 'assistant', 'content' => 'Artificial intelligence is …'],
        ['role' => 'user',      'content' => 'Make it shorter'],
    ],
    'temperature' => 0.7,
    'max_tokens'  => 1000,
]);
```

> **Multi-turn conversations**: history is not stored by default — `messages` is passed in full by your code. To let the library maintain context for you, set `rounds`; see [Conversation context](#conversation-context).

---

## Supported platforms and models

### Platform list

Pass one of the values below as `protocol` and the platform's console key as `api_key`. Everything else is identical across platforms:

```php
// Switching platforms touches only these two lines; business code is untouched
$ai = AI::create([
    'protocol' => 'qwen',          // see tables below
    'model'    => 'qwen-plus',
    'api_key'  => 'sk-xxx',
]);
echo $ai->chat('Hello')->getContent();
```

#### Mainland China platforms

| Platform | `protocol` value | Default endpoint | Model-name auto-detection |
|----------|-----------------|------------------|---------------------------|
| DeepSeek | `deepseek` <br><sub>alias `deepseek-ai`</sub> | `api.deepseek.com/v1/chat/completions` | `deepseek-*` |
| Alibaba Model Studio (Qwen) | `qwen` <br><sub>alias `dashscope` / `bailian` / `tongyi` / `aliyun`</sub> | `dashscope.aliyuncs.com/compatible-mode/v1/chat/completions` | `qwen*`, `qwq*` |
| Alibaba Model Studio (Qwen) | `qwen-anthropic` <br><sub>alias `qwen-claude` / `dashscope-anthropic`</sub> | `dashscope.aliyuncs.com/api/v2/apps/claude-code-proxy/v1/messages` | — |
| Volcengine Ark (Doubao) | `doubao` <br><sub>alias `ark` / `volcengine` / `volces` / `volc`</sub> | `ark.cn-beijing.volces.com/api/v3/chat/completions` | `doubao-*` |
| Baidu Qianfan (ERNIE) | `ernie` <br><sub>alias `qianfan` / `baidu` / `wenxin` / `yiyan`</sub> | `qianfan.baidubce.com/v2/chat/completions` | `ernie-*` |
| Zhipu AI (GLM) | `zhipu` <br><sub>alias `glm` / `bigmodel` / `chatglm` / `zhipuai`</sub> | `open.bigmodel.cn/api/paas/v4/chat/completions` | `glm-*`, `cogview-*` |
| Zhipu AI (GLM) | `zhipu-anthropic` <br><sub>alias `glm-anthropic` / `zhipu-claude`</sub> | `open.bigmodel.cn/api/anthropic/v1/messages` | — |
| Moonshot (Kimi) | `moonshot` <br><sub>alias `kimi` / `yuezhianmian`</sub> | `api.moonshot.cn/v1/chat/completions` | `kimi-*`, `moonshot-*` |
| Moonshot (Kimi) | `moonshot-anthropic` <br><sub>alias `kimi-anthropic` / `moonshot-claude`</sub> | `api.moonshot.cn/anthropic/v1/messages` | — |
| Tencent Hunyuan | `hunyuan` <br><sub>alias `tencent` / `tencent-hunyuan`</sub> | `api.hunyuan.cloud.tencent.com/v1/chat/completions` | `hunyuan-*` |
| iFlytek Spark | `spark` <br><sub>alias `xunfei` / `iflytek` / `xfyun` / `xinghuo`</sub> | `spark-api-open.xf-yun.com/v1/chat/completions` | `spark*`, `4.0Ultra` |
| MiniMax | `minimax` <br><sub>alias `xiyu` / `minimaxi`</sub> | `api.minimaxi.com/v1/text/chatcompletion_v2` | `MiniMax-*`, `abab*` |
| StepFun | `stepfun` <br><sub>alias `step` / `jieyue`</sub> | `api.stepfun.com/v1/chat/completions` | `step-*` |
| 01.AI (Yi) | `yi` <br><sub>alias `lingyiwanwu` / `01ai` / `01-ai` / `zeroone`</sub> | `api.lingyiwanwu.com/v1/chat/completions` | `yi-*` |
| Baichuan | `baichuan` <br><sub>alias `baichuan-inc`</sub> | `api.baichuan-ai.com/v1/chat/completions` | `Baichuan*` |
| SenseNova (SenseTime) | `sensenova` <br><sub>alias `sensetime` / `sensechat` / `shangtang`</sub> | `api.sensenova.cn/compatible-mode/v1/chat/completions` | `SenseChat-*` |
| 360 Zhinao | `zhinao` <br><sub>alias `360` / `qihoo` / `360ai`</sub> | `api.360.cn/v1/chat/completions` | `360gpt*` |
| Huawei Cloud ModelArts (PanGu / MaaS) | `modelarts` <br><sub>alias `huawei` / `maas` / `pangu`</sub> | `api.modelarts-maas.com/v1/chat/completions` | — |

#### International platforms

| Platform | `protocol` value | Default endpoint | Model-name auto-detection |
|----------|-----------------|------------------|---------------------------|
| OpenAI | `openai` <br><sub>alias `oai` / `openai-compatible` / `compatible` / `chat_completions`</sub> | `api.openai.com/v1/chat/completions` | `gpt-*`, `o3` |
| Anthropic Claude | `claude` <br><sub>alias `anthropic` / `claude-messages` / `messages`</sub> | `api.anthropic.com/v1/messages` | `claude-*` |
| Google Gemini | `gemini` <br><sub>alias `google`</sub> | `generativelanguage.googleapis.com/v1beta/openai/chat/completions` | `gemini-*` |
| Z.ai (Zhipu international) | `zai` <br><sub>alias `z-ai` / `zhipu-global`</sub> | `api.z.ai/api/paas/v4/chat/completions` | — |
| xAI (Grok) | `grok` <br><sub>alias `xai` / `x-ai` / `x.ai`</sub> | `api.x.ai/v1/chat/completions` | `grok-*` |
| Mistral AI | `mistral` <br><sub>alias `mistralai`</sub> | `api.mistral.ai/v1/chat/completions` | `mistral-*`, `codestral-*` |
| Meta (Llama API) | `llama` <br><sub>alias `meta` / `meta-llama`</sub> | `api.llama.com/compat/v1/chat/completions` | — |
| Cohere | `cohere` <br><sub>alias `command`</sub> | `api.cohere.ai/compatibility/v1/chat/completions` | `command-*` |
| Perplexity | `perplexity` <br><sub>alias `pplx` / `sonar`</sub> | `api.perplexity.ai/chat/completions` | `sonar*` |
| Azure OpenAI | `azure` <br><sub>alias `azure-openai` / `azureopenai`</sub> | you must supply `base_url` | — |

#### Aggregators and relays

| Platform | `protocol` value | Default endpoint | Model-name auto-detection |
|----------|-----------------|------------------|---------------------------|
| OpenRouter | `openrouter` <br><sub>alias `or` / `open-router` / `open_router`</sub> | `openrouter.ai/api/v1/chat/completions` | — |
| SiliconFlow (SiliconCloud) | `siliconflow` <br><sub>alias `silicon` / `siliconcloud` / `guiji`</sub> | `api.siliconflow.cn/v1/chat/completions` | — |
| ModelScope | `modelscope` <br><sub>alias `moda` / `damo`</sub> | `api-inference.modelscope.cn/v1/chat/completions` | — |
| Groq | `groq` <br><sub>alias `groqcloud`</sub> | `api.groq.com/openai/v1/chat/completions` | — |
| Together AI | `together` <br><sub>alias `togetherai` / `together-ai`</sub> | `api.together.xyz/v1/chat/completions` | — |
| Fireworks AI | `fireworks` <br><sub>alias `fireworksai`</sub> | `api.fireworks.ai/inference/v1/chat/completions` | — |
| DeepInfra | `deepinfra` | `api.deepinfra.com/v1/openai/chat/completions` | — |
| Cerebras | `cerebras` | `api.cerebras.ai/v1/chat/completions` | — |
| NVIDIA NIM | `nvidia` <br><sub>alias `nim` / `nvidia-nim` / `build-nvidia`</sub> | `integrate.api.nvidia.com/v1/chat/completions` | — |

#### Local / self-hosted

| Platform | `protocol` value | Default endpoint | Model-name auto-detection |
|----------|-----------------|------------------|---------------------------|
| Ollama (local) | `ollama` | `localhost:11434/v1/chat/completions` | — |
| LM Studio (local) | `lmstudio` <br><sub>alias `lm-studio`</sub> | `localhost:1234/v1/chat/completions` | — |
| vLLM (self-hosted) | `vllm` <br><sub>alias `sglang` / `xinference`</sub> | `localhost:8000/v1/chat/completions` | — |

> A programmatic version of these tables is available via `$ai->listProtocols()` / `listProtocolGroups()`, ready to render into an admin dropdown.
> `$ai->listKnownModels('qwen')` returns that platform's common model list without making a request.
> **Aliases** are just alternative spellings of the same protocol (`protocol => 'kimi'` is identical to `protocol => 'moonshot'`).

### Built-in model identifiers (shortcuts)

These **model identifiers** can be passed directly as `model` without a `protocol`; the library resolves the platform, protocol and endpoint:

| Platform | Model identifier | Actual protocol | Endpoint |
|----------|-----------------|-----------------|----------|
| OpenAI | `gpt-4.1` | OpenAI | api.openai.com |
| OpenAI | `gpt-4o` | OpenAI | api.openai.com |
| Claude | `claude-3-opus` | Claude | api.anthropic.com |
| Gemini | `gemini-2.5-pro` | Gemini (OpenAI-compatible endpoint) | generativelanguage.googleapis.com |
| DeepSeek | `deepseek-chat` | OpenAI | api.deepseek.com |
| DeepSeek | `deepseek-reasoner` | OpenAI | api.deepseek.com |
| DeepSeek | `deepseek-v4-pro` | OpenAI | api.deepseek.com |
| DeepSeek | `deepseek-v4-flash` | OpenAI | api.deepseek.com |
| DeepSeek | `deepseek-anthropic` | **Claude** | api.deepseek.com/anthropic |
| OpenRouter | full identifiers such as `openai/gpt-4o` | **OpenRouter** (OpenAI-compatible) | openrouter.ai/api |

`deepseek-anthropic` is DeepSeek's Anthropic-compatible endpoint, spoken over the Claude protocol — **use it when you need tool calling (Agent): you get Anthropic's tools protocol at DeepSeek's prices**.

### Models outside the table work too

The table above lists only the ready-made shortcuts. **`model` is not limited to those values**:

- New official models (`claude-opus-5`, `gpt-5.1`, `qwen3-max`, `glm-4.6`, `kimi-k2-turbo-preview` …): the library infers the protocol family and official endpoint from the model name, so they work immediately without waiting for a library update.
- Open-source models hosted by many platforms (`llama3`, `mixtral` …), or models on a third-party relay / self-hosted gateway: add `protocol` or `base_url`.

```php
// New official model: recognised as the claude family, uses api.anthropic.com/v1/messages
$ai = AI::create(['model' => 'claude-opus-5', 'api_key' => 'sk-ant-xxx']);

// New Chinese model: recognised as the zhipu family, uses open.bigmodel.cn/api/paas/v4/chat/completions
$ai = AI::create(['model' => 'glm-4.6', 'api_key' => 'xxx']);

// Open-source model / third-party endpoint: any model name + explicit protocol + custom address
$ai = AI::create([
    'model'    => 'llama3',
    'protocol' => 'openai',                    // choose the protocol format explicitly
    'base_url' => 'http://10.0.0.9:11434/v1',  // custom endpoint
]);
```

> If the model name cannot be attributed to an official platform and no `base_url` / `endpoint` is given, a `ConfigException` is thrown **before** the request — rather than sending your key to an unrelated official domain.

Besides the values in the platform tables, `protocol` also accepts a **custom protocol class name** implementing `Ai\Contracts\ProtocolInterface` (see "Extending the library").

When `protocol` is omitted it is inferred from the model name (see the "Model-name auto-detection" column), including `vendor/model` spellings such as `qwen/qwen-max`. If inference fails the `openai` protocol is assumed, and `base_url` or `endpoint` becomes mandatory.

> **Only vendor-owned model names are inferred.** Open-source names hosted by many platforms — `llama3`, `mixtral` — are excluded (there is no way to tell which host you mean), so pass `protocol` or `base_url` explicitly.

### Protocol differences (important)

Platforms do not support the same payload keys. Know this before writing business code:

| Payload key | OpenAI protocol | Claude protocol | Gemini protocol |
|-------------|-----------------|-----------------|-----------------|
| `messages` | ✅ | ✅ (`role: system` is dropped) | ✅ |
| `system` (top level) | ❌ ignored | ✅ | ❌ ignored |
| `tools` / `tool_choice` | ❌ ignored | ✅ | ❌ ignored |
| `temperature` / `max_tokens` | ✅ | ✅ | ✅ |
| `stream` | ✅ (usage stats attached automatically) | ✅ | ✅ |

In short:

- For **OpenAI / Gemini**, put the system prompt in `messages` with `role: system`.
- For **Claude**, put it in the top-level `system` key.
- **Agents and tool calling require the Claude protocol.** Besides Anthropic itself, these Chinese platforms expose Anthropic-compatible endpoints, so you can run Agents at Chinese model prices:

| `protocol` | Platform | Key used for auth |
|------------|----------|-------------------|
| `deepseek-anthropic` (model identifier) | DeepSeek | `deepseek__api_key` |
| `zhipu-anthropic` | Zhipu GLM | `zhipu__api_key` |
| `moonshot-anthropic` | Moonshot Kimi | `moonshot__api_key` |
| `qwen-anthropic` | Alibaba Model Studio | `qwen__api_key` |

```php
// Tool calling on GLM-4.6: Claude's protocol, Zhipu's pricing and key
$ai = AI::create([
    'protocol' => 'zhipu-anthropic',
    'model'    => 'glm-4.6',
    'api_key'  => $config['zhipu__api_key'],
]);
```

### Querying platforms and models at runtime

```php
$ai = new AI();

// —— Metadata queries that make no network request (good for admin dropdowns) ——
$ai->listPlatforms();        // 37 platforms: ['deepseek'=>'DeepSeek','qwen'=>'Alibaba Model Studio (Qwen)',...]
                             // The key is the agreed key prefix: {platform}__api_key
$ai->listProtocols();        // 40 protocols: ['openai'=>'OpenAI compatible (Chat Completions)',...]
$ai->listProtocolGroups();   // Same, grouped into "Mainland China / International / Aggregators / Local" — render as optgroups
$ai->listKnownModels('qwen');// That platform's common models: ['qwen3-max'=>'Qwen3 Max','qwen-max'=>'Qwen Max',...]
$ai->platformOfModel('qwen-max');   // 'qwen' (safe to call before setting a model; returns custom when unattributable)

// —— Current state after a model is set ——
$ai->setConfig(['model' => 'glm-4.6', 'api_key' => 'xxx']);
$ai->getPlatform();     // 'zhipu'
$ai->getProtocolKey();  // 'zhipu' — the protocol actually in use
$ai->resolveEndpoint(); // 'https://open.bigmodel.cn/api/paas/v4/chat/completions' — the endpoint actually used
$ai->listKnownModels(); // With no argument, returns the current protocol's built-in list

// —— Live model list fetched from the platform ——
$ai->listModels();      // The endpoint follows base_url / endpoint, so behind a third-party gateway you get the gateway's models
                        // On failure, or when the platform has no such endpoint: falls back to the built-in list
                        // only if the request targeted the official domain; behind a third-party gateway it returns null
                        // (so the official list is never mistaken for the gateway's capabilities)
$ai->listModels(true);  // Pass true for full model records (id / created / owned_by / pricing …)
                        // Useful for OpenRouter, SiliconFlow and others where you display pricing or capability tags
```

**Real-world use** — fetch model lists per platform, cache locally for a week, and render an admin dropdown:

```php
$platforms = $this->ai->listPlatforms();
$result    = [];

foreach ($platforms as $platform => $platformName) {
    $apiKey = (string)($this->siteConfig["{$platform}__api_key"] ?? '');
    if (empty($apiKey)) continue;               // skip platforms with no key configured

    try {
        $ai = new AI();
        // Any model of that platform will do (it only determines the protocol) — take the first built-in one
        $known = $ai->listKnownModels(\Ai\Helpers\Protocols::platformProtocols($platform)[0] ?? '');
        $ai->setConfig([
            'model'   => (string)array_key_first($known),
            'api_key' => $apiKey,
        ]);
        $models = $ai->listModels();
        if (is_array($models) && $models) $result[$platform] = $models;
    } catch (\Exception $e) {
        // one platform failing must not affect the others
    }
}
```

---

## Configuration

```php
$ai->setConfig([
    'model'        => 'gpt-4o',      // model identifier (required) — built-in or any custom name
    'api_key'      => 'sk-xxx',      // API key (optional for self-hosted / intranet endpoints)
    'protocol'     => '',            // explicit protocol format: see the platform tables, or a custom protocol class name
    'tools'        => [],            // tool definitions (unified format — see "Agent: tool-calling loop")
    'tool_choice'  => null,          // auto / any / none / ['type'=>'tool','name'=>'x']
    'base_url'     => '',            // API root, joined intelligently with the protocol path — see "Custom endpoints"
    'endpoint'     => '',            // full chat endpoint, used verbatim; takes precedence over base_url
    'endpoint_models' => '',         // full model-list endpoint (affects listModels only)
    'platform'     => '',            // platform label for your own bookkeeping; defaults to the model/protocol
    'headers'      => [],            // add or override request headers; a null value deletes a protocol default
    'extra_body'   => [],            // vendor-specific parameters merged into the request body
    'search'       => false,         // web search: true, or an array of options — see "Web search"
    'max_tokens'             => 1024 * 64,  // maximum output tokens
    'max_completion_tokens'  => 16384,      // OpenAI o1/o3 series only; overrides max_tokens
    'temperature'            => 0.7,
    'organization' => 'org-xxx',     // OpenAI enterprise accounts only
    'project_id'   => 'proj_xxx',    // OpenAI enterprise accounts only
]);
```

`setConfig()` **merges incrementally** and may be called repeatedly; passing `model` rebuilds the model and protocol instances. `base_url`, `protocol` and friends can be supplied alongside `model` or added afterwards.

Generation parameters (`max_tokens`, `max_completion_tokens`, `temperature`, `top_p`, `top_k`, `stop`, `presence_penalty`, `frequency_penalty`, `seed`, `response_format`, `system`, `tools`, `tool_choice`, `reasoning_effort`, `thinking`) set via `setConfig()` apply to every request; a per-call `chat()` payload wins. Connection settings (`api_key`, `base_url` …) never enter the request body.

Use `extra_body` for vendor-specific parameters (merged straight into the body) and `headers` for unusual auth schemes:

```php
$ai->setConfig([
    'headers'    => [
        'Authorization' => null,      // remove the Bearer header the protocol writes by default
        'X-Api-Token'   => 'abc123',  // replace it with what the endpoint expects
    ],
    'extra_body' => ['enable_thinking' => false, 'safe_mode' => 1],
]);
```

Fluent interface:

```php
$ai->setModel('claude-3-opus')   // switch model only
   ->setTimeout(300)             // timeout in seconds — raise it for long generations
   ->setConnectTimeout(30)       // connect timeout in seconds, independent of the total
   ->setUserAgent('MyApp/1.0')   // custom User-Agent (none is sent by default)
   ->setSslVerify(false)         // disable SSL verification (debugging / self-signed intranet only)
   ->setProxy('socks5h://127.0.0.1:1080')
   ->setStream(true)
   ->setStreamCallback($fn)      // hand stream chunks to a callback (required in long-running frameworks — see "Streaming")
   ->setAttachments([$file])
   ->chat($prompt);
```

While debugging, `$ai->getLastInfo()` returns cURL details of the last request (http_code, total time …).

### HTTP proxy

Supports `http://`, `https://`, `socks5://`, `socks5h://` (DNS through the proxy too), `socks4://` and `socks4a://`:

```php
if (!empty($config['PROXY_SOCKS5'])) {
    $ai->setProxy($config['PROXY_SOCKS5']);
} elseif (!empty($config['PROXY_HTTP'])) {
    $ai->setProxy($config['PROXY_HTTP']);
}
```

### Custom endpoints (third-party relays / gateways)

The default endpoint is each platform's official address. To use a relay or self-hosted gateway there are two options, highest precedence first:

**1. `base_url` — API root, joined intelligently with the protocol path (most common)**

Give the root address and the library appends the protocol's path. A path prefix in the root is preserved, and segments that overlap the official path are de-duplicated:

```php
$ai = AI::create([
    'model'    => 'deepseek-chat',
    'api_key'  => 'sk-xxx',
    'base_url' => 'https://proxy.example.com',   // or with a port: http://127.0.0.1:8080
]);
// actual request => https://proxy.example.com/v1/chat/completions
```

| `base_url` | Protocol path | Actual endpoint |
|------------|---------------|-----------------|
| `https://proxy.com` | `/v1/chat/completions` | `https://proxy.com/v1/chat/completions` |
| `https://proxy.com/v1` | `/v1/chat/completions` | `https://proxy.com/v1/chat/completions` (overlap removed) |
| `https://proxy.com/openai` | `/v1/chat/completions` | `https://proxy.com/openai/v1/chat/completions` |
| `https://proxy.com/v1/chat/completions` | `/v1/chat/completions` | unchanged (already a full endpoint) |
| `127.0.0.1:8080` | `/v1/chat/completions` | `https://127.0.0.1:8080/v1/chat/completions` (missing scheme defaults to https) |

**2. `endpoint` — full override, used verbatim (most flexible)**

Use this when the path structure differs entirely from the official one:

```php
$ai = AI::create([
    'model'    => 'deepseek-chat',
    'api_key'  => 'sk-xxx',
    'endpoint' => 'https://proxy.example.com/openai/deepseek/chat',  // used as-is
]);
```

`endpoint` beats `base_url`; with neither set the official endpoint is used, so behaviour is fully backward compatible.

---

### OpenRouter

[OpenRouter](https://openrouter.ai) aggregates many models behind a single OpenAI-compatible API — OpenAI, Claude, Gemini, DeepSeek, Llama and more. The `openrouter` protocol is built in and works out of the box:

```php
// Option 1 (recommended): set protocol = openrouter and openrouter.ai/api is used automatically
$ai = AI::create([
    'model'    => 'openai/gpt-4o',              // full OpenRouter model identifier
    'protocol' => 'openrouter',
    'api_key'  => 'sk-or-v1-xxxxxxxxx',         // OpenRouter API key
    'referer'  => 'https://myapp.com',          // optional referrer (visible in the OpenRouter dashboard)
    'title'    => 'MyApp',                      // optional app name
]);

// Option 2: configure via base_url (without protocol=openrouter)
$ai = AI::create([
    'model'    => 'anthropic/claude-sonnet-4-20250514',
    'base_url' => 'https://openrouter.ai/api',
    'api_key'  => 'sk-or-v1-xxxxxxxxx',
]);
```

OpenRouter model names use a `vendor/model` form and protocol inference extracts the vendor prefix:
- `openai/gpt-4o` → inferred as `openai`, uses the OpenAI protocol format
- `anthropic/claude-sonnet-4-20250514` → inferred as `claude` (but OpenRouter still replies in OpenAI-compatible format — **the `protocol` setting wins**)
- `deepseek/deepseek-chat` → inferred as `deepseek`
- `google/gemini-2.5-pro-exp-03-25` → inferred as `gemini`

> **OpenRouter's `usage` field is OpenRouter's own accounting, not the underlying model's** (it may include cache-hit markers and other extensions). `$response->getUsage()` passes it through untouched.

**Live model status via OpenRouter:**

```php
$ai = AI::create([
    'protocol' => 'openrouter',
    'api_key'  => 'sk-or-v1-xxx',
]);
$models = $ai->setModel('openai/gpt-4o')->listModels(true);
// full model records including id, pricing, context_length …
```

---

### Other relays and aggregators

OpenRouter, SiliconFlow, ModelScope, Groq, Together, Fireworks, DeepInfra and NVIDIA NIM are built-in protocols (see the platform tables) — just set `protocol`. Other relays and self-hosted gateways speak the OpenAI-compatible protocol (`protocol=openai`) with a `base_url` or `endpoint`:

```php
// API2D (an OpenAI relay in China)
AI::create(['model'=>'gpt-4o', 'protocol'=>'openai', 'api_key'=>'fkxxxxx',
            'base_url'=>'https://openapi.api2d.com']);

// Cloudflare AI Gateway
AI::create(['model'=>'@cf/meta/llama-3-8b-instruct', 'protocol'=>'openai', 'api_key'=>'xxx',
            'base_url'=>'https://gateway.ai.cloudflare.com/v1/{account_id}/{gateway_id}');

// one-api / new-api (self-hosted aggregation gateway — the most common case)
AI::create(['model'=>'glm-4.6', 'protocol'=>'openai', 'api_key'=>'sk-xxx',
            'base_url'=>'https://gateway.example.com']);

// Self-hosted Anthropic-compatible gateway (Agent tool calling needs the claude protocol)
AI::create(['model'=>'my-agent-model', 'protocol'=>'anthropic', 'api_key'=>'k',
            'base_url'=>'http://127.0.0.1:8080/gw']);
// => http://127.0.0.1:8080/gw/v1/messages

// Intranet service (no key; private auth header instead)
AI::create(['model'=>'llama3', 'protocol'=>'openai',
            'base_url'=>'http://10.0.0.9:11434/v1',
            'headers' =>['Authorization'=>null, 'X-Internal-Token'=>'t']]);

// Built-in protocol + custom address: your own proxy, but still that platform's protocol and auth
AI::create(['model'=>'qwen-plus', 'protocol'=>'qwen', 'api_key'=>'sk-xxx',
            'base_url'=>'https://my-proxy.example.com/dashscope']);
```

All of the above support streaming, attachments, callbacks and every other feature.

> **Model-list endpoint**: `listModels()` follows `base_url` through the same gateway. When only `endpoint` is set, the library derives the model-list path from the chat endpoint (`.../v1/chat/completions` → `.../v1/models`). If your gateway uses an unusual path, override it completely with `endpoint_models` (which affects `listModels()` only). OpenRouter's model-list endpoint returns full pricing data — use `listModels(true)`.

The effective endpoint can be inspected at any time:

```php
echo $ai->resolveEndpoint();   // the endpoint resolved from the current config
```

---

## Driving the Claude Code CLI

`Ai\Cli\ClaudeCode` invokes the **`claude` executable** installed locally (Claude Code CLI), complementing `Ai\Protocol\Claude` (the Anthropic HTTP API). It is the right tool when you want the AI to work on files directly: read and write files, run tools, and — with the `acceptEdits` permission mode — actually edit your code.

### Quick start

```php
use Ai\Cli\ClaudeCode;

$cli = ClaudeCode::create([
    'workdir' => '/var/www',   // working directory (all file operations happen here)
]);

$response = $cli->chat('Check the syntax of index.php and fix anything broken');
echo $response->getContent();   // final text
echo $response->getSessionId(); // session ID
echo $response->getCostUsd();   // actual cost reported by the CLI (USD)
```

Default arguments (all verified against the real CLI):

```
claude --print --output-format stream-json --verbose
       --setting-sources user,project,local --no-chrome
       --allowedTools "Read Edit Write Grep Glob" --disallowedTools "Bash"
       --permission-mode acceptEdits  [--resume <session-id>]  < prompt.txt
```

- `--print` — non-interactive: run one query and exit
- `--setting-sources user,project,local` — load every settings source. **In print mode the CLI does not load them all by default**, and omitting this silently disables the project's `CLAUDE.md`, the permission rules in `.claude/settings.json`, and custom agents/skills. The official IDE plugin passes this flag too
- `--no-chrome` — disable Chrome integration (there is no browser on a server)
- `--allowedTools Read Edit Write Grep Glob` — **the no-prompt allowlist**, not "the set of available tools"
- `--disallowedTools Bash` — removes Bash from the tool set entirely so the model cannot see it (this is the hard disable)
- `--permission-mode acceptEdits` — file edits without confirmation
- The prompt is passed through a temporary file plus stdin redirection, avoiding command-line length limits (important when the SSH exec channel is restricted)

> `--allowedTools` and `--tools` mean different things: the former decides "which tools need no confirmation", the latter (`setTools()`) decides "which tools exist at all". To genuinely restrict capabilities use `setTools()` or `setDisallowedTools()`.

### Locating `claude`: auto-detection, caching, manual override

```php
$cli = ClaudeCode::create();              // auto-detect (with caching)
$cli->setBinary('/usr/local/bin/claude'); // manual override (highest precedence)

// Cache control
$cli->setBinaryCacheEnabled(true);      // enable the file cache (default true)
$cli->setBinaryCacheTtl(86400);         // cache lifetime in seconds (default 1 day)
$cli->clearBinaryCache();               // clear the cache and force re-detection
```

Detection order: `command -v claude` (PATH) → common install paths (`~/.local/bin`, Homebrew, `/usr/bin`) → the newest nvm version at `~/.nvm/versions/node/*/bin/claude` → `command -v` in a login shell. The result is cached in-process and on disk (default `sys_get_temp_dir()/ai_claude_binary_cache.json`) so PHP-FPM does not re-probe on every request.

### Custom CLI arguments

Every claude flag can be overridden, added or removed:

```php
$cli
    ->setFlag('allowedTools', 'Read Grep Glob')    // tighten tool permissions
    ->setFlag('model', 'claude-sonnet-4-5')         // pick a model
    ->setFlag('max-turns', 3)                       // cap the number of turns
    ->setFlag('include-partial-messages', true)     // token-level incremental events
    ->setFlag('max-budget-usd', 5)                  // per-turn spend cap (note: max-budget-usd)
    ->setFlag('system-prompt', 'You are a code review expert')
    ->removeFlag('verbose')                         // drop a default flag
    ->resetFlags();                                 // restore defaults
```

Flag names are underscore-insensitive: `setFlag('permission_mode', 'plan')` is the same as `--permission-mode plan`.

Multi-value flags are rendered according to each flag's own parsing rules, so you never assemble the string yourself: `setting-sources`/`tools`/`fallback-model` are comma-joined, `add-dir`/`mcp-config` take several values after one flag, and `plugin-dir`/`plugin-url` repeat the flag.

> **Note**: the `claude` CLI has no `--working-dir`, `--max-budget`, `--proxy` or `--theme` flags (verified — they are rejected). Use `setWorkdir()` for the working directory (turned into `cd … &&` internally) and the `HTTP_PROXY`/`HTTPS_PROXY` environment variables for proxying.

### Dedicated methods for common flags

Besides the generic `setFlag()`, common options have dedicated, value-checked methods:

```php
$cli
    // Permissions and tools
    ->setPermissionMode('acceptEdits')      // acceptEdits/auto/bypassPermissions/manual/dontAsk/plan
    ->setAllowedTools(['Read', 'Bash(git *)'])  // no-prompt allowlist, fine-grained patterns supported
    ->setDisallowedTools(['Bash'])          // hard denial (tool removed from the tool set)
    ->setTools(['Read', 'Grep', 'Glob'])    // restrict the available tool set; pass [] to disable all tools
    ->setAddDirs(['/data/shared'])          // extra directories accessible outside the working directory
    ->setSkipPermissions(false)             // --dangerously-skip-permissions

    // Model and reasoning
    ->setModel('claude-sonnet-5')
    ->setFallbackModel(['sonnet', 'haiku']) // degrade in order when the primary model is overloaded
    ->setEffort('high')                     // low/medium/high/xhigh/max
    ->setThinkingTokens(31999)              // thinking budget (the value the IDE plugin uses)

    // Cost and output
    ->setMaxBudgetUsd(0.5)                  // abort when over budget — strongly recommended for unattended runs
    ->setJsonSchema(['type' => 'object', 'properties' => [...]])  // structured output

    // Prompts and extensions
    ->setSystemPrompt('You are a code review expert')
    ->appendSystemPrompt('Always answer in English')
    ->setAgent('reviewer')
    ->setAgents(['reviewer' => ['description' => 'Code review', 'prompt' => '...']])
    ->setMcpConfig(['mcpServers' => ['fs' => ['command' => 'npx']]])
    ->setStrictMcpConfig()

    // Sessions and settings sources
    ->setSettingSources(['user', 'project'])   // pass [] to load no settings files at all
    ->setSettings('/path/settings.json')
    ->setFixedSessionId('550e8400-e29b-41d4-a716-446655440000')  // choose the new session ID
    ->setForkSession()                      // fork on resume instead of polluting the original session
    ->setContinueLast()                     // resume the most recent session in this directory
    ->setSessionPersistence(false)          // do not persist the session to disk

    // Output and diagnostics
    ->setPartialMessages()                  // token-level incremental events
    ->setIncludeHookEvents()
    ->setForwardSubagentText()
    ->setDebug('api,hooks')                 // --debug + --debug-to-stderr; logs arrive as stderr events
    ->setBare()                             // lean mode: skip hooks/LSP/CLAUDE.md auto-discovery
    ->setSafeMode();                        // disable all custom configuration (for troubleshooting)
```

### Structured output

`setJsonSchema()` forces the model's final output to match a schema; `getStructured()` hands you the array:

```php
$cli->setJsonSchema([
    'type'       => 'object',
    'properties' => [
        'severity' => ['type' => 'string'],
        'issues'   => ['type' => 'array', 'items' => ['type' => 'string']],
    ],
    'required'   => ['severity', 'issues'],
]);

$res = $cli->chat('Review src/User.php and list the issues per the schema');
$data = $res->getStructured();   // ['severity' => 'high', 'issues' => [...]]; null if parsing fails
```

### Streaming (event callbacks)

`runStream()` calls back per event, with the same event semantics as the official IDE plugin, so you can forward them straight to SSE:

```php
$cli->runStream('Refactor this code', function ($event, $data) {
    switch ($event) {
        case 'start':          break;  // ['resume' => bool]
        case 'init':           break;  // session init: cwd, session_id, available tools, MCP servers
        case 'text':           break;  // assistant text block (string)
        case 'thinking':       break;  // assistant thinking content (string)
        case 'tool_use':       break;  // ['id','name','input']
        case 'tool_result':    break;  // ['tool_use_id','content','is_error']
        case 'text_delta':     break;  // token-level text delta (requires setPartialMessages())
        case 'thinking_delta': break;  // token-level thinking delta (requires setPartialMessages())
        case 'rate_limit':     break;  // rate-limit status
        case 'system':         break;  // other system subtypes (thinking_tokens, compact_boundary …)
        case 'error':          break;  // this turn is marked is_error
        case 'message':        break;  // raw stream-json event (every event passes through here first)
        case 'stderr':         break;  // diagnostics (debug output arrives here once setDebug() is on)
        case 'result':         break;  // final summary
        case 'done':           break;
    }
});
```

The `result` event is the final summary: text comes from `result.result`, cost from `result.total_cost_usd` (measured by the CLI — no price table needed), and `isSuccess()` returns `false` when `is_error: true`.

Besides `getContent()` / `getSessionId()` / `getCostUsd()`, the response object offers `getStructured()`, `getThinking()`, `getToolUses()`, `getTools()`, `getPermissionDenials()`, `getSubtype()`, `getStopReason()`, `getInit()`, `getNumTurns()`, `getDurationMs()`, `getExitCode()` and `getCommand()`.

### Resuming multi-turn sessions

```php
$res = $cli->chat('First question');
$cli->setSessionId($res->getSessionId());   // the next turn resumes automatically via --resume

$cli->chat('Second question', ['reset' => true]);    // force a brand-new session
```

Whenever a run emits a new `session_id` it is written back internally, so `getSessionId()` is always current.

### Custom runner (remote / containerised setups)

Local execution uses `proc_open` by default. When claude must run on the host over SSH/SFTP (e.g. PHP inside a Docker container), inject a custom runner:

```php
$cli->setRunner(function ($cmd, $onChunk) {
    $stream = ssh2_exec($conn, $cmd);         // run the same command on the host
    $err = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
    while ($buf = fread($stream, 8192))       { $onChunk($buf, 'out'); }
    while ($buf = fread($err, 8192))          { $onChunk($buf, 'err'); }
    return 0;
});
```

Companion settings:
- `setShellPrefix('export LANG=en_US.UTF-8; ')` — inject environment (locale, nvm PATH …)
- `setPromptDir('/data/ai_prompt_tmp')` — directory for the prompt temp file. With a 1:1 container/host mount, point it at a path both sides can see (the `stdin` redirection in the command is resolved on the host side)
- For local execution the nvm directory containing claude is added to PATH automatically (`setAutoNvmPath(false)` disables this)

### Process behaviour: environment / signals / coroutines

All three apply to `ClaudeCode` and `ClaudeCodeSession` alike.

**The child process inherits the current environment by default** (`setInheritEnv(false)` turns it off)

When `proc_open` receives a non-null env array it **replaces** the child's environment wholesale rather than adding to it. Without inheritance the child has no `HOME` at all, so claude can only fall back to `/etc/passwd` to find the credentials under `~/.claude` — containers frequently have no matching entry there, and the symptom is a login state that "mysteriously disappears". `PATH` is likewise reduced to the shell's built-in default, which does not include a node installed via nvm.

Inheritance is complete: if the parent process sets `ANTHROPIC_API_KEY`, `CLAUDE_CODE_*` or similar, the child sees them too (which can change claude's billing and behaviour). Turn inheritance off when you need a clean environment, then supply exactly what you want with `setEnv()`.

**claude replaces the shell process** (`setExecReplace(false)` turns it off)

The command gives claude an `exec` prefix. Passing a string to `proc_open` runs it as `sh -c "<cmd>"`, and dash was measured not to apply the exec optimisation, leaving a two-level `sh → claude` process tree: the signal from `proc_terminate()` only reaches the intermediate sh, so after a timeout exception is thrown or `kill()` returns, claude is still running in the background all the way to the end of the turn, burning quota. With `exec`, the signal hits claude itself.

There is only one reason to turn it off: a custom runner appends something **after** the command string (e.g. `$cmd . '; echo done'` — once exec replaces the shell, nothing after it runs), or the caller already inserts its own `exec` (`exec exec cmd` fails outright with 127 under dash).

Its companion `setKillGrace(2)`: when terminating, SIGTERM is sent first and this grace period is allowed so claude can wind down and flush its session to disk (still `--resume`-able afterwards); SIGKILL follows only if it has not exited by then. Set 0 to kill outright.

**Replace the internal waiting in coroutine environments** (`setSleeper()`)

In long-running coroutine environments such as Swoole / Workerman, the library's internal `usleep` and `stream_select` pin the whole worker and every other request on it queues up behind you:

```php
$cli->setSleeper(function ($sec) { \Swoole\Coroutine::sleep($sec); });
```

Once set, the local execution polling interval, the duplex session's event pump and its shutdown path all go through it, and the session class's `stream_select` becomes "poll and yield" instead of blocking the whole thread.

### Introspection (version / login / models / quota)

All of this reads CLI-side information without starting a conversation and **consumes no model quota**:

```php
$cli = ClaudeCode::create();

// —— Sub-command queries (milliseconds) ——
$cli->getVersion();       // '2.1.222' (cached per instance)
$cli->isLoggedIn();       // true
$cli->getAuthStatus();    // ['loggedIn'=>true,'authMethod'=>'claude.ai','apiProvider'=>'firstParty',
                          //  'email'=>'...','orgId'=>'...','orgName'=>'...','subscriptionType'=>'max']
$cli->doctor();           // installation health report (raw text)
$cli->runCommand(['mcp', 'list']);   // any sub-command: ['exit_code'=>0,'stdout'=>'...','stderr'=>'']

// —— Control-protocol queries (seconds — a temporary claude process is started, queried and closed) ——
$cli->listModels();       // ['default','opus[1m]','claude-fable-5[1m]','sonnet','haiku','opus']
                          // the return value can be passed straight to setModel()
$cli->listModels(true);   // full entries: resolvedModel / displayName / description /
                          // supportsEffort / supportedEffortLevels / supportsFastMode …
$cli->getUsage();         // complete usage and rate-limit data
$cli->getRateLimits();    // condensed quota overview (see below)
$cli->getSettings();      // effective settings after merging user/project/local
$cli->getMcpServers();    // MCP server status list
$cli->getBinaryVersion(); // ['version'=>'2.1.222','buildTime'=>'2026-08-04T01:24:05Z']
```

`listModels()` follows the same convention as `Ai\AI::listModels()`: an array of directly usable identifiers by default, full records when passed `true` — ready for an admin dropdown.

**Quota**

```php
foreach ($cli->getRateLimits() as $limit) {
    printf("%-14s used %5.1f%%  resets in %s\n",
        $limit['key'],                        // session / weekly_all / weekly_scoped
        $limit['percent'],                    // percentage used
        gmdate('H:i:s', $limit['resets_in'])  // seconds until reset
    );
}
// session        used  16.0%  resets in 01:49:47
// weekly_all     used  17.0%  resets in 06:39:47
// weekly_scoped  used   0.0%  resets in 06:39:47
```

Each entry also carries `severity` (`normal` or a warning level), `resets_at` (ISO 8601) and `is_active`. On subscription billing the `limit_dollars`-style fields are `null` and only percentages are provided; when quota data is unavailable an empty array is returned.

Full structure of `getUsage()`:

| Key | Meaning |
|-----|---------|
| `session` | This process's `total_cost_usd`, duration, lines added/removed, per-model usage |
| `subscription_type` | Subscription tier, e.g. `max` / `pro` |
| `rate_limits` | Raw data per rate-limit window, a normalised `limits` array, and `extra_usage` top-up info |
| `behaviors` | `request_count`, `session_count` and similar statistics for the last day / week |

**Querying inside a session**: the same methods on `ClaudeCodeSession` reuse the running process, so `session.total_cost_usd` from `getUsage()` is the real cumulative spend of that session. `getSessionCost()` returns the same textual report as the interactive `/cost` command.

```php
$s = ClaudeCodeSession::create(['workdir' => '/var/www']);
$s->send('First question');
$s->send('Second question');

$usage = $s->getUsage();
echo $usage['session']['total_cost_usd'];   // cumulative cost of both turns
echo $s->getSessionCost();
// Total cost:            $0.0067
// Total duration (API):  36s
// Total code changes:    0 lines added, 0 lines removed
// Usage by model:
//     claude-haiku-4-5:  10 input, 52 output, 14.1k cache read, 2.5k cache write ($0.0067)
```

> The CLI's `get_context_usage` (context-window occupancy) only responds in the interactive UI and returns nothing in headless mode, so this library provides no method for it.

### Persistent duplex session (ClaudeCodeSession)

`Ai\Cli\ClaudeCodeSession` mirrors how the official IDE plugins (VSCode / JetBrains) drive the CLI: claude runs as a **long-lived process**, stdin keeps receiving JSON messages, stdout keeps emitting events, and tool permissions are decided by your PHP code in real time through the `control_request` protocol over stdio.

The plugin's observed launch arguments:

```
claude --output-format stream-json --verbose --input-format stream-json
       --max-thinking-tokens 31999 --permission-prompt-tool stdio
       --setting-sources=user,project,local --permission-mode auto
       --debug --debug-to-stderr --enable-auth-status --no-chrome
       --replay-user-messages
```

This class adopts the parts that suit a server context (duplex + stdio permission callbacks + all settings sources + message replay + Chrome disabled). The permission mode stays at the more conservative `acceptEdits`, and the thinking budget and debug logs are off by default — enable them with `setThinkingTokens(31999)` / `setDebug()` when needed.

```php
use Ai\Cli\ClaudeCodeSession;

$s = ClaudeCodeSession::create([
    'workdir'      => '/var/www',
    'turn_timeout' => 300,        // per-turn wait limit (seconds)
]);

// Real-time permission decisions — the equivalent of the IDE's "allow this?" dialog
$s->onPermission(function (array $req) {
    // $req: tool_name / display_name / input / description / tool_use_id / permission_suggestions
    if ($req['tool_name'] === 'Bash')  return 'Shell commands are forbidden here';  // string = deny with a reason
    if ($req['tool_name'] === 'Write' && strpos($req['input']['file_path'], '/etc/') === 0) {
        return false;                                                               // false = deny
    }
    return true;                                                                    // true = allow
    // You may also return ['behavior' => 'allow', 'updatedInput' => [...]] to allow with rewritten input
});

$a = $s->send('Show me the structure of src');
$b = $s->send('Add doc comments to the first file you listed');   // same process, context stays resident — no --resume replay
$s->close();
```

**How it differs from one-shot mode**

| | `ClaudeCode` | `ClaudeCodeSession` |
|---|---|---|
| Process | started and stopped per turn | long-lived, shared across turns |
| Multi-turn | history replayed via `--resume` | context stays in memory |
| Tool permissions | static flags | callback into PHP per call |
| Interruption | only by killing the process | graceful `interrupt()` |
| Runtime reconfiguration | not supported | hot-switch permission mode / model / thinking budget |
| New request mid-turn | not supported | `post()` injects into the running turn |
| Event loop | blocks inside the library | `tick()` is driven by your own loop |
| Restricted PHP environments | supports a custom runner (SSH) | local `proc_open` only |

**Sending a new request while a turn is still running (non-blocking event pump)**

`send()` blocks until the turn ends, so "the user has another request while claude is still working" is impossible with it — the caller's only execution flow is stuck waiting. `post()` separates *delivering* from *waiting* and hands the event loop to you, matching the interaction in the official IDE plugins:

```php
$s = ClaudeCodeSession::create(['workdir' => '/var/www'])
        ->setSleeper(function ($sec) { \Swoole\Coroutine::sleep($sec); });   // required in coroutine environments

$s->post('Add doc comments across src');   // returns immediately, does not wait for the turn

while ($clientAlive()) {
    while ($msg = $queue->pop()) {
        $s->post($msg);                     // same call in or out of a turn: in a turn = injected into it
    }

    $active = $s->tick($onEvent);           // pump a batch of events, return immediately

    if (!$active && ($res = $s->takeResult())) {
        $saveAnswer($res);                  // the turn is done; the process stays for the next one
    }
    $s->isTurnActive() ? $pause(0.02) : $pause(0.1);
}
$s->close();
```

| Method | Purpose |
|---|---|
| `post($text)` / `postMessage($blocks)` | deliver a user message without blocking; returns a local message ID |
| `tick($onEvent)` | process whatever output is readable now, dispatch events, return immediately; the return value tells you whether the turn is still running |
| `isTurnActive()` | whether a turn is in flight (drives the input box and stop button in your UI) |
| `takeResult()` | take the latest turn's result, clearing it; identical in shape to what `send()` returns |

Semantics of a mid-turn injection (measured on claude CLI 2.1.207):

- It takes effect **after the current tool call finishes**; it does not interrupt a running tool;
- The turn still produces exactly **one** `result` event, with `num_turns` accumulating — if you persist on `result`, that single record corresponds to "several user messages and one reply";
- The CLI re-emits a `system/init` event for every turn (near-instant from the second turn on), so `getInit()` being overwritten is expected;
- A persistent duplex process saves the 3–6 second cold start that "one process per turn plus `--resume`" pays every time.

Two events exist beyond those of `send()`: `posted` (delivered locally, `['id','content','injected']`, where `injected` marks a mid-turn injection) and `delivered` (the CLI received and replayed it, `['id','event']`). In the UI, mark a message "queued" when `post()` returns and switch to "delivered" when the `delivered` event arrives. Every other event is identical to `send()`.

`send()` is itself `post()` plus a `tick()` loop, so the two APIs mix freely and can share the same persistence code.

**Interruption and runtime control**

`interrupt()` is the "stop" button — and it only became genuinely usable once the API went non-blocking (previously the only place you could call it was inside `send()`'s event callback). It lets claude wind down and record its session, keeping the process alive for the next turn; `kill()` takes the process down instead (SIGTERM first, then SIGKILL after a 2 second grace period, see `setKillGrace()`), giving claude no chance to wind down.

```php
$fired = false;
$res = $s->send('Refactor the entire project', function ($ev, $d) use ($s, &$fired) {
    if ($ev === 'tool_use' && !$fired) {
        $fired = true;
        $s->interrupt();          // like the IDE's "stop" button; the process survives for the next turn
    }
});
echo $res->getSubtype();          // error_during_execution

$s->setPermissionMode('plan');    // hot-switch while running (before start, it only changes the launch flags)
$s->switchModel('claude-haiku-4-5-20251001');
$s->switchThinkingTokens(31999);
$s->control(['subtype' => 'set_cwd', 'cwd' => '/srv/app']);   // send any control_request
```

**Limits of the permission callback (important)**

`onPermission()` only receives calls the CLI considers worth asking about. It is **not a complete interception layer**. The CLI allows these on its own without consulting PHP:

- Rules already pre-authorised in settings files (`~/.claude/settings.json`, the project's `.claude/settings.json`) — use `setSettingSources([])` to load none of them
- Categories the current `permission-mode` already allows (file edits under `acceptEdits`, for example)
- Commands the CLI deems read-only and sandboxed (verified: `Bash(echo hi)` is not asked about even under `permission-mode manual`)

To **hard-block** a tool, use `setDisallowedTools(['Bash'])` (the tool is removed from the tool set and the model never sees it) or restrict the set with `setTools([...])`. Do not rely on the callback alone.

With no `onPermission()` registered, only `Read / Edit / Write / Grep / Glob` are auto-approved and everything else is denied. `setAutoApproveTools([...])` changes that list; `allowAllTools()` hands the decision back to the CLI entirely.

Other session methods: `start()`, `isRunning()`, `close()`, `kill()`, `sendMessage($contentBlocks)`, `getInit()`, `getAvailableTools()`, `getCommand()`. Every argument method from `ClaudeCode` is available on the session class too (set them before the first `send()`), including `setSleeper()`, `setInheritEnv()`, `setExecReplace()` and `setKillGrace()` (see "Process behaviour").

### Requirements

- Claude Code CLI installed (`npm install -g @anthropic-ai/claude-code` or a native install)
- Local execution requires `proc_open`; `shell_exec` is only used as a fallback for path detection and is skipped when unavailable
- `ClaudeCodeSession` needs `proc_open`'s bidirectional pipes and does not support a custom runner (in coroutine environments `setSleeper()` is enough to yield — you do not need to take over the process)

---

## Streaming (SSE)

After calling `setStream(true)`, `chat()` **writes SSE frames straight to the output buffer** as data arrives (setting response headers and disabling Nginx buffering automatically) — no callback needed:

```php
$ai = AI::create(['model' => 'deepseek-chat', 'api_key' => 'sk-xxx']);

$response = $ai->setStream(true)->chat('Write an article about artificial intelligence');

// The full content and token counts are still available after the stream ends
$full = $response->getContent();
$used = $response->tokens();
```

The wire format the server emits (a fixed protocol your front end parses):

```
data: {"type":"stream_chunk","content":"Artificial"}

data: {"type":"stream_chunk","content":" intelligence"}

data: {"type":"stream_end","data":{"content":"Artificial intelligence …","model":"deepseek-chat","usage":{"prompt_tokens":12,"completion_tokens":300,"total_tokens":312}}}

data: [DONE]
```

The matching front-end consumer:

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

**Real-world use** — a back-office chat endpoint with proxy, attachments and streaming:

```php
$ai = new \Ai\AI();

if (!empty($siteConfig['PROXY_SOCKS5'])) $ai->setProxy($siteConfig['PROXY_SOCKS5']);

try {
    $ai->setStream(true)
       ->setConfig(['model' => $model, 'api_key' => $apiKey])
       ->setAttachments($attachments)
       ->chat($message);
} catch (\Ai\Exceptions\AIException $e) {
    // Once streaming has started, the error can only go out through the stream too
    echo "data: " . json_encode(['type' => 'error', 'message' => $e->getMessage()]) . "\n\n";
}
```

> **PHP environment note**: streaming flushes and closes every output buffer. If you use session locking (`session_start()`), call `session_write_close()` before streaming starts, or the same user's other requests will block.

### Long-running frameworks (Swoole / Workerman): `setStreamCallback()`

The default behaviour echoes into the output buffer, which **only works under PHP-FPM / CLI**. In Swoole, Workerman or RoadRunner, `echo` lands in the process's stdout (a log file) and never reaches the client. In those environments register a callback and emit the frames yourself:

```php
$ai->setStream(true)->setStreamCallback(function($event) use ($response) {
    if ($event['type'] === 'stream_chunk' && $event['content'] !== null) {
        // $response is a Swoole\Http\Response; write() sends a chunk
        $response->write('data: ' . json_encode(['content' => $event['content']]) . "\n\n");
    }
    if ($event['type'] === 'stream_end') {
        $response->write('data: ' . json_encode($event['data']) . "\n\n");
    }
});

$full = $ai->chat($messages)->getContent();   // unchanged: still the fully assembled text
```

Once a callback is registered the library produces no output of its own, and the SSE frame format is entirely yours. Event structure:

| Field | Meaning |
|-------|---------|
| `type` | `stream_chunk` for a delta, `stream_end` for the end |
| `content` | The delta text of a `stream_chunk`, **already normalised by the protocol layer** and identical across platforms; `null` for chunks with no text |
| `raw` | The platform's raw chunk array — only needed for vendor-specific fields |
| `data` | The `stream_end` summary: full `content`, `model`, `usage` |

Pass `null` (`setStreamCallback(null)`) to restore the default direct output.

### Platform coverage: all 40 protocols

"Plain chat + streaming + token accounting" is baseline for every protocol, and `tests/stream_test.php` replays each platform's real SSE frames to verify it. All the test suites are **offline, need no API key, and do not require PHPUnit**:

```bash
php tests/smoke_test.php    # every class loads/instantiates; inheritance signatures compatible
php tests/stream_test.php   # 40 protocols × plain chat / streaming / token accounting
php tests/tools_test.php    # cross-platform tool-calling consistency
php tests/lib_test.php      # concurrent batching / Memory concurrency safety / pricing / log injection
php tests/cli_test.php      # CLI flag rendering and command-injection protection
php tests/ssrf_test.php     # every known SSRF bypass vector

composer test               # run all of the above in order
composer analyse            # PHPStan level 8 static analysis (clean)
composer compat             # PHP 7.1 compatibility scan (PHPCompatibility)
composer check              # run all three of the above
```

CI runs the same checks on **PHP 7.1 / 7.2 / 7.4 / 8.0 / 8.2 / 8.4**, plus static analysis and the PHP 7.1 compatibility scan on 8.2. The minimum version, 7.1, runs the whole test suite for real — not just a syntax check.

> Every public method carries full phpdoc types (PHP 7.1 has no typed properties, so types live in comments). IDEs autocomplete the return shapes of methods like `getToolCalls()` correctly.

Wire-format differences that are handled in the transport layer (each one was a real bug at some point):

| Difference | Notes |
|------------|-------|
| No space after `data:` | The space after the colon is optional in the SSE spec, and platforms such as iFlytek Spark omit it. Accepting only the spaced form yields an entirely empty stream |
| CRLF line endings | Some gateways and proxies rewrite newlines |
| No trailing newline | The last frame — often exactly the one carrying `usage` — must not be dropped when it lacks a newline |
| Interleaved `event:` / `id:` fields | The Anthropic protocol prefixes every frame with `event:`; these must be skipped correctly |
| Usage split across frames | Anthropic puts `input_tokens` in `message_start` and `output_tokens` in `message_delta`, so they must be merged across frames |
| HTTP 200 with an error inside the stream | MiniMax (`base_resp`), the OpenAI family (`error`) and others still return 200 on failure — this must raise an exception, not return empty content |

The three standard `usage` fields — `prompt_tokens`, `completion_tokens`, `total_tokens` — are consistently available on every platform (Anthropic's `input_tokens` / `output_tokens` are mapped automatically), and vendor-specific fields are preserved.

`getStopReason()` and `getToolCalls()` work in streaming mode too:

```php
$resp = $ai->setStream(true)->chat(['messages' => $msgs, 'tools' => $toolDefs]);

$resp->getStopReason();   // end_turn / max_tokens / tool_use … (use it to detect truncation)
$resp->getToolCalls();    // chunks already reassembled; identical to the non-streaming shape
```

> **Custom protocols** may implement four optional streaming hooks (streaming still works without them; you simply do not get that information):
>
> | Hook | Purpose |
> |------|---------|
> | `parseStreamUsage(array $chunk): ?array` | Token usage in this frame; the AI layer merges frames |
> | `parseStreamError(array $chunk): ?string` | Error in this frame; anything non-empty raises an exception |
> | `parseStreamStopReason(array $chunk): ?string` | Stop reason in this frame (already normalised) |
> | `parseStreamToolCalls(array $chunk): ?array` | Tool-call fragments in this frame: `[index => ['id'=>, 'name'=>, 'arguments'=>fragment]]`; the AI layer joins them by index |

---

## Attachments (multimodal)

```php
use Ai\Helpers\AIFile;

$image = AIFile::fromPath('/path/to/image.jpg');            // local file, MIME detected automatically
$image = AIFile::fromPath($tmpName, $_FILES['x']['type']);  // explicit MIME
$image = AIFile::fromUrl('https://example.com/image.jpg');  // remote URL

$response = $ai->setAttachments([$image])->chat('Describe this image');
```

Attachment formatting is handled by the **model layer**: vision models (`gpt-4o` …) get each platform's image block format, while models without multimodal support (the DeepSeek family, for instance) receive the attachment information appended as text to the last user message, so the request does not simply fail.

Attachments are cleared after every `chat()` and never leak into the next turn.

---

## Conversation context

Two approaches — pick one:

### Option 1: set `rounds` and let the library manage it (recommended)

```php
$ai = AI::create([
    'protocol' => 'qwen',
    'model'    => 'qwen-plus',
    'api_key'  => 'sk-xxx',
    'rounds'   => 5,          // keep the last 5 turns of context; default 0 = disabled
]);

echo $ai->chat('My name is Alice')->getContent();
echo $ai->chat('What is my name?')->getContent();   // the model answers "Alice"
```

`rounds` defaults to **0 (disabled)**, in which case the library does not touch history at all and behaves exactly as older versions did.

For **multi-user or long-running processes**, isolate context with `setSessionId()` — one AI instance can serve many conversations:

```php
$ai->setSessionId('user-1001')->chat('I like coffee');
$ai->setSessionId('user-2002')->chat('I like tea');       // completely separate from the previous session

$ai->setSessionId('user-1001')->chat('What do I like to drink?');  // answers "coffee"
```

History lives in memory and disappears when the process exits. Persist it yourself to survive across requests:

```php
// Save at the end of the request
$redis->set("ai:history:{$uid}", json_encode($ai->exportHistory()));

// Restore on the next request
$ai->importHistory(json_decode($redis->get("ai:history:{$uid}"), true) ?: []);
```

| Method | Purpose |
|--------|---------|
| `setSessionId(string)` / `getSessionId()` | Switch/read the current session; each session keeps its own history |
| `getHistory()` / `setHistory(array)` | Read/write the current session's messages |
| `clearHistory(bool $all = false)` | Clear the current session; pass `true` to clear all sessions |
| `exportHistory()` / `importHistory(array)` | Export/import every session, for persistence |

Trimming works in **turns**, not messages: a turn starts at a genuine user question, and messages containing only `tool_result` belong to the previous turn's tool call. This keeps `tool_use` and its matching `tool_result` together — separating them makes the next request fail outright at the platform.

> `chatBatch()` neither reads nor writes history: every item is an independent request, so sharing one context would be meaningless.

### Option 2: manage `messages` yourself

Without `rounds` the library stays out of the way entirely:

```php
$messages = [];
$messages[] = ['role' => 'user', 'content' => 'My name is Alice'];
$resp = $ai->chat(['messages' => $messages]);

$messages[] = ['role' => 'assistant', 'content' => $resp->getContent()];
$messages[] = ['role' => 'user', 'content' => 'What is my name?'];
$resp = $ai->chat(['messages' => $messages]);
```

Use this when you need full control over the trimming strategy (by token count, by summarising importance, and so on).

## Before/after callbacks

```php
$ai->onBefore(function (&$payload) {
    // Before the request: audit or rewrite the payload
    log_message('debug', json_encode($payload));
    $payload['temperature'] = 0.5;
});

$ai->onAfter(function ($response) {          // onResponse() is an alias
    // After the request: count tokens, write to a usage table
    log_usage($response->getUsage());
});
```

Exceptions thrown inside a callback are caught and written to `error_log`; they never interrupt the main flow.

---

## Response object

`chat()` returns an `Ai\Contracts\AIResponseInterface`:

| Method | Purpose |
|--------|---------|
| `getContent(): string` | Reply text (the accumulated full text in streaming mode) |
| `getRaw(): array` | The platform's raw response body (this is how the Agent parses `tool_use` blocks) |
| `getUsage(): array` | Full usage object with `prompt_tokens` / `completion_tokens` / `total_tokens` plus vendor extensions (`prompt_tokens_details.cached_tokens`, `cache_creation_input_tokens` … — varies by platform) |
| `tokens(): int` | Total tokens |
| `getModel(): string` | The model name actually returned |
| `isSuccess(): bool` | Success flag |
| `cost(array $pricing): float` | Estimated cost, **per thousand tokens** by default (matching older versions so existing accounting is unaffected) |
| `costPerMillion(array $pricing): float` | Same, but **per million tokens** so you can copy vendor pricing directly, e.g. `['prompt'=>5.0,'completion'=>25.0,'cached'=>0.5]`. `cached` is the cache-hit input price; both protocol families' field names are recognised |
| `getToolCalls(): array` | Tool calls requested by the model, normalised: `[['id'=>..,'name'=>..,'input'=>[..]]]` |
| `hasToolCalls(): bool` | Whether this turn requests a tool call |
| `getStopReason(): string` | Normalised stop reason: `end_turn` / `tool_use` / `max_tokens` / `content_filter` / `refusal` |
| `toAssistantMessage(): array` | Convert to an assistant turn ready to append to `messages` |
| `getError(): string` | Failure reason (only populated where exceptions are not thrown, such as `chatBatch()`) |
| `toArray()` / `__toString()` | Convert to array / use directly as a string |

---

## Error handling

```php
use Ai\Exceptions\AIException;
use Ai\Exceptions\ConfigException;
use Ai\Exceptions\RequestException;

try {
    $response = $ai->chat($prompt);
} catch (ConfigException $e) {
    // Configuration error: unknown model, no model set, protocol not initialised
} catch (AIException $e) {
    // Request failure: network, auth, rate limit or platform error — all wrapped into AIException inside chat()
    echo $e->getMessage();
    echo $e->getPlatform();      // failing platform, e.g. 'openai'
    echo $e->getErrorCode();     // platform error code
    print_r($e->getRawResponse()); // the platform's raw error response — the key to diagnosing anything
}
```

`RequestException` is raised by the transport layer; `chat()` converts it into an `AIException` carrying platform information, so business code normally only catches `AIException`.

---

## Robustness: timeouts and automatic retries

The most frequent AI-API failure is not "cannot connect" but **429 rate limits** and **transient 5xx errors**. Both are handled by default:

```php
$ai->setTimeout(120);         // default 120s (reasoning models often run a minute or two; 60s kills good requests)
$ai->setRetry(2);             // retry twice by default; pass 0 to disable
$ai->setRetry(3, 800, 30000); // 3 retries, 800 ms backoff base, 30 s cap per wait
```

| Situation | Behaviour |
|-----------|-----------|
| 408 / 409 / 429 / 500 / 502 / 503 / 504 / 529 | Retried automatically |
| Response carries `Retry-After` (seconds or an HTTP date) | The server's value wins, with an upper bound |
| No `Retry-After` | Exponential backoff with jitter (so several rate-limited processes do not all retry at once) |
| Other 4xx such as 400 / 401 / 403 | **No retry** — the exception is raised immediately |
| Streaming requests | **No retry** — chunks have already reached the caller, and retrying would duplicate output |
| Request body with non-UTF-8 bytes | Immediate `RequestException` explaining why (previously this silently sent an empty body) |

To use a connection pool, swap the HTTP client, or inject a fake transport in unit tests, replace the whole transport layer:

```php
$ai->setTransport(new MyTransport());   // implement Ai\Contracts\TransportInterface
```

### Concurrent batching: `chatBatch()`

Bulk translation, summarisation or tagging done serially costs "one item × N", and every item repeats the TLS handshake. `chatBatch()` uses `curl_multi`, so total time is roughly "the slowest item × number of batches":

```php
$results = $ai->chatBatch([
    'title' => 'Translate to English: 你好',
    'intro' => 'Translate to English: 世界',
    'body'  => ['messages' => [['role' => 'user', 'content' => 'Translate: 再见']]],
], 5);   // the second argument is concurrency, default 5

foreach ($results as $key => $r) {
    if ($r->isSuccess()) {
        echo $key, ': ', $r->getContent(), "\n";
    } else {
        echo $key, ' failed: ', $r->getError(), "\n";   // one failure does not affect the others
    }
}
```

- Results **map one-to-one onto the input keys and keep their order**, so they line up with the original array
- **A single failure raises no exception**: you get a response whose `isSuccess()` is false and whose `getError()` explains why. A batch should not discard finished results because one item failed
- Streaming is not supported (concurrent streams would interleave their chunks)
- High concurrency invites platform rate limits — pair it with `setRetry()`

### Logging: plug in your own system

The library no longer hard-codes `error_log()`. Once injected, model-list fetch failures, streaming callback errors and similar all go to your logger:

```php
use Ai\Helpers\Log;

Log::setLogger($monolog);                  // any PSR-3-style object (without depending on psr/log)
Log::setLogger(function ($level, $message, array $context) {
    log_message($level, '[AI] ' . $message . ' ' . json_encode($context));
});
Log::setLogger(null);                      // back to the default error_log
```

---

## Agent: the tool-calling loop

`Ai\Agent\Agent` implements the full agentic loop: the model decides which tool to call → the library runs it → the result is fed back → repeat, until the model produces a final answer or the iteration cap is reached. Since v2.0 it runs on an `AgentRuntime` architecture with an object-oriented tool interface, loop guard, permission system, context compaction, session persistence, sub-agents, and budget control.

**All 40 protocols work.** The vendors express the same idea in two different shapes; the library absorbs the difference in the protocol layer:

| | OpenAI family (36 protocols) | Anthropic family (4 protocols) |
|---|---|---|
| Tool definition | `{type:'function', function:{name, parameters}}` | `{name, description, input_schema}` |
| Model request | `message.tool_calls[]`, `arguments` is a JSON **string** | a `tool_use` block in content, `input` is an **array** |
| Result feedback | a separate `{role:'tool', tool_call_id}` message | a `tool_result` block in a user message |
| Stop reason | `finish_reason: 'tool_calls'` | `stop_reason: 'tool_use'` |
| System prompt | first `role:'system'` message | top-level `system` field |

Your code writes **one** version (the library's unified format, modelled on Anthropic's), and switching platforms only changes `protocol`:

```php
$agent = (new Agent($ai))->setTools($tools)->onEvent($handler);
$agent->run([['role' => 'user', 'content' => "What's the weather in Beijing?"]]);

// The code above needs no changes when $ai is any of these:
AI::create(['protocol' => 'qwen',     'model' => 'qwen-plus',       'api_key' => '...']);
AI::create(['protocol' => 'zhipu',    'model' => 'glm-4.6',         'api_key' => '...']);
AI::create(['protocol' => 'doubao',   'model' => 'doubao-seed-1-6', 'api_key' => '...']);
AI::create(['protocol' => 'openai',   'model' => 'gpt-4o',          'api_key' => '...']);
AI::create(['protocol' => 'claude',   'model' => 'claude-opus-5',   'api_key' => '...']);
```

See `examples_agent.php` for a runnable example; cross-platform consistency is enforced by `tests/tools_test.php`.

**Streaming Agents**: with `setStream(true)` each turn's text reaches your callback in real time while tool calling keeps working — the library reassembles the `tool_calls` that platforms send in fragments (the OpenAI family joins `arguments` strings by `delta.tool_calls[].index`; the Anthropic family joins `content_block_start` + `input_json_delta`). This suits chat UIs: the user watches the model talk and reach for tools at the same time.

```php
$ai->setStreamCallback(function ($event) use ($response) {
    if ($event['type'] === 'stream_chunk' && $event['content'] !== null) {
        $response->write('data: ' . json_encode(['content' => $event['content']]) . "\n\n");
    }
});

(new Agent($ai))
    ->setStream(true)          // off by default, matching older versions
    ->setTools($tools)
    ->run([['role' => 'user', 'content' => "What's the weather in Beijing?"]]);
```

The Agent only **borrows** your AI instance: it restores `setStream()` to its previous value when finished (including on the exception path), so unrelated `chat()` calls afterwards are unaffected.

> You may also write native OpenAI format (`{type:'function'}` tool definitions, `role:'tool'` messages) — the library recognises it and converts to the target platform's structure, so existing code need not be migrated.

### Driving the loop yourself, without Agent

`AIResponse` exposes platform-neutral accessors:

```php
$resp = $ai->chat(['messages' => $messages, 'tools' => $toolDefs]);

if ($resp->hasToolCalls()) {                      // identical on every platform
    $messages[] = $resp->toAssistantMessage();    // append the model's turn back into the context

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

### Tool definitions

```php
$tools = [
    'sql_query' => [
        'description'  => 'Run a read-only SQL query. SELECT/SHOW/DESCRIBE/EXPLAIN only, max 200 rows.',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'sql' => ['type' => 'string', 'description' => 'the SQL to run'],
            ],
            'required'   => ['sql'],
        ],
        'handler' => function (array $input) {
            $sql = trim((string)($input['sql'] ?? ''));
            if (!is_readonly_sql($sql)) return 'ERROR: read-only queries only';
            return json_encode(db_query($sql), JSON_UNESCAPED_UNICODE);
        },
    ],
];
```

Whatever `handler` returns is fed back to the model as a `tool_result`. Exceptions it throws are converted into `ERROR: <message>` and handed to the model, so the loop is never interrupted.

### Running it

```php
$ai = new \Ai\AI();
$ai->setConfig([
    'model'      => 'deepseek-anthropic',
    'api_key'    => $apiKey,
    'max_tokens' => 1024 * 64,
]);

$emit = function ($ev) {
    // Push the Agent's progress to the front end in real time
    echo "data: " . json_encode($ev, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
};

$agent = (new \Ai\Agent\Agent($ai))
    ->setSystem($systemPrompt)
    ->setTools($tools)
    ->setMaxIter(128)      // page-by-page analysis needs plenty of iterations; default 25
    ->onEvent($emit);

$agent->run([['role' => 'user', 'content' => $message]]);

$reply = $agent->lastText();   // the final natural-language reply
```

### Event types

The `onEvent()` callback receives, in order:

| Event | Fields | Meaning |
|-------|--------|---------|
| `thinking` | `iter` | iteration N is starting |
| `agent_text` | `text` | natural language emitted by the model |
| `tool_call` | `name`, `input` | the model decided to call a tool |
| `tool_error` | `name`, `message` | a tool threw an error (already fed back to the model) |
| `tool_permission` | `name`, `input`, `request_id` | waiting for user approval |
| `context_compact` | `tokens`, `messages` | context compaction is starting |
| `context_compact_done` | `messages` | compaction completed |
| `done` | — | finished normally |
| `error` | `message` | failed, or hit the iteration cap |

Every event carries these extra fields, useful for SSE / WebSocket reconnection:

| Field | Type | Meaning |
|-------|------|---------|
| `id` | `string` | auto-incrementing event ID (`evt_1_xxx`) |
| `session_id` | `string` | session ID |
| `agent_id` | `string` | agent identifier |
| `turn_id` | `string` | current iteration turn |
| `timestamp` | `float` | Unix timestamp (microsecond precision) |

Fine-grained events inside a tool (diffs, progress …) are emitted by each `handler` through its own closure; the library makes no assumptions.

### Execution result (AgentResult)

`Agent::run()` returns `void` for backward compatibility — use `$agent->getRuntime()->run()` to get the structured `AgentResult`:

```php
// Legacy: use run() then lastText()
$agent->run([['role' => 'user', 'content' => "What's the weather in Beijing?"]]);
echo $agent->lastText();

// Modern: get the full AgentResult
$result = $agent->getRuntime()->run($messages);

echo $result->getText();           // the model's final reply
echo $result->getStopReason();     // end_turn / max_iter / no_progress / ...
echo $result->getIterations();     // number of iterations
print_r($result->getUsage());      // token usage

if ($result->isDone()) {
    echo 'Normal termination';
} elseif ($result->isError()) {
    echo 'Abnormal stop: ' . $result->getStopReason();
}
```

| Method | Returns | Description |
|--------|---------|-------------|
| `getText()` | `string` | The model's final natural-language reply |
| `getStopReason()` | `string` | Stop reason (see StopReason) |
| `getToolCalls()` | `array` | Sequence of tool calls this turn |
| `getUsage()` | `array` | Cumulative token usage |
| `getIterations()` | `int` | Actual iteration count |
| `isDone()` | `bool` | Whether termination was normal |
| `isError()` | `bool` | Whether termination was abnormal |

### AgentToolInterface: object-oriented tool definitions

Since v2.0 tools can be written as objects implementing `Ai\Agent\Tool\AgentToolInterface`:

```php
use Ai\Agent\Tool\AgentToolInterface;
use Ai\Agent\Tool\ToolContext;
use Ai\Agent\Tool\ToolResult;

class ReadFileTool implements AgentToolInterface
{
    public function name()        { return 'read_file'; }
    public function description() { return 'Read a file inside the workspace'; }
    public function schema()      { return ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]]; }
    public function execute(array $input, ToolContext $context): ToolResult
    {
        return ToolResult::success('file content');
    }
}

$agent->setTools([new ReadFileTool()]);
```

Object tools and legacy closure tools can be mixed:

```php
$agent->setTools([
    new ReadFileTool(),                    // object
    'get_weather' => [                     // closure (legacy format)
        'description'  => 'Get the weather',
        'input_schema' => [...],
        'handler'      => function (array $in) { return 'sunny'; },
    ],
]);
```

#### Parallel safety marker

Implement `ParallelSafeToolInterface` to mark a tool as safe for parallel execution — read-only tools (read_file / grep / glob) can run concurrently:

```php
use Ai\Agent\Tool\ParallelSafeToolInterface;

class ReadFileTool implements AgentToolInterface, ParallelSafeToolInterface
{
    public function isParallelSafe() { return true; }
}
```

Tools without this interface default to "not parallel-safe" (safety first).

### ClaudeCodeTools: built-in tool factory

`Ai\Agent\Tools\ClaudeCodeTools` provides a one-call way to create the full set of built-in tools, aligned with the Claude Code CLI default toolset:

```php
use Ai\Agent\Tools\ClaudeCodeTools;

// All tools: Read / Write / Edit / Glob / Grep / Bash
$agent->setTools(ClaudeCodeTools::all([
    'workdir' => '/var/www/project',
]));

// Read-only toolset (for plan mode): Read / Glob / Grep
$agent->setTools(ClaudeCodeTools::readOnly([
    'workdir' => '/var/www/project',
]));
```

The six built-in tools:

| Tool | Description | Parallel-safe |
|------|-------------|---------------|
| `read_file` | Read a file, with offset/limit pagination | ✅ |
| `write_file` | Create or overwrite a file, auto-creates directories | ❌ |
| `edit_file` | Precise local replacement using str_replace semantics | ❌ |
| `glob` | Match file paths by glob pattern | ✅ |
| `grep` | Search file contents by pattern | ✅ |
| `bash` | Execute a shell command, auto-terminates on timeout | ❌ |

All file tools are protected by the `PathSafety` sandbox — they cannot escape the workdir.

#### Output truncation: the Text helper

Tool output, command echoes and diff bodies all need a length cap. `substr()` can split a CJK character in half (invalid UTF-8 makes `json_encode()` fail), while `mb_substr()` counts characters and so cannot enforce a byte budget — the built-in tools therefore all go through `Ai\Helpers\Text`:

```php
use Ai\Helpers\Text;

Text::cutBytes($output, 1024);      // byte cap, never splits a character (tool output, diffs)
Text::cutChars($summary, 200);      // character cap (human-facing summaries)
Text::ellipsis($text, 200);         // character cap plus an ellipsis, added only when it truncated
Text::isValidUtf8($text);           // validation
Text::length($text);                // character count
```

Anything your own tools feed back into the model should use `cutBytes()`.

### Permission system

The Agent has 6 built-in permission modes that control how tool calls are gated:

| Mode | Description |
|------|-------------|
| `manual` | Read-only tools allowed automatically; dangerous tools (bash) ask the user |
| `auto` | Everything allowed automatically |
| `plan` | Only read-only tools allowed; the rest are denied |
| `accept_edits` | File edits allowed; high-risk operations like bash ask |
| `dont_ask` | Auto-allow (no prompting) |
| `bypass` | Allow everything (⚠️ unsafe — do not use with untrusted input) |

```php
$agent->setPermissionMode('plan');      // read-only mode
$agent->setPermissionMode('manual');    // dangerous tools ask (default)
$agent->setPermissionMode('bypass');    // allow everything
```

#### Custom rules

On top of the mode, you can add fine-grained rules with `allowTool` / `denyTool`. Priority: deny > allow > mode default:

```php
use Ai\Agent\Permission\PermissionManager;

$pm = new PermissionManager('manual');
$pm->allowTool('read_file');                           // allow all read_file calls
$pm->allowTool('write_file', ['path' => '/var/www/*']); // only write to the specified directory
$pm->denyTool('bash', ['command' => 'rm -rf *']);       // deny dangerous commands

$agent->getRuntime()->setPermission($pm);
```

Or use the shortcut:

```php
$agent->setPermissionMode('manual');
```

#### User approval flow

When the permission manager returns `ask`, the Agent pauses with a `PERMISSION_DENIED` stop signal, waiting for the business layer to respond via `approve()` / `deny()`:

```php
$messages = [['role' => 'user', 'content' => 'List files']];  // keep a copy of messages

$agent->onEvent(function ($event) {
    if ($event['type'] === 'tool_permission') {
        // Show an approval dialog in the frontend
        echo "Authorisation requested: {$event['name']}(" . json_encode($event['input']) . ")";
        $requestId = $event['request_id'];
    }
});

// Use getRuntime()->run() to obtain an AgentResult
$result = $agent->getRuntime()->run($messages);

// Resume execution via approve() / deny()
if ($result->getStopReason() === 'permission_denied') {
    $requestId = $result->getExtra()['request_id'] ?? '';

    // Approve: pass the saved message copy, Agent continues
    $result = $agent->approve($requestId, $messages);
    // or
    $result = $agent->deny($requestId, 'Not needed', $messages);
}
```

### Context compaction

The Agent automatically detects when the context window is getting full and compresses old history into a summary using the AI model itself, preventing token overflows. Compaction is turn-aware — it groups messages by Agent Turn (user input → assistant response → tool results), so `tool_use` and `tool_result` blocks are never split apart:

```php
use Ai\Agent\Context\ContextManager;

$agent->getRuntime()->setContextManager(new ContextManager(
    $messages,
    [
        'maxTokens'  => 100000,   // compaction threshold (default 100k)
        'threshold'  => 0.8,      // trigger when >80% full
        'keepRecent' => 10,       // keep the 10 most recent messages
    ]
));
```

The compaction process emits `context_compact` / `context_compact_done` events so the frontend can show progress.

### Session persistence

Agent sessions can be persisted to the filesystem for cross-request resume (ideal for PHP-FPM):

```php
use Ai\Agent\Session\FileSessionStore;
use Ai\Agent\Session\SessionManager;

$store = new FileSessionStore('/tmp/agent_sessions');

$agent
    ->setSessionId('user-abc-123')
    ->setSessionManager(new SessionManager($store));

$agent->run($messages);

// On the next request, resume the session
$session = $store->load('user-abc-123');
if ($session) {
    $agent->run($session->getMessages());
}
```

Session state lifecycle: `running` → `paused` (waiting for approval) → `running` (resumed) → `completed` / `interrupted`.

### Sub-agents

Register sub-agents via `SubAgentManager`. The main Agent can spawn them at runtime using the `spawn_agent` tool — each sub-agent has its own isolated context, so the main Agent's context never grows from sub-agent work:

```php
use Ai\Agent\SubAgent\SubAgentManager;
use Ai\Agent\Tools\ReadFileTool;
use Ai\Agent\Tools\PathSafety;

$sam = new SubAgentManager($ai);
$sam->register('code-reviewer', [
    'description' => 'Review code quality',
    'prompt'      => 'You are a code review expert. Find security issues and performance problems.',
    'tools'       => [new ReadFileTool(new PathSafety('/var/www'))],
    'max_iter'    => 10,
]);

$agent->getRuntime()->setSubAgentManager($sam);
```

Sub-agents inherit the parent Agent's permissions and cannot exceed them.

#### Background mode (Background SubAgent)

The `spawn_agent` tool supports a `background` parameter. When `true`, the main Agent does not block waiting for the sub-agent to complete — it receives a `task_id` immediately and continues its own work:

```php
$sam->setBackgroundRunner(function ($task) {
    // Execute asynchronously in Swoole/Workerman coroutines or a queue worker
    return [
        'status'     => 'completed',
        'summary'    => 'Sub-task completed',
        'iterations' => 5,
    ];
});
```

- With a `backgroundRunner` injected (coroutine/queue environment) → non-blocking execution, the tool returns `task_id` immediately
- Without a runner → falls back to synchronous execution (full transcript is still recorded)

#### Sub-agent transcript (P0-5)

Every sub-agent run's full message history, iteration count, stop reason, and final result are recorded, separate from the main Agent's transcript:

```php
// Get the full transcript of a run
$transcript = $sam->getTranscript('sub_1_...');
// $transcript = [
//     'task_id'    => 'sub_1_...',
//     'agent'      => 'code-reviewer',
//     'task'       => 'Review Auth.php',
//     'status'     => 'completed',
//     'reason'     => 'end_turn',
//     'summary'    => 'Found 3 issues...',
//     'messages'   => [...],           // full message history
//     'iterations' => 8,
//     'duration_ms'=> 12500.3,
//     'created_at' => 1700000000,
// ];

// Query recent runs
$recent = $sam->recentRuns(10);

// Get a result summary (without full message history, suitable for feeding back to the main Agent)
$result = $sam->getResult('sub_1_...');
```

The main Agent only sees the structured summary returned by the `spawn_agent` tool (with a `transcript_id`). The full transcript is queried separately via `getTranscript()`, so the main Agent's context never grows from sub-agent details.

#### Worktree isolation mode

With `spawn_agent`'s `isolation` parameter set to `worktree`, the sub-agent runs inside a separate git worktree so its changes never touch the main working tree; the diff is captured and the worktree removed when it finishes:

```php
$agent->setWorkdir('/var/www/project');   // must be the root of a git repository
// Model call: spawn_agent(agent="refactorer", task="Refactor Auth.php", isolation="worktree")

$result = $sam->getResult('sub_1_...');
echo $result['diff'];        // full unified diff; files in the main tree are untouched
echo $result['diff_stat'];   // ' src/Auth.php | 42 +++++-----'
```

This suits letting a sub-agent try out code changes: the main Agent — or a human — decides whether to apply the resulting diff. When the working directory is not a git repository the run returns `status = failed` with `reason = no_git_repo`, rather than silently falling back to editing the main tree.

### Budget control

`BudgetManager` tracks token usage and estimated cost, stopping the Agent when the budget is exceeded:

```php
// Shortcut
$agent->setMaxBudget(5.0);                          // $5 budget
$agent->getRuntime()->setMaxTokens(500000);          // or token limit

// Fine-grained
use Ai\Agent\Budget\BudgetManager;

$bm = new BudgetManager([
    'maxBudget'  => 5.0,                              // max budget (USD)
    'maxTokens'  => 500000,                           // max tokens
    'pricing'    => ['prompt' => 5.0, 'completion' => 25.0, 'cached' => 0.5],
    'perMillion' => true,                             // prices per million tokens (official)
]);

$agent->getRuntime()->setBudget($bm);
```

When the budget is exceeded, the Agent stops with `budget_exceeded`.

`BudgetManager` also covers two dimensions that have nothing to do with money but can equally let a task run away:

```php
$bm = new BudgetManager([
    'maxDuration'  => 300,   // wall clock: at most 5 minutes
    'maxToolCalls' => 100,   // cap on tool invocations
]);
```

These two are the **execution budget**, independent of tokens and cost: a task stuck on a slow tool may not have spent a single extra token while holding an HTTP connection open for ten minutes; an Agent that greps in circles may be light on tokens and already past a hundred tool calls. Exceeding them also stops with `budget_exceeded`, and `summary()['reason']` says which limit it was:

```php
$summary = $bm->summary();
// ['exceeded' => true, 'reason' => 'wall-clock limit exceeded (312.4s > 300s)',
//  'tokens' => 48210, 'cost' => 0.83, 'elapsed' => 312.4, 'tool_calls' => 37]
```

The wall clock starts at the top of the loop (`start()` is called by the loop itself), not when `BudgetManager` is constructed — it is often built during wiring, long before anything actually runs. Call `reset()` before reusing one instance for a second task.

**Sub-agents share the parent's ledger**: tokens, cost and tool calls spent by delegated work are recorded against the parent, and the parent's wall-clock limit applies to it too. Same principle as permissions — a sub-agent's allowance is a subset of the parent's. Without inheritance, a parent capped at five minutes cannot stop the work it delegated from running for twenty. The cancellation token is passed down as well: stopping the parent stops the sub-agents it dispatched.

When a run finishes, cost, duration and changed files are filled into `AgentResult` automatically, so you don't have to assemble them from each manager yourself:

```php
$result = $agent->getRuntime()->run($messages);

$result->getCost();          // estimated cost of this run
$result->getDuration();      // milliseconds
$result->getFilesChanged();  // which files were touched
$result->toContract();       // full structured result, ready to return as JSON
```

### Retrying transient model failures

The transport layer retries 408 / 429 / 5xx but not 4xx — generally correct, since a 400 usually means the request itself is malformed and resending changes nothing.

Field testing found an exception: third-party gateways that wrap other vendors' models behind an Anthropic-compatible API return 400 **intermittently**. The same byte-identical request sent four times succeeded three times and failed once with `400 Format Error`. Over a 25-iteration task that probability becomes a certainty — at a 1-in-4 per-call failure rate a full run is almost guaranteed to die partway through, taking all prior work with it.

So the loop adds its own retry around the model call (2 attempts by default, exponential backoff):

```php
$agent->getRuntime()->getLoop()->setModelRetries(3, 500);   // 3 retries, 500ms initial backoff
$agent->getRuntime()->getLoop()->setModelRetries(0);        // off
```

This retries the **same model** (for transient gateway faults), a different job from `setFallbackModels()` — that one switches models only after retries are exhausted (for a model that is genuinely unavailable). Auth failures and missing models (401 / 403 / 404 / 413) give up immediately, since retrying only wastes time. Every retry emits a `model_retry` event.

If the retries are exhausted anyway, the result carries **the last model text** rather than an empty string — a model call failing on iteration N should not erase what iterations 1..N-1 actually did:

```php
if ($result->getStopReason() === 'model_error') {
    echo $result->getText();                         // what it said before dying
    echo $result->getExtra()['error'];               // why it died
    echo implode(',', $result->getFilesChanged());   // which files it already changed
}
```

### Cancellation: making the Agent stoppable

In production a task has to be stoppable mid-flight: the user hit stop, the browser went away, the task ran too long. `CancellationToken` lets the loop bow out at **safe points**:

```php
use Ai\Agent\Loop\CancellationToken;

// 1. Cancel directly, same process
$token = new CancellationToken();
$agent->setCancellation($token);
// …elsewhere (a signal handler, a timer):
$token->cancel('User interrupted');

// 2. Web: stop burning money the moment the browser disconnects
$agent->setCancellation(CancellationToken::whenConnectionAborted());

// 3. Background jobs: another process just touches a file to call it off
$agent->setCancellation(CancellationToken::whenFileExists("/tmp/stop-{$taskId}"));

// 4. Hard timeout
$agent->setCancellation(CancellationToken::afterSeconds(600));

// You can also just stop it — a token is created for you if none is set
$agent->cancel('No need to continue');
```

The checkpoints sit on three **safe boundaries**: before starting a new iteration, after the model replies but before any tool runs, and after a batch of tool results has been written back. Nothing is interrupted outside those points — PHP has no safe thread interruption, and killing a tool halfway through a file write leaves half a file, which is worse than one extra iteration.

**Cancelling is not abandoning.** On a hit the loop saves a checkpoint and finishes with `user_cancel`, so the work can be resumed:

```php
$result = $agent->getRuntime()->run($messages);
if ($result->getStopReason() === 'user_cancel') {
    echo $result->getExtra()['reason'];      // why it was cancelled
    echo $result->getExtra()['resumable'];   // whether a checkpoint exists
}
```

Long-running tools (downloads, whole-repository scans) can keep asking whether to carry on:

```php
$handler = function (array $in, ToolContext $ctx) {
    foreach ($items as $item) {
        if ($ctx->isCancelled()) {
            return 'Cancelled after processing ' . $done . ' items';
        }
        // …
    }
};
```

### Parallel tool execution

When the model returns multiple tool calls in one turn, parallel-safe tools (read_file / grep / glob) can run together while the rest run sequentially. Off by default — enable explicitly:

```php
$agent->setParallelTools(true);     // enable parallel execution
```

The parallel executor runs sequentially by default (semantically correct). In a Swoole / Workerman coroutine environment, inject a parallel runner for true concurrency:

```php
$agent->getRuntime()->setParallelRunner(function (array $tasks) {
    return \Swoole\Coroutine\parallel($tasks);
});
```

### Tool execution timeout

A tool call (including retry waits) that exceeds the specified number of seconds is marked as timed out and no further retries are attempted:

```php
$agent->setToolTimeout(60);                // global timeout of 60 seconds
$agent->getRuntime()->setToolTimeout(30);  // or via the Runtime
```

The timeout check happens after each execution attempt and before each retry wait, so it cannot forcibly interrupt synchronous PHP code mid-execution. However, it prevents unnecessary retries past the deadline. The built-in `BashTool` has its own timeout-kill mechanism and works alongside the global timeout.

A timed-out tool produces `StopReason::timeout`, and the `error` field contains the timeout details.

### Model fallback

When the primary model returns a service-level error, the Agent automatically switches to a fallback model in order, preserving the same context and tool state:

```php
$agent->setFallbackModels(['claude-sonnet', 'claude-haiku']);
```

A `model_fallback` event is emitted whenever a fallback is triggered, including the model name and the original error message. If all fallback models also fail, the Agent stops with `MODEL_ERROR`.

### Verification management (VerificationManager)

Automatically run verification commands (such as `php -l`) after a tool executes, so code changes are validated without relying on the model to "remember to test itself". Verification failures are fed back to the model so it can fix them.

```php
$agent->setVerification([
    'edit_file'  => ['php -l {file}'],
    'write_file' => ['php -l {file}'],
    'test'       => ['vendor/bin/phpunit'],
]);
```

Verification rules are configured per tool name, supporting multiple commands. The `{file}` placeholder is replaced with the tool input's `file_path`. Commands run synchronously via `exec()`, exit code 0 means pass, non-zero output is fed back to the model.

Standalone usage:

```php
use Ai\Agent\Verification\VerificationManager;

$vm = new VerificationManager([
    'edit_file' => ['php -l {file}'],
]);
$results = $vm->verify('edit_file', ['file_path' => 'src/Auth.php']);
// $results[0]->isPassed()  => true / false
// $results[0]->getError()  => error message
```

#### Verifier framework (VerifierInterface)

Command-based `addRule()` fits "run a command, check the exit code". When you need to parse tool output and point at a specific file and line, implement `VerifierInterface`:

```php
use Ai\Agent\Verification\PhpSyntaxVerifier;

$verifier = new PhpSyntaxVerifier();
$verifier->supports('write_file');   // true — it only handles file-writing tools

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

`PhpSyntaxVerifier` runs `php -l` on written `.php` files and parses `Parse error / Fatal error` output into structured errors with line numbers. Non-PHP files, missing files and a disabled verifier all return "passed" so the flow is never blocked.

`VerificationResult` keeps the existing `isPassed()` / `getCommand()` / `getOutput()` / `getError()` and adds `getVerifierName()`, `getErrors()`, `addError()` and `toArray()`, so existing code is unaffected.

#### Built-in verifiers

Besides `PhpSyntaxVerifier` there are three more ready-to-use verifiers, all implementing `VerifierInterface`:

| Verifier | Name | What it does |
|----------|------|--------------|
| `PhpSyntaxVerifier` | `php_syntax` | Runs `php -l` on written `.php` files and parses syntax errors with line numbers |
| `SecurityVerifier` | `security` | Scans for dangerous functions (`eval` / `exec` / `system` …) and hardcoded credentials |
| `UnitTestVerifier` | `unit_test` | Runs the test command after a change and parses failing case names |
| `GitDiffVerifier` | `git_diff` | Measures the size of the change; blocks when it exceeds the limits or touches a protected path |

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
    'maxFiles'     => 10,     // more than 10 files changed at once → blocked
    'maxLines'     => 500,    // more than 500 lines changed at once → blocked
    'protectPaths' => ['composer.json', '.github/'],
]));
```

Or mount them all at once:

```php
$agent->useDefaultVerifiers([
    'test'     => 'composer test',      // only mounts UnitTestVerifier when given
    'workdir'  => '/var/www/project',   // only mounts GitDiffVerifier when given
    'maxFiles' => 10,
]);
```

`SecurityVerifier` scans via `token_get_all()`, so an `eval` inside a comment or string is not a false positive, and a method call such as `$db->exec()` is not treated as the built-in dangerous function. When you really do need one of them, allow it explicitly:

```php
$sec = new SecurityVerifier();
$sec->allow('exec');   // more precise than disabling the whole verifier
```

`GitDiffVerifier` exists to put a guard rail around "let the Agent change code freely" — 40 files and a few thousand lines in one go is usually the model drifting, not the task. When the directory is not a git repository it passes straight through rather than blocking the flow.

### Workspace management (WorkspaceManager)

Tracks the Agent's working directory Git status so the model knows cwd, branch, modified files, and more. The state is refreshed on demand (5-second cache by default) instead of running git commands every turn.

```php
$agent->setWorkspaceDir('/var/www/project');

// Or manually
use Ai\Agent\Workspace\WorkspaceManager;

$wm = new WorkspaceManager('/var/www/project');
$wm->refresh();
echo $wm->getBranch();        // 'main'
echo $wm->getProjectName();   // 'project'
print_r($wm->getModified());  // ['src/Auth.php']
```

The workspace state is formatted as a `<workspace>` block and injected into the system prompt each iteration, so the model always knows the current working directory, branch, and modified files.

**Note**: `setWorkdir()` also creates a `WorkspaceManager` automatically (if one is not explicitly set), so existing `setWorkdir()` calls gain workspace state tracking for free.

### Skills (SkillManager)

A Skill is a set of detailed instructions for a capability or workflow, organised by directory. Only the "name + description" is injected into the system prompt (saving context), and the model loads the full content via the `use_skill` tool when needed.

```php
$agent->loadSkills('/path/to/skills');

// Or manually
use Ai\Agent\Skill\SkillManager;

$sm = new SkillManager();
$sm->register('deploy', [
    'description'  => 'Deploy to production',
    'content'      => "# Deployment\n1. Build...",
    'allowedTools' => ['Bash(git *)'],
]);
$agent->setSkillManager($sm);
```

#### SKILL.md directory format

Skills directory convention: `{dir}/{skill-name}/SKILL.md`, with frontmatter support:

```markdown
---
name: deploy
description: Deploy to production
allowed-tools:
  - Bash(git *)
  - Bash(docker *)
---
# Deployment

1. Build
2. Upload to server
3. Restart service
```

The `use_skill` tool is automatically registered in the Agent's tool registry. When the model calls it, the full skill content is loaded and any `allowed-tools` restrictions are collected (these cannot break through global permissions).

#### On-demand discovery and context matching (Skill 2.0)

Once you have many skills, `loadFromDir()` reads every body into memory. `discover()` registers skills from their frontmatter alone and defers the body until the model actually calls `use_skill`:

```php
$found = $agent->discoverSkills('/path/to/skills');   // ['wordpress', 'deploy', 'nginx']
```

Two more frontmatter fields are recognised:

```markdown
---
name: wordpress
description: WordPress plugin development
files:
  - wp-content
  - "*.wp.php"
knowledge: |
  WordPress is extended through hooks (actions / filters).
  Plugins live in wp-content/plugins/.
---
# WordPress plugin development

(full body, loaded only after the model calls use_skill)
```

- `knowledge` is a few key lines, injected into the system prompt alongside the skill descriptions (a `<skill-knowledge>` block) — not the full body
- `files` are the glob patterns that make a skill relevant, used for context matching

```php
// Find the skills that apply to a file
$sm->forFile('/var/www/wp-content/plugins/foo.php');   // ['wordpress' => SkillDefinition]

// Activate them directly — skipping the step where the model decides whether to use_skill
$agent->activateSkillsForFile('/var/www/wp-content/plugins/foo.php');   // ['wordpress']

// Load the body without activating (no allowed-tools merged)
$sm->loadByName('wordpress');
```

**A skill without `files` never matches** — guessing file paths from a skill's name misfires too easily, so the declaration has to be explicit.

### Project instructions (InstructionManager)

Loads project-level instruction files (CLAUDE.md / AGENTS.md). These are long-term rules the project must follow, distinct from Skills:

- **CLAUDE.md / AGENTS.md** = long-term project rules
- **Skill** = capability / workflow instructions
- **Tool** = actual action execution

```php
$agent->loadInstructions('/var/www/project');

// Or manually
use Ai\Agent\Instruction\InstructionManager;

$im = new InstructionManager();
$im->loadFromTree('/var/www/project');         // loads .claude/ .ai/ and root directory
$im->loadFromDir('/var/www/project/src');      // subdirectory instructions (higher priority)
$agent->setInstructionManager($im);
```

Load priority (later loads take precedence): Global → Project → Subdirectory → Task. Instructions are formatted as `<instructions>` blocks and injected into the system prompt.

```php
// Custom filenames
$im->setFilenames(['CLAUDE.md', 'AGENTS.md', '.ai/AGENTS.md']);
```

### MCP Runtime (McpManager)

MCP (Model Context Protocol) server management. Runs MCP servers as stdio subprocesses and automatically registers their tools into the Agent's tool registry.

```php
$agent->setMcpServers([
    'filesystem' => [
        'command' => 'npx',
        'args'    => ['-y', '@modelcontextprotocol/server-fs', '/tmp'],
    ],
]);

// Or manually
use Ai\Agent\Mcp\McpManager;

$mm = new McpManager();
$mm->addServer('filesystem', 'npx', [
    '-y', '@modelcontextprotocol/server-fs', '/tmp',
]);
$agent->setMcpManager($mm);
```

Each MCP server runs as an independent subprocess communicating via JSON-RPC 2.0 over stdio. Tool names are formatted as `{serverName}__{toolName}` to avoid conflicts between servers.

```php
// Batch configuration
$mm->addServers([
    'fs'   => ['command' => 'npx', 'args' => ['-y', '@modelcontextprotocol/server-fs', '/tmp']],
    'db'   => ['command' => 'npx', 'args' => ['-y', '@modelcontextprotocol/server-sqlite', './data']],
]);

// Shut down all MCP servers
$mm->shutdown();
```

#### Transports: stdio / HTTP / SSE / WebSocket

MCP is a JSON-RPC 2.0 protocol, and the transport underneath is swappable. Local tools run over a stdio subprocess, remote services over HTTP, and long-lived sessions over WebSocket:

| Protocol | Transport | Suits |
|----------|-----------|-------|
| stdio | subprocess stdin/stdout | local tools (filesystem, Git, database clients) |
| HTTP / SSE | HTTP POST, response may be `text/event-stream` | remote services, MCP servers shared across a team |
| WebSocket | long-lived bidirectional connection | server push, or many tool calls in one session |

```php
$mm = new McpManager();

// stdio (default)
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

**Connection management** — no longer all-up-front, all-at-once:

```php
$mm->connect('remote');            // connect one; returns false on failure instead of throwing
$mm->isConnected('remote');        // true
$mm->discoverTools('remote');      // discover tools, connecting first if needed
$mm->disconnect('remote');         // disconnect one
print_r($mm->status());
// ['fs' => ['connected' => false, 'transport' => 'stdio'],
//  'remote' => ['connected' => true, 'transport' => 'http']]
echo $mm->getLastError();          // why the last connection attempt failed
```

A failed connection returns `false` rather than throwing — one unavailable MCP server should not stop the whole Agent; read `getLastError()` for the reason.

The HTTP transport remembers the server's `Mcp-Session-Id` and sends it on subsequent requests; notifications interleaved in an SSE response are skipped and only the message whose id matches is returned. `wss://` requires the openssl extension; CDP and plain local `ws://` do not.

### Scoped memory (MemoryManager)

Scope-based long-term memory management supporting five scopes: `user` / `project` / `session` / `task` / `agent`, each with its own memory file. When injected into the system prompt, memories are merged in scope order so the model perceives different levels of memory context.

```php
$agent->setMemoryDir('/tmp/agent_memory');

// Or manually
use Ai\Agent\Memory\MemoryManager;

$mm = new MemoryManager('/tmp/agent_memory');
$mm->remember('user', 'The user prefers PHP');
$mm->remember('project', 'The project uses CodeIgniter 3');
$mm->remember('session', 'Currently fixing the login issue');
$mm->remember('task', 'Working on Auth.php');
$mm->remember('agent', 'Last attempt with plan A failed');
$agent->setMemoryManager($mm);
```

Memory files are persisted at `{baseDir}/{scope}.md`, supporting append, overwrite, clear, and read:

```php
$mm->read('user');          // read
$mm->write('user', 'single entry');  // overwrite
$mm->forget('user');        // clear
$mm->clearAll();            // clear all scopes
$mm->forPrompt();           // generate <memory> block for system prompt
```

#### Memory retrieval (MemoryRetriever)

`forPrompt()` injects every scope's memory wholesale. Once memories pile up that becomes a burden: out of a few thousand characters of history maybe two lines relate to the current task, and the rest just crowds the context. The retriever splits memory into per-line entries and injects only the few most relevant to the task at hand:

```php
$retriever = $mm->retriever();

$hits = $retriever->retrieve('login endpoint returns 401');
// [['scope' => 'project', 'line' => 3, 'text' => 'Login uses JWT, key in config/jwt.php', 'score' => 62.5], ...]

echo $retriever->forPrompt('login endpoint returns 401');
// <memory-relevant query="login endpoint returns 401">
// [project] Login uses JWT, key in config/jwt.php
// </memory-relevant>

$mm->retrieve('login 401');              // equivalent shortcut
$mm->forPromptRelevant('login 401');     // equivalent shortcut
$retriever->search('JWT', 'project');    // plain keyword search, no scoring
```

An Agent with `setGoal()` set uses retrieval-based injection automatically; without a goal it falls back to injecting all memory, exactly as before.

Relevance is computed locally, with no model call: English is split into words, CJK into bigrams, and the more tokens match — and the larger the share of the query they cover — the higher the score. This scoring is **literal, not semantic** — asking about "authentication" will not match a memory that only says "login". Swap in your own scorer for semantic retrieval:

```php
$retriever->setScorer(function ($query, $text) use ($ai) {
    return cosineSimilarity($ai->embed($query), $ai->embed($text)) * 100;
});
$retriever->setTopK(3)->setMinScore(20.0);
```

**Compression and expiry** — memory grows without bound on long-running tasks, and pruning it on a schedule is more predictable than letting it be truncated at `maxInject`:

```php
$retriever->compress('session', 20);   // keep only the 20 most recent entries; returns how many were dropped
$retriever->expire('agent', 30);       // drop entries older than 30 days
```

`expire()` only touches entries with a date prefix (`[2026-09-02] ...`) and keeps everything else — if the write time is unknown, it is not for the library to decide the entry has expired.

### Checkpoint (CheckpointManager)

Automatically saves a checkpoint at the end of each iteration. If the Runtime crashes, it can resume from the latest checkpoint. Each checkpoint is persisted as a JSON file, grouped by task ID.

```php
$agent->setCheckpointDir('/tmp/checkpoints');

// Or manually
use Ai\Agent\Checkpoint\CheckpointManager;

$cm = new CheckpointManager('/tmp/checkpoints');
$cm->save('task_1', 5, $messages, ['extra' => 'data']);
$latest = $cm->loadLatest('task_1');
echo $latest->getIteration();  // 5
```

Keeps the most recent 5 checkpoints by default; older ones are cleaned up automatically. Also supports expiry-based cleanup:

```php
// Custom retention
$cm = new CheckpointManager('/tmp/checkpoints', [
    'maxCheckpoints' => 10,
]);

// Clean checkpoints older than 7 days
$cm->cleanExpired('task_1', 7);
```

### Crash recovery

Recover from a crash by loading the latest checkpoint, restoring the message context, and continuing execution.

```php
$agent->setCheckpointDir('/tmp/checkpoints');

$messages = $agent->recoverFromCrash('task_1');
if ($messages !== null) {
    // Recovery succeeded, continue
    $result = $agent->run($messages);
} else {
    // No checkpoint available, start fresh
    $agent->run([['role' => 'user', 'content' => 'Please check the project']]);
}
```

`recoverFromCrash()` internally calls `AgentRuntime::recover()`, which loads the latest checkpoint, restores the iteration count, and sets the task ID. A crash checkpoint is also saved automatically when an exception occurs.

#### Long-running recovery: more than message history

Recovering a task that ran for hours — or days — takes more than the message history: the Agent needs to know which step the plan reached and what the goal was, or the model is left re-deriving both from the transcript. So checkpoints carry the runtime state alongside:

```php
$agent->setCheckpointDir('/var/data/checkpoints');
$agent->setPlanDir('/var/data/plans');
$plan = $agent->plan('Migrate the database', ['Back up', 'Alter tables', 'Verify']);

$agent->run([['role' => 'user', 'content' => 'Start the migration']]);
// …and it crashes partway through

// Recover in a new process
$runtime = $agent->getRuntime();
$messages = $runtime->recover('task_1');
echo $runtime->getGoal();                        // 'Migrate the database' — restored
echo $runtime->getPlan()->progress();            // plan and per-step statuses restored as they were
print_r($runtime->getLastCheckpoint()->getExtra()['workspace']);
// ['dir' => '/var/www/project', 'branch' => 'main', 'modified' => ['db/schema.sql']]
```

What a checkpoint holds: message history, iteration count, the task goal, a plan snapshot (including each step's status), the workspace state at crash time (directory / branch / modified files), and the memory directory.

**The workspace is deliberately not restored for you**: a checkpoint records what the workspace looked like at the moment of the crash, and the files on disk may well have moved on since. Rewinding them to an old snapshot is a destructive act. The snapshot is handed to you as information to compare against; whether to act on it is your call.

### Task queue (AgentQueue)

Enqueues tasks (Task) with runtimes (AgentRuntime) in FIFO order for sequential processing. Useful for background execution in PHP-FPM environments.

```php
use Ai\Agent\Queue\AgentQueue;

$queue = new AgentQueue();
$task = $queue->dispatch('Check and fix the project', $runtime, $messages, 'sess_1');

// Process pending tasks one by one
while ($queue->hasPending()) {
    $result = $queue->processNext();
    // $result->getText() gets the final reply
}

// Or process a specific task
$result = $queue->process('task_xxx');

// Task control
$queue->cancel('task_xxx');    // cancel
$queue->resume('task_xxx');    // resume a paused task

echo $task->getStatus();  // queued / running / completed / failed / cancelled
```

`AgentQueue` uses `TaskManager` internally for task lifecycle management, creating one automatically or reusing an externally provided one.

### Execution plan (PlanManager)

For complex tasks the Agent breaks the goal into ordered steps before acting, instead of thinking one step at a time. A plan carries status, dependencies and revision history, and can be persisted to disk so execution continues after a crash.

```php
use Ai\Agent\Planning\PlanManager;

$pm = new PlanManager('/var/data/plans');   // pass an empty string to keep plans in memory only

$plan = $pm->createPlan('Add unit tests for the Auth module', [
    'steps' => [
        'Read src/Auth.php and map out the branches',
        'Write tests/AuthTest.php',
        'Run phpunit and fix failing cases',
    ],
    'risks' => ['Depends on an external Redis, tests need a mock'],
]);

$pm->start($plan->getId());
$step = $pm->getCurrentStep($plan->getId());   // PlanStep: step 1
$pm->completeStep($plan->getId(), $step->getId(), 'Mapped out 4 branches');
$pm->advance($plan->getId());                  // move to the next step

echo $plan->progress();    // 33 (percent)
echo $plan->toSummary();   // compact summary for injecting into the system prompt
```

Step status goes `pending → running → completed / failed / skipped`; plan status goes `pending → running → completed / failed`.

**Dependency-graph execution** — steps can declare dependencies, and `PlanExecutor` only runs steps whose dependencies are satisfied:

```php
use Ai\Agent\Planning\PlanExecutor;

$executor = new PlanExecutor($pm, ['mode' => PlanExecutor::MODE_DEPENDENCY]);
$result = $executor->executeAll($plan->getId(), function ($step, $plan) {
    // Let the Agent actually run this step; return a string result or throw to mark it failed
    return $agent->ask($step->getAction())->getText();
});
// $result = ['completed' => 2, 'failed' => 1, 'skipped' => 0, 'status' => 'failed']
```

**Plan review** — when execution drifts from the plan, `PlanReview` reports issues and suggestions, and can amend the plan directly:

```php
use Ai\Agent\Planning\PlanReview;

$review = new PlanReview($pm);
$r = $review->review($plan->getId());
// $r = ['status' => 'affected', 'progress' => 33, 'issues' => [...], 'suggestions' => [...], ...]

// Append / insert / remove steps after the review
$review->reviewAndAdjust($plan->getId(), [
    'append' => ['Update the testing notes in README'],
], 'Docs were out of sync');

$review->detectDependencyCycle($plan);   // returns the step IDs on the cycle, empty array if acyclic
```

#### Wiring it into the Agent runtime

Once a `PlanManager` is attached to the Agent, the plan summary is injected into the system prompt on every iteration (as a `<plan>` block), so the model knows which step it is on:

```php
$agent->setPlanDir('/var/data/plans');            // optional; in-memory only without it
$plan = $agent->plan('Refactor the payment module', [
    'Understand the existing Pay.php',
    'Extract a PaymentGateway interface',
    'Run the tests and confirm behaviour is unchanged',
]);

$agent->run([['role' => 'user', 'content' => 'Start the refactor']]);
echo $agent->getPlan()->progress();   // how far along it is
```

`plan()` also writes the goal via `setGoal()` — both reflection and memory retrieval use that goal.

A plan can be persisted alongside the task state, so execution continues after a crash:

```php
$state = new \Ai\Agent\Task\TaskState(['goal' => 'Refactor the payment module']);
$state->setPlan($plan);
file_put_contents('/var/data/task.json', $state->toJson());

// Restore
$restored = \Ai\Agent\Task\TaskState::fromJson(file_get_contents('/var/data/task.json'));
$plan = $restored->restorePlan();   // a Plan object with step states intact
```

### Self-reflection (ReflectionManager)

A tool finishing is not the same as the goal being met. `ReflectionManager` decides whether the goal is actually complete after each round of tool results, and suggests a next action when it is not — forming an "execute → check → not done → continue" loop.

```php
use Ai\Agent\Reflection\ReflectionManager;

$rm = new ReflectionManager(['maxRounds' => 5]);
$result = $rm->reflect($messages, 'Make the whole phpunit suite pass');

if ($rm->shouldContinue($result)) {
    echo $result->getReason();       // 'The last tool run reported an error: Fatal error ...'
    echo $result->getNextAction();   // 'Analyse the error above, fix it and re-run the tests'
    echo $result->toPrompt();        // text ready to inject into the next round's prompt
}
```

The default rules are checked in order:

1. The last assistant message carries a completion marker ("任务完成", "已解决", "all done", …) → done
2. The **most recent batch** of tool results still contains an error → keep going; if the identical error hits two batches in a row it stops instead, because retrying is making no progress
3. No tool has been called at all and this is still the first round → keep going (the model is still in conversation mode)
4. Otherwise → done

Rule 2 looks only at the last batch: an earlier error may already have been fixed in a later round, and pushing the model with a stale error just spins until the round limit. An error is identified by the `is_error` flag on the tool result (the library always sets it); the keyword scan is a fallback only for hand-built messages that lack the flag — running it over library-produced results causes false positives, where reading a file whose body contains `error` counts as a failure.

Rule 4 is the "the model stopped calling tools" signal itself, which is more reliable than any keyword. **Do not force extra rounds by count** — the text fed back carries no information about the task, so the model just asks what it is about, and that puzzled reply becomes the final answer the caller receives, burying the real conclusion.

Inject a custom strategy for model-driven reflection:

```php
$rm->setStrategy(function (array $messages, $goal) use ($ai) {
    $verdict = $ai->ask("Goal: {$goal}\nIs the work above complete? Answer DONE or state what is missing");
    return strpos($verdict, 'DONE') !== false
        ? \Ai\Agent\Reflection\ReflectionResult::completed('Model judged it complete')
        : \Ai\Agent\Reflection\ReflectionResult::continuing($verdict, 'Continue per the notes above');
});
```

`maxRounds` is the backstop: once the **number of reflections** hits the limit the loop is forced to stop, so the model cannot spin forever on "almost there". It counts reflection rounds (`reflection_round`), not the agent loop's iteration number (`iteration`) — the two mean different things, and conflating them turns into "after iteration N every reflection passes", even while a tool is still failing.

The limit is applied **after** the verdict: a custom strategy is always called, and the cap only steps in when the strategy says "keep going" but the rounds have run out. (Applying it first means an injected strategy never runs once the count is exceeded — and you end up suspecting your own strategy.)

#### Wiring it into the Agent loop

Once attached to the Agent, reflection happens at the moment the model stops calling tools and is about to wrap up: if the goal is not met, the next-step suggestion is appended as a user message to keep the model working rather than ending there.

```php
$agent->enableReflection(['maxRounds' => 5]);
$agent->setGoal('Make the whole composer test suite pass');

$agent->onEvent(function ($e) {
    if ($e['type'] === 'reflection') {
        echo $e['success'] ? "Reflection: goal met\n" : "Reflection: {$e['reason']}\n";
    }
});

$agent->run([['role' => 'user', 'content' => 'Fix the failing tests']]);
```

Without calling `enableReflection()` the loop behaves exactly as before — no extra rounds appear out of nowhere. When `setGoal()` is not set, the goal falls back to the first user message.

### Developer API: three things and you are working

You do not need to understand LoopController / ContextManager / PermissionManager / SubAgentManager — supply an AI, a workdir and a prompt:

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

Chained configuration:

```php
$agent = Agent::create($ai)
    ->workdir(__DIR__)
    ->codeAgent(['test' => 'composer test'])
    ->tools(['my_tool' => $myTool])              // append tools (does not replace existing ones)
    ->skills('/path/to/skills')                  // load skills
    ->agents(['dba' => [                         // register a custom sub-agent
        'description' => '数据库结构与索引优化',
        'tools'       => ['read_file', 'bash'],  // tool names suffice; they come from the parent set
    ]]);

$result = $agent->task('修复登录 Bug');          // the orchestration layer picks the strategy
$handle = $agent->dispatch('扫描整个项目');      // run in the background, returns a task_id immediately
$agent->resume($handle['task_id']);              // read the background handle, or restore from a checkpoint
```

`AgentResult`'s structured contract: `getStatus()` / `getSummary()` / `getFilesChanged()` / `getTests()` / `getVerification()` / `getArtifacts()` / `getSubtasks()` / `getCost()` / `getDuration()`, with `toContract()` returning everything at once, ready to serialise to JSON. The fields are filled in by the caller or by `WorkspaceSnapshot` — `AgentResult` does not scan the workspace itself; that is Workspace's job.

### Tool groups and on-demand discovery

With many tools registered, sending them all every round is wasteful: dozens of tool definitions cost tokens and make the model's choice harder.

```php
$agent->toolGroups()->disable(\Ai\Agent\Tool\ToolGroup::DEPLOYMENT);   // no deployments on this task
$agent->toolGroups()->only([\Ai\Agent\Tool\ToolGroup::FILESYSTEM]);    // file operations only
```

Built-in groups: filesystem / git / database / network / browser / cloud / testing / deployment. **Tools that were never grouped stay available** — grouping is for narrowing, and forgetting to classify a tool should not make it vanish. A tool in several groups is available if any of them is enabled.

Discovery works the other way round: start with a handful of common tools plus `search_tools`, and let the model find the rest when it needs them:

```php
$agent->useToolDiscovery(['read_file', 'grep', 'glob']);

// Model call: search_tools(query: "database")
//   → finds sql_query / db_schema, enables them, and they become callable
```

Search is local keyword matching over tool names and descriptions, with no model call — spending a model call to save tokens is a poor trade.

What gets narrowed is **what the model sees**, not what can be executed: the tool registry always holds the full catalogue (otherwise a tool found and enabled could not actually run), and the definitions sent to the model each round are filtered by the active set. So a tool the model enables via `search_tools` this round **becomes visible and callable on the next one**.

### Layered permission policy

Permissions come from four layers, combined by **intersection** rather than union, with **DENY winning**:

```text
Effective = Global AND Agent AND Skill AND Task
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
$policy->check('Bash', 'curl x.com');    // 'ask' — nobody explicitly allowed it
$policy->explain('Bash', 'rm -rf /');    // ['decision' => 'deny', 'layer' => 'global', 'rule' => 'Bash(rm -rf *)']
```

The order cannot be reversed: if a lower layer could widen an upper one, a `Bash(rm -rf *)` allowed by a Skill would bypass the global ban. Call `clearLayer('task')` when a task ends — layers must not contaminate each other.

### Event replay (EventLog)

A reconnecting client wants "everything after the last event I received". **Replay events; never re-run the Agent** — a re-run produces fresh side effects (editing files again, running commands again) when all the client needed was the messages it missed.

```php
$log = $agent->eventLog('/var/data/events');   // hooks itself into the event callback

// The client reconnects with a Last-Event-ID
$missed = $log->sinceId($lastEventId);
echo EventLog::toSse($missed);                 // emit SSE frames directly

$log->since(42);                // replay by sequence
$log->ofTask('task_1');         // every event for one task
$log->ofType('tool_call');      // filter by type
$log->lastSequence();           // the current head
```

When the event ID is not found, everything is returned — better to resend than to drop, since the client can deduplicate but cannot recover a lost message. Without a directory the log lives in memory only; **reconnection scenarios must configure one**, because the process may well have been replaced by then.

### Scheduling and concurrency limits

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

$next = $scheduler->next();          // 'task_1' — higher priority first
$scheduler->start($next);
$scheduler->finish($next, false);    // failure → requeued automatically while retries remain
```

Priorities: critical / high / normal / low, with submission order breaking ties. **One PHP request should not spawn dozens of agents** — each sub-agent is a separate model call and context, so runaway concurrency burns money and memory alike; the defaults are deliberately conservative.

Attach a `TaskGraph` and only dependency-satisfied tasks get scheduled. Automatic retry exists because transient failures (network blips, model timeouts) usually clear on the second attempt, and making a human resubmit them serves no purpose.

### Model routing and artifacts

Sending the strongest model to grep code is waste; sending the cheapest one to rework an architecture is false economy:

```php
$router = $agent->modelRouter([
    'cheap'    => 'claude-haiku-4-5-20251001',
    'standard' => 'claude-sonnet-5',
    'premium'  => 'claude-opus-5',
]);

$router->route(['agent' => 'explorer']);                     // cheap
$router->route(['agent' => 'coder']);                        // premium
$router->route(['task' => '重构整个认证系统']);               // premium (high complexity)
$router->route(['agent' => 'coder', 'budget_left' => 0.05]);  // cheap (budget nearly gone; finishing beats finesse)
```

**No configured model names, no routing**: it returns an empty string and the caller keeps the current model. Inventing a plausible-looking model name only buys a runtime "model not found".

Once created, the router is wired into the sub-agent manager automatically: it picks the model for **roles whose definition omits `model`**, while a definition that names a `model` wins — an explicit setting should not be overridden by a heuristic. When the chosen model lives on another platform, its credentials are matched by model name from the table registered with `platforms()`:

```php
$agent->platforms([
    'deepseek' => ['api_key' => $deepseekKey],
    'moonshot' => ['api_key' => $moonshotKey],
    'openai'   => ['api_key' => $openaiKey],
])->modelRouter([
    'cheap'    => 'deepseek-chat',      // grunt work such as explorer
    'standard' => 'moonshot-v1-32k',
    'premium'  => 'gpt-4o',             // coder / reviewer
]);
```

Values only the caller knows — `budget_left`, `priority` — go in through `setRouteContext()` (an array for fixed values, a closure to read them fresh before each routing decision):

```php
$agent->getRuntime()->getSubAgentManager()->setRouteContext(function ($def, $task) use ($budget) {
    return ['budget_left' => $budget->remainingRatio()];
});
```

Artifacts — test reports, patches, logs, analyses — should not go into the context verbatim; drop a 5000-line report in and there is no room left for the conversation:

```php
$artifacts = $agent->artifacts('/var/data/artifacts');

$ref = $artifacts->put('task_123', 'test-report.json', $reportJson);
// 'artifact://task_123/test-report.json'

$artifacts->preview($ref, 500);      // show the model only the head
$artifacts->get($ref);               // fetch the full text when details are needed
$artifacts->listFor('task_123');     // what this task produced
$artifacts->summarize('task_1', 'out.txt', $output, 5);
// '已保存到 artifact://task_1/out.txt（12.4 KB）：\nFAILURES!\n…'
```

Artifact names are guarded against path traversal, so `../../../etc/passwd` cannot get through.

### Model-driven: let the model plan and delegate on its own

The orchestration layer picks a strategy with rules **before the model ever sees the task** — the word "refactor" in the description triggers planning, a chance match against a sub-agent's description sends the work there. Rules are fast and reproducible, but they cannot read the task: the exploration that genuinely deserves delegation goes unrecognised, while a simple edit that happens to hit a keyword gets shipped off.

`modelDrivenTools()` turns both of these into **tools**, so the model decides inside the loop:

```php
$agent = Agent::create($ai)
    ->workdir(__DIR__)
    ->tools($tools)
    ->setPlanManager(new PlanManager())
    ->setSubAgentManager($sam)
    ->modelDrivenTools();      // ← installs update_plan and delegate

$agent->run([['role' => 'user', 'content' => 'Add OAuth login to the project and get the tests passing']]);
```

`codeAgent()` installs both automatically (it already wires up a PlanManager and the six sub-agents); pass `['noModelDrivenTools' => true]` to turn that off.

| Tool | What the model uses it for |
|---|---|
| `update_plan` | Write down the intended approach, mark each step `completed` as it lands, and rewrite the whole table when the original plan turns out to be wrong |
| `delegate` | Hand exploration that needs many files read to a sub-agent working in an **isolated context**, and take back only the summary |

**A plan is state, not a script.** The point is that the model can change its mind once it has real results — after reading three files it discovers the project already ships an `AuthProvider` base class, so it rewrites the plan instead of marching through a table fixed before any work began:

```php
$agent->onEvent(function ($e) {
    if ($e['type'] === 'plan_updated') {
        echo "Plan now at version {$e['version']}, {$e['steps']} steps, {$e['progress']}% done\n";
    }
});
```

Every version prior to a rewrite is snapshotted (`PlanManager::versions()`) — "what was the original plan, and why did it become this" is the key thread when tracing an Agent that went off course, and overwriting in place destroys it. To do the same full-table rewrite from your own code, use `PlanManager::rewrite($planId, $steps, $reason)`.

A few boundaries:

- Neither tool enters the sub-agents' tool set. A sub-agent that declares no `tools` inherits the parent set wholesale; handed `delegate`, it could spawn sub-agents of its own, nesting without a floor.
- Nothing is registered without its dependency: no `PlanManager` means no `update_plan`, no sub-agents means no `delegate`. Registering a tool that is guaranteed to fail only wastes a model round.
- The delegation cap (8 by default) is a **per-run** budget, reset at the start of `run()` and `task()`.
- Code that calls neither `modelDrivenTools()` nor `codeAgent()` sees exactly the same tool set and behaviour as before.

### Default system prompt

`codeAgent()` installs a default system prompt (`CodeAgentPrompt`) that tells the model **when** to use these tools — a tool description explains what a tool does, but not when reaching for it is the right move.

Calling `setSystem()` yourself takes precedence:

```php
$agent = Agent::create($ai)->workdir($dir)->codeAgent();          // default prompt
$agent = Agent::create($ai)->setSystem('...')->codeAgent();       // your own
echo \Ai\Agent\CodeAgentPrompt::build(['test' => 'composer test']);  // read the default
```

It covers four things: understand the current state before changing it; always modify **existing** files with `edit_file` (`write_file` is for new files only); write down steps with `update_plan` once a task has three or more of them; and actually verify after changing code.

The prompt was tuned empirically. The same task (add three features to a multi-file project and extend the tests) against a real gateway:

| | No prompt | With prompt | Plus the "batch your edits" line |
|---|---|---|---|
| Iterations | 6 | 13 | 9 |
| Cost | $0.032 | $0.067 | $0.053 |
| `update_plan` calls | 0 | 3 | 2 |
| How existing files were edited | `write_file` full rewrite ×3 | `edit_file` ×6 | `edit_file` ×5 (issued together) |
| Test result | ALL PASS | ALL PASS | ALL PASS |

All three runs produced correct results; what differs is **process safety and cost**. The no-prompt run rewrote three existing files wholesale with `write_file` — the files were small and the rewrites happened to be right, but on a file of several hundred lines a wholesale rewrite is exactly how an agent silently deletes code. The default prompt trades roughly 60% more cost for removing that risk; adding the "issue edits you have already decided on together" line brings the premium down to about 40%.

If that is too expensive, supply a shorter prompt of your own, or `setSystem('')` — but that returns the model to guessing.

### Orchestration layer (AgentOrchestrator)

`AgentRuntime` answers "how do I run one iteration"; the orchestration layer answers **"how should this work be done"** — call tools directly, break it into a plan first, delegate to a sub-agent, fan out in parallel, or push it to the background.

```php
$agent = (new Agent($ai))->setWorkdir('/var/www/project')->codeAgent();

$result = $agent->task('分析项目中的认证、支付、SEO');
// Recognised as three parallel tracks → explorers investigate separately → summary aggregated

echo $agent->orchestrator()->lastDecision()->toSummary();
// 策略：parallel —— 识别到 3 个互不相关的子任务
```

`codeAgent()` wires everything up in one call: built-in tools, the six specialist sub-agents, default verifiers, planning, reflection and project instructions. After that, one `task()` gets to work.

The seven strategies:

| Strategy | Chosen when | Typical task |
|---|---|---|
| `direct` | scope is clear | "read the README" |
| `plan` | broad scope, refactor/migrate verbs, or numbered steps | "refactor the whole auth system" |
| `delegate` | matches a sub-agent's job | "review Auth.php for security issues" → reviewer |
| `parallel` | several independent subtasks detected | "analyse auth, payments and SEO" |
| `background` | the description asks for it | "scan the whole project in the background" |
| `ask_user` | empty or undecidable description | — |
| `verify` | only confirming an existing change | "run the tests and confirm" |

Strategy selection is **keyword-based** by default, and keywords cannot read a task: "and while you're there, refactor the wording" gets classified as a major refactor. `useModelStrategy()` hands this step to the model too:

```php
$agent->useModelStrategy();                            // decide with the current model
$agent->useModelStrategy(['model' => 'gpt-4o-mini']);  // decide with a cheap small model
```

It is one small, cheap model call: a single JSON object, no tools, outside the Agent loop, cached per task description. When the model is unsure, returns something that is not JSON, or the call fails, it **falls back to the rule-based version** — a failed decision should not be a failed task. If the model names a sub-agent that does not exist, the decision degrades to `direct` rather than dispatching into the void.

**Every decision goes into the event stream** (a `strategy_decision` event carrying `reason`). Once the Agent picks its own strategy, you must be able to answer "why did it do that" — otherwise a bad run leaves you guessing:

```php
$agent->onEvent(function ($e) {
    if ($e['type'] === 'strategy_decision') {
        echo "{$e['strategy']}：{$e['reason']}\n";
    }
});
```

Deciding and executing are separate, so you can inspect before committing:

```php
$decision = $agent->orchestrator()->decide('重构认证系统');
if ($decision->is(\Ai\Agent\Orchestrator\ExecutionStrategy::PLAN)) {
    $result = $agent->orchestrator()->execute('重构认证系统', $decision);
}
```

The default selector is **rule-based** and makes no extra model call — spending a model call to decide whether to spend model calls is a poor trade, and rule-based decisions are reproducible and debuggable. When unsure it falls back to `direct`: the cost of being conservative is a few extra tool rounds, while the cost of a wrong delegation is a sub-agent running fifteen turns on the wrong context.

Swap in a model-driven selector when you want one:

```php
$agent->orchestrator()->selector()->setResolver(function ($task, $context) use ($ai) {
    // return a StrategyDecision; return null to fall back to the rules
});

// or simply turn off a class of automatic behaviour
$agent->orchestrator()->selector()->setAutoDelegate(false)->setAutoPlan(false);
```

**Without a sub-agent manager and plan manager every strategy falls back to `direct`**, behaving exactly like calling `run()` — the orchestration layer only adds, it never changes existing behaviour.

### Built-in sub-agents (BuiltinAgents)

Six ready-to-use specialist roles. Their value lies less in the prompts than in the **narrowed tool sets**: explorer has no file-writing tool, so it cannot change anything while "investigating the code". That is far more reliable than a prompt asking it not to.

| Role | Purpose | Tools |
|---|---|---|
| `explorer` | search, read, investigate, dependency analysis | read_file / grep / glob |
| `planner` | produce execution plans, break work down | read_file / grep / glob |
| `coder` | modify code | read_file / write_file / edit_file / grep / glob / bash |
| `tester` | run tests, analyse failures | read_file / grep / glob / bash |
| `reviewer` | code review, security review | read_file / grep / glob / bash |
| `debugger` | error analysis, root-cause location | read_file / grep / glob / bash |

```php
use Ai\Agent\SubAgent\BuiltinAgents;

BuiltinAgents::registerAll($sam);                      // register all six
BuiltinAgents::register($sam, ['explorer', 'tester']); // register two
BuiltinAgents::isReadOnly('explorer');                 // true
```

### Full sub-agent configuration

```php
$sam->register('reviewer', [
    'description'     => 'Code review and security review',   // also drives automatic delegation
    'prompt'          => 'You are the reviewer…',
    'tools'           => [...],
    'disallowedTools' => ['write_file', 'edit_file'],
    'model'           => 'claude-sonnet-5',      // a model just for this role
    'api_key'         => $anthropicKey,          // credentials just for this role (see "Cross-platform orchestration")
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

**A sub-agent's capabilities are always a subset of its parent's.** This is enforced, not advised:

```php
$sam->setParentTools($parentTools);   // parent only has read_file / grep / glob

$sam->register('greedy', ['tools' => ['read_file' => …, 'write_file' => …, 'bash' => …]]);
$sam->resolveTools($sam->get('greedy'));   // only read_file survives — what the parent lacks cannot appear
```

Three narrowing steps, each of which can only remove tools: start from the sub-agent's declared `tools` (or the parent's full set if undeclared) → intersect with the parent's tools → subtract `disallowedTools`. `permissionMode` works the same way and can only tighten: a `bypass` parent may host a `manual` child, never the reverse.

`model` and the connection details are carried by a **dedicated AI instance** (cloned from the parent's), so the parent's model and credentials are never touched. See the next section.

`background` and `isolation` declared on the definition are a **floor**: a tool call may switch them on, never off. A sub-agent configured to require worktree isolation must not end up editing the parent tree just because the model omitted the argument.

### Cross-platform orchestration: each role on its own platform

DeepSeek writes the code, Kimi reviews it, GPT plans the work — three platforms, three keys, three endpoints, all inside one Agent:

```php
$agent = Agent::create($ai)->agents([
    'planner'  => ['description' => 'Task planning', 'model' => 'gpt-4o',          'api_key' => $openaiKey],
    'coder'    => ['description' => 'Writes code',   'model' => 'deepseek-chat',   'api_key' => $deepseekKey],
    'reviewer' => ['description' => 'Code review',   'model' => 'moonshot-v1-32k', 'api_key' => $moonshotKey],
]);
```

With many roles there is no need to repeat the key on each one — register it once per platform with `platforms()`, and the library matches it by inferring the platform from the model name:

```php
$agent->platforms([
    'deepseek' => ['api_key' => $deepseekKey],
    'moonshot' => ['api_key' => $moonshotKey],
    'openai'   => ['api_key' => $openaiKey],
])->agents([
    'coder'    => ['description' => 'Writes code',   'model' => 'deepseek-chat'],
    'reviewer' => ['description' => 'Code review',   'model' => 'moonshot-v1-32k'],
    'planner'  => ['description' => 'Task planning', 'model' => 'gpt-4o'],
]);
```

Table keys may be a platform name (the same values `AI::platformOfModel()` returns) or a **specific model name** — an exact model-name match wins, which covers the case of one platform's models going through different gateways. If you already have a configured `AI` instance, pass `'ai' => $kimiAi` directly; it takes precedence over everything else.

The connection keys you may write: `api_key`, `base_url`, `endpoint`, `endpoint_models`, `protocol`, `platform`, `headers`, `organization`, `project_id`, `extra_body` — or all of them at once under `connection`.

Three rules, all there to prevent silently hitting the wrong platform:

1. **Write any connection key and the parent's connection details stop being inherited entirely.** Half-inheritance is the dangerous case: keep the parent's `base_url` and Kimi's model name gets posted to DeepSeek's endpoint, which answers "model not found" — nobody thinks to look at the connection details.
2. **Write no connection key and the behaviour is unchanged from before**: the parent's connection is used as-is and only the model name differs. Gateway setups such as OpenRouter — one key for every model — are unaffected.
3. **Generation parameters such as `temperature` and `max_tokens` are still inherited** from the parent; they have nothing to do with which platform is being called.

A sub-agent receives its own AI instance (cloned from the parent's, so an injected transport and the streaming callback come along). Therefore the parent's model and key are exactly as they were once the run finishes, and roles running in parallel cannot step on each other. Credentials never reach the transcript — `SubAgentDefinition::toArray()` lists only the connection **key names**, never their values.

A complete runnable example lives in `examples_multi_platform.php`.

### Task dependency graph (TaskGraph)

A parent link (`parentTaskId`) expresses containment; a dependency expresses **order** — two sibling tasks can share a parent while one still has to wait for the other. Keeping them separate is what makes "B and C in parallel, D after C" expressible at all.

```php
use Ai\Agent\Task\TaskGraph;

$graph = new TaskGraph();
$graph->addTask('a')->addTask('b')->addTask('c')->addTask('d');
$graph->dependsOn('b', 'a');
$graph->dependsOn('c', 'a');
$graph->dependsOn('d', 'c');

$graph->ready();              // ['a']
$graph->markCompleted('a');
$graph->ready();              // ['b', 'c'] — can run in parallel
$graph->markCompleted('c');
$graph->ready();              // ['b', 'd']

$graph->layers();             // layered by dependency depth; one layer can run together
$graph->dependentsOf('c');    // ['d'] — who is affected by changing c
```

**Failure propagates downstream as `blocked`**: leaving a task that can never run queued up only wastes a scheduling pass.

```php
$graph->markFailed('x');
$graph->getStatus('y');       // 'blocked' (y hard-depends on x)
$graph->blocked();            // ['y', 'z'] — blocking is transitive
```

A soft dependency only constrains order and still runs if its upstream failed:

```php
$graph->dependsOn('q', 'p', \Ai\Agent\Task\TaskDependency::TYPE_SOFT);
```

**A dependency that would create a cycle is rejected outright** (`dependsOn()` returns `false`) — a cycle means nothing can ever run, and failing while building the graph is far easier to diagnose than deadlocking at runtime. Self-dependency is rejected the same way.

Syncing with `TaskManager`:

```php
$graph->syncFrom($taskManager);   // syncs tasks and statuses; graph structure untouched
```

### Background and parallel execution

PHP has no built-in event loop, and how much "background execution" is actually achievable varies wildly between deployments. The library keeps all three tiers in one place and **states honestly which one it took** — silently degrading to synchronous execution while letting the caller believe it was async is more dangerous than saying it cannot be done.

```php
$handle = $agent->dispatch('扫描整个项目的安全问题');
// ['task_id' => 'task_1_ab12cd34', 'status' => 'running', 'mode' => 'fork', 'background' => true]

$agent->taskStatus($handle['task_id']);
```

| Tier | Condition | Behaviour |
|---|---|---|
| `runner` | a runner is injected (Swoole / Workerman coroutine, queue worker) | truly async, returns immediately |
| `fork` | `pcntl_fork` available and not in `disable_functions` | forks a child; the parent returns immediately |
| `sync` | neither available | runs to completion first, but still returns a task_id so the state machine is unchanged |

```php
use Ai\Agent\Orchestrator\BackgroundDispatcher;

$dispatcher = new BackgroundDispatcher([
    'resultDir' => '/var/data/bg',      // required for the fork tier: the parent cannot see the child's memory
    'runner'    => function (array $payload) { /* push to a queue */ return null; },
]);
$agent->orchestrator()->setDispatcher($dispatcher);
```

Results from the fork tier can only travel back through disk, so without `resultDir` there is no result to fetch. If a runner returns a result synchronously, the handle is marked `completed` rather than pretending the work is still running.

**Parallel sub-agents** degrade across the same three tiers (`runner` / `fork` / `sequential`):

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

The sequential tier produces exactly the same results, it just saves no time. If an injected runner returns a different number of results than there were jobs, execution falls back to sequential — better slow than pairing results with the wrong tasks.

`maxConcurrency` defaults to 4: one PHP request should not spawn dozens of agents.

### Result aggregation (ResultAggregator)

Fan out to three explorers and three multi-thousand-word reports come back. **The main Agent should receive only a summary by default**, with the full text left in each transcript to be fetched on demand — otherwise the time parallelism saved is paid straight back in a polluted context.

```php
$summary = $agent->orchestrator()->aggregator()->aggregate($results);

$summary['summary'];          // merged summary (this is what the main Agent gets)
$summary['findings'];         // per-track conclusions, truncated
$summary['files'];            // files mentioned, deduplicated
$summary['errors'];           // the tracks that did not finish, with reasons
$summary['recommendations'];  // extracted recommendations
$summary['transcripts'];      // task_id list — look here for the details
$summary['stats'];            // ['total' => 3, 'completed' => 2, 'failed' => 1]
```

The default is rule-based aggregation (truncate, classify, deduplicate) with no model call. Inject a model summariser when you want a better summary — that costs an extra call, which is not a decision the library should make for you:

```php
$aggregator->setSummarizer(function (array $results) use ($ai) {
    return $ai->ask('Merge these parallel investigation results into one summary: ' . json_encode($results));
});
```

If the summariser throws, aggregation falls back to rule-based assembly rather than losing the results because the summary failed.

### Sub-agent transcript persistence and resume

```php
$sam->setTranscriptDir('/var/data/transcripts');

$runId = $sam->runSync('explorer', '调查登录流程');
// visible from another process too
$sam2->setTranscriptDir('/var/data/transcripts');
$sam2->getTranscript($runId);

// Continue a sub-agent that was cut off (iteration limit, permission denied) instead of starting over
$newRunId = $sam->resume($runId, '继续看看权限校验');
$sam->getTranscript($newRunId)['resumed_from'];   // the original runId
```

Without `transcriptDir` the transcript lives only in memory and disappears with the process — background tasks and crash recovery both need it readable from another process, so long-running setups must configure it.

### Verification gate and completion criteria

**A task is not done just because the model says so.** Models are famously optimistic about their own progress: tests still red, three plan steps outstanding, the last tool call still erroring — and it will still report "completed".

```php
$agent->useVerificationGate();                      // pick the verification chain by task type
$agent->setCompletionCriteria([                     // "done" becomes a set of checkable conditions
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
    $agent->ask($outcome['prompt']);   // continue, carrying the reasons
}
```

The gate selects a different chain per task type — one chain for everything is either too loose (missing what mattered) or too tight (running the full integration suite over a typo fix):

| Task type | Chain | Keywords |
|---|---|---|
| `bug_fix` | syntax → unit tests | 修复 / 报错 / bug / fix |
| `feature` | syntax → security → unit tests | 新增 / 实现 / 支持 / implement |
| `refactor` | syntax → tests → change-size check | 重构 / 重写 / refactor |
| `security` | syntax → **security (must pass)** → tests | 安全 / 漏洞 / 注入 / injection |
| `default` | syntax + security | used when the type cannot be told |

```php
use Ai\Agent\Verification\VerificationGate;
use Ai\Agent\Verification\VerificationPolicy;

$gate = new VerificationGate($vm, VerificationPolicy::security());
$outcome = $gate->check(['file_path' => 'src/Auth.php']);

$outcome['passed'];    // did the gate let it through
$outcome['failed'];    // which steps failed, with `required` marking must-pass ones
$outcome['skipped'];   // steps named by the policy with no verifier mounted
$outcome['prompt'];    // failure text ready to feed back, with files and line numbers
```

A step named by the policy with no matching verifier is **skipped and recorded** rather than stalling the chain. `failFast` is on by default: once a must-pass step fails, the rest are not run for nothing.

Four built-in criteria: `verification_passed` (**never having run verification counts as unmet** — treating "no verification" as a pass is the same as having no gate), `no_pending_steps`, `no_pending_errors`, `model_claims_done`. Custom criteria can be registered:

```php
$criteria->addCriterion('has_changelog', function (array $context) {
    return ['met' => !empty($context['changelog']), 'reason' => '还没写 changelog'];
});
```

Presets: `CompletionCriteria::lenient()` (errors only — suits light tasks with no tests and no plan) and `::strict()` (all four).

### Task handoff (AgentHandoff)

The coder gets halfway and finds it is really a schema problem, so the task goes to the DBA and comes back afterwards. **A handoff must leave a trace** — otherwise, after a task has passed between several roles, nobody can say what it went through.

```php
$handoff = $team->handoff('developer', 'dba', '慢查询定位到索引缺失', [
    'task_id'         => 'task_9',
    'context_summary' => '已定位到 UserRepo::findByEmail，全表扫描 12 万行',
]);

// The DBA finishes and hands it back
$team->handoffBack($handoff, '索引已补，慢查询从 3s 降到 20ms');

$team->handoffChain('task_9');   // ['developer → dba', 'dba → developer']
```

A handoff automatically delivers a `handoff` message to the receiving role, so the next time it is assigned work, its inbox already says who handed this over, why, and how far it got.

Message types are now nine: the original `task` / `bug` / `review` / `status` / `result` plus `request` / `response` / `error` / `handoff`. A response automatically carries `reply_to` — when a role has three questions outstanding, without it there is no telling which answer belongs to which:

```php
$req  = AgentMessage::request('coder', 'dba', '这张表有索引吗');
$resp = AgentMessage::respondTo($req, '没有，需要补');
$resp->getReplyTo();   // the ID of $req
```

### Cross-session messaging (SessionBus)

`AgentCommunication` covers messages inside one team in one process. Crossing sessions is a different problem: a background Agent finishing in another process (or another PHP request entirely) still has to reach the main session. **That message has to hit disk to get there** — an in-memory queue is invisible to another process.

```php
// On the background Agent's side
$bus = new \Ai\Agent\Session\SessionBus('/var/data/session-bus');
$bus->send('session_main', AgentMessage::status('background', '安全扫描完成，发现 3 个问题'));

// On the main session's side
$bus = $agent->sessionBus('/var/data/session-bus');
$bus->pendingCount('session_main');           // 1
echo $bus->toPrompt('session_main');          // a <session-messages> block, ready to inject
$bus->receive('session_main');                // consumed on read
```

Subscription callbacks fire only for `send()` **within the same process** — a message delivered across processes has to be fetched with `receive()` on the other side. PHP has no long-lived inter-process push channel, and there is no way around that.

Without a directory it degrades to memory-only, usable within one process; **background task notifications must configure a directory**, or the message goes out with nobody able to receive it.

### Plan version chain

`modifyPlan()` no longer overwrites in place; it produces a new version. **"What was the original plan and why was it changed" is the key evidence when an Agent goes off track**, and overwriting destroys it.

```php
$plan = $pm->createPlan('迁移数据库', ['steps' => ['备份', '改表']]);
$plan->getVersionLabel();   // 'plan_v1'

$pm->modifyPlan($plan->getId(), ['append' => ['校验数据']], '发现漏了校验');
$pm->getPlan($plan->getId())->getVersion();   // 2

$pm->versions($plan->getId());          // historical snapshots
$pm->getVersion($plan->getId(), 1);     // v1 back, still two steps
$pm->diffVersions($plan->getId(), 1, 2);
// ['added' => ['校验数据'], 'removed' => [], 'reason' => '发现漏了校验']
```

### Workspace snapshot (WorkspaceSnapshot)

Take one at the start of a task and one at the end, and the difference tells you what the task actually changed — rather than trusting the model's own report that it "changed Auth.php". Models both under-report and over-report, especially when they changed their mind mid-run and reverted.

```php
use Ai\Agent\Workspace\WorkspaceSnapshot;

$before = WorkspaceSnapshot::capture('/var/www/project');
// …the Agent works…
$after = WorkspaceSnapshot::capture('/var/www/project');

$diff = WorkspaceSnapshot::diff($before, $after);
$diff['added'];            // files added
$diff['modified'];         // files modified
$diff['deleted'];          // files that disappeared
$diff['branch_changed'];   // did the branch move
$diff['content_changed'];  // did the diff hash change
```

A snapshot holds: cwd, branch, commit, modified files, untracked files and a hash of the working-tree diff. It still works outside a git repository — branch / commit / diff_hash simply come back empty. Record what can be recorded, leave the rest blank, guess nothing.

### Worktree wrap-up: merge or discard

Once a sub-agent has finished inside its isolated worktree and the diff is back, it is either merged or dropped:

```php
$samW->mergeWorktreeRun($runId, true);   // dry run first (git apply --check)
$samW->mergeWorktreeRun($runId);         // apply for real
// ['applied' => true, 'reason' => 'applied']

$samW->discardWorktreeRun($runId, '方案不对');   // leaves a trace: this diff was reviewed and rejected
```

It applies a patch with `git apply` rather than merging a branch — changes in a worktree are usually uncommitted, so there is no commit to merge.

### Skill lifecycle and dependencies

```php
$sm->onEvent(function ($e) { echo $e['type'], "\n"; });
// skill_discovered / skill_loaded / skill_activated / skill_deactivated

$sm->deactivate('deploy');   // stop injecting it on later rounds; allowed-tools are recomputed
```

SKILL.md recognises two more fields:

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

If a tool in `required_tools` is not currently available, loading the skill will not help — better to say so up front than to let the model follow the skill's instructions into a tool that does not exist.

### Nearest-first instruction discovery

```php
$im->setProjectRoot('/var/www/project');
$im->discoverFor('/var/www/project/src/Admin/User.php');
// loads in order: project/CLAUDE.md → project/src/AGENTS.md → project/src/Admin/AI.md
```

It walks up from the file's directory to the project root and loads what it finds furthest-first — **the rule nearest the current file wins**, because it is the most specific. Recognised names: `CLAUDE.md` / `AGENTS.md` / `AI.md` / `.ai/AGENTS.md`.

`projectRoot` bounds the upward walk: without a boundary it would climb to the filesystem root and drag in rules from other projects, or from the user's home directory. Already-loaded paths are skipped — injecting the same rules twice does not make the model follow them harder, it just costs context.

### Memory consolidation (MemoryConsolidator)

**Do not let every tool result flow into memory.** Memory turns into noise fast that way: every file read and every command run is in there, and the two or three entries that mattered are buried.

```text
Events → Task Result → Reflection → Memory Candidate → Consolidation → Memory
```

```php
$consolidator = $agent->memoryConsolidator();

$consolidator->propose('project', '登录走 JWT，密钥在 config/jwt.php', ['confidence' => 0.9]);
$consolidator->proposeFromReflection($reflectionResult);   // reflection conclusions are worth keeping
$consolidator->proposeFromResult($agentResult, 'task');    // first paragraph only, not the whole reply

$written = $consolidator->consolidate();   // dedupe + drop low confidence + sort by confidence + cap
```

Candidates are not written immediately — before `consolidate()` they only queue in memory. That is the point of the step: judging one entry's importance in isolation is hard, comparing a batch is easy.

Deduplication uses the retriever's relevance scoring as an approximation, so restating an existing memory does not add a second copy. Filter out sensitive content:

```php
$consolidator->setFilter(function (array $candidate) {
    return strpos($candidate['content'], '密码') === false;
});
```

### Multi-role team (AgentTeam)

An upgrade from "parent agent spawns a sub-agent" to "a set of roles that each own part of the work". The difference is that members persist: after Developer changes the code, Tester receives the context of that same round, and Reviewer can see what both concluded.

```php
use Ai\Agent\Team\AgentRole;

$team = $agent->team([
    AgentRole::developer(),
    AgentRole::tester(),
    AgentRole::reviewer(),
]);

// Assign to one member
$result = $team->assign('developer', 'Implement the login endpoint');
echo $result['status'];   // 'completed'
echo $result['text'];

// Pipeline: each stage's output feeds the next
$results = $team->pipeline('Add tests for the Auth module', ['developer', 'tester', 'reviewer']);

echo $team->toSummary();
// [developer] Add tests for the Auth module（completed，4 轮）：Added 3 test cases…
// [tester] …
```

Five roles are built in — `manager` / `developer` / `tester` / `security` / `reviewer` — each with a system prompt that spells out the boundary of its job. Tester's prompt, for instance, states plainly that it should report reproduction steps and not edit the implementation itself; without that, multi-role collaboration quickly degenerates into every role editing code.

Custom roles, with tools narrowed per role:

```php
$team->addMember(new AgentRole('dba', [
    'description' => 'Database schema and query tuning',
    'prompt'      => 'You are the DBA. You own schema and indexes; do not touch business code.',
    'tools'       => ['read_file', 'bash'],   // only these two tools
    'maxIter'     => 8,
]));
```

Members share the team's tool set and permission configuration but **each holds its own AgentRuntime and context** — which is the whole point of multiple roles: Tester's context should not be full of Developer's reasoning.

If a member throws, the run is recorded as `status = failed` and the pipeline continues — Reviewer knowing that Tester crashed is more useful than Reviewer knowing nothing.

### Inter-agent communication (AgentCommunication)

Messages between members are delivered and retained on a bus. Passing plain text loses the type and the sender, leaving the recipient unable to tell an assignment from a report — that is what the `type` field is for.

```php
use Ai\Agent\Team\AgentMessage;

$team->send(AgentMessage::bug('tester', 'developer', 'AuthTest::testLogin failed: expected true, got false', [
    'file' => 'tests/AuthTest.php',
    'line' => 42,
]));

$team->broadcast('Requirements are frozen; stop changing interface signatures');

$bus = $team->communication();
$bus->unreadCount('developer');            // unread count
$bus->peek('developer');                   // look without consuming
$bus->inbox('developer');                  // take (marks as read)
$bus->history(AgentMessage::TYPE_BUG);     // full history, filterable by type
$bus->between('tester', 'developer');      // traffic between two roles
```

Message types: `task` (assignment), `bug` (defect report), `review` (review comment), `status` (status sync, broadcast by default), `result` (execution result).

**When a task is assigned, unread messages are prepended to the task description automatically** — what the previous stage left for this member should not have to be asked for. The full history stays in `history()` for post-mortems: when multi-role collaboration goes wrong, the final result alone does not tell you which hand-off garbled it.

### Human approval (ApprovalWorkflow)

Code the AI writes does not count until a person says so: it submits the change with its diff, waits for approval to continue, and on rejection goes back with the reason. In enterprise settings this is a hard requirement — no automated change reaches production unsigned.

```php
$workflow = $agent->enableApproval('/var/data/approvals');

// Agent side: submit after making the change
$request = $agent->submitForApproval($diff, [
    'summary' => 'Fix the login 401',
    'files'   => ['src/Auth.php'],
]);

// Human side: another process, or a back-office page
foreach ($workflow->getPendingRequests() as $req) {
    echo $req->toSummary();      // summary + files + diff
}
$workflow->approve($request->getId(), 'Alex');
// or: $workflow->reject($request->getId(), 'Missing input validation', 'Sam');

// Agent side: read the outcome
$status = $workflow->getStatus($request->getId());   // approved / rejected / pending_review / expired
if ($status === \Ai\Agent\Approval\ApprovalRequest::STATUS_REJECTED) {
    $agent->ask($workflow->getRequest($request->getId())->toRejectionPrompt());
}
```

**Approval is inherently cross-process**: the Agent submits, a person approves, and hours may pass in between. So when a directory is given, requests are written to disk and `getStatus()` re-reads them every time — an in-memory copy alone would report `pending` forever, and a crashed Agent could not resume waiting. Without a directory, requests stay in memory, which suits interactive confirmation inside one process.

Other capabilities:

```php
$workflow->waitFor($id, 300);              // block, returning the current status on timeout rather than hanging forever
$workflow->onSubmit(function ($req) { … }); // email / chat notification / open a ticket on submission
$workflow->purgeDecided();                  // drop decided and expired requests
new ApprovalWorkflow('', ['ttl' => 3600]);  // requests expire after an hour
new ApprovalWorkflow('', ['autoApprove' => true]);  // local development: approve automatically
```

A decided or expired request cannot be decided again — an approval outcome is issued once. A request raised three days ago should not still be approvable, so once its `ttl` passes its status simply reads `expired`, with nothing to clean up first.

### Browser tool (BrowserTool)

Lets the Agent drive a real browser: open pages, click, fill forms, screenshot, extract content. Unlike HTTP fetching this runs Chrome — JS-rendered content, post-login state and client-side routing are all reachable here, and none of them are in the raw HTML.

```php
use Ai\Agent\Tools\BrowserTool;

$agent->addTool(new BrowserTool(['headless' => true]));

// Model calls:
//   browser(action: "open", url: "https://example.com")
//   browser(action: "wait", selector: "#results")
//   browser(action: "type", selector: "#q", text: "php")
//   browser(action: "click", selector: "button[type=submit]")
//   browser(action: "extract", selector: ".result")
//   browser(action: "screenshot", path: "shot.png", full_page: true)
//   browser(action: "close")
```

The session is reused: after one `open`, later `click` / `type` calls act on the same page, so login state and page state survive between calls.

Using `BrowserSession` directly:

```php
use Ai\Agent\Tools\BrowserSession;

if (!BrowserSession::isAvailable()) {
    echo 'No Chrome / Chromium installed on this machine';
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

It is built on the Chrome DevTools Protocol: a headless instance is started with `--remote-debugging-port`, page targets come from CDP's HTTP endpoints, and commands go over WebSocket. Clicks and typing run as in-page JS (typing dispatches `input` / `change` events, without which Vue / React never see the value change) rather than synthesised mouse coordinates — coordinate clicking goes wrong as soon as there is scrolling or an overlay.

**Prerequisites**: Chrome / Chromium installed locally and `proc_open` permitted. When it is missing the tool returns a clear error instead of throwing — a model told "there is no browser" can choose another route, whereas a crash leaves it only retrying. CDP talks over local `ws://`, so no openssl extension is needed.

Pass a `PathSafety` to confine screenshot paths to the workspace:

```php
$agent->addTool(new BrowserTool([], new \Ai\Agent\Tools\PathSafety('/var/www/project')));
```

### Code understanding (CodeAnalyzer)

Before the Agent changes code it has to understand it. `Ai\Code` scans a project to build a class index plus two relationship graphs — who calls whom, who depends on whom — so "what does changing this method affect" no longer means grepping the whole project.

```php
use Ai\Code\CodeAnalyzer;

$analyzer = new CodeAnalyzer();
$analyzer->scan('/var/www/project/src');

print_r($analyzer->stats());
// ['files' => 189, 'classes' => 189, 'methods' => 2107, 'callEdges' => 3604, 'dependencyEdges' => 422]

$analyzer->findCallers('login');                       // who calls login
$analyzer->findDependencies('App\Auth');               // what Auth depends on
$analyzer->findDependents('App\Auth', true);           // who depends on Auth (transitively) — the blast radius
$analyzer->findRelatedFiles('src/Auth.php');           // files worth reading alongside it
$analyzer->findSymbol('login');                        // which file and line defines it
```

`explain()` produces a class description ready to drop into a prompt — far cheaper than pasting the whole class, and it carries the "who depends on me" part that the source alone does not show:

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

**Single-file analysis** — works without building an index:

```php
use Ai\Code\FileAnalyzer;

$analysis = (new FileAnalyzer())->analyze('src/Auth.php');
echo $analysis->getNamespace();                    // 'App\Service'
print_r($analysis->getImports());                  // ['User' => 'App\Model\User', ...]
$class = $analysis->getMainClass();
echo $class->getParent();                          // fully qualified parent (resolved through imports)
print_r($class->getMethods());                     // method name => FunctionAnalysis
echo $class->getMethod('login')->getSignature();   // 'public function login($name, $password = ...): bool'
```

**The two graphs** — `CallGraph` and `DependencyGraph` can also be used on their own:

```php
$analyzer->callGraph()->impactOf('App\Auth::login');      // functions that reach login directly or indirectly
$analyzer->callGraph()->unreferenced();                   // functions with no callers (dead-code candidates)
$analyzer->dependencyGraph()->detectCycles();             // circular dependencies
$analyzer->dependencyGraph()->mostDepended(10);           // most depended-upon classes — riskiest to change
$analyzer->dependencyGraph()->layers();                   // classes layered by dependency depth
$analyzer->classAnalyzer()->allMethods('App\Auth', $analyzer->index());  // methods including inherited and trait ones
```

**On accuracy**: parsing is built on `token_get_all()` with no third-party parser. It resolves namespaces, imports (including group imports and aliases), inheritance and interfaces, method signatures (parameter types, nullability, variadics, return types), property visibility, class constants, and calls made inside function bodies.

What it cannot resolve is the runtime type of a variable. `$obj->save()` gives no clue what class `$obj` is, so the graph records `->save`, and asking for callers of `save` matches same-named methods on every class. **Treat the result as "possible callers" — good for narrowing a search, not sound enough to be the sole basis for a refactor.** The same goes for dynamic calls (`$class::$method()`) and code inside `eval`: static scanning cannot see them.

### Code index tool (CodeIndexTool)

Hands `Ai\Code\CodeAnalyzer` to the Agent: **scan once, query repeatedly**. An explorer investigating a class no longer greps the whole project each time, nor pulls the entire class source into its context.

```php
$agent->addTool(new \Ai\Agent\Tools\CodeIndexTool('/var/www/project/src'));

// Model calls:
//   code_index(action: "explain", target: "App\Auth")        structure + inheritance + dependents
//   code_index(action: "callers", target: "login")           who calls it
//   code_index(action: "dependents", target: "App\Auth")     blast radius of a change
//   code_index(action: "related", target: "src/Auth.php")    files worth reading together
//   code_index(action: "symbol", target: "login")            which line defines it
//   code_index(action: "stats")                              index size
//   code_index(action: "refresh")                            rebuild after editing files
```

`codeAgent()` installs it automatically, and the explorer / planner / reviewer / debugger built-in roles all carry it — understanding the code first is exactly their job.

The index is built lazily (on the first call) and then kept in memory. **Call `refresh` after editing files**, or queries will keep reporting the old structure.

Tool output carries the accuracy caveat: `$obj->save()` gives no receiver type, so `callers` matches same-named methods across classes. That is inherent to static scanning — the result means "possible callers".

### Project index (RepositoryIndexer)

On first entering a project, scan its structure once — framework, entry point, where the controllers / models / services / configs live — and store it as `project.index.json`. After that the Agent reads the index instead of rediscovering the layout every time.

```php
use Ai\Agent\Code\RepositoryIndexer;

$indexer = new RepositoryIndexer();
$index = $indexer->ensureIndex('/var/www/project');   // reuse if fresh, rebuild otherwise

echo $index->getFramework();        // 'Laravel'
echo $index->getEntry();            // 'public/index.php'
print_r($index->getFiles('controllers'));
print_r($index->getNamespaces());   // ['App\' => 'app/']
echo $index->toSummary();           // the <project> block for the prompt
```

Framework detection looks at composer dependencies first, then directory signatures, covering Laravel / Symfony / CodeIgniter / ThinkPHP / Yii / CakePHP / Laminas / Slim / WordPress. **When it cannot tell, it returns an empty string rather than guessing** — a wrong guess sends the model looking for files under the wrong conventions, which is worse than not knowing.

Staleness is judged by TTL (one day by default) or a newer composer.json, not by comparing every file's mtime — that is far too slow on a large project. File classification is a heuristic over paths and filenames (`app/Http/Controllers/Auth.php` → controllers); file contents are not read.

### User interaction (AskUser)

When a task description is ambiguous, multiple approaches are reasonable, or a key decision is needed, the Agent can ask the user a question instead of guessing. This is fundamentally different from Permission:

- **Permission**: answers "may I execute this?" (authorisation check)
- **AskUser**: answers "what should I do?" (Agent proactively asks the user)

```php
use Ai\Agent\Interaction\UserInteractionManager;

$uim = new UserInteractionManager();

$agent
    ->setUserInteractionManager($uim)
    ->setTools([...]);

$result = $agent->getRuntime()->run($messages);

// Paused, waiting for user's answer
if ($result->getStopReason() === 'waiting_user') {
    $questionId = $result->getExtra()['question_id'] ?? '';
    $answer = 'main';  // the user's choice

    // Resume after answering
    $result = $agent->answerUser($questionId, $answer, $messages);
    echo $result->getText();
}
```

The Agent calls the `ask_user` tool when it needs input, then pauses and waits. `answerUser()` feeds the answer back to the model and continues execution.

### Hooks

Inject custom logic at key points in the Agent execution chain without modifying core tools. Hooks cover the full lifecycle: before/after tool execution, before/after model calls, permission requests, tasks, sub-agents, context compaction, and agent start/stop.

```php
$agent
    // Called before each tool: return ToolResult to short-circuit (skip actual execution)
    ->onBeforeTool(function ($name, $input, $ctx) {
        if ($name === 'bash') {
            // log all bash commands to an audit trail
            return null; // return null to continue
        }
    })
    // Called after each tool: modify/wrap the result
    ->onAfterTool(function ($name, $result) {
        return $result;
    })
    // Called before each model call: modify request parameters
    ->onBeforeModel(function ($messages, $tools) {
        return ['messages' => $messages, 'tools' => $tools];
    })
    // Called after each model call: record or modify the response
    ->onAfterModel(function ($resp) {
        return $resp;
    });
```

Full hook list and signatures:

| Hook | Registration method | Signature | When it fires |
|------|---------|-----------|-------------|
| `before_tool` | `onBeforeTool` | `(string $name, array $input, ToolContext $ctx): ?ToolResult` | Before a tool runs; return `ToolResult` to short-circuit |
| `after_tool` | `onAfterTool` | `(string $name, ToolResult $result): ToolResult` | After a tool runs; modify/wrap the result |
| `tool_error` | `onToolError` | `(string $name, ToolResult $result): void` | After a tool errors (or permission is denied) |
| `after_tool_batch` | `onAfterToolBatch` | `(array $results): array` | After all tools in a batch finish, before the next model call; unified audit/state refresh |
| `before_model` | `onBeforeModel` | `(array $messages, array $tools): array` | Before a model call; return `['messages'=>..., 'tools'=>...]` to modify the request |
| `after_model` | `onAfterModel` | `($response): $response` | After a model call; modify/record the response |
| `permission_request` | `onPermissionRequest` | `(string $toolName, array $input, string $requestId): HookResult` | When a permission request is created; return `HookResult` to express an opinion |
| `task_start` | `onTaskStart` | `(string $taskId, string $goal): void` | When a task starts |
| `task_complete` | `onTaskComplete` | `(string $taskId, string $result): void` | When a task completes normally |
| `task_failed` | `onTaskFailed` | `(string $taskId, string $error): void` | When a task fails |
| `subagent_start` | `onSubagentStart` | `(string $agentName, string $task): void` | When a sub-agent starts |
| `subagent_stop` | `onSubagentStop` | `(string $agentName, string $result): void` | When a sub-agent finishes |
| `before_compact` | `onBeforeCompact` | `(int $tokenCount, int $messageCount): void` | Before context compaction |
| `after_compact` | `onAfterCompact` | `(int $messageCount): void` | After context compaction |
| `agent_start` | `onAgentStart` | `(): void` | When the Agent loop starts |
| `agent_stop` | `onAgentStop` | `(string $stopReason): void` | When the Agent loop ends (with the stop reason) |

`onTaskFailed` / `onAgentStart` / `onAgentStop` are not exposed as shortcut methods on `Agent` — inject the hooks container directly:

```php
use Ai\Agent\Hooks\AgentHooks;

$hooks = new AgentHooks();
$hooks->onTaskFailed(function ($taskId, $error) {
    log_message('error', "Task {$taskId} failed: {$error}");
});
$hooks->onAgentStart(function () {
    echo 'Agent started';
});
$hooks->onAgentStop(function ($stopReason) {
    echo "Agent stopped: {$stopReason}";
});

$agent->getRuntime()->setHooks($hooks);
```

#### HookResult: unified return value

`Ai\Agent\Hooks\HookResult` is the unified return type for hooks, supporting five actions:

| Action | Factory method | Description |
|--------|---------------|-------------|
| `CONTINUE` | `HookResult::go()` | Continue execution (default) |
| `ALLOW` | `HookResult::allow()` | Allow |
| `DENY` | `HookResult::deny($reason)` | Deny, with a reason |
| `MODIFY` | `HookResult::modify($data)` | Modify input and continue |
| `STOP` | `HookResult::stop($reason)` | Stop the Agent |

```php
use Ai\Agent\Hooks\HookResult;

$hooks->onPermissionRequest(function ($toolName, $input, $requestId) {
    if ($toolName === 'bash' && strpos($input['command'], 'DROP TABLE') !== false) {
        return HookResult::deny('DROP TABLE is forbidden');
    }
    return HookResult::go();
});
```

`HookResult` provides `getAction()` / `getReason()` / `getData()` and `isContinue()` / `isAllow()` / `isDeny()` / `isModify()` / `isStop()`.

#### Execution order

Hooks are wired into the execution chain in a fixed order:

```text
Model
 ↓
Tool Call
 ↓
before_tool (may short-circuit)
 ↓
Permission
 ↓
Tool execution
 ↓
tool_error (on error) / after_tool
 ↓
after_tool_batch (after all tools)
 ↓
Tool Result fed back
 ↓
Model
```

Note: **a hook's allow cannot bypass a hard permission deny.** Priority: `Deny rules → Permission Deny → Allow → Ask`. `before_tool` can only short-circuit "the tool about to run", it cannot undo a denial already made by the permission system.

### Task system (Task)

The Agent task system separates "the whole user goal" from "what the model says each turn". A single Task spans multiple Turns and supports full lifecycle management.

Core components:

- `AgentTask` — task value object: id, goal, status, parentTaskId, sessionId
- `TaskState` — task progress record: completed, pending, blocked, importantFacts, modifiedFiles, errors, subtasks
- `TaskManager` — manages the task lifecycle (queued → running → waiting_permission/waiting_user → paused → completed/failed/cancelled)
- `TaskStatus` — status enum constants

```php
use Ai\Agent\Task\TaskManager;

$tm = new TaskManager();

// Create a task
$task = $tm->create('Fix the login issue', 'sess_abc');

// Run the task through an AgentRuntime
$result = $tm->start($task->getId(), $runtime, $messages);

// Lifecycle control
$tm->pause($task->getId());
$tm->resume($task->getId());
$tm->cancel($task->getId());
```

#### TaskState progress record

TaskState records detailed execution progress. Feed it into the system prompt after context compaction so the Agent still knows where the task stands:

```php
use Ai\Agent\Task\TaskState;

$state = new TaskState(['goal' => 'Fix the login issue']);
$state->addCompleted('Found Auth.php');
$state->addPending('Run PHPUnit');
$state->addModifiedFile('Auth.php');
$state->addImportantFact('session expiry is misconfigured');

// Generate a progress summary
echo $state->toSummary();
// # Task state
// Goal: Fix the login issue
//
// ## Completed
// - Found Auth.php
//
// ## Pending
// - Run PHPUnit
//
// ## Modified files
// - Auth.php
// ...
```

#### Injecting TaskManager through Agent

```php
$agent = (new Agent($ai))
    ->setSystem('Assistant')
    ->setTaskManager($tm)         // inject the task manager
    ->setTaskId($task->getId());  // bind the current task

$agent->run($messages);
// The task is automatically marked completed or failed afterwards
// task_start / task_complete / task_failed events are also emitted
```

Task lifecycle events are received via `onEvent()`:

| Event | Meaning |
|-------|---------|
| `task_start` | the task started running |
| `task_complete` | the task completed normally |
| `task_failed` | the task failed (including exceptions/errors) |

### Runtime architecture

Internal structure since v2.0:

```
Agent (public API)
  ├── setTools() / setSystem() / onEvent() / setMaxIter() / run()
  ├── setPermissionMode() / setSessionId() / setMaxBudget()
  ├── setFallbackModels() / setToolTimeout()
  ├── setTaskManager() / setTaskId()                    ← task system
  ├── setUserInteractionManager()                      ← user interaction
  ├── setVerification()                                ← verification management
  ├── setWorkspaceDir()                                ← workspace management
  ├── loadSkills() / setSkillManager()                 ← skills
  ├── loadInstructions() / setInstructionManager()     ← project instructions
  ├── setMcpServers() / setMcpManager()                ← MCP runtime
  ├── setMemoryDir() / setMemoryManager()              ← scoped memory
  ├── setCheckpointDir() / recoverFromCrash()          ← checkpoint / crash recovery
  ├── approve() / deny()                              ← user approval
  ├── answerUser()                                    ← answer user questions
  ├── onBeforeTool() / onAfterTool()                  ← tool hooks
  ├── onToolError() / onAfterToolBatch()              ← tool error & batch post hooks
  ├── onBeforeModel() / onAfterModel()                ← model hooks
  ├── onPermissionRequest()                           ← permission hook
  ├── onTaskStart() / onTaskComplete()                ← task hooks
  ├── onSubagentStart() / onSubagentStop()            ← sub-agent hooks
  ├── onBeforeCompact() / onAfterCompact()            ← context compaction hooks
  └── getRuntime() ─────────────────────────────────→  AgentRuntime (execution engine)
        ├── ToolRegistry (tool registry)               ← AgentToolInterface registration
        ├── ToolExecutor (tool executor)               ← retry / timeout / output truncation
        ├── LoopController (loop controller)           ← drives the main loop (with fallback)
        ├── LoopGuard (loop guard)                     ← repeated-call & progress detection
        ├── ParallelToolExecutor (parallel executor)   ← parallel-safe tools
        ├── PermissionManager (permission manager)     ← 6 modes + rule matching
        ├── ContextManager (context manager)            ← turn-aware compaction
        ├── SessionManager (session manager)            ← persistence / pause / resume
        ├── BudgetManager (budget manager)              ← token / cost control
        ├── VerificationManager (verification manager)  ← auto-verify after tool execution
        ├── WorkspaceManager (workspace manager)        ← git status tracking
        ├── SkillManager (skill manager)                ← skill directory + use_skill tool
        ├── InstructionManager (instruction manager)    ← CLAUDE.md / AGENTS.md
        ├── McpManager (MCP manager)                    ← stdio JSON-RPC tools
        ├── MemoryManager (memory manager)              ← scoped long-term memory
        ├── CheckpointManager (checkpoint manager)       ← per-iteration checkpoint auto-save
        ├── SubAgentManager (sub-agent manager)         ← spawn_agent tool
        ├── TaskManager (task manager)                 ← task lifecycle (AgentTask / TaskState)
        ├── UserInteractionManager (user interaction)  ← ask_user tool
        └── AgentHooks (hooks)                         ← full lifecycle hooks: before/after_tool, tool_error,
                                                         after_tool_batch, before/after_model,
                                                         permission_request, task_start/complete/failed,
                                                         subagent_start/stop, before/after_compact,
                                                         agent_start/stop
```

Access the internals through `$agent->getRuntime()`.

### Stop reasons (StopReason)

Get the stop reason via `$result->getStopReason()`:

| Constant | Value | Description |
|----------|-------|-------------|
| `END_TURN` | `end_turn` | The model gave its final answer — normal termination |
| `MAX_ITER` | `max_iter` | Hit the maximum iteration cap |
| `NO_PROGRESS` | `no_progress` | Repeated the same tool call without progress |
| `TOOL_ERROR` | `tool_error` | A tool threw an error |
| `USER_CANCEL` | `user_cancel` | User cancelled |
| `BUDGET_EXCEEDED` | `budget_exceeded` | Budget exceeded |
| `TIMEOUT` | `timeout` | Timed out |
| `PERMISSION_DENIED` | `permission_denied` | Permission denied or waiting for user approval |
| `WAITING_USER` | `waiting_user` | Waiting for user answer (ask_user) |
| `MODEL_ERROR` | `model_error` | Model returned an error |

---

## Editor: AI code editing

`Ai\Editor\*` provides a complete protocol for letting an AI edit code: the editor's situation (current file, cursor, selection, open files, workspace conventions) is structured and handed to the model, which returns verifiable, executable edit actions.

```php
use Ai\Editor\EditContext;
use Ai\Editor\EditProtocol;
use Ai\Editor\EditExecutor;
use Ai\Editor\EditAction;

// 1. Assemble the editing context
$ctx = (new EditContext(FCPATH))
    ->setFile('templates/default/index.php')
    ->setLanguage('php')
    ->setContent($fileContent)
    ->setCursor(['line' => 42, 'column' => 8])
    ->setSelection(['start' => [...], 'end' => [...]], $selectedText)
    ->setOpenedFiles($openedFiles)
    ->setWorkspace($workspace);      // Ai\Editor\Workspace — writable root and coding conventions

// 2. Build the system prompt (which explains the edit protocol) plus the context JSON
$system  = EditProtocol::systemPrompt($ctx);
$ctxJson = json_encode($ctx->toPromptJson(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$response = $ai->chat([
    'system'   => $system,
    'messages' => [['role' => 'user', 'content' => $message . "\n\n[CONTEXT]\n```json\n{$ctxJson}\n```"]],
]);

// 3. Parse the edit plan returned by the model
$plan = EditProtocol::parse($response->getContent());

// 4. Validate and execute
$executor = new EditExecutor($workspace->getRoot());   // out-of-bounds paths are rejected
foreach ($plan->toArray()['actions'] as $a) {
    $action = EditAction::fromArray($a);
    if (!$action->validate()) continue;
    $abs        = $executor->resolveAbsolute($action->file);   // safe path resolution
    $newContent = $executor->computeContent(file_get_contents($abs), $action);
    file_put_contents($abs, $newContent);                      // back up first in production
}
```

Combined with an Agent this supports three modes: `plan` (read-only planning), `approval` (produce suggestions for human review) and `auto` (write automatically with backups).

---

## Web search

Many platforms let the model search the web before answering. Every vendor spells the switch
differently — some take a boolean at the top of the request body, some want a built-in tool
pushed into `tools`, others go through a plugin system. This library normalises them into a
single `search` option:

```php
$ai = AI::create([
    'model'   => 'qwen-plus',
    'api_key' => 'sk-xxx',
    'search'  => true,          // turn on web search
]);

echo $ai->chat('What are today\'s top news stories?')->getContent();
```

Switching platforms only changes `model`; the `search` line stays as it is:

```php
$ai->setConfig(['model' => 'claude-sonnet-4-20250514', 'api_key' => 'sk-ant-xxx']);
$ai->setConfig(['model' => 'glm-4-plus',               'api_key' => 'xxx']);
```

### Fine-grained options

Pass an array to `search` to tune the details. Omitting `enable` means enabled:

```php
$ai->setConfig([
    'search' => [
        'enable'          => true,
        'max_uses'        => 5,                 // max searches per request
        'count'           => 10,                // number of results returned
        'query'           => 'PHP 8.5 features', // force the search term; otherwise the model writes it
        'recency'         => 'week',            // freshness: hour / day / week / month / year
        'forced'          => true,              // always search, don't let the model decide
        'citation'        => true,              // inline citation markers in the answer
        'sources'         => true,              // return the list of sources
        'allowed_domains' => ['wikipedia.org'], // search only these domains
        'blocked_domains' => ['spam.com'],      // never search these (mutually exclusive with the above)
    ],
]);
```

**Options a platform doesn't have are silently ignored** and do not stop search itself from
being enabled. This is deliberate: the unified layer promises "search will be on", not "every
detail takes effect everywhere". Here is where each option actually lands:

| Unified option | Claude | Qwen | Zhipu GLM | Kimi | ERNIE | OpenRouter | Perplexity |
|---------|--------|---------|---------|------|---------|-----------|-----------|
| `max_uses` | `max_uses` | — | — | — | — | — | — |
| `count` | — | — | `count` | — | `search_number` | `max_results` | — |
| `query` | — | — | `search_query` | — | — | — | — |
| `recency` | — | — | `search_recency_filter` | — | — | — | `search_recency_filter` |
| `forced` | — | `forced_search` | `require_search` | — | — | — | — |
| `citation` | always on | `enable_citation` | — | — | `enable_citation` | — | — |
| `sources` | — | `enable_source` | `search_result` | — | `enable_trace` | — | `return_related_questions` |
| `allowed_domains` | ✅ | — | first one only | — | — | `include_domains` | ✅ |
| `blocked_domains` | ✅ | — | — | — | — | `exclude_domains` | prefixed with `-` |

A few differences worth knowing:

- **Claude** always cites its sources; there is no switch. Passing `allowed_domains` and
  `blocked_domains` together is a 400 on the platform, so this library rejects it before
  the request goes out.
- **Zhipu**'s `search_domain_filter` is officially a string rather than an array, so only the
  first domain is used.
- **Zhipu** has no "past hour" bucket, so `recency => 'hour'` is widened to `oneDay`.
- **Perplexity**'s Sonar models **are always online**; there is nothing to turn on. Here
  `search` only carries the filters.
- **Kimi**'s built-in search runs through the tool_calls flow — the model only produces the
  search arguments, and the conversation continues only after the client feeds the result
  back. It therefore has to be used with the Agent loop; a single `chat()` call just returns
  a tool call:

  ```php
  $ai->setConfig(['model' => 'kimi-k2-0905-preview', 'search' => true]);

  $agent = new \Ai\Agent\Agent($ai);                                     // ✅ use an Agent
  $agent->run([['role' => 'user', 'content' => 'What are today\'s top news stories?']]);
  echo $agent->lastText();
  ```

### Which platforms support it

```php
print_r(\Ai\Helpers\Protocols::withWebSearch());
// ['claude', 'qwen', 'ernie', 'zhipu', 'moonshot', 'perplexity', 'openrouter']

\Ai\Helpers\Protocols::supportsWebSearch('deepseek');   // false
```

**On any platform not listed, setting `search` throws a `ConfigException`** instead of being
silently ignored. Silence would be the worst outcome here: you would get an answer that reads
perfectly well but never went online, stale with no indication at all — usually noticed only
once the model refers to last year's events as current.

Two cases deserve a note:

- **OpenAI**'s Chat Completions endpoint has no web-search switch. Web search is offered on the
  Responses API or through dedicated search models such as `gpt-5-search-api`. This library
  talks to Chat Completions, so the `openai` protocol does not declare support.
- **The Anthropic-compatible endpoints of Qwen / Zhipu / Kimi** (`qwen-anthropic` and friends)
  do not support it either. Those gateways only translate Anthropic's **request format**;
  Anthropic's web_search is Anthropic's own server-side capability and does not travel with the
  format. To search on those platforms, use their OpenAI-compatible protocols instead.

### Vendor-specific parameters: use `extra_body`

The unified option only covers semantics every vendor shares. Vendor-specific parameters
(Qwen's `search_strategy`, Zhipu's `search_engine`, OpenRouter's `engine`, …) do not go into
`search` — write them with `extra_body`:

```php
$ai->setConfig([
    'search'     => ['forced' => true],
    'extra_body' => ['search_options' => ['search_strategy' => 'max']],
]);
```

`extra_body` merges at the top level of the request body, so **a key present in both replaces
the whole value** produced by `search` — in the example above the `search_options` actually sent
contains only `search_strategy`, and `forced_search` is gone. To use both, write every sub-field
into `extra_body`.

`extra_body` is also the escape hatch when the library's view of a platform is wrong or out of
date: it bypasses every capability check and sends the platform's native search parameters
directly.

### How this differs from `Ai\Tools\HttpFetch`

Both give the model access to web content, but they are not the same thing:

| | `search` option | `Ai\Tools\HttpFetch` (next section) |
|---|---|---|
| Who goes online | the platform's servers | your PHP process |
| Billing | the platform charges per search | tokens only; traffic goes through your server |
| Capability | search-engine retrieval | fetches URLs you name |
| Control | filters only | fully under your control, with SSRF protection |
| Coverage | only the 7 platforms above | every platform |

Use `search` when the model should decide what to look up; use `HttpFetch` when you want it to
read specific pages you name. Both can be on at once.

---

## Tools: safe web fetching

When you let a model read the web, the biggest risk is SSRF. `Ai\Tools\HttpFetch` has defence in depth built in:

- `http`/`https` only; URLs containing `user:pass@` are rejected
- Port allowlist (80/443 by default)
- Every A/AAAA record of the host is resolved, and **if any IP falls in a private / reserved / loopback / link-local / cloud-metadata range the whole request is rejected**
- `CURLOPT_RESOLVE` pins the connection to the validated IP, defeating DNS rebinding
- Redirects are not followed automatically; every hop is re-validated
- Aborts once `max_bytes` is exceeded; verifies TLS; sends no cookies; ignores the site proxy

```php
use Ai\Tools\HttpFetch;
use Ai\Tools\WebContent;

$fetcher = new HttpFetch(['max_bytes' => 1500 * 1024, 'timeout' => 15]);
$res     = $fetcher->fetch($url);
// $res = ['ok'=>bool, 'status'=>int, 'content_type'=>string, 'final_url'=>string, 'bytes'=>int, 'body'=>string, 'error'=>string]

if ($res['ok']) {
    // Render into a model-friendly format: text / md (Markdown) / source (raw)
    $text = WebContent::render($res['body'], $res['content_type'], 'md', 16000);
}
```

Wrap it as an Agent tool and the model can browse on its own:

```php
'fetch_url' => [
    'description'  => 'Fetch a public web page and return its body, for verifying live information.',
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

## Memory: long-term Agent memory

Treat a Markdown file as the Agent's persistent memory (much like `CLAUDE.md`). Your code decides where it lives; the library knows no specific path.

```php
use Ai\Agent\Memory;

$mem = new Memory(FCPATH . 'writable/agent/memory.md', 20000);  // second argument: max characters injected into the prompt

$block = $mem->forPrompt();          // read and truncate; empty memory returns ''
if ($block !== '') {
    $system .= "\n\n# Long-term memory\n" . $block;
}

$mem->append('The user prefers dark themes');    // append one entry
$mem->write($fullContent);                        // overwrite the whole file
```

---

## Full example: batch JSON translation

A real scenario — send multilingual entries to the AI in batches, require a strict `{"recordId":"translation"}` JSON reply, and handle retries, format validation and write-back.

```php
use Ai\AI;
use Ai\Exceptions\AIException;

// 1. Assemble the data: { recordId: sourceText }
$translateData = [];
foreach ($batch as $rec) {
    $translateData[(int)$rec['id']] = $rec['text_source'];
}

// JSON_UNESCAPED_SLASHES matters: without it </p> becomes <\/p>, and the model
// copies the literal \/ into the translation, corrupting the output
$dataJson = json_encode($translateData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$prompt = "Translate each value in the JSON below from {$from} to {$to}, "
        . "keeping the keys unchanged, preserving HTML tags, and returning JSON only:\n{$dataJson}";

// 2. Call, with retries and JSON validation
$ai = new AI();
$ai->setConfig(['model' => $model, 'api_key' => $apiKey, 'max_tokens' => 1024 * 256])
   ->setTimeout(300);

$content = '';
for ($loop = 1; $loop <= 3; $loop++) {
    try {
        $content = trim($ai->chat($prompt)->getContent());
    } catch (AIException $e) {
        log_message('error', 'AI translation failed: ' . $e->getMessage());
        break;
    }
    // Strip the ```json ``` fence the model may wrap around the result, then validate
    $content = preg_replace('@^\s*```(json)?\s*(.+?)\s*```\s*$@is', '$2', $content);
    if ($content !== '' && json_decode($content, true) !== null) break;
    $content = '';
}

// 3. Write back
$result = json_decode($content, true);
if (is_array($result)) {
    foreach ($result as $id => $text) {
        if (!isset($translateData[(int)$id]) || trim($text) === '') continue;
        update_translation((int)$id, trim($text));
    }
}
```

Lessons from production:

- **Always pass `JSON_UNESCAPED_SLASHES`**, and add a `str_replace('\\/', '/', $text)` safety net on the result.
- Models often wrap JSON in a ```` ```json ```` fence — strip it before parsing.
- Split batches by **total character count**, not item count (e.g. 100 000 characters per batch), and give an oversized single HTML entry its own batch.
- Log the model's raw reply on failure, or you will have nothing to debug with.

---

## Extending the library

### Adding a model

```php
namespace Ai\Models\OpenAI;

use Ai\Models\BaseModel;

class GPT4Turbo extends BaseModel
{
    protected $name     = 'gpt-4-turbo';                        // the real model name sent to the platform
    protected $platform = 'openai';
    protected $protocol = 'Ai\\Protocol\\OpenAI';               // reuse the protocol
    protected $endpoint = 'https://api.openai.com/v1/chat/completions';
    protected $features = ['chat', 'stream', 'vision'];
    protected $config   = ['max_tokens' => 4096, 'temperature' => 0.7];
}
```

Then register `'gpt-4-turbo' => 'Ai\Models\OpenAI\GPT4Turbo'` in `AI::$modelMap`.

For models needing special attachment handling, override `processAttachments(array $payload, array $attachments): array`.

> If you only want to try a new model or endpoint, **you do not need a model class** — `model` + `protocol` + `base_url` is enough, and the library builds an `Ai\Models\CustomModel` at runtime. You can also `new CustomModel([...])` yourself and pass it to `setModel()`.

**Most new platforms are OpenAI-compatible**: extend `Ai\Protocol\OpenAI` and change one address — that is how all 30-odd platform protocols in this library are implemented:

```php
namespace Ai\Protocol;

/**
 * SomeCloud (OpenAI compatible)
 */
class MyCloud extends OpenAI
{
    public function defaultBaseUrl(): string
    {
        return 'https://api.mycloud.com';
    }

    // Override only when the paths differ from OpenAI's
    public function chatPath(): string   { return '/v2/chat/completions'; }
    public function modelsPath(): string { return '/v2/models'; }

    // Override buildHeaders() when auth is not Authorization: Bearer

    /** Common models: renders an offline dropdown and acts as a fallback when fetching fails */
    public function knownModels(): array
    {
        return ['my-model-pro' => 'MyCloud Pro'];
    }
}
```

Then register the identifier in `Ai\Helpers\Protocols::$map` (adding aliases and model-name detection rules to `$alias` / `$detect` while you are there) and `protocol => 'mycloud'` works.

**When the protocol format itself differs** (neither OpenAI nor Anthropic nor Gemini):

1. Implement `Ai\Contracts\ProtocolInterface`: `buildRequest`, `parseResponse`, `buildHeaders`, `parseStreamChunk`, `isStreamEnd`, `listModels`. Optionally implement `defaultBaseUrl()` / `chatPath()` / `modelsPath()` so endpoints assemble automatically, and `use ModelCatalog` for the common-model list and fallback behaviour.
2. Create a model class for the platform whose `$protocol` points at the new protocol — or simply pass the protocol class name as the `protocol` config value (no changes to this library required):
   ```php
   AI::create(['model'=>'x', 'protocol'=>'App\\Protocol\\MyApi', 'base_url'=>'https://api.my.com']);
   ```
3. Register the model identifier in `AI::$modelMap` (optional — only to provide a shortcut).

The transport layer `Ai\Transport\CurlTransport` is protocol-agnostic and rarely needs changes.

---

## Architecture

```
php-ai/
├── src/                    # source (PSR-4 namespace Ai\)
│   ├── AI.php              # main entry: config, model resolution, chat, streaming, callbacks
│   ├── Agent/              # Agent loop + long-term memory
│   ├── Cli/                # ClaudeCode one-shot / ClaudeCodeSession persistent duplex + response objects
│   ├── Contracts/          # interfaces: Model / Protocol / Transport / AIResponse
│   ├── Editor/             # AI code editing: context / protocol / action / executor / workspace
│   ├── Exceptions/         # AIException / ConfigException / RequestException / ProcessException
│   ├── Helpers/            # AIFile (attachments), Endpoint (endpoint resolution), Protocols (registry)
│   │                       # Headers (header merging), Tools (tool-call normalisation), Log (injectable logging)
│   ├── Models/             # model layer: names, endpoints, capabilities, default config per platform
│   │   ├── BaseModel.php
│   │   ├── CustomModel.php # generic model: any name + explicit protocol + custom endpoint
│   │   ├── OpenAI/  Claude/  Gemini/  DeepSeek/
│   ├── Protocol/           # protocol layer: 40 platform protocols
│   │                       #   ModelCatalog.php   common-model list + fetch-failure fallback (trait)
│   │                       #   OpenAI / Claude / Gemini  the three base protocol formats
│   │                       #   China:  Qwen / Doubao / Ernie / Zhipu / Moonshot / Hunyuan / Spark /
│   │                       #           MiniMax / StepFun / Yi / Baichuan / SenseNova / Zhinao / ModelArts …
│   │                       #   Global: Grok / Mistral / Cohere / Perplexity / Llama / Azure …
│   │                       #   Aggregators: OpenRouter / SiliconFlow / ModelScope / Groq / Together / Fireworks …
│   │                       #   Local:  Ollama / LMStudio / VLLM
│   ├── Response/           # unified response objects
│   ├── Tools/              # HttpFetch (SSRF protection), WebContent (formatting)
│   └── Transport/          # cURL transport (SSE parsing, proxy, timeouts)
├── autoload.php            # PSR-4 loader (include this when not using Composer)
├── composer.json
├── tests/                  # regression tests (plain PHP: no PHPUnit, no network, no API key)
│   ├── smoke_test.php      # every class loads/instantiates; inheritance signatures compatible
│   ├── stream_test.php     # 40 protocols × plain chat / streaming / token accounting
│   ├── tools_test.php      # cross-platform tool-calling consistency (one snippet, both protocol families)
│   ├── lib_test.php        # concurrent batching / Memory concurrency safety / pricing / log injection
│   ├── cli_test.php        # CLI flag rendering and command-injection protection
│   └── ssrf_test.php       # every known SSRF bypass vector
├── examples*.php           # usage examples (examples_platforms.php covers multi-platform setup)
├── LICENSE
├── README.md
└── .gitignore
```

The design has four layers: the **model layer** declares *what* (name, endpoint, capabilities), the **protocol layer** handles *how to speak* (request/response/streaming formats), the **transport layer** handles *how to send* (cURL, proxy, timeouts, SSE), and the **main entry** orchestrates. Adding a platform touches only the first two.

---

## Extended capabilities: images / audio / video / embeddings

> **Current status**
>
> | Capability | Status |
> |------------|--------|
> | Text embeddings `embeddings()` | ✅ v1.15.0 |
> | Image generation `images()` | ✅ v1.16.0 sync, v1.20.0 async and editing |
> | Speech synthesis / recognition `audio()` | ✅ v1.17.0, binary and JSON-hex forms normalised |
> | WebSocket `realtime()` | ✅ v1.18.0, iFlytek speech (RFC 6455, pure PHP) |
> | Video generation `video()` | ✅ v1.19.0, four platforms' async tasks normalised |
>
> Calling a capability a platform does not have raises `UnsupportedCapabilityException` with the reason — it never returns an empty result silently. Chat (`chat()`) is entirely unaffected.

### Entry points

Everything beyond chat goes through sub-facades that share the same configuration as `chat()`: configure `setProxy()` / `setRetry()` / `setTimeout()` / `setLogger()` once and every capability inherits it.

```php
$ai = new AI(['api_key' => '...', 'model' => '...']);

$ai->embeddings();   // text embeddings
$ai->images();       // image generation
$ai->audio();        // speech synthesis / recognition (HTTP)
$ai->video();        // video generation (async task)
$ai->realtime();     // WebSocket channel, disabled by default
```

### Check first, then call

```php
use Ai\Helpers\Capabilities;

if ($ai->supports(Capabilities::IMAGE)) {
    $img = $ai->images()->generate('A cat reading a book', ['size' => '1024x1024']);
    $paths = $img->saveTo('/var/www/uploads');   // returns the absolute paths actually written
}

$ai->capabilities();   // capabilities the current model supports, e.g. ['embedding', 'image']
```

Calling without checking is fine too — an unsupported capability raises an exception explaining why, rather than returning an empty value and leaving you to guess:

```
The protocol used by the current model does not support "image generation".
This protocol currently supports chat only.
```

### Text embeddings

```php
$ai = new AI(['api_key' => '...', 'model' => 'text-embedding-3-small']);

// Single input
$vec = $ai->embeddings()->create('This is some text')->getVector(0);

// Batch: the returned order **always** matches the input order
$res = $ai->embeddings()->create(['First', 'Second', 'Third']);
$res->getVectors();      // [[...], [...], [...]]
$res->getVector(1);      // the vector for "Second"
$res->getDimensions();   // 1536
count($res);             // 3
$res->getUsage();        // ['prompt_tokens' => .., 'total_tokens' => ..]
```

**That ordering guarantee is bought, not free**: most platforms do not promise that the `data` array comes back in input order, so the library reorders by each item's `index`. This kind of mismatch raises no error at all — it merely makes your later retrieval inexplicably inaccurate.

#### Platform parameters pass straight through

```php
$ai->embeddings()->create($texts, [
    'dimensions'      => 512,      // OpenAI text-embedding-3-* supports dimensionality reduction
    'encoding_format' => 'base64', // when base64 is returned the library decodes it back to floats
]);
```

Only `model` and `input` are normalised; everything else is forwarded verbatim. Support for parameters like `dimensions` varies by platform — consult their documentation. The library does not guess on your behalf, and an unsupported platform returns its own error message.

#### Automatic batching for large inputs

Platforms cap the number of inputs per request very differently (OpenAI allows thousands; some allow a dozen or two), and the official documentation does not always say. The default is **no batching** (one request, no pointless splitting); specify `batch_size` when you need it:

```php
$res = $ai->embeddings()->create($tenThousandTexts, ['batch_size' => 25]);
// split into 400 requests, merged in the original order, usage accumulated per batch
```

If a batch returns a different number of vectors than the texts submitted, an exception is raised **immediately** rather than continuing — a count mismatch means every later index is off, and silently carrying on is far more dangerous than failing.

#### Coverage

31 protocols declare embedding support. Paths are derived as siblings of each protocol's chat path (`/v1/chat/completions` → `/v1/embeddings`, `/v4/chat/completions` → `/v4/embeddings`), so prefixed gateways, Azure and Gemini's compatibility endpoint all work automatically.

The Anthropic Messages family (`claude`, `qwen-anthropic`, `zhipu-anthropic`, `moonshot-anthropic`, `deepseek-anthropic`) is **not supported** — Anthropic has no embeddings endpoint. Calling it raises a clear error.

> A platform audit (v1.21.0 – v1.24.0) verified each platform against its official documentation and endpoint probes. DeepSeek, SenseNova, Llama, Perplexity and Cerebras were confirmed to have **no** embeddings endpoint and no longer declare one. If you know otherwise, configure `embedding_endpoint` to bypass the library's judgement.

### Image generation

```php
$ai = new AI(['api_key' => '...', 'model' => 'gpt-image-1']);

$img = $ai->images()->generate('A cat reading a book', ['size' => '1024x1024', 'n' => 2]);

$img->getUrls();            // ['https://...', 'https://...']
$img->getBase64();          // present when the platform returns base64
$img->getRevisedPrompt();   // some platforms rewrite the prompt; it is passed through
count($img);                // 2

// ⚠️ Save promptly: every platform's image URLs expire
$paths = $img->saveTo('/var/www/uploads', 'cat');
// ['/var/www/uploads/cat_1.png', '/var/www/uploads/cat_2.png']
```

**URL expiry is the easiest trap here**: Wanx URLs last about 24 hours, SiliconFlow's **only one hour**. Store the URL in your database and users coming back later see nothing but broken images. To keep results, call `saveTo()`.

`saveTo()`'s directory **must already exist**; it is not created for you. Multimodal code often writes in a loop, and an automatic `mkdir` on a mistyped path scatters empty directories across the disk that you notice far too late. Downloads go through the library's `HttpFetch` (with full SSRF protection), not a bare `file_get_contents()`.

#### Field differences already normalised

Image APIs are far less uniform than chat. The following differences (verified against each platform's official documentation, 2026-08) are handled inside the library, so **your code is identical on every platform**:

| Platform | Actual field | You still write |
|----------|--------------|-----------------|
| SiliconFlow | `image_size` / `batch_size`, response is `images[]` | `size` / `n` |
| xAI | `aspect_ratio` + `resolution`, no `size` | `size` (converted to the nearest ratio bucket) |
| Volcengine Ark (Doubao) | `response_format: "base64"` | `response_format: "b64_json"` |
| OpenAI `gpt-image-*` | returns `b64_json` only, no `url` | either `getUrls()` or `getBase64()` works |
| iFlytek Spark | three-part `header`/`parameter`/`payload` body, base64 image | `prompt` / `size` |

Vendor-specific parameters (`seed`, `guidance_scale`, `watermark`, `negative_prompt` …) pass through untouched. Normalising every parameter of every platform would make the normalisation layer unmaintainable.

#### Supported platforms and models

Model lists come from **each platform's official documentation**, not guessed from endpoint probes:

| Platform | Models |
|----------|--------|
| OpenAI | `gpt-image-1.5`, `gpt-image-1`, `gpt-image-1-mini`, `dall-e-3`, `dall-e-2` |
| Gemini | `gemini-3.1-flash-image`, `gemini-3.1-flash-lite-image`, `gemini-3-pro-image`, `gemini-2.5-flash-image` |
| iFlytek Spark | via its own TTI endpoint (`maas-api.cn-huabei-1.xf-yun.com/v2.1/tti`); requires `app_id` |
| Zhipu | `glm-image`, `cogview-4-250304`, `cogview-4`, `cogview-3-flash` |
| xAI | `grok-imagine-image-quality`, `grok-imagine-image-2.0` |
| SiliconFlow | `Kwai-Kolors/Kolors`, `Qwen/Qwen-Image-Edit`, `Qwen/Qwen-Image-Edit-2509` |
| StepFun | `step-1x-medium` |
| Volcengine Ark (Doubao) | `doubao-seedream-5.0-lite`, `doubao-seedream-4.5`, `doubao-seedream-4.0`, `doubao-seedream-3.0-t2i` |
| Qwen (Wanx) | asynchronous — see below |

```php
$ai->images();  // sub-facade
(new \Ai\Protocol\Zhipu())->knownImageModels();   // a protocol's image model list
```

> Whether a given platform has actually enabled the endpoint is per their documentation. When it has not, you receive that platform's own 404 rather than the library's guess.

#### Asynchronous image generation (Qwen Wanx)

Qwen's Wanx text-to-image is **submit a task, then poll** — one request does not produce an image. On such platforms `generate()` raises a clear error pointing at `generateAsync()`. Returning a "successful but imageless" response is the worst outcome: the caller gets nothing and has no idea where to look.

```php
$ai = AI::create(['protocol' => 'qwen', 'model' => 'wan2.2-t2i-flash', 'api_key' => '...']);

$task = $ai->images()->generateAsync('A cat reading a book', ['size' => '1024x1024', 'n' => 2]);
$db->save(['task' => json_encode($task->toArray())]);

// … later
$task = AsyncTask::fromArray(json_decode($row['task'], true), $ai);
if ($task->refresh()->isSucceeded()) {
    $task->getResult()->saveTo('/var/www/uploads');
}
```

It shares the same `AsyncTask` as video generation: non-blocking, restorable across requests, and a timeout is not a failure.

> The library converts the unified `size: "1024x1024"` into the `"1024*1024"` (asterisk) form Wanx requires. Getting the separator wrong is not tolerated — the platform rejects it as an invalid parameter.

#### Image editing (image-to-image / inpainting)

```php
// Rewrite the whole image
$ai->images()->edit('/path/cat.png', 'Replace the background with a starry sky')->saveTo('/var/www/uploads');

// Inpainting: only the masked area changes
$ai->images()->edit('/path/cat.png', 'Remove this hand', ['mask' => '/path/mask.png']);
```

This uploads via multipart and is **not the same endpoint** as text-to-image. Only **local files** are accepted — fetch remote images with `Ai\Helpers\Media::download()` first. The library will not download for you, because that requires SSRF protection which does not belong in upload logic.

Supported: OpenAI, StepFun, xAI, Zhipu.
**SiliconFlow is not supported** (probing found no `/images/edits` route; it folds image-to-image into `images/generations`, distinguished by an `image` parameter). **Qwen is not supported either** (its compatibility mode returns 404).

### Speech synthesis and recognition

```php
// Text → audio
$ai = new AI(['api_key' => '...', 'model' => 'gpt-4o-mini-tts']);
$ai->audio()->speech('Hello world')->saveTo('/tmp/hello.mp3');

// With parameters
$audio = $ai->audio()->speech('Hello world', [
    'voice'  => 'sage',    // voice
    'format' => 'wav',     // the library standardises on `format`; platforms name it differently
    'speed'  => 1.2,
]);
$audio->getBytes();    // raw audio bytes
$audio->getFormat();   // 'wav'
$audio->getSize();     // byte count

// Audio → text
$text = $ai->audio()->transcribe('/tmp/record.wav', ['language' => 'en'])->getText();
```

#### Two completely different response shapes, normalised

| Platform | What it actually returns |
|----------|--------------------------|
| OpenAI / SiliconFlow / StepFun / Zhipu | **binary audio bytes** (`Content-Type: audio/*`) |
| MiniMax | **JSON** with the audio in `data.audio`, **hex-encoded** (not base64) |

Callers write `speech()->saveTo()` in both cases and get a playable file.

**Two silent traps live here, and the library blocks both:**

1. **On failure the platform returns JSON, not audio.** Without checking, you write a pile of `.mp3` files whose contents are error messages, with no error anywhere. The library inspects the response's actual `Content-Type`, treats JSON as an error, and makes `saveTo()` throw instead of writing a broken file.
2. **MiniMax's hex is not base64.** Both are printable characters, so `base64_decode` raises no error — it simply produces a file that will not play. Its failures also do not show up in the HTTP status: `base_resp.status_code` must be 0, and HTTP is still 200 otherwise. The library checks that field.

#### Default voice

OpenAI's `voice` parameter is **mandatory** — omitting it is an immediate 400. The library supplies a default so `speech('Hello')` works out of the box; pass your own and yours wins.

```php
(new \Ai\Protocol\OpenAI())->knownVoices();
// ['alloy','ash','ballad','coral','echo','sage','shimmer','verse','marin','cedar']
```

> This list comes from OpenAI's official OpenAPI specification (2026-08) and **differs from older documentation**: `fable` / `nova` / `onyx` are no longer in the enum, and `marin` / `cedar` were added. Writing a voice name from memory earns a 400.

#### Speech recognition uploads via multipart

`transcribe()` accepts **local files only** (a path string or an `AIFile` instance). Fetch remote audio with `Ai\Helpers\Media::download()` first — the library will not download for you, because that requires SSRF protection which does not belong in upload logic.

#### Supported platforms and models

Model lists come from each platform's official documentation:

| Platform | TTS models | ASR |
|----------|-----------|-----|
| OpenAI | `gpt-4o-mini-tts`, `tts-1-hd`, `tts-1` | `gpt-4o-transcribe`, `whisper-1` … |
| SiliconFlow | `FunAudioLLM/CosyVoice2-0.5B`, `fnlp/MOSS-TTSD-v0.5` | `FunAudioLLM/SenseVoiceSmall`, `TeleAI/TeleSpeechASR` |
| StepFun | `step-tts-mini`, `step-tts-2`, `step-tts-vivid` | ✅ |
| Zhipu | `glm-tts` | `glm-asr-2512` |
| MiniMax | `speech-2.8-hd`, `speech-2.8-turbo` and six more | ✖ (different shape, not integrated) |
| Cohere | ✖ | `cohere-transcribe-03-2026` (requires an explicit `language`) |
| Mistral / Groq / Together / Fireworks / OpenRouter / DeepInfra | ✅ | ✅ |

**Qwen is not supported**: its OpenAI compatibility mode has no audio endpoints (verified 404), and the native DashScope audio API differs too much to integrate so far.

**iFlytek only offers WebSocket** — use `$ai->realtime()`.

### WebSocket realtime channel

iFlytek's speech capabilities are **WebSocket-only**, with no HTTP equivalent. The library integrates them so you do not have to write a WebSocket client for a single platform.

```php
$ai = AI::create([
    'protocol' => 'spark',
    'app_id'   => '<APPID from the console>',
    'api_key'  => '<APIKey>:<APISecret>',   // joined with a colon
]);

// The WebSocket channel must be enabled explicitly
$ai->realtime()->useWebSocket()->speech('Hello world')->saveTo('/tmp/hello.mp3');

$text = $ai->realtime()->useWebSocket()->transcribe('/tmp/record.wav')->getText();
```

#### Why `useWebSocket()` must be explicit

The channel defaults to `null` and no connection is made until you enable it. This is not pedantry: WebSocket is a long-lived connection whose timeout semantics and failure modes differ from ordinary HTTP, and **even after a successful handshake** a frame-format problem can hang it silently (the server neither replies nor sends a close). That behavioural difference should not happen without your knowledge.

Calling without enabling gives a precise message rather than a vague failure:

```
No realtime channel protocol specified. This platform's speech capabilities are only
reachable over WebSocket — call ->useWebSocket() explicitly to enable it. …
```

#### Zero new dependencies

The RFC 6455 client is pure PHP, using only the **core functions** `stream_socket_client`, `stream_socket_enable_crypto`, `random_bytes` and `pack`. It needs neither `ext-sockets` nor any Composer package.

`wss://` requires `ext-openssl` (present almost everywhere). When missing you get an explicit message rather than an obscure connection error. It is declared under `suggest` in `composer.json`.

#### Credentials differ from the HTTP APIs

iFlytek speech authentication **does not use headers**: an HMAC-SHA256 signature computed from APIKey/APISecret is appended to the URL query string. The signature carries a timestamp, is short-lived, and **must be recomputed for every connection** — the URL cannot be cached. A third credential, `app_id`, is required alongside APIKey/APISecret. The library handles all of this; you only supply the three values.

#### Speech recognition wants raw PCM

iFlytek dictation accepts raw 16 kHz / 16-bit / mono PCM. When you pass a `.wav` the library strips the header and extracts the `data` chunk — feeding a whole wav file in makes those header bytes be interpreted as samples, producing a burst of noise at the start or odd recognition results, **with no error**, and the file format is the last place anyone looks.

Convert other container formats (mp3, m4a …) yourself before uploading. This library does no transcoding, since that would require an external dependency such as ffmpeg.

#### iFlytek has no HTTP speech endpoint

On the Spark protocol, `$ai->audio()->speech()` reports "not supported" and lists the protocol's actual capabilities (including "realtime channel") in the error message, pointing you at `$ai->realtime()`. That is better than letting the request hit a non-existent HTTP path and returning a vague 404.

### Video generation / async tasks

Video APIs are **always** task-based, so `generate()` returns a task, not a video.

```php
// In a web request: submit, persist, done — no blocking
$task = $ai->video()->generate('Sunset over the sea', ['duration' => 5, 'ratio' => '16:9']);
$db->save(['task' => json_encode($task->toArray())]);

// In a cron job or queue worker: restore and poll
$task = AsyncTask::fromArray(json_decode($row['task'], true), $ai);
if ($task->refresh()->isSucceeded()) {
    $task->getResult()->saveTo('/var/www/videos/x.mp4');
}
```

#### Why not just wait for the result

Video generation takes minutes. `wait()` exists, but **do not call it in a web request** — it occupies a PHP-FPM worker and the whole site falls over under concurrency. That method is for CLI scripts and queue workers.

```php
// CLI / worker only
$task->wait(300, 3);   // wait up to 300s, starting at 3s with exponential backoff
```

#### A timeout is not a failure

`wait()` **raises no exception** on timeout. The task is still running on the platform, and throwing would lead you to `catch` it and treat it as failed, discarding a generation you already paid for. After a timeout:

```php
$task->isTimeout();   // true
$task->isDone();      // false — so `if ($task->isDone())` is inherently safe
$task->isFailed();    // false — never mistaken for a failure
$task->getMessage();  // "Still processing on the platform… save task_id 'xxx' and poll again later"
```

#### Four platforms' status values, normalised

| Platform | Status field | Values |
|----------|--------------|--------|
| Qwen Wanx | `task_status` | PENDING / RUNNING / SUCCEEDED / FAILED / CANCELED |
| Zhipu CogVideoX | `task_status` | PROCESSING / SUCCESS / FAIL |
| Volcengine Ark Seedance | `status` | queued / running / succeeded / failed |
| MiniMax Hailuo | `status` | Preparing / Queueing / Processing / Success / Fail |
| Gemini Veo | `status` | queued / in_progress / completed / failed |

The library normalises these to `pending` / `running` / `succeeded` / `failed` / `timeout`. **When a platform introduces a status the library has never seen, it is treated as "running", not "failed"** — turning every user's task into a failure because a vendor added a status value is the worst possible degradation.

#### MiniMax takes three steps

The others are "submit → poll"; MiniMax adds one more:

```
submit  POST /v1/video_generation           → task_id
poll    GET  /v1/query/video_generation     → status=Success, but only a file_id
fetch   GET  /v1/files/retrieve             → the real download URL (valid 9 hours)
```

The library walks all three transparently. MiniMax failures also **do not appear in the HTTP status**: `base_resp.status_code` must be 0, and HTTP stays 200 otherwise. The library checks that field.

Gemini's Veo is Sora-compatible and also takes three steps, but the third one returns **bytes rather than a URL** (`GET /videos/{id}/content`, authenticated). `VideoResponse::saveTo()` writes those bytes directly.

#### Supported platforms and models

| Platform | Models (from official documentation) |
|----------|--------------------------------------|
| Qwen Wanx | `wan2.7-t2v`, `wan2.7-t2v-2026-06-12` |
| Zhipu | `cogvideox-3`, `cogvideox-2`, `cogvideox-flash`, `viduq1-*`, `vidu2-*` |
| Volcengine Ark | Seedance series |
| MiniMax | `MiniMax-Hailuo-2.3`, `MiniMax-Hailuo-02`, `T2V-01-Director`, `T2V-01` |
| Gemini | `veo-3.1-generate-preview`, `veo-3.1-lite-generate-preview`, `gemini-omni-flash` |
| Z.ai | video generation (per z.ai documentation) |

⚠️ **Result URLs expire** (roughly 24 hours for Wanx, only 9 hours for MiniMax). Storing the URL is not enough — call `saveTo()` promptly.

### Save media results promptly

Most platforms' image and video URLs are **valid for a few hours up to 24 hours**. Store only the URL and everything breaks the next day. Fetch them with `saveTo()`:

```php
$img->saveTo('/var/www/uploads');          // images; returns an array of paths
$audio->saveTo('/tmp/hello.mp3');          // audio
$video->saveTo('/var/www/v.mp4');          // video; 64 MB cap by default
```

Downloads use the library's SSRF-protected fetcher (IP pinning, per-hop revalidation), not a bare `file_get_contents()`. The target directory **must already exist** — the library does not create it, so a mistyped path cannot scatter empty directories across your disk.

### The WebSocket channel is off by default

iFlytek and similar platforms offer speech only over WebSocket. The library integrates it, but you **must enable it explicitly**, because WS is a long-lived connection whose timeout and error semantics differ from ordinary HTTP:

```php
$ai->realtime()->useWebSocket()->speech('Hello world');
```

Calling without enabling gives a precise message rather than a vague connection failure.

### Custom gateways

When `base_url` points at a self-hosted gateway or relay, the image and audio endpoints **follow the same gateway automatically** and never fall back to the official address (which would send your data to a server you never specified). To override one capability's full address, use the `<capability>_endpoint` config key:

```php
$ai = new AI([
    'api_key'        => '...',
    'base_url'       => 'https://my-gateway.com/v1',
    'image_endpoint' => 'https://another-host.com/v1/images/generations',  // optional
]);
```

This key is also the **escape hatch** when the library's capability judgement is wrong or out of date: configuring `<capability>_endpoint` bypasses the declaration check entirely. If the protocol family has no parser for that response shape (Claude for images, say), you still get a clear error explaining exactly which layer refused and suggesting `protocol` + `base_url` instead.

### Migration note for custom protocol classes

`ProtocolInterface` gained four capability methods in v1.14.0.

- **If you extend a built-in protocol (`extends OpenAI`, `extends Claude` …): nothing to do.** That is the style the "Extending the library" section teaches, and all 38 vendor protocol classes in this library use it.
- **If you implement the interface directly (`implements ProtocolInterface`): add one line.**

```php
class MyProtocol implements ProtocolInterface
{
    use \Ai\Protocol\Concerns\CapabilityDefaults;   // ← this line is all it takes
    // … your existing six methods stay exactly as they are …
}
```

---

## Known limitations

- Conversation history lives in memory and is lost when the process exits; persist it across requests yourself with `exportHistory()` / `importHistory()`.
- Streaming tool calls are supported (fragments are reassembled automatically). If the model says it wants a tool this turn but nothing reassembles — meaning the platform uses a fragment structure this library does not yet cover — a `stream_tool_calls_unassembled` exception is raised rather than silently returning an empty response.
- Streaming extracts body text only. A reasoning model's chain of thought (`reasoning_content` / `thinking` blocks) is not counted in `getContent()`; read it from the `raw` field of the `stream_chunk` event if you need it.
- Each platform's `knownModels()` list is a static snapshot maintained in the library, used for offline dropdowns and as a fallback when fetching fails. For the current set of models, rely on `listModels()`.
- Azure OpenAI covers only the newer `/openai/v1` routing; the older "deployment name + api-version" routing needs a full URL via `endpoint`. AWS Bedrock and Google Vertex AI are not built in, as they require SigV4 / OAuth signing.
- A custom model's `supports()` returns optimistic defaults (the library cannot know what an arbitrary endpoint implements). Declare the truth with the `features` config key when it matters.
- `Ai\Protocol\Gemini::convertMessages()` is never called — Gemini uses the OpenAI-compatible endpoint and messages pass through unchanged.
- `chatBatch()` supports neither streaming nor `setAttachments()` (put attachments in each item's own payload).
- `cost()` requires you to supply the price table; the library ships no prices, because they change often and any built-in table would be stale.
- `Ai\Cli\ClaudeCode` requires the claude binary on the machine. Restricted PHP environments where `proc_open` / `shell_exec` are disabled need a custom runner (SSH/SFTP, for example).
- Video generation covers **text-to-video and first-frame image-to-video** only.
- Image editing covers platforms that expose `/images/edits`. SiliconFlow's and Qwen's image-to-image use different shapes and are not integrated.
- `wait()` blocks and suits only CLI and queue workers. In web requests use "submit, persist, poll from a cron job".
- The WebSocket channel implements one mode only — a single session, send everything, receive everything, close. That is enough for iFlytek TTS/ASR. Concurrent connections, automatic reconnection, server mode and the permessage-deflate extension are not supported.
- MiniMax speech recognition differs too much from OpenAI's shape and is not integrated.
- Embeddings do not batch by default. Pass `batch_size` when you exceed a platform's per-request cap — the caps vary widely and are not always documented, and defaulting to a "safe small value" would make platforms that could send everything at once issue dozens of pointless requests.
- Volcengine Ark's video model list and StepFun's ASR/voice list are **deliberately empty**: their documentation sites are JavaScript-rendered and could not be verified. An empty list falls back to the platform's own model-list endpoint, whereas a wrong list would hand users model names that do not exist.

---

## License

MIT License
