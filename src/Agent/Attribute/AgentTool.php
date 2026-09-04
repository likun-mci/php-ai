<?php
namespace Ai\Agent\Attribute;

/**
 * `#[AgentTool]` —— 用 PHP 8 Attribute 声明 Agent Tool
 *
 * PHPDoc 那套（`@agent-tool` 等）是为兼容 PHP 7 项目准备的；PHP 8+ 项目可以改用
 * 本 Attribute，好处是拼错标签名会被 IDE/静态分析当场发现，而不是静默不入库。
 *
 * ```php
 * #[AgentTool(name: 'article.update', description: '修改文章', controller: 'article/update', risk: 'medium')]
 * public function update(int $id, ?string $title = null): array
 * {
 * }
 * ```
 *
 * 两者可以混用：同一个方法上 Attribute 的字段**覆盖** PHPDoc 的同名字段，
 * Attribute 没给的字段仍从 PHPDoc 取（规范 §6 的优先级）。
 *
 * ⚠️ 下面那行 `#[\Attribute(...)]` 必须**写在一行**：PHP 7 把 `#` 开头的整行当注释，
 * 于是本类在 7.1 上就是个普通类，不会有语法错误；PHP 8 才把它解析成 Attribute。
 * 拆成多行会让 PHP 7 在第二行报 Parse error。
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class AgentTool
{
    /** @var string Tool 名（article.update） */
    public $name;

    /** @var string 给模型看的能力描述 */
    public $description;

    /** @var string 现有 Controller 入口路径 */
    public $controller;

    /** @var string 风险等级 low/medium/high/critical */
    public $risk;

    /** @var bool|null 是否需要人工确认；null 表示不声明，由风险等级推导 */
    public $confirm;

    /** @var string[]|string 可选权限标识（仅 Discovery 过滤用） */
    public $permission;

    /** @var string[]|string 补充搜索词 */
    public $keywords;

    /** @var bool 是否启用 */
    public $enabled;

    /** @var string Tool 版本 */
    public $version;

    /**
     * 构造签名不用具名参数以外的花样，保持 PHP 7.1 可解析
     * （具名参数是**调用方**语法，PHP 8 项目才会那样写，本类本身没有 8.0 语法）。
     *
     * @param string $name
     * @param string $description
     * @param string $controller
     * @param string $risk
     * @param bool|null $confirm
     * @param string[]|string $permission
     * @param string[]|string $keywords
     * @param bool $enabled
     * @param string $version
     */
    public function __construct(
        $name = '',
        $description = '',
        $controller = '',
        $risk = 'low',
        $confirm = null,
        $permission = [],
        $keywords = [],
        $enabled = true,
        $version = ''
    ) {
        $this->name        = (string) $name;
        $this->description = (string) $description;
        $this->controller  = (string) $controller;
        $this->risk        = (string) $risk;
        $this->confirm     = $confirm === null ? null : (bool) $confirm;
        $this->permission  = $permission;
        $this->keywords    = $keywords;
        $this->enabled     = (bool) $enabled;
        $this->version     = (string) $version;
    }

    /**
     * 转成 Indexer 用的字段数组（只输出**显式给过**的字段，避免默认值盖掉 PHPDoc）
     *
     * @return array<string, mixed>
     */
    public function toFields()
    {
        $out = [];
        if ($this->name !== '') {
            $out['name'] = $this->name;
        }
        if ($this->description !== '') {
            $out['description'] = $this->description;
        }
        if ($this->controller !== '') {
            $out['controller_path'] = $this->controller;
        }
        if ($this->risk !== '' && $this->risk !== 'low') {
            $out['risk'] = $this->risk;
        }
        if ($this->confirm !== null) {
            $out['requires_confirmation'] = $this->confirm;
        }
        if ($this->permission !== [] && $this->permission !== '') {
            $out['permissions'] = $this->permission;
        }
        if ($this->keywords !== [] && $this->keywords !== '') {
            $out['keywords'] = $this->keywords;
        }
        if ($this->enabled === false) {
            $out['enabled'] = false;
        }
        if ($this->version !== '') {
            $out['version'] = $this->version;
        }
        return $out;
    }
}
