#!/usr/bin/env node

/**
 * 统一验证工具
 * 
 * 合并所有验证功能，包括配置验证、GitHub Actions验证、
 * 环境验证和集成测试等。
 * 
 * @author Frank-Loong
 * @version 2.0.0-beta.1
 */

const fs = require('fs');
const path = require('path');
const yaml = require('js-yaml');
const chalk = require('chalk');

// 导入统一模块
const config = require('./lib/config');
const Utils = require('./lib/utils');

class ValidationTool {
    constructor() {
        this.projectRoot = config.getProjectRoot();
        this.errors = [];
        this.warnings = [];
        this.passed = [];
    }

    /**
     * 添加错误
     */
    addError(test, message) {
        this.errors.push({ test, message });
        Utils.error(`${test}: ${message}`);
    }

    /**
     * 添加警告
     */
    addWarning(test, message) {
        this.warnings.push({ test, message });
        Utils.warn(`${test}: ${message}`);
    }

    /**
     * 添加通过的测试
     */
    addPassed(test, message = '') {
        this.passed.push({ test, message });
        Utils.success(`${test}${message ? ': ' + message : ''}`);
    }

    /**
     * 验证项目配置
     */
    validateProjectConfig() {
        Utils.info('验证项目配置...');
        
        try {
            const projectConfig = config.getProjectInfo();
            
            // 检查必需字段
            const requiredFields = ['name', 'displayName', 'description', 'author', 'license'];
            for (const field of requiredFields) {
                if (!projectConfig[field]) {
                    this.addError('项目配置', `缺少必需字段: ${field}`);
                } else {
                    this.addPassed(`项目配置.${field}`, projectConfig[field]);
                }
            }
            
        } catch (error) {
            this.addError('项目配置', `配置加载失败: ${error.message}`);
        }
    }

    /**
     * 验证版本配置
     */
    validateVersionConfig() {
        Utils.info('验证版本配置...');
        
        try {
            const versionConfig = config.getVersionConfig();
            
            if (!versionConfig.files || versionConfig.files.length === 0) {
                this.addError('版本配置', '未找到版本文件配置');
                return;
            }
            
            this.addPassed('版本配置', `管理 ${versionConfig.files.length} 个版本文件`);
            
            // 检查版本文件是否存在
            let missingFiles = 0;
            for (const fileConfig of versionConfig.files) {
                const filePath = path.join(this.projectRoot, fileConfig.path);
                if (!fs.existsSync(filePath)) {
                    this.addWarning('版本文件', `文件不存在: ${fileConfig.path}`);
                    missingFiles++;
                }
            }
            
            if (missingFiles === 0) {
                this.addPassed('版本文件', '所有版本文件都存在');
            }
            
        } catch (error) {
            this.addError('版本配置', `验证失败: ${error.message}`);
        }
    }

    /**
     * 验证构建配置
     */
    validateBuildConfig() {
        Utils.info('验证构建配置...');
        
        try {
            const buildConfig = config.getBuildConfig();
            
            // 检查输出配置
            if (!buildConfig.output || !buildConfig.output.directory) {
                this.addError('构建配置', '缺少输出目录配置');
            } else {
                this.addPassed('构建输出', buildConfig.output.directory);
            }
            
            // 检查包含文件
            if (!buildConfig.include || !buildConfig.include.files) {
                this.addError('构建配置', '缺少包含文件配置');
            } else {
                this.addPassed('构建包含', `${buildConfig.include.files.length} 个文件`);
            }
            
            // 检查包含目录
            if (!buildConfig.include || !buildConfig.include.directories) {
                this.addError('构建配置', '缺少包含目录配置');
            } else {
                this.addPassed('构建目录', `${buildConfig.include.directories.length} 个目录`);
            }
            
        } catch (error) {
            this.addError('构建配置', `验证失败: ${error.message}`);
        }
    }

    /**
     * 验证Git配置
     */
    validateGitConfig() {
        Utils.info('验证Git配置...');
        
        try {
            const gitConfig = config.getGitConfig();
            
            // 检查分支配置
            if (!gitConfig.branch || !gitConfig.branch.main) {
                this.addError('Git配置', '缺少主分支配置');
            } else {
                this.addPassed('Git主分支', gitConfig.branch.main);
            }
            
            // 检查标签配置
            if (!gitConfig.tag || !gitConfig.tag.format) {
                this.addError('Git配置', '缺少标签格式配置');
            } else {
                this.addPassed('Git标签格式', gitConfig.tag.format);
            }
            
        } catch (error) {
            this.addError('Git配置', `验证失败: ${error.message}`);
        }
    }

    /**
     * 验证GitHub配置
     */
    validateGitHubConfig() {
        Utils.info('验证GitHub配置...');
        
        try {
            const githubConfig = config.getGitHubConfig();
            
            // 检查仓库配置
            if (!githubConfig.repository) {
                this.addError('GitHub配置', '缺少仓库配置');
            } else {
                this.addPassed('GitHub仓库', githubConfig.repository);
            }
            
        } catch (error) {
            this.addError('GitHub配置', `验证失败: ${error.message}`);
        }
    }

    /**
     * 验证环境配置
     */
    validateEnvironmentConfig() {
        Utils.info('验证环境配置...');
        
        try {
            const envConfig = config.getEnvironmentConfig();
            
            // 检查Node.js版本要求
            if (envConfig.node && envConfig.node.version) {
                this.addPassed('Node.js要求', envConfig.node.version);
            }
            
            // 检查PHP版本要求
            if (envConfig.php && envConfig.php.version) {
                this.addPassed('PHP要求', envConfig.php.version);
            }
            
        } catch (error) {
            this.addError('环境配置', `验证失败: ${error.message}`);
        }
    }

    /**
     * 验证文件引用
     */
    validateFileReferences() {
        Utils.info('验证文件引用...');
        
        try {
            const fullConfig = config.getConfig();
            
            // 检查版本文件
            if (fullConfig.version && fullConfig.version.files) {
                for (const file of fullConfig.version.files) {
                    const filePath = path.join(this.projectRoot, file.path);
                    if (!fs.existsSync(filePath)) {
                        this.addWarning('文件引用', `未找到引用的文件：${file.path}`);
                    }
                }
            }
            
            // 检查构建包含的文件
            if (fullConfig.build && fullConfig.build.include && fullConfig.build.include.files) {
                for (const file of fullConfig.build.include.files) {
                    const filePath = path.join(this.projectRoot, file);
                    if (!fs.existsSync(filePath)) {
                        this.addWarning('文件引用', `未找到包含的文件：${file}`);
                    }
                }
            }
            
            // 检查构建包含的目录
            if (fullConfig.build && fullConfig.build.include && fullConfig.build.include.directories) {
                for (const dir of fullConfig.build.include.directories) {
                    const dirPath = path.join(this.projectRoot, dir);
                    if (!fs.existsSync(dirPath)) {
                        this.addWarning('文件引用', `未找到包含的目录：${dir}`);
                    }
                }
            }
            
            this.addPassed('文件引用校验');
            
        } catch (error) {
            this.addError('文件引用', `验证失败: ${error.message}`);
        }
    }

    /**
     * 验证GitHub Actions工作流
     */
    validateGitHubActions() {
        Utils.info('验证GitHub Actions工作流...');
        
        const workflowPath = path.join(this.projectRoot, '.github/workflows/release.yml');
        
        try {
            // 检查工作流文件是否存在
            if (!fs.existsSync(workflowPath)) {
                this.addError('GitHub Actions', '未找到工作流文件: .github/workflows/release.yml');
                return;
            }
            
            // 读取并解析 YAML
            const content = fs.readFileSync(workflowPath, 'utf8');
            const workflow = yaml.load(content);
            
            // 验证工作流结构
            this.validateWorkflowStructure(workflow);
            this.validateWorkflowTriggers(workflow);
            this.validateWorkflowPermissions(workflow);
            this.validateWorkflowJobs(workflow);
            
            this.addPassed('GitHub Actions', `工作流配置有效`);
            
        } catch (error) {
            this.addError('GitHub Actions', `验证失败: ${error.message}`);
        }
    }

    /**
     * 验证工作流结构
     */
    validateWorkflowStructure(workflow) {
        const required = ['name', 'on', 'jobs'];
        for (const field of required) {
            if (!workflow[field]) {
                this.addError('工作流结构', `缺少必需字段: ${field}`);
            }
        }
    }

    /**
     * 验证工作流触发器
     */
    validateWorkflowTriggers(workflow) {
        if (!workflow.on || !workflow.on.push || !workflow.on.push.tags) {
            this.addError('工作流触发器', '缺少标签推送触发器');
        }
    }

    /**
     * 验证工作流权限
     */
    validateWorkflowPermissions(workflow) {
        if (!workflow.permissions) {
            this.addWarning('工作流权限', '未设置权限配置');
        }
    }

    /**
     * 验证工作流任务
     */
    validateWorkflowJobs(workflow) {
        if (!workflow.jobs || Object.keys(workflow.jobs).length === 0) {
            this.addError('工作流任务', '未找到任务配置');
        }
    }

    /**
     * 验证环境依赖
     */
    validateEnvironment() {
        Utils.info('验证环境依赖...');
        
        // 检查Node.js
        const nodeResult = Utils.execCommand('node --version');
        if (nodeResult.success) {
            this.addPassed('Node.js', nodeResult.output);
        } else {
            this.addError('环境依赖', 'Node.js 未安装或不可用');
        }
        
        // 检查npm
        const npmResult = Utils.execCommand('npm --version');
        if (npmResult.success) {
            this.addPassed('npm', npmResult.output);
        } else {
            this.addError('环境依赖', 'npm 未安装或不可用');
        }
        
        // 检查Git
        const gitResult = Utils.execCommand('git --version');
        if (gitResult.success) {
            this.addPassed('Git', gitResult.output);
        } else {
            this.addError('环境依赖', 'Git 未安装或不可用');
        }
    }

    /**
     * 运行版本一致性测试
     */
    testVersionConsistency() {
        Utils.info('测试版本一致性...');
        
        try {
            const VersionManager = require('./version');
            const versionManager = new VersionManager();
            
            // 获取当前版本
            const currentVersion = versionManager.getCurrentVersion();
            
            // 验证版本一致性
            const consistentVersion = versionManager.validateVersion();
            
            if (currentVersion === consistentVersion) {
                this.addPassed('版本一致性', `所有文件版本一致: ${currentVersion}`);
            } else {
                this.addError('版本一致性', '版本号不一致');
            }
            
        } catch (error) {
            this.addError('版本一致性', `测试失败: ${error.message}`);
        }
    }

    /**
     * 运行完整验证
     */
    async runAll() {
        Utils.info(chalk.bold('🔍 发布配置校验工具\n'));
        
        // 重置状态
        this.errors = [];
        this.warnings = [];
        this.passed = [];
        
        try {
            // 验证配置
            this.validateProjectConfig();
            this.validateVersionConfig();
            this.validateBuildConfig();
            this.validateGitConfig();
            this.validateGitHubConfig();
            this.validateEnvironmentConfig();
            this.validateFileReferences();
            
            // 验证GitHub Actions
            this.validateGitHubActions();
            
            // 验证环境
            this.validateEnvironment();
            
            // 测试版本一致性
            this.testVersionConsistency();
            
            // 生成报告
            this.generateReport();
            
            return this.errors.length === 0;
            
        } catch (error) {
            this.addError('验证过程', `验证失败: ${error.message}`);
            this.generateReport();
            return false;
        }
    }

    /**
     * 生成验证报告
     */
    generateReport() {
        console.log('\n' + '='.repeat(60));
        console.log(chalk.bold('📋 验证报告'));
        console.log('='.repeat(60));
        
        // 显示统计
        console.log(chalk.green(`✅ 通过: ${this.passed.length}`));
        console.log(chalk.yellow(`⚠️  警告: ${this.warnings.length}`));
        console.log(chalk.red(`❌ 错误: ${this.errors.length}`));
        
        // 显示详细信息
        if (this.errors.length > 0) {
            console.log(chalk.red('\n❌ 错误详情:'));
            this.errors.forEach(error => {
                console.log(`  • ${error.test}: ${error.message}`);
            });
        }
        
        if (this.warnings.length > 0) {
            console.log(chalk.yellow('\n⚠️  警告详情:'));
            this.warnings.forEach(warning => {
                console.log(`  • ${warning.test}: ${warning.message}`);
            });
        }
        
        // 最终结果
        if (this.errors.length === 0) {
            console.log(chalk.green('\n✅ 配置校验通过!'));
            console.log(chalk.blue('🎉 没有发现问题 - 配置完美!'));
        } else {
            console.log(chalk.red('\n❌ 配置校验失败!'));
            console.log(chalk.yellow('请修复上述错误后重新运行验证。'));
        }
    }

    /**
     * 显示帮助信息
     */
    showHelp() {
        const commands = {
            '✅ 验证命令': {
                'npm run validate': '运行所有验证',
                'node scripts/validate.js': '直接运行验证',
                'npm run validate:config': '验证配置文件',
                'npm run validate:github-actions': '验证 GitHub Actions',
                'npm run validate:version': '验证版本一致性'
            },
            '🔍 验证选项': {
                'all': '运行所有验证（默认）',
                'config': '仅验证配置',
                'github-actions': '仅验证GitHub Actions',
                'version': '仅验证版本一致性',
                'environment': '仅验证环境',
                'help': '显示帮助信息'
            }
        };

        Utils.showHelp('验证工具', commands);
    }
}

// 命令行处理
if (require.main === module) {
    const args = process.argv.slice(2);
    const command = args[0] || 'all';

    const validator = new ValidationTool();

    switch (command) {
        case 'all':
            validator.runAll().then(success => {
                process.exit(success ? 0 : 1);
            });
            break;
        case 'config':
            validator.validateProjectConfig();
            validator.validateVersionConfig();
            validator.validateBuildConfig();
            validator.validateGitConfig();
            validator.validateGitHubConfig();
            validator.validateEnvironmentConfig();
            validator.validateFileReferences();
            validator.generateReport();
            process.exit(validator.errors.length === 0 ? 0 : 1);
            break;
        case 'github-actions':
            validator.validateGitHubActions();
            validator.generateReport();
            process.exit(validator.errors.length === 0 ? 0 : 1);
            break;
        case 'version':
            validator.testVersionConsistency();
            validator.generateReport();
            process.exit(validator.errors.length === 0 ? 0 : 1);
            break;
        case 'environment':
            validator.validateEnvironment();
            validator.generateReport();
            process.exit(validator.errors.length === 0 ? 0 : 1);
            break;
        case 'help':
        case '--help':
        case '-h':
            validator.showHelp();
            break;
        default:
            Utils.error(`无效的命令: ${command}`);
            validator.showHelp();
            process.exit(1);
    }
}

module.exports = ValidationTool;