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
    
    /**
     * @param string          $message     错误信息
     * @param string          $platform    平台标识
     * @param string          $errorCode   平台错误码或 HTTP 状态码
     * @param array           $rawResponse 平台原始错误响应
     * @param \Throwable|null $previous    原始异常。库内部把 Error 类异常
     *                                     （TypeError 等）包装成 AIException 时会带上，
     *                                     这样调用方拿到统一错误类型的同时，
     *                                     用 getPrevious() 仍能拿到完整堆栈定位真正的 bug
     */
    public function __construct(
        string $message,
        string $platform = '',
        string $errorCode = '',
        array $rawResponse = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
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
