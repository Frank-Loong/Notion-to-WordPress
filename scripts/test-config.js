#!/usr/bin/env node

/**
 * 配置集成测试
 * 
 * 用于测试发布配置与现有发布系统组件的集成情况。
 * 
 * @author Frank-Loong
 * @version 2.0.0-beta.2
 */

const config = require('../release.config.js');
const chalk = require('chalk');

console.log(chalk.bold('📋 配置集成测试\n'));

try {
    const cfg = config.getConfig();

    console.log(chalk.blue('📋 配置概览:'));
    console.log(`  项目: ${cfg.project.name} (${cfg.project.displayName})`);
    console.log(`  作者: ${cfg.project.author}`);
    console.log(`  许可证: ${cfg.project.license}`);

    console.log(chalk.blue('\n📝 版本管理:'));
    console.log(`  需更新文件数: ${cfg.version.files.length}`);
    cfg.version.files.forEach((file, i) => {
        console.log(`    ${i+1}. ${file.path} (${file.patterns.length} 规则)`);
    });

    console.log(chalk.blue('\n📦 构建配置:'));
    console.log(`  输出目录: ${cfg.build.output.directory}`);
    console.log(`  文件名模板: ${cfg.build.output.filename}`);
    console.log(`  包含文件: ${cfg.build.include.files.length}`);
    console.log(`  包含目录: ${cfg.build.include.directories.length}`);
    console.log(`  排除文件: ${cfg.build.exclude.files.length}`);
    console.log(`  排除目录: ${cfg.build.exclude.directories.length}`);

    console.log(chalk.blue('\n🔧 Git 配置:'));
    console.log(`  主分支: ${cfg.git.branch.main}`);
    console.log(`  Tag 格式: ${cfg.git.tag.format}`);
    console.log(`  提交模板: ${cfg.git.commitMessage.template}`);
    console.log(`  远程: ${cfg.git.remote.name}`);
    
    console.log(chalk.blue('\n🐙 GitHub 配置:'));
    console.log(`  仓库: ${cfg.github.repository.owner}/${cfg.github.repository.name}`);
    console.log(`  自动生成发布说明: ${cfg.github.release.generateReleaseNotes}`);
    console.log(`  上传资源数: ${cfg.github.assets.length}`);
    
    console.log(chalk.blue('\n🌍 环境:'));
    console.log(`  最低 Node.js 版本: ${cfg.environment.node.minVersion}`);
    console.log(`  需工具: ${cfg.environment.requiredTools.join(', ')}`);
    
    console.log(chalk.green('\n✅ 配置集成测试通过!'));
    console.log(chalk.green('🎉 所有配置项结构正确!'));
    
} catch (error) {
    console.log(chalk.red(`❌ 配置测试失败: ${error.message}`));
    process.exit(1);
}
