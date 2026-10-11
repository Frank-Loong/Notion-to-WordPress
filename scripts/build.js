#!/usr/bin/env node

/**
 * Notion-to-WordPress WordPress 插件打包工具
 * 
 * 本工具用于生成符合 WordPress 标准的插件 ZIP 包，
 * 自动排除开发文件，仅包含运行所需内容，
 * 生成的 ZIP 可直接在 WordPress 后台安装。
 * 
 * @author Frank-Loong
 * @version 2.0.0-beta.2
 */

const fs = require('fs');
const path = require('path');
const archiver = require('archiver');
const chalk = require('chalk');
const { glob } = require('glob');
const crypto = require('crypto');

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
        
        // 注意：现在使用白名单模式，只复制 requiredFiles 和 requiredDirs 中指定的内容
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
            
            return '1.0.0'; // 备用版本
        } catch (error) {
            this.warn(`无法确定插件版本: ${error.message}`);
            return '1.0.0';
        }
    }

    /**
     * 创建构建目录结构
     */
    prepareBuildDir() {
        this.log('正在准备构建目录...');
        
        // 清理现有构建目录
        if (fs.existsSync(this.buildDir)) {
            fs.rmSync(this.buildDir, { recursive: true, force: true });
        }
        
        // 创建构建和临时目录
        fs.mkdirSync(this.buildDir, { recursive: true });
        fs.mkdirSync(this.tempDir, { recursive: true });
        
        this.success('构建目录准备完成');
    }

    /**
     * 拷贝文件到临时目录
     */
    async copyFiles() {
        this.log('正在拷贝插件文件...');

        const pluginTempDir = path.join(this.tempDir, this.pluginName);
        fs.mkdirSync(pluginTempDir, { recursive: true });

        let copiedCount = 0;
        let skippedCount = 0;

        // 复制必需文件
        for (const file of this.requiredFiles) {
            const sourcePath = path.join(this.projectRoot, file);
            const targetPath = path.join(pluginTempDir, file);

            if (fs.existsSync(sourcePath)) {
                fs.copyFileSync(sourcePath, targetPath);
                copiedCount++;
            } else {
                this.warn(`必需文件未找到: ${file}`);
                skippedCount++;
            }
        }

        // 复制必需目录
        for (const dir of this.requiredDirs) {
            const sourceDir = path.join(this.projectRoot, dir);
            const targetDir = path.join(pluginTempDir, dir);

            if (fs.existsSync(sourceDir)) {
                // 获取此目录中的所有文件
                const dirFiles = await glob('**/*', {
                    cwd: sourceDir,
                    dot: false,
                    nodir: true
                });

                for (const file of dirFiles) {
                    const sourcePath = path.join(sourceDir, file);
                    const targetPath = path.join(targetDir, file);

                    // 确保目标目录存在
                    const targetFileDir = path.dirname(targetPath);
                    if (!fs.existsSync(targetFileDir)) {
                        fs.mkdirSync(targetFileDir, { recursive: true });
                    }

                    // 复制文件
                    fs.copyFileSync(sourcePath, targetPath);
                    copiedCount++;
                }
            } else {
                this.warn(`必需目录未找到: ${dir}`);
            }
        }
        
        this.success(`✅ 已复制 ${copiedCount} 个文件，跳过 ${skippedCount} 个文件`);
        return pluginTempDir;
    }

    /**
     * 创建 ZIP 包
     */
    async createZip(sourceDir) {
        const version = this.getPluginVersion();
        const zipFileName = `${this.pluginName}-${version}.zip`;
        const zipPath = path.join(this.buildDir, zipFileName);
        const archiveDate = new Date('1980-01-01T00:00:00.000Z');
        const files = (await glob('**/*', {
            cwd: sourceDir,
            dot: false,
            nodir: true
        })).sort((left, right) => left.localeCompare(right, 'en'));
        
        this.log(`正在创建 ZIP 包: ${zipFileName}`);
        
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
                this.error(`ZIP 创建失败: ${err.message}`);
                reject(err);
            });
            
            archive.pipe(output);
            
            // Add files in a stable order with normalized metadata so the same
            // source tree always produces the same release checksum.
            for (const file of files) {
                archive.append(fs.readFileSync(path.join(sourceDir, file)), {
                    name: `${this.pluginName}/${file.replace(/\\/g, '/')}`,
                    date: archiveDate,
                    mode: 0o100644
                });
            }
            
            archive.finalize();
        });
    }

    /**
     * 清理临时文件
     */
    cleanup() {
        if (fs.existsSync(this.tempDir)) {
            fs.rmSync(this.tempDir, { recursive: true, force: true });
            this.log('临时文件已清理');
        }
    }

    /**
     * 校验生成的 ZIP 包
     */
    validatePackage(zipPath) {
        this.log('正在验证 WordPress 插件包...');
        
        const stats = fs.statSync(zipPath);
        const sizeInMB = (stats.size / 1024 / 1024).toFixed(2);
        
        // 基本验证检查项
        const checks = [
            { name: '文件存在', passed: fs.existsSync(zipPath) },
            { name: '文件大小大于0', passed: stats.size > 0 },
            { name: '文件大小小于100MB', passed: stats.size < 100 * 1024 * 1024 }
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
            this.success(`验证通过（${sizeInMB} MB）`);
            return true;
        } else {
            this.error('验证失败');
            return false;
        }
    }

    /**
     * 生成校验和文件
     */
    async generateChecksums(zipPath) {
        this.log('🔐 正在生成校验和...');

        try {
            const zipFileName = path.basename(zipPath);
            const checksumPath = path.join(this.buildDir, 'checksums.txt');

            // 读取 ZIP 文件
            const zipBuffer = fs.readFileSync(zipPath);

            // 生成 SHA256 校验和
            const sha256Hash = crypto.createHash('sha256');
            sha256Hash.update(zipBuffer);
            const sha256 = sha256Hash.digest('hex');

            // 生成 MD5 校验和
            const md5Hash = crypto.createHash('md5');
            md5Hash.update(zipBuffer);
            const md5 = md5Hash.digest('hex');

            // 创建校验和内容（与 GitHub Actions 格式相同）
            const checksumContent = `${sha256}  ${zipFileName}\n${md5}  ${zipFileName}\n`;

            // 写入校验和文件
            fs.writeFileSync(checksumPath, checksumContent, 'utf8');

            this.success(`✅ 校验和已生成：${checksumPath}`);
            this.log(`SHA256: ${sha256}`);
            this.log(`MD5: ${md5}`);

            return checksumPath;

        } catch (error) {
            throw new Error(`生成校验和失败：${error.message}`);
        }
    }

    /**
     * 主构建流程
     */
    async build() {
        try {
            this.log(chalk.bold('🚀 WordPress 插件构建工具'));
            this.log(`正在构建插件：${chalk.cyan(this.pluginName)}`);
            
            // 准备构建目录
            this.prepareBuildDir();
            
            // 复制文件
            const pluginDir = await this.copyFiles();
            
            // 创建 ZIP 包
            const zipPath = await this.createZip(pluginDir);
            
            // 验证包
            const isValid = this.validatePackage(zipPath);

            // 生成校验和
            const checksumPath = await this.generateChecksums(zipPath);

            // 清理
            this.cleanup();

            if (isValid) {
                this.success(`✅ 构建成功完成！`);
                this.log(`生成的包位置：${chalk.green(zipPath)}`);
                this.log(`校验和文件：${chalk.green(checksumPath)}`);
                this.log(`您现在可以在 WordPress 后台安装此 ZIP 文件。`);

                return zipPath;
            } else {
                throw new Error('包验证失败');
            }
            
        } catch (error) {
            this.error(`构建失败：${error.message}`);
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
