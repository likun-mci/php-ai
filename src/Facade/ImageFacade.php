<?php
namespace Ai\Facade;

use Ai\Exceptions\RequestException;
use Ai\Exceptions\UnsupportedCapabilityException;
use Ai\Helpers\AIFile;
use Ai\Helpers\Capabilities;
use Ai\Response\ImageResponse;
use Ai\Task\AsyncTask;

/**
 * 图像生成
 *
 * ```php
 * $img = $ai->images()->generate('一只在看书的猫', ['size' => '1024x1024', 'n' => 2]);
 * $paths = $img->saveTo('/var/www/uploads');   // 及时落地：URL 通常 24 小时后失效
 * ```
 */
class ImageFacade extends BaseFacade
{
    protected function capability(): string
    {
        return Capabilities::IMAGE;
    }

    /**
     * 文生图
     *
     * 只归一最高频的几个参数（size / n / response_format / negative_prompt），
     * 其余平台私有参数原样透传——把所有平台的所有参数都做映射，
     * 归一层会厚到没法维护，且平台一改就得跟着改
     *
     * @param string               $prompt  提示词
     * @param array<string, mixed> $options 生成参数
     */
    public function generate(string $prompt, array $options = []): ImageResponse
    {
        if (trim($prompt) === '') {
            throw new RequestException('提示词为空', '', 'empty_prompt', []);
        }

        // 异步平台在这里直接给不出图。返回一个「成功但没有图」的响应是最坏的做法——
        // 调用方拿到空结果，完全不知道该去哪找原因
        $protocol = $this->protocol();
        if (method_exists($protocol, 'imageIsAsync') && $protocol->imageIsAsync()) {
            throw new UnsupportedCapabilityException(
                '当前平台的图像生成是异步任务式，一次请求拿不到图片。'
                . '请改用 $ai->images()->generateAsync()，它会立刻返回一个 AsyncTask；'
                . '之后用 refresh() 查询，或在 CLI / 队列 worker 里用 wait() 等待。'
            );
        }

        $payload = array_merge($options, [
            'model'  => isset($options['model']) ? $options['model'] : $this->modelName(),
            'prompt' => $prompt,
        ]);

        $response = $this->send($payload);
        if (!$response instanceof ImageResponse) {
            throw new RequestException(
                '协议返回了非预期的响应类型：' . get_class($response),
                '',
                'unexpected_response_type',
                []
            );
        }
        return $response;
    }

    /**
     * 提交异步图像生成任务
     *
     * 通义万相等平台的文生图是「提交 → 轮询」两步，一次请求拿不到图。
     * 与 video()->generate() 同样**立刻返回、不阻塞**。
     *
     * ```php
     * $task = $ai->images()->generateAsync('一只在看书的猫');
     * $db->save(['task' => json_encode($task->toArray())]);
     * // ……稍后
     * $task = AsyncTask::fromArray($row, $ai);
     * if ($task->refresh()->isSucceeded()) {
     *     $task->getResult()->saveTo('/var/www/uploads');
     * }
     * ```
     *
     * @param array<string, mixed> $options
     * @throws RequestException|UnsupportedCapabilityException
     */
    public function generateAsync(string $prompt, array $options = []): AsyncTask
    {
        if (trim($prompt) === '') {
            throw new RequestException('提示词为空', '', 'empty_prompt', []);
        }

        $protocol = $this->protocol();
        if (!method_exists($protocol, 'imageIsAsync') || !$protocol->imageIsAsync()) {
            throw new UnsupportedCapabilityException(
                '当前平台的图像生成是同步的，一次请求就能拿到图片，不需要异步任务。'
                . '请直接用 $ai->images()->generate()。'
            );
        }
        if (!method_exists($protocol, 'parseTaskSubmit')) {
            throw new UnsupportedCapabilityException(sprintf(
                '协议 %s 未实现异步任务提交解析（缺少 parseTaskSubmit 方法）',
                get_class($protocol)
            ));
        }

        $payload = array_merge($options, [
            'model'  => isset($options['model']) ? $options['model'] : $this->modelName(),
            'prompt' => $prompt,
        ]);

        $response = $this->dispatch($payload);
        /** @var array{id?: string, query_url?: string} $info */
        $info = $protocol->parseTaskSubmit(Capabilities::IMAGE, $response, $this->endpoint());

        $id = isset($info['id']) ? (string) $info['id'] : '';
        if ($id === '') {
            throw new UnsupportedCapabilityException(
                '平台未返回任务 ID，无法跟踪该图像生成任务。原始响应：'
                . json_encode($response, JSON_UNESCAPED_UNICODE)
            );
        }

        return new AsyncTask(
            $id,
            Capabilities::IMAGE,
            $this->ai,
            isset($info['query_url']) ? (string) $info['query_url'] : '',
            $response
        );
    }

    /**
     * 图像编辑（图生图 / 局部重绘）
     *
     * 走 multipart 上传，与文生图**不是同一个端点**。
     * mask 是可选的蒙版图：给了就只重绘蒙版覆盖的区域。
     *
     * ```php
     * $ai->images()->edit('/path/cat.png', '把背景换成星空')->saveTo('/var/www/uploads');
     * $ai->images()->edit('/path/cat.png', '去掉这只手', ['mask' => '/path/mask.png']);
     * ```
     *
     * @param string|AIFile        $image   待编辑的本地图片
     * @param string               $prompt  编辑指令
     * @param array<string, mixed> $options 支持 mask（本地路径或 AIFile）、n、size 等
     * @throws RequestException
     */
    public function edit($image, string $prompt, array $options = []): ImageResponse
    {
        if (trim($prompt) === '') {
            throw new RequestException('编辑指令为空', '', 'empty_prompt', []);
        }

        $payload = array_merge($options, [
            'model'  => isset($options['model']) ? $options['model'] : $this->modelName(),
            'prompt' => $prompt,
            'image'  => $this->toLocalFile($image, 'image'),
        ]);

        if (!empty($options['mask'])) {
            $payload['mask'] = $this->toLocalFile($options['mask'], 'mask');
        }

        $response = $this->send(
            $payload,
            ['Content-Type' => 'multipart/form-data'],
            Capabilities::IMAGE_EDIT
        );
        if (!$response instanceof ImageResponse) {
            throw new RequestException(
                '协议返回了非预期的响应类型：' . get_class($response),
                '',
                'unexpected_response_type',
                []
            );
        }
        return $response;
    }

    /**
     * 把入参统一成本地文件
     *
     * 只接受本地文件：远端 URL 需要下载，而下载必须走带 SSRF 防护的
     * Media::download()，不该由上传逻辑顺手代劳
     *
     * @param string|AIFile $file
     * @throws RequestException
     */
    protected function toLocalFile($file, string $label): AIFile
    {
        $af = $file instanceof AIFile ? $file : AIFile::fromPath((string) $file);
        if ($af->getType() !== 'path') {
            throw new RequestException(
                sprintf(
                    '图像编辑的 %s 只接受本地文件；远端图片请先用 Ai\\Helpers\\Media::download() 取回并落盘',
                    $label
                ),
                '',
                'image_edit_needs_local_file',
                []
            );
        }
        return $af;
    }
}
