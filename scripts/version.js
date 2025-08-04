#!/usr/bin/env node

/**
 * 统一版本管理工具
 * 
 * 负责所有版本相关操作，包括版本检查、升级、自定义设置等。
 * 使用统一配置，消除配置重复。
 * 
 * @author Frank-Loong
 * @version 2.0.0-beta.1
 */

const fs = require('fs');
const path = require('path');
const semver = require('semver');
const chalk = require('chalk');
const minimist = require('minimist');

// 导入统一模块
const config = require('./lib/config');
const Utils = require('./lib/utils');

class VersionManager {
    constructor() {
        this.projectRoot = config.getProjectRoot();
        this.currentVersion = null;
        this.newVersion = null;
    }

    /**
     * 获取版本文件配置
     * 直接定义，避免正则表达式序列化问题
     */
    getVersionFiles() {
        return [
            {
                path: 'notion-to-wordpress.php',
                patterns: [
                    {
                        regex: /(\* Version:\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    },
                    {
                        regex: /(define\(\s*'NOTION_TO_WORDPRESS_VERSION',\s*')([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)(.*\);)/,
                        replacement: '$1{VERSION}$3'
                    }
                ]
            },
            {
                path: 'release.config.js',
                patterns: [
                    {
                        regex: /(\* @version\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'readme.txt',
                patterns: [
                    {
                        regex: /(Stable tag:\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'package.json',
                patterns: [
                    {
                        regex: /("version":\s*")([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)(.*")/,
                        replacement: '$1{VERSION}$3'
                    }
                ]
            },
            {
                path: 'package-lock.json',
                patterns: [
                    {
                        regex: /(^\s*"version":\s*")([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)(.*")/m,
                        replacement: '$1{VERSION}$3'
                    },
                    {
                        regex: /(\s*"":\s*\{[^}]*?"version":\s*")([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)(.*")/s,
                        replacement: '$1{VERSION}$3'
                    }
                ]
            },
            {
                path: 'README.md',
                patterns: [
                    {
                        regex: /(©\s*2025\s+Frank-Loong\s*·\s*Notion-to-WordPress\s+v)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'README-zh_CN.md',
                patterns: [
                    {
                        regex: /(©\s*2025\s+Frank-Loong\s*·\s*Notion-to-WordPress\s+v)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            }
        ];
    }

    /**
     * 从主插件文件获取当前版本号
     */
    getCurrentVersion() {
        try {
            const mainFile = path.join(this.projectRoot, 'notion-to-wordpress.php');
            const content = fs.readFileSync(mainFile, 'utf8');
            
            const versionMatch = content.match(/\* Version:\s+([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/);
            if (!versionMatch) {
                throw new Error('Could not find version in main plugin file');
            }
            
            this.currentVersion = versionMatch[1];
            return this.currentVersion;
        } catch (error) {
            Utils.error(`获取当前版本失败: ${error.message}`);
            process.exit(1);
        }
    }

    /**
     * 校验所有文件的版本号格式和一致性
     */
    validateVersion() {
        const versions = [];
        const missingFiles = [];
        const versionFiles = this.getVersionFiles();

        for (const fileConfig of versionFiles) {
            const filePath = path.join(this.projectRoot, fileConfig.path);

            if (!fs.existsSync(filePath)) {
                missingFiles.push(fileConfig.path);
                continue;
            }

            const content = fs.readFileSync(filePath, 'utf8');

            for (const pattern of fileConfig.patterns) {
                const match = content.match(pattern.regex);
                if (match && match[2]) {
                    versions.push({
                        file: fileConfig.path,
                        version: match[2],
                        line: Utils.getLineNumber(content, match[0])
                    });
                }
            }
        }

        // 报告缺失的文件
        if (missingFiles.length > 0) {
            Utils.warn(`以下文件未找到: ${missingFiles.join(', ')}`);
        }

        // 检查是否找到版本号
        if (versions.length === 0) {
            throw new Error('未找到任何版本号');
        }

        // 检查版本一致性
        const uniqueVersions = [...new Set(versions.map(v => v.version))];
        if (uniqueVersions.length > 1) {
            Utils.error('发现版本不一致:');
            versions.forEach(v => {
                console.log(`  ${v.file}:${v.line} → ${v.version}`);
            });
            throw new Error('版本号不一致');
        }

        Utils.success(`所有文件版本一致: ${uniqueVersions[0]}`);
        return uniqueVersions[0];
    }

    /**
     * 根据升级类型计算新版本号
     */
    bumpVersion(currentVersion, bumpType) {
        try {
            let newVersion;
            
            switch (bumpType) {
                case 'patch':
                    newVersion = semver.inc(currentVersion, 'patch');
                    break;
                case 'minor':
                    newVersion = semver.inc(currentVersion, 'minor');
                    break;
                case 'major':
                    newVersion = semver.inc(currentVersion, 'major');
                    break;
                case 'beta':
                    if (currentVersion.includes('-beta')) {
                        newVersion = semver.inc(currentVersion, 'prerelease', 'beta');
                    } else {
                        newVersion = semver.inc(currentVersion, 'patch') + '-beta.1';
                    }
                    break;
                default:
                    throw new Error(`无效的升级类型: ${bumpType}`);
            }
            
            if (!newVersion) {
                throw new Error(`从 ${currentVersion} 计算新版本号失败`);
            }
            
            return newVersion;
        } catch (error) {
            Utils.error(`版本号升级失败: ${error.message}`);
            process.exit(1);
        }
    }

    /**
     * 更新单个文件的版本号
     */
    updateFileVersion(filePath, newVersion) {
        const versionFiles = this.getVersionFiles();
        const fileConfig = versionFiles.find(f => f.path === filePath);
        
        if (!fileConfig) {
            return false;
        }

        const fullPath = path.join(this.projectRoot, filePath);
        if (!fs.existsSync(fullPath)) {
            return false;
        }

        let content = fs.readFileSync(fullPath, 'utf8');
        let updated = false;

        for (const pattern of fileConfig.patterns) {
            const newContent = content.replace(pattern.regex, pattern.replacement.replace('{VERSION}', newVersion));
            if (newContent !== content) {
                content = newContent;
                updated = true;
            }
        }

        if (updated) {
            fs.writeFileSync(fullPath, content, 'utf8');
            return true;
        }

        return false;
    }

    /**
     * 更新所有文件的版本号
     */
    updateAllFiles(newVersion) {
        const versionFiles = this.getVersionFiles();
        let updatedCount = 0;

        Utils.info(`正在将所有文件更新为版本 ${newVersion}...`);

        for (const fileConfig of versionFiles) {
            if (this.updateFileVersion(fileConfig.path, newVersion)) {
                Utils.success(`已更新 ${fileConfig.path}`);
                updatedCount++;
            } else {
                Utils.warn(`未能更新 ${fileConfig.path}`);
            }
        }

        // 更新文件头部注释
        const headerUpdatedCount = this.updateFileHeaders(newVersion);

        Utils.success(`成功更新 ${updatedCount} 个配置文件，${headerUpdatedCount} 个头部注释文件`);
        return updatedCount > 0;
    }

    /**
     * 自动扫描并更新所有包含 @version 标签的文件
     */
    updateFileHeaders(newVersion) {
        Utils.info('正在扫描并更新文件头部注释...');

        const directories = ['includes', 'admin', 'assets/js', 'assets/css', 'scripts'];
        const extensions = ['.php', '.js', '.css'];
        const excludeDirs = ['assets/vendor', 'node_modules', 'build', 'languages'];

        let updatedCount = 0;

        directories.forEach(dir => {
            const fullDirPath = path.join(this.projectRoot, dir);
            if (fs.existsSync(fullDirPath)) {
                const files = Utils.getFilesRecursively(fullDirPath, extensions, excludeDirs);
                files.forEach(filePath => {
                    if (this.updateFileHeaderVersion(filePath, newVersion)) {
                        updatedCount++;
                    }
                });
            }
        });

        // 也检查根目录的 uninstall.php
        const uninstallPath = path.join(this.projectRoot, 'uninstall.php');
        if (fs.existsSync(uninstallPath)) {
            if (this.updateFileHeaderVersion(uninstallPath, newVersion)) {
                updatedCount++;
            }
        }

        if (updatedCount > 0) {
            Utils.success(`成功更新 ${updatedCount} 个文件的头部注释版本`);
        } else {
            Utils.info('没有找到需要更新的头部注释文件');
        }

        return updatedCount;
    }

    /**
     * 更新单个文件的头部版本注释
     */
    updateFileHeaderVersion(filePath, newVersion) {
        try {
            let content = fs.readFileSync(filePath, 'utf8');
            const originalContent = content;

            // 匹配 @version 标签
            const versionRegex = /(\* @version\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/g;
            content = content.replace(versionRegex, `$1${newVersion}`);

            if (content !== originalContent) {
                fs.writeFileSync(filePath, content, 'utf8');
                const relativePath = path.relative(this.projectRoot, filePath);
                console.log(`  ✓ 更新 ${relativePath}`);
                return true;
            }

            return false;
        } catch (error) {
            return false;
        }
    }

    /**
     * 设置自定义版本号
     */
    updateToCustomVersion(customVersion) {
        // 验证版本格式
        if (!semver.valid(customVersion)) {
            throw new Error(`无效的版本格式: ${customVersion}`);
        }

        Utils.info(`正在将所有文件更新为版本 ${customVersion}...`);

        // 更新所有文件
        const success = this.updateAllFiles(customVersion);

        if (success) {
            Utils.success(`已将版本更新为 ${customVersion}`);
            this.newVersion = customVersion;
        } else {
            throw new Error('没有文件被更新');
        }
    }

    /**
     * 主执行函数 - 版本升级
     */
    run(bumpType) {
        try {
            Utils.info(chalk.bold('Notion-to-WordPress 版本号升级工具'));
            Utils.info(`升级类型: ${chalk.cyan(bumpType)}`);
            
            // 获取并校验当前版本
            const currentVersion = this.getCurrentVersion();
            this.validateVersion();
            
            // 计算新版本号
            const newVersion = this.bumpVersion(currentVersion, bumpType);
            
            Utils.info(`当前版本: ${chalk.yellow(currentVersion)}`);
            Utils.info(`新版本: ${chalk.green(newVersion)}`);

            try {
                // 更新所有文件
                const success = this.updateAllFiles(newVersion);

                if (success) {
                    Utils.success(`版本成功从 ${currentVersion} 升级到 ${newVersion}`);
                    this.newVersion = newVersion;
                } else {
                    throw new Error('没有文件被更新');
                }

            } catch (updateError) {
                Utils.error(`更新失败: ${updateError.message}`);
                process.exit(1);
            }

        } catch (error) {
            Utils.error(`版本升级失败: ${error.message}`);
            process.exit(1);
        }
    }

    /**
     * 检查版本一致性
     */
    check() {
        try {
            Utils.info('检查版本一致性...');
            this.validateVersion();
        } catch (error) {
            Utils.error(`版本检查失败: ${error.message}`);
            process.exit(1);
        }
    }

    /**
     * 显示帮助信息
     */
    showHelp() {
        const commands = {
            '📝 版本管理': {
                'npm run version:check': '检查版本一致性',
                'node scripts/version.js --version=X.Y.Z': '设置自定义版本号',
                'npm run version:<类型>': '升级版本号'
            },
            '版本升级类型': {
                'patch': '补丁版本升级 (1.1.0 → 1.1.1)',
                'minor': '小版本升级 (1.1.0 → 1.2.0)',
                'major': '主版本升级 (1.1.0 → 2.0.0)',
                'beta': '测试版升级 (1.1.0 → 1.1.1-beta.1)'
            },
            '示例': {
                'npm run version:check': '仅检查版本一致性',
                'node scripts/version.js --version=1.8.3': '设置为指定版本',
                'npm run version:patch': '补丁升级',
                'npm run version:minor': '小版本升级'
            }
        };

        Utils.showHelp('版本管理工具', commands);
        console.log(chalk.yellow('⚠️  注意: 由于 npm 参数传递有限制，自定义版本设置请使用 node 命令'));
    }
}

// 命令行处理
if (require.main === module) {
    const args = minimist(process.argv.slice(2));
    const command = args._[0];
    const customVersion = args.version;

    const manager = new VersionManager();

    // 处理帮助命令
    if (command === 'help' || args.help) {
        manager.showHelp();
        process.exit(0);
    }

    // 处理检查命令
    if (command === 'check') {
        manager.check();
        process.exit(0);
    }

    // 处理自定义版本命令
    if (customVersion || command === 'custom') {
        if (command === 'custom' && !customVersion) {
            Utils.error('自定义版本需要指定版本号');
            console.log('\n使用方法:');
            console.log('  npm run version:custom -- --version=X.Y.Z');
            console.log('  node scripts/version.js --version=X.Y.Z');
            console.log('  node scripts/version.js custom --version=X.Y.Z');
            process.exit(1);
        }

        try {
            Utils.info(chalk.bold('Notion-to-WordPress 自定义版本设置工具'));
            Utils.info(`目标版本: ${chalk.cyan(customVersion)}`);

            manager.updateToCustomVersion(customVersion);
            Utils.success(`版本已成功设置为 ${customVersion}`);
            process.exit(0);
        } catch (error) {
            Utils.error(`自定义版本设置失败: ${error.message}`);
            process.exit(1);
        }
    }

    // 处理版本升级命令
    const validCommands = ['patch', 'minor', 'major', 'beta'];
    if (validCommands.includes(command)) {
        manager.run(command);
        process.exit(0);
    }

    // 如果没有有效命令，显示帮助
    if (!command) {
        manager.showHelp();
    } else {
        Utils.error(`无效的命令: ${command}`);
        manager.showHelp();
        process.exit(1);
    }
}

module.exports = VersionManager;