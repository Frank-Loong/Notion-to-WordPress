#!/usr/bin/env node

/**
 * 发布配置校验工具
 * 
 * 本脚本用于校验发布配置文件，确保所有设置项都已正确配置，
 * 并符合发布系统的要求。
 * 
 * @author Frank-Loong
 * @version 1.8.3-test.2
 */

const fs = require('fs');
const path = require('path');
const chalk = require('chalk');

class ConfigValidator {
    constructor() {
        this.projectRoot = path.resolve(__dirname, '..');
        this.configPath = path.join(this.projectRoot, 'release.config.js');
        this.errors = [];
        this.warnings = [];
    }

    /**
     * 校验发布配置
     */
    validate() {
        console.log(chalk.bold('🔍 发布配置校验工具\n'));

        try {
            // 检查配置文件是否存在
            if (!fs.existsSync(this.configPath)) {
                throw new Error('未找到配置文件：release.config.js');
            }

            // 加载配置
            delete require.cache[require.resolve(this.configPath)];
            const configModule = require(this.configPath);
            const config = configModule.getConfig();

            // 校验各个模块
            this.validateProject(config.project);
            this.validateVersion(config.version);
            this.validateBuild(config.build);
            this.validateGit(config.git);
            this.validateGitHub(config.github);
            this.validateEnvironment(config.environment);

            // 检查文件是否存在
            this.validateFileReferences(config);

            // 展示结果
            this.displayResults(config);

            return this.errors.length === 0;

        } catch (error) {
            console.log(chalk.red(`❌ 校验失败：${error.message}`));
            return false;
        }
    }

    /**
     * 校验项目配置
     */
    validateProject(project) {
        if (!project.name) {
            this.errors.push('project.name 为必填项');
        }

        if (!project.displayName) {
            this.warnings.push('建议填写 project.displayName');
        }

        if (!project.description) {
            this.warnings.push('建议填写 project.description');
        }

        if (!project.repository?.url) {
            this.warnings.push('建议填写 project.repository.url');
        }

        console.log(chalk.green('✅ 项目配置校验通过'));
    }

    /**
     * 校验版本配置
     */
    validateVersion(version) {
        if (!version.files || !Array.isArray(version.files)) {
            this.errors.push('version.files 必须是一个数组');
            return;
        }

        if (version.files.length === 0) {
            this.errors.push('version.files 不能为空');
            return;
        }

        // 校验每个版本文件的配置
        for (let i = 0; i < version.files.length; i++) {
            const file = version.files[i];
            
            if (!file.path) {
                this.errors.push(`version.files[${i}].path 为必填项`);
                continue;
            }

            if (!file.patterns || !Array.isArray(file.patterns)) {
                this.errors.push(`version.files[${i}].patterns 必须是一个数组`);
                continue;
            }

            // 检查文件是否存在
            const filePath = path.join(this.projectRoot, file.path);
            if (!fs.existsSync(filePath)) {
                this.warnings.push(`未找到版本文件：${file.path}`);
            }

            // 校验模式
            for (let j = 0; j < file.patterns.length; j++) {
                const pattern = file.patterns[j];
                
                if (!pattern.regex) {
                    this.errors.push(`version.files[${i}].patterns[${j}].regex 为必填项`);
                }

                if (!pattern.replacement) {
                    this.errors.push(`version.files[${i}].patterns[${j}].replacement 为必填项`);
                }
            }
        }

        console.log(chalk.green('✅ 版本配置校验通过'));
    }

    /**
     * 校验构建配置
     */
    validateBuild(build) {
        if (!build.output?.directory) {
            this.errors.push('build.output.directory 为必填项');
        }

        if (!build.output?.filename) {
            this.errors.push('build.output.filename 为必填项');
        }

        // 检查输出目录是否可创建
        const outputDir = path.join(this.projectRoot, build.output.directory);
        try {
            if (!fs.existsSync(outputDir)) {
                fs.mkdirSync(outputDir, { recursive: true });
                fs.rmdirSync(outputDir); // 清理测试目录
            }
        } catch (error) {
            this.errors.push(`无法创建构建输出目录：${build.output.directory}`);
        }

        // 校验包含/排除设置
        if (build.include?.files && !Array.isArray(build.include.files)) {
            this.errors.push('build.include.files 必须是一个数组');
        }

        if (build.exclude?.files && !Array.isArray(build.exclude.files)) {
            this.errors.push('build.exclude.files 必须是一个数组');
        }

        console.log(chalk.green('✅ 构建配置校验通过'));
    }

    /**
     * 校验 Git 配置
     */
    validateGit(git) {
        if (!git.branch?.main) {
            this.errors.push('git.branch.main 为必填项');
        }

        if (!git.remote?.name) {
            this.errors.push('git.remote.name 为必填项');
        }

        if (!git.tag?.prefix && git.tag?.prefix !== '') {
            this.warnings.push('建议填写 git.tag.prefix');
        }

        console.log(chalk.green('✅ Git 配置校验通过'));
    }

    /**
     * 校验 GitHub 配置
     */
    validateGitHub(github) {
        if (!github.repository?.owner) {
            this.errors.push('github.repository.owner 为必填项');
        }

        if (!github.repository?.name) {
            this.errors.push('github.repository.name 为必填项');
        }

        if (!github.assets || !Array.isArray(github.assets)) {
            this.warnings.push('github.assets 应该是一个数组');
        }

        console.log(chalk.green('✅ GitHub 配置校验通过'));
    }

    /**
     * 校验环境配置
     */
    validateEnvironment(environment) {
        if (!environment.node?.minVersion) {
            this.warnings.push('建议设置 environment.node.minVersion');
        }

        if (!environment.requiredTools || !Array.isArray(environment.requiredTools)) {
            this.warnings.push('environment.requiredTools 应该是一个数组');
        }

        console.log(chalk.green('✅ 环境配置校验通过'));
    }

    /**
     * 校验配置中引用的文件
     */
    validateFileReferences(config) {
        // 检查版本文件
        for (const file of config.version.files) {
            const filePath = path.join(this.projectRoot, file.path);
            if (!fs.existsSync(filePath)) {
                this.warnings.push(`未找到引用的文件：${file.path}`);
            }
        }

        // 检查包含的文件
        if (config.build.include?.files) {
            for (const file of config.build.include.files) {
                const filePath = path.join(this.projectRoot, file);
                if (!fs.existsSync(filePath)) {
                    this.warnings.push(`未找到包含的文件：${file}`);
                }
            }
        }

        // 检查包含的目录
        if (config.build.include?.directories) {
            for (const dir of config.build.include.directories) {
                const dirPath = path.join(this.projectRoot, dir);
                if (!fs.existsSync(dirPath)) {
                    this.warnings.push(`未找到包含的目录：${dir}`);
                }
            }
        }

        console.log(chalk.green('✅ 文件引用校验通过'));
    }

    /**
     * 展示校验结果
     */
    displayResults(config) {
        console.log(chalk.bold('\n📋 配置摘要:'));
        console.log(`  • 项目: ${config.project.name}`);
        console.log(`  • 版本文件: ${config.version.files.length}`);
        console.log(`  • 构建输出: ${config.build.output.directory}`);
        console.log(`  • Git 分支: ${config.git.branch.main}`);
        console.log(`  • GitHub 仓库: ${config.github.repository.owner}/${config.github.repository.name}`);

        if (this.warnings.length > 0) {
            console.log(chalk.yellow('\n⚠️  警告:'));
            this.warnings.forEach(warning => {
                console.log(chalk.yellow(`  • ${warning}`));
            });
        }

        if (this.errors.length > 0) {
            console.log(chalk.red('\n❌ 错误:'));
            this.errors.forEach(error => {
                console.log(chalk.red(`  • ${error}`));
            });
            console.log(chalk.red('\n❌ 配置校验失败!'));
        } else {
            console.log(chalk.green('\n✅ 配置校验通过!'));
            if (this.warnings.length === 0) {
                console.log(chalk.green('🎉 没有发现问题 - 配置完美!'));
            }
        }
    }
}

// CLI 执行入口
if (require.main === module) {
    const validator = new ConfigValidator();
    const isValid = validator.validate();
    process.exit(isValid ? 0 : 1);
}

module.exports = ConfigValidator;
