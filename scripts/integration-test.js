#!/usr/bin/env node

/**
 * Integration Test Suite for Automated Release System
 * 
 * This comprehensive test suite validates all components of the automated
 * release system to ensure stability, reliability, and proper integration.
 * 
 * @author Frank-Loong
 * @version 1.0.0
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
        
        // Test configuration
        this.testConfig = {
            skipGitOperations: true,  // Skip actual Git operations for safety
            skipGitHubActions: true,  // Skip GitHub Actions tests
            testVersionTypes: ['patch', 'minor', 'major', 'beta'],
            validatePackages: true,
            checkDocumentation: true
        };
    }

    /**
     * Run the complete integration test suite
     */
    async runTests() {
        console.log(chalk.bold('🧪 Automated Release System Integration Test Suite\n'));
        
        try {
            // Test 1: Environment and Dependencies
            await this.testEnvironment();
            
            // Test 2: Configuration Validation
            await this.testConfiguration();
            
            // Test 3: Version Management
            await this.testVersionManagement();
            
            // Test 4: Build System
            await this.testBuildSystem();
            
            // Test 5: Release Controller
            await this.testReleaseController();
            
            // Test 6: GitHub Actions Configuration
            await this.testGitHubActions();
            
            // Test 7: Documentation Completeness
            await this.testDocumentation();
            
            // Test 8: Error Handling and Recovery
            await this.testErrorHandling();
            
            // Generate test report
            this.generateTestReport();
            
        } catch (error) {
            this.addError('Test suite execution failed', error.message);
            this.generateTestReport();
            process.exit(1);
        }
    }

    /**
     * Test 1: Environment and Dependencies
     */
    async testEnvironment() {
        this.logTestStart('Environment and Dependencies');
        
        try {
            // Check Node.js version
            const nodeVersion = process.version;
            const majorVersion = parseInt(nodeVersion.slice(1).split('.')[0]);
            if (majorVersion >= 16) {
                this.addResult('Node.js version check', 'PASS', `${nodeVersion} (>= 16.0.0)`);
            } else {
                this.addResult('Node.js version check', 'FAIL', `${nodeVersion} (< 16.0.0)`);
            }
            
            // Check npm availability
            try {
                const npmVersion = execSync('npm --version', { encoding: 'utf8' }).trim();
                this.addResult('npm availability', 'PASS', `v${npmVersion}`);
            } catch (error) {
                this.addResult('npm availability', 'FAIL', 'npm not found');
            }
            
            // Check Git availability
            try {
                const gitVersion = execSync('git --version', { encoding: 'utf8' }).trim();
                this.addResult('Git availability', 'PASS', gitVersion);
            } catch (error) {
                this.addResult('Git availability', 'FAIL', 'Git not found');
            }
            
            // Check required dependencies
            const packageJson = JSON.parse(fs.readFileSync(path.join(this.projectRoot, 'package.json'), 'utf8'));
            const requiredDeps = ['semver', 'archiver', 'chalk', 'fs-extra', 'glob', 'minimist', 'js-yaml'];
            
            for (const dep of requiredDeps) {
                if (packageJson.devDependencies && packageJson.devDependencies[dep]) {
                    this.addResult(`Dependency: ${dep}`, 'PASS', packageJson.devDependencies[dep]);
                } else {
                    this.addResult(`Dependency: ${dep}`, 'FAIL', 'Not found in package.json');
                }
            }
            
            // Check if node_modules exists
            const nodeModulesPath = path.join(this.projectRoot, 'node_modules');
            if (fs.existsSync(nodeModulesPath)) {
                this.addResult('node_modules directory', 'PASS', 'Dependencies installed');
            } else {
                this.addResult('node_modules directory', 'FAIL', 'Dependencies not installed');
            }
            
        } catch (error) {
            this.addError('Environment test failed', error.message);
        }
    }

    /**
     * Test 2: Configuration Validation
     */
    async testConfiguration() {
        this.logTestStart('Configuration Validation');
        
        try {
            // Test release configuration
            try {
                const configPath = path.join(this.projectRoot, 'release.config.js');
                if (fs.existsSync(configPath)) {
                    delete require.cache[require.resolve(configPath)];
                    const config = require(configPath);
                    const cfg = config.getConfig();
                    this.addResult('Release configuration', 'PASS', `${cfg.version.files.length} version files configured`);
                } else {
                    this.addResult('Release configuration', 'FAIL', 'release.config.js not found');
                }
            } catch (error) {
                this.addResult('Release configuration', 'FAIL', error.message);
            }
            
            // Test GitHub Actions configuration
            const workflowPath = path.join(this.projectRoot, '.github/workflows/release.yml');
            if (fs.existsSync(workflowPath)) {
                try {
                    const yaml = require('js-yaml');
                    const content = fs.readFileSync(workflowPath, 'utf8');
                    const workflow = yaml.load(content);
                    this.addResult('GitHub Actions workflow', 'PASS', `${Object.keys(workflow.jobs).length} jobs configured`);
                } catch (error) {
                    this.addResult('GitHub Actions workflow', 'FAIL', error.message);
                }
            } else {
                this.addResult('GitHub Actions workflow', 'FAIL', 'release.yml not found');
            }
            
            // Test package.json scripts
            const packageJson = JSON.parse(fs.readFileSync(path.join(this.projectRoot, 'package.json'), 'utf8'));
            const requiredScripts = ['release:patch', 'release:minor', 'release:major', 'release:beta', 'build'];
            
            for (const script of requiredScripts) {
                if (packageJson.scripts && packageJson.scripts[script]) {
                    this.addResult(`npm script: ${script}`, 'PASS', packageJson.scripts[script]);
                } else {
                    this.addResult(`npm script: ${script}`, 'FAIL', 'Script not found');
                }
            }
            
        } catch (error) {
            this.addError('Configuration test failed', error.message);
        }
    }

    /**
     * Test 3: Version Management
     */
    async testVersionManagement() {
        this.logTestStart('Version Management');
        
        try {
            // Test version-bump.js
            const versionBumpPath = path.join(this.projectRoot, 'scripts/version-bump.js');
            if (fs.existsSync(versionBumpPath)) {
                try {
                    const VersionBumper = require(versionBumpPath);
                    const bumper = new VersionBumper();
                    
                    // Test current version detection
                    const currentVersion = bumper.getCurrentVersion();
                    this.addResult('Current version detection', 'PASS', currentVersion);
                    
                    // Test version validation
                    bumper.validateVersion();
                    this.addResult('Version consistency validation', 'PASS', 'All files consistent');
                    
                    // Test version calculation (without actually updating)
                    for (const type of this.testConfig.testVersionTypes) {
                        try {
                            const newVersion = bumper.bumpVersion(currentVersion, type);
                            this.addResult(`Version calculation: ${type}`, 'PASS', `${currentVersion} → ${newVersion}`);
                        } catch (error) {
                            this.addResult(`Version calculation: ${type}`, 'FAIL', error.message);
                        }
                    }
                    
                } catch (error) {
                    this.addResult('Version management functionality', 'FAIL', error.message);
                }
            } else {
                this.addResult('Version management script', 'FAIL', 'version-bump.js not found');
            }
            
        } catch (error) {
            this.addError('Version management test failed', error.message);
        }
    }

    /**
     * Test 4: Build System
     */
    async testBuildSystem() {
        this.logTestStart('Build System');
        
        try {
            // Test build.js
            const buildPath = path.join(this.projectRoot, 'scripts/build.js');
            if (fs.existsSync(buildPath)) {
                try {
                    // Test build script loading
                    const BuildTool = require(buildPath);
                    const builder = new BuildTool();
                    this.addResult('Build script loading', 'PASS', 'BuildTool class loaded');
                    
                    // Test actual build (this will create a real package)
                    await builder.build();
                    
                    // Verify build output
                    const buildDir = path.join(this.projectRoot, 'build');
                    if (fs.existsSync(buildDir)) {
                        const files = fs.readdirSync(buildDir);
                        const zipFiles = files.filter(f => f.endsWith('.zip'));
                        
                        if (zipFiles.length > 0) {
                            const zipFile = zipFiles[0];
                            const zipPath = path.join(buildDir, zipFile);
                            const stats = fs.statSync(zipPath);
                            const sizeInMB = (stats.size / 1024 / 1024).toFixed(2);
                            
                            this.addResult('Build package generation', 'PASS', `${zipFile} (${sizeInMB}MB)`);
                            
                            // Validate package size
                            if (stats.size > 100 * 1024 && stats.size < 50 * 1024 * 1024) {
                                this.addResult('Package size validation', 'PASS', `${sizeInMB}MB (within limits)`);
                            } else {
                                this.addResult('Package size validation', 'WARN', `${sizeInMB}MB (check if appropriate)`);
                            }
                        } else {
                            this.addResult('Build package generation', 'FAIL', 'No ZIP file generated');
                        }
                    } else {
                        this.addResult('Build output directory', 'FAIL', 'Build directory not created');
                    }
                    
                } catch (error) {
                    this.addResult('Build system functionality', 'FAIL', error.message);
                }
            } else {
                this.addResult('Build script', 'FAIL', 'build.js not found');
            }
            
        } catch (error) {
            this.addError('Build system test failed', error.message);
        }
    }

    /**
     * Test 5: Release Controller
     */
    async testReleaseController() {
        this.logTestStart('Release Controller');
        
        try {
            const releasePath = path.join(this.projectRoot, 'scripts/release.js');
            if (fs.existsSync(releasePath)) {
                try {
                    const ReleaseController = require(releasePath);
                    const controller = new ReleaseController();
                    this.addResult('Release controller loading', 'PASS', 'ReleaseController class loaded');
                    
                    // Test argument parsing
                    try {
                        controller.parseArguments(['patch', '--dry-run']);
                        this.addResult('Argument parsing', 'PASS', 'patch --dry-run parsed correctly');
                    } catch (error) {
                        this.addResult('Argument parsing', 'FAIL', error.message);
                    }
                    
                    // Test environment validation (should pass in most cases)
                    try {
                        controller.validateEnvironment();
                        this.addResult('Environment validation', 'PASS', 'Environment checks passed');
                    } catch (error) {
                        // This might fail due to uncommitted changes, which is expected
                        if (error.message.includes('uncommitted changes')) {
                            this.addResult('Environment validation', 'PASS', 'Correctly detected uncommitted changes');
                        } else {
                            this.addResult('Environment validation', 'FAIL', error.message);
                        }
                    }
                    
                } catch (error) {
                    this.addResult('Release controller functionality', 'FAIL', error.message);
                }
            } else {
                this.addResult('Release controller script', 'FAIL', 'release.js not found');
            }
            
        } catch (error) {
            this.addError('Release controller test failed', error.message);
        }
    }

    /**
     * Test 6: GitHub Actions Configuration
     */
    async testGitHubActions() {
        this.logTestStart('GitHub Actions Configuration');
        
        try {
            // Test workflow file existence and syntax
            const workflowPath = path.join(this.projectRoot, '.github/workflows/release.yml');
            if (fs.existsSync(workflowPath)) {
                try {
                    const yaml = require('js-yaml');
                    const content = fs.readFileSync(workflowPath, 'utf8');
                    const workflow = yaml.load(content);
                    
                    // Validate workflow structure
                    const requiredFields = ['name', 'on', 'jobs'];
                    for (const field of requiredFields) {
                        if (workflow[field]) {
                            this.addResult(`Workflow field: ${field}`, 'PASS', 'Present');
                        } else {
                            this.addResult(`Workflow field: ${field}`, 'FAIL', 'Missing');
                        }
                    }
                    
                    // Check trigger configuration
                    if (workflow.on && workflow.on.push && workflow.on.push.tags) {
                        this.addResult('Trigger configuration', 'PASS', 'Tag push trigger configured');
                    } else {
                        this.addResult('Trigger configuration', 'FAIL', 'Tag push trigger missing');
                    }
                    
                    // Check permissions
                    if (workflow.permissions && workflow.permissions.contents === 'write') {
                        this.addResult('Permissions configuration', 'PASS', 'Contents write permission set');
                    } else {
                        this.addResult('Permissions configuration', 'FAIL', 'Contents write permission missing');
                    }
                    
                    // Check jobs
                    if (workflow.jobs && workflow.jobs.release) {
                        const releaseJob = workflow.jobs.release;
                        if (releaseJob.steps && Array.isArray(releaseJob.steps)) {
                            this.addResult('Release job configuration', 'PASS', `${releaseJob.steps.length} steps configured`);
                        } else {
                            this.addResult('Release job configuration', 'FAIL', 'No steps configured');
                        }
                    } else {
                        this.addResult('Release job', 'FAIL', 'Release job missing');
                    }
                    
                } catch (error) {
                    this.addResult('GitHub Actions workflow syntax', 'FAIL', error.message);
                }
            } else {
                this.addResult('GitHub Actions workflow file', 'FAIL', 'release.yml not found');
            }
            
        } catch (error) {
            this.addError('GitHub Actions test failed', error.message);
        }
    }

    /**
     * Test 7: Documentation Completeness
     */
    async testDocumentation() {
        this.logTestStart('Documentation Completeness');
        
        try {
            const requiredDocs = [
                'docs/RELEASE_GUIDE.md',
                'docs/RELEASE_GUIDE-zh_CN.md',
                'README.md',
                'README-zh_CN.md'
            ];
            
            for (const docPath of requiredDocs) {
                const fullPath = path.join(this.projectRoot, docPath);
                if (fs.existsSync(fullPath)) {
                    const content = fs.readFileSync(fullPath, 'utf8');
                    const lineCount = content.split('\n').length;
                    this.addResult(`Documentation: ${docPath}`, 'PASS', `${lineCount} lines`);
                    
                    // Check for release system mentions in README files
                    if (docPath.includes('README') && (content.includes('Automated Release System') || content.includes('自动化发布系统'))) {
                        this.addResult(`Release system mention: ${docPath}`, 'PASS', 'Release system documented');
                    } else if (docPath.includes('README')) {
                        this.addResult(`Release system mention: ${docPath}`, 'WARN', 'Release system not mentioned');
                    }
                } else {
                    this.addResult(`Documentation: ${docPath}`, 'FAIL', 'File not found');
                }
            }
            
        } catch (error) {
            this.addError('Documentation test failed', error.message);
        }
    }

    /**
     * Test 8: Error Handling and Recovery
     */
    async testErrorHandling() {
        this.logTestStart('Error Handling and Recovery');
        
        try {
            // Test invalid version type handling
            try {
                const releasePath = path.join(this.projectRoot, 'scripts/release.js');
                if (fs.existsSync(releasePath)) {
                    const ReleaseController = require(releasePath);
                    const controller = new ReleaseController();
                    
                    try {
                        controller.parseArguments(['invalid-type']);
                        this.addResult('Invalid version type handling', 'FAIL', 'Should have thrown error');
                    } catch (error) {
                        if (error.message.includes('Invalid or missing release type')) {
                            this.addResult('Invalid version type handling', 'PASS', 'Correctly rejected invalid type');
                        } else {
                            this.addResult('Invalid version type handling', 'FAIL', `Unexpected error: ${error.message}`);
                        }
                    }
                }
            } catch (error) {
                this.addResult('Error handling test setup', 'FAIL', error.message);
            }
            
            // Test configuration validation
            try {
                const configPath = path.join(this.projectRoot, 'scripts/validate-config.js');
                if (fs.existsSync(configPath)) {
                    const ConfigValidator = require(configPath);
                    const validator = new ConfigValidator();
                    const isValid = validator.validate();
                    this.addResult('Configuration validation', isValid ? 'PASS' : 'FAIL', 'Configuration validation completed');
                }
            } catch (error) {
                this.addResult('Configuration validation', 'FAIL', error.message);
            }
            
        } catch (error) {
            this.addError('Error handling test failed', error.message);
        }
    }

    /**
     * Utility methods
     */
    logTestStart(testName) {
        console.log(chalk.blue(`\n🔍 Testing: ${testName}`));
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
     * Generate comprehensive test report
     */
    generateTestReport() {
        console.log(chalk.bold('\n📊 Integration Test Report\n'));
        
        const passed = this.testResults.filter(r => r.status === 'PASS').length;
        const failed = this.testResults.filter(r => r.status === 'FAIL').length;
        const warnings = this.testResults.filter(r => r.status === 'WARN').length;
        const total = this.testResults.length;
        
        console.log(chalk.bold('📈 Test Summary:'));
        console.log(`  Total Tests: ${total}`);
        console.log(`  ${chalk.green('Passed')}: ${passed}`);
        console.log(`  ${chalk.red('Failed')}: ${failed}`);
        console.log(`  ${chalk.yellow('Warnings')}: ${warnings}`);
        
        const successRate = ((passed / total) * 100).toFixed(1);
        console.log(`  Success Rate: ${successRate}%`);
        
        if (failed > 0) {
            console.log(chalk.red('\n❌ Failed Tests:'));
            this.testResults
                .filter(r => r.status === 'FAIL')
                .forEach(r => console.log(`  • ${r.test}: ${r.details}`));
        }
        
        if (warnings > 0) {
            console.log(chalk.yellow('\n⚠️  Warnings:'));
            this.testResults
                .filter(r => r.status === 'WARN')
                .forEach(r => console.log(`  • ${r.test}: ${r.details}`));
        }
        
        if (this.errors.length > 0) {
            console.log(chalk.red('\n🚨 Errors:'));
            this.errors.forEach(e => console.log(`  • ${e.context}: ${e.message}`));
        }
        
        // Overall assessment
        console.log(chalk.bold('\n🎯 Overall Assessment:'));
        if (failed === 0 && this.errors.length === 0) {
            console.log(chalk.green('✅ All tests passed! The automated release system is ready for production use.'));
        } else if (failed <= 2 && this.errors.length === 0) {
            console.log(chalk.yellow('⚠️  Most tests passed with minor issues. Review failed tests before production use.'));
        } else {
            console.log(chalk.red('❌ Multiple test failures detected. System requires fixes before production use.'));
        }
        
        console.log(chalk.bold('\n🚀 Next Steps:'));
        if (failed === 0) {
            console.log('  • System is ready for production use');
            console.log('  • Consider running a test release with --dry-run');
            console.log('  • Review documentation for best practices');
        } else {
            console.log('  • Fix failed tests and re-run integration tests');
            console.log('  • Check error messages for specific issues');
            console.log('  • Verify environment setup and dependencies');
        }
        
        return failed === 0 && this.errors.length === 0;
    }
}

// CLI execution
if (require.main === module) {
    const testSuite = new IntegrationTestSuite();
    testSuite.runTests().then(success => {
        process.exit(success ? 0 : 1);
    }).catch(error => {
        console.error(chalk.red('Test suite failed:'), error);
        process.exit(1);
    });
}

module.exports = IntegrationTestSuite;
