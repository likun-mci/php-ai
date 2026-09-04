<?php
namespace Ai\Agent\Registry;

/**
 * Tool Registry 相关异常
 *
 * SQLite 建表失败、PDO 扩展缺失、写入冲突等一律包成它，调用方只需 catch 一个类型。
 */
class RegistryException extends \RuntimeException
{
}
