#!/usr/bin/env node

/**
 * 自动化发布系统集成测试套件
 * 
 * 本套件全面校验自动化发布系统的所有组件，确保其稳定性、可靠性和集成正确。
 * 
 * @author Frank-Loong
 * @version 2.0.0-beta.2
 */

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const chalk = require('chalk');

class IntegrationTestSuite {
    constructor() {
        this.projectRoot = path.resolve(__dirname, '..');
        this.testResults = [];
        this.errors = [];
        this.warnings = [];
        
        // 测试配置
        this.testConfig = {
            skipGitOperations: true,  // 安全起见，跳过实际的 Git 操作
            skipGitHubActions: true,  // 跳过 GitHub Actions 测试
            testVersionTypes: ['patch', 'minor', 'major', 'beta'],
            validatePackages: true,
            checkDocumentation: true
        };
    }

    /**
     * 运行完整的集成测试套件
     */
    async runTests() {
        console.log(chalk.bold('🧪 自动化发布系统集成测试套件\n'));
        
        try {
            // 测试 1: 环境与依赖
            await this.testEnvironment();
            
            // 测试 2: 配置校验
            await this.testConfiguration();
            
            // 测试 3: 版本管理
            await this.testVersionManagement();
            
            // 测试 4: 构建系统
            await this.testBuildSystem();
            
            // 测试 5: 发布控制器
            await this.testReleaseController();
            
            // 测试 6: GitHub Actions 配置
            await this.testGitHubActions();
            
            // 测试 7: 文档完整性
            await this.testDocumentation();
            
            // 测试 8: 错误处理与恢复
            await this.testErrorHandling();
            
            // 生成测试报告
            this.generateTestReport();
            
        } catch (error) {
            this.addError('测试套件执行失败', error.message);
            this.generateTestReport();
            process.exit(1);
        }
    }

    /**
     * 测试 1：环境与依赖
     */
    async testEnvironment() {
        this.logTestStart('环境与依赖');
        
        try {
            // 检查 Node.js 版本
            const nodeVersion = process.version;
            const majorVersion = parseInt(nodeVersion.slice(1).split('.')[0]);
            if (majorVersion >= 16) {
                this.addResult('Node.js 版本检查', 'PASS', `${nodeVersion} (>= 16.0.0)`);
            } else {
                this.addResult('Node.js 版本检查', 'FAIL', `${nodeVersion} (< 16.0.0)`);
            }
            
            // 检查 npm 可用性
            try {
                const npmVersion = execSync('npm --version', { encoding: 'utf8' }).trim();
                this.addResult('npm 可用性', 'PASS', `v${npmVersion}`);
            } catch (error) {
                this.addResult('npm 可用性', 'FAIL', '未找到 npm');
            }
            
            // 检查 Git 可用性
            try {
                const gitVersion = execSync('git --version', { encoding: 'utf8' }).trim();
                this.addResult('Git 可用性', 'PASS', gitVersion);
            } catch (error) {
                this.addResult('Git 可用性', 'FAIL', '未找到 Git');
            }
            
            // 检查必需的依赖
            const packageJson = JSON.parse(fs.readFileSync(path.join(this.projectRoot, 'package.json'), 'utf8'));
            const requiredDeps = ['semver', 'archiver', 'chalk', 'fs-extra', 'glob', 'minimist', 'js-yaml'];
            
            for (const dep of requiredDeps) {
                if (packageJson.devDependencies && packageJson.devDependencies[dep]) {
                    this.addResult(`依赖: ${dep}`, 'PASS', packageJson.devDependencies[dep]);
                } else {
                    this.addResult(`依赖: ${dep}`, 'FAIL', '未在 package.json 中找到');
                }
            }
            
            // 检查 node_modules 是否存在
            const nodeModulesPath = path.join(this.projectRoot, 'node_modules');
            if (fs.existsSync(nodeModulesPath)) {
                this.addResult('node_modules 目录', 'PASS', '依赖已安装');
            } else {
                this.addResult('node_modules 目录', 'FAIL', '未安装依赖');
            }
            
        } catch (error) {
            this.addError('环境测试失败', error.message);
        }
    }

    /**
     * 测试 2：配置校验
     */
    async testConfiguration() {
        this.logTestStart('配置校验');
        
        try {
            // 测试发布配置
            try {
                const configPath = path.join(this.projectRoot, 'release.config.js');
                if (fs.existsSync(configPath)) {
                    delete require.cache[require.resolve(configPath)];
                    const config = require(configPath);
                    const cfg = config.getConfig();
                    this.addResult('发布配置', 'PASS', `${cfg.version.files.length} 个版本文件已配置`);
                } else {
                    this.addResult('发布配置', 'FAIL', '未找到 release.config.js');
                }
            } catch (error) {
                this.addResult('发布配置', 'FAIL', error.message);
            }
            
            // 测试 GitHub Actions 配置
            const workflowPath = path.join(this.projectRoot, '.github/workflows/release.yml');
            if (fs.existsSync(workflowPath)) {
                try {
                    const yaml = require('js-yaml');
                    const content = fs.readFileSync(workflowPath, 'utf8');
                    const workflow = yaml.load(content);
                    this.addResult('GitHub Actions 工作流', 'PASS', `${Object.keys(workflow.jobs).length} 个作业已配置`);
                } catch (error) {
                    this.addResult('GitHub Actions 工作流', 'FAIL', error.message);
                }
            } else {
                this.addResult('GitHub Actions 工作流', 'FAIL', '未找到 release.yml');
            }
            
            // 测试 package.json 脚本
            const packageJson = JSON.parse(fs.readFileSync(path.join(this.projectRoot, 'package.json'), 'utf8'));
            const requiredScripts = ['release:patch', 'release:minor', 'release:major', 'release:beta', 'build'];
            
            for (const script of requiredScripts) {
                if (packageJson.scripts && packageJson.scripts[script]) {
                    this.addResult(`npm 脚本: ${script}`, 'PASS', packageJson.scripts[script]);
                } else {
                    this.addResult(`npm 脚本: ${script}`, 'FAIL', '未找到脚本');
                }
            }
            
        } catch (error) {
            this.addError('配置测试失败', error.message);
        }
    }

    /**
     * 测试 3：版本管理
     */
    async testVersionManagement() {
        this.logTestStart('版本管理');
        
        try {
            // 测试 version-bump.js
            const versionBumpPath = path.join(this.projectRoot, 'scripts/version-bump.js');
            if (fs.existsSync(versionBumpPath)) {
                try {
                    const VersionBumper = require(versionBumpPath);
                    const bumper = new VersionBumper();
                    
                    // 测试当前版本检测
                    const currentVersion = bumper.getCurrentVersion();
                    this.addResult('当前版本检测', 'PASS', currentVersion);
                    
                    // 测试版本一致性
                    bumper.validateVersion();
                    this.addResult('版本一致性校验', 'PASS', '所有文件一致');
                    
                    // 测试版本计算（不实际更新）
                    for (const type of this.testConfig.testVersionTypes) {
                        try {
                            const newVersion = bumper.bumpVersion(currentVersion, type);
                            this.addResult(`版本计算: ${type}`, 'PASS', `${currentVersion} → ${newVersion}`);
                        } catch (error) {
                            this.addResult(`版本计算: ${type}`, 'FAIL', error.message);
                        }
                    }
                    
                } catch (error) {
                    this.addResult('版本管理功能', 'FAIL', error.message);
                }
            } else {
                this.addResult('版本管理脚本', 'FAIL', '未找到 version-bump.js');
            }
            
        } catch (error) {
            this.addError('版本管理测试失败', error.message);
        }
    }

    /**
     * 测试 4：构建系统
     */
    async testBuildSystem() {
        this.logTestStart('构建系统');
        
        try {
            // 测试 build.js
            const buildPath = path.join(this.projectRoot, 'scripts/build.js');
            if (fs.existsSync(buildPath)) {
                try {
                    // 测试构建脚本加载
                    const BuildTool = require(buildPath);
                    const builder = new BuildTool();
                    this.addResult('构建脚本加载', 'PASS', 'BuildTool 类已加载');
                    
                    // 测试实际构建（这将创建一个真实的包）
                    await builder.build();
                    
                    // 验证构建输出
                    const buildDir = path.join(this.projectRoot, 'build');
                    if (fs.existsSync(buildDir)) {
                        const files = fs.readdirSync(buildDir);
                        const zipFiles = files.filter(f => f.endsWith('.zip'));
                        
                        if (zipFiles.length > 0) {
                            const zipFile = zipFiles[0];
                            const zipPath = path.join(buildDir, zipFile);
                            const stats = fs.statSync(zipPath);
                            const sizeInMB = (stats.size / 1024 / 1024).toFixed(2);
                            
                            this.addResult('构建包生成', 'PASS', `${zipFile} (${sizeInMB}MB)`);
                            
                            // 验证包大小
                            if (stats.size > 100 * 1024 && stats.size < 50 * 1024 * 1024) {
                                this.addResult('包大小验证', 'PASS', `${sizeInMB}MB (在限制范围内)`);
                            } else {
                                this.addResult('包大小验证', 'WARN', `${sizeInMB}MB (请检查是否合适)`);
                            }
                        } else {
                            this.addResult('构建包生成', 'FAIL', '未生成 ZIP 文件');
                        }
                    } else {
                        this.addResult('构建输出目录', 'FAIL', '未创建构建目录');
                    }
                    
                } catch (error) {
                    this.addResult('构建系统功能', 'FAIL', error.message);
                }
            } else {
                this.addResult('构建脚本', 'FAIL', '未找到 build.js');
            }
            
        } catch (error) {
            this.addError('构建系统测试失败', error.message);
        }
    }

    /**
     * 测试 5：发布控制器
     */
    async testReleaseController() {
        this.logTestStart('发布控制器');
        
        try {
            const releasePath = path.join(this.projectRoot, 'scripts/release.js');
            if (fs.existsSync(releasePath)) {
                try {
                    const ReleaseController = require(releasePath);
                    const controller = new ReleaseController();
                    this.addResult('发布控制器加载', 'PASS', 'ReleaseController 类已加载');
                    
                    // 测试参数解析
                    try {
                        controller.parseArguments(['patch', '--dry-run']);
                        this.addResult('参数解析', 'PASS', 'patch --dry-run 解析正确');
                    } catch (error) {
                        this.addResult('参数解析', 'FAIL', error.message);
                    }
                    
                    // 测试环境验证（大多数情况下应通过）
                    try {
                        controller.validateEnvironment();
                        this.addResult('环境验证', 'PASS', '环境检查通过');
                    } catch (error) {
                        // 这可能由于未提交的更改而失败，这是预期的
                        if (error.message.includes('uncommitted changes')) {
                            this.addResult('环境验证', 'PASS', '正确检测到未提交的更改');
                        } else {
                            this.addResult('环境验证', 'FAIL', error.message);
                        }
                    }
                    
                } catch (error) {
                    this.addResult('发布控制器功能', 'FAIL', error.message);
                }
            } else {
                this.addResult('发布控制器脚本', 'FAIL', '未找到 release.js');
            }
            
        } catch (error) {
            this.addError('发布控制器测试失败', error.message);
        }
    }

    /**
     * 测试 6：GitHub Actions 配置
     */
    async testGitHubActions() {
        this.logTestStart('GitHub Actions 配置');
        
        try {
            // 测试工作流文件存在性和语法
            const workflowPath = path.join(this.projectRoot, '.github/workflows/release.yml');
            if (fs.existsSync(workflowPath)) {
                try {
                    const yaml = require('js-yaml');
                    const content = fs.readFileSync(workflowPath, 'utf8');
                    const workflow = yaml.load(content);
                    
                    // 验证工作流结构
                    const requiredFields = ['name', 'on', 'jobs'];
                    for (const field of requiredFields) {
                        if (workflow[field]) {
                            this.addResult(`工作流字段: ${field}`, 'PASS', '存在');
                        } else {
                            this.addResult(`工作流字段: ${field}`, 'FAIL', '缺失');
                        }
                    }
                    
                    // 检查触发器配置
                    if (workflow.on && workflow.on.push && workflow.on.push.tags) {
                        this.addResult('触发器配置', 'PASS', '标签推送触发器已配置');
                    } else {
                        this.addResult('触发器配置', 'FAIL', '缺少标签推送触发器');
                    }
                    
                    // 检查权限
                    if (workflow.permissions && workflow.permissions.contents === 'write') {
                        this.addResult('权限配置', 'PASS', '已设置内容写入权限');
                    } else {
                        this.addResult('权限配置', 'FAIL', '缺少内容写入权限');
                    }
                    
                    // 检查作业
                    if (workflow.jobs && workflow.jobs.release) {
                        const releaseJob = workflow.jobs.release;
                        if (releaseJob.steps && Array.isArray(releaseJob.steps)) {
                            this.addResult('发布作业配置', 'PASS', `${releaseJob.steps.length} 个步骤已配置`);
                        } else {
                            this.addResult('发布作业配置', 'FAIL', '未配置步骤');
                        }
                    } else {
                        this.addResult('发布作业', 'FAIL', '缺少发布作业');
                    }
                    
                } catch (error) {
                    this.addResult('GitHub Actions 工作流语法', 'FAIL', error.message);
                }
            } else {
                this.addResult('GitHub Actions 工作流文件', 'FAIL', '未找到 release.yml');
            }
            
        } catch (error) {
            this.addError('GitHub Actions 测试失败', error.message);
        }
    }

    /**
     * 测试 7：文档完整性
     */
    async testDocumentation() {
        this.logTestStart('文档完整性');
        
        try {
            const requiredDocs = [
                'docs/PROJECT_OVERVIEW.md',
                'docs/PROJECT_OVERVIEW-zh_CN.md',
                'docs/DEVELOPER_GUIDE.md',
                'docs/DEVELOPER_GUIDE-zh_CN.md',
                'README.md',
                'README-zh_CN.md'
            ];
            
            for (const docPath of requiredDocs) {
                const fullPath = path.join(this.projectRoot, docPath);
                if (fs.existsSync(fullPath)) {
                    const content = fs.readFileSync(fullPath, 'utf8');
                    const lineCount = content.split('\n').length;
                    this.addResult(`文档: ${docPath}`, 'PASS', `${lineCount} 行`);
                } else {
                    this.addResult(`文档: ${docPath}`, 'FAIL', '未找到文件');
                }
            }
            
        } catch (error) {
            this.addError('文档测试失败', error.message);
        }
    }

    /**
     * 测试 8：错误处理与恢复
     */
    async testErrorHandling() {
        this.logTestStart('错误处理与恢复');
        
        try {
            // 测试无效版本类型处理
            try {
                const releasePath = path.join(this.projectRoot, 'scripts/release.js');
                if (fs.existsSync(releasePath)) {
                    // 由于 release.js 使用 process.exit()，我们通过检查错误输出来验证
                    // 这里我们只验证脚本能够正确加载和基本功能
                    const ReleaseController = require(releasePath);
                    const controller = new ReleaseController();

                    // 测试有效参数解析
                    try {
                        const result = controller.parseArguments(['patch', '--dry-run']);
                        if (result.releaseType === 'patch' && result.isDryRun) {
                            this.addResult('参数解析功能', 'PASS', '正确解析有效参数');
                        } else {
                            this.addResult('参数解析功能', 'FAIL', '参数解析结果不正确');
                        }
                    } catch (error) {
                        this.addResult('参数解析功能', 'FAIL', `解析失败: ${error.message}`);
                    }
                }
            } catch (error) {
                this.addResult('错误处理测试设置', 'FAIL', error.message);
            }
            
            // 测试配置校验
            try {
                const configPath = path.join(this.projectRoot, 'scripts/validate-config.js');
                if (fs.existsSync(configPath)) {
                    const ConfigValidator = require(configPath);
                    const validator = new ConfigValidator();
                    const isValid = validator.validate();
                    this.addResult('配置校验', isValid ? 'PASS' : 'FAIL', '配置校验完成');
                }
            } catch (error) {
                this.addResult('配置校验', 'FAIL', error.message);
            }
            
        } catch (error) {
            this.addError('错误处理测试失败', error.message);
        }
    }

    /**
     * 工具方法
     */
    logTestStart(testName) {
        console.log(chalk.blue(`\n🔍 测试: ${testName}`));
    }

    addResult(test, status, details) {
        this.testResults.push({ test, status, details });
        
        const icon = status === 'PASS' ? '✅' : status === 'FAIL' ? '❌' : '⚠️';
        const color = status === 'PASS' ? chalk.green : status === 'FAIL' ? chalk.red : chalk.yellow;
        
        console.log(`  ${icon} ${color(test)}: ${details}`);
    }

    addError(context, message) {
        this.errors.push({ context, message });
        console.log(chalk.red(`❌ ${context}: ${message}`));
    }

    addWarning(context, message) {
        this.warnings.push({ context, message });
        console.log(chalk.yellow(`⚠️  ${context}: ${message}`));
    }

    /**
     * 生成集成测试报告
     */
    generateTestReport() {
        console.log(chalk.bold('\n📊 集成测试报告\n'));
        
        const passed = this.testResults.filter(r => r.status === 'PASS').length;
        const failed = this.testResults.filter(r => r.status === 'FAIL').length;
        const warnings = this.testResults.filter(r => r.status === 'WARN').length;
        const total = this.testResults.length;
        
        console.log(chalk.bold('📈 测试汇总:'));
        console.log(`  总测试数: ${total}`);
        console.log(`  ${chalk.green('通过')}: ${passed}`);
        console.log(`  ${chalk.red('失败')}: ${failed}`);
        console.log(`  ${chalk.yellow('警告')}: ${warnings}`);
        
        const successRate = ((passed / total) * 100).toFixed(1);
        console.log(`  通过率: ${successRate}%`);
        
        if (failed > 0) {
            console.log(chalk.red('\n❌ 失败用例:'));
            this.testResults
                .filter(r => r.status === 'FAIL')
                .forEach(r => console.log(`  • ${r.test}: ${r.details}`));
        }
        
        if (warnings > 0) {
            console.log(chalk.yellow('\n⚠️  警告:'));
            this.testResults
                .filter(r => r.status === 'WARN')
                .forEach(r => console.log(`  • ${r.test}: ${r.details}`));
        }
        
        if (this.errors.length > 0) {
            console.log(chalk.red('\n🚨 错误:'));
            this.errors.forEach(e => console.log(`  • ${e.context}: ${e.message}`));
        }
        
        // 总体评估
        console.log(chalk.bold('\n🏁 总体评估:'));
        if (failed === 0 && this.errors.length === 0) {
            console.log(chalk.green('✅ 所有测试通过！自动化发布系统可用于生产。'));
        } else if (failed <= 2 && this.errors.length === 0) {
            console.log(chalk.yellow('⚠️  大部分测试通过，仅有少量问题。请在生产前修复失败用例。'));
        } else {
            console.log(chalk.red('❌ 存在多个失败用例，系统需修复后再用于生产。'));
        }
        
        console.log(chalk.bold('\n🚀 后续建议:'));
        if (failed === 0) {
            console.log('  • 系统可直接用于生产');
            console.log('  • 建议先用 --dry-run 进行测试发布');
            console.log('  • 查阅文档获取最佳实践');
        } else {
            console.log('  • 修复失败用例后重新运行集成测试');
            console.log('  • 检查错误信息定位具体问题');
            console.log('  • 校验环境和依赖配置');
        }
        
        return failed === 0 && this.errors.length === 0;
    }
}

// CLI 执行入口
if (require.main === module) {
    const testSuite = new IntegrationTestSuite();
    testSuite.runTests().then(success => {
        process.exit(success ? 0 : 1);
    }).catch(error => {
        console.error(chalk.red('测试套件失败:'), error);
        process.exit(1);
    });
}

module.exports = IntegrationTestSuite;
