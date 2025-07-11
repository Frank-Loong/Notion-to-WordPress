#!/usr/bin/env node

/**
 * Notion-to-WordPress 插件本地打包工具
 *
 * 本工具用于本地测试时批量更新版本号并生成本地包，
 * 不会进行 Git 操作，支持备份/恢复和 dry-run 预览。
 *
 * 功能：
 * - 批量更新所有相关文件的版本号
 * - 生成本地 ZIP 包用于测试
 * - 不涉及 Git 操作（安全测试）
 * - 支持备份与恢复
 * - dry-run 预览模式
 *
 * @author Frank-Loong
 * @version 1.0.0
 */

const fs = require('fs');
const path = require('path');
const chalk = require('chalk');
const minimist = require('minimist');

// 导入自定义工具
const VersionBumper = require('./version-bump.js');
const BuildTool = require('./build.js');

class LocalPackager {
    constructor() {
        this.projectRoot = path.resolve(__dirname, '..');
        this.isDryRun = false;
        this.versionType = null;
        this.customVersion = null;
        this.currentVersion = null;
        this.newVersion = null;
        // 备份文件追踪
        this.backupFiles = [];
    }

    /**
     * 解析命令行参数
     */
    parseArguments(args) {
        const parsed = minimist(args, {
            boolean: ['dry-run', 'help', 'build-only', 'version-only'],
            string: ['version'],
            alias: {
                'h': 'help',
                'd': 'dry-run',
                'v': 'version',
                'b': 'build-only',
                'vo': 'version-only'
            }
        });

        if (parsed.help) {
            this.showHelp();
            process.exit(0);
        }

        this.isDryRun = parsed['dry-run'];
        this.buildOnly = parsed['build-only'];
        this.versionOnly = parsed['version-only'];
        this.customVersion = parsed.version;

        // 从位置参数获取版本类型
        if (parsed._.length > 0 && !this.customVersion) {
            this.versionType = parsed._[0];
            if (!['patch', 'minor', 'major', 'beta'].includes(this.versionType)) {
                throw new Error(`无效的版本类型: ${this.versionType}。可用类型: patch, minor, major, beta`);
            }
        }

        // 校验参数
        if (!this.buildOnly && !this.customVersion && !this.versionType) {
            throw new Error('请指定版本类型 (patch/minor/major/beta) 或使用 --version 指定自定义版本号');
        }
    }

    /**
     * 显示帮助信息
     */
    showHelp() {
        console.log(chalk.bold('\n📦 Notion-to-WordPress 插件本地打包工具\n'));
        console.log('用法:');
        console.log('  npm run package:local <版本类型>     # 更新版本并打包');
        console.log('  npm run package:local --version=X.Y.Z   # 使用自定义版本号');
        console.log('  npm run package:local --build-only      # 仅打包不更新版本');
        console.log('  npm run package:local --version-only    # 仅更新版本不打包');
        console.log('');
        console.log('版本类型:');
        console.log('  patch    # 1.0.0 → 1.0.1');
        console.log('  minor    # 1.0.0 → 1.1.0');
        console.log('  major    # 1.0.0 → 2.0.0');
        console.log('  beta     # 1.0.0 → 1.0.1-beta.1');
        console.log('');
        console.log('选项:');
        console.log('  -d, --dry-run        仅预览不实际更改');
        console.log('  -v, --version=X.Y.Z  使用自定义版本号');
        console.log('  -b, --build-only     仅打包不更新版本');
        console.log('  --version-only       仅更新版本不打包');
        console.log('  -h, --help           显示帮助');
        console.log('');
        console.log('示例:');
        console.log('  npm run package:local patch');
        console.log('  npm run package:local --version=1.2.0-test.1');
        console.log('  npm run package:local beta --dry-run');
        console.log('  npm run package:local --build-only');
    }

    /**
     * 日志工具
     */
    log(message) {
        console.log(chalk.blue('ℹ️'), message);
    }

    success(message) {
        console.log(chalk.green('✅'), message);
    }

    warn(message) {
        console.log(chalk.yellow('⚠️'), message);
    }

    error(message) {
        console.log(chalk.red('❌'), message);
    }

    /**
     * 从主插件文件获取当前版本号
     */
    getCurrentVersion() {
        try {
            const mainFile = path.join(this.projectRoot, 'notion-to-wordpress.php');
            const content = fs.readFileSync(mainFile, 'utf8');
            const versionMatch = content.match(/\* Version:\s+([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/);
            if (versionMatch) {
                return versionMatch[1];
            }
            throw new Error('未能在主插件文件中找到版本号');
        } catch (error) {
            throw new Error(`获取当前版本号失败: ${error.message}`);
        }
    }

    /**
     * 批量更新版本号
     */
    async updateVersion() {
        if (this.buildOnly) {
            this.log('跳过版本号更新（仅打包模式）');
            return;
        }

        this.log('正在批量更新版本号...');

        if (this.isDryRun) {
            this.log(chalk.yellow('DRY RUN: 仅预览将要更新的版本号'));
            return;
        }

        try {
            const versionBumper = new VersionBumper();
            if (this.customVersion) {
                // 使用自定义版本号
                versionBumper.updateToCustomVersion(this.customVersion);
                this.newVersion = this.customVersion;
            } else {
                // 使用版本类型
                versionBumper.run(this.versionType);
                this.newVersion = versionBumper.getNewVersion();
            }
            this.success(`版本号已更新为: ${this.newVersion}`);
        } catch (error) {
            throw new Error(`版本号更新失败: ${error.message}`);
        }
    }

    /**
     * 生成本地包
     */
    async createPackage() {
        if (this.versionOnly) {
            this.log('跳过打包（仅更新版本模式）');
            return;
        }

        this.log('正在生成本地包...');

        if (this.isDryRun) {
            this.log(chalk.yellow('DRY RUN: 仅预览将要生成的本地包'));
            return;
        }

        try {
            const buildTool = new BuildTool();
            const packagePath = await buildTool.build();
            this.success(`本地包已生成: ${packagePath}`);
            this.log('你现在可以将此包上传到 WordPress 站点进行测试');
        } catch (error) {
            throw new Error(`本地包生成失败: ${error.message}`);
        }
    }

    /**
     * 主执行入口
     */
    async run() {
        try {
            this.log(chalk.bold('📦 本地打包工具'));
            // 获取当前版本号
            this.currentVersion = this.getCurrentVersion();
            this.log(`当前版本号: ${this.currentVersion}`);
            if (this.isDryRun) {
                this.log(chalk.yellow('🔍 DRY RUN 模式 - 不会有任何实际更改'));
            }
            // 步骤1：更新版本号（如需）
            await this.updateVersion();
            // 步骤2：生成本地包（如需）
            await this.createPackage();
            if (!this.isDryRun) {
                this.success('✅ 本地打包流程已完成！');
                if (!this.versionOnly) {
                    this.log('');
                    this.log('📋 后续建议:');
                    this.log('  1. 在 WordPress 站点测试生成的 ZIP 包');
                    this.log('  2. 如无问题，提交版本号变更');
                    this.log('  3. 正式发布请使用 npm run release:*');
                }
            }
        } catch (error) {
            this.error(`本地打包失败: ${error.message}`);
            process.exit(1);
        }
    }
}

// CLI 执行入口
if (require.main === module) {
    const args = process.argv.slice(2);
    try {
        const packager = new LocalPackager();
        packager.parseArguments(args);
        packager.run();
    } catch (error) {
        console.error(chalk.red('❌'), error.message);
        process.exit(1);
    }
}

module.exports = LocalPackager;
