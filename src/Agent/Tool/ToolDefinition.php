<?php
namespace Ai\Agent\Tool;

/**
 * Agent Tool 定义（值对象）
 *
 * 一条「应用业务能力」的完整描述：模型看到的名字与说明、映射到的 Controller
 * 入口路径、风险等级、参数 Schema，以及来源文件/行号/hash（增量扫描用）。
 *
 * 它是 Indexer 的产出、Registry 的存储单元、Executor 的输入，三方共用一个结构。
 *
 * 关键约定：
 *   - `controllerPath` 是**权限与执行的唯一入口**。没有它的 Tool 不允许执行——
 *     本方案不做「拿 className/methodName 直接反射调用」这件事（规范 §31.6）。
 *   - `permissions` 只用于 Discovery 阶段的候选过滤，**不是**授权依据；
 *     真正的权限判定发生在应用现有的 Controller 入口校验里（规范 §14）。
 *
 * 用法：
 * ```php
 * $tool = new ToolDefinition([
 *     'name'            => 'article.update',
 *     'description'     => '修改指定文章的标题和正文',
 *     'controller_path' => 'article/update',
 *     'risk'            => ToolDefinition::RISK_MEDIUM,
 *     'parameters'      => [new ToolParameter([...])],
 * ]);
 * $tool->schema();        // JSON Schema（object）
 * $tool->toModelTool();   // ['name','description','input_schema']
 * ```
 *
 * 注：不用类型化属性，保持 PHP 7.1 兼容（库的版本下限）。
 */
class ToolDefinition
{
    const RISK_LOW      = 'low';
    const RISK_MEDIUM   = 'medium';
    const RISK_HIGH     = 'high';
    const RISK_CRITICAL = 'critical';

    /** @var string[] 合法风险等级，顺序即由低到高 */
    protected static $riskLevels = [self::RISK_LOW, self::RISK_MEDIUM, self::RISK_HIGH, self::RISK_CRITICAL];

    /** @var string Tool 名（article.update），全局唯一 */
    protected $name = '';

    /** @var string 给模型看的能力描述 */
    protected $description = '';

    /** @var string 现有 Controller 入口路径（权限与执行入口） */
    protected $controllerPath = '';

    /** @var string[] 可选权限标识，仅供 Discovery 过滤 */
    protected $permissions = [];

    /** @var string 风险等级 */
    protected $risk = self::RISK_LOW;

    /** @var bool 是否需要人工确认（PHPDoc 里的默认值，可被 RiskPolicy 覆盖） */
    protected $requiresConfirmation = false;

    /** @var bool 显式写过 @agent-confirm 才为 true（否则由 risk 推导） */
    protected $confirmDeclared = false;

    /** @var bool 是否启用 */
    protected $enabled = true;

    /** @var string 来源类名（仅记录，不据此反射调用） */
    protected $className = '';

    /** @var string 来源方法名（仅记录） */
    protected $methodName = '';

    /** @var ToolParameter[] 参数（按声明顺序） */
    protected $parameters = [];

    /** @var string 返回值说明 */
    protected $returns = '';

    /** @var string[] 补充搜索词（中文同义词等） */
    protected $keywords = [];

    /** @var string Tool 版本 */
    protected $version = '';

    /** @var string 来源文件绝对路径 */
    protected $sourceFile = '';

    /** @var int 来源行号 */
    protected $sourceLine = 0;

    /** @var string 源文件内容 hash（增量扫描用） */
    protected $hash = '';

    /** @var array<string, mixed> 预留扩展 */
    protected $metadata = [];

    /** @var int */
    protected $createdAt = 0;

    /** @var int */
    protected $updatedAt = 0;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->name           = isset($data['name']) ? (string) $data['name'] : '';
        $this->description    = isset($data['description']) ? (string) $data['description'] : '';
        $this->controllerPath = isset($data['controller_path']) ? (string) $data['controller_path'] : '';
        $this->className      = isset($data['class_name']) ? (string) $data['class_name'] : '';
        $this->methodName     = isset($data['method_name']) ? (string) $data['method_name'] : '';
        $this->returns        = isset($data['returns']) ? (string) $data['returns'] : '';
        $this->version        = isset($data['version']) ? (string) $data['version'] : '';
        $this->sourceFile     = isset($data['source_file']) ? (string) $data['source_file'] : '';
        $this->sourceLine     = isset($data['source_line']) ? (int) $data['source_line'] : 0;
        $this->hash           = isset($data['hash']) ? (string) $data['hash'] : '';
        $this->enabled        = isset($data['enabled']) ? (bool) $data['enabled'] : true;
        $this->createdAt      = isset($data['created_at']) ? (int) $data['created_at'] : 0;
        $this->updatedAt      = isset($data['updated_at']) ? (int) $data['updated_at'] : 0;

        $this->risk = self::normalizeRisk(isset($data['risk']) ? $data['risk'] : null);

        if (array_key_exists('requires_confirmation', $data) && $data['requires_confirmation'] !== null) {
            $this->requiresConfirmation = (bool) $data['requires_confirmation'];
            $this->confirmDeclared      = true;
        }
        if (isset($data['confirm_declared'])) {
            $this->confirmDeclared = (bool) $data['confirm_declared'];
        }

        if (isset($data['permissions'])) {
            $this->permissions = self::toStringList($data['permissions']);
        }
        if (isset($data['keywords'])) {
            $this->keywords = self::toStringList($data['keywords']);
        }
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $this->metadata = $data['metadata'];
        }

        if (isset($data['parameters']) && is_array($data['parameters'])) {
            $this->setParameters($data['parameters']);
        }
    }

    /**
     * 把 'a,b' / ['a','b'] 统一成字符串数组
     *
     * @param mixed $value
     * @return string[]
     */
    protected static function toStringList($value)
    {
        if (is_string($value)) {
            $value = preg_split('/[,\s]+/', $value);
            if ($value === false) {
                return [];
            }
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            if (!is_string($v) && !is_numeric($v)) {
                continue;
            }
            $v = trim((string) $v);
            if ($v !== '' && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }
        return $out;
    }

    /** 非法/空的风险等级一律归一化为 low
     * @param mixed $risk
     * @return string
     */
    public static function normalizeRisk($risk)
    {
        $risk = is_string($risk) ? strtolower(trim($risk)) : '';
        return in_array($risk, self::$riskLevels, true) ? $risk : self::RISK_LOW;
    }

    /** 风险等级的数值（0=low … 3=critical），便于比较阈值
     * @param string $risk
     * @return int
     */
    public static function riskWeight($risk)
    {
        $idx = array_search(self::normalizeRisk($risk), self::$riskLevels, true);
        return $idx === false ? 0 : (int) $idx;
    }

    /** @return string[] */
    public static function riskLevels()
    {
        return self::$riskLevels;
    }

    /** @return string */
    public function getName()
    {
        return $this->name;
    }

    /** @return string */
    public function getDescription()
    {
        return $this->description;
    }

    /** @return string */
    public function getControllerPath()
    {
        return $this->controllerPath;
    }

    /** @return string[] */
    public function getPermissions()
    {
        return $this->permissions;
    }

    /** @return string */
    public function getRisk()
    {
        return $this->risk;
    }

    /** @return bool */
    public function requiresConfirmation()
    {
        return $this->requiresConfirmation;
    }

    /** 是否在源码里显式写过 @agent-confirm
     * @return bool
     */
    public function isConfirmDeclared()
    {
        return $this->confirmDeclared;
    }

    /** @return bool */
    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * @param bool $enabled
     * @return $this
     */
    public function setEnabled($enabled)
    {
        $this->enabled = (bool) $enabled;
        return $this;
    }

    /** @return string */
    public function getClassName()
    {
        return $this->className;
    }

    /** @return string */
    public function getMethodName()
    {
        return $this->methodName;
    }

    /** @return ToolParameter[] */
    public function getParameters()
    {
        return $this->parameters;
    }

    /**
     * @param string $name
     * @return ToolParameter|null
     */
    public function getParameter($name)
    {
        foreach ($this->parameters as $p) {
            if ($p->getName() === (string) $name) {
                return $p;
            }
        }
        return null;
    }

    /**
     * @param array<int, ToolParameter|array<string, mixed>> $params
     * @return $this
     */
    public function setParameters(array $params)
    {
        $this->parameters = [];
        $i = 0;
        foreach ($params as $p) {
            if (is_array($p)) {
                $p = ToolParameter::fromArray($p);
            }
            if (!$p instanceof ToolParameter) {
                continue;
            }
            if ($p->getSortOrder() === 0) {
                $p->setSortOrder($i);
            }
            $this->parameters[] = $p;
            $i++;
        }
        return $this;
    }

    /** @return string */
    public function getReturns()
    {
        return $this->returns;
    }

    /** @return string[] */
    public function getKeywords()
    {
        return $this->keywords;
    }

    /** @return string */
    public function getVersion()
    {
        return $this->version;
    }

    /** @return string */
    public function getSourceFile()
    {
        return $this->sourceFile;
    }

    /** @return int */
    public function getSourceLine()
    {
        return $this->sourceLine;
    }

    /** @return string */
    public function getHash()
    {
        return $this->hash;
    }

    /**
     * @param string $hash
     * @return $this
     */
    public function setHash($hash)
    {
        $this->hash = (string) $hash;
        return $this;
    }

    /** @return array<string, mixed> */
    public function getMetadata()
    {
        return $this->metadata;
    }

    /** @return int */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /** @return int */
    public function getUpdatedAt()
    {
        return $this->updatedAt;
    }

    /**
     * @param int $ts
     * @return $this
     */
    public function setUpdatedAt($ts)
    {
        $this->updatedAt = (int) $ts;
        return $this;
    }

    /**
     * @param int $ts
     * @return $this
     */
    public function setCreatedAt($ts)
    {
        $this->createdAt = (int) $ts;
        return $this;
    }

    /**
     * 组装完整的入参 JSON Schema
     *
     * 结构与规范 §7 的示例一致：type=object + properties + required。
     * 没有参数时 properties 用空数组（json_encode 后是 `{}`，靠 JSON_FORCE_OBJECT
     * 不可靠，所以这里在没有参数时显式给 stdClass）。
     *
     * @return array<string, mixed>
     */
    public function schema()
    {
        $properties = [];
        $required   = [];
        foreach ($this->parameters as $p) {
            $properties[$p->getName()] = $p->toSchema();
            if ($p->isRequired()) {
                $required[] = $p->getName();
            }
        }

        $schema = [
            'type'       => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    /** 转成现有 Agent 运行时能直接吃的工具定义
     * @return array<string, mixed>
     */
    public function toModelTool()
    {
        return [
            'name'         => $this->name,
            'description'  => $this->description,
            'input_schema' => $this->schema(),
        ];
    }

    /**
     * 搜索结果用的轻量摘要
     *
     * Discovery 阶段返回它而不是完整定义——目的就是让模型先看一眼候选，
     * 真要用再 get_app_tool 拉完整 Schema（规范 §12）。
     *
     * @return array<string, mixed>
     */
    public function summary()
    {
        return [
            'name'        => $this->name,
            'description' => $this->description,
            'risk'        => $this->risk,
            'controller'  => $this->controllerPath,
        ];
    }

    /** 转为可持久化数组
     * @return array<string, mixed>
     */
    public function toArray()
    {
        $params = [];
        foreach ($this->parameters as $p) {
            $params[] = $p->toArray();
        }
        return [
            'name'                  => $this->name,
            'description'           => $this->description,
            'controller_path'       => $this->controllerPath,
            'permissions'           => $this->permissions,
            'risk'                  => $this->risk,
            'requires_confirmation' => $this->requiresConfirmation,
            'confirm_declared'      => $this->confirmDeclared,
            'enabled'               => $this->enabled,
            'class_name'            => $this->className,
            'method_name'           => $this->methodName,
            'parameters'            => $params,
            'returns'               => $this->returns,
            'keywords'              => $this->keywords,
            'version'               => $this->version,
            'source_file'           => $this->sourceFile,
            'source_line'           => $this->sourceLine,
            'hash'                  => $this->hash,
            'metadata'              => $this->metadata,
            'created_at'            => $this->createdAt,
            'updated_at'            => $this->updatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data)
    {
        return new self($data);
    }
}
