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

// ===== 汇总 =====

echo "\n============================================================\n";
echo ($failed === 0 ? "全部通过" : "{$failed} 个失败") . "：{$passed} 通过，{$failed} 失败\n";
exit($failed === 0 ? 0 : 1);