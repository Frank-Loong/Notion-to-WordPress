#!/usr/bin/env node

/**
 * Notion-to-WordPress WordPress 插件打包工具
 * 
 * 本工具用于生成符合 WordPress 标准的插件 ZIP 包，
 * 自动排除开发文件，仅包含运行所需内容。
 * 生成的 ZIP 可直接在 WordPress 后台安装。
 * 
 * @author Frank-Loong
 * @version 1.0.0
 */

const fs = require('fs');
const path = require('path');
const archiver = require('archiver');
const chalk = require('chalk');
const { glob } = require('glob');

class WordPressBuildTool {
    constructor() {
        this.projectRoot = path.resolve(__dirname, '..');
        this.buildDir = path.join(this.projectRoot, 'build');
        this.tempDir = path.join(this.buildDir, 'temp');
        this.pluginName = 'notion-to-wordpress';
        
        // 必须包含的文件和目录
        this.requiredFiles = [
            'notion-to-wordpress.php',  // 主插件文件
            'readme.txt',               // 插件描述
            'uninstall.php'            // 卸载脚本
            // LICENSE 文件为减小包体积已排除
        ];
        
        // 必须包含的目录
        this.requiredDirs = [
            'admin/',                  // 后台界面
            'assets/',                 // 前端资源
            'includes/',               // 核心功能
            'languages/'               // 国际化
        ];
        
        // 可选包含的文件（推荐但非必须）
        this.optionalFiles = [
            // 文档文件为减小包体积已排除
        ];

        // 可选包含的目录（文档）
        this.optionalDirs = [
            // 文档目录为减小包体积已排除
        ];
        
        // 需排除的开发文件/目录（除 .gitignore 外）
        this.developmentExcludes = [
            'scripts/',                // 构建脚本
            '.github/',               // GitHub Actions
            'node_modules/',          // Node 依赖
            'package.json',           // npm 配置
            'package-lock.json',      // npm 锁文件
            '.gitignore',            // Git 忽略文件
            '.env',                  // 环境变量
            '*.log'                  // 日志文件
        ];
        
        this.gitignoreRules = [];
    }

    /**
     * 读取并解析 .gitignore 文件
     */
    readGitignore() {
        const gitignorePath = path.join(this.projectRoot, '.gitignore');
        
        if (fs.existsSync(gitignorePath)) {
            const content = fs.readFileSync(gitignorePath, 'utf8');
            this.gitignoreRules = content
                .split('\n')
                .map(line => line.trim())
                .filter(line => line && !line.startsWith('#'))
                .filter(line => !line.startsWith('!')) // Ignore negation rules for simplicity
                .map(rule => {
                    // Convert gitignore patterns to glob patterns
                    if (rule.endsWith('/')) {
                        return rule + '**';
                    }
                    return rule;
                });
        }
        
        this.log(`Loaded ${this.gitignoreRules.length} gitignore rules`);
    }

    /**
     * 判断文件是否应排除
     */
    shouldExclude(filePath) {
        const relativePath = path.relative(this.projectRoot, filePath);
        const normalizedPath = relativePath.replace(/\\/g, '/');
        
        // Check development excludes
        for (const exclude of this.developmentExcludes) {
            if (exclude.includes('*')) {
                // Handle glob patterns
                if (this.matchGlob(normalizedPath, exclude)) {
                    return true;
                }
            } else if (exclude.endsWith('/')) {
                // Directory exclusion
                if (normalizedPath.startsWith(exclude) || normalizedPath === exclude.slice(0, -1)) {
                    return true;
                }
            } else {
                // File exclusion
                if (normalizedPath === exclude || normalizedPath.endsWith('/' + exclude)) {
                    return true;
                }
            }
        }
        
        // Check gitignore rules
        for (const rule of this.gitignoreRules) {
            if (this.matchGlob(normalizedPath, rule)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 简单 glob 匹配
     */
    matchGlob(str, pattern) {
        // Convert glob pattern to regex
        const regexPattern = pattern
            .replace(/\./g, '\\.')
            .replace(/\*/g, '.*')
            .replace(/\?/g, '.');
        
        const regex = new RegExp('^' + regexPattern + '$');
        return regex.test(str);
    }

    /**
     * 获取当前插件版本号
     */
    getPluginVersion() {
        try {
            const mainFile = path.join(this.projectRoot, 'notion-to-wordpress.php');
            const content = fs.readFileSync(mainFile, 'utf8');
            
            const versionMatch = content.match(/\* Version:\s+([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/);
            if (versionMatch) {
                return versionMatch[1];
            }
            
            return '1.0.0'; // Fallback version
        } catch (error) {
            this.warn(`Could not determine plugin version: ${error.message}`);
            return '1.0.0';
        }
    }

    /**
     * 创建构建目录结构
     */
    prepareBuildDir() {
        this.log('Preparing build directory...');
        
        // Clean existing build directory
        if (fs.existsSync(this.buildDir)) {
            fs.rmSync(this.buildDir, { recursive: true, force: true });
        }
        
        // Create build and temp directories
        fs.mkdirSync(this.buildDir, { recursive: true });
        fs.mkdirSync(this.tempDir, { recursive: true });
        
        this.success('Build directory prepared');
    }

    /**
     * 拷贝文件到临时目录
     */
    async copyFiles() {
        this.log('Copying plugin files...');
        
        const pluginTempDir = path.join(this.tempDir, this.pluginName);
        fs.mkdirSync(pluginTempDir, { recursive: true });
        
        // Get all files in project
        const allFiles = await glob('**/*', {
            cwd: this.projectRoot,
            dot: true,
            nodir: true
        });
        
        let copiedCount = 0;
        let skippedCount = 0;
        
        for (const file of allFiles) {
            const sourcePath = path.join(this.projectRoot, file);
            const targetPath = path.join(pluginTempDir, file);
            
            if (this.shouldExclude(sourcePath)) {
                skippedCount++;
                continue;
            }
            
            // Ensure target directory exists
            const targetDir = path.dirname(targetPath);
            if (!fs.existsSync(targetDir)) {
                fs.mkdirSync(targetDir, { recursive: true });
            }
            
            // Copy file
            fs.copyFileSync(sourcePath, targetPath);
            copiedCount++;
        }
        
        this.success(`Copied ${copiedCount} files, skipped ${skippedCount} files`);
        return pluginTempDir;
    }

    /**
     * 创建 ZIP 包
     */
    async createZip(sourceDir) {
        const version = this.getPluginVersion();
        const zipFileName = `${this.pluginName}-${version}.zip`;
        const zipPath = path.join(this.buildDir, zipFileName);
        
        this.log(`Creating ZIP package: ${zipFileName}`);
        
        return new Promise((resolve, reject) => {
            const output = fs.createWriteStream(zipPath);
            const archive = archiver('zip', {
                zlib: { level: 9 } // Maximum compression
            });
            
            output.on('close', () => {
                const sizeInMB = (archive.pointer() / 1024 / 1024).toFixed(2);
                this.success(`ZIP package created: ${zipFileName} (${sizeInMB} MB)`);
                resolve(zipPath);
            });
            
            archive.on('error', (err) => {
                this.error(`ZIP creation failed: ${err.message}`);
                reject(err);
            });
            
            archive.pipe(output);
            
            // Add all files from temp directory
            archive.directory(sourceDir, this.pluginName);
            
            archive.finalize();
        });
    }

    /**
     * 清理临时文件
     */
    cleanup() {
        if (fs.existsSync(this.tempDir)) {
            fs.rmSync(this.tempDir, { recursive: true, force: true });
            this.log('Temporary files cleaned up');
        }
    }

    /**
     * 校验生成的 ZIP 包
     */
    validatePackage(zipPath) {
        this.log('Validating WordPress plugin package...');
        
        const stats = fs.statSync(zipPath);
        const sizeInMB = (stats.size / 1024 / 1024).toFixed(2);
        
        // Basic validation checks
        const checks = [
            { name: 'File exists', passed: fs.existsSync(zipPath) },
            { name: 'File size > 0', passed: stats.size > 0 },
            { name: 'File size < 50MB', passed: stats.size < 50 * 1024 * 1024 }
        ];
        
        let allPassed = true;
        for (const check of checks) {
            if (check.passed) {
                this.success(`✓ ${check.name}`);
            } else {
                this.error(`✗ ${check.name}`);
                allPassed = false;
            }
        }
        
        if (allPassed) {
            this.success(`Package validation passed (${sizeInMB} MB)`);
            return true;
        } else {
            this.error('Package validation failed');
            return false;
        }
    }

    /**
     * 主构建流程
     */
    async build() {
        try {
            this.log(chalk.bold('🚀 WordPress Plugin Build Tool'));
            this.log(`Building plugin: ${chalk.cyan(this.pluginName)}`);
            
            // Read gitignore rules
            this.readGitignore();
            
            // Prepare build directory
            this.prepareBuildDir();
            
            // Copy files
            const pluginDir = await this.copyFiles();
            
            // Create ZIP package
            const zipPath = await this.createZip(pluginDir);
            
            // Validate package
            const isValid = this.validatePackage(zipPath);
            
            // Clean up
            this.cleanup();
            
            if (isValid) {
                this.success(`✅ Build completed successfully!`);
                this.log(`Package location: ${chalk.green(zipPath)}`);
                this.log(`You can now install this ZIP file in WordPress admin.`);
            } else {
                throw new Error('Package validation failed');
            }
            
        } catch (error) {
            this.error(`Build failed: ${error.message}`);
            this.cleanup();
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
    const builder = new WordPressBuildTool();
    builder.build();
}

module.exports = WordPressBuildTool;
