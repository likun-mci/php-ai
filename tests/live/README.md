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
```

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
