# AI 标准库架构文档 - 流式输出设计

## 问题背景

不同 AI 平台的流式输出格式存在显著差异：

### OpenAI 流式格式
```json
data: {"id":"chatcmpl-xxx","object":"chat.completion.chunk","created":1234567890,"model":"gpt-4","choices":[{"index":0,"delta":{"content":"Hello"},"finish_reason":null}]}
data: {"choices":[{"delta":{"content":" World"}}]}
data: [DONE]
```

**特点**：
- SSE (Server-Sent Events) 格式
- 内容路径：`choices[0].delta.content`
- 结束标记：`[DONE]` 或 `finish_reason` 不为 null

### Claude (Anthropic) 流式格式
```
event: message_start
data: {"type":"message_start","message":{...}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hello"}}

event: content_block_delta
data: {"type":"content_block_delta","delta":{"text":" World"}}

event: message_stop
data: {"type":"message_stop"}
```

**特点**：
- 事件驱动格式
- 内容路径：`delta.text`（在 `content_block_delta` 事件中）
- 结束标记：`message_stop` 事件

### Gemini 流式格式
```json
{"candidates":[{"content":{"parts":[{"text":"Hello"}],"role":"model"}}]}
{"candidates":[{"content":{"parts":[{"text":" World"}]}}]}
{"candidates":[{"content":{"parts":[{"text":"!"}]},"finishReason":"STOP"}]}
```

**特点**：
- JSON 流格式（可能不是 SSE）
- 内容路径：`candidates[0].content.parts[0].text`
- 结束标记：`finishReason` 字段存在

---

## 架构设计原则

### 分层职责

1. **传输层（Transport Layer）** - `CurlTransport`
   - 职责：接收原始 HTTP 流式数据
   - 范围：解析 SSE 格式（`data: ...`）
   - 不做：不解析具体平台的数据结构

2. **协议层（Protocol Layer）** - `ProtocolInterface` 实现
   - 职责：解析平台特定的流式数据格式
   - 方法：
     - `parseStreamChunk(array $chunk): ?string` - 提取文本内容
     - `isStreamEnd(array $chunk): bool` - 判断是否结束
   - 优势：每个平台独立实现，互不干扰

3. **业务层（Application Layer）** - `AI` 主类
   - 职责：协调流式输出流程
   - 功能：
     - 自动启用 `stream: true`
     - 调用协议层解析内容
     - 累积完整文本
     - 触发用户回调

---

## 实现细节

### 1. 协议接口扩展

```php
interface ProtocolInterface
{
    /**
     * 解析流式数据块，提取内容
     * @param array $chunk 流式数据块
     * @return string|null 提取的文本内容，无内容返回 null
     */
    public function parseStreamChunk(array $chunk): ?string;
    
    /**
     * 判断流式数据是否结束
     * @param array $chunk 流式数据块
     * @return bool 是否为结束标记
     */
    public function isStreamEnd(array $chunk): bool;
}
```

### 2. OpenAI 协议实现

```php
class OpenAI implements ProtocolInterface
{
    public function parseStreamChunk(array $chunk): ?string
    {
        // OpenAI: choices[0].delta.content
        if (isset($chunk['choices'][0]['delta']['content'])) {
            return $chunk['choices'][0]['delta']['content'];
        }
        return null;
    }
    
    public function isStreamEnd(array $chunk): bool
    {
        // finish_reason 不为 null 表示结束
        return isset($chunk['choices'][0]['finish_reason']) 
            && $chunk['choices'][0]['finish_reason'] !== null;
    }
}
```

### 3. Claude 协议实现

```php
class Claude implements ProtocolInterface
{
    public function parseStreamChunk(array $chunk): ?string
    {
        // Claude: 多种事件类型
        if (isset($chunk['type'])) {
            // content_block_delta 事件包含文本
            if ($chunk['type'] === 'content_block_delta' && isset($chunk['delta']['text'])) {
                return $chunk['delta']['text'];
            }
        }
        return null;
    }
    
    public function isStreamEnd(array $chunk): bool
    {
        // message_stop 事件表示结束
        return isset($chunk['type']) && $chunk['type'] === 'message_stop';
    }
}
```

### 4. Gemini 协议实现

```php
class Gemini implements ProtocolInterface
{
    public function parseStreamChunk(array $chunk): ?string
    {
        // Gemini: candidates[0].content.parts[].text
        if (isset($chunk['candidates'][0]['content']['parts'])) {
            $parts = $chunk['candidates'][0]['content']['parts'];
            $text = '';
            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    $text .= $part['text'];
                }
            }
            return $text !== '' ? $text : null;
        }
        return null;
    }
    
    public function isStreamEnd(array $chunk): bool
    {
        // finishReason 字段存在表示结束
        return isset($chunk['candidates'][0]['finishReason']);
    }
}
```

### 5. 传输层改进

```php
class CurlTransport
{
    protected function handleStreamData(string $data): int
    {
        // ... 解析 SSE 格式 ...
        
        if (strpos($line, 'data: ') === 0) {
            $jsonData = substr($line, 6);
            $decoded = json_decode($jsonData, true);
            
            if ($decoded !== null && $this->streamCallback) {
                // 只传递原始数据，不提取内容
                call_user_func($this->streamCallback, $decoded);
            }
        }
    }
}
```

### 6. 业务层协调

```php
class AI
{
    public function chat(array $payload = []): AIResponseInterface
    {
        if ($this->streamCallback !== null) {
            $payload['stream'] = true;
            
            $this->transport->setStreamCallback(function($data) {
                // 使用协议层解析（支持不同平台）
                $content = $this->protocol->parseStreamChunk($data);
                
                // 累积内容
                if ($content !== null) {
                    $this->streamAccumulatedContent .= $content;
                }
                
                // 调用用户回调
                call_user_func($this->streamCallback, $data);
            });
        }
        
        // ... 发送请求 ...
    }
}
```

---

## 数据流转

```
┌─────────────────┐
│  AI Platform    │ (OpenAI/Claude/Gemini)
└────────┬────────┘
         │ HTTP Stream
         ▼
┌─────────────────┐
│ CurlTransport   │ ← 传输层：解析 SSE 格式
│ handleStreamData│
└────────┬────────┘
         │ Raw JSON Data
         ▼
┌─────────────────┐
│ Protocol Layer  │ ← 协议层：提取文本内容
│ parseStreamChunk│   (平台特定逻辑)
└────────┬────────┘
         │ Text Content
         ▼
┌─────────────────┐
│ AI Class        │ ← 业务层：累积内容 + 用户回调
│ chat()          │
└────────┬────────┘
         │
         ├──→ User Callback (实时数据)
         │
         └──→ AIResponse (完整结果)
```

---

## 优势

### ✅ 符合单一职责原则
- 传输层：只管 HTTP 和 SSE
- 协议层：只管平台格式
- 业务层：只管流程控制

### ✅ 开放封闭原则
- 添加新平台：只需实现协议接口
- 不影响现有代码

### ✅ 易于测试
- 每层可独立测试
- Mock 接口即可

### ✅ 可扩展性强
- 支持任意平台的流式格式
- 统一的用户接口

---

## 使用示例

```php
// 用户代码完全相同，自动适配不同平台
$ai = AI::create([
    'api_key' => 'xxx',
    'model' => 'gpt-4o', // 或 claude-3-opus, gemini-pro
]);

$ai->setStreamCallback(function($data) {
    // 接收原始数据，也可以用协议层解析
    echo "."; // 简单处理
});

$response = $ai->chat([
    'messages' => [['role' => 'user', 'content' => 'Hello']]
]);

echo $response->getContent(); // 完整内容
```

不同平台的流式数据会被协议层自动解析，用户无需关心差异！

---

## 总结

通过在**协议层**处理流式格式差异，实现了：
1. 传输层通用化
2. 协议层平台化
3. 业务层统一化

这是经典的**适配器模式**应用，完美解决了多平台流式输出的兼容性问题。
