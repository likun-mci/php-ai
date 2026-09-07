<?php
namespace Ai\Agent\Capability;

use Ai\AI;
use Ai\Agent\Context\MessagePart;
use Ai\Agent\Media\MediaResolver;
use Ai\Helpers\MediaTranslator;

/**
 * 模态路由 —— 主模型看不了图时，自动找一个能看的
 *
 * 四种模式：
 *
 * | 模式 | 行为 |
 * |---|---|
 * | `auto`（默认） | 当前模型支持 → 直发；不支持 → 找视觉模型做 describe；找不到 → 明确告知不可用 |
 * | `native` | 强制直发当前模型，不路由 |
 * | `describe` | 总是先让视觉模型把图读成文字，再交给主模型 |
 * | `switch` | 本轮**这一次模型调用**换成视觉模型；tools / permissions / session / memory 一律不变 |
 *
 * 为什么默认 `auto` 而不是 `describe`（设计文档 §10）：主模型本来就支持视觉时，
 * describe 会白白多花一次模型调用，而且把原图降级成了别人写的文字。
 *
 * describe 产出的是**派生上下文**，不替代原始媒体引用（设计文档 §5.3）：
 * 描述被记在 `agent_media` 块的 `description` 字段里，原 `ref` 原样保留。
 * 好处有二——以后换成视觉模型还能重新看原图；同一张图在后续轮次里不会被
 * 反复描述（那既费钱又慢）。
 *
 * ⚠️ 描述回填给主模型时会**注明来源**（「由视觉模型 X 生成」），不伪装成
 * 主模型自己看到的（设计文档 §8）。
 *
 * 注：不用类型化属性，保持 PHP 7.1 兼容（库的版本下限）。
 */
class ModalityRouter
{
    const MODE_AUTO     = 'auto';
    const MODE_NATIVE   = 'native';
    const MODE_DESCRIBE = 'describe';
    const MODE_SWITCH   = 'switch';

    /** 决策：直发当前模型 */
    const DECISION_NATIVE = 'native';
    /** 决策：先让视觉模型转成文字 */
    const DECISION_DESCRIBE = 'describe';
    /** 决策：本次调用换用视觉模型 */
    const DECISION_SWITCH = 'switch';
    /** 决策：没有可用的视觉模型 */
    const DECISION_UNAVAILABLE = 'unavailable';
    /** 决策：消息里没有媒体，什么都不用做 */
    const DECISION_NONE = 'none';

    /** @var CapabilityResolver */
    protected $capabilities;

    /** @var string */
    protected $mode = self::MODE_AUTO;

    /** @var string 指定的视觉模型；空则从已配置平台里自动挑 */
    protected $preferredModel = '';

    /** @var array<string, array<string, mixed>> 平台名 => 连接配置 */
    protected $platformConfigs = [];

    /** @var MediaResolver|null */
    protected $resolver = null;

    /** @var callable|null function(string $model, array $config): AI —— 便于测试替换 */
    protected $aiFactory = null;

    /** @var string 让视觉模型输出描述时用的提示词 */
    protected $describePrompt = '请详细描述这个文件的内容。'
        . '尽可能保留一切可能相关的细节：可见的文字（逐字照录）、数字、表格、'
        . '代码、界面元素与它们的位置关系、颜色、图表的数据趋势。'
        . '只描述你实际看到的内容，不要推测、不要总结、不要评价。';

    /** @var array<int, array<string, mixed>> 本次路由产生的说明，供事件与调试 */
    protected $notes = [];

    /**
     * @param CapabilityResolver|null $capabilities
     * @param array<string, mixed> $options mode / model / platforms / describe_prompt
     */
    public function __construct($capabilities = null, array $options = [])
    {
        $this->capabilities = $capabilities instanceof CapabilityResolver
            ? $capabilities
            : new CapabilityResolver();

        if (isset($options['mode'])) {
            $this->setMode($options['mode']);
        }
        if (isset($options['model'])) {
            $this->preferredModel = (string) $options['model'];
        }
        if (isset($options['platforms']) && is_array($options['platforms'])) {
            $this->platformConfigs = $options['platforms'];
        }
        if (isset($options['describe_prompt']) && (string) $options['describe_prompt'] !== '') {
            $this->describePrompt = (string) $options['describe_prompt'];
        }
    }

    /**
     * @param string $mode
     * @return $this
     */
    public function setMode($mode)
    {
        $mode = strtolower(trim((string) $mode));
        $valid = [self::MODE_AUTO, self::MODE_NATIVE, self::MODE_DESCRIBE, self::MODE_SWITCH];
        $this->mode = in_array($mode, $valid, true) ? $mode : self::MODE_AUTO;
        return $this;
    }

    /** @return string */
    public function mode()
    {
        return $this->mode;
    }

    /**
     * @param array<string, array<string, mixed>> $configs
     * @return $this
     */
    public function setPlatformConfigs(array $configs)
    {
        $this->platformConfigs = $configs;
        return $this;
    }

    /**
     * @param MediaResolver|null $resolver
     * @return $this
     */
    public function setResolver($resolver)
    {
        $this->resolver = $resolver instanceof MediaResolver ? $resolver : null;
        return $this;
    }

    /**
     * 替换 AI 实例的构造方式（测试用；生产环境用默认的即可）
     *
     * @param callable|null $factory function(string $model, array $config): AI
     * @return $this
     */
    public function setAiFactory($factory)
    {
        $this->aiFactory = is_callable($factory) ? $factory : null;
        return $this;
    }

    /** @return CapabilityResolver */
    public function capabilities()
    {
        return $this->capabilities;
    }

    /** 本次路由产生的说明
     * @return array<int, array<string, mixed>>
     */
    public function notes()
    {
        return $this->notes;
    }

    /**
     * 路由决策 + 必要的消息改写
     *
     * @param array<int, array<string, mixed>> $messages 当前上下文
     * @param string $currentModel 主模型名
     * @param array<string, mixed> $options family / platform
     * @return array{messages: array<int, array<string, mixed>>, decision: string,
     *               support: array<string, bool>, provider: string, notes: array<int, array<string, mixed>>}
     */
    public function route(array $messages, $currentModel, array $options = [])
    {
        $this->notes = [];

        $support = $this->capabilities->supportFlags($currentModel, $options, true);

        if (!MessagePart::messagesHaveMedia($messages)) {
            return $this->result($messages, self::DECISION_NONE, $support, '');
        }

        if ($this->mode === self::MODE_NATIVE) {
            return $this->result($messages, self::DECISION_NATIVE, $support, '');
        }

        // 哪些模态是当前模型吃不下的、哪些是「不确定」的
        $split   = $this->classifyModalities($messages, $currentModel, $options);
        $missing = $split['missing'];
        $unsure  = $split['unsure'];

        if ($this->mode === self::MODE_AUTO && $missing === [] && $unsure === []) {
            // 主模型**确定**能看——不折腾，直发（设计文档 §10）
            return $this->result($messages, self::DECISION_NATIVE, $support, '');
        }

        // 需要外援。describe 模式即便主模型支持也照走（用户显式要求）
        $needed = array_values(array_unique(array_merge($missing, $unsure)));
        if ($needed === []) {
            $needed = [Modalities::IMAGE];
        }
        $provider = $this->pickProvider($needed);

        // 能力不确定 + 没有可用的视觉模型 → 乐观直发，让平台自己说行不行。
        // 这比谎称看不到诚实：万一模型其实支持，用户就白白损失了这个能力
        if ($provider === null && $missing === [] && $unsure !== []) {
            $this->notes[] = ['reason' => 'unknown_capability_optimistic', 'modalities' => $unsure];
            return $this->result($messages, self::DECISION_NATIVE, $support, '');
        }

        if ($provider === null) {
            $this->notes[] = [
                'reason'    => 'no_provider',
                'modalities' => $needed,
            ];
            return $this->result($messages, self::DECISION_UNAVAILABLE, $support, '');
        }

        if ($this->mode === self::MODE_SWITCH) {
            // 本次模型调用整个换成视觉模型；消息不改写，图片原样发过去
            return $this->result($messages, self::DECISION_SWITCH, $support, $provider['model']);
        }

        $messages = $this->describeAll($messages, $provider);
        return $this->result($messages, self::DECISION_DESCRIBE, $support, $provider['model']);
    }

    /**
     * 把消息里出现的模态分成「确定不支持」与「不确定」两类
     *
     * 为什么要分开：能力**未知**时如果乐观直发，撞上不支持的模型换回来的是
     * 一个毫无信息量的错误（实测 SCNet 的 GLM-5-Base 收到图片直接 HTTP 510
     * "Model Request Error"，既不说是哪个字段的问题也不说是不支持）。
     * 所以只要手上有确定能看图的模型，不确定的情况也优先路由过去；
     * 实在没有外援时才乐观直发——那时至少错误是可见的。
     *
     * @param array<int, array<string, mixed>> $messages
     * @param string $currentModel
     * @param array<string, mixed> $options
     * @return array{missing: string[], unsure: string[]}
     */
    protected function classifyModalities(array $messages, $currentModel, array $options)
    {
        $missing = [];
        $unsure  = [];
        foreach (MessagePart::mediaBlocksIn($messages) as $block) {
            // 已经有描述的不再算「缺」——它已经以文字形式可用了
            if (isset($block['description']) && (string) $block['description'] !== '') {
                continue;
            }
            $media = isset($block['media']) ? (string) $block['media'] : Modalities::IMAGE;
            $known = $this->capabilities->supports($currentModel, $media, $options);
            if ($known === false) {
                $missing[$media] = true;
            } elseif ($known === null) {
                $unsure[$media] = true;
            }
        }
        return ['missing' => array_keys($missing), 'unsure' => array_keys($unsure)];
    }

    /**
     * 从已配置平台里挑一个能处理这些模态的
     *
     * 优先用显式指定的 `model`；否则遍历 `platforms()` 配置，
     * 用 `CapabilityResolver::canServe()` 判断（未知能力默认不选）。
     *
     * @param string[] $modalities
     * @return array{model: string, platform: string, config: array<string, mixed>}|null
     */
    public function pickProvider(array $modalities)
    {
        if ($this->preferredModel !== '') {
            $platform = $this->platformOf($this->preferredModel);
            return [
                'model'    => $this->preferredModel,
                'platform' => $platform,
                'config'   => $this->configFor($this->preferredModel, $platform),
            ];
        }

        foreach ($this->platformConfigs as $platform => $config) {
            if (!is_array($config)) {
                continue;
            }
            $model = isset($config['model']) ? (string) $config['model'] : '';
            $opts  = ['platform' => (string) $platform];

            $ok = true;
            foreach ($modalities as $modality) {
                if (!$this->capabilities->canServe($model, $modality, $opts)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return ['model' => $model, 'platform' => (string) $platform, 'config' => $config];
            }
        }
        return null;
    }

    /**
     * 把消息里还没描述过的媒体逐个交给视觉模型
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array{model: string, platform: string, config: array<string, mixed>} $provider
     * @return array<int, array<string, mixed>>
     */
    protected function describeAll(array $messages, array $provider)
    {
        $ai = $this->makeAi($provider);
        if ($ai === null) {
            $this->notes[] = ['reason' => 'provider_unavailable', 'model' => $provider['model']];
            return $messages;
        }

        foreach ($messages as $mi => $msg) {
            if (!is_array($msg) || !isset($msg['content']) || !is_array($msg['content'])) {
                continue;
            }
            foreach ($msg['content'] as $bi => $block) {
                if (!MessagePart::isMedia($block)) {
                    continue;
                }
                if (isset($block['description']) && (string) $block['description'] !== '') {
                    continue;   // 描述过了，不重复花钱
                }
                $description = $this->describeOne($ai, $block, $provider);
                if ($description === '') {
                    continue;
                }
                // 原 ref 原样保留（设计文档 §5.3）：描述只是派生上下文，
                // 以后换成视觉模型还能重新看原图
                $messages[$mi]['content'][$bi]['description'] = $description;
                $messages[$mi]['content'][$bi]['described_by'] = $provider['model'];
            }
        }
        return $messages;
    }

    /**
     * 让视觉模型描述一个媒体
     *
     * @param AI $ai
     * @param array<string, mixed> $block
     * @param array{model: string, platform: string, config: array<string, mixed>} $provider
     * @return string 失败返回空串（不抛——一张图读不了不该让整轮跑不下去）
     */
    protected function describeOne(AI $ai, array $block, array $provider)
    {
        if ($this->resolver === null) {
            $this->notes[] = ['reason' => 'no_resolver'];
            return '';
        }

        $family = MediaTranslator::normalizeFamily(
            isset($provider['config']['family']) ? $provider['config']['family'] : $this->familyOfAi($ai)
        );

        // 视觉模型这一路自己装配翻译上下文：它跟主模型的支持情况不一样
        MediaTranslator::begin($this->resolver, [
            'image' => true,
            'pdf'   => true,
        ]);
        try {
            $resp = $ai->chat([
                'messages' => [[
                    'role'    => 'user',
                    'content' => MessagePart::compose($this->describePrompt, [$block]),
                ]],
            ]);
            $text = trim((string) $resp->getContent());
        } catch (\Throwable $e) {
            $this->notes[] = [
                'reason'  => 'describe_failed',
                'model'   => $provider['model'],
                'name'    => isset($block['name']) ? (string) $block['name'] : '',
                'error'   => $e->getMessage(),
            ];
            $text = '';
        }
        MediaTranslator::end();

        if ($text !== '') {
            $this->notes[] = [
                'reason' => 'described',
                'model'  => $provider['model'],
                'name'   => isset($block['name']) ? (string) $block['name'] : '',
                'chars'  => strlen($text),
            ];
        }
        return $text;
    }

    /**
     * 按平台配置造一个 AI 实例
     *
     * @param array{model: string, platform: string, config: array<string, mixed>} $provider
     * @return AI|null
     */
    protected function makeAi(array $provider)
    {
        if ($this->aiFactory !== null) {
            $ai = call_user_func($this->aiFactory, $provider['model'], $provider['config']);
            return $ai instanceof AI ? $ai : null;
        }

        $config = $provider['config'];
        unset($config['modalities'], $config['capabilities']);
        if ($provider['model'] !== '') {
            $config['model'] = $provider['model'];
        }
        if (!isset($config['platform']) && $provider['platform'] !== '') {
            $config['platform'] = $provider['platform'];
        }

        try {
            return new AI($config);
        } catch (\Throwable $e) {
            $this->notes[] = [
                'reason' => 'provider_init_failed',
                'model'  => $provider['model'],
                'error'  => $e->getMessage(),
            ];
            return null;
        }
    }

    /**
     * 造一个用于 switch 模式的 AI 实例（对外暴露，供 LoopController 使用）
     *
     * @param string $model
     * @return AI|null
     */
    public function providerAi($model)
    {
        $platform = $this->platformOf($model);
        return $this->makeAi([
            'model'    => $model,
            'platform' => $platform,
            'config'   => $this->configFor($model, $platform),
        ]);
    }

    /**
     * @param string $model
     * @return string
     */
    protected function platformOf($model)
    {
        foreach ($this->platformConfigs as $platform => $config) {
            if (is_array($config) && isset($config['model']) && (string) $config['model'] === (string) $model) {
                return (string) $platform;
            }
        }
        // 配置里没写 model 时按模型名推断平台
        $detected = \Ai\Helpers\Protocols::detect((string) $model);
        if ($detected !== null) {
            foreach (array_keys($this->platformConfigs) as $platform) {
                if (strtolower((string) $platform) === strtolower($detected)) {
                    return (string) $platform;
                }
            }
        }
        return '';
    }

    /**
     * @param string $model
     * @param string $platform
     * @return array<string, mixed>
     */
    protected function configFor($model, $platform)
    {
        if ($platform !== '' && isset($this->platformConfigs[$platform])
            && is_array($this->platformConfigs[$platform])
        ) {
            return $this->platformConfigs[$platform];
        }
        // 只配了一个平台时不必写 model 也能对上
        if (count($this->platformConfigs) === 1) {
            $only = reset($this->platformConfigs);
            if (is_array($only)) {
                return $only;
            }
        }
        return [];
    }

    /**
     * @param AI $ai
     * @return string
     */
    protected function familyOfAi(AI $ai)
    {
        $model = $ai->model();
        if ($model === null) {
            return MediaTranslator::FAMILY_OPENAI;
        }
        $protocol = (string) $model->getProtocol();
        return (stripos($protocol, 'claude') !== false || stripos($protocol, 'anthropic') !== false)
            ? MediaTranslator::FAMILY_ANTHROPIC
            : MediaTranslator::FAMILY_OPENAI;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param string $decision
     * @param array<string, bool> $support
     * @param string $provider
     * @return array{messages: array<int, array<string, mixed>>, decision: string,
     *               support: array<string, bool>, provider: string, notes: array<int, array<string, mixed>>}
     */
    protected function result(array $messages, $decision, array $support, $provider)
    {
        return [
            'messages' => $messages,
            'decision' => $decision,
            'support'  => $support,
            'provider' => $provider,
            'notes'    => $this->notes,
        ];
    }
}
