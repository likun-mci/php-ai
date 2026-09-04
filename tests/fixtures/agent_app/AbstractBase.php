<?php
namespace AgentAppFixture;

/**
 * 抽象类 —— 即使带标注也不该进 Registry（不能实例化，无法作为执行载体）
 */
abstract class AbstractBase
{
    /**
     * @agent-tool abstract.never
     * @agent-description 抽象类里的标注不应被索引
     * @agent-controller abstract/never
     *
     * @return array
     */
    public function never()
    {
        return [];
    }
}

/**
 * trait 同理
 */
trait NeverTrait
{
    /**
     * @agent-tool trait.never
     * @agent-description trait 里的标注不应被索引
     * @agent-controller trait/never
     *
     * @return array
     */
    public function neverTrait()
    {
        return [];
    }
}
