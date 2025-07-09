#!/usr/bin/env node

/**
 * Release Configuration Validator
 * 
 * This script validates the release configuration file to ensure
 * all settings are properly configured and compatible with the
 * release system requirements.
 * 
 * @author Frank-Loong
 * @version 1.0.0
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
     * Validate the release configuration
     */
    validate() {
        console.log(chalk.bold('🔍 Release Configuration Validator\n'));

        try {
            // Check if config file exists
            if (!fs.existsSync(this.configPath)) {
                throw new Error('Configuration file not found: release.config.js');
            }

            // Load configuration
            delete require.cache[require.resolve(this.configPath)];
            const configModule = require(this.configPath);
            const config = configModule.getConfig();

            // Validate different sections
            this.validateProject(config.project);
            this.validateVersion(config.version);
            this.validateBuild(config.build);
            this.validateGit(config.git);
            this.validateGitHub(config.github);
            this.validateEnvironment(config.environment);

            // Check for file existence
            this.validateFileReferences(config);

            // Display results
            this.displayResults(config);

            return this.errors.length === 0;

        } catch (error) {
            console.log(chalk.red(`❌ Validation failed: ${error.message}`));
            return false;
        }
    }

    /**
     * Validate project configuration
     */
    validateProject(project) {
        if (!project.name) {
            this.errors.push('project.name is required');
        }

        if (!project.displayName) {
            this.warnings.push('project.displayName is recommended');
        }

        if (!project.description) {
            this.warnings.push('project.description is recommended');
        }

        if (!project.repository?.url) {
            this.warnings.push('project.repository.url is recommended');
        }

        console.log(chalk.green('✅ Project configuration validated'));
    }

    /**
     * Validate version configuration
     */
    validateVersion(version) {
        if (!version.files || !Array.isArray(version.files)) {
            this.errors.push('version.files must be an array');
            return;
        }

        if (version.files.length === 0) {
            this.errors.push('version.files cannot be empty');
            return;
        }

        // Validate each version file configuration
        for (let i = 0; i < version.files.length; i++) {
            const file = version.files[i];
            
            if (!file.path) {
                this.errors.push(`version.files[${i}].path is required`);
                continue;
            }

            if (!file.patterns || !Array.isArray(file.patterns)) {
                this.errors.push(`version.files[${i}].patterns must be an array`);
                continue;
            }

            // Check if file exists
            const filePath = path.join(this.projectRoot, file.path);
            if (!fs.existsSync(filePath)) {
                this.warnings.push(`Version file not found: ${file.path}`);
            }

            // Validate patterns
            for (let j = 0; j < file.patterns.length; j++) {
                const pattern = file.patterns[j];
                
                if (!pattern.regex) {
                    this.errors.push(`version.files[${i}].patterns[${j}].regex is required`);
                }

                if (!pattern.replacement) {
                    this.errors.push(`version.files[${i}].patterns[${j}].replacement is required`);
                }
            }
        }

        console.log(chalk.green('✅ Version configuration validated'));
    }

    /**
     * Validate build configuration
     */
    validateBuild(build) {
        if (!build.output?.directory) {
            this.errors.push('build.output.directory is required');
        }

        if (!build.output?.filename) {
            this.errors.push('build.output.filename is required');
        }

        // Check if output directory can be created
        const outputDir = path.join(this.projectRoot, build.output.directory);
        try {
            if (!fs.existsSync(outputDir)) {
                fs.mkdirSync(outputDir, { recursive: true });
                fs.rmdirSync(outputDir); // Clean up test directory
            }
        } catch (error) {
            this.errors.push(`Cannot create build output directory: ${build.output.directory}`);
        }

        // Validate include/exclude settings
        if (build.include?.files && !Array.isArray(build.include.files)) {
            this.errors.push('build.include.files must be an array');
        }

        if (build.exclude?.files && !Array.isArray(build.exclude.files)) {
            this.errors.push('build.exclude.files must be an array');
        }

        console.log(chalk.green('✅ Build configuration validated'));
    }

    /**
     * Validate Git configuration
     */
    validateGit(git) {
        if (!git.branch?.main) {
            this.errors.push('git.branch.main is required');
        }

        if (!git.remote?.name) {
            this.errors.push('git.remote.name is required');
        }

        if (!git.tag?.prefix && git.tag?.prefix !== '') {
            this.warnings.push('git.tag.prefix is recommended');
        }

        console.log(chalk.green('✅ Git configuration validated'));
    }

    /**
     * Validate GitHub configuration
     */
    validateGitHub(github) {
        if (!github.repository?.owner) {
            this.errors.push('github.repository.owner is required');
        }

        if (!github.repository?.name) {
            this.errors.push('github.repository.name is required');
        }

        if (!github.assets || !Array.isArray(github.assets)) {
            this.warnings.push('github.assets should be an array');
        }

        console.log(chalk.green('✅ GitHub configuration validated'));
    }

    /**
     * Validate environment configuration
     */
    validateEnvironment(environment) {
        if (!environment.node?.minVersion) {
            this.warnings.push('environment.node.minVersion is recommended');
        }

        if (!environment.requiredTools || !Array.isArray(environment.requiredTools)) {
            this.warnings.push('environment.requiredTools should be an array');
        }

        console.log(chalk.green('✅ Environment configuration validated'));
    }

    /**
     * Validate file references in configuration
     */
    validateFileReferences(config) {
        // Check version files
        for (const file of config.version.files) {
            const filePath = path.join(this.projectRoot, file.path);
            if (!fs.existsSync(filePath)) {
                this.warnings.push(`Referenced file not found: ${file.path}`);
            }
        }

        // Check include files
        if (config.build.include?.files) {
            for (const file of config.build.include.files) {
                const filePath = path.join(this.projectRoot, file);
                if (!fs.existsSync(filePath)) {
                    this.warnings.push(`Include file not found: ${file}`);
                }
            }
        }

        // Check include directories
        if (config.build.include?.directories) {
            for (const dir of config.build.include.directories) {
                const dirPath = path.join(this.projectRoot, dir);
                if (!fs.existsSync(dirPath)) {
                    this.warnings.push(`Include directory not found: ${dir}`);
                }
            }
        }

        console.log(chalk.green('✅ File references validated'));
    }

    /**
     * Display validation results
     */
    displayResults(config) {
        console.log(chalk.bold('\n📋 Configuration Summary:'));
        console.log(`  • Project: ${config.project.name}`);
        console.log(`  • Version files: ${config.version.files.length}`);
        console.log(`  • Build output: ${config.build.output.directory}`);
        console.log(`  • Git branch: ${config.git.branch.main}`);
        console.log(`  • GitHub repo: ${config.github.repository.owner}/${config.github.repository.name}`);

        if (this.warnings.length > 0) {
            console.log(chalk.yellow('\n⚠️  Warnings:'));
            this.warnings.forEach(warning => {
                console.log(chalk.yellow(`  • ${warning}`));
            });
        }

        if (this.errors.length > 0) {
            console.log(chalk.red('\n❌ Errors:'));
            this.errors.forEach(error => {
                console.log(chalk.red(`  • ${error}`));
            });
            console.log(chalk.red('\n❌ Configuration validation failed!'));
        } else {
            console.log(chalk.green('\n✅ Configuration validation passed!'));
            if (this.warnings.length === 0) {
                console.log(chalk.green('🎉 No issues found - configuration is perfect!'));
            }
        }
    }
}

// CLI execution
if (require.main === module) {
    const validator = new ConfigValidator();
    const isValid = validator.validate();
    process.exit(isValid ? 0 : 1);
}

module.exports = ConfigValidator;
