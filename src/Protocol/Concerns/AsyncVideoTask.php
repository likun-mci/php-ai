<?php
namespace Ai\Protocol\Concerns;

use Ai\Helpers\Endpoint;

/**
 * 异步视频任务的公共小工具
 *
 * 四家平台（通义万相 / 智谱 CogVideoX / 火山方舟 Seedance / MiniMax 海螺）
 * 的字段名、状态取值、甚至流程步数都不一样，真正能共用的只有这几个小函数。
 * 具体的 buildVideoRequest / parseTaskSubmit / parseTaskStatus 由各协议自己实现。
 */
trait AsyncVideoTask
{
    /**
     * 本协议已登记的视频生成模型
     *
     * 一律以各平台官方文档为准。查不到时**返回空数组而不是凭印象填**——
     * 空清单会让调用方回退到平台自己的模型列表接口，错清单则会让用户
     * 拿着一个不存在的模型名去调，还以为是自己配错了。
     *
     * @return array<int, string>
     */
    public function knownVideoModels(): array
    {
        return [];
    }

    /**
     * 取提交端点的同源前缀，用于拼查询地址
     *
     * 必须由**实际提交端点**推导，不能写死官方域名——用户把 base_url
     * 指向自建网关时，查询请求也得走同一个网关
     */
    protected function taskOrigin(string $submitUrl): string
    {
        $up = parse_url(Endpoint::withScheme($submitUrl));
        if (empty($up['host'])) {
            return '';
        }
        return (isset($up['scheme']) ? $up['scheme'] : 'https') . '://' . $up['host']
             . (isset($up['port']) ? ':' . $up['port'] : '');
    }

    /**
     * 把提交端点尾部的若干段换成另一段路径
     *
     * 例：.../api/v3/contents/generations/tasks + '/abc' → .../api/v3/contents/generations/tasks/abc
     */
    protected function taskUrlFrom(string $submitUrl, string $suffix): string
    {
        return rtrim($submitUrl, '/') . $suffix;
    }

    /**
     * 把一个完整 URL 尾部的已知路径换成另一段路径
     *
     * 用来从**提交端点**推导查询端点。不能只取 scheme+host——
     * 用户把 base_url 指向带路径前缀的网关（如 https://gw.internal/ds）时，
     * 只取 origin 会把 /ds 丢掉，请求打到网关根目录上去。
     *
     * @param string $url     完整 URL
     * @param string $tail    要剥掉的已知尾部路径
     * @param string $newTail 要接上的新路径
     * @return string 尾部对不上时退回「origin + newTail」
     */
    protected function taskSiblingUrl(string $url, string $tail, string $newTail): string
    {
        $tail = '/' . trim($tail, '/');
        if ($tail !== '/' && substr($url, -strlen($tail)) === $tail) {
            return substr($url, 0, -strlen($tail)) . $newTail;
        }
        $origin = $this->taskOrigin($url);
        return $origin === '' ? '' : $origin . $newTail;
    }

    /**
     * 从响应里按点号路径取值
     *
     * @param array<string, mixed> $data
     * @return mixed
     */
    protected function dig(array $data, string $path)
    {
        $cur = $data;
        foreach (explode('.', $path) as $seg) {
            if (!is_array($cur) || !array_key_exists($seg, $cur)) {
                return null;
            }
            $cur = $cur[$seg];
        }
        return $cur;
    }

    /**
     * 从响应里抽错误信息
     *
     * @param array<string, mixed> $response
     */
    protected function taskError(array $response): string
    {
        foreach (['error', 'base_resp'] as $key) {
            if (!isset($response[$key])) {
                continue;
            }
            $err = $response[$key];
            if (is_string($err) && $err !== '') {
                return $err;
            }
            if (is_array($err)) {
                foreach (['message', 'status_msg', 'msg'] as $mk) {
                    if (!empty($err[$mk]) && is_string($err[$mk])) {
                        return $err[$mk];
                    }
                }
            }
        }
        foreach (['message', 'msg', 'task_status_message'] as $key) {
            if (!empty($response[$key]) && is_string($response[$key])) {
                return $response[$key];
            }
        }
        return '';
    }
}
