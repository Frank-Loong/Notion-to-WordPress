#!/usr/bin/env node

/**
 * Release Controller for Notion-to-WordPress Plugin
 * 
 * This is the main release orchestrator that coordinates the entire
 * automated release process including version updates, building,
 * Git operations, and error handling with rollback capabilities.
 * 
 * @author Frank-Loong
 * @version 1.0.0
 */

const fs = require('fs');
const path = require('path');
const { execSync, spawn } = require('child_process');
const chalk = require('chalk');
const minimist = require('minimist');

// Import our custom tools
const VersionBumper = require('./version-bump.js');
const BuildTool = require('./build.js');

class ReleaseController {
    constructor() {
        this.projectRoot = path.resolve(__dirname, '..');
        this.isDryRun = false;
        this.releaseType = null;
        this.currentVersion = null;
        this.newVersion = null;
        
        // Release steps tracking
        this.completedSteps = [];
        this.rollbackActions = [];
    }

    /**
     * Parse command line arguments
     */
    parseArguments(args) {
        const parsed = minimist(args, {
            boolean: ['dry-run', 'help', 'force'],
            string: ['version'],
            alias: {
                'h': 'help',
                'd': 'dry-run',
                'f': 'force',
                'v': 'version'
            }
        });

        if (parsed.help) {
            this.showHelp();
            process.exit(0);
        }

        this.isDryRun = parsed['dry-run'] || false;
        this.forceRelease = parsed.force || false;
        this.customVersion = parsed.version;
        this.releaseType = parsed._[0];

        // Validate arguments
        if (this.customVersion) {
            // Custom version provided, validate format
            if (!this.isValidVersion(this.customVersion)) {
                this.error(`Invalid version format: ${this.customVersion}`);
                this.showHelp();
                process.exit(1);
            }
            this.releaseType = 'custom';
        } else if (!this.releaseType || !['patch', 'minor', 'major', 'beta'].includes(this.releaseType)) {
            this.error('Invalid or missing release type. Use patch/minor/major/beta or --version=X.Y.Z');
            this.showHelp();
            process.exit(1);
        }

        return {
            releaseType: this.releaseType,
            isDryRun: this.isDryRun,
            forceRelease: this.forceRelease,
            customVersion: this.customVersion
        };
    }

    /**
     * Validate version format
     */
    isValidVersion(version) {
        // Basic semver validation
        const semverRegex = /^([0-9]+)\.([0-9]+)\.([0-9]+)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/;
        return semverRegex.test(version);
    }

    /**
     * Show help information
     */
    showHelp() {
        console.log(chalk.bold('\n🚀 Notion-to-WordPress Release Controller\n'));
        console.log('Usage: node release.js <release-type> [options]');
        console.log('       node release.js --version=X.Y.Z [options]\n');
        console.log('Release Types:');
        console.log('  patch     Patch release (1.1.0 → 1.1.1)');
        console.log('  minor     Minor release (1.1.0 → 1.2.0)');
        console.log('  major     Major release (1.1.0 → 2.0.0)');
        console.log('  beta      Beta release (1.1.0 → 1.1.1-beta.1)\n');
        console.log('Options:');
        console.log('  -v, --version=X.Y.Z  Use custom version number');
        console.log('  -d, --dry-run        Preview changes without executing');
        console.log('  -f, --force          Skip confirmation prompts');
        console.log('  -h, --help           Show this help message\n');
        console.log('Examples:');
        console.log('  node release.js patch');
        console.log('  node release.js minor --dry-run');
        console.log('  node release.js major --force');
        console.log('  node release.js --version=1.2.0-rc.1');
        console.log('  node release.js --version=1.2.0-hotfix.1 --dry-run');
    }

    /**
     * Validate environment and prerequisites
     */
    validateEnvironment() {
        this.log('🔍 Validating environment...');

        // Check if we're in a git repository
        try {
            execSync('git rev-parse --git-dir', { 
                cwd: this.projectRoot, 
                stdio: 'pipe' 
            });
        } catch (error) {
            throw new Error('Not in a Git repository');
        }

        // Check for uncommitted changes
        try {
            const status = execSync('git status --porcelain', { 
                cwd: this.projectRoot, 
                encoding: 'utf8' 
            });
            
            if (status.trim() && !this.forceRelease) {
                throw new Error('Working directory has uncommitted changes. Use --force to override.');
            }
        } catch (error) {
            if (error.message.includes('uncommitted changes')) {
                throw error;
            }
            // Git status command failed for other reasons
            this.warn('Could not check Git status');
        }

        // Check if required tools are available
        const requiredFiles = [
            path.join(__dirname, 'version-bump.js'),
            path.join(__dirname, 'build.js')
        ];

        for (const file of requiredFiles) {
            if (!fs.existsSync(file)) {
                throw new Error(`Required tool not found: ${path.basename(file)}`);
            }
        }

        // Check Node.js version
        const nodeVersion = process.version;
        const majorVersion = parseInt(nodeVersion.slice(1).split('.')[0]);
        if (majorVersion < 16) {
            throw new Error(`Node.js 16+ required, current: ${nodeVersion}`);
        }

        this.success('Environment validation passed');
    }

    /**
     * Get current version and calculate new version
     */
    prepareVersions() {
        this.log('📋 Preparing version information...');

        const versionBumper = new VersionBumper();

        // Get current version
        this.currentVersion = versionBumper.getCurrentVersion();
        versionBumper.validateVersion();

        // Calculate new version
        if (this.customVersion) {
            // Use custom version
            this.newVersion = this.customVersion;
        } else {
            // Calculate version based on release type
            this.newVersion = versionBumper.bumpVersion(this.currentVersion, this.releaseType);
        }

        this.log(`Current version: ${chalk.yellow(this.currentVersion)}`);
        this.log(`New version: ${chalk.green(this.newVersion)}`);

        return {
            currentVersion: this.currentVersion,
            newVersion: this.newVersion
        };
    }

    /**
     * Ask for user confirmation
     */
    async askConfirmation() {
        if (this.isDryRun || this.forceRelease) {
            return true;
        }

        console.log(chalk.bold('\n📋 Release Summary:'));
        console.log(`  Release Type: ${chalk.cyan(this.releaseType)}`);
        console.log(`  Current Version: ${chalk.yellow(this.currentVersion)}`);
        console.log(`  New Version: ${chalk.green(this.newVersion)}`);
        console.log(`  Dry Run: ${this.isDryRun ? chalk.green('Yes') : chalk.red('No')}`);

        return new Promise((resolve) => {
            const readline = require('readline');
            const rl = readline.createInterface({
                input: process.stdin,
                output: process.stdout
            });

            rl.question(chalk.bold('\n❓ Proceed with release? (y/N): '), (answer) => {
                rl.close();
                resolve(answer.toLowerCase() === 'y' || answer.toLowerCase() === 'yes');
            });
        });
    }

    /**
     * Execute version bump
     */
    async executeVersionBump() {
        this.log('🔄 Updating version numbers...');

        if (this.isDryRun) {
            this.log('  [DRY RUN] Would update version to ' + this.newVersion);
            return;
        }

        try {
            const versionBumper = new VersionBumper();

            if (this.customVersion) {
                // Use custom version
                versionBumper.updateToCustomVersion(this.customVersion);
                this.newVersion = this.customVersion;
            } else {
                // Use standard release type
                versionBumper.run(this.releaseType);
                this.newVersion = versionBumper.getNewVersion();
            }
            
            this.completedSteps.push('version-bump');
            this.rollbackActions.push(() => {
                this.log('Rolling back version changes...');
                try {
                    versionBumper.restoreFromBackup();
                } catch (error) {
                    this.warn('Could not restore version backup: ' + error.message);
                }
            });
            
            this.success('Version updated successfully');
        } catch (error) {
            throw new Error(`Version bump failed: ${error.message}`);
        }
    }

    /**
     * Execute build process
     */
    async executeBuild() {
        this.log('📦 Building WordPress plugin package...');

        if (this.isDryRun) {
            this.log('  [DRY RUN] Would build plugin package');
            return;
        }

        try {
            const buildTool = new BuildTool();
            await buildTool.build();
            
            this.completedSteps.push('build');
            this.success('Plugin package built successfully');
        } catch (error) {
            throw new Error(`Build failed: ${error.message}`);
        }
    }

    /**
     * Execute Git operations
     */
    async executeGitOperations() {
        this.log('📝 Performing Git operations...');

        if (this.isDryRun) {
            this.log('  [DRY RUN] Would commit changes and create tag');
            return;
        }

        try {
            // Add all changes
            execSync('git add .', { cwd: this.projectRoot });
            
            // Commit changes
            const commitMessage = `Release version ${this.newVersion}`;
            execSync(`git commit -m "${commitMessage}"`, { cwd: this.projectRoot });
            
            // Create tag
            const tagMessage = `Version ${this.newVersion}`;
            execSync(`git tag -a v${this.newVersion} -m "${tagMessage}"`, { cwd: this.projectRoot });
            
            this.completedSteps.push('git-operations');
            this.rollbackActions.push(() => {
                this.log('Rolling back Git operations...');
                try {
                    execSync(`git tag -d v${this.newVersion}`, { cwd: this.projectRoot });
                    execSync('git reset --hard HEAD~1', { cwd: this.projectRoot });
                } catch (error) {
                    this.warn('Could not rollback Git operations: ' + error.message);
                }
            });
            
            this.success('Git operations completed');
        } catch (error) {
            throw new Error(`Git operations failed: ${error.message}`);
        }
    }

    /**
     * Push to remote repository
     */
    async pushToRemote() {
        this.log('🚀 Pushing to remote repository...');

        if (this.isDryRun) {
            this.log('  [DRY RUN] Would push commits and tags to remote');
            return;
        }

        try {
            // Push commits
            execSync('git push origin main', { cwd: this.projectRoot });
            
            // Push tags
            execSync(`git push origin v${this.newVersion}`, { cwd: this.projectRoot });
            
            this.completedSteps.push('push');
            this.success('Pushed to remote repository');
        } catch (error) {
            throw new Error(`Push failed: ${error.message}`);
        }
    }

    /**
     * Execute rollback actions
     */
    async executeRollback() {
        this.warn('🔄 Executing rollback...');
        
        // Execute rollback actions in reverse order
        for (let i = this.rollbackActions.length - 1; i >= 0; i--) {
            try {
                await this.rollbackActions[i]();
            } catch (error) {
                this.error(`Rollback action failed: ${error.message}`);
            }
        }
    }

    /**
     * Main release execution
     */
    async executeRelease() {
        try {
            this.log(chalk.bold('🚀 Starting Release Process'));
            
            // Step 1: Validate environment
            this.validateEnvironment();
            
            // Step 2: Prepare versions
            this.prepareVersions();
            
            // Step 3: Ask for confirmation
            const confirmed = await this.askConfirmation();
            if (!confirmed) {
                this.log('Release cancelled by user');
                return;
            }
            
            // Step 4: Execute version bump
            await this.executeVersionBump();
            
            // Step 5: Execute build
            await this.executeBuild();
            
            // Step 6: Execute Git operations
            await this.executeGitOperations();
            
            // Step 7: Push to remote
            await this.pushToRemote();
            
            // Success!
            this.success(`✅ Release ${this.newVersion} completed successfully!`);
            
            if (!this.isDryRun) {
                console.log(chalk.bold('\n📦 Next Steps:'));
                console.log('  • GitHub Actions will automatically create a release');
                console.log('  • Check the Actions tab for build status');
                console.log(`  • Download the plugin from: build/notion-to-wordpress-${this.newVersion}.zip`);
            }
            
        } catch (error) {
            this.error(`Release failed: ${error.message}`);
            
            if (!this.isDryRun && this.completedSteps.length > 0) {
                await this.executeRollback();
            }
            
            process.exit(1);
        }
    }

    // Utility logging methods
    log(message) {
        console.log(message);
    }

    success(message) {
        console.log(chalk.green('✅ ' + message));
    }

    warn(message) {
        console.log(chalk.yellow('⚠️  ' + message));
    }

    error(message) {
        console.log(chalk.red('❌ ' + message));
    }
}

// CLI execution
if (require.main === module) {
    const controller = new ReleaseController();
    const args = process.argv.slice(2);
    
    try {
        controller.parseArguments(args);
        controller.executeRelease();
    } catch (error) {
        controller.error(error.message);
        process.exit(1);
    }
}

module.exports = ReleaseController;
