<?php
namespace Ai\Helpers;

/**
 * AI 文件辅助类
 */
class AIFile
{
    protected $type; // 'path' or 'url'
    protected $source;
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
            return base64_encode(file_get_contents($this->source));
        }
        return '';
    }
    
    /**
     * 转为数组
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
