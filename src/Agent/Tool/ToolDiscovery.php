<?php
namespace Ai\Agent\Tool;

/**
 * ToolDiscovery——按需发现工具
 *
 * 注册了几十个工具时，把全部定义塞进每一轮请求既费 token 又让模型更难选。
 * 发现机制反过来：初始只给一小撮常用工具 + 一个 `search_tools`，模型需要别的
 * 能力时自己搜出来再启用。
 *
 * ```php
 * $discovery = new ToolDiscovery($registry);
 * $discovery->setAlwaysAvailable(['read_file', 'grep', 'glob']);
 *
 * $agent->setTools($discovery->initialTools());     // 只给常用的 + search_tools
 *
 * // 模型调用：search_tools(query: "database")
 * //   → 返回 sql_query / db_schema，并自动启用
 * ```
 *
 * 搜索是纯本地的关键词匹配（工具名 + 描述），不调模型——为了省 token 而多花一次
 * 模型调用不划算。
 */
class ToolDiscovery
{
    /** @var ToolRegistry 运行时注册表（工具实际从这里被执行） */
    protected $registry;

    /** @var array<string, mixed> 全量工具目录（构造时快照，不随注册表被裁而变） */
    protected $catalog = [];

    /** @var string[] 一开始就给模型的工具 */
    protected $alwaysAvailable = [];

    /** @var array<string, bool> 已经被搜出来启用的工具 */
    protected $activated = [];

    /** @var int 一次搜索最多返回几个 */
    protected $maxResults = 5;

    /** @var ToolGroup|null 分组过滤，停用分组里的工具搜不出来 */
    protected $groups = null;

    /**
     * @param ToolRegistry $registry
     * @param array<string, mixed> $options alwaysAvailable / maxResults / groups
     */
    public function __construct(ToolRegistry $registry, array $options = [])
    {
        $this->registry = $registry;
        // 建的时候把整份目录**快照**下来。原先 search()/activate() 直接查
        // $this->registry，而装配流程紧接着就把那个注册表裁到只剩常用工具——
        // 于是它只能在「已经给了模型的那几个」里搜，搜出来的自然全是已启用的，
        // 渐进披露整个失效。目录得独立于运行时注册表存在
        $this->catalog = $registry->all();
        if (isset($options['alwaysAvailable']) && is_array($options['alwaysAvailable'])) {
            $this->alwaysAvailable = array_values(array_map('strval', $options['alwaysAvailable']));
        }
        if (isset($options['maxResults'])) {
            $this->maxResults = max(1, (int) $options['maxResults']);
        }
        if (isset($options['groups']) && $options['groups'] instanceof ToolGroup) {
            $this->groups = $options['groups'];
        }
    }

    /**
     * 设置一开始就可用的工具
     *
     * @param string[] $names
     * @return $this
     */
    public function setAlwaysAvailable(array $names)
    {
        $this->alwaysAvailable = array_values(array_map('strval', $names));
        return $this;
    }

    /**
     * @param ToolGroup|null $groups
     * @return $this
     */
    public function setGroups($groups)
    {
        $this->groups = $groups instanceof ToolGroup ? $groups : null;
        return $this;
    }

    /**
     * 初始工具集：常用工具 + search_tools
     *
     * @return array<string, mixed>
     */
    public function initialTools()
    {
        $tools = [];
        foreach ($this->alwaysAvailable as $name) {
            $tool = $this->lookup($name);
            if ($tool !== null && $this->allowedByGroup($name)) {
                $tools[$name] = $tool;
            }
        }
        $tools['search_tools'] = $this->searchToolDefinition();
        return $tools;
    }

    /**
     * 当前可用的工具（常用 + 已激活）
     *
     * @return array<string, mixed>
     */
    public function activeTools()
    {
        $tools = $this->initialTools();
        foreach (array_keys($this->activated) as $name) {
            $tool = $this->lookup($name);
            if ($tool !== null && $this->allowedByGroup($name)) {
                $tools[$name] = $tool;
            }
        }
        return $tools;
    }

    /**
     * 搜索工具
     *
     * @param string $query 关键词
     * @return array<int, array{name: string, description: string, score: float}>
     */
    public function search($query)
    {
        $tokens = $this->tokenize((string) $query);
        if (!$tokens) {
            return [];
        }

        $scored = [];
        foreach ($this->catalog as $name => $tool) {
            $name = (string) $name;
            if ($name === 'search_tools' || !$this->allowedByGroup($name)) {
                continue;
            }

            // 目录里存的是工具对象，但 registerAll 也接受旧格式的数组定义，
            // 先确认是对象再调方法
            $description = is_object($tool) && method_exists($tool, 'description')
                ? (string) $tool->description()
                : '';
            $haystack = $this->normalize($name . ' ' . $description);

            $score = 0.0;
            foreach ($tokens as $token) {
                if (strpos($haystack, $token) !== false) {
                    $score += 1.0;
                }
            }
            // 名字直接命中权重更高
            if (strpos($this->normalize($name), $this->normalize((string) $query)) !== false) {
                $score += 2.0;
            }
            if ($score <= 0) {
                continue;
            }
            $scored[] = ['name' => $name, 'description' => $description, 'score' => $score];
        }

        usort($scored, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return strcmp($a['name'], $b['name']);
            }
            return $a['score'] > $b['score'] ? -1 : 1;
        });

        return array_slice($scored, 0, $this->maxResults);
    }

    /**
     * 启用一个工具
     *
     * @param string $name
     * @return bool 工具不存在或被分组挡住返回 false
     */
    public function activate($name)
    {
        $name = (string) $name;
        if ($this->lookup($name) === null || !$this->allowedByGroup($name)) {
            return false;
        }
        $this->activated[$name] = true;
        return true;
    }

    /**
     * 停用一个已激活的工具
     *
     * @param string $name
     * @return $this
     */
    public function deactivate($name)
    {
        unset($this->activated[(string) $name]);
        return $this;
    }

    /**
     * 已激活的工具名
     *
     * @return string[]
     */
    public function activated()
    {
        return array_keys($this->activated);
    }

    /**
     * search_tools 工具定义——注册给模型用
     *
     * @return array<string, mixed>
     */
    public function searchToolDefinition()
    {
        $self = $this;
        return [
            'description'  => '按关键词搜索当前未启用的工具（如 "database"、"浏览器"、"部署"）。'
                . '找到后会自动启用，之后就能直接调用。工具很多时用它，不必一开始全部加载。',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => '要找什么能力的工具',
                    ],
                ],
                'required' => ['query'],
            ],
            'handler' => function (array $input) use ($self) {
                $query = isset($input['query']) ? (string) $input['query'] : '';
                if (trim($query) === '') {
                    return 'ERROR: 需要 query 参数';
                }

                $hits = $self->search($query);
                if (!$hits) {
                    return '没有找到与「' . $query . '」相关的工具';
                }

                $lines = ['找到以下工具（已自动启用，可直接调用）：'];
                foreach ($hits as $hit) {
                    $self->activate($hit['name']);
                    $lines[] = '- ' . $hit['name'] . '：' . $hit['description'];
                }
                return implode("\n", $lines);
            },
        ];
    }

    /**
     * 从目录里取工具，取不到再退回运行时注册表
     *
     * 退回这一步留给「建好 discovery 之后又注册进来的工具」——MCP 工具就是
     * 连上服务器才知道有哪些。
     *
     * @param string $name
     * @return mixed|null
     */
    protected function lookup($name)
    {
        $name = (string) $name;
        if (isset($this->catalog[$name])) {
            return $this->catalog[$name];
        }
        return $this->registry->get($name);
    }

    /**
     * 把新工具补进目录（运行时才接进来的，如 MCP）
     *
     * @param array<string, mixed> $tools
     * @return $this
     */
    public function addToCatalog(array $tools)
    {
        foreach ($tools as $name => $tool) {
            $this->catalog[(string) $name] = $tool;
        }
        return $this;
    }

    /**
     * 全量目录里的工具名
     *
     * @return string[]
     */
    public function catalogNames()
    {
        return array_keys($this->catalog);
    }

    /**
     * 分组允许这个工具吗
     *
     * @param string $name
     * @return bool
     */
    protected function allowedByGroup($name)
    {
        return $this->groups === null || $this->groups->isEnabled($name);
    }

    /**
     * @param string $text
     * @return string[]
     */
    protected function tokenize($text)
    {
        $text = $this->normalize($text);
        $tokens = [];

        if (preg_match_all('/[a-z0-9_]{2,}/u', $text, $m)) {
            foreach ($m[0] as $word) {
                $tokens[] = $word;
            }
        }
        if (preg_match_all('/[\x{4e00}-\x{9fff}]+/u', $text, $m)) {
            foreach ($m[0] as $run) {
                $chars = preg_split('//u', $run, -1, PREG_SPLIT_NO_EMPTY);
                if ($chars === false) {
                    continue;
                }
                $count = count($chars);
                if ($count === 1) {
                    $tokens[] = $chars[0];
                    continue;
                }
                for ($i = 0; $i < $count - 1; $i++) {
                    $tokens[] = $chars[$i] . $chars[$i + 1];
                }
            }
        }
        return array_values(array_unique($tokens));
    }

    /**
     * @param string $text
     * @return string
     */
    protected function normalize($text)
    {
        $text = (string) $text;
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }
}
