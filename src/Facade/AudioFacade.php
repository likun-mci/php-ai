<?php
namespace Ai\Facade;

use Ai\Exceptions\RequestException;
use Ai\Helpers\AIFile;
use Ai\Helpers\Capabilities;
use Ai\Response\AudioResponse;

/**
 * 语音合成与识别（HTTP 通道）
 *
 * 只提供 WebSocket 的平台（如讯飞）走 $ai->realtime()，不在这里。
 *
 * ```php
 * $ai->audio()->speech('你好世界')->saveTo('/tmp/hello.mp3');
 * $text = $ai->audio()->transcribe('/tmp/record.wav')->getText();
 * ```
 */
class AudioFacade extends BaseFacade
{
    /**
     * 本门面同时服务 tts 与 asr 两种能力，具体由各方法自行指定
     */
    protected function capability(): string
    {
        return Capabilities::TTS;
    }

    /**
     * 语音合成：文本 → 音频
     *
     * @param string               $text    待合成文本
     * @param array<string, mixed> $options 如 ['voice' => 'alloy', 'speed' => 1.0, 'format' => 'mp3']
     */
    public function speech(string $text, array $options = []): AudioResponse
    {
        if (trim($text) === '') {
            throw new RequestException('待合成的文本为空', '', 'empty_text', []);
        }

        $payload = array_merge($options, [
            'model' => isset($options['model']) ? $options['model'] : $this->modelName(),
            'input' => $text,
        ]);

        $response = $this->send($payload, [], Capabilities::TTS);
        if (!$response instanceof AudioResponse) {
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
     * 语音识别：音频 → 文本
     *
     * 走 multipart/form-data 上传。**Content-Type 只是一个意图声明**，
     * 传输层会把它摘掉并交给 curl 生成带 boundary 的完整头——
     * 手写的 multipart 头没有 boundary，服务端会拿到无法解析的 body 且仍返回 200。
     *
     * @param string|AIFile        $file    本地音频文件路径，或 AIFile 实例
     * @param array<string, mixed> $options 如 ['language' => 'zh', 'prompt' => '...']
     */
    public function transcribe($file, array $options = []): AudioResponse
    {
        $audio = $file instanceof AIFile ? $file : AIFile::fromPath((string) $file);

        if ($audio->getType() !== 'path') {
            throw new RequestException(
                '语音识别只接受本地文件；远端音频请先用 Ai\Helpers\Media::download() 取回并落盘',
                '',
                'asr_needs_local_file',
                []
            );
        }

        $payload = array_merge($options, [
            'model' => isset($options['model']) ? $options['model'] : $this->modelName(),
            'file'  => $audio,
        ]);

        $response = $this->send(
            $payload,
            ['Content-Type' => 'multipart/form-data'],
            Capabilities::ASR
        );
        if (!$response instanceof AudioResponse) {
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
