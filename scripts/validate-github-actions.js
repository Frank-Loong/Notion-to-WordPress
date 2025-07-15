#!/usr/bin/env node

/**
 * GitHub Actions 配置校验工具
 * 
 * 本脚本用于校验 GitHub Actions 工作流配置，
 * 确保结构正确且包含所有必需元素。
 * 
 * @author Frank-Loong
 * @version 1.8.3-beta.2
 */

const fs = require('fs');
const path = require('path');
const yaml = require('js-yaml');
const chalk = require('chalk');

class GitHubActionsValidator {
    constructor() {
        this.projectRoot = path.resolve(__dirname, '..');
        this.workflowPath = path.join(this.projectRoot, '.github/workflows/release.yml');
    }

    /**
     * 校验 GitHub Actions 工作流文件
     */
    validate() {
        console.log(chalk.bold('🔍 GitHub Actions 工作流校验器\n'));

        try {
            // 检查工作流文件是否存在
            if (!fs.existsSync(this.workflowPath)) {
                throw new Error('未找到工作流文件: .github/workflows/release.yml');
            }

            // 读取并解析 YAML
            const content = fs.readFileSync(this.workflowPath, 'utf8');
            const workflow = yaml.load(content);

            // 校验工作流结构
            this.validateWorkflowStructure(workflow);
            this.validateTriggers(workflow);
            this.validatePermissions(workflow);
            this.validateJobs(workflow);
            this.validateSteps(workflow);

            console.log(chalk.green('\n✅ GitHub Actions 工作流校验通过！'));
            console.log(chalk.blue('📋 工作流摘要:'));
            console.log(`  • 名称: ${workflow.name}`);
            console.log(`  • 触发器: ${Object.keys(workflow.on).join(', ')}`);
            console.log(`  • 任务数: ${Object.keys(workflow.jobs).length}`);
            console.log(`  • 步骤数: ${this.countSteps(workflow)}`);

            return true;

        } catch (error) {
            console.log(chalk.red(`❌ 校验失败: ${error.message}`));
            return false;
        }
    }

    /**
     * 校验工作流基础结构
     */
    validateWorkflowStructure(workflow) {
        const requiredFields = ['name', 'on', 'jobs'];
        
        for (const field of requiredFields) {
            if (!workflow[field]) {
                throw new Error(`缺少必需字段: ${field}`);
            }
        }

        console.log(chalk.green('✅ 工作流基础结构有效'));
    }

    /**
     * 校验触发器配置
     */
    validateTriggers(workflow) {
        if (!workflow.on.push || !workflow.on.push.tags) {
            throw new Error('缺少 tag 推送触发器');
        }

        const tagPatterns = workflow.on.push.tags;
        if (!Array.isArray(tagPatterns) || !tagPatterns.includes('v*')) {
            throw new Error('缺少或无效的 tag 模式（应包含 v*）');
        }

        console.log(chalk.green('✅ 触发器配置有效'));
    }

    /**
     * 校验权限配置
     */
    validatePermissions(workflow) {
        if (!workflow.permissions) {
            throw new Error('缺少权限配置');
        }

        if (workflow.permissions.contents !== 'write') {
            throw new Error('contents 权限缺失或无效（应为 "write"）');
        }

        console.log(chalk.green('✅ 权限配置有效'));
    }

    /**
     * 校验 jobs 配置
     */
    validateJobs(workflow) {
        if (!workflow.jobs.release) {
            throw new Error('缺少 release 任务');
        }

        const releaseJob = workflow.jobs.release;
        
        if (releaseJob['runs-on'] !== 'ubuntu-latest') {
            throw new Error('release 任务应运行在 ubuntu-latest');
        }

        if (!releaseJob.steps || !Array.isArray(releaseJob.steps)) {
            throw new Error('release 任务缺少 steps');
        }

        console.log(chalk.green('✅ jobs 配置有效'));
    }

    /**
     * 校验工作流步骤
     */
    validateSteps(workflow) {
        const releaseSteps = workflow.jobs.release.steps;
        const requiredSteps = [
            { name: 'Checkout Repository', keywords: ['检出', 'checkout'] },
            { name: 'Setup Node.js', keywords: ['Node.js', 'setup-node'] },
            { name: 'Install Dependencies', keywords: ['安装依赖', 'npm ci'] },
            { name: 'Build Plugin Package', keywords: ['构建', 'build'] },
            { name: 'Create GitHub Release', keywords: ['GitHub Release', 'gh-release'] }
        ];

        for (const requiredStep of requiredSteps) {
            const stepExists = releaseSteps.some(step => {
                if (!step.name && !step.uses && !step.run) return false;

                return requiredStep.keywords.some(keyword => {
                    return (step.name && step.name.includes(keyword)) ||
                           (step.uses && step.uses.includes(keyword)) ||
                           (step.run && step.run.includes(keyword));
                });
            });

            if (!stepExists) {
                throw new Error(`缺少步骤: ${requiredStep.name}`);
            }
        }

        // 校验特定步骤配置
        const checkoutStep = releaseSteps.find(step => step.uses && step.uses.includes('checkout'));
        if (!checkoutStep) {
            throw new Error('缺少 checkout 步骤');
        }

        const nodeStep = releaseSteps.find(step => step.uses && step.uses.includes('setup-node'));
        if (!nodeStep) {
            throw new Error('缺少 Node.js 环境设置步骤');
        }

        console.log(chalk.green('✅ 工作流步骤有效'));
    }

    /**
     * 统计工作流总步骤数
     */
    countSteps(workflow) {
        let totalSteps = 0;
        
        for (const jobName in workflow.jobs) {
            const job = workflow.jobs[jobName];
            if (job.steps && Array.isArray(job.steps)) {
                totalSteps += job.steps.length;
            }
        }
        
        return totalSteps;
    }
}

// CLI 执行入口
if (require.main === module) {
    const validator = new GitHubActionsValidator();
    const isValid = validator.validate();
    process.exit(isValid ? 0 : 1);
}

module.exports = GitHubActionsValidator;
