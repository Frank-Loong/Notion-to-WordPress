#!/usr/bin/env node

/**
 * Notion-to-WordPress 插件发布控制器
 * 
 * 这是主发布编排器，负责协调整个自动化发布流程，包括版本号更新、构建、
 * Git 操作和带回滚能力的错误处理。
 * 
 * @author Frank-Loong
 * @version 1.0.0
 */

const fs = require('fs');
const path = require('path');
const { execSync, spawn } = require('child_process');
const chalk = require('chalk');
const minimist = require('minimist');

// 导入自定义工具
const VersionBumper = require('./version-bump.js');
const BuildTool = require('./build.js');

class ReleaseController {
    constructor() {
        this.projectRoot = path.resolve(__dirname, '..');
        this.isDryRun = false;
        this.releaseType = null;
        this.currentVersion = null;
        this.newVersion = null;
        
        // 发布步骤追踪
        this.completedSteps = [];
        this.rollbackActions = [];
    }

    /**
     * 解析命令行参数
     */
    parseArguments(args) {
        const parsed = minimist(args, {
            boolean: ['dry-run', 'help', 'force'],
            string: ['version'],
            alias: {
                'h': 'help',
                'd': 'dry-run',
                'f': 'force',
                'v': 'version'
            }
        });

        if (parsed.help) {
            this.showHelp();
            process.exit(0);
        }

        this.isDryRun = parsed['dry-run'] || false;
        this.forceRelease = parsed.force || false;
        this.customVersion = parsed.version;
        this.releaseType = parsed._[0];

        // 校验参数
        if (this.customVersion) {
            // 指定了自定义版本号，校验格式
            if (!this.isValidVersion(this.customVersion)) {
                this.error(`无效的版本格式: ${this.customVersion}`);
                this.showHelp();
                process.exit(1);
            }
            this.releaseType = 'custom';
        } else if (!this.releaseType || !['patch', 'minor', 'major', 'beta'].includes(this.releaseType)) {
            this.error('无效或缺失的发布类型。使用 patch/minor/major/beta 或 --version=X.Y.Z');
            this.showHelp();
            process.exit(1);
        }

        return {
            releaseType: this.releaseType,
            isDryRun: this.isDryRun,
            forceRelease: this.forceRelease,
            customVersion: this.customVersion
        };
    }

    /**
     * 校验版本号格式
     */
    isValidVersion(version) {
        // 基础 semver 校验
        const semverRegex = /^([0-9]+)\.([0-9]+)\.([0-9]+)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/;
        return semverRegex.test(version);
    }

    /**
     * 显示帮助信息
     */
    showHelp() {
        console.log(chalk.bold('\n🚀 Notion-to-WordPress 发布控制器\n'));
        console.log('用法: npm run release:<发布类型> [-- options]');
        console.log('      npm run release:custom -- --version=X.Y.Z [options]\n');
        console.log('发布类型:');
        console.log('  patch     补丁发布 (1.1.0 → 1.1.1)');
        console.log('  minor     小版本发布 (1.1.0 → 1.2.0)');
        console.log('  major     主版本发布 (1.1.0 → 2.0.0)');
        console.log('  beta      测试版发布 (1.1.0 → 1.1.1-beta.1)\n');
        console.log('选项:');
        console.log('  --version=X.Y.Z      使用自定义版本号');
        console.log('  --dry-run            仅预览不执行');
        console.log('  --force              跳过确认提示');
        console.log('  --help               显示帮助信息\n');
        console.log('示例:');
        console.log('  npm run release:patch');
        console.log('  npm run test:release:minor');
        console.log('  npm run release:custom -- --version=1.2.0-rc.1');
        console.log('  npm run release:custom -- --version=1.2.0-hotfix.1 --dry-run');
    }

    /**
     * 校验环境和前置条件
     */
    validateEnvironment() {
        this.log('🔍 正在验证环境...');

        // 检查是否在 git 仓库中
        try {
            execSync('git rev-parse --git-dir', { 
                cwd: this.projectRoot, 
                stdio: 'pipe' 
            });
        } catch (error) {
            throw new Error('不在 Git 仓库中');
        }

        // 检查是否有未提交的更改
        try {
            const status = execSync('git status --porcelain', { 
                cwd: this.projectRoot, 
                encoding: 'utf8' 
            });
            
            if (status.trim() && !this.forceRelease) {
                throw new Error('工作目录有未提交的更改。使用 --force 来覆盖。');
            }
        } catch (error) {
            if (error.message.includes('uncommitted changes')) {
                throw error;
            }
            // 其他原因导致 git status 失败（如不在git仓库中）
            if (!this.dryRun) {
                this.warn('无法检查 Git 状态');
            }
        }

        // 检查所需工具文件
        const requiredFiles = [
            path.join(__dirname, 'version-bump.js'),
            path.join(__dirname, 'build.js')
        ];

        for (const file of requiredFiles) {
            if (!fs.existsSync(file)) {
                throw new Error(`未找到必需的工具: ${path.basename(file)}`);
            }
        }

        // 检查 Node.js 版本
        const nodeVersion = process.version;
        const majorVersion = parseInt(nodeVersion.slice(1).split('.')[0]);
        if (majorVersion < 16) {
            throw new Error(`需要 Node.js 16 以上版本，当前版本: ${nodeVersion}`);
        }

        this.success('环境验证通过');
    }

    /**
     * 获取当前版本并计算新版本
     */
    prepareVersions() {
        this.log('📋 正在准备版本信息...');

        const versionBumper = new VersionBumper();

        // 获取当前版本
        this.currentVersion = versionBumper.getCurrentVersion();
        versionBumper.validateVersion();

        // 计算新版本
        if (this.customVersion) {
            // 使用自定义版本
            this.newVersion = this.customVersion;
        } else {
            // 根据发布类型计算新版本
            this.newVersion = versionBumper.bumpVersion(this.currentVersion, this.releaseType);
        }

        this.log(`当前版本: ${chalk.yellow(this.currentVersion)}`);
        this.log(`新版本: ${chalk.green(this.newVersion)}`);

        return {
            currentVersion: this.currentVersion,
            newVersion: this.newVersion
        };
    }

    /**
     * 用户确认
     */
    async askConfirmation() {
        if (this.isDryRun || this.forceRelease) {
            return true;
        }

        console.log(chalk.bold('\n📋 发布摘要:'));
        console.log(`  发布类型: ${chalk.cyan(this.releaseType)}`);
        console.log(`  当前版本: ${chalk.yellow(this.currentVersion)}`);
        console.log(`  新版本: ${chalk.green(this.newVersion)}`);
        console.log(`  干运行: ${this.isDryRun ? chalk.green('是') : chalk.red('否')}`);

        return new Promise((resolve) => {
            const readline = require('readline');
            const rl = readline.createInterface({
                input: process.stdin,
                output: process.stdout
            });

            rl.question(chalk.bold('\n❓ 是否继续发布? (y/N): '), (answer) => {
                rl.close();
                resolve(answer.toLowerCase() === 'y' || answer.toLowerCase() === 'yes');
            });
        });
    }

    /**
     * 执行版本号升级
     */
    async executeVersionBump() {
        this.log('🔄 正在更新版本号...');

        if (this.isDryRun) {
            this.log('  [干运行] 将更新版本为 ' + this.newVersion);
            return;
        }

        try {
            const versionBumper = new VersionBumper();

            if (this.customVersion) {
                // 使用自定义版本
                versionBumper.updateToCustomVersion(this.customVersion);
                this.newVersion = this.customVersion;
            } else {
                // 使用标准发布类型
                versionBumper.run(this.releaseType);
                this.newVersion = versionBumper.getNewVersion();
            }
            
            this.completedSteps.push('version-bump');
            this.rollbackActions.push(() => {
                this.log('回滚版本更改...');
                try {
                    versionBumper.restoreFromBackup();
                } catch (error) {
                    this.warn('无法恢复版本备份: ' + error.message);
                }
            });
            
            this.success('版本更新成功');
        } catch (error) {
            throw new Error(`版本升级失败: ${error.message}`);
        }
    }

    /**
     * 执行构建流程
     */
    async executeBuild() {
        this.log('📦 正在构建 WordPress 插件包...');

        if (this.isDryRun) {
            this.log('  [干运行] 将构建插件包');
            return;
        }

        try {
            const buildTool = new BuildTool();
            await buildTool.build();
            
            this.completedSteps.push('build');
            this.success('插件包构建成功');
        } catch (error) {
            throw new Error(`构建失败: ${error.message}`);
        }
    }

    /**
     * 执行 Git 操作
     */
    async executeGitOperations() {
        this.log('📝 正在执行 Git 操作...');

        if (this.isDryRun) {
            this.log('  [干运行] 将提交更改并创建标签');
            return;
        }

        try {
            // 添加所有更改
            execSync('git add .', { cwd: this.projectRoot });
            
            // 提交更改
            const commitMessage = `发布版本 ${this.newVersion}`;
            execSync(`git commit -m "${commitMessage}"`, { cwd: this.projectRoot });
            
            // 创建标签
            const tagMessage = `版本 ${this.newVersion}`;
            execSync(`git tag -a v${this.newVersion} -m "${tagMessage}"`, { cwd: this.projectRoot });
            
            this.completedSteps.push('git-operations');
            this.rollbackActions.push(() => {
                this.log('回滚 Git 操作...');
                try {
                    execSync(`git tag -d v${this.newVersion}`, { cwd: this.projectRoot });
                    execSync('git reset --hard HEAD~1', { cwd: this.projectRoot });
                } catch (error) {
                    this.warn('无法回滚 Git 操作: ' + error.message);
                }
            });
            
            this.success('Git 操作完成');
        } catch (error) {
            throw new Error(`Git 操作失败: ${error.message}`);
        }
    }

    /**
     * 推送到远程仓库
     */
    async pushToRemote() {
        this.log('🚀 正在推送到远程仓库...');

        if (this.isDryRun) {
            this.log('  [干运行] 将推送提交和标签到远程');
            return;
        }

        try {
            // 推送提交
            execSync('git push origin main', { cwd: this.projectRoot });
            
            // 推送标签
            execSync(`git push origin v${this.newVersion}`, { cwd: this.projectRoot });
            
            this.completedSteps.push('push');
            this.success('推送到远程仓库成功');
        } catch (error) {
            throw new Error(`推送失败: ${error.message}`);
        }
    }

    /**
     * 执行回滚操作
     */
    async executeRollback() {
        this.warn('🔄 正在执行回滚...');
        
        // 逆序执行回滚操作
        for (let i = this.rollbackActions.length - 1; i >= 0; i--) {
            try {
                await this.rollbackActions[i]();
            } catch (error) {
                this.error(`回滚操作失败: ${error.message}`);
            }
        }
    }

    /**
     * 主发布流程
     */
    async executeRelease() {
        try {
            this.log(chalk.bold('🚀 开始发布流程'));
            
            // 步骤 1: 校验环境
            this.validateEnvironment();
            
            // 步骤 2: 准备版本信息
            this.prepareVersions();
            
            // 步骤 3: 用户确认
            const confirmed = await this.askConfirmation();
            if (!confirmed) {
                this.log('发布已被用户取消');
                return;
            }
            
            // 步骤 4: 执行版本号升级
            await this.executeVersionBump();
            
            // 步骤 5: 执行构建
            await this.executeBuild();
            
            // 步骤 6: 执行 Git 操作
            await this.executeGitOperations();
            
            // 步骤 7: 推送到远程
            await this.pushToRemote();
            
            // 成功！
            this.success(`✅ 发布 ${this.newVersion} 成功!`);
            
            if (!this.isDryRun) {
                console.log(chalk.bold('\n📦 下一步:'));
                console.log('  • GitHub Actions 将自动创建发布');
                console.log('  • 在 Actions 标签页查看构建状态');
                console.log(`  • 从以下地址下载插件: build/notion-to-wordpress-${this.newVersion}.zip`);
            }
            
        } catch (error) {
            this.error(`发布失败: ${error.message}`);
            
            if (!this.isDryRun && this.completedSteps.length > 0) {
                await this.executeRollback();
            }
            
            process.exit(1);
        }
    }

    // 工具方法：日志输出
    log(message) {
        console.log(message);
    }

    success(message) {
        console.log(chalk.green('\u2705 ' + message));
    }

    warn(message) {
        console.log(chalk.yellow('\u26a0\ufe0f  ' + message));
    }

    error(message) {
        console.log(chalk.red('\u274c ' + message));
    }
}
// CLI 执行入口
if (require.main === module) {
    const controller = new ReleaseController();
    const args = process.argv.slice(2);
    
    try {
        controller.parseArguments(args);
        controller.executeRelease();
    } catch (error) {
        controller.error(error.message);
        process.exit(1);
    }
}

module.exports = ReleaseController;
