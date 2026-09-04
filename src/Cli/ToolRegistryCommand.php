<?php
namespace Ai\Cli;

use Ai\Agent\Indexer\IndexResult;
use Ai\Agent\Indexer\ToolIndexer;
use Ai\Agent\Registry\MemoryToolRegistry;
use Ai\Agent\Registry\RegistryException;
use Ai\Agent\Registry\SqliteToolRegistry;
use Ai\Agent\Registry\ToolRegistryInterface;
use Ai\Agent\Registry\ToolSearchContext;
use Ai\Agent\Tool\ToolDefinition;

/**
 * `php-ai` 命令的实现
 *
 * 逻辑放在类里而不是脚本里，理由有二：`bin/php-ai` 那个文件不在 phpstan 的
 * 扫描范围内（只扫 src），逻辑写在那儿等于没有静态检查；测试也没法直接调
 * 一个脚本的内部函数。
 *
 * ```php
 * $code = (new ToolRegistryCommand())->run(['index', '--path=app/Controller']);
 * ```
 *
 * `run()` 返回退出码，不调用 `exit()` —— 调用方（脚本或测试）自己决定怎么用。
 * 输出默认走 STDOUT/STDERR，可以用 `setOutput()` 换成回调以便测试捕获。
 */
class ToolRegistryCommand
{
    /** @var string 默认 Registry 路径（相对当前工作目录） */
    const DEFAULT_DB = '.ai/registry.sqlite';

    /** @var callable|null 输出回调 function(string $text, bool $isError) */
    protected $output = null;

    /** @var bool */
    protected $quiet = false;

    /** @var bool */
    protected $json = false;

    /**
     * @param callable|null $cb function(string $text, bool $isError)
     * @return $this
     */
    public function setOutput($cb)
    {
        $this->output = is_callable($cb) ? $cb : null;
        return $this;
    }

    /**
     * @param string[] $argv 不含脚本名
     * @return int 退出码
     */
    public function run(array $argv)
    {
        $parsed  = $this->parseArgs($argv);
        $options = $parsed['options'];
        $args    = $parsed['args'];

        $this->quiet = !empty($options['quiet']);
        $this->json  = !empty($options['json']);

        $command = isset($args[0]) ? (string) $args[0] : '';

        if ($command === '' || $command === 'help' || !empty($options['help'])) {
            $this->usage();
            return $command === '' && empty($options['help']) ? 2 : 0;
        }

        // 应用自己的 autoloader：不加载它，被扫的类往往连 parent 都找不到
        if (isset($options['bootstrap'])) {
            $boot = (string) $options['bootstrap'];
            if (!is_file($boot)) {
                $this->err('bootstrap 文件不存在: ' . $boot);
                return 2;
            }
            /** @psalm-suppress UnresolvableInclude */
            require_once $boot;
        }

        try {
            switch ($command) {
                case 'index':
                    return $this->cmdIndex($options, $args);
                case 'tools':
                    return $this->cmdTools($options);
                case 'tools:search':
                    return $this->cmdSearch($options, $args);
                case 'tools:show':
                    return $this->cmdShow($options, $args);
                case 'tools:remove':
                    return $this->cmdRemove($options, $args);
                default:
                    $this->err('未知命令: ' . $command);
                    $this->usage();
                    return 2;
            }
        } catch (RegistryException $e) {
            $this->err('错误: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * 解析 `--key=value` / `--flag` / 位置参数
     *
     * `--path` 可以出现多次，值累积成数组。
     *
     * @param string[] $argv
     * @return array{options: array<string, mixed>, args: string[]}
     */
    protected function parseArgs(array $argv)
    {
        $options = [];
        $args    = [];

        foreach ($argv as $item) {
            $item = (string) $item;
            if (strpos($item, '--') !== 0) {
                $args[] = $item;
                continue;
            }
            $body = substr($item, 2);
            $eq   = strpos($body, '=');
            if ($eq === false) {
                $options[$body] = true;
                continue;
            }
            $key   = substr($body, 0, $eq);
            $value = substr($body, $eq + 1);
            if ($key === 'path') {
                if (!isset($options['path']) || !is_array($options['path'])) {
                    $options['path'] = [];
                }
                $options['path'][] = $value;
                continue;
            }
            $options[$key] = $value;
        }

        return ['options' => $options, 'args' => $args];
    }

    /**
     * `php-ai index`
     *
     * @param array<string, mixed> $options
     * @param string[] $args
     * @return int
     */
    protected function cmdIndex(array $options, array $args)
    {
        $config = $this->loadConfig($options);
        $paths  = $this->resolvePaths($options, $config);

        if ($paths === []) {
            $this->err(
                '没有可扫描的路径。用 --path=... 指定，或在 .ai/config.php 里配置 '
                . "agent.index.paths\n示例：\n"
                . "  return ['agent' => ['index' => ['paths' => [__DIR__ . '/app/Controller']]]];"
            );
            return 2;
        }

        $registry = $this->openRegistry($options, $config);
        $indexer  = new ToolIndexer($registry, $this->indexerOptions($config));

        // --check 只比对不写库
        if (!empty($options['check'])) {
            $result = $indexer->check($paths);
            $stale  = $result->isStale();
            if ($this->json) {
                $payload = $result->toArray();
                $payload['stale'] = $stale;
                $this->out((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            } else {
                $this->out($stale
                    ? '索引已过期：' . $result->filesParsed . ' 个文件有变化，'
                        . $result->toolsRemoved . ' 个来源已消失。请运行 php-ai index'
                    : '索引是最新的（' . $result->filesScanned . ' 个文件）');
            }
            return $stale ? 1 : 0;
        }

        if (!empty($options['clear'])) {
            $registry->clear();
            $this->info('已清空 Registry');
        }

        $result = $indexer->scan($paths, ['force' => !empty($options['force'])]);
        $this->reportIndex($result, $registry);

        return $result->hasErrors() && $this->onlyFatalErrors($result) ? 1 : 0;
    }

    /**
     * 「缺 @agent-controller」这类是警告，不该让 CI 挂掉；类载入失败才是真出错
     *
     * @param IndexResult $result
     * @return bool
     */
    protected function onlyFatalErrors(IndexResult $result)
    {
        foreach ($result->errors as $e) {
            if (strpos($e, '缺少 @agent-controller') === false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param IndexResult $result
     * @param ToolRegistryInterface $registry
     * @return void
     */
    protected function reportIndex(IndexResult $result, ToolRegistryInterface $registry)
    {
        if ($this->json) {
            $payload = $result->toArray();
            $payload['total_tools'] = $registry->count(true);
            $this->out((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return;
        }

        $this->info($result->summary());
        foreach ($result->errors as $e) {
            $this->err('  ⚠ ' . $e);
        }
        $this->info('Registry 现有 Tool: ' . $registry->count(true));
    }

    /**
     * `php-ai tools`
     *
     * @param array<string, mixed> $options
     * @return int
     */
    protected function cmdTools(array $options)
    {
        $registry = $this->openRegistry($options, $this->loadConfig($options));
        $tools    = $registry->all(true);

        if ($this->json) {
            $rows = [];
            foreach ($tools as $t) {
                $rows[] = $t->summary();
            }
            $this->out((string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return 0;
        }

        if ($tools === []) {
            $this->out('Registry 里还没有 Tool。先运行 php-ai index');
            return 0;
        }

        $this->out($this->renderTable($tools));
        $this->out('共 ' . count($tools) . ' 个');
        return 0;
    }

    /**
     * `php-ai tools:search "文章 修改"`
     *
     * @param array<string, mixed> $options
     * @param string[] $args
     * @return int
     */
    protected function cmdSearch(array $options, array $args)
    {
        if (!isset($args[1])) {
            $this->err('用法: php-ai tools:search "关键词"');
            return 2;
        }
        $registry = $this->openRegistry($options, $this->loadConfig($options));
        $limit    = isset($options['limit']) ? max(1, (int) $options['limit']) : 20;
        $tools    = $registry->search((string) $args[1], new ToolSearchContext(['limit' => $limit]));

        if ($this->json) {
            $rows = [];
            foreach ($tools as $t) {
                $rows[] = $t->summary();
            }
            $this->out((string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return 0;
        }

        if ($tools === []) {
            $this->out('没有匹配的 Tool');
            return 0;
        }
        $this->out($this->renderTable($tools));
        return 0;
    }

    /**
     * `php-ai tools:show article.update`
     *
     * @param array<string, mixed> $options
     * @param string[] $args
     * @return int
     */
    protected function cmdShow(array $options, array $args)
    {
        if (!isset($args[1])) {
            $this->err('用法: php-ai tools:show <tool-name>');
            return 2;
        }
        $registry = $this->openRegistry($options, $this->loadConfig($options));
        $tool     = $registry->get((string) $args[1]);
        if ($tool === null) {
            $this->err('找不到 Tool: ' . $args[1]);
            return 1;
        }

        if ($this->json) {
            $this->out((string) json_encode($tool->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return 0;
        }

        $lines = [
            '名称:       ' . $tool->getName(),
            '描述:       ' . $tool->getDescription(),
            'Controller: ' . ($tool->getControllerPath() !== '' ? $tool->getControllerPath() : '(未声明，无法执行)'),
            '风险:       ' . $tool->getRisk() . ($tool->requiresConfirmation() ? '（需确认）' : ''),
            '启用:       ' . ($tool->isEnabled() ? '是' : '否'),
            '来源:       ' . $tool->getClassName() . '::' . $tool->getMethodName()
                . ' (' . $tool->getSourceFile() . ':' . $tool->getSourceLine() . ')',
            '参数 Schema:',
            (string) json_encode($tool->schema(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ];
        if ($tool->getReturns() !== '') {
            $lines[] = '返回:       ' . $tool->getReturns();
        }
        $this->out(implode("\n", $lines));
        return 0;
    }

    /**
     * `php-ai tools:remove article.update`
     *
     * @param array<string, mixed> $options
     * @param string[] $args
     * @return int
     */
    protected function cmdRemove(array $options, array $args)
    {
        if (!isset($args[1])) {
            $this->err('用法: php-ai tools:remove <tool-name>');
            return 2;
        }
        $registry = $this->openRegistry($options, $this->loadConfig($options));
        $name     = (string) $args[1];
        if ($registry->get($name) === null) {
            $this->err('找不到 Tool: ' . $name);
            return 1;
        }
        $registry->remove($name);
        $this->out('已删除: ' . $name);
        return 0;
    }

    /**
     * @param ToolDefinition[] $tools
     * @return string
     */
    protected function renderTable(array $tools)
    {
        $lines = [];
        foreach ($tools as $t) {
            $flag = $t->isEnabled() ? ' ' : '✗';
            $lines[] = sprintf(
                '%s %-28s %-9s %-24s %s',
                $flag,
                $t->getName(),
                $t->getRisk(),
                $t->getControllerPath() !== '' ? $t->getControllerPath() : '-',
                $this->truncate($t->getDescription(), 48)
            );
        }
        return implode("\n", $lines);
    }

    /**
     * 按「字符数」截断（中文一个字算一个），避免把 UTF-8 从中间切断
     *
     * @param string $text
     * @param int $max
     * @return string
     */
    protected function truncate($text, $max)
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false || count($chars) <= $max) {
            return $text;
        }
        return implode('', array_slice($chars, 0, $max)) . '…';
    }

    /**
     * 打开 Registry
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $config
     * @return ToolRegistryInterface
     */
    protected function openRegistry(array $options, array $config)
    {
        $db = isset($options['db']) ? (string) $options['db'] : '';
        if ($db === '' && isset($config['db'])) {
            $db = (string) $config['db'];
        }
        if ($db === '') {
            $db = self::DEFAULT_DB;
        }

        if ($db === ':memory:') {
            return new MemoryToolRegistry();
        }
        return new SqliteToolRegistry($db);
    }

    /**
     * 读取配置
     *
     * 探测顺序：`--config=PATH` → `.ai/config.php` → `ai.config.php` →
     * `composer.json` 的 `extra.php-ai.index`。都没有就返回空数组。
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed> 已展开到 agent.index 那一层
     */
    protected function loadConfig(array $options)
    {
        $explicit = isset($options['config']) ? (string) $options['config'] : '';
        if ($explicit !== '') {
            if (!is_file($explicit)) {
                $this->err('配置文件不存在: ' . $explicit);
                return [];
            }
            return $this->extractIndexConfig($this->includeConfig($explicit));
        }

        foreach (['.ai/config.php', 'ai.config.php'] as $candidate) {
            if (is_file($candidate)) {
                return $this->extractIndexConfig($this->includeConfig($candidate));
            }
        }

        if (is_file('composer.json')) {
            $raw = (string) @file_get_contents('composer.json');
            $json = json_decode($raw, true);
            if (is_array($json) && isset($json['extra']['php-ai']['index'])
                && is_array($json['extra']['php-ai']['index'])
            ) {
                return $json['extra']['php-ai']['index'];
            }
        }

        return [];
    }

    /**
     * @param string $file
     * @return mixed
     */
    protected function includeConfig($file)
    {
        /** @psalm-suppress UnresolvableInclude */
        return require $file;
    }

    /**
     * 从应用配置里取出 agent.index 那一段
     *
     * 同时接受两种写法：完整的 `['agent' => ['index' => [...]]]`，
     * 或者直接就是 `['paths' => [...]]`。
     *
     * @param mixed $config
     * @return array<string, mixed>
     */
    protected function extractIndexConfig($config)
    {
        if (!is_array($config)) {
            return [];
        }
        if (isset($config['agent']['index']) && is_array($config['agent']['index'])) {
            return $config['agent']['index'];
        }
        if (isset($config['index']) && is_array($config['index'])) {
            return $config['index'];
        }
        return $config;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $config
     * @return string[]
     */
    protected function resolvePaths(array $options, array $config)
    {
        $paths = [];
        if (isset($options['path']) && is_array($options['path'])) {
            foreach ($options['path'] as $p) {
                $paths[] = (string) $p;
            }
        }
        if ($paths === [] && isset($config['paths']) && is_array($config['paths'])) {
            foreach ($config['paths'] as $p) {
                $paths[] = (string) $p;
            }
        }
        return $paths;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function indexerOptions(array $config)
    {
        $out = [];
        if (isset($config['excludes']) && is_array($config['excludes'])) {
            $out['excludes'] = $config['excludes'];
        }
        if (isset($config['extensions']) && is_array($config['extensions'])) {
            $out['extensions'] = $config['extensions'];
        }
        return $out;
    }

    /** @return void */
    protected function usage()
    {
        $this->out(
            "php-ai —— Agent Tool 索引与查询\n\n"
            . "用法:\n"
            . "  php-ai index [--path=DIR]...     扫描并写入 Registry\n"
            . "  php-ai index --clear             先清空再全量扫描\n"
            . "  php-ai index --check             只检查是否需要重建（过期时退出码 1）\n"
            . "  php-ai index --force             忽略文件 hash，全部重扫\n"
            . "  php-ai tools                     列出全部 Tool\n"
            . "  php-ai tools:search \"文章 修改\"   搜索 Tool\n"
            . "  php-ai tools:show NAME           打印某个 Tool 的完整定义\n"
            . "  php-ai tools:remove NAME         从 Registry 删除某个 Tool\n\n"
            . "选项:\n"
            . "  --db=PATH          Registry 文件，默认 " . self::DEFAULT_DB . "\n"
            . "  --config=PATH      配置文件，默认探测 .ai/config.php → ai.config.php → composer.json\n"
            . "  --bootstrap=PATH   先 require 这个文件（应用自己的 autoloader）\n"
            . "  --limit=N          tools:search 返回条数\n"
            . "  --json             机器可读输出\n"
            . "  --quiet            只输出错误\n"
            . "  --help             显示本帮助\n"
        );
    }

    /**
     * @param string $text
     * @return void
     */
    protected function out($text)
    {
        $this->write($text, false);
    }

    /**
     * 非 --quiet 时才输出（进度类信息）
     *
     * @param string $text
     * @return void
     */
    protected function info($text)
    {
        if (!$this->quiet) {
            $this->write($text, false);
        }
    }

    /**
     * @param string $text
     * @return void
     */
    protected function err($text)
    {
        $this->write($text, true);
    }

    /**
     * @param string $text
     * @param bool $isError
     * @return void
     */
    protected function write($text, $isError)
    {
        if ($this->output !== null) {
            call_user_func($this->output, $text . "\n", $isError);
            return;
        }
        $stream = $isError ? STDERR : STDOUT;
        if (!defined('STDOUT') || !is_resource($stream)) {
            echo $text . "\n";
            return;
        }
        fwrite($stream, $text . "\n");
    }
}
