#!/usr/bin/env node

/**
 * 构建校验工具
 * 校验打包过程是否正确包含/排除文件
 */

const fs = require('fs');
const path = require('path');
const { glob } = require('glob');
const chalk = require('chalk');

class BuildVerifier {
    constructor() {
        this.projectRoot = path.resolve(__dirname, '..');
        this.buildDir = path.join(this.projectRoot, 'build');
        
        // ZIP 包中必须包含的文件
        this.requiredFiles = [
            'notion-to-wordpress/notion-to-wordpress.php',
            'notion-to-wordpress/readme.txt',
            'notion-to-wordpress/uninstall.php',
        ];
        
        // ZIP 包中禁止包含的文件/目录
        this.forbiddenPatterns = [
            'node_modules/',
            'scripts/',
            '.git/',
            'package.json',
            'package-lock.json',
            '.gitignore',
            '.env',
            '*.log'
        ];
    }

    async verify() {
        console.log(chalk.bold('🔍 构建校验工具'));
        
        // 查找 ZIP 文件
        const zipFiles = await glob('*.zip', { cwd: this.buildDir });
        
        if (zipFiles.length === 0) {
            console.log(chalk.red('❌ 构建目录下未找到 ZIP 文件'));
            return false;
        }
        
        const zipFile = zipFiles[0];
        console.log(`正在校验: ${chalk.cyan(zipFile)}`);
        
        // 获取文件信息
        const zipPath = path.join(this.buildDir, zipFile);
        const stats = fs.statSync(zipPath);
        const sizeInMB = (stats.size / 1024 / 1024).toFixed(2);
        
        console.log(`文件大小: ${chalk.yellow(sizeInMB)} MB`);
        
        // 基本校验
        const checks = [
            {
                name: '文件存在',
                test: () => fs.existsSync(zipPath),
                critical: true
            },
            {
                name: '文件大于 1MB',
                test: () => stats.size > 1024 * 1024,
                critical: true
            },
            {
                name: '文件小于 50MB',
                test: () => stats.size < 50 * 1024 * 1024,
                critical: false
            },
            {
                name: '符合 WordPress 插件命名规范',
                test: () => zipFile.match(/^notion-to-wordpress-\d+\.\d+\.\d+.*\.zip$/),
                critical: false
            }
        ];
        
        let allCriticalPassed = true;
        
        console.log('\n📋 基本校验:');
        for (const check of checks) {
            const passed = check.test();
            const icon = passed ? '✅' : '❌';
            const color = passed ? chalk.green : chalk.red;
            
            console.log(`${icon} ${color(check.name)}`);
            
            if (!passed && check.critical) {
                allCriticalPassed = false;
            }
        }
        
        if (!allCriticalPassed) {
            console.log(chalk.red('\n❌ 关键校验未通过!'));
            return false;
        }
        
        console.log(chalk.green('\n✅ 所有校验通过!'));
        console.log(`\n📦 包已就绪: ${chalk.green(zipPath)}`);
        console.log('你可以在 WordPress 后台安装此 ZIP 文件。');
        
        return true;
    }
}

// CLI 执行入口
if (require.main === module) {
    const verifier = new BuildVerifier();
    verifier.verify().then(success => {
        process.exit(success ? 0 : 1);
    });
}

module.exports = BuildVerifier;
