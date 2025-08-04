/**
 * 现代化帮助系统
 * 
 * 提供详细的帮助文档、常见问题解答和最佳实践指南
 * 
 * @author Frank-Loong
 * @version 2.0.0-beta.1
 */

const chalk = require('chalk');

class HelpSystem {
    /**
     * 显示快速开始指南
     */
    static showQuickStart() {
        console.log(chalk.cyan.bold('\n新手快速开始指南\n'));

        console.log(chalk.blue('第一次使用？按照以下步骤操作：\n'));

        console.log(chalk.blue('1. 创建新项目（如果是新项目）'));
        console.log('   ' + chalk.cyan('ntwp init') + chalk.gray(' - 创建一个新的 WordPress 插件项目'));
        console.log('   ' + chalk.gray('   系统会引导您填写项目信息'));

        console.log(chalk.blue('\n2. 检查项目状态'));
        console.log('   ' + chalk.cyan('ntwp doctor') + chalk.gray(' - 检查项目是否配置正确'));
        console.log('   ' + chalk.gray('   这会告诉您项目是否有问题'));

        console.log(chalk.blue('\n3. 日常开发流程'));
        console.log('   ' + chalk.cyan('ntwp version check') + chalk.gray(' - 检查版本号是否一致'));
        console.log('   ' + chalk.cyan('ntwp validate all') + chalk.gray(' - 检查所有配置是否正确'));
        console.log('   ' + chalk.cyan('ntwp build package') + chalk.gray(' - 打包插件为 ZIP 文件'));

        console.log(chalk.blue('\n4. 版本升级（发布新功能时）'));
        console.log('   ' + chalk.cyan('ntwp version bump patch') + chalk.gray(' - 修复 bug 时使用'));
        console.log('   ' + chalk.cyan('ntwp version bump minor') + chalk.gray(' - 添加新功能时使用'));
        console.log('   ' + chalk.cyan('ntwp version bump major') + chalk.gray(' - 重大更新时使用'));

        console.log(chalk.blue('\n5. 发布到 GitHub'));
        console.log('   ' + chalk.cyan('ntwp release patch --dry-run') + chalk.gray(' - 预览发布（推荐先使用）'));
        console.log('   ' + chalk.cyan('ntwp release patch') + chalk.gray(' - 正式发布到 GitHub'));

        console.log(chalk.yellow('\n小贴士：'));
        console.log('   • 使用 ' + chalk.cyan('ntwp help-guide --faq') + ' 查看常见问题');
        console.log('   • 使用 ' + chalk.cyan('ntwp <命令> --help') + ' 查看具体命令帮助');
        console.log('   • 遇到问题时先运行 ' + chalk.cyan('ntwp doctor') + ' 诊断');
    }

    /**
     * 显示常见问题解答
     */
    static showFAQ() {
        console.log(chalk.cyan.bold('\n❓ 常见问题解答\n'));
        
        const faqs = [
            {
                q: '如何检查项目是否配置正确？',
                a: '运行 `ntwp doctor` 进行全面的项目健康检查，或使用 `ntwp validate all` 验证所有配置。'
            },
            {
                q: '版本号不一致怎么办？',
                a: '运行 `ntwp version check` 检查具体哪些文件版本不一致，然后使用 `ntwp version set <版本号>` 统一设置。'
            },
            {
                q: '构建失败怎么解决？',
                a: '首先运行 `ntwp validate all` 检查配置，然后使用 `ntwp build clean` 清理后重新构建。'
            },
            {
                q: '如何自定义构建配置？',
                a: '编辑 `release.config.js` 文件中的构建配置，或使用 `ntwp config --list` 查看当前配置。'
            },
            {
                q: '如何添加新的文件到构建包？',
                a: '在 `release.config.js` 的 `build.include.files` 或 `build.include.directories` 中添加文件路径。'
            },
            {
                q: 'GitHub Actions 工作流失败怎么办？',
                a: '使用 `ntwp validate github-actions` 验证工作流配置，检查是否有语法错误或配置问题。'
            }
        ];

        faqs.forEach((faq, index) => {
            console.log(chalk.blue(`${index + 1}. ${faq.q}`));
            console.log(chalk.gray(`   ${faq.a}\n`));
        });
    }

    /**
     * 显示最佳实践
     */
    static showBestPractices() {
        console.log(chalk.cyan.bold('\n✨ 最佳实践指南\n'));
        
        console.log(chalk.blue('📝 版本管理最佳实践'));
        console.log('   • 使用语义化版本号 (Semantic Versioning)');
        console.log('   • 发布前始终运行 ' + chalk.cyan('ntwp version check'));
        console.log('   • 重大更改使用 major 版本升级');
        console.log('   • 新功能使用 minor 版本升级');
        console.log('   • 错误修复使用 patch 版本升级');
        
        console.log(chalk.blue('\n📦 构建最佳实践'));
        console.log('   • 构建前运行 ' + chalk.cyan('ntwp validate all'));
        console.log('   • 定期清理构建目录 ' + chalk.cyan('ntwp build clean'));
        console.log('   • 验证构建结果 ' + chalk.cyan('ntwp build verify'));
        console.log('   • 检查构建包大小，避免包含不必要的文件');
        
        console.log(chalk.blue('\n🔍 质量保证最佳实践'));
        console.log('   • 定期运行 ' + chalk.cyan('ntwp doctor') + ' 检查项目健康');
        console.log('   • 使用 ' + chalk.cyan('ntwp validate all') + ' 进行全面验证');
        console.log('   • 发布前进行预览 ' + chalk.cyan('ntwp release <type> --dry-run'));
        console.log('   • 保持依赖更新，定期运行安全扫描');
        
        console.log(chalk.blue('\n🚀 发布最佳实践'));
        console.log('   • 发布前确保所有测试通过');
        console.log('   • 使用 Git 标签管理版本');
        console.log('   • 编写清晰的变更日志');
        console.log('   • 发布后验证 GitHub Release');
    }

    /**
     * 显示故障排除指南
     */
    static showTroubleshooting() {
        console.log(chalk.cyan.bold('\n🔧 故障排除指南\n'));
        
        const issues = [
            {
                problem: '命令执行失败',
                solutions: [
                    '检查 Node.js 版本是否符合要求 (>=16.0.0)',
                    '运行 `npm install` 确保依赖已安装',
                    '使用 `--verbose` 选项查看详细错误信息'
                ]
            },
            {
                problem: '版本检查失败',
                solutions: [
                    '检查所有版本文件是否存在',
                    '验证版本号格式是否正确',
                    '使用 `ntwp version set <版本号>` 统一版本'
                ]
            },
            {
                problem: '构建失败',
                solutions: [
                    '运行 `ntwp validate config` 检查配置',
                    '确保所有必需文件存在',
                    '检查文件权限和路径',
                    '清理构建目录后重试'
                ]
            },
            {
                problem: 'GitHub Actions 失败',
                solutions: [
                    '检查工作流文件语法',
                    '验证环境变量和密钥配置',
                    '查看 Actions 日志获取详细错误信息'
                ]
            }
        ];

        issues.forEach((issue, index) => {
            console.log(chalk.red(`${index + 1}. 问题：${issue.problem}`));
            console.log(chalk.blue('   解决方案：'));
            issue.solutions.forEach(solution => {
                console.log(chalk.gray(`   • ${solution}`));
            });
            console.log();
        });
    }

    /**
     * 显示命令参考
     */
    static showCommandReference() {
        console.log(chalk.cyan.bold('\n📚 命令参考\n'));
        
        const commands = {
            '项目管理': {
                'ntwp init': '初始化新项目',
                'ntwp doctor': '诊断项目健康状况',
                'ntwp info': '显示项目信息',
                'ntwp config --list': '列出当前配置'
            },
            '版本管理': {
                'ntwp version check': '检查版本一致性',
                'ntwp version bump <type>': '升级版本号',
                'ntwp version set <version>': '设置自定义版本号'
            },
            '构建工具': {
                'ntwp build package': '构建插件包',
                'ntwp build clean': '清理构建目录',
                'ntwp build verify': '验证构建结果'
            },
            '验证工具': {
                'ntwp validate all': '运行所有验证',
                'ntwp validate config': '验证配置文件',
                'ntwp validate github-actions': '验证 GitHub Actions'
            },
            '发布工具': {
                'ntwp release <type>': '发布新版本',
                'ntwp release <type> --dry-run': '预览发布'
            }
        };

        Object.entries(commands).forEach(([category, cmds]) => {
            console.log(chalk.blue(`${category}:`));
            Object.entries(cmds).forEach(([cmd, desc]) => {
                console.log(`  ${chalk.cyan(cmd.padEnd(30))} ${chalk.gray(desc)}`);
            });
            console.log();
        });
    }

    /**
     * 显示完整帮助
     */
    static showFullHelp() {
        console.log(chalk.cyan.bold('🚀 Notion-to-WordPress CLI 完整帮助文档'));
        console.log('='.repeat(60));
        
        this.showQuickStart();
        this.showCommandReference();
        this.showBestPractices();
        this.showFAQ();
        this.showTroubleshooting();
        
        console.log(chalk.yellow('\n💡 更多帮助：'));
        console.log('   • 项目文档：https://github.com/Frank-Loong/Notion-to-WordPress');
        console.log('   • 问题反馈：https://github.com/Frank-Loong/Notion-to-WordPress/issues');
        console.log('   • 使用 ' + chalk.cyan('ntwp <command> --help') + ' 查看具体命令帮助');
    }
}

module.exports = HelpSystem;