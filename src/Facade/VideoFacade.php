<?php
namespace Ai\Facade;

use Ai\Exceptions\UnsupportedCapabilityException;
use Ai\Helpers\Capabilities;
use Ai\Task\AsyncTask;

/**
 * 视频生成
 *
 * 视频接口无一例外都是异步任务式，因此 generate() **返回任务而不是结果**，
 * 立刻返回、不阻塞。
 *
 * ```php
 * // Web 请求里：提交后存库就结束
 * $task = $ai->video()->generate('日落的海边');
 * $db->save(['task' => json_encode($task->toArray())]);
 *
 * // 定时任务里：恢复并查询
 * $task = AsyncTask::fromArray(json_decode($row['task'], true), $ai);
 * if ($task->refresh()->isSucceeded()) {
 *     $task->getResult()->saveTo('/var/www/videos/x.mp4');
 * }
 * ```
 */
class VideoFacade extends BaseFacade
{
    protected function capability(): string
    {
        return Capabilities::VIDEO;
    }

    /**
     * 提交视频生成任务
     *
     * ⚠️ 返回的是 AsyncTask，**不是**视频。视频生成通常要几分钟，
     * 在 Web 请求里阻塞等待会占死一个 PHP-FPM worker。
     *
     * @param string               $prompt  提示词
     * @param array<string, mixed> $options 如 ['duration' => 5, 'ratio' => '16:9', 'image' => '首帧图 URL']
     */
    public function generate(string $prompt, array $options = []): AsyncTask
    {
        $payload = array_merge($options, [
            'model'  => isset($options['model']) ? $options['model'] : $this->modelName(),
            'prompt' => $prompt,
        ]);

        $protocol = $this->protocol();
        $response = $this->dispatch($payload);

        // 提交回执的解析由协议层提供：各平台 task_id 的字段位置完全不同
        // （output.task_id / id / data.task_id），且查询端点也未必与提交端点同路径
        if (!method_exists($protocol, 'parseTaskSubmit')) {
            throw new UnsupportedCapabilityException(sprintf(
                '协议 %s 未实现异步任务提交解析（缺少 parseTaskSubmit 方法）',
                get_class($protocol)
            ));
        }

        /** @var array{id?: string, query_url?: string} $info */
        $info = $protocol->parseTaskSubmit(Capabilities::VIDEO, $response);

        $id = isset($info['id']) ? (string) $info['id'] : '';
        if ($id === '') {
            throw new UnsupportedCapabilityException(
                '平台未返回任务 ID，无法跟踪该视频生成任务。原始响应：'
                . json_encode($response, JSON_UNESCAPED_UNICODE)
            );
        }

        return new AsyncTask(
            $id,
            Capabilities::VIDEO,
            $this->ai,
            isset($info['query_url']) ? (string) $info['query_url'] : '',
            $response
        );
    }
}
