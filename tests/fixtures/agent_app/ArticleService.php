<?php
namespace AgentAppFixture;

/**
 * 文章业务 Service —— Agent Tool 标注示例（PHPDoc 路线，PHP 7.1 可用）
 *
 * 只有带 @agent-tool 的方法会进 Registry；其余 public 方法（如 helper()）不会。
 */
class ArticleService
{
    /** @var array<int, array<string, mixed>> 假数据，测试用 */
    protected $rows = [
        1 => ['id' => 1, 'title' => '香港SEO公司推荐', 'views' => 980, 'status' => 'published'],
        2 => ['id' => 2, 'title' => 'PHP 性能优化', 'views' => 420, 'status' => 'draft'],
    ];

    /**
     * 文章列表
     *
     * @agent-tool article.list
     * @agent-description 分页列出文章，可按状态过滤
     * @agent-controller article/list
     * @agent-risk low
     * @agent-keywords 文章,列表,分页
     *
     * @param int $page 页码，从 1 开始
     * @param int $size 每页条数
     * @param 'draft'|'published' $status 文章状态
     * @return array 文章数组
     */
    public function listArticles($page = 1, $size = 20, $status = 'published')
    {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row['status'] === $status) {
                $out[] = $row;
            }
        }
        return array_slice($out, ($page - 1) * $size, $size);
    }

    /**
     * 读取单篇文章
     *
     * @agent-tool article.read
     * @agent-description 按 ID 读取一篇文章的完整内容
     * @agent-controller article/read
     * @agent-risk low
     * @agent-keywords 文章,详情,查看
     *
     * @param int $id 文章 ID
     * @return array 文章数据
     */
    public function read($id)
    {
        return isset($this->rows[$id]) ? $this->rows[$id] : [];
    }

    /**
     * 创建文章
     *
     * @agent-tool article.create
     * @agent-description 新建一篇文章，标题与正文必填
     * @agent-controller article/create
     * @agent-risk medium
     * @agent-permission article/create
     * @agent-keywords 文章,新建,发布
     *
     * @param string $title 文章标题
     * @param string $content 文章正文
     * @param string|null $summary 文章摘要
     * @return array 新建的文章
     */
    public function create($title, $content, $summary = null)
    {
        return ['id' => 3, 'title' => $title, 'content' => $content, 'summary' => $summary];
    }

    /**
     * 修改文章
     *
     * @agent-tool article.update
     * @agent-description 修改指定文章的标题、摘要和正文
     * @agent-controller article/update
     * @agent-risk medium
     * @agent-confirm false
     * @agent-permission article/update
     * @agent-keywords 文章,修改,编辑,标题
     * @agent-version 1.0
     *
     * @param int $id 文章 ID
     * @param string|null $title 文章标题
     * @param string|null $summary 文章摘要
     * @param string|null $content 文章正文
     * @return array 修改后的文章
     */
    public function update($id, $title = null, $summary = null, $content = null)
    {
        $row = isset($this->rows[$id]) ? $this->rows[$id] : ['id' => $id];
        if ($title !== null) {
            $row['title'] = $title;
        }
        if ($summary !== null) {
            $row['summary'] = $summary;
        }
        if ($content !== null) {
            $row['content'] = $content;
        }
        return $row;
    }

    /**
     * 删除文章
     *
     * @agent-tool article.delete
     * @agent-description 永久删除一篇文章，不可恢复
     * @agent-controller article/delete
     * @agent-risk high
     * @agent-keywords 文章,删除
     *
     * @param int $id 文章 ID
     * @return array 删除结果
     */
    public function delete($id)
    {
        return ['deleted' => $id];
    }

    /**
     * 内部辅助方法——没有 @agent-tool，不应该进 Registry
     *
     * @param string $s
     * @return string
     */
    public function helper($s)
    {
        return strtoupper($s);
    }
}
