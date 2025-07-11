#!/usr/bin/env node

/**
 * Notion-to-WordPress 插件版本号自动升级工具
 * 
 * 本工具会自动在 WordPress 插件项目的所有相关文件中更新版本号，
 * 保证版本号一致，并支持语义化版本（patch、minor、major、beta）。
 * 
 * @author Frank-Loong
 * @version 1.0.0
 */

const fs = require('fs');
const path = require('path');
const semver = require('semver');
const chalk = require('chalk');

class VersionBumper {
    constructor() {
        this.projectRoot = path.resolve(__dirname, '..');
        this.backupDir = path.join(this.projectRoot, '.version-backup');
        this.currentVersion = null;
        
        // 需要更新版本号的文件列表
        this.versionFiles = [
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
                path: 'readme.txt',
                patterns: [
                    {
                        regex: /(Stable tag:\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'includes/class-notion-to-wordpress.php',
                patterns: [
                    {
                        regex: /(\$this->version\s*=\s*')([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)(';)/,
                        replacement: '$1{VERSION}$3'
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
                path: 'README.md',
                patterns: [
                    {
                        regex: /(>\s*©\s*2025\s+Frank-Loong\s*·\s*Notion-to-WordPress\s+v?)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'README-zh_CN.md',
                patterns: [
                    {
                        regex: /(>\s*©\s*2025\s+Frank-Loong\s*·\s*Notion·to·WordPress\s+v?)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'docs/PROJECT_OVERVIEW.md',
                patterns: [
                    {
                        regex: /(>\s*\*\*Current Version\*\*:\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'docs/PROJECT_OVERVIEW-zh_CN.md',
                patterns: [
                    {
                        regex: /(>\s*\*\*当前版本\*\*:\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
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
            this.error(`获取当前版本失败: ${error.message}`);
            process.exit(1);
        }
    }

    /**
     * 校验所有文件的版本号格式和一致性
     */
    validateVersion() {
        this.log('正在校验文件版本号的一致性...');
        
        const versions = [];
        
        for (const fileConfig of this.versionFiles) {
            const filePath = path.join(this.projectRoot, fileConfig.path);
            
            if (!fs.existsSync(filePath)) {
                this.warn(`未找到文件: ${fileConfig.path}`);
                continue;
            }
            
            const content = fs.readFileSync(filePath, 'utf8');
            
            for (const pattern of fileConfig.patterns) {
                const match = content.match(pattern.regex);
                if (match && match[2]) {
                    versions.push({
                        file: fileConfig.path,
                        version: match[2]
                    });
                }
            }
        }
        
        // 检查所有版本号是否一致
        const uniqueVersions = [...new Set(versions.map(v => v.version))];
        
        if (uniqueVersions.length > 1) {
            this.error('检测到版本不一致:');
            versions.forEach(v => {
                console.log(`  ${v.file}: ${v.version}`);
            });
            process.exit(1);
        }
        
        if (uniqueVersions.length === 0) {
            this.error('在任何文件中未找到版本号');
            process.exit(1);
        }
        
        this.success(`所有文件的版本号一致: ${uniqueVersions[0]}`);
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
            this.error(`版本号升级失败: ${error.message}`);
            process.exit(1);
        }
    }

    /**
     * 修改前备份所有相关文件
     */
    createBackup() {
        this.log('正在备份文件...');
        
        if (fs.existsSync(this.backupDir)) {
            fs.rmSync(this.backupDir, { recursive: true, force: true });
        }
        fs.mkdirSync(this.backupDir, { recursive: true });
        
        for (const fileConfig of this.versionFiles) {
            const sourcePath = path.join(this.projectRoot, fileConfig.path);
            
            if (fs.existsSync(sourcePath)) {
                const backupPath = path.join(this.backupDir, fileConfig.path);
                const backupDir = path.dirname(backupPath);
                
                if (!fs.existsSync(backupDir)) {
                    fs.mkdirSync(backupDir, { recursive: true });
                }
                
                fs.copyFileSync(sourcePath, backupPath);
            }
        }
        
        this.success('备份成功');
    }

    /**
     * 更新指定文件的版本号
     */
    updateFileVersion(fileConfig, newVersion) {
        const filePath = path.join(this.projectRoot, fileConfig.path);
        
        if (!fs.existsSync(filePath)) {
            this.warn(`未找到文件: ${fileConfig.path}`);
            return false;
        }
        
        let content = fs.readFileSync(filePath, 'utf8');
        let updated = false;
        
        for (const pattern of fileConfig.patterns) {
            const replacement = pattern.replacement.replace('{VERSION}', newVersion);
            
            if (pattern.regex.test(content)) {
                content = content.replace(pattern.regex, replacement);
                updated = true;
            }
        }
        
        if (updated) {
            fs.writeFileSync(filePath, content, 'utf8');
            this.success(`已更新 ${fileConfig.path}`);
            return true;
        } else {
            this.warn(`在 ${fileConfig.path} 中未找到版本号模式`);
            return false;
        }
    }

    /**
     * 批量更新所有文件的版本号
     */
    updateAllFiles(newVersion) {
        this.log(`正在将所有文件更新为版本 ${newVersion}...`);
        
        let updatedCount = 0;
        
        for (const fileConfig of this.versionFiles) {
            if (this.updateFileVersion(fileConfig, newVersion)) {
                updatedCount++;
            }
        }
        
        this.success(`成功更新 ${updatedCount} 个文件`);
        return updatedCount > 0;
    }

    /**
     * 从备份恢复文件
     */
    restoreFromBackup() {
        this.log('正在从备份恢复文件...');
        
        if (!fs.existsSync(this.backupDir)) {
            this.error('没有找到备份文件');
            return false;
        }
        
        for (const fileConfig of this.versionFiles) {
            const backupPath = path.join(this.backupDir, fileConfig.path);
            const targetPath = path.join(this.projectRoot, fileConfig.path);
            
            if (fs.existsSync(backupPath)) {
                fs.copyFileSync(backupPath, targetPath);
            }
        }
        
        this.success('文件已从备份恢复');
        return true;
    }

    /**
     * 清理备份目录
     */
    cleanupBackup() {
        if (fs.existsSync(this.backupDir)) {
            fs.rmSync(this.backupDir, { recursive: true, force: true });
        }
    }

    /**
     * 主执行函数
     */
    run(bumpType) {
        try {
            this.log(chalk.bold('🚀 Notion-to-WordPress 版本号升级工具'));
            this.log(`升级类型: ${chalk.cyan(bumpType)}`);
            
            // 获取并校验当前版本
            const currentVersion = this.getCurrentVersion();
            this.validateVersion();
            
            // 计算新版本号
            const newVersion = this.bumpVersion(currentVersion, bumpType);
            
            this.log(`当前版本: ${chalk.yellow(currentVersion)}`);
            this.log(`新版本: ${chalk.green(newVersion)}`);
            
            // 修改前备份文件
            this.createBackup();
            
            try {
                // 更新所有文件
                const success = this.updateAllFiles(newVersion);
                
                if (success) {
                    this.success(`✅ 版本成功从 ${currentVersion} 升级到 ${newVersion}`);
                    this.setNewVersion(newVersion);
                    this.cleanupBackup();
                } else {
                    throw new Error('没有文件被更新');
                }
                
            } catch (updateError) {
                this.error(`更新失败: ${updateError.message}`);
                this.restoreFromBackup();
                process.exit(1);
            }
            
        } catch (error) {
            this.error(`版本升级失败: ${error.message}`);
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

    /**
     * 设置自定义版本号（本地打包用）
     *
     * @since 1.1.1
     * @param {string} customVersion - 要设置的自定义版本号
     */
    updateToCustomVersion(customVersion) {
        try {
            // 校验版本号格式
            if (!semver.valid(customVersion)) {
                throw new Error(`无效的版本号格式: ${customVersion}`);
            }

            this.log(`正在更新为自定义版本号: ${customVersion}`);

            // 获取当前版本以便备份
            const currentVersion = this.getCurrentVersion();
            this.log(`当前版本: ${currentVersion}`);

            // 备份文件
            this.createBackup();

            // 更新所有文件
            const success = this.updateAllFiles(customVersion);

            if (success) {
                this.success(`✅ 版本成功从 ${currentVersion} 升级到 ${customVersion}`);
                this.newVersion = customVersion;
                return customVersion;
            } else {
                throw new Error('没有文件被更新');
            }

        } catch (error) {
            this.error(`自定义版本更新失败: ${error.message}`);
            this.restoreFromBackup();
            throw error;
        }
    }

    /**
     * 获取升级/更新后的新版本号
     *
     * @since 1.1.1
     * @returns {string} 新版本号
     */
    getNewVersion() {
        return this.newVersion || this.getCurrentVersion();
    }

    /**
     * 设置新版本号（内部使用）
     *
     * @since 1.1.1
     * @param {string} version - 新版本号
     */
    setNewVersion(version) {
        this.newVersion = version;
    }
}

// CLI 执行入口
if (require.main === module) {
    const args = process.argv.slice(2);
    const command = args[0];

    if (command === 'rollback') {
        const bumper = new VersionBumper();
        if (bumper.restoreFromBackup()) {
            bumper.success('\u2705 成功回滚到上一个版本');
        } else {
            bumper.error('\u274c 回滚失败');
            process.exit(1);
        }
        return;
    }

    const bumpType = command;

    // Handle help command
    if (command === '--help' || command === '-h' || command === 'help') {
        console.log(chalk.bold('\n📝 Notion-to-WordPress 版本号管理工具\n'));
        console.log('用法: npm run version:bump:<命令>');
        console.log('');
        console.log('命令:');
        console.log('  patch      补丁版本升级 (1.1.0 → 1.1.1)');
        console.log('  minor      小版本升级 (1.1.0 → 1.2.0)');
        console.log('  major      主版本升级 (1.1.0 → 2.0.0)');
        console.log('  beta       测试版升级 (1.1.0 → 1.1.1-beta.1)');
        console.log('  rollback   从备份恢复版本');
        console.log('');
        console.log('示例:');
        console.log('  npm run version:bump:patch     # 补丁升级');
        console.log('  npm run version:bump:minor     # 小版本升级');
        console.log('  npm run version:bump:major     # 主版本升级');
        console.log('  npm run version:bump:beta      # 测试版升级');
        console.log('  npm run version:bump:rollback  # 恢复备份');
        console.log('  npm run version:bump           # 检查版本一致性');
        process.exit(0);
    }

    if (!bumpType || !['patch', 'minor', 'major', 'beta'].includes(bumpType)) {
        console.log(chalk.red('\u274c 未指定或无效的升级类型'));
        console.log('用法: npm run version:bump:<patch|minor|major|beta|rollback>');
        console.log('使用 npm run version:bump -- --help 查看详细帮助信息');
        process.exit(1);
    }

    const bumper = new VersionBumper();
    bumper.run(bumpType);
}

module.exports = VersionBumper;
