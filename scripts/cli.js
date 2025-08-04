#!/usr/bin/env node

/**
 * Notion-to-WordPress CLI 工具
 * 
 * 现代化、用户友好的统一命令行界面
 * 提供项目管理、构建、发布等全套功能
 * 
 * @author Frank-Loong
 * @version 2.0.0-beta.1
 */

const { Command } = require('commander');
const chalk = require('chalk');
const inquirer = require('inquirer');
const ora = require('ora');
const path = require('path');
const fs = require('fs');

// 导入现有模块
const VersionManager = require('./version');
const BuildTool = require('./build');
const ValidationTool = require('./validate');
const ReleaseController = require('./release');
const config = require('./lib/config');
const Utils = require('./lib/utils');
const HelpSystem = require('./lib/help');

class NotionWPCLI {
    constructor() {
        this.program = new Command();
        this.setupProgram();
        this.setupCommands();
    }

    /**
     * 设置程序基本信息
     */
    setupProgram() {
        const packageInfo = this.getPackageInfo();
        
        this.program
            .name('ntwp')
            .usage('<command> [options]')
            .description('Notion-to-WordPress 开发工具链 - 让 WordPress 插件开发更简单')
            .version(packageInfo.version, '-v, --version', '显示当前版本号')
            .helpOption('-h, --help', '显示帮助信息')
            .configureHelp({
                sortSubcommands: true,
                subcommandTerm: (cmd) => cmd.name() + ' ' + cmd.usage()
            })
            .addHelpText('before', chalk.yellow.bold('\n用法示例：\n') +
                '  ' + chalk.cyan('ntwp init') + '                     # 创建新项目\n' +
                '  ' + chalk.cyan('ntwp doctor') + '                   # 检查项目健康\n' +
                '  ' + chalk.cyan('ntwp version check') + '            # 检查版本一致性\n' +
                '  ' + chalk.cyan('ntwp build package') + '            # 构建插件包\n' +
                '  ' + chalk.cyan('ntwp release patch --dry-run') + '  # 预览发布\n')
            .addHelpText('after', chalk.gray('\n使用 "ntwp <command> --help" 查看具体命令的详细帮助'));

        // 全局选项
        this.program
            .option('--verbose', '显示详细的执行过程信息')
            .option('--quiet', '静默模式，只显示错误信息')
            .option('--no-color', '禁用彩色输出（适用于日志文件）');
    }

    /**
     * 设置所有命令
     */
    setupCommands() {
        this.setupInitCommand();
        this.setupVersionCommands();
        this.setupBuildCommands();
        this.setupValidateCommands();
        this.setupReleaseCommands();
        this.setupUtilityCommands();
    }

    /**
     * 项目初始化命令
     */
    setupInitCommand() {
        this.program
            .command('init')
            .description('创建新的 WordPress 插件项目（推荐新手使用）')
            .option('-t, --template <type>', '选择项目模板类型', 'basic')
            .option('-f, --force', '强制覆盖已存在的文件')
            .action(async (options) => {
                await this.handleInit(options);
            });
    }

    /**
     * 版本管理命令
     */
    setupVersionCommands() {
        const versionCmd = this.program
            .command('version')
            .alias('v')
            .description('管理插件版本号');

        versionCmd
            .command('check')
            .description('检查所有文件的版本号是否一致')
            .action(async () => {
                await this.handleVersionCheck();
            });

        versionCmd
            .command('bump <type>')
            .description('自动升级版本号 (类型: patch补丁|minor小版本|major大版本|beta测试版)')
            .option('-d, --dry-run', '预览模式，只显示将要做的更改，不实际修改文件')
            .action(async (type, options) => {
                await this.handleVersionBump(type, options);
            });

        versionCmd
            .command('set <version>')
            .description('手动设置版本号 (格式: 1.2.3 或 1.2.3-beta.1)')
            .action(async (version) => {
                await this.handleVersionSet(version);
            });
    }

    /**
     * 构建命令
     */
    setupBuildCommands() {
        const buildCmd = this.program
            .command('build')
            .alias('b')
            .description('构建 WordPress 插件包');

        buildCmd
            .command('package')
            .description('打包插件为可安装的 ZIP 文件')
            .option('-c, --clean', '构建前先清理旧文件')
            .option('-v, --verify', '构建后自动验证包的完整性')
            .action(async (options) => {
                await this.handleBuild(options);
            });

        buildCmd
            .command('clean')
            .description('清理构建目录中的所有文件')
            .action(async () => {
                await this.handleBuildClean();
            });

        buildCmd
            .command('verify')
            .description('检查已构建的插件包是否正确')
            .action(async () => {
                await this.handleBuildVerify();
            });
    }

    /**
     * 验证命令
     */
    setupValidateCommands() {
        const validateCmd = this.program
            .command('validate')
            .alias('check')
            .description('检查项目配置和文件');

        validateCmd
            .command('all')
            .description('运行所有检查项目（推荐在发布前使用）')
            .action(async () => {
                await this.handleValidateAll();
            });

        validateCmd
            .command('config')
            .description('检查项目配置文件是否正确')
            .action(async () => {
                await this.handleValidateConfig();
            });

        validateCmd
            .command('github-actions')
            .description('检查 GitHub 自动化工作流配置')
            .action(async () => {
                await this.handleValidateGitHubActions();
            });
    }

    /**
     * 发布命令
     */
    setupReleaseCommands() {
        this.program
            .command('release <type>')
            .description('发布新版本到 GitHub (类型: patch补丁|minor小版本|major大版本|beta测试版)')
            .option('-d, --dry-run', '预览模式，只显示将要执行的操作，不实际发布')
            .option('-s, --skip-tests', '跳过测试步骤（不推荐）')
            .action(async (type, options) => {
                await this.handleRelease(type, options);
            });
    }

    /**
     * 实用工具命令
     */
    setupUtilityCommands() {
        this.program
            .command('doctor')
            .description('检查项目健康状况（推荐定期使用）')
            .action(async () => {
                await this.handleDoctor();
            });

        this.program
            .command('config')
            .description('管理项目配置文件')
            .option('-l, --list', '显示当前所有配置项')
            .option('-g, --generate', '生成新的配置文件')
            .action(async (options) => {
                await this.handleConfig(options);
            });

        this.program
            .command('info')
            .description('显示项目基本信息')
            .action(async () => {
                await this.handleInfo();
            });

        this.program
            .command('help-guide')
            .description('显示详细使用指南（新手必看）')
            .option('-q, --quick', '显示快速开始指南')
            .option('-f, --faq', '显示常见问题解答')
            .option('-b, --best-practices', '显示最佳实践建议')
            .option('-t, --troubleshooting', '显示问题解决方法')
            .action(async (options) => {
                await this.handleHelpGuide(options);
            });
    }

    /**
     * 获取包信息
     */
    getPackageInfo() {
        try {
            const packagePath = path.join(__dirname, '..', 'package.json');
            return JSON.parse(fs.readFileSync(packagePath, 'utf8'));
        } catch (error) {
            return { version: '1.0.0', name: 'notion-to-wordpress' };
        }
    }

    /**
     * 显示欢迎信息
     */
    showWelcome() {
        const packageInfo = this.getPackageInfo();
        const version = packageInfo.version || '1.0.0';

        console.log(chalk.cyan.bold('\nNotion-to-WordPress CLI v' + version));
        console.log(chalk.gray('Modern WordPress Plugin Development Toolkit\n'));
    }

    /**
     * 处理项目初始化
     */
    async handleInit(options) {
        this.showWelcome();
        
        const spinner = ora('正在初始化项目...').start();
        
        try {
            // 检查是否已经是项目目录
            const isProject = fs.existsSync('notion-to-wordpress.php');
            
            if (isProject && !options.force) {
                spinner.stop();
                const { confirm } = await inquirer.prompt([{
                    type: 'confirm',
                    name: 'confirm',
                    message: '检测到现有项目，是否继续初始化？',
                    default: false
                }]);
                
                if (!confirm) {
                    Utils.info('初始化已取消');
                    return;
                }
                spinner.start();
            }

            // 交互式配置
            spinner.stop();
            const answers = await this.promptProjectConfig();
            spinner.start('正在生成项目文件...');

            // 生成配置文件
            await this.generateProjectFiles(answers);
            
            spinner.succeed('项目初始化完成！');
            
            console.log(chalk.green('\n✅ 项目初始化成功！'));
            console.log(chalk.blue('\n📋 下一步操作：'));
            console.log('  1. 运行 ' + chalk.cyan('ntwp doctor') + ' 检查项目健康状况');
            console.log('  2. 运行 ' + chalk.cyan('ntwp validate all') + ' 验证配置');
            console.log('  3. 运行 ' + chalk.cyan('ntwp build package') + ' 构建插件包');
            
        } catch (error) {
            spinner.fail('初始化失败');
            Utils.error(`初始化失败: ${error.message}`);
            process.exit(1);
        }
    }

    /**
     * 项目配置提示
     */
    async promptProjectConfig() {
        return await inquirer.prompt([
            {
                type: 'input',
                name: 'name',
                message: '项目名称:',
                default: 'notion-to-wordpress',
                validate: (input) => input.length > 0 || '项目名称不能为空'
            },
            {
                type: 'input',
                name: 'displayName',
                message: '显示名称:',
                default: 'Notion-to-WordPress'
            },
            {
                type: 'input',
                name: 'description',
                message: '项目描述:',
                default: 'WordPress plugin for syncing Notion databases'
            },
            {
                type: 'input',
                name: 'author',
                message: '作者:',
                default: 'Your Name'
            },
            {
                type: 'list',
                name: 'license',
                message: '许可证:',
                choices: ['GPL-3.0-or-later', 'MIT', 'Apache-2.0', 'BSD-3-Clause'],
                default: 'GPL-3.0-or-later'
            }
        ]);
    }

    /**
     * 生成项目文件
     */
    async generateProjectFiles(projectConfig) {
        Utils.info('生成项目配置文件...');

        // 生成基本的package.json
        const packageJson = {
            name: projectConfig.name,
            version: "1.0.0",
            description: projectConfig.description,
            main: `${projectConfig.name}.php`,
            scripts: {
                "build": "node scripts/cli.js build package",
                "validate": "node scripts/cli.js validate all",
                "version:check": "node scripts/cli.js version check",
                "doctor": "node scripts/cli.js doctor"
            },
            author: projectConfig.author,
            license: projectConfig.license,
            devDependencies: {
                "chalk": "^4.1.2",
                "commander": "^11.1.0",
                "inquirer": "^8.2.6",
                "ora": "^5.4.1",
                "semver": "^7.7.2"
            }
        };

        // 写入package.json
        const packagePath = path.join(process.cwd(), 'package.json');
        if (!fs.existsSync(packagePath)) {
            fs.writeFileSync(packagePath, JSON.stringify(packageJson, null, 2));
            Utils.success('已生成 package.json');
        }

        // 生成基本的WordPress插件主文件
        const pluginContent = `<?php
/**
 * Plugin Name: ${projectConfig.displayName}
 * Description: ${projectConfig.description}
 * Version: 1.0.0
 * Author: ${projectConfig.author}
 * License: ${projectConfig.license}
 * Text Domain: ${projectConfig.name}
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 定义插件常量
define('${projectConfig.name.toUpperCase().replace(/-/g, '_')}_VERSION', '1.0.0');
define('${projectConfig.name.toUpperCase().replace(/-/g, '_')}_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('${projectConfig.name.toUpperCase().replace(/-/g, '_')}_PLUGIN_URL', plugin_dir_url(__FILE__));

// 插件激活钩子
register_activation_hook(__FILE__, '${projectConfig.name.replace(/-/g, '_')}_activate');

// 插件停用钩子
register_deactivation_hook(__FILE__, '${projectConfig.name.replace(/-/g, '_')}_deactivate');

/**
 * 插件激活时执行
 */
function ${projectConfig.name.replace(/-/g, '_')}_activate() {
    // 激活逻辑
}

/**
 * 插件停用时执行
 */
function ${projectConfig.name.replace(/-/g, '_')}_deactivate() {
    // 停用逻辑
}

/**
 * 插件初始化
 */
function ${projectConfig.name.replace(/-/g, '_')}_init() {
    // 初始化逻辑
}

// 初始化插件
add_action('init', '${projectConfig.name.replace(/-/g, '_')}_init');
`;

        // 写入主插件文件
        const pluginPath = path.join(process.cwd(), `${projectConfig.name}.php`);
        if (!fs.existsSync(pluginPath)) {
            fs.writeFileSync(pluginPath, pluginContent);
            Utils.success(`已生成 ${projectConfig.name}.php`);
        }

        // 生成readme.txt
        const readmeContent = `=== ${projectConfig.displayName} ===
Contributors: ${projectConfig.author.toLowerCase().replace(/\s+/g, '')}
Tags: wordpress, plugin
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.0
Requires PHP: 7.4
License: ${projectConfig.license}

${projectConfig.description}

== Description ==

${projectConfig.description}

== Installation ==

1. Upload the plugin files to the \`/wp-content/plugins/${projectConfig.name}\` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure the plugin settings as needed

== Changelog ==

= 1.0.0 =
* Initial release
`;

        // 写入readme.txt
        const readmePath = path.join(process.cwd(), 'readme.txt');
        if (!fs.existsSync(readmePath)) {
            fs.writeFileSync(readmePath, readmeContent);
            Utils.success('已生成 readme.txt');
        }

        // 创建基本目录结构
        const directories = ['includes', 'admin', 'assets/css', 'assets/js', 'languages'];
        directories.forEach(dir => {
            const dirPath = path.join(process.cwd(), dir);
            if (!fs.existsSync(dirPath)) {
                fs.mkdirSync(dirPath, { recursive: true });
                Utils.success(`已创建目录 ${dir}/`);
            }
        });

        Utils.success('项目文件生成完成！');
    }

    /**
     * 处理版本检查
     */
    async handleVersionCheck() {
        const spinner = ora('检查版本一致性...').start();
        
        try {
            const versionManager = new VersionManager();
            versionManager.check();
            spinner.succeed('版本检查完成');
        } catch (error) {
            spinner.fail('版本检查失败');
            Utils.error(`版本检查失败: ${error.message}`);
            process.exit(1);
        }
    }

    /**
     * 处理版本升级
     */
    async handleVersionBump(type, options) {
        const validTypes = ['patch', 'minor', 'major', 'beta'];
        
        if (!validTypes.includes(type)) {
            Utils.error(`无效的版本类型: ${type}`);
            Utils.info(`有效类型: ${validTypes.join(', ')}`);
            process.exit(1);
        }

        if (options.dryRun) {
            Utils.info('预览模式：将要执行的操作');
            // 显示预览信息
            return;
        }

        const { confirm } = await inquirer.prompt([{
            type: 'confirm',
            name: 'confirm',
            message: `确定要升级 ${type} 版本吗？`,
            default: false
        }]);

        if (!confirm) {
            Utils.info('版本升级已取消');
            return;
        }

        const spinner = ora(`正在升级 ${type} 版本...`).start();
        
        try {
            const versionManager = new VersionManager();
            versionManager.run(type);
            spinner.succeed('版本升级完成');
        } catch (error) {
            spinner.fail('版本升级失败');
            Utils.error(`版本升级失败: ${error.message}`);
            process.exit(1);
        }
    }

    /**
     * 处理自定义版本设置
     */
    async handleVersionSet(version) {
        const { confirm } = await inquirer.prompt([{
            type: 'confirm',
            name: 'confirm',
            message: `确定要设置版本为 ${version} 吗？`,
            default: false
        }]);

        if (!confirm) {
            Utils.info('版本设置已取消');
            return;
        }

        const spinner = ora(`正在设置版本为 ${version}...`).start();
        
        try {
            const versionManager = new VersionManager();
            versionManager.updateToCustomVersion(version);
            spinner.succeed('版本设置完成');
        } catch (error) {
            spinner.fail('版本设置失败');
            Utils.error(`版本设置失败: ${error.message}`);
            process.exit(1);
        }
    }

    /**
     * 处理构建
     */
    async handleBuild(options) {
        const spinner = ora('正在构建插件包...').start();
        
        try {
            if (options.clean) {
                spinner.text = '清理构建目录...';
                const buildTool = new BuildTool();
                buildTool.clean();
            }

            spinner.text = '构建插件包...';
            const buildTool = new BuildTool();
            const zipPath = await buildTool.build();

            if (options.verify) {
                spinner.text = '验证构建结果...';
                buildTool.verify();
            }

            spinner.succeed('构建完成');
            Utils.success(`构建包位置: ${zipPath}`);
        } catch (error) {
            spinner.fail('构建失败');
            Utils.error(`构建失败: ${error.message}`);
            process.exit(1);
        }
    }

    /**
     * 处理构建清理
     */
    async handleBuildClean() {
        const spinner = ora('清理构建目录...').start();
        
        try {
            const buildTool = new BuildTool();
            buildTool.clean();
            spinner.succeed('清理完成');
        } catch (error) {
            spinner.fail('清理失败');
            Utils.error(`清理失败: ${error.message}`);
            process.exit(1);
        }
    }

    /**
     * 处理构建验证
     */
    async handleBuildVerify() {
        const spinner = ora('验证构建结果...').start();
        
        try {
            const buildTool = new BuildTool();
            buildTool.verify();
            spinner.succeed('验证完成');
        } catch (error) {
            spinner.fail('验证失败');
            Utils.error(`验证失败: ${error.message}`);
            process.exit(1);
        }
    }

    /**
     * 处理全面验证
     */
    async handleValidateAll() {
        const spinner = ora('运行全面验证...').start();
        
        try {
            const validator = new ValidationTool();
            const success = await validator.runAll();
            
            if (success) {
                spinner.succeed('所有验证通过');
            } else {
                spinner.fail('验证发现问题');
                process.exit(1);
            }
        } catch (error) {
            spinner.fail('验证失败');
            Utils.error(`验证失败: ${error.message}`);
            process.exit(1);
        }
    }

    /**
     * 处理配置验证
     */
    async handleValidateConfig() {
        const spinner = ora('验证配置文件...').start();
        
        try {
            const validator = new ValidationTool();
            validator.validateProjectConfig();
            validator.validateVersionConfig();
            validator.validateBuildConfig();
            validator.validateGitConfig();
            validator.validateGitHubConfig();
            validator.validateEnvironmentConfig();
            validator.validateFileReferences();
            validator.generateReport();
            
            if (validator.errors.length === 0) {
                spinner.succeed('配置验证通过');
            } else {
                spinner.fail('配置验证失败');
                process.exit(1);
            }
        } catch (error) {
            spinner.fail('配置验证失败');
            Utils.error(`配置验证失败: ${error.message}`);
            process.exit(1);
        }
    }

    /**
     * 处理GitHub Actions验证
     */
    async handleValidateGitHubActions() {
        const spinner = ora('验证 GitHub Actions...').start();
        
        try {
            const validator = new ValidationTool();
            validator.validateGitHubActions();
            validator.generateReport();
            
            if (validator.errors.length === 0) {
                spinner.succeed('GitHub Actions 验证通过');
            } else {
                spinner.fail('GitHub Actions 验证失败');
                process.exit(1);
            }
        } catch (error) {
            spinner.fail('GitHub Actions 验证失败');
            Utils.error(`GitHub Actions 验证失败: ${error.message}`);
            process.exit(1);
        }
    }

    /**
     * 处理发布
     */
    async handleRelease(type, options) {
        const validTypes = ['patch', 'minor', 'major', 'beta'];

        if (!validTypes.includes(type)) {
            Utils.error(`无效的发布类型: ${type}`);
            Utils.info(`有效类型: ${validTypes.join(', ')}`);
            process.exit(1);
        }

        // 显示发布信息
        console.log(chalk.cyan.bold('\n🚀 Notion-to-WordPress 发布工具\n'));
        console.log(`发布类型: ${chalk.cyan(type)}`);
        if (options.dryRun) {
            console.log(`模式: ${chalk.yellow('预览模式（不会实际发布）')}`);
        } else {
            console.log(`模式: ${chalk.green('正式发布')}`);
        }

        // 确认发布
        if (!options.dryRun) {
            const { confirm } = await inquirer.prompt([{
                type: 'confirm',
                name: 'confirm',
                message: `确定要发布 ${type} 版本吗？这将会：\n  • 升级版本号\n  • 构建插件包\n  • 创建 Git 标签\n  • 发布到 GitHub\n  继续吗？`,
                default: false
            }]);

            if (!confirm) {
                Utils.info('发布已取消');
                return;
            }
        }

        const spinner = ora('正在准备发布...').start();

        try {
            // 创建发布控制器
            const releaseController = new ReleaseController();

            // 设置发布参数
            releaseController.isDryRun = options.dryRun || false;
            releaseController.releaseType = type;
            releaseController.skipTests = options.skipTests || false;

            spinner.text = '正在执行发布流程...';

            // 执行发布
            await releaseController.executeRelease();

            spinner.succeed('发布完成！');

            if (options.dryRun) {
                console.log(chalk.yellow('\n✅ 预览模式完成！'));
                console.log(chalk.blue('📋 预览结果：'));
                console.log('  • 版本号升级检查通过');
                console.log('  • 构建流程验证通过');
                console.log('  • Git 操作验证通过');
                console.log('  • GitHub 发布准备就绪');
                console.log(chalk.yellow('\n💡 运行不带 --dry-run 参数的命令进行正式发布'));
            } else {
                console.log(chalk.green('\n🎉 发布成功！'));
                console.log(chalk.blue('\n📋 发布结果：'));
                console.log(`  • 新版本: ${chalk.cyan(releaseController.newVersion)}`);
                console.log(`  • 构建包: ${chalk.gray('已生成')}`);
                console.log(`  • Git 标签: ${chalk.gray('已创建')}`);
                console.log(`  • GitHub Release: ${chalk.gray('已发布')}`);

                console.log(chalk.blue('\n🔗 相关链接：'));
                console.log(`  • GitHub Release: https://github.com/Frank-Loong/Notion-to-WordPress/releases/tag/v${releaseController.newVersion}`);
                console.log(`  • 下载地址: https://github.com/Frank-Loong/Notion-to-WordPress/releases/download/v${releaseController.newVersion}/notion-to-wordpress-${releaseController.newVersion}.zip`);
            }

        } catch (error) {
            spinner.fail('发布失败');
            Utils.error(`发布失败: ${error.message}`);

            console.log(chalk.yellow('\n🔧 故障排除建议：'));
            console.log('  1. 检查网络连接');
            console.log('  2. 验证 GitHub 访问权限');
            console.log('  3. 确保工作目录干净（无未提交的更改）');
            console.log('  4. 运行 ' + chalk.cyan('ntwp doctor') + ' 检查项目健康状况');
            console.log('  5. 查看详细错误信息并根据提示操作');

            process.exit(1);
        }
    }

    /**
     * 处理项目诊断
     */
    async handleDoctor() {
        const spinner = ora('诊断项目健康状况...').start();
        
        try {
            spinner.text = '检查项目结构...';
            await this.checkProjectStructure();
            
            spinner.text = '检查依赖...';
            await this.checkDependencies();
            
            spinner.text = '检查配置...';
            await this.checkConfiguration();
            
            spinner.succeed('项目诊断完成');
            
            console.log(chalk.green('\n✅ 项目健康状况良好！'));
            
        } catch (error) {
            spinner.fail('诊断发现问题');
            Utils.error(`诊断失败: ${error.message}`);
        }
    }

    /**
     * 检查项目结构
     */
    async checkProjectStructure() {
        const requiredFiles = [
            'notion-to-wordpress.php',
            'readme.txt',
            'package.json',
            'composer.json'
        ];

        const requiredDirs = [
            'includes',
            'admin',
            'assets',
            'scripts'
        ];

        for (const file of requiredFiles) {
            if (!fs.existsSync(file)) {
                throw new Error(`缺少必需文件: ${file}`);
            }
        }

        for (const dir of requiredDirs) {
            if (!fs.existsSync(dir)) {
                throw new Error(`缺少必需目录: ${dir}`);
            }
        }
    }

    /**
     * 检查依赖
     */
    async checkDependencies() {
        // 检查 Node.js 版本
        const nodeVersion = process.version;
        Utils.info(`Node.js 版本: ${nodeVersion}`);

        // 检查 npm 包
        if (!fs.existsSync('node_modules')) {
            throw new Error('未安装 npm 依赖，请运行 npm install');
        }

        // 检查 Composer
        if (fs.existsSync('composer.json') && !fs.existsSync('vendor')) {
            Utils.warn('未安装 Composer 依赖，建议运行 composer install');
        }
    }

    /**
     * 检查配置
     */
    async checkConfiguration() {
        try {
            config.validateConfig();
            Utils.info('配置文件验证通过');
        } catch (error) {
            throw new Error(`配置验证失败: ${error.message}`);
        }
    }

    /**
     * 处理配置管理
     */
    async handleConfig(options) {
        if (options.list) {
            this.listConfiguration();
        } else if (options.generate) {
            await this.generateConfiguration();
        } else {
            Utils.info('配置管理功能');
            Utils.info('使用 --list 列出配置，--generate 生成配置文件');
        }
    }

    /**
     * 列出配置
     */
    listConfiguration() {
        try {
            const fullConfig = config.getConfig();
            console.log(chalk.blue('\n📋 当前配置:'));
            console.log(JSON.stringify(fullConfig, null, 2));
        } catch (error) {
            Utils.error(`获取配置失败: ${error.message}`);
        }
    }

    /**
     * 生成配置
     */
    async generateConfiguration() {
        const spinner = ora('正在生成配置文件...').start();

        try {
            // 检查是否已存在配置文件
            const configPath = path.join(process.cwd(), 'release.config.js');

            if (fs.existsSync(configPath)) {
                spinner.stop();
                const { overwrite } = await inquirer.prompt([{
                    type: 'confirm',
                    name: 'overwrite',
                    message: '配置文件已存在，是否覆盖？',
                    default: false
                }]);

                if (!overwrite) {
                    Utils.info('配置文件生成已取消');
                    return;
                }
                spinner.start();
            }

            // 获取项目信息
            const packagePath = path.join(process.cwd(), 'package.json');
            let projectInfo = {
                name: 'my-wordpress-plugin',
                displayName: 'My WordPress Plugin',
                description: 'A WordPress plugin',
                author: 'Plugin Author',
                license: 'GPL-3.0-or-later'
            };

            if (fs.existsSync(packagePath)) {
                const packageJson = JSON.parse(fs.readFileSync(packagePath, 'utf8'));
                projectInfo = {
                    name: packageJson.name || projectInfo.name,
                    displayName: packageJson.displayName || packageJson.name || projectInfo.displayName,
                    description: packageJson.description || projectInfo.description,
                    author: packageJson.author || projectInfo.author,
                    license: packageJson.license || projectInfo.license
                };
            }

            // 生成配置文件内容
            const configContent = `/**
 * ${projectInfo.displayName} 发布配置
 *
 * 统一管理项目的构建、版本、发布等配置
 *
 * @author ${projectInfo.author}
 * @version 1.0.0
 */

const config = {
    // 项目基本信息
    project: {
        name: '${projectInfo.name}',
        displayName: '${projectInfo.displayName}',
        description: '${projectInfo.description}',
        author: '${projectInfo.author}',
        license: '${projectInfo.license}',
        homepage: 'https://github.com/${projectInfo.author}/${projectInfo.name}',
        repository: {
            type: 'git',
            url: 'https://github.com/${projectInfo.author}/${projectInfo.name}.git'
        },
        bugs: {
            url: 'https://github.com/${projectInfo.author}/${projectInfo.name}/issues'
        }
    },

    // 版本管理配置
    version: {
        files: [
            {
                path: '${projectInfo.name}.php',
                patterns: [
                    {
                        regex: /(\\* Version:\\s+)([0-9]+\\.[0-9]+\\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'package.json',
                patterns: [
                    {
                        regex: /("version":\\s*")([0-9]+\\.[0-9]+\\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)(.*")/,
                        replacement: '$1{VERSION}$3'
                    }
                ]
            },
            {
                path: 'readme.txt',
                patterns: [
                    {
                        regex: /(Stable tag:\\s+)([0-9]+\\.[0-9]+\\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            }
        ]
    },

    // 构建配置
    build: {
        output: {
            directory: 'build',
            filename: '{PROJECT_NAME}-{VERSION}.zip'
        },
        include: {
            files: [
                '${projectInfo.name}.php',
                'readme.txt',
                'uninstall.php'
            ],
            directories: [
                'includes/',
                'admin/',
                'assets/',
                'languages/'
            ]
        },
        exclude: {
            files: [
                'package.json',
                'package-lock.json',
                '.gitignore'
            ],
            directories: [
                'node_modules/',
                '.git/',
                'build/',
                'tests/'
            ]
        }
    }
};

/**
 * 获取配置
 */
function getConfig() {
    return config;
}

module.exports = {
    getConfig
};
`;

            // 写入配置文件
            fs.writeFileSync(configPath, configContent);

            spinner.succeed('配置文件生成完成');

            console.log(chalk.green('\n✅ 配置文件已生成！'));
            console.log(chalk.blue('\n📋 生成的文件：'));
            console.log(`  • ${chalk.cyan('release.config.js')} - 项目配置文件`);

            console.log(chalk.blue('\n🔧 下一步操作：'));
            console.log('  1. 检查并调整配置文件中的设置');
            console.log('  2. 运行 ' + chalk.cyan('ntwp validate config') + ' 验证配置');
            console.log('  3. 运行 ' + chalk.cyan('ntwp doctor') + ' 检查项目健康状况');

        } catch (error) {
            spinner.fail('配置文件生成失败');
            Utils.error(`生成失败: ${error.message}`);
        }
    }

    /**
     * 处理项目信息
     */
    async handleInfo() {
        try {
            const packageInfo = this.getPackageInfo();
            const projectInfo = config.getProjectInfo();
            
            console.log(chalk.blue('\n项目信息:'));
            console.log(`  名称: ${chalk.cyan(projectInfo.name)}`);
            console.log(`  显示名称: ${chalk.cyan(projectInfo.displayName)}`);
            console.log(`  版本: ${chalk.cyan(packageInfo.version)}`);
            console.log(`  描述: ${chalk.gray(projectInfo.description)}`);
            console.log(`  作者: ${chalk.cyan(projectInfo.author)}`);
            console.log(`  许可证: ${chalk.cyan(projectInfo.license)}`);
            
        } catch (error) {
            Utils.error(`获取项目信息失败: ${error.message}`);
        }
    }

    /**
     * 处理帮助指南
     */
    async handleHelpGuide(options) {
        if (options.quick) {
            HelpSystem.showQuickStart();
        } else if (options.faq) {
            HelpSystem.showFAQ();
        } else if (options.bestPractices) {
            HelpSystem.showBestPractices();
        } else if (options.troubleshooting) {
            HelpSystem.showTroubleshooting();
        } else {
            HelpSystem.showFullHelp();
        }
    }

    /**
     * 显示友好的主帮助信息
     */
    showMainHelp() {
        this.showWelcome();

        console.log(chalk.blue.bold('可用命令：\n'));

        console.log(chalk.cyan('项目管理'));
        console.log('  ' + chalk.green('ntwp init') + '                    创建新的 WordPress 插件项目');
        console.log('  ' + chalk.green('ntwp doctor') + '                  检查项目健康状况');
        console.log('  ' + chalk.green('ntwp info') + '                    显示项目基本信息');

        console.log(chalk.cyan('\n版本管理'));
        console.log('  ' + chalk.green('ntwp version check') + '           检查版本号一致性');
        console.log('  ' + chalk.green('ntwp version bump <type>') + '      升级版本号 (patch/minor/major/beta)');
        console.log('  ' + chalk.green('ntwp version set <version>') + '    设置自定义版本号');

        console.log(chalk.cyan('\n构建工具'));
        console.log('  ' + chalk.green('ntwp build package') + '           打包插件为 ZIP 文件');
        console.log('  ' + chalk.green('ntwp build clean') + '             清理构建目录');
        console.log('  ' + chalk.green('ntwp build verify') + '            验证构建结果');

        console.log(chalk.cyan('\n验证工具'));
        console.log('  ' + chalk.green('ntwp validate all') + '            运行所有验证检查');
        console.log('  ' + chalk.green('ntwp validate config') + '         检查配置文件');
        console.log('  ' + chalk.green('ntwp validate github-actions') + ' 检查 GitHub 工作流');

        console.log(chalk.cyan('\n发布工具'));
        console.log('  ' + chalk.green('ntwp release <type>') + '          发布新版本到 GitHub');
        console.log('  ' + chalk.green('ntwp release <type> --dry-run') + ' 预览发布过程');

        console.log(chalk.cyan('\n配置工具'));
        console.log('  ' + chalk.green('ntwp config --list') + '           显示当前配置');
        console.log('  ' + chalk.green('ntwp config --generate') + '       生成配置文件');

        console.log(chalk.cyan('\n帮助系统'));
        console.log('  ' + chalk.green('ntwp help-guide') + '              显示完整使用指南');
        console.log('  ' + chalk.green('ntwp help-guide --quick') + '      显示快速开始指南');
        console.log('  ' + chalk.green('ntwp help-guide --faq') + '        显示常见问题解答');

        console.log(chalk.yellow('\n使用技巧：'));
        console.log('  • 使用 ' + chalk.cyan('ntwp <command> --help') + ' 查看具体命令的详细帮助');
        console.log('  • 新手推荐先运行 ' + chalk.cyan('ntwp help-guide --quick') + ' 查看快速指南');
        console.log('  • 遇到问题时运行 ' + chalk.cyan('ntwp doctor') + ' 进行诊断');
        console.log('  • 发布前建议运行 ' + chalk.cyan('ntwp validate all') + ' 进行全面检查');

        console.log(chalk.gray('\n使用 ' + chalk.cyan('ntwp --help') + ' 查看完整的命令行选项'));
    }

    /**
     * 运行CLI
     */
    run() {
        // 如果没有参数，显示友好的主帮助
        if (process.argv.length <= 2) {
            this.showMainHelp();
            return;
        }

        this.program.parse();
    }
}

// 运行CLI
if (require.main === module) {
    const cli = new NotionWPCLI();
    cli.run();
}

module.exports = NotionWPCLI;