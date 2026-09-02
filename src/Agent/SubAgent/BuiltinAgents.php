<?php
namespace Ai\Agent\SubAgent;

/**
 * BuiltinAgents——内置子 Agent 角色
 *
 * 六个开箱即用的专职角色。它们的价值主要不在提示词，而在**工具集是收窄的**：
 * explorer 拿不到写文件的工具，所以它不可能在"调查代码"的过程中顺手改掉什么；
 * reviewer 同理。这比在提示词里写"请不要修改代码"可靠得多。
 *
 * ```php
 * $sam = new SubAgentManager($ai);
 * $sam->setParentTools($tools);          // 先给父工具集
 * BuiltinAgents::registerAll($sam);      // 六个角色一次装上
 *
 * // 或只装其中几个
 * BuiltinAgents::register($sam, ['explorer', 'tester']);
 * ```
 *
 * 每个角色声明的是**工具名**，实际拿到哪些实例由 `SubAgentManager::resolveTools()`
 * 与父工具集求交后决定——父 Agent 没有 bash，coder 也不会凭空得到 bash。
 */
class BuiltinAgents
{
    const EXPLORER = 'explorer';
    const PLANNER  = 'planner';
    const CODER    = 'coder';
    const TESTER   = 'tester';
    const REVIEWER = 'reviewer';
    const DEBUGGER = 'debugger';

    /** @var array<string, array<string, mixed>> 角色名 => 配置 */
    protected static $definitions = [
        self::EXPLORER => [
            'description' => '代码搜索、阅读、调查与分析：在大代码库里定位实现、找调用方、理清依赖关系',
            'toolNames'   => ['read_file', 'grep', 'glob', 'code_index'],
            'prompt'      => '你是代码调查员。任务是「找到并读懂」，不是「改」——你没有写文件的工具。'
                . "\n输出要点：相关文件与行号、关键实现逻辑、调用关系、你没能确认的部分。"
                . "\n先用 code_index 查结构与调用关系，再用 glob/grep 缩小范围，"
                . "最后才读文件——不要一上来就整份读。",
            'maxTurns'    => 20,
        ],
        self::PLANNER => [
            'description' => '执行计划生成与任务拆解：把复杂目标拆成有序、可验证的步骤',
            'toolNames'   => ['read_file', 'grep', 'glob', 'code_index'],
            'prompt'      => '你是技术负责人。先看清现状，再把目标拆成有序步骤。'
                . "\n每一步要可执行、可验证、有明确产出；标出步骤之间的依赖与风险点。"
                . "\n不要在计划里写「看情况」这类无法执行的步骤。",
            'maxTurns'    => 15,
        ],
        self::CODER => [
            'description' => '代码修改：实现功能、修复缺陷、按计划改动代码',
            'toolNames'   => ['read_file', 'write_file', 'edit_file', 'grep', 'glob', 'bash'],
            'prompt'      => '你是开发工程师。改之前先读懂上下文，改完自己验证一遍。'
                . "\n遵循项目既有写法，不要引入与周围代码风格冲突的新写法。"
                . "\n不确定需求时说出来，不要猜着实现。",
            'maxTurns'    => 25,
        ],
        self::TESTER => [
            'description' => '运行测试与分析失败：补测试用例、执行测试、定位失败原因',
            'toolNames'   => ['read_file', 'grep', 'glob', 'bash'],
            'prompt'      => '你是测试工程师。补用例、跑测试、分析失败。'
                . "\n重点覆盖边界与错误分支。发现问题时给出复现步骤与实际/期望结果，"
                . "\n不要自己去改实现代码——你没有写业务代码的职责。",
            'maxTurns'    => 20,
        ],
        self::REVIEWER => [
            'description' => '代码审查与安全审查：看正确性、可读性、安全问题',
            'toolNames'   => ['read_file', 'grep', 'glob', 'bash', 'code_index'],
            'prompt'      => '你是代码审查者。看正确性、可读性、是否符合项目既有写法，'
                . "并检查注入、越权、路径穿越、命令执行、敏感信息泄露等安全问题。"
                . "\n每条结论指出具体文件与行号，给出具体改法；没有实际可利用性的安全问题不要报。",
            'maxTurns'    => 20,
        ],
        self::DEBUGGER => [
            'description' => '错误分析与问题定位：从报错、日志、堆栈追到根因',
            'toolNames'   => ['read_file', 'grep', 'glob', 'bash', 'code_index'],
            'prompt'      => '你是排障工程师。从现象追到根因，不要停在"加个 try/catch 就好了"。'
                . "\n先复现，再定位，最后说明根因与建议改法；说不准根因时要明确讲出还缺什么信息。",
            'maxTurns'    => 20,
        ],
    ];

    /**
     * 全部内置角色名
     *
     * @return string[]
     */
    public static function names()
    {
        return array_keys(self::$definitions);
    }

    /**
     * 取一个角色的配置
     *
     * @param string $name
     * @return array<string, mixed>|null
     */
    public static function config($name)
    {
        $name = (string) $name;
        return isset(self::$definitions[$name]) ? self::$definitions[$name] : null;
    }

    /**
     * 全部角色配置
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all()
    {
        return self::$definitions;
    }

    /**
     * 把内置角色注册到 SubAgentManager
     *
     * @param SubAgentManager $manager
     * @param string[] $only 只注册这几个，空数组表示全部
     * @param array<string, mixed> $overrides 统一覆盖项（如 model、memory 根目录）
     * @return string[] 实际注册的角色名
     */
    public static function register(SubAgentManager $manager, array $only = [], array $overrides = [])
    {
        $registered = [];
        $parentTools = $manager->getParentTools();

        foreach (self::$definitions as $name => $config) {
            if ($only && !in_array($name, $only, true)) {
                continue;
            }

            $tools = [];
            foreach ($config['toolNames'] as $toolName) {
                if (isset($parentTools[$toolName])) {
                    $tools[$toolName] = $parentTools[$toolName];
                }
            }

            $manager->register($name, array_merge([
                'description' => $config['description'],
                'prompt'      => $config['prompt'],
                'tools'       => $tools,
                'maxTurns'    => $config['maxTurns'],
            ], $overrides));
            $registered[] = $name;
        }
        return $registered;
    }

    /**
     * 注册全部内置角色
     *
     * @param SubAgentManager $manager
     * @param array<string, mixed> $overrides
     * @return string[]
     */
    public static function registerAll(SubAgentManager $manager, array $overrides = [])
    {
        return self::register($manager, [], $overrides);
    }

    /**
     * 某个角色声明的工具名
     *
     * @param string $name
     * @return string[]
     */
    public static function toolNames($name)
    {
        $config = self::config($name);
        return $config === null ? [] : $config['toolNames'];
    }

    /**
     * 该角色是不是只读的（拿不到任何写工具）
     *
     * @param string $name
     * @return bool
     */
    public static function isReadOnly($name)
    {
        $tools = self::toolNames($name);
        foreach (['write_file', 'edit_file'] as $writer) {
            if (in_array($writer, $tools, true)) {
                return false;
            }
        }
        return $tools !== [];
    }
}
