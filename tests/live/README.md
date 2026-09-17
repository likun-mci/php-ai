# 实网络测试

这里的用例会**真的发请求**，因此：

- **不进 `composer test`**，`composer check` 不会跑到它们
- 没有密钥文件时**自动跳过**，退出码仍为 0
- 密钥从下面两个文件读，仓库里不含它们（`.claude/` 已在 `.gitignore`）

```
.claude/secrets/scnet.key     SCNet 密钥
.claude/secrets/gemini.key    Gemini 密钥
```

运行：

```bash
php tests/live/multimodal_live_test.php
php tests/live/citations_live_test.php [平台名 ...]   # 联网搜索的来源与引用
```

`citations_live_test.php` 按平台读 `.claude/secrets/<平台>.key`
（claude / openai / xai / openrouter / perplexity / zhipu / ernie / qwen / gemini），缺哪个跳哪个；
中国大陆以外的平台走 `LIVE_PROXY`（默认 `http://127.0.0.1:8620`）。
没有原厂 key 的平台可经 OpenRouter 测：`LIVE_OPENROUTER_MODELS=x-ai/grok-4.3,perplexity/sonar`；
原始响应写到 `LIVE_DUMP_DIR`（默认系统临时目录），方便对照平台真实结构。

## 环境依赖

| 平台 | 端点 | 备注 |
|---|---|---|
| SCNet | `https://api.scnet.cn/api/llm/v1` | OpenAI 兼容。模型 `GLM-5-Base` 是**推理模型**，正文在推理结束后才出，`max_tokens` 给小了会只拿到空串 |
| Gemini | 官方端点 | 需要 HTTP 代理，默认 `http://127.0.0.1:8993`，可用 `GEMINI_PROXY` 环境变量覆盖 |

## 已实测的事实

- `GLM-5-Base` 收到 `image_url` 直接返回 **HTTP 510 `Model Request Error`**，
  是纯文本模型。这条已登记进 `CapabilityRegistry`（只登记 `glm-5-base*`，
  不推广到 `glm-5*`——同代可能有带 V 的多模态型号）。
- `gemini-2.0-flash` 在该 key 下 404；`gemini-2.5-flash` 可用且快；
  `gemini-2.5-pro` 也是推理模型，`max_tokens` 要给够。
- Gemini 原生 `generateContent` + `google_search`（`gemini-2.5-flash`，2026-09-17）：
  `groundingSupports.segment` 的 `startIndex` / `endIndex` 确为 **UTF-8 字节偏移**——
  中文回答里按字节换算成字符后截出的文字与 `segment.text` 逐字一致（非流式 3 条、流式 11 条）。
  流式下 grounding 只出现在一帧里，偏移相对于累积后的完整正文。
- OpenRouter web 插件（2026-09-17，DeepSeek / Grok / Perplexity / Qwen / GLM / Kimi）：
  - 大多数模型的 `annotations` 区间是 **0-0**，出处以 `[域名](url)` 写在正文里；
    经 OpenRouter 的 `x-ai/grok-4.3` 给的是有效区间，实测为**字符偏移、end 不含**（中文正文对照确认）。
  - 转发 `perplexity/sonar` 时，annotations 是**全部 20 条检索结果**，正文只引用其中两三条。
  - 流式下 annotations 在 `choices[0].delta.annotations` 里逐条下发（OpenRouter 文档未写，实测确认）。
  - 本机 key 调 `anthropic/*`、`openai/*`、`google/*` 一律 403「violation of provider Terms Of Service」，
    连纯文本 `hi` 也一样，与代理无关——这三家经 OpenRouter 测不了。
- Perplexity `sonar` 原生：`search_results` 与 `citations` 同序，正文 `[n]` 角标 1 起对应；流式每帧都带完整
  `search_results`，不发 `[DONE]`。经代理时偶发流中途断开，库会返回截断的正文而不报错（传输层现有行为）。
- 智谱 GLM（2026-09-17，glm-4-flash / glm-4.6 / glm-4-plus）：
  - 顶层 `web_search[]` 条目字段为 `content / icon / link / media / publish_date / refer / title`，`refer` 为 `ref_1` 起。
  - 流式下 `web_search` 出现在**倒数第二帧**（与正文增量同帧），文档未写，实测确认。
  - 默认 `search_prompt` 下正文**不带角标**；自定义 `search_prompt` 要求 `[ref_1]` 标注时，
    正文形如 `…举行 [ref_1][ref_4]。`，库解析出的角标与 `refer` 全部对应。
  - 默认 `search_std` 引擎搜到的多为旧资料，时效性问题可改 `search_pro`。
