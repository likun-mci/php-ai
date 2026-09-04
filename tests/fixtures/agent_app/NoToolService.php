<?php
namespace AgentAppFixture;

// 这个文件里没有任何 Agent 标注。Indexer 必须**连 include 都不做**——
// 下面这个常量就是探针：一旦被载入，测试就能发现它被定义了。
define('AGENT_FIXTURE_NO_TOOL_LOADED', true);

/**
 * 没有任何 Agent 标注的 Service
 */
class NoToolService
{
    /**
     * 普通的 public 方法，不该出现在 Registry 里
     *
     * @param int $a
     * @return int
     */
    public function doSomething($a)
    {
        return $a * 2;
    }
}
