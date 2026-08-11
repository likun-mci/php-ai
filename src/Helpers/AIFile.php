<?php
namespace Ai\Helpers;

/**
 * AI 文件辅助类
 */
class AIFile
{
    /**
     * @var string
     */
    protected $type; // 'path' or 'url'
    /**
     * @var string
     */
    protected $source;
    /**
     * @var string
     */
    protected $mimeType;
    
    protected function __construct(string $type, string $source, string $mimeType = '')
    {
        $this->type = $type;
        $this->source = $source;
        $this->mimeType = $mimeType;
    }
    
    /**
     * 从文件路径创建
     */
    public static function fromPath(string $path, string $mimeType = ''): self
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }
        
        if (empty($mimeType)) {
            $mimeType = mime_content_type($path) ?: 'application/octet-stream';
        }
        
        return new self('path', $path, $mimeType);
    }
    
    /**
     * 从 URL 创建
     */
    public static function fromUrl(string $url, string $mimeType = ''): self
    {
        return new self('url', $url, $mimeType);
    }
    
    /**
     * 获取类型
     */
    public function getType(): string
    {
        return $this->type;
    }
    
    /**
     * 获取源
     */
    public function getSource(): string
    {
        return $this->source;
    }
    
    /**
     * 获取 MIME 类型
     */
    public function getMimeType(): string
    {
        return $this->mimeType;
    }
    
    /**
     * 读取文件内容（Base64 编码）
     */
    public function getBase64Content(): string
    {
        if ($this->type === 'path') {
            // file_get_contents 读不到时返回 false，直接喂给 base64_encode()
            // 在 PHP 8 上是 TypeError，在 PHP 7 上则会静默编码成空串——
            // 两种都不该发生，这里显式判定并记录原因
            $raw = @file_get_contents($this->source);
            if ($raw === false) {
                \Ai\Helpers\Log::error('附件文件读取失败，本次附件将被忽略', [
                    'source' => $this->source,
                ]);
                return '';
            }
            return base64_encode($raw);
        }
        return '';
    }
    
    /**
     * 转为数组
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'source' => $this->source,
            'mime_type' => $this->mimeType,
        ];
    }
}
