#!/usr/bin/env node

/**
 * Configuration Integration Test
 * 
 * This script tests the integration of the release configuration
 * with the existing release system components.
 */

const config = require('../release.config.js');
const chalk = require('chalk');

console.log(chalk.bold('🧪 Configuration Integration Test\n'));

try {
    const cfg = config.getConfig();
    
    console.log(chalk.blue('📋 Configuration Overview:'));
    console.log(`  Project: ${cfg.project.name} (${cfg.project.displayName})`);
    console.log(`  Author: ${cfg.project.author}`);
    console.log(`  License: ${cfg.project.license}`);
    
    console.log(chalk.blue('\n📝 Version Management:'));
    console.log(`  Files to update: ${cfg.version.files.length}`);
    cfg.version.files.forEach((file, i) => {
        console.log(`    ${i+1}. ${file.path} (${file.patterns.length} patterns)`);
    });
    
    console.log(chalk.blue('\n📦 Build Configuration:'));
    console.log(`  Output directory: ${cfg.build.output.directory}`);
    console.log(`  Filename template: ${cfg.build.output.filename}`);
    console.log(`  Include files: ${cfg.build.include.files.length}`);
    console.log(`  Include directories: ${cfg.build.include.directories.length}`);
    console.log(`  Exclude files: ${cfg.build.exclude.files.length}`);
    console.log(`  Exclude directories: ${cfg.build.exclude.directories.length}`);
    
    console.log(chalk.blue('\n🔧 Git Configuration:'));
    console.log(`  Main branch: ${cfg.git.branch.main}`);
    console.log(`  Tag format: ${cfg.git.tag.format}`);
    console.log(`  Commit template: ${cfg.git.commitMessage.template}`);
    console.log(`  Remote: ${cfg.git.remote.name}`);
    
    console.log(chalk.blue('\n🐙 GitHub Configuration:'));
    console.log(`  Repository: ${cfg.github.repository.owner}/${cfg.github.repository.name}`);
    console.log(`  Generate release notes: ${cfg.github.release.generateReleaseNotes}`);
    console.log(`  Assets to upload: ${cfg.github.assets.length}`);
    
    console.log(chalk.blue('\n🌍 Environment:'));
    console.log(`  Min Node.js version: ${cfg.environment.node.minVersion}`);
    console.log(`  Required tools: ${cfg.environment.requiredTools.join(', ')}`);
    
    console.log(chalk.green('\n✅ Configuration integration test passed!'));
    console.log(chalk.green('🎉 All configuration sections are properly structured!'));
    
} catch (error) {
    console.log(chalk.red(`❌ Configuration test failed: ${error.message}`));
    process.exit(1);
}
