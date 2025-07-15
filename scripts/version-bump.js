#!/usr/bin/env node

/**
 * Notion-to-WordPress 插件版本号自动升级工具
 * 
 * 本工具会自动在 WordPress 插件项目的所有相关文件中更新版本号，
 * 保证版本号一致，并支持语义化版本（patch、minor、major、beta）。
 * 
 * @author Frank-Loong
 * @version 1.8.3-beta.2
 */

const fs = require('fs');
const path = require('path');
const semver = require('semver');
const chalk = require('chalk');
const minimist = require('minimist');

class VersionBumper {
    constructor() {
        this.projectRoot = path.resolve(__dirname, '..');
        this.currentVersion = null;
        this.newVersion = null;

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
                path: 'includes/class-notion-to-wordpress.php',
                patterns: [
                    {
                        // 文件头部的 @version 注释
                        regex: /(\* @version\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    },
                    {
                        // 构造函数中的硬编码版本号
                        regex: /(\$this->version\s*=\s*')([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)(';)/,
                        replacement: '$1{VERSION}$3'
                    }
                ]
            },
            {
                path: 'release.config.js',
                patterns: [
                    {
                        // 文件头部的 @version 注释
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
                        // npm 锁定文件版本 - 根级别（第3行）
                        regex: /(^\s*"version":\s*")([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)(.*")/m,
                        replacement: '$1{VERSION}$3'
                    },
                    {
                        // npm 锁定文件版本 - packages根级别（第9行左右）
                        regex: /(\s*"":\s*\{[^}]*?"version":\s*")([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)(.*")/s,
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
                        regex: /(>\s*©\s*2025\s+Frank-Loong\s*·\s*Notion-to-WordPress\s+v?)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
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
                    },
                    {
                        regex: /(© 2025 Frank-Loong · Notion-to-WordPress v)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
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
                    },
                    {
                        regex: /(© 2025 Frank-Loong · Notion-to-WordPress v)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            // 语言文件
            {
                path: 'languages/notion-to-wordpress.pot',
                patterns: [
                    {
                        regex: /(Project-Id-Version:\s+Notion to WordPress\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'languages/notion-to-wordpress-zh_CN.po',
                patterns: [
                    {
                        regex: /(Project-Id-Version:\s+Notion to WordPress\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'languages/notion-to-wordpress-en_US.po',
                patterns: [
                    {
                        regex: /(Project-Id-Version:\s+Notion to WordPress\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'docs/Wiki.md',
                patterns: [
                    {
                        regex: /(© 2025 Frank-Loong · Notion-to-WordPress v)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'docs/Wiki.zh_CN.md',
                patterns: [
                    {
                        regex: /(© 2025 Frank-Loong · Notion-to-WordPress v)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'docs/DEVELOPER_GUIDE.md',
                patterns: [
                    {
                        regex: /(© 2025 Frank-Loong · Notion-to-WordPress v)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
            {
                path: 'docs/DEVELOPER_GUIDE-zh_CN.md',
                patterns: [
                    {
                        regex: /(© 2025 Frank-Loong · Notion-to-WordPress v)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/,
                        replacement: '$1{VERSION}'
                    }
                ]
            },
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
        const versions = [];
        const missingFiles = [];

        for (const fileConfig of this.versionFiles) {
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
                        line: this.getLineNumber(content, match[0])
                    });
                }
            }
        }

        // 报告缺失的文件
        if (missingFiles.length > 0) {
            this.warn(`以下文件未找到: ${missingFiles.join(', ')}`);
        }

        // 检查是否找到版本号
        if (versions.length === 0) {
            throw new Error('未找到任何版本号');
        }

        // 检查版本一致性
        const uniqueVersions = [...new Set(versions.map(v => v.version))];

        if (uniqueVersions.length > 1) {
            const errorMsg = '检测到版本不一致:\n' +
                versions.map(v => `  ${v.file}:${v.line} → ${v.version}`).join('\n');
            throw new Error(errorMsg);
        }

        return uniqueVersions[0];
    }

    /**
     * 获取匹配内容在文件中的行号
     */
    getLineNumber(content, matchText) {
        try {
            const index = content.indexOf(matchText);
            if (index === -1) return 0;
            const lines = content.substring(0, index).split('\n');
            return lines.length;
        } catch (error) {
            return 0;
        }
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
     * 使用自定义版本号直接更新所有文件
     */
    updateToCustomVersion(newVersion) {
        // 校验版本格式
        if (!semver.valid(newVersion)) {
            this.error(`无效的版本格式: ${newVersion}`);
            process.exit(1);
        }

        // 获取并校验当前版本一致性
        this.getCurrentVersion();
        this.validateVersion();

        // 开始更新
        try {
            const success = this.updateAllFiles(newVersion);
            if (!success) {
                throw new Error('没有文件被更新');
            }
            this.newVersion = newVersion;
            this.success(`✅ 已将版本更新为 ${newVersion}`);
            return true;
        } catch (err) {
            this.error(`更新失败: ${err.message}`);
            process.exit(1);
        }
    }

    /**
     * 获取 updateAllFiles 后的新版本号
     */
    getNewVersion() {
        return this.newVersion;
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

        // 更新配置文件中指定的版本号文件
        for (const fileConfig of this.versionFiles) {
            if (this.updateFileVersion(fileConfig, newVersion)) {
                updatedCount++;
            }
        }

        // 自动扫描并更新所有包含 @version 标签的文件
        const headerUpdatedCount = this.updateFileHeaders(newVersion);

        this.success(`成功更新 ${updatedCount} 个配置文件，${headerUpdatedCount} 个头部注释文件`);
        return updatedCount > 0 || headerUpdatedCount > 0;
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

            try {
                // 更新所有文件
                const success = this.updateAllFiles(newVersion);

                if (success) {
                    this.success(`✅ 版本成功从 ${currentVersion} 升级到 ${newVersion}`);
                    this.setNewVersion(newVersion);
                } else {
                    throw new Error('没有文件被更新');
                }

            } catch (updateError) {
                this.error(`更新失败: ${updateError.message}`);
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
     * 获取升级/更新后的新版本号
     *
     * @since 1.1.1
     * @returns {string} 新版本号
     */
    getNewVersion() {
        return this.newVersion || this.getCurrentVersion();
    }

    /**
     * 自动扫描并更新所有包含 @version 标签的文件
     */
    updateFileHeaders(newVersion) {
        this.log('正在扫描并更新文件头部注释...');

        const directories = ['includes', 'admin', 'assets/js', 'assets/css', 'scripts'];
        const extensions = ['.php', '.js', '.css'];
        const excludeDirs = ['assets/vendor', 'node_modules', 'build', 'languages'];

        let updatedCount = 0;

        directories.forEach(dir => {
            const fullDirPath = path.join(this.projectRoot, dir);
            if (fs.existsSync(fullDirPath)) {
                const files = this.getFilesRecursively(fullDirPath, extensions, excludeDirs);
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
            this.success(`成功更新 ${updatedCount} 个文件的头部注释版本`);
        } else {
            this.log('没有找到需要更新的头部注释文件');
        }

        return updatedCount;
    }

    /**
     * 递归获取目录下指定扩展名的文件
     */
    getFilesRecursively(dirPath, extensions = [], excludeDirs = []) {
        let files = [];

        try {
            const items = fs.readdirSync(dirPath);

            items.forEach(item => {
                const fullPath = path.join(dirPath, item);
                const relativePath = path.relative(this.projectRoot, fullPath);

                // 检查是否在排除目录中
                const isExcluded = excludeDirs.some(excludeDir =>
                    relativePath.startsWith(excludeDir) ||
                    relativePath.includes(`/${excludeDir}/`) ||
                    relativePath.includes(`\\${excludeDir}\\`)
                );

                if (isExcluded) {
                    return;
                }

                const stat = fs.statSync(fullPath);

                if (stat.isDirectory()) {
                    files = files.concat(this.getFilesRecursively(fullPath, extensions, excludeDirs));
                } else {
                    // 检查文件扩展名
                    if (extensions.length === 0 || extensions.includes(path.extname(fullPath))) {
                        files.push(fullPath);
                    }
                }
            });
        } catch (error) {
            this.warn(`读取目录失败: ${dirPath} - ${error.message}`);
        }

        return files;
    }

    /**
     * 更新单个文件头部注释中的版本号
     */
    updateFileHeaderVersion(filePath, newVersion) {
        try {
            if (!fs.existsSync(filePath)) {
                return false;
            }

            let content = fs.readFileSync(filePath, 'utf8');

            // 检查是否包含 @version 标签
            const versionRegex = /(@version\s+)([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/g;

            if (!versionRegex.test(content)) {
                return false; // 文件不包含 @version 标签
            }

            // 重置正则表达式的 lastIndex
            versionRegex.lastIndex = 0;

            // 替换版本号
            const updatedContent = content.replace(versionRegex, `$1${newVersion}`);

            if (updatedContent !== content) {
                fs.writeFileSync(filePath, updatedContent, 'utf8');
                const relativePath = path.relative(this.projectRoot, filePath);
                this.log(`  ✓ 更新 ${relativePath}`);
                return true;
            }

            return false;
        } catch (error) {
            this.warn(`更新文件头部注释失败: ${filePath} - ${error.message}`);
            return false;
        }
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

// ========================================
// CLI 执行入口
// ========================================
if (require.main === module) {
    const rawArgs = process.argv.slice(2);
    const parsed = minimist(rawArgs, {
        string: ['version'],
        boolean: ['help'],
        alias: { 'h': 'help', 'v': 'version' }
    });

    // 帮助
    if (parsed.help) {
        showHelp();
        process.exit(0);
    }

    const customVersion = parsed.version || process.env.NOTION_VERSION;
    const command = parsed._[0];

    // 显示帮助信息
    function showHelp() {
        console.log(chalk.bold('\n📝 Notion-to-WordPress 版本号管理工具\n'));
        console.log('用法:');
        console.log('  npm run version:check                         # 检查版本一致性');
        console.log('  node scripts/version-bump.js --version=X.Y.Z # 设置自定义版本号');
        console.log('  npm run version:<类型>                        # 升级版本号');
        console.log('  npm run version:help                          # 显示帮助');
        console.log('');
        console.log('版本升级类型:');
        console.log('  patch      补丁版本升级 (1.1.0 → 1.1.1)');
        console.log('  minor      小版本升级 (1.1.0 → 1.2.0)');
        console.log('  major      主版本升级 (1.1.0 → 2.0.0)');
        console.log('  beta       测试版升级 (1.1.0 → 1.1.1-beta.1)');
        console.log('');
        console.log('示例:');
        console.log('  npm run version:check                         # 仅检查版本一致性');
        console.log('  node scripts/version-bump.js --version=1.8.3 # 设置为指定版本');
        console.log('  npm run version:patch                         # 补丁升级');
        console.log('  npm run version:minor                         # 小版本升级');
        console.log('  npm run version:major                         # 主版本升级');
        console.log('  npm run version:beta                          # 测试版升级');
        console.log('');
        console.log('⚠️  注意:由于 npm 参数传递有限制，自定义版本设置请使用 node 命令');
    }

    // 处理帮助命令
    if ((!command && !customVersion) || command === '--help' || command === '-h' || command === 'help') {
        showHelp();
        process.exit(0);
    }

    const bumper = new VersionBumper();

    // 处理版本一致性检查
    if (command === 'check' || command === 'validate') {
        try {
            bumper.log(chalk.bold('🔍 检查版本一致性...'));
            const currentVersion = bumper.getCurrentVersion();
            bumper.validateVersion();
            bumper.success(`✅ 所有文件版本一致: ${chalk.green(currentVersion)}`);
            process.exit(0);
        } catch (error) {
            bumper.error(`❌ 版本检查失败: ${error.message}`);
            process.exit(1);
        }
    }



    // 处理自定义版本命令
    if (customVersion || command === 'custom') {
        if (command === 'custom' && !customVersion) {
            console.log(chalk.red('❌ 自定义版本需要指定版本号'));
            console.log('');
            console.log('使用方法:');
            console.log('  npm run version:custom -- --version=X.Y.Z');
            console.log('  node scripts/version-bump.js --version=X.Y.Z');
            console.log('  node scripts/version-bump.js custom --version=X.Y.Z');
            process.exit(1);
        }

        try {
            bumper.log(chalk.bold('🚀 Notion-to-WordPress 自定义版本设置工具'));
            bumper.log(`目标版本: ${chalk.cyan(customVersion)}`);

            bumper.updateToCustomVersion(customVersion);
            bumper.success(`✅ 版本已成功设置为 ${customVersion}`);
            process.exit(0);
        } catch (error) {
            bumper.error(`❌ 自定义版本设置失败: ${error.message}`);
            process.exit(1);
        }
    }

    // 处理版本升级命令
    const validBumpTypes = ['patch', 'minor', 'major', 'beta'];
    if (!validBumpTypes.includes(command)) {
        console.log(chalk.red(`❌ 无效的命令: ${command}`));
        console.log('');
        showHelp();
        process.exit(1);
    }

    // 执行版本升级
    bumper.run(command);
}

module.exports = VersionBumper;
