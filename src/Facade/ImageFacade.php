<?php
namespace Ai\Facade;

use Ai\Exceptions\RequestException;
use Ai\Helpers\Capabilities;
use Ai\Response\ImageResponse;

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
}
