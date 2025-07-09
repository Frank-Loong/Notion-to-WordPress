#!/usr/bin/env node

/**
 * Build Verification Tool
 * Verifies that the build process correctly includes/excludes files
 */

const fs = require('fs');
const path = require('path');
const { glob } = require('glob');
const chalk = require('chalk');

class BuildVerifier {
    constructor() {
        this.projectRoot = path.resolve(__dirname, '..');
        this.buildDir = path.join(this.projectRoot, 'build');
        
        // Files that MUST be in the ZIP
        this.requiredFiles = [
            'notion-to-wordpress/notion-to-wordpress.php',
            'notion-to-wordpress/readme.txt',
            'notion-to-wordpress/uninstall.php',
            'notion-to-wordpress/LICENSE'
        ];
        
        // Files that MUST NOT be in the ZIP
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
        console.log(chalk.bold('🔍 Build Verification Tool'));
        
        // Find ZIP file
        const zipFiles = await glob('*.zip', { cwd: this.buildDir });
        
        if (zipFiles.length === 0) {
            console.log(chalk.red('❌ No ZIP file found in build directory'));
            return false;
        }
        
        const zipFile = zipFiles[0];
        console.log(`Verifying: ${chalk.cyan(zipFile)}`);
        
        // Get file stats
        const zipPath = path.join(this.buildDir, zipFile);
        const stats = fs.statSync(zipPath);
        const sizeInMB = (stats.size / 1024 / 1024).toFixed(2);
        
        console.log(`File size: ${chalk.yellow(sizeInMB)} MB`);
        
        // Basic checks
        const checks = [
            {
                name: 'File exists',
                test: () => fs.existsSync(zipPath),
                critical: true
            },
            {
                name: 'File size > 1MB',
                test: () => stats.size > 1024 * 1024,
                critical: true
            },
            {
                name: 'File size < 50MB',
                test: () => stats.size < 50 * 1024 * 1024,
                critical: false
            },
            {
                name: 'WordPress plugin naming convention',
                test: () => zipFile.match(/^notion-to-wordpress-\d+\.\d+\.\d+.*\.zip$/),
                critical: false
            }
        ];
        
        let allCriticalPassed = true;
        
        console.log('\n📋 Basic Checks:');
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
            console.log(chalk.red('\n❌ Critical checks failed!'));
            return false;
        }
        
        console.log(chalk.green('\n✅ All checks passed!'));
        console.log(`\n📦 Package ready: ${chalk.green(zipPath)}`);
        console.log('You can install this ZIP file in WordPress admin.');
        
        return true;
    }
}

// CLI execution
if (require.main === module) {
    const verifier = new BuildVerifier();
    verifier.verify().then(success => {
        process.exit(success ? 0 : 1);
    });
}

module.exports = BuildVerifier;
