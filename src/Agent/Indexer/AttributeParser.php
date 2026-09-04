<?php
namespace Ai\Agent\Indexer;

use Ai\Agent\Attribute\AgentTool;

/**
 * `#[AgentTool]` Attribute 解析器
 *
 * 只在 PHP 8+ 上真正工作：`ReflectionMethod::getAttributes()` 是 8.0 才有的方法，
 * 因此这里 `method_exists()` 探测后再用，7.1 上直接返回空数组，整条链回退到 PHPDoc。
 *
 * 优先级见规范 §6：Attribute > PHPDoc > Reflection 推断。本类只负责产出
 * 「Attribute 显式给过的字段」，合并由 ToolIndexer 完成。
 */
class AttributeParser
{
    /**
     * 当前 PHP 是否支持 Attribute 解析
     *
     * @return bool
     */
    public static function supported()
    {
        return PHP_VERSION_ID >= 80000 && method_exists('ReflectionMethod', 'getAttributes');
    }

    /**
     * 从方法上读取 #[AgentTool]
     *
     * @param \ReflectionMethod $method
     * @return array<string, mixed> 显式给过的字段；没有 Attribute 时为空数组
     */
    public function parse(\ReflectionMethod $method)
    {
        if (!self::supported()) {
            return [];
        }

        // 用变量方法名调用，避免 PHP 7 的静态分析/解析阶段接触到 8.0 API
        $getAttributes = 'getAttributes';
        $attrs = $method->$getAttributes(AgentTool::class);
        if (!is_array($attrs) || $attrs === []) {
            return [];
        }

        $attr = $attrs[0];
        if (!is_object($attr)) {
            return [];
        }

        // newInstance() 会执行 AgentTool 的构造函数；参数写错时抛 Error，
        // 这里兜住并退回 arguments 原始数组，避免一个写错的标注让整次扫描失败
        try {
            if (method_exists($attr, 'newInstance')) {
                $instance = $attr->newInstance();
                if ($instance instanceof AgentTool) {
                    return $instance->toFields();
                }
            }
        } catch (\Throwable $e) {
            // 落到下面的原始参数分支
        }

        if (method_exists($attr, 'getArguments')) {
            $args = $attr->getArguments();
            if (is_array($args)) {
                return $this->fieldsFromArguments($args);
            }
        }

        return [];
    }

    /**
     * newInstance() 失败时的兜底：直接读 Attribute 的原始参数
     *
     * 只认具名参数（`name:` / `description:` …），位置参数无从判断含义。
     *
     * @param array<string|int, mixed> $args
     * @return array<string, mixed>
     */
    protected function fieldsFromArguments(array $args)
    {
        $map = [
            'name'        => 'name',
            'description' => 'description',
            'controller'  => 'controller_path',
            'risk'        => 'risk',
            'confirm'     => 'requires_confirmation',
            'permission'  => 'permissions',
            'keywords'    => 'keywords',
            'enabled'     => 'enabled',
            'version'     => 'version',
        ];
        $out = [];
        foreach ($args as $k => $v) {
            if (is_string($k) && isset($map[$k])) {
                $out[$map[$k]] = $v;
            }
        }
        return $out;
    }
}
