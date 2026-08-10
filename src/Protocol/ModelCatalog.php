<?php
namespace Ai\Protocol;

/**
 * 模型目录：内置常用模型清单 + 拉取失败时的兜底
 *
 * 各协议类用 knownModels() 声明该平台的常用模型（模型 id => 显示名），两个用途：
 *   1) 后台下拉框可离线渲染，不必先发一次请求；
 *   2) 平台没有模型列表接口（如 Perplexity、商汤）或临时拉取失败时兜底。
 *
 * 兜底只在「实际请求的确实是该协议的官方域名」时生效——接第三方网关/中转时
 * 返回官方模型清单会误导业务层，此时一律返回 null。
 */
trait ModelCatalog
{
    /**
     * 该平台的常用模型：模型 id => 显示名
     * 子类按需覆盖，返回空数组表示不提供内置清单
     */
    public function knownModels(): array
    {
        return [];
    }

    /**
     * 模型列表拉取失败/为空时的兜底清单
     *
     * @param array $config 运行时配置（据此判断实际请求的是不是官方域名）
     * @return array|null 不适用兜底时返回 null
     */
    protected function fallbackModels(array $config): ?array
    {
        $known = $this->knownModels();
        if (!$known) {
            return null;
        }
        $official = $this->defaultBaseUrl();
        if ($official === '') {
            return null;
        }
        return \Ai\Helpers\Endpoint::sameHost($this->modelsEndpoint($config), $official)
            ? $known
            : null;
    }
}
