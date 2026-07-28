<?php
namespace Ai\Exceptions;

use Exception;

/**
 * AI 异常基类
 */
class AIException extends Exception
{
    protected $platform;
    protected $errorCode;
    protected $rawResponse;
    
    public function __construct(string $message, string $platform = '', string $errorCode = '', array $rawResponse = [])
    {
        parent::__construct($message);
        $this->platform = $platform;
        $this->errorCode = $errorCode;
        $this->rawResponse = $rawResponse;
    }
    
    /**
     * 获取平台名称
     */
    public function getPlatform(): string
    {
        return $this->platform;
    }
    
    /**
     * 获取错误代码
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
    
    /**
     * 获取原始响应
     */
    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }
}
