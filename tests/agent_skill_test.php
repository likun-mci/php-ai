<?php
/**
 * SkillManager 测试
 *
 * 覆盖：
 *   1. SkillDefinition 值对象
 *   2. SkillManager 注册 / 查询
 *   3. frontmatter 解析（标量 + 列表）
 *   4. 从目录加载 SKILL.md
 *   5. toSystemPrompt 只给名称描述
 *   6. useSkill 加载完整内容并激活
 *   7. use_skill 工具 schema / handler
 *   8. 集成到 AgentRuntime / Agent
 *
 * 运行：php tests/agent_skill_test.php
 */

require __DIR__ . '/../autoload.php';

use Ai\AI;
use Ai\Agent\Agent;
use Ai\Agent\Skill\SkillManager;
use Ai\Agent\Skill\SkillDefinition;
use Ai\Agent\Skill\SkillResourceException;
use Ai\Agent\Tool\ToolContext;

$passed = 0;
$failed = 0;

function test($name, $ok)
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "✓ {$name}\n";
    } else {
        $failed++;
        echo "✗ {$name}\n";
    }
}

function assert_eq($name, $expected, $actual)
{
    test($name, $expected === $actual);
}

/**
 * 读附件并返回错误消息（成功则返回空串）
 *
 * @param SkillManager $sm
 * @param string       $skill
 * @param string       $rel
 * @return string
 */
function readResourceError($sm, $skill, $rel)
{
    try {
        $sm->readResource($skill, $rel);
    } catch (SkillResourceException $e) {
        return $e->getMessage();
    }
    return '';
}

// ===== 1. SkillDefinition 值对象 =====

echo "=== 一、SkillDefinition 值对象 ===\n";

$skill = new SkillDefinition([
    'name'         => 'deploy',
    'description'  => '部署项目到生产环境',
    'content'      => "# 部署流程\n\n1. 构建",
    'allowedTools' => ['Bash(git *)', 'Bash(docker *)'],
]);
assert_eq('名称', 'deploy', $skill->getName());
assert_eq('描述', '部署项目到生产环境', $skill->getDescription());
assert_eq('内容', "# 部署流程\n\n1. 构建", $skill->getContent());
assert_eq('允许工具数量', 2, count($skill->getAllowedTools()));
test('有内容时 isLoaded 为 true', $skill->isLoaded());
test('默认未激活', !$skill->isActive());

$skill->setActive(true);
test('setActive 后激活', $skill->isActive());

$skill->setContent('新内容');
test('setContent 更新内容', $skill->getContent() === '新内容');

assert_eq('描述行', '- deploy: 部署项目到生产环境', $skill->toDescriptionLine());

// ===== 2. SkillManager 注册 / 查询 =====

echo "\n=== 二、SkillManager 注册 / 查询 ===\n";

$sm = new SkillManager();
$sm->register('deploy', [
    'description' => '部署项目',
    'content'     => '部署步骤...',
]);
$sm->register('seo', [
    'description' => 'SEO 优化',
    'content'     => 'SEO 步骤...',
]);
$sm->register('php', [
    'description' => 'PHP 开发规范',
    'content'     => 'PHP 规范...',
]);

test('count 返回 3', $sm->count() === 3);
test('has deploy', $sm->has('deploy'));
test('has 不存在的返回 false', !$sm->has('wordpress'));
test('get 返回技能', $sm->get('deploy') !== null);
test('get 不存在返回 null', $sm->get('nope') === null);
assert_eq('all 数量', 3, count($sm->all()));

// ===== 3. frontmatter 解析 =====

echo "\n=== 三、frontmatter 解析 ===\n";

$parsed = SkillManager::parseFrontmatter("---\nname: deploy\ndescription: 部署项目\nallowed-tools:\n  - Bash(git *)\n  - Bash(docker *)\n---\n# 部署流程\n步骤");
assert_eq('解析出 name', 'deploy', $parsed['meta']['name']);
assert_eq('解析出 description', '部署项目', $parsed['meta']['description']);
assert_eq('解析出 allowed-tools 数量', 2, count($parsed['meta']['allowed-tools']));
assert_eq('allowed-tools 第一项', 'Bash(git *)', $parsed['meta']['allowed-tools'][0]);
assert_eq('正文剥离 frontmatter', "# 部署流程\n步骤", $parsed['content']);

// 无 frontmatter
$parsed2 = SkillManager::parseFrontmatter("# 只有正文\n没有元数据");
assert_eq('无 frontmatter 时 meta 为空', 0, count($parsed2['meta']));
assert_eq('无 frontmatter 时正文原样', "# 只有正文\n没有元数据", $parsed2['content']);

// 标量 + 空列表键
$parsed3 = SkillManager::parseFrontmatter("---\nname: x\n---\n正文");
assert_eq('标量键解析', 'x', $parsed3['meta']['name']);
assert_eq('空列表键不产生条目', 1, count($parsed3['meta']));

// ===== 4. 从目录加载 =====

echo "\n=== 四、从目录加载 SKILL.md ===\n";

$tmpRoot = sys_get_temp_dir() . '/skill_test_' . uniqid();
mkdir($tmpRoot . '/wordpress', 0777, true);
mkdir($tmpRoot . '/seo', 0777, true);
mkdir($tmpRoot . '/php', 0777, true);

file_put_contents($tmpRoot . '/wordpress/SKILL.md', "---\nname: wordpress\ndescription: WordPress 插件开发\n---\n# WP 插件\n教程正文");
file_put_contents($tmpRoot . '/seo/SKILL.md', "---\nname: seo\ndescription: 搜索引擎优化\nallowed-tools:\n  - Bash(curl *)\n---\n# SEO\n步骤正文");
file_put_contents($tmpRoot . '/php/SKILL.md', "---\nname: php\ndescription: PHP 编码规范\n---\n# PHP\n规范正文");
// 无 SKILL.md 的子目录应被跳过
mkdir($tmpRoot . '/noskill', 0777, true);
file_put_contents($tmpRoot . '/noskill/README.md', '不是技能');

$smDir = new SkillManager();
$smDir->loadFromDir($tmpRoot);
test('加载 3 个技能', $smDir->count() === 3);
test('wordpress 已加载', $smDir->has('wordpress'));
test('seo 已加载', $smDir->has('seo'));
test('php 已加载', $smDir->has('php'));
test('noskill 被跳过', !$smDir->has('noskill'));

$wp = $smDir->get('wordpress');
assert_eq('wordpress 描述', 'WordPress 插件开发', $wp->getDescription());
test('wordpress 正文已加载', $wp->getContent() !== '');

$seo = $smDir->get('seo');
assert_eq('seo allowed-tools', 1, count($seo->getAllowedTools()));
assert_eq('seo 路径', $tmpRoot . '/seo/SKILL.md', $seo->getPath());

// 加载不存在的目录
$smDir->loadFromDir('/nonexistent_dir_xyz');
test('加载不存在目录不报错', $smDir->count() === 3);

// ===== 5. toSystemPrompt 只给名称描述 =====

echo "\n=== 五、toSystemPrompt 只给名称描述 ===\n";

$prompt = $sm->toSystemPrompt();
test('提示词包含 deploy 名称', strpos($prompt, 'deploy') !== false);
test('提示词包含 deploy 描述', strpos($prompt, '部署项目') !== false);
test('提示词不含完整内容', strpos($prompt, '部署步骤...') === false);

$smDisabled = new SkillManager();
$smDisabled->register('x', ['description' => 'X']);
$smDisabled->setEnabled(false);
test('停用后 toSystemPrompt 为空', $smDisabled->toSystemPrompt() === '');

// ===== 6. useSkill 加载完整内容并激活 =====

echo "\n=== 六、useSkill 加载完整内容并激活 ===\n";

$content = $sm->useSkill('deploy');
assert_eq('useSkill 返回完整内容', '部署步骤...', $content);
test('useSkill 后技能激活', $sm->get('deploy')->isActive());
test('activeSkills 包含 deploy', isset($sm->activeSkills()['deploy']));
test('activeSkills 不包含 seo', !isset($sm->activeSkills()['seo']));

// 从目录加载的技能，useSkill 读取文件
$content2 = $smDir->useSkill('wordpress');
test('useSkill 从文件加载正文', strpos($content2, '# WP 插件') !== false);

// 激活 seo 后收集其 allowed-tools
$smDir->useSkill('seo');
test('seo 激活后收集 allowed-tools', in_array('Bash(curl *)', $smDir->getAllowedTools(), true));
test('wordpress 无 allowed-tools 不影响收集', count($smDir->getAllowedTools()) === 1);

// useSkill 不存在的技能
assert_eq('useSkill 不存在返回空', '', $sm->useSkill('nonexistent'));

// ===== 7. use_skill 工具 schema / handler =====

echo "\n=== 七、use_skill 工具 schema / handler ===\n";

$schema = $sm->getUseSkillToolSchema();
assert_eq('schema 名称', 'use_skill', $schema['name']);
test('schema 含技能枚举', isset($schema['input_schema']['properties']['skill']['enum']));
assert_eq('schema 枚举含 deploy', true, in_array('deploy', $schema['input_schema']['properties']['skill']['enum'], true));

$handler = $sm->getUseSkillHandler();
test('handler 是可调用', is_callable($handler));
$result = $handler(['skill' => 'deploy']);
assert_eq('handler 加载 deploy', '部署步骤...', $result);
$result2 = $handler(['skill' => 'missing']);
test('handler 对不存在技能报错', strpos($result2, 'ERROR') !== false);
$result3 = $handler([]);
test('handler 空输入报错', strpos($result3, 'ERROR') !== false);

// ===== 8. 集成到 AgentRuntime / Agent =====

echo "\n=== 八、集成到 AgentRuntime / Agent ===\n";

$ai = new AI();
$ai->setConfig([
    'model'      => 'deepseek-anthropic',
    'api_key'    => 'sk-test',
    'max_tokens' => 1024,
]);

$agent = new Agent($ai);
$agent->setSkillManager($sm);
$runtime = $agent->getRuntime();
test('AgentRuntime 已设置技能管理器', $runtime->getSkillManager() !== null);
assert_eq('getSkillManager 返回同一实例', true, $runtime->getSkillManager() === $sm);

// 从目录加载的快捷方式
$agent2 = new Agent($ai);
$agent2->loadSkills($tmpRoot);
$sm2 = $agent2->getRuntime()->getSkillManager();
test('loadSkills 创建了 SkillManager', $sm2 !== null);
assert_eq('loadSkills 加载数量', 3, $sm2->count());

// 清理临时目录
@unlink($tmpRoot . '/wordpress/SKILL.md');
@unlink($tmpRoot . '/seo/SKILL.md');
@unlink($tmpRoot . '/php/SKILL.md');
@unlink($tmpRoot . '/noskill/README.md');
@rmdir($tmpRoot . '/wordpress');
@rmdir($tmpRoot . '/seo');
@rmdir($tmpRoot . '/php');
@rmdir($tmpRoot . '/noskill');
@rmdir($tmpRoot);

// ===== 9. 技能附件：目录与清单 =====

echo "\n=== 九、技能附件：目录与清单 ===\n";

$resRoot = sys_get_temp_dir() . '/skill_res_' . uniqid();
mkdir($resRoot . '/demo/references/core', 0777, true);
mkdir($resRoot . '/demo/scripts', 0777, true);
mkdir($resRoot . '/demo/.git', 0777, true);
file_put_contents($resRoot . '/demo/SKILL.md', "---\nname: demo\ndescription: 附件演示\n---\n# demo 正文\n见 references/core/06-luxe.md");
file_put_contents($resRoot . '/demo/README.md', 'README');
file_put_contents($resRoot . '/demo/references/01-overview.md', 'A');
file_put_contents($resRoot . '/demo/references/core/06-luxe.md', '流派正文');
file_put_contents($resRoot . '/demo/scripts/run.sh', str_repeat('x', 100));
file_put_contents($resRoot . '/demo/.git/config', 'git 元数据，不该出现在清单里');
file_put_contents($resRoot . '/demo/blob.dat', "abc\0def");

$smRes = new SkillManager();
$smRes->loadFromDir($resRoot);
$demo = $smRes->get('demo');

assert_eq('技能目录', $resRoot . '/demo', $demo->getDir());
$list = $demo->getResources();
assert_eq('附件数量', 5, count($list));
test('清单已排序', $list === ['README.md', 'blob.dat', 'references/01-overview.md', 'references/core/06-luxe.md', 'scripts/run.sh']);
test('清单不含 SKILL.md', !in_array('SKILL.md', $list, true));
test('清单不含隐藏目录内容', !in_array('.git/config', $list, true));
test('清单含多级子目录', in_array('references/core/06-luxe.md', $list, true));
test('hasResources 为 true', $demo->hasResources());
test('清单走缓存（返回值相等）', $demo->getResources() === $list);

// 体积上限：超过的不列（清单与读取同一份上限，免得「列了却读不了」）
$demo->setResourceMaxBytes(50);
test('超限文件不进清单', !in_array('scripts/run.sh', $demo->getResources(), true));
test('未超限文件仍在清单', in_array('references/01-overview.md', $demo->getResources(), true));
$demo->setResourceMaxBytes(1048576);
assert_eq('恢复上限后清单复原', 5, count($demo->getResources()));

// 只有文件名、没有目录的 path 不该把当前目录当成技能目录
$noDir = new SkillDefinition(['name' => 'x', 'path' => 'SKILL.md']);
assert_eq('无目录 path 的 dir 为空', '', $noDir->getDir());
assert_eq('无目录时清单为空', [], $noDir->getResources());
$deriveDir = new SkillDefinition(['name' => 'y', 'path' => '/tmp/skills/y/SKILL.md']);
assert_eq('dir 从 path 推导', '/tmp/skills/y', $deriveDir->getDir());

// ===== 10. use_skill 返回里附上附件清单 =====

echo "\n=== 十、use_skill 返回里的附件清单 ===\n";

$resHandler = $smRes->getUseSkillHandler();
$out = $resHandler(['skill' => 'demo']);
test('返回含正文', strpos($out, '# demo 正文') !== false);
test('返回含技能目录', strpos($out, '技能目录：' . $resRoot . '/demo') !== false);
test('返回含附件相对路径', strpos($out, '- references/core/06-luxe.md') !== false);
test('返回提示用 read_resource 读', strpos($out, 'read_resource') !== false);
test('清单块有闭合标签', strpos($out, '</skill-resources>') !== false);

// 关掉开关 → 回到「只有正文」的旧行为
$smRes->setResourceListVisible(false);
test('关掉清单开关后不附清单', strpos($resHandler(['skill' => 'demo']), '<skill-resources>') === false);
$smRes->setResourceListVisible(true);
test('重新打开后清单回来', strpos($resHandler(['skill' => 'demo']), '<skill-resources>') !== false);

// 清单条数上限
$smRes->setResourceListLimit(2);
$limited = $smRes->resourceBlock('demo');
assert_eq('清单最多列 2 条', 2, substr_count($limited, "\n- "));
test('超出部分只报个数', strpos($limited, '另有 3 个文件未列出') !== false);
$smRes->setResourceListLimit(200);

// 手工注册（无目录）的技能不附清单
assert_eq('手工注册技能返回纯正文', '部署步骤...', $sm->getUseSkillHandler()(['skill' => 'deploy']));

// ===== 11. readResource：读附件与边界 =====

echo "\n=== 十一、readResource ===\n";

assert_eq('读嵌套附件', '流派正文', $smRes->readResource('demo', 'references/core/06-luxe.md'));
assert_eq('./ 前缀正常解析', '流派正文', $smRes->readResource('demo', './references/core/06-luxe.md'));

test('越界 .. 被拒', readResourceError($smRes, 'demo', '../SKILL.md') !== '');
test('.. 报错说清原因', strpos(readResourceError($smRes, 'demo', '../SKILL.md'), '「..」') !== false);
test('绝对路径被拒', strpos(readResourceError($smRes, 'demo', '/etc/passwd'), '相对路径') !== false);
test('协议前缀被拒', strpos(readResourceError($smRes, 'demo', 'file:///etc/passwd'), '相对路径') !== false);
test('隐藏项被拒', strpos(readResourceError($smRes, 'demo', '.git/config'), '隐藏项') !== false);
test('空路径被拒', strpos(readResourceError($smRes, 'demo', ''), '不能为空') !== false);
test('技能不存在被拒', strpos(readResourceError($smRes, 'nope', 'a.md'), '不存在') !== false);
test('附件不存在被拒', strpos(readResourceError($smRes, 'demo', 'references/none.md'), '附件不存在') !== false);
test('手工注册技能无附件被拒', strpos(readResourceError($sm, 'deploy', 'a.md'), '没有附带文件') !== false);
test('二进制文件被拒', strpos(readResourceError($smRes, 'demo', 'blob.dat'), '不是文本文件') !== false);

// 软链接指向技能目录之外：realpath 校验必须拦住（防「链接进去读」这类逃逸）
if (@symlink('/etc', $resRoot . '/demo/outside_link')) {
    test('软链接逃逸被拒', strpos(readResourceError($smRes, 'demo', 'outside_link/passwd'), '超出技能目录范围') !== false);
    @unlink($resRoot . '/demo/outside_link');
} else {
    test('软链接逃逸被拒（创建软链接失败，跳过）', true);
}

$smRes->setResourceMaxBytes(10);
test('超过读取上限被拒', strpos(readResourceError($smRes, 'demo', 'scripts/run.sh'), '过大') !== false);
$smRes->setResourceMaxBytes(1048576);
assert_eq('恢复上限后可读', str_repeat('x', 100), $smRes->readResource('demo', 'scripts/run.sh'));

// ===== 12. read_resource 工具 + 运行时自动注册 =====

echo "\n=== 十二、read_resource 工具与自动注册 ===\n";

$rrSchema = $smRes->getReadResourceToolSchema();
assert_eq('read_resource schema 名称', 'read_resource', $rrSchema['name']);
assert_eq('read_resource required', ['skill', 'resource'], $rrSchema['input_schema']['required']);

$rrHandler = $smRes->getReadResourceHandler();
assert_eq('handler 读附件', '流派正文', $rrHandler(['skill' => 'demo', 'resource' => 'references/core/06-luxe.md']));
test('handler 参数缺失报 ERROR', strpos($rrHandler(['skill' => 'demo']), 'ERROR') === 0);
test('handler 越界报 ERROR', strpos($rrHandler(['skill' => 'demo', 'resource' => '../SKILL.md']), 'ERROR') === 0);

// 技能名 enum 开关：技能上百时去掉 enum 省每次调用的固定开销
test('默认带 enum', isset($sm->getUseSkillToolSchema()['input_schema']['properties']['skill']['enum']));
$smEnumOff = clone $sm;
$smEnumOff->setEnumInSchema(false);
test('关掉后不带 enum', !isset($smEnumOff->getUseSkillToolSchema()['input_schema']['properties']['skill']['enum']));
test('关掉后 schema 仍要求 skill', $smEnumOff->getUseSkillToolSchema()['input_schema']['required'] === ['skill']);

// 只有「从目录加载」的技能才需要 read_resource
test('有目录技能 → hasResourceSkills', $smRes->hasResourceSkills());
test('手工注册技能 → hasResourceSkills 为假', !$sm->hasResourceSkills());

$agentRes = new Agent($ai);
$agentRes->setSkillManager($smRes);
$rtRes = $agentRes->getRuntime();
$register = new ReflectionMethod($rtRes, 'registerSkillTool');
$register->setAccessible(true);
$register->invoke($rtRes);
$reg = $rtRes->getToolRegistry();
test('运行时注册了 use_skill', $reg->has('use_skill'));
test('运行时注册了 read_resource', $reg->has('read_resource'));
$rrResult = $reg->get('read_resource')->execute([
    'skill'    => 'demo',
    'resource' => 'references/core/06-luxe.md',
], new ToolContext(['workdir' => sys_get_temp_dir()]));
assert_eq('read_resource 可直接执行', '流派正文', $rrResult->getContent());

// 没有目录技能时不注册 read_resource
$agentBare = new Agent($ai);
$agentBare->setSkillManager($sm);
$rtBare = $agentBare->getRuntime();
$registerBare = new ReflectionMethod($rtBare, 'registerSkillTool');
$registerBare->setAccessible(true);
$registerBare->invoke($rtBare);
test('无目录技能不注册 read_resource', !$rtBare->getToolRegistry()->has('read_resource'));

// ===== 13. subset：子 Agent 取技能子集 =====

echo "\n=== 十三、subset 技能子集 ===\n";

$smRes->useSkill('demo');   // 先让源技能处于激活状态
test('源技能已激活', $smRes->get('demo')->isActive());
$sub = $smRes->subset(['demo', 'nope']);
test('子集保留选中的技能', $sub->has('demo'));
test('子集跳过不存在的技能', !$sub->has('nope'));
assert_eq('子集数量', 1, $sub->count());
test('子集不继承激活状态', !$sub->get('demo')->isActive());
test('子集保留正文', strpos($sub->get('demo')->getContent(), '# demo 正文') !== false);
test('子集保留附件目录', $sub->get('demo')->getDir() === $resRoot . '/demo');
assert_eq('子集能读附件', '流派正文', $sub->readResource('demo', 'references/core/06-luxe.md'));
assert_eq('子集允许工具为空', [], $sub->getAllowedTools());

// 子类扩展（如宿主自建索引）不能被降级回基类
class TestSkillLibrary extends SkillManager
{
    /** @var string 子类自己的状态，subset 后应保留 */
    public $tag = 'lib';

    /** @return string */
    public function extra()
    {
        return $this->tag;
    }
}

$lib = new TestSkillLibrary();
$lib->loadFromDir($resRoot);
$libSub = $lib->subset(['demo']);
assert_eq('subset 保留子类', 'TestSkillLibrary', get_class($libSub));
assert_eq('subset 保留子类属性', 'lib', $libSub->extra());

// 源管理器不受子集影响
test('子集新增技能不影响源', !$smRes->has('extra_skill'));
$sub->register('extra_skill', ['description' => 'x', 'content' => 'y']);
test('子集可独立注册', $sub->has('extra_skill'));
test('源仍没有该技能', !$smRes->has('extra_skill'));
test('源技能仍处于激活状态', $smRes->get('demo')->isActive());

// 清理临时目录
foreach ([$resRoot . '/demo/references/core/06-luxe.md', $resRoot . '/demo/references/01-overview.md',
          $resRoot . '/demo/scripts/run.sh', $resRoot . '/demo/.git/config',
          $resRoot . '/demo/SKILL.md', $resRoot . '/demo/README.md', $resRoot . '/demo/blob.dat'] as $f) {
    @unlink($f);
}
foreach ([$resRoot . '/demo/references/core', $resRoot . '/demo/references', $resRoot . '/demo/scripts',
          $resRoot . '/demo/.git', $resRoot . '/demo', $resRoot] as $d) {
    @rmdir($d);
}

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);