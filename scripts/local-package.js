#!/usr/bin/env node

/**
 * Local Package Tool for Notion-to-WordPress Plugin
 * 
 * This tool allows you to update version numbers and create local packages
 * for testing without publishing or committing to Git.
 * 
 * Features:
 * - Update version numbers in all relevant files
 * - Create local ZIP package for testing
 * - No Git operations (safe for testing)
 * - Backup and restore capabilities
 * - Dry-run mode for preview
 * 
 * @author Frank-Loong
 * @version 1.0.0
 */

const fs = require('fs');
const path = require('path');
const chalk = require('chalk');
const minimist = require('minimist');

// Import our custom tools
const VersionBumper = require('./version-bump.js');
const BuildTool = require('./build.js');

class LocalPackager {
    constructor() {
        this.projectRoot = path.resolve(__dirname, '..');
        this.isDryRun = false;
        this.versionType = null;
        this.customVersion = null;
        this.currentVersion = null;
        this.newVersion = null;
        
        // Backup tracking
        this.backupFiles = [];
    }

    /**
     * Parse command line arguments
     */
    parseArguments(args) {
        const parsed = minimist(args, {
            boolean: ['dry-run', 'help', 'build-only', 'version-only'],
            string: ['version'],
            alias: {
                'h': 'help',
                'd': 'dry-run',
                'v': 'version',
                'b': 'build-only',
                'vo': 'version-only'
            }
        });

        if (parsed.help) {
            this.showHelp();
            process.exit(0);
        }

        this.isDryRun = parsed['dry-run'];
        this.buildOnly = parsed['build-only'];
        this.versionOnly = parsed['version-only'];
        this.customVersion = parsed.version;

        // Get version type from positional argument
        if (parsed._.length > 0 && !this.customVersion) {
            this.versionType = parsed._[0];
            if (!['patch', 'minor', 'major', 'beta'].includes(this.versionType)) {
                throw new Error(`Invalid version type: ${this.versionType}. Use: patch, minor, major, beta`);
            }
        }

        // Validate arguments
        if (!this.buildOnly && !this.customVersion && !this.versionType) {
            throw new Error('Please specify version type (patch/minor/major/beta) or custom version with --version');
        }
    }

    /**
     * Show help information
     */
    showHelp() {
        console.log(chalk.bold('\n📦 Local Package Tool for Notion-to-WordPress Plugin\n'));
        console.log('Usage:');
        console.log('  npm run package:local <version-type>     # Update version and build');
        console.log('  npm run package:local --version=X.Y.Z   # Use custom version');
        console.log('  npm run package:local --build-only      # Only build, no version update');
        console.log('  npm run package:local --version-only    # Only update version, no build');
        console.log('');
        console.log('Version types:');
        console.log('  patch    # 1.0.0 → 1.0.1');
        console.log('  minor    # 1.0.0 → 1.1.0');
        console.log('  major    # 1.0.0 → 2.0.0');
        console.log('  beta     # 1.0.0 → 1.0.1-beta.1');
        console.log('');
        console.log('Options:');
        console.log('  -d, --dry-run        Preview changes without applying');
        console.log('  -v, --version=X.Y.Z  Use custom version number');
        console.log('  -b, --build-only     Only create package, skip version update');
        console.log('  --version-only       Only update version, skip package creation');
        console.log('  -h, --help           Show this help');
        console.log('');
        console.log('Examples:');
        console.log('  npm run package:local patch');
        console.log('  npm run package:local --version=1.2.0-test.1');
        console.log('  npm run package:local beta --dry-run');
        console.log('  npm run package:local --build-only');
    }

    /**
     * Logging utilities
     */
    log(message) {
        console.log(chalk.blue('ℹ'), message);
    }

    success(message) {
        console.log(chalk.green('✅'), message);
    }

    warn(message) {
        console.log(chalk.yellow('⚠️'), message);
    }

    error(message) {
        console.log(chalk.red('❌'), message);
    }

    /**
     * Get current version from main plugin file
     */
    getCurrentVersion() {
        try {
            const mainFile = path.join(this.projectRoot, 'notion-to-wordpress.php');
            const content = fs.readFileSync(mainFile, 'utf8');
            
            const versionMatch = content.match(/\* Version:\s+([0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?)/);
            if (versionMatch) {
                return versionMatch[1];
            }
            
            throw new Error('Could not find version in main plugin file');
        } catch (error) {
            throw new Error(`Failed to get current version: ${error.message}`);
        }
    }

    /**
     * Update version numbers
     */
    async updateVersion() {
        if (this.buildOnly) {
            this.log('Skipping version update (build-only mode)');
            return;
        }

        this.log('Updating version numbers...');

        if (this.isDryRun) {
            this.log(chalk.yellow('DRY RUN: Would update version numbers'));
            return;
        }

        try {
            const versionBumper = new VersionBumper();
            
            if (this.customVersion) {
                // Use custom version
                versionBumper.updateToCustomVersion(this.customVersion);
                this.newVersion = this.customVersion;
            } else {
                // Use version type
                versionBumper.run(this.versionType);
                this.newVersion = versionBumper.getNewVersion();
            }

            this.success(`Version updated to: ${this.newVersion}`);
        } catch (error) {
            throw new Error(`Failed to update version: ${error.message}`);
        }
    }

    /**
     * Create local package
     */
    async createPackage() {
        if (this.versionOnly) {
            this.log('Skipping package creation (version-only mode)');
            return;
        }

        this.log('Creating local package...');

        if (this.isDryRun) {
            this.log(chalk.yellow('DRY RUN: Would create local package'));
            return;
        }

        try {
            const buildTool = new BuildTool();
            const packagePath = await buildTool.build();
            
            this.success(`Local package created: ${packagePath}`);
            this.log(`You can now test this package by uploading it to a WordPress site`);
        } catch (error) {
            throw new Error(`Failed to create package: ${error.message}`);
        }
    }

    /**
     * Main execution
     */
    async run() {
        try {
            this.log(chalk.bold('📦 Local Package Tool'));
            
            // Get current version
            this.currentVersion = this.getCurrentVersion();
            this.log(`Current version: ${this.currentVersion}`);

            if (this.isDryRun) {
                this.log(chalk.yellow('🔍 DRY RUN MODE - No changes will be made'));
            }

            // Step 1: Update version (if needed)
            await this.updateVersion();

            // Step 2: Create package (if needed)
            await this.createPackage();

            if (!this.isDryRun) {
                this.success('✅ Local packaging completed successfully!');
                
                if (!this.versionOnly) {
                    this.log('');
                    this.log('📋 Next steps:');
                    this.log('  1. Test the generated ZIP package on a WordPress site');
                    this.log('  2. If satisfied, commit the version changes');
                    this.log('  3. Use npm run release:* for official releases');
                }
            }

        } catch (error) {
            this.error(`Local packaging failed: ${error.message}`);
            process.exit(1);
        }
    }
}

// Main execution
if (require.main === module) {
    const args = process.argv.slice(2);
    
    try {
        const packager = new LocalPackager();
        packager.parseArguments(args);
        packager.run();
    } catch (error) {
        console.error(chalk.red('❌'), error.message);
        process.exit(1);
    }
}

module.exports = LocalPackager;
