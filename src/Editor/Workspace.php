<?php
namespace Ai\Editor;

/**
 * 工作区（通用值对象）
 *
 * 描述「AI 可跨文件编辑的一个沙箱项目」。库内不认识任何具体业务概念
 * （如网站模板、.prompts 文档等），这些一律由业务层构建后注入：
 *
 *   $ws = (new Workspace())
 *       ->setName('default')                    // 人类可读名（业务可传模板名）
 *       ->setRoot('/abs/path/to/sandbox/')      // 沙箱根（绝对路径）
 *       ->setRelPath('site/index.php')          // 当前文件相对 root
 *       ->setFiles(['site/index.php', ...])     // 相对文件清单
 *       ->setGuidelines("……开发规范文本……");    // 注入 system 的规则/文档
 *
 * EditContext 持有它即进入「工作区模式」：允许跨文件 / 新建 / 删除，
 * 并把 guidelines 注入系统提示。无工作区时只能操作当前文件。
 */
class Workspace
{
    /**
     * @var string
     */
    protected $name       = '';
    /**
     * @var string
     */
    protected $root       = '';   // 绝对路径，含尾斜杠
    /**
     * @var string
     */
    protected $relPath    = '';    // 当前文件相对 root
    /**
     * @var string[]
     */
    protected $files      = [];    // 相对路径清单
    /**
     * @var string
     */
    protected $guidelines = '';    // 注入 system 的规范/文档文本

    /**
     * @param mixed $name
     * @return $this
     */
    public function setName($name)        { $this->name = (string) $name; return $this; }
    /**
     * @param mixed $root
     * @return $this
     */
    public function setRoot($root)        { $this->root = rtrim(str_replace('\\', '/', (string) $root), '/') . '/'; return $this; }
    /**
     * @param mixed $rel
     * @return $this
     */
    public function setRelPath($rel)      { $this->relPath = ltrim(str_replace('\\', '/', (string) $rel), '/'); return $this; }
    /**
     * @param string[] $files
     * @return $this
     */
    public function setFiles(array $files){ $this->files = array_values($files); return $this; }
    /**
     * @param mixed $text
     * @return $this
     */
    public function setGuidelines($text)  { $this->guidelines = (string) $text; return $this; }

    /**
     * @return string
     */
    public function getName()       { return $this->name; }
    /**
     * @return string
     */
    public function getRoot()       { return $this->root; }
    /**
     * @return string
     */
    public function getRelPath()    { return $this->relPath; }
    /**
     * @return string[]
     */
    public function getFiles()      { return $this->files; }
    /**
     * @return string
     */
    public function getGuidelines() { return $this->guidelines; }
}
