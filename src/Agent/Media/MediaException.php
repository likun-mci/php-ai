<?php
namespace Ai\Agent\Media;

/**
 * 媒体处理异常
 *
 * 附件校验失败、存储读写失败、体积超限、SSRF 拦截等一律包成它，
 * 调用方只需 catch 一个类型。
 */
class MediaException extends \RuntimeException
{
}
