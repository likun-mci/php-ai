<?php
namespace Ai\Response\Concerns;

/**
 * 各扩展能力响应类的公共部分
 *
 * 抽出来只为避免四个响应类里出现四份一模一样的 getter，
 * 没有更深的含义——真正有差异的取值方法（getUrls / getBytes / getVectors）
 * 仍由各响应类自己定义。
 */
trait HasRawPayload
{
    /** @var array<string, mixed> */
    protected $raw = [];
    /** @var array<string, mixed> */
    protected $usage = [];
    /** @var string */
    protected $model = '';
    /** @var string */
    protected $error = '';

    /**
     * @return array<string, mixed>
     */
    public function getRaw(): array
    {
        return $this->raw;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUsage(): array
    {
        return $this->usage;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getError(): string
    {
        return $this->error;
    }

    public function isSuccess(): bool
    {
        return $this->error === '';
    }

    /**
     * 填充公共字段。各响应类的构造函数调用
     *
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $usage
     */
    protected function fillCommon(array $raw, string $model = '', array $usage = [], string $error = ''): void
    {
        $this->raw   = $raw;
        $this->model = $model;
        $this->usage = $usage;
        $this->error = $error;
    }
}
